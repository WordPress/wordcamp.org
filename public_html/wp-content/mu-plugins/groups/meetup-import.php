<?php
/**
 * Network-admin tool for importing a Meetup group into a group site.
 *
 * Moving a group off Meetup.com (#1788): pull one Meetup group's events,
 * venues, and optionally members from the Meetup Pro API into a chosen group
 * site.
 *
 * - Upcoming events land as drafts, for the organizer to review and publish.
 * - Past events (opt-in) land published, as the group's history, with
 *   Meetup's RSVP and attendance counts kept as meta. Individual RSVPs aren't
 *   imported.
 * - Members (opt-in) are only ever matched to existing WordPress.org accounts
 *   by email. A match joins the group as a Member with event emails off; no
 *   account is created for anyone else.
 *
 * Nothing here sends an email: upcoming events stay drafts, past events are
 * published with the new-event notification marked as already handled, and
 * matched members are opted out of this group's event updates until they
 * choose otherwise.
 *
 * Re-running the import is safe. Events and venues are keyed on their Meetup
 * IDs: an event still in draft is refreshed from Meetup, so date or venue
 * changes made there before cutover come across, while one the organizer has
 * published (or trashed) is theirs and is never touched again. Members are
 * considered once per group, so someone who leaves isn't added back.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Meetup_Import;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Venue;
use WordCamp\Groups\Frontend\Check_In;
use WordCamp\Groups\Frontend\Event_Hosts;
use WordCamp\Groups\Frontend\Group_Location;
use WordCamp\Logger;
use WordPressdotorg\MU_Plugins\Utilities\Meetup_Client;
use WP_Error;
use WP_User;

use function WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key;
use function WordCamp\Groups\Frontend\REST\storable_timezone;
use function WordCamp\Groups\Frontend\REST\sync_event_venue_terms;
use function WordCamp\Groups\Frontend\REST\sync_online_event_link;
use const WordCamp\Groups\Frontend\Notifications\PUBLISH_NOTIFICATION_SCHEDULED_META;

defined( 'WPINC' ) || die();

const PAGE_SLUG      = 'meetup-import';
const NONCE_ACTION   = 'wcorg_meetup_import';
const NONCE_NAME     = 'wcorg_meetup_import_nonce';
const EVENT_ID_META  = '_wporg_meetup_event_id';
const EVENT_URL_META = '_wporg_meetup_event_url';
const VENUE_ID_META  = '_wporg_meetup_venue_id';
const RSVP_YES_META  = '_wporg_meetup_rsvp_yes_count';
const ATTENDED_META  = '_wporg_meetup_attended_count';
const PHOTO_ID_META  = '_wporg_meetup_photo_id';

/**
 * Site options recording which Meetup group a site was imported from, and
 * when that group was founded, for the group's history.
 */
const GROUP_URLNAME_OPTION = 'wporg_meetup_group_urlname';
const FOUNDED_OPTION       = 'wporg_meetup_founded_date';

/**
 * The site role each Meetup leadership role maps to.
 *
 * Meetup's organizers and co-organizers run the group, the full "Organizer"
 * tier here (`editor`); assistant and event organizers run events, the "Event
 * Organizer" tier (`author`). See `Frontend\Capabilities\ORGANIZER_ROLES`.
 * Nobody is ever made an `administrator` by an import -- that stays with the
 * lead organizer the site was created for.
 */
const LEADER_ROLES = array(
	'ORGANIZER'       => 'editor',
	'COORGANIZER'     => 'editor',
	'ASST_ORGANIZER'  => 'author',
	'EVENT_ORGANIZER' => 'author',
);

/**
 * How the site roles rank, so an import only ever promotes.
 */
const ROLE_RANK = array(
	'subscriber'    => 0,
	'contributor'   => 0,
	'author'        => 1,
	'editor'        => 2,
	'administrator' => 3,
);

/**
 * GatherPress RSVP status for each Meetup one, and whether it means they came.
 *
 * Meetup's waiting list comes over as "attending" and GatherPress places it:
 * on an event with room it promotes waitlisted RSVPs straight away anyway,
 * and on a full one it puts an attending RSVP on the waiting list itself
 * (`Rsvp::constrain_rsvp_intent()`). Saving `waiting_list` directly on an
 * event with room also trips a bug in GatherPress 0.35's waiting-list
 * promotion (`Rsvp::check_waiting_list()` walks past the end of the list).
 *
 * Meetup statuses with no equivalent (maybe, no-show, excused, haven't
 * answered) are left out rather than guessed at.
 */
const RSVP_STATUSES = array(
	'YES'                 => array( 'attending', false ),
	'YES_PENDING_PAYMENT' => array( 'attending', false ),
	'ATTENDED'            => array( 'attending', true ),
	'NO'                  => array( 'not_attending', false ),
	'WAITLIST'            => array( 'attending', false ),
);

/**
 * Marks an imported past event that still has to be published.
 *
 * Set when the event is created and cleared once it's published, so a run
 * that dies in between leaves something the next run knows to finish.
 */
const PENDING_PUBLISH_META = '_wporg_meetup_pending_publish';

/**
 * Site option listing the Meetup member IDs an import has already considered.
 *
 * Only Meetup IDs are kept, never emails, so a rerun can tell a member it has
 * seen before (and who may since have left the group) from a new one.
 */
const MEMBERS_OPTION = 'wporg_meetup_imported_member_ids';

/**
 * Option row that marks an import as running, and how long it may hold it.
 *
 * One import at a time, across the whole network: events are looked up and
 * then inserted, so two imports racing for the same group (a double-click,
 * two admins) could both miss an event and both create it. The expiry frees
 * the lock if an import dies without releasing it.
 */
const LOCK_OPTION  = 'wporg_meetup_import_lock';
const LOCK_TIMEOUT = 15 * MINUTE_IN_SECONDS;

/**
 * The placeholder venue Meetup attaches to online events.
 *
 * Mirrors `Meetup_Client::ONLINE_VENUE_ID`. It's repeated rather than
 * referenced so that normalizing an event doesn't load the client, whose
 * OAuth class requires the Meetup credentials to be defined.
 */
const ONLINE_VENUE_ID = 26906060;

add_action( 'network_admin_menu', __NAMESPACE__ . '\register_admin_page' );
add_action( 'admin_init', __NAMESPACE__ . '\handle_form_submission' );

/**
 * Add the "Import from Meetup" screen under Network Admin > Groups.
 */
function register_admin_page() {
	add_submenu_page(
		\WordCamp\Groups\Archive\PAGE_SLUG,
		__( 'Import from Meetup', 'wordcamporg' ),
		__( 'Import from Meetup', 'wordcamporg' ),
		'manage_sites',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * Hold what the form submission produced, for the render on the same request.
 *
 * @param array|null $state New state to store, or null to read.
 * @return array
 */
function request_state( ?array $state = null ): array {
	static $current = array();

	if ( null !== $state ) {
		$current = $state;
	}

	return $current;
}

/**
 * Handle the preview and import buttons.
 *
 * Runs on `admin_init` so it completes before `render_admin_page()` renders
 * on the same request, like the Add Group Site screen. Both buttons fetch from
 * Meetup afresh: nothing is cached between the preview and the import, so the
 * import always writes what Meetup has now.
 */
function handle_form_submission() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified with check_admin_referer() below.
	if ( empty( $_POST['wcorg_meetup_import_step'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ) );
	}

	check_admin_referer( NONCE_ACTION, NONCE_NAME );

	$step         = 'import' === $_POST['wcorg_meetup_import_step'] ? 'import' : 'preview';
	$urlname      = parse_group_urlname( isset( $_POST['meetup_group'] ) ? sanitize_text_field( wp_unslash( $_POST['meetup_group'] ) ) : '' );
	$site_id      = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$include_past = ! empty( $_POST['include_past'] );
	$match_people = ! empty( $_POST['match_people'] );
	$state        = compact( 'step', 'urlname', 'site_id', 'include_past', 'match_people' );
	$is_valid     = validate_target_site( $site_id );

	if ( '' === $urlname ) {
		add_settings_error( 'wcorg-meetup-import', 'invalid_group', __( 'Please enter a Meetup group URL or name.', 'wordcamporg' ) );
		request_state( $state );
		return;
	}

	if ( is_wp_error( $is_valid ) ) {
		add_settings_error( 'wcorg-meetup-import', $is_valid->get_error_code(), $is_valid->get_error_message() );
		request_state( $state );
		return;
	}

	$raw_group   = fetch_group( $urlname );
	$raw_events  = fetch_group_events( $urlname, $include_past );
	$raw_members = $match_people ? fetch_group_members( $urlname ) : array();
	$raw_leaders = $match_people ? fetch_group_leaders( $urlname ) : array();

	foreach ( array( $raw_group, $raw_events, $raw_members, $raw_leaders ) as $fetched ) {
		if ( is_wp_error( $fetched ) ) {
			add_settings_error( 'wcorg-meetup-import', $fetched->get_error_code(), $fetched->get_error_message() );
			request_state( $state );
			return;
		}
	}

	$data = array(
		'group'   => normalize_group( $raw_group ),
		'events'  => array_map( __NAMESPACE__ . '\normalize_event', $raw_events ),
		'members' => array_map( __NAMESPACE__ . '\normalize_member', $raw_members ),
		'leaders' => array_map( __NAMESPACE__ . '\normalize_leader', $raw_leaders ),
	);

	if ( 'preview' === $step ) {
		$state['group_results'] = plan_group( $site_id, $data['group'] );
		$state['plan']          = plan_events( $site_id, $data['events'] );

		if ( $match_people ) {
			$state['leader_counts'] = plan_leaders( $site_id, $data['leaders'] );
			$state['member_counts'] = plan_members( $site_id, $data['members'] );
		}

		request_state( $state );
		return;
	}

	if ( ! acquire_lock() ) {
		add_settings_error(
			'wcorg-meetup-import',
			'import_running',
			__( 'Another Meetup import is running right now. Please try again in a few minutes.', 'wordcamporg' )
		);
		request_state( $state );
		return;
	}

	// `finally` doesn't run when PHP hits its time limit, but shutdown
	// functions do, so a timed-out import frees the lock for the retry right
	// away instead of holding it until `LOCK_TIMEOUT`. The retry then picks up
	// where this run stopped: see `import_event()` on reruns.
	register_shutdown_function( __NAMESPACE__ . '\release_lock' );

	try {
		run_import( $site_id, $data, $match_people, $state );
	} finally {
		release_lock();
	}
}

/**
 * Write the import and report on it. Runs while holding the import lock.
 *
 * Group details go first, then the group's leaders and members, so that
 * everyone is in the group, in the right role, before events credit them as
 * hosts.
 *
 * @param int   $site_id      Target group site.
 * @param array $data         Normalized `group`, `events`, `members`, and `leaders`.
 * @param bool  $match_people Whether to match people to WordPress.org accounts by email.
 * @param array $state        Request state so far.
 */
function run_import( int $site_id, array $data, bool $match_people, array $state ): void {
	$group_results = import_group( $site_id, $data['group'] );

	if ( is_wp_error( $group_results ) ) {
		add_settings_error( 'wcorg-meetup-import', $group_results->get_error_code(), $group_results->get_error_message() );
		request_state( $state );
		return;
	}

	$state['group_results'] = $group_results;

	if ( $match_people ) {
		$state['leader_counts'] = import_leaders( $site_id, $data['leaders'] );
		$state['member_counts'] = import_members( $site_id, $data['members'] );
	}

	$results = import_events( $site_id, $data['events'], $match_people );

	if ( is_wp_error( $results ) ) {
		add_settings_error( 'wcorg-meetup-import', $results->get_error_code(), $results->get_error_message() );
		request_state( $state );
		return;
	}

	$counts = array_count_values( wp_list_pluck( $results, 'status' ) );

	add_settings_error(
		'wcorg-meetup-import',
		'success',
		sprintf(
			/* translators: 1: Created count, 2: Updated count, 3: Skipped count, 4: Failed count, 5: Site's events admin URL. */
			__( 'Events imported: %1$d created, %2$d updated, %3$d skipped, %4$d failed. <a href="%5$s">Review the drafts</a>', 'wordcamporg' ),
			$counts['created'] ?? 0,
			$counts['updated'] ?? 0,
			$counts['skipped'] ?? 0,
			$counts['failed'] ?? 0,
			esc_url( get_admin_url( $site_id, 'edit.php?post_type=' . Event::POST_TYPE . '&post_status=draft' ) )
		),
		'success'
	);

	$state['results'] = $results;

	if ( $match_people ) {
		$state['rsvp_counts'] = sum_rsvp_counts( $results );
	}

	request_state( $state );
}

/**
 * Render the import form, and the preview or results below it.
 */
function render_admin_page() {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ) );
	}

	$state        = request_state();
	$urlname      = $state['urlname'] ?? '';
	$site_id      = $state['site_id'] ?? 0;
	$include_past = ! empty( $state['include_past'] );
	$match_people = ! empty( $state['match_people'] );
	$rows         = $state['plan'] ?? $state['results'] ?? array();
	$is_preview   = isset( $state['plan'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import from Meetup', 'wordcamporg' ); ?></h1>

		<?php if ( ! is_meetup_connected() ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				printf(
					/* translators: %s: URL of the Meetup connection settings screen. */
					wp_kses_post( __( 'Meetup is not connected on this network. <a href="%s">Connect it</a> before importing.', 'wordcamporg' ) ),
					esc_url( \WordCamp\Meetup_OAuth\get_page_url() )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php settings_errors( 'wcorg-meetup-import' ); ?>

		<p>
			<?php esc_html_e( 'Copies a Meetup group into a group site: its location, About text, and photo where the site has none yet, and its upcoming events and venues as drafts. Optionally also its past events, and the people in it who have WordPress.org accounts. No emails are sent. Running it again refreshes events that are still drafts and leaves published ones alone.', 'wordcamporg' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( NONCE_ACTION, NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="meetup_group"><?php esc_html_e( 'Meetup group', 'wordcamporg' ); ?></label></th>
					<td>
						<input name="meetup_group" type="text" id="meetup_group" class="regular-text" required="required" value="<?php echo esc_attr( $urlname ); ?>" placeholder="https://www.meetup.com/wordpress-group/" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="site_id"><?php esc_html_e( 'Import into', 'wordcamporg' ); ?></label></th>
					<td>
						<select name="site_id" id="site_id" required="required">
							<option value=""><?php esc_html_e( 'Select a group site', 'wordcamporg' ); ?></option>
							<?php foreach ( \WordCamp\Groups\Archive\get_group_sites() as $group ) : ?>
								<option value="<?php echo esc_attr( $group->blog_id ); ?>" <?php selected( (int) $group->blog_id, $site_id ); ?>>
									<?php echo esc_html( get_blog_option( $group->blog_id, 'blogname' ) ?: $group->domain . $group->path ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Also import', 'wordcamporg' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input name="include_past" type="checkbox" value="1" <?php checked( $include_past ); ?> />
								<?php esc_html_e( 'Past events, published as the group\'s history, with Meetup\'s RSVP and attendance counts', 'wordcamporg' ); ?>
							</label>
							<br />
							<label>
								<input name="match_people" type="checkbox" value="1" <?php checked( $match_people ); ?> />
								<?php esc_html_e( 'Match people to WordPress.org accounts by email: members join the group (event emails off), Meetup organizers get the matching Organizer role, hosts are credited on their events, and RSVPs are kept. Nobody gets a new account.', 'wordcamporg' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="wcorg_meetup_import_step" value="preview" class="button button-secondary"><?php esc_html_e( 'Preview', 'wordcamporg' ); ?></button>
				<?php if ( $is_preview ) : ?>
					<button type="submit" name="wcorg_meetup_import_step" value="import" class="button button-primary"><?php esc_html_e( 'Import', 'wordcamporg' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( isset( $state['plan'] ) || isset( $state['results'] ) ) : ?>
			<h2><?php echo esc_html( isset( $state['plan'] ) ? __( 'Preview', 'wordcamporg' ) : __( 'Results', 'wordcamporg' ) ); ?></h2>

			<?php if ( isset( $state['group_results'] ) ) : ?>
				<h3><?php esc_html_e( 'Group', 'wordcamporg' ); ?></h3>
				<ul class="ul-disc">
					<?php foreach ( describe_group_results( $state['group_results'], $is_preview ) as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( isset( $state['member_counts'] ) ) : ?>
				<h3><?php esc_html_e( 'People', 'wordcamporg' ); ?></h3>
				<ul class="ul-disc">
					<li><?php echo esc_html( describe_leader_counts( $state['leader_counts'] ?? array(), $is_preview ) ); ?></li>
					<li><?php echo esc_html( describe_member_counts( $state['member_counts'], $is_preview ) ); ?></li>
					<li>
						<?php
						echo esc_html(
							isset( $state['rsvp_counts'] )
								? describe_rsvp_counts( $state['rsvp_counts'] )
								: __( 'RSVPs are fetched per event, so they are matched during the import rather than previewed.', 'wordcamporg' )
						);
						?>
					</li>
				</ul>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Events', 'wordcamporg' ); ?></h3>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'wordcamporg' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event', 'wordcamporg' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Venue', 'wordcamporg' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'wordcamporg' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'This group has no events to import on Meetup.', 'wordcamporg' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['event']['start'] ); ?></td>
							<td><a href="<?php echo esc_url( $row['event']['meetup_url'] ); ?>"><?php echo esc_html( $row['event']['title'] ); ?></a></td>
							<td>
								<?php
								$venue_parts = array_filter( array( $row['event']['venue']['name'] ?? '', $row['event']['is_online'] ? __( 'Online', 'wordcamporg' ) : '' ) );
								echo esc_html( implode( ' + ', $venue_parts ) );
								?>
							</td>
							<td><?php echo esc_html( describe_status( $row['status'], $row['event']['is_past'], $row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Human label for a planned or completed action.
 *
 * @param string $status  One of the statuses `plan_events()`/`import_events()` return.
 * @param bool   $is_past Whether the event has already happened on Meetup.
 * @param string $message Failure detail, if any.
 */
function describe_status( string $status, bool $is_past = false, string $message = '' ): string {
	$labels = array(
		'create'  => $is_past ? __( 'Will be published as a past event', 'wordcamporg' ) : __( 'Will be created as a draft', 'wordcamporg' ),
		'update'  => __( 'Draft will be refreshed', 'wordcamporg' ),
		'skip'    => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'created' => $is_past ? __( 'Published as a past event', 'wordcamporg' ) : __( 'Created as a draft', 'wordcamporg' ),
		'updated' => __( 'Draft refreshed', 'wordcamporg' ),
		'skipped' => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'failed'  => __( 'Failed', 'wordcamporg' ),
	);

	$label = $labels[ $status ] ?? $status;

	return $message ? "$label: $message" : $label;
}

/**
 * One-line summary of what happened, or would happen, to the group's members.
 *
 * @param array $counts    From `plan_members()` or `import_members()`.
 * @param bool  $is_preview Whether this is a preview.
 */
function describe_member_counts( array $counts, bool $is_preview ): string {
	return sprintf(
		/* translators: 1: Total members, 2: Members joining, 3: Already members, 4: Considered by an earlier import, 5: No matching account, 6: No email shared. */
		$is_preview
			? __( '%1$d Meetup members: %2$d will join the group, %3$d are already members, %4$d were handled by an earlier import, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' )
			: __( '%1$d Meetup members: %2$d joined the group, %3$d were already members, %4$d were handled by an earlier import, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' ),
		array_sum( $counts ),
		$counts['join'],
		$counts['already_member'],
		$counts['previously_imported'],
		$counts['unmatched'],
		$counts['no_email']
	);
}

/**
 * One line per group detail, saying what happened (or would happen) to it.
 *
 * @param array $results    From `plan_group()` or `import_group()`: detail => outcome.
 * @param bool  $is_preview Whether this is a preview.
 * @return string[]
 */
function describe_group_results( array $results, bool $is_preview ): array {
	$labels = array(
		'location' => array(
			'set'     => $is_preview ? __( 'Location: will be set from Meetup.', 'wordcamporg' ) : __( 'Location: set from Meetup.', 'wordcamporg' ),
			'kept'    => __( 'Location: already set on the site, left as is.', 'wordcamporg' ),
			'missing' => __( 'Location: Meetup has no usable city and country.', 'wordcamporg' ),
		),
		'about'    => array(
			'set'     => $is_preview ? __( 'About page: the example text will be replaced with Meetup\'s group description.', 'wordcamporg' ) : __( 'About page: example text replaced with Meetup\'s group description.', 'wordcamporg' ),
			'kept'    => __( 'About page: already edited, or missing, left as is.', 'wordcamporg' ),
			'missing' => __( 'About page: Meetup has no group description.', 'wordcamporg' ),
		),
		'icon'     => array(
			'set'     => $is_preview ? __( 'Site icon: will be set from Meetup\'s group photo.', 'wordcamporg' ) : __( 'Site icon: set from Meetup\'s group photo.', 'wordcamporg' ),
			'kept'    => __( 'Site icon: already set, left as is.', 'wordcamporg' ),
			'missing' => __( 'Site icon: Meetup has no group photo.', 'wordcamporg' ),
			'failed'  => __( 'Site icon: the group photo could not be downloaded.', 'wordcamporg' ),
		),
	);

	$lines = array();

	foreach ( $labels as $detail => $outcomes ) {
		if ( isset( $results[ $detail ], $outcomes[ $results[ $detail ] ] ) ) {
			$lines[] = $outcomes[ $results[ $detail ] ];
		}
	}

	return $lines;
}

/**
 * One-line summary of what happened, or would happen, to the group's Meetup organizers.
 *
 * @param array $counts     From `plan_leaders()` or `import_leaders()`.
 * @param bool  $is_preview Whether this is a preview.
 */
function describe_leader_counts( array $counts, bool $is_preview ): string {
	$counts = array_merge( array_fill_keys( array( 'added', 'promoted', 'kept', 'unmatched', 'no_email' ), 0 ), $counts );

	return sprintf(
		/* translators: 1: Total organizers, 2: Added to the group, 3: Given a higher role, 4: Already in that role or higher, 5: No matching account, 6: No email shared. */
		$is_preview
			? __( '%1$d Meetup organizers: %2$d will join with their organizer role, %3$d will be promoted, %4$d already have that role or higher, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' )
			: __( '%1$d Meetup organizers: %2$d joined with their organizer role, %3$d were promoted, %4$d already had that role or higher, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' ),
		array_sum( $counts ),
		$counts['added'],
		$counts['promoted'],
		$counts['kept'],
		$counts['unmatched'],
		$counts['no_email']
	);
}

/**
 * One-line summary of the RSVPs an import carried over.
 *
 * @param array $counts From `sum_rsvp_counts()`.
 */
function describe_rsvp_counts( array $counts ): string {
	return sprintf(
		/* translators: 1: RSVPs saved, 2: Of which checked in, 3: No matching account, 4: Status with no equivalent, e.g. "maybe", 5: Could not be fetched or saved. */
		__( 'RSVPs: %1$d kept (%2$d checked in as attended), %3$d had no matching WordPress.org account, %4$d had a status with no equivalent here, %5$d failed.', 'wordcamporg' ),
		$counts['saved'],
		$counts['checked_in'],
		$counts['unmatched'],
		$counts['skipped'],
		$counts['failed']
	);
}

/**
 * Whether the network has a Meetup connection to import with.
 *
 * The token lives in a network option managed by Network Settings > Meetup
 * (`wcorg-meetup-oauth.php`); it's read directly rather than through the
 * client so that rendering the form doesn't make an API call.
 */
function is_meetup_connected(): bool {
	return \WordCamp\Meetup_OAuth\has_credentials()
		&& ! empty( get_site_option( \WordCamp\Meetup_OAuth\TOKEN_SITE_OPTION ) );
}

/**
 * Turn what an admin pasted into a Meetup group urlname.
 *
 * Accepts a bare urlname or any meetup.com URL under the group, e.g. an event
 * page.
 *
 * @param string $input Group URL or urlname.
 */
function parse_group_urlname( string $input ): string {
	$input = trim( $input );

	if ( preg_match( '#^(?:https?://)?(?:www\.)?meetup\.com/([^/?\#]+)#i', $input, $matches ) ) {
		$input = $matches[1];
	}

	$input = trim( $input, '/' );

	return preg_match( '/^[A-Za-z0-9_-]+$/', $input ) ? $input : '';
}

/**
 * A Meetup client, if the network is connected.
 *
 * @return Meetup_Client|WP_Error
 */
function get_meetup_client() {
	if ( ! is_meetup_connected() ) {
		return new WP_Error( 'meetup_not_connected', __( 'Meetup is not connected on this network.', 'wordcamporg' ) );
	}

	$client = new Meetup_Client();

	if ( $client->error->has_errors() ) {
		return $client->error;
	}

	return $client;
}

/**
 * Fetch a group's events from the Meetup API.
 *
 * @param string $urlname      Meetup group urlname.
 * @param bool   $include_past Whether to include events that have already happened.
 * @return array|WP_Error Raw event nodes, as `Meetup_Client::get_group_events()` returns them.
 */
function fetch_group_events( string $urlname, bool $include_past = false ) {
	/**
	 * Short-circuit the Meetup events request, e.g. with fixture data.
	 *
	 * The Meetup client can't be constructed without the network's Meetup
	 * credentials, which tests and local environments don't have.
	 *
	 * @param array|WP_Error|null $events       Raw event nodes, or null to fetch from Meetup.
	 * @param string              $urlname      Meetup group urlname.
	 * @param bool                $include_past Whether past events were asked for.
	 */
	$events = apply_filters( 'wcorg_meetup_import_pre_fetch', null, $urlname, $include_past );

	if ( null === $events ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$events = $client->get_group_events(
			$urlname,
			array(
				'status' => $include_past ? 'upcoming,past' : 'upcoming',
				'fields' => array(
					'howToFindUs',
					'venues { address postalCode }',
					'featuredEventPhoto { id highResUrl }',
					'eventHosts { member { id email } }',
				),
			)
		);
	}

	if ( is_wp_error( $events ) ) {
		return $events;
	}

	if ( ! is_array( $events ) || ! array_is_list( $events ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	if ( ! $include_past ) {
		$events = array_values(
			array_filter(
				$events,
				static function ( $event ) {
					return 'PAST' !== strtoupper( (string) ( $event['status'] ?? '' ) );
				}
			)
		);
	}

	Logger\log(
		'fetched_events',
		array(
			'urlname' => $urlname,
			'count'   => count( $events ),
		)
	);

	return $events;
}

/**
 * Fetch a group's members from the Meetup API.
 *
 * Meetup only shares a member's email with the Pro network when the member
 * allows it, so expect some members without one.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error Raw member nodes, as `Meetup_Client::get_group_members()` returns them.
 */
function fetch_group_members( string $urlname ) {
	/**
	 * Short-circuit the Meetup members request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $members Raw member nodes (`id`, `email`), or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$members = apply_filters( 'wcorg_meetup_import_pre_fetch_members', null, $urlname );

	if ( null === $members ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$members = $client->get_group_members( $urlname );
	}

	if ( is_wp_error( $members ) ) {
		return $members;
	}

	if ( ! is_array( $members ) || ! array_is_list( $members ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	Logger\log(
		'fetched_members',
		array(
			'urlname' => $urlname,
			'count'   => count( $members ),
		)
	);

	return $members;
}

/**
 * Keep only what member matching needs: the Meetup ID and the email.
 *
 * @param array $raw Member node from the Meetup API.
 * @return array
 */
function normalize_member( array $raw ): array {
	return array(
		'meetup_id' => (string) ( $raw['id'] ?? '' ),
		'email'     => strtolower( (string) sanitize_email( (string) ( $raw['email'] ?? '' ) ) ),
	);
}

/**
 * Fetch a group's own details from the Meetup API.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error The `groupByUrlname` node.
 */
function fetch_group( string $urlname ) {
	/**
	 * Short-circuit the Meetup group request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $group   The `groupByUrlname` node, or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$group = apply_filters( 'wcorg_meetup_import_pre_fetch_group', null, $urlname );

	if ( null === $group ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $urlname: String! ) {
				groupByUrlname( urlname: $urlname ) {
					id name urlname description city country foundedDate
					keyGroupPhoto { id highResUrl }
				}
			}',
			array( 'urlname' => $urlname )
		);

		$group = is_wp_error( $result ) ? $result : ( $result['groupByUrlname'] ?? null );
	}

	if ( is_wp_error( $group ) ) {
		return $group;
	}

	if ( ! is_array( $group ) || empty( $group['id'] ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	return $group;
}

/**
 * Fetch a group's organizers from the Meetup API, with their roles.
 *
 * The Meetup account the connection belongs to is left out. On a Pro network
 * that account is listed as an organizer of every group, and matching it by
 * email would hand whoever owns that address an Organizer role on every
 * imported site.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error Raw edges: `{ metadata: { role }, node: { id, email } }`.
 */
function fetch_group_leaders( string $urlname ) {
	/**
	 * Short-circuit the Meetup leaders request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $leaders Raw edges, or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$leaders = apply_filters( 'wcorg_meetup_import_pre_fetch_leaders', null, $urlname );

	if ( null === $leaders ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $urlname: String!, $cursor: String ) {
				self { id }
				groupByUrlname( urlname: $urlname ) {
					memberships ( first: 100, after: $cursor, filter: { status: LEADER } ) {
						' . $client->pagination . '
						edges { metadata { role } node { id email } }
					}
				}
			}',
			array(
				'urlname' => $urlname,
				'cursor'  => null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$self_id = (string) ( $result['self']['id'] ?? '' );
		$leaders = array_values(
			array_filter(
				$result['groupByUrlname']['memberships']['edges'] ?? array(),
				static function ( $edge ) use ( $self_id ) {
					return '' === $self_id || (string) ( $edge['node']['id'] ?? '' ) !== $self_id;
				}
			)
		);
	}

	return is_array( $leaders ) || is_wp_error( $leaders ) ? $leaders : array();
}

/**
 * Fetch one event's RSVPs from the Meetup API.
 *
 * @param string $meetup_event_id Meetup event ID.
 * @return array|WP_Error Raw RSVP nodes: `{ status, guestsCount, member: { id, email } }`.
 */
function fetch_event_rsvps( string $meetup_event_id ) {
	/**
	 * Short-circuit a Meetup RSVPs request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $rsvps           Raw RSVP nodes, or null to fetch from Meetup.
	 * @param string              $meetup_event_id Meetup event ID.
	 */
	$rsvps = apply_filters( 'wcorg_meetup_import_pre_fetch_rsvps', null, $meetup_event_id );

	if ( null === $rsvps ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $eventId: ID!, $cursor: String ) {
				event( id: $eventId ) {
					rsvps ( first: 100, after: $cursor ) {
						' . $client->pagination . '
						edges { node { status guestsCount member { id email } } }
					}
				}
			}',
			array(
				'eventId' => $meetup_event_id,
				'cursor'  => null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rsvps = array_column( $result['event']['rsvps']['edges'] ?? array(), 'node' );
	}

	return is_array( $rsvps ) || is_wp_error( $rsvps ) ? $rsvps : array();
}

/**
 * Map a Meetup group node to the details the importer writes.
 *
 * @param array $raw The `groupByUrlname` node.
 * @return array
 */
function normalize_group( array $raw ): array {
	$founded = '';

	if ( ! empty( $raw['foundedDate'] ) ) {
		try {
			$founded = ( new DateTimeImmutable( (string) $raw['foundedDate'] ) )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			$founded = '';
		}
	}

	return array(
		'meetup_id'    => (string) ( $raw['id'] ?? '' ),
		'urlname'      => (string) ( $raw['urlname'] ?? '' ),
		'description'  => '' !== trim( (string) ( $raw['description'] ?? '' ) ) ? wp_kses_post( wpautop( (string) $raw['description'] ) ) : '',
		'city'         => trim( (string) ( $raw['city'] ?? '' ) ),
		'country_code' => strtoupper( trim( (string) ( $raw['country'] ?? '' ) ) ),
		'founded'      => $founded,
		'photo'        => normalize_photo( $raw['keyGroupPhoto'] ?? null ),
	);
}

/**
 * Keep what leader matching needs: the Meetup ID, the email, and the site role.
 *
 * @param array $raw Edge from `fetch_group_leaders()`.
 * @return array `role` is null for a Meetup role with no organizer equivalent.
 */
function normalize_leader( array $raw ): array {
	return array(
		'meetup_id' => (string) ( $raw['node']['id'] ?? '' ),
		'email'     => strtolower( (string) sanitize_email( (string) ( $raw['node']['email'] ?? '' ) ) ),
		'role'      => LEADER_ROLES[ strtoupper( (string) ( $raw['metadata']['role'] ?? '' ) ) ] ?? null,
	);
}

/**
 * Map a Meetup RSVP to the GatherPress RSVP it becomes.
 *
 * @param array $raw RSVP node from `fetch_event_rsvps()`.
 * @return array `status` is null for a Meetup status with no equivalent.
 */
function normalize_rsvp( array $raw ): array {
	$mapped = RSVP_STATUSES[ strtoupper( (string) ( $raw['status'] ?? '' ) ) ] ?? array( null, false );

	return array(
		'email'      => strtolower( (string) sanitize_email( (string) ( $raw['member']['email'] ?? '' ) ) ),
		'status'     => $mapped[0],
		'checked_in' => $mapped[1],
		'guests'     => max( 0, (int) ( $raw['guestsCount'] ?? 0 ) ),
	);
}

/**
 * Map a Meetup event node to the fields the importer writes.
 *
 * Times are converted to the group's own timezone, which is what GatherPress
 * stores and displays.
 *
 * @param array $raw Event node from the Meetup API.
 * @return array
 */
function normalize_event( array $raw ): array {
	$timezone = (string) ( $raw['group']['timezone'] ?? '' );

	try {
		$zone = new DateTimeZone( '' !== $timezone ? $timezone : wp_timezone_string() );
	} catch ( Exception $e ) {
		$zone = wp_timezone();
	}

	$start = new DateTimeImmutable( (string) $raw['dateTime'] );
	$end   = ! empty( $raw['endTime'] )
		? new DateTimeImmutable( (string) $raw['endTime'] )
		: $start->modify( '+' . (int) ( $raw['duration'] ?? 7200 ) . ' seconds' );

	$event_type = strtoupper( (string) ( $raw['eventType'] ?? '' ) );
	$is_online  = in_array( $event_type, array( 'ONLINE', 'HYBRID' ), true );

	$venue = null;
	foreach ( $raw['venues'] ?? array() as $candidate ) {
		// Meetup attaches a placeholder "Online event" venue to online events.
		if ( empty( $candidate['id'] ) || ONLINE_VENUE_ID === (int) $candidate['id'] || empty( $candidate['name'] ) ) {
			continue;
		}

		$locality = trim( implode( ' ', array_filter( array( $candidate['state'] ?? '', $candidate['postalCode'] ?? '' ) ) ) );
		$venue    = array(
			'meetup_id' => (string) $candidate['id'],
			'name'      => (string) $candidate['name'],
			'address'   => implode(
				', ',
				array_filter(
					array(
						$candidate['address'] ?? '',
						$candidate['city'] ?? '',
						$locality,
						strtoupper( (string) ( $candidate['country'] ?? '' ) ),
					)
				)
			),
		);
		break;
	}

	$description = wpautop( (string) ( $raw['description'] ?? '' ) );

	if ( ! empty( $raw['howToFindUs'] ) ) {
		$description .= wpautop(
			sprintf(
				/* translators: %s: Directions to the venue, as written on Meetup. */
				__( 'How to find us: %s', 'wordcamporg' ),
				$raw['howToFindUs']
			)
		);
	}

	return array(
		'meetup_id'   => (string) $raw['id'],
		'meetup_url'  => (string) ( $raw['eventUrl'] ?? '' ),
		'title'       => (string) ( $raw['title'] ?? '' ),
		'description' => wp_kses_post( $description ),
		'timezone'    => $zone->getName(),
		'start'       => $start->setTimezone( $zone )->format( 'Y-m-d H:i:s' ),
		'end'         => $end->setTimezone( $zone )->format( 'Y-m-d H:i:s' ),
		'is_online'   => $is_online,
		'venue'       => $venue,
		'is_past'     => 'PAST' === strtoupper( (string) ( $raw['status'] ?? '' ) ),
		'rsvp_yes'    => (int) ( $raw['rsvps']['yesCount'] ?? 0 ),
		'attended'    => (int) ( $raw['rsvps']['attendedCount'] ?? 0 ),
		'photo'       => normalize_photo( $raw['featuredEventPhoto'] ?? null ),
		'hosts'       => array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $host ) {
							return strtolower( (string) sanitize_email( (string) ( $host['member']['email'] ?? '' ) ) );
						},
						(array) ( $raw['eventHosts'] ?? array() )
					)
				)
			)
		),
	);
}

/**
 * Keep an event's featured photo only if it's an image Meetup hosts.
 *
 * The URL is something this server will download, so it has to point at
 * Meetup's own image host over HTTPS, not anywhere a response could name.
 *
 * @param mixed $photo `featuredEventPhoto` from the Meetup API.
 * @return array|null `{ meetup_id, url }`, or null for no usable photo.
 */
function normalize_photo( $photo ): ?array {
	if ( ! is_array( $photo ) || empty( $photo['id'] ) || empty( $photo['highResUrl'] ) ) {
		return null;
	}

	$url  = (string) $photo['highResUrl'];
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );

	if (
		'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ||
		( 'meetupstatic.com' !== $host && ! str_ends_with( $host, '.meetupstatic.com' ) )
	) {
		return null;
	}

	return array(
		'meetup_id' => (string) $photo['id'],
		'url'       => esc_url_raw( $url ),
	);
}

/**
 * Check that a site is a live group site that can take an import.
 *
 * @param int $site_id Target site ID.
 * @return true|WP_Error
 */
function validate_target_site( int $site_id ) {
	$site = get_site( $site_id );

	if (
		! $site ||
		GROUPS_NETWORK_ID !== (int) $site->network_id ||
		GROUPS_ROOT_BLOG_ID === (int) $site->blog_id ||
		$site->deleted ||
		$site->archived ||
		$site->spam
	) {
		return new WP_Error( 'invalid_group_site', __( 'Please choose an active group site to import into.', 'wordcamporg' ) );
	}

	return true;
}

/**
 * Work out what an import would do, without writing anything.
 *
 * @param int   $site_id Target group site.
 * @param array $events  Normalized events.
 * @return array[] One `{ event, status }` per event, status `create`, `update`, or `skip`.
 */
function plan_events( int $site_id, array $events ): array {
	return with_site(
		$site_id,
		function () use ( $events ) {
			$plan = array();

			foreach ( $events as $event ) {
				$existing = find_imported_post( Event::POST_TYPE, EVENT_ID_META, $event['meetup_id'] );

				// A past event an interrupted run left half-done is, as far as
				// anyone is concerned, still to be created.
				if ( ! $existing || get_post_meta( $existing->ID, PENDING_PUBLISH_META, true ) ) {
					$status = 'create';
				} elseif ( 'draft' === $existing->post_status ) {
					$status = 'update';
				} else {
					$status = 'skip';
				}

				$plan[] = compact( 'event', 'status' );
			}

			return $plan;
		}
	);
}

/**
 * Create or refresh draft events on a group site.
 *
 * Callers MUST verify the `manage_sites` capability before calling this -- it
 * performs no capability check of its own, matching `create_group_site()`.
 *
 * @param int   $site_id      Target group site.
 * @param array $events       Normalized events, from `normalize_event()`.
 * @param bool  $match_people Whether to credit hosts and carry RSVPs over, matching people by email.
 * @return array[]|WP_Error One `{ event, status, post_id, message, rsvps }` per event,
 *                          status `created`, `updated`, `skipped`, or `failed`.
 */
function import_events( int $site_id, array $events, bool $match_people = false ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	if ( ! class_exists( Event::class ) ) {
		return new WP_Error( 'gatherpress_missing', __( 'GatherPress is not loaded.', 'wordcamporg' ) );
	}

	$results = with_site(
		$site_id,
		function () use ( $events, $match_people ) {
			/*
			 * The recurring-events tables are per-site and created lazily, from
			 * hooks that don't fire for a `switch_to_blog()`. See the same call
			 * in `network-export.php`.
			 */
			if ( class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
				\WordPressdotorg\GatherPress_Recurring_Events\Database::maybe_install();
			}

			return array_map(
				static function ( $event ) use ( $match_people ) {
					return import_event( $event, $match_people );
				},
				$events
			);
		}
	);

	Logger\log(
		'finished',
		array(
			'site_id' => $site_id,
			'counts'  => array_count_values( wp_list_pluck( $results, 'status' ) ),
		)
	);

	return $results;
}

/**
 * Create or refresh one event on the current site.
 *
 * Writes the same pieces the frontend's draft save does
 * (`Frontend\REST\save_draft()`), so an imported draft is indistinguishable
 * from one an organizer started by hand. No `wporg_groups_frontend_event_draft_saved`
 * action fires, upcoming events stay drafts, and past events are published
 * with their notification already marked handled, so no emails go out.
 *
 * @param array $event        Normalized event.
 * @param bool  $match_people Whether to credit hosts and carry RSVPs over.
 * @return array
 */
function import_event( array $event, bool $match_people = false ): array {
	$existing = find_imported_post( Event::POST_TYPE, EVENT_ID_META, $event['meetup_id'] );

	if ( $existing && 'draft' !== $existing->post_status ) {
		return array(
			'event'   => $event,
			'status'  => 'skipped',
			'post_id' => $existing->ID,
		);
	}

	// A past event is history, not something to review, so it ends up
	// published. It's created as a draft marked to publish, and only published
	// once every other piece is in place, so an import that dies partway
	// leaves a marked draft that the next run finishes -- not a published
	// event missing its date or venue. An event that was imported as an
	// upcoming draft and has happened since carries no mark, and stays the
	// organizer's to publish.
	$publish = $existing ? (bool) get_post_meta( $existing->ID, PENDING_PUBLISH_META, true ) : $event['is_past'];

	$post_args = array(
		'post_type'    => Event::POST_TYPE,
		'post_status'  => 'draft',
		// `sanitize_text_field()` is not enough on its own here. See `wcorg_sanitize_plain_text()`.
		'post_title'   => wcorg_sanitize_plain_text( $event['title'] ),
		'post_content' => $event['description'],
	);

	if ( $existing ) {
		$post_args['ID'] = $existing->ID;
		$post_id         = wp_update_post( wp_slash( $post_args ), true );
	} else {
		// The Meetup ID goes in with the post itself, so there's no moment
		// where the event exists but a rerun couldn't find it and would
		// create it again.
		$post_args['meta_input'] = array(
			EVENT_ID_META  => $event['meetup_id'],
			EVENT_URL_META => esc_url_raw( $event['meetup_url'] ),
		);

		if ( $publish ) {
			// First publish emails every member of the group. This marks that
			// notification as already handled, so there's nothing to send.
			$post_args['meta_input'][ PENDING_PUBLISH_META ]                = 1;
			$post_args['meta_input'][ PUBLISH_NOTIFICATION_SCHEDULED_META ] = 1;
		}

		$post_id = wp_insert_post( wp_slash( $post_args ), true );
	}

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return array(
			'event'   => $event,
			'status'  => 'failed',
			'post_id' => 0,
			'message' => is_wp_error( $post_id ) ? $post_id->get_error_message() : '',
		);
	}

	$post_id = (int) $post_id;

	update_post_meta( $post_id, EVENT_URL_META, esc_url_raw( $event['meetup_url'] ) );

	( new Event( $post_id ) )->save_datetimes(
		array(
			'post_id'        => $post_id,
			'datetime_start' => $event['start'],
			'datetime_end'   => $event['end'],
			'timezone'       => storable_timezone( $event['timezone'] ),
		)
	);

	sync_event_venue_terms( $post_id, find_or_create_venue( $event['venue'] ), $event['is_online'] );

	// Meetup doesn't hand out the meeting link, so the organizer adds it while reviewing.
	sync_online_event_link( $post_id, $event['is_online'], '' );

	// Only fill an empty slot: a refreshed draft keeps whatever image the
	// organizer has picked since.
	if ( $event['photo'] && ! has_post_thumbnail( $post_id ) ) {
		$attachment_id = find_or_sideload_photo( $event['photo'], $post_id );

		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	// Meetup's own counts, kept for history and reporting. There are no
	// individual RSVP records behind them.
	update_post_meta( $post_id, RSVP_YES_META, $event['rsvp_yes'] );
	update_post_meta( $post_id, ATTENDED_META, $event['attended'] );

	// People last, while the event is still a draft: RSVP confirmations only
	// go out for published events (see `import_rsvps()`).
	$rsvps = null;

	if ( $match_people ) {
		import_hosts( $post_id, $event['hosts'] );
		$rsvps = import_rsvps( $post_id, $event['meetup_id'] );
	}

	if ( $publish ) {
		$published = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $published ) ) {
			return array(
				'event'   => $event,
				'status'  => 'failed',
				'post_id' => $post_id,
				'message' => $published->get_error_message(),
			);
		}

		delete_post_meta( $post_id, PENDING_PUBLISH_META );
	}

	return array(
		'event'   => $event,
		'status'  => $existing ? 'updated' : 'created',
		'post_id' => $post_id,
		'rsvps'   => $rsvps,
	);
}

/**
 * Work out what an import would do with the group's own details.
 *
 * @param int   $site_id Target group site.
 * @param array $group   Normalized group, from `normalize_group()`.
 * @return string[] Detail => outcome (`set`, `kept`, `missing`).
 */
function plan_group( int $site_id, array $group ): array {
	return with_site(
		$site_id,
		static function () use ( $group ) {
			return apply_group_details( $group, false );
		}
	);
}

/**
 * Bring the group's own details over, wherever the site doesn't have them yet.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * @param int   $site_id Target group site.
 * @param array $group   Normalized group, from `normalize_group()`.
 * @return string[]|WP_Error Detail => outcome (`set`, `kept`, `missing`, `failed`).
 */
function import_group( int $site_id, array $group ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	$results = with_site(
		$site_id,
		static function () use ( $group ) {
			return apply_group_details( $group, true );
		}
	);

	Logger\log( 'group_imported', compact( 'site_id', 'results' ) );

	return $results;
}

/**
 * Decide, and optionally write, each group detail on the current site.
 *
 * One function for both the preview and the import, so they can't disagree.
 * Every detail only fills a gap: anything the organizer has already set is
 * theirs and stays.
 *
 * - Location: the same site meta the group settings tab writes, validated the
 *   same way.
 * - About page: only the draft seeded by Add Group Site, and only while it's
 *   untouched (never modified since it was created).
 * - Site icon: Meetup's key group photo, downloaded like an event photo.
 *
 * @param array $group Normalized group.
 * @param bool  $write Whether to write, or only report.
 * @return string[]
 */
function apply_group_details( array $group, bool $write ): array {
	$results = array();

	// Location.
	if ( null !== Group_Location\get_location() ) {
		$results['location'] = 'kept';
	} else {
		$location = Group_Location\normalize_location(
			array(
				'type'        => Group_Location\TYPE_PHYSICAL,
				'city'        => $group['city'],
				'countryCode' => $group['country_code'],
			)
		);

		if ( is_wp_error( $location ) ) {
			$results['location'] = 'missing';
		} else {
			if ( $write ) {
				Group_Location\save_location( $location );
			}

			$results['location'] = 'set';
		}
	}

	// About page.
	$about = get_page_by_path( 'about', OBJECT, 'page' );

	if ( '' === $group['description'] ) {
		$results['about'] = 'missing';
	} elseif ( ! $about || 'draft' !== $about->post_status || $about->post_modified !== $about->post_date ) {
		$results['about'] = 'kept';
	} else {
		if ( $write ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $about->ID,
						'post_content' => str_replace(
							array( '<p>', '</p>' ),
							array( '<!-- wp:paragraph --><p>', '</p><!-- /wp:paragraph -->' ),
							$group['description']
						),
					)
				)
			);
		}

		$results['about'] = 'set';
	}

	// Site icon.
	if ( get_option( 'site_icon' ) ) {
		$results['icon'] = 'kept';
	} elseif ( ! $group['photo'] ) {
		$results['icon'] = 'missing';
	} elseif ( ! $write ) {
		$results['icon'] = 'set';
	} else {
		$attachment_id = find_or_sideload_photo( $group['photo'], 0 );

		if ( $attachment_id ) {
			update_option( 'site_icon', $attachment_id );
		}

		$results['icon'] = $attachment_id ? 'set' : 'failed';
	}

	// Where the site came from, for the group's history.
	if ( $write ) {
		if ( '' !== $group['urlname'] ) {
			update_option( GROUP_URLNAME_OPTION, $group['urlname'] );
		}

		if ( '' !== $group['founded'] ) {
			update_option( FOUNDED_OPTION, $group['founded'] );
		}
	}

	return $results;
}

/**
 * Add a user to a group site, opted out of its event emails.
 *
 * The one way an import puts someone in a group, whether as a member, an
 * organizer, or a host. The opt-out goes first: GatherPress opts users in by
 * default, nobody should start getting mail because their address turned up
 * in a Meetup export, and if the run died right after adding them, the next
 * run would see an existing member and leave them be.
 *
 * Someone who is already in the group is left exactly as they are.
 *
 * @param int    $site_id Group site.
 * @param int    $user_id User to add.
 * @param string $role    Site role to add them with.
 * @return bool Whether they're in the group now.
 */
function ensure_member( int $site_id, int $user_id, string $role = 'subscriber' ): bool {
	if ( is_user_member_of_blog( $user_id, $site_id ) ) {
		return true;
	}

	update_user_meta( $user_id, get_scoped_opt_in_meta_key( $site_id ), '0' );

	return ! is_wp_error( add_user_to_blog( $site_id, $user_id, $role ) );
}

/**
 * The highest-ranking role a user has on a site.
 *
 * @param int $user_id User.
 * @param int $site_id Site.
 * @return int Rank from `ROLE_RANK`, or -1 for none.
 */
function role_rank( int $user_id, int $site_id ): int {
	$roles = ( new WP_User( $user_id, '', $site_id ) )->roles;
	$ranks = array_map(
		static function ( $role ) {
			return ROLE_RANK[ $role ] ?? 0;
		},
		$roles
	);

	return $ranks ? max( $ranks ) : -1;
}

/**
 * Sort a group's Meetup organizers by what an import does with each one.
 *
 * @param int   $site_id Target group site.
 * @param array $leaders Normalized leaders, from `normalize_leader()`.
 * @return array[] One `{ leader, outcome, user_id }` per leader with an organizer role.
 */
function classify_leaders( int $site_id, array $leaders ): array {
	$classified = array();

	foreach ( $leaders as $leader ) {
		if ( ! $leader['role'] ) {
			continue;
		}

		$user    = '' !== $leader['email'] ? get_user_by( 'email', $leader['email'] ) : false;
		$user_id = $user ? (int) $user->ID : 0;

		if ( '' === $leader['email'] ) {
			$outcome = 'no_email';
		} elseif ( ! $user_id ) {
			$outcome = 'unmatched';
		} elseif ( ! is_user_member_of_blog( $user_id, $site_id ) ) {
			$outcome = 'added';
		} elseif ( role_rank( $user_id, $site_id ) < ROLE_RANK[ $leader['role'] ] ) {
			$outcome = 'promoted';
		} else {
			$outcome = 'kept';
		}

		$classified[] = compact( 'leader', 'outcome', 'user_id' );
	}

	return $classified;
}

/**
 * Count what an import would do with a group's organizers, without writing anything.
 *
 * @param int   $site_id Target group site.
 * @param array $leaders Normalized leaders.
 * @return int[] Counts keyed by outcome.
 */
function plan_leaders( int $site_id, array $leaders ): array {
	return array_count_values( wp_list_pluck( classify_leaders( $site_id, $leaders ), 'outcome' ) );
}

/**
 * Give a group's Meetup organizers the matching Organizer role on its site.
 *
 * Matched by email to existing WordPress.org accounts only. Only ever
 * promotes: someone already in that role or higher keeps theirs, and nobody
 * is made an administrator (see `LEADER_ROLES`).
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * @param int   $site_id Target group site.
 * @param array $leaders Normalized leaders.
 * @return int[] Counts keyed by outcome.
 */
function import_leaders( int $site_id, array $leaders ): array {
	$classified = classify_leaders( $site_id, $leaders );

	foreach ( $classified as $index => $entry ) {
		if ( 'added' === $entry['outcome'] ) {
			if ( ! ensure_member( $site_id, $entry['user_id'], $entry['leader']['role'] ) ) {
				$classified[ $index ]['outcome'] = 'failed';
			}
		} elseif ( 'promoted' === $entry['outcome'] ) {
			( new WP_User( $entry['user_id'], '', $site_id ) )->set_role( $entry['leader']['role'] );
		}
	}

	$counts = array_count_values( wp_list_pluck( $classified, 'outcome' ) );

	Logger\log( 'leaders_imported', compact( 'site_id', 'counts' ) );

	return $counts;
}

/**
 * Credit an event's Meetup hosts as its hosts here.
 *
 * Hosts are matched to existing WordPress.org accounts by email and added to
 * the group if they aren't in it, since a host has to be a member. Only fills
 * an event with no hosts yet, so a refreshed draft keeps the organizer's
 * choice. Runs on the event's own site.
 *
 * @param int   $post_id Event post ID.
 * @param array $emails  Normalized host emails.
 */
function import_hosts( int $post_id, array $emails ): void {
	if ( ! $emails || get_post_meta( $post_id, Event_Hosts\META_KEY, true ) ) {
		return;
	}

	$user_ids = array();

	foreach ( $emails as $email ) {
		$user = get_user_by( 'email', $email );

		if ( $user && ensure_member( get_current_blog_id(), (int) $user->ID ) ) {
			$user_ids[] = (int) $user->ID;
		}
	}

	Event_Hosts\set_event_hosts( $post_id, $user_ids );
}

/**
 * Carry an event's Meetup RSVPs over as real RSVPs, for matched people only.
 *
 * Saved through GatherPress's own RSVP API, so its rules apply as for any
 * RSVP (attendance limits, the waiting list). RSVPing doesn't add anyone to
 * the group. An "attended" RSVP is also checked in, the same record the
 * check-in screen keeps, so reporting counts it as attendance.
 *
 * No confirmation email goes out: those are only sent when the event is
 * published and the person whose RSVP it is made the change
 * (`Frontend\RSVP_Confirmation\send_confirmation()`), and this runs as the
 * admin, on an event that's still a draft. Runs on the event's own site.
 *
 * @param int    $post_id         Event post ID.
 * @param string $meetup_event_id Meetup event ID.
 * @return int[] Counts: `saved`, `checked_in`, `unmatched`, `skipped`, `failed`.
 */
function import_rsvps( int $post_id, string $meetup_event_id ): array {
	$counts = array_fill_keys( array( 'saved', 'checked_in', 'unmatched', 'skipped', 'failed' ), 0 );
	$raw    = fetch_event_rsvps( $meetup_event_id );
	$rsvp   = ( new Event( $post_id ) )->rsvp;

	if ( is_wp_error( $raw ) || ! $rsvp ) {
		++$counts['failed'];
		return $counts;
	}

	foreach ( array_map( __NAMESPACE__ . '\normalize_rsvp', $raw ) as $entry ) {
		if ( ! $entry['status'] ) {
			++$counts['skipped'];
			continue;
		}

		$user = '' !== $entry['email'] ? get_user_by( 'email', $entry['email'] ) : false;

		if ( ! $user ) {
			++$counts['unmatched'];
			continue;
		}

		try {
			$saved = $rsvp->save( (int) $user->ID, $entry['status'], 0, $entry['guests'] );
		} catch ( \Throwable $e ) {
			// One RSVP GatherPress chokes on shouldn't take the rest of the import down with it.
			Logger\log(
				'rsvp_failed',
				array(
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				)
			);
			$saved = array();
		}

		if ( empty( $saved['comment_id'] ) ) {
			++$counts['failed'];
			continue;
		}

		++$counts['saved'];

		if ( $entry['checked_in'] ) {
			$comment = get_comment( (int) $saved['comment_id'] );

			if ( $comment ) {
				Check_In\set_checked_in( $comment, true );
				++$counts['checked_in'];
			}
		}
	}

	return $counts;
}

/**
 * Add up the RSVP counts across an import's events.
 *
 * @param array[] $results From `import_events()`.
 * @return int[]
 */
function sum_rsvp_counts( array $results ): array {
	$totals = array_fill_keys( array( 'saved', 'checked_in', 'unmatched', 'skipped', 'failed' ), 0 );

	foreach ( $results as $result ) {
		foreach ( $result['rsvps'] ?? array() as $key => $count ) {
			$totals[ $key ] += $count;
		}
	}

	return $totals;
}

/**
 * Sort a group's Meetup members by what an import does with each one.
 *
 * In order: a member an earlier import already considered is left alone (so
 * someone who has since left the group isn't added back); one without a
 * shared email, or whose email matches no WordPress.org account, is only
 * counted; one who is already in the group stays as they are; the rest join.
 *
 * @param int   $site_id Target group site.
 * @param array $members Normalized members, from `normalize_member()`.
 * @return array[] One `{ member, outcome, user_id }` per member.
 */
function classify_members( int $site_id, array $members ): array {
	$seen       = array_flip( (array) get_blog_option( $site_id, MEMBERS_OPTION, array() ) );
	$classified = array();

	foreach ( $members as $member ) {
		$user    = '' !== $member['email'] ? get_user_by( 'email', $member['email'] ) : false;
		$user_id = $user ? (int) $user->ID : 0;

		if ( isset( $seen[ $member['meetup_id'] ] ) ) {
			$outcome = 'previously_imported';
		} elseif ( '' === $member['email'] ) {
			$outcome = 'no_email';
		} elseif ( ! $user_id ) {
			$outcome = 'unmatched';
		} elseif ( is_user_member_of_blog( $user_id, $site_id ) ) {
			$outcome = 'already_member';
		} else {
			$outcome = 'join';
		}

		$classified[] = compact( 'member', 'outcome', 'user_id' );
	}

	return $classified;
}

/**
 * Count what an import would do with a group's members, without writing anything.
 *
 * @param int   $site_id Target group site.
 * @param array $members Normalized members.
 * @return int[] Counts keyed by outcome.
 */
function plan_members( int $site_id, array $members ): array {
	return count_member_outcomes( classify_members( $site_id, $members ) );
}

/**
 * Add a group's Meetup members who already have WordPress.org accounts.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * A match joins as a Member, the role the frontend's own join button grants
 * (`Members_Controller::join_group()`), and is opted out of this group's
 * event emails: GatherPress opts users in by default, and nobody should start
 * getting mail because their address turned up in a Meetup export. They can
 * opt in from the group like anyone else.
 *
 * @param int   $site_id Target group site.
 * @param array $members Normalized members.
 * @return int[]|WP_Error Counts keyed by outcome.
 */
function import_members( int $site_id, array $members ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	$classified = classify_members( $site_id, $members );
	$seen       = (array) get_blog_option( $site_id, MEMBERS_OPTION, array() );

	foreach ( $classified as $index => $entry ) {
		if ( 'join' === $entry['outcome'] && ! ensure_member( $site_id, $entry['user_id'] ) ) {
			$classified[ $index ]['outcome'] = 'failed';
			continue;
		}

		if ( in_array( $entry['outcome'], array( 'join', 'already_member' ), true ) ) {
			$seen[] = $entry['member']['meetup_id'];
		}
	}

	update_blog_option( $site_id, MEMBERS_OPTION, array_values( array_unique( $seen ) ) );

	$counts = count_member_outcomes( $classified );

	Logger\log( 'members_imported', compact( 'site_id', 'counts' ) );

	return $counts;
}

/**
 * Tally classified members by outcome, with every outcome present.
 *
 * @param array[] $classified From `classify_members()`.
 * @return int[]
 */
function count_member_outcomes( array $classified ): array {
	return array_merge(
		array_fill_keys( array( 'join', 'already_member', 'previously_imported', 'unmatched', 'no_email', 'failed' ), 0 ),
		array_count_values( wp_list_pluck( $classified, 'outcome' ) )
	);
}

/**
 * Find the attachment a Meetup photo was imported as, downloading it the first time.
 *
 * A recurring meetup usually reuses one cover photo across its events, so
 * keying on Meetup's photo ID downloads it once per group site rather than
 * once per event. A failed download leaves the event without an image; it
 * doesn't fail the event.
 *
 * @param array $photo   Normalized photo, from `normalize_photo()`.
 * @param int   $post_id Event the photo is first attached to.
 * @return int Attachment ID, or 0.
 */
function find_or_sideload_photo( array $photo, int $post_id ): int {
	$existing = find_imported_post( 'attachment', PHOTO_ID_META, $photo['meetup_id'] );

	if ( $existing ) {
		return $existing->ID;
	}

	/**
	 * Short-circuit downloading a Meetup photo, e.g. in tests.
	 *
	 * @param int|WP_Error|null $attachment_id Attachment ID, or null to download it.
	 * @param string            $url           Photo URL on Meetup's image host.
	 * @param int               $post_id       Event the photo is attached to.
	 */
	$attachment_id = apply_filters( 'wcorg_meetup_import_pre_sideload_photo', null, $photo['url'], $post_id );

	if ( null === $attachment_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $photo['url'], $post_id, null, 'id' );
	}

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		Logger\log(
			'photo_failed',
			array(
				'url'   => $photo['url'],
				'error' => is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '',
			)
		);

		return 0;
	}

	update_post_meta( (int) $attachment_id, PHOTO_ID_META, $photo['meetup_id'] );

	return (int) $attachment_id;
}

/**
 * Find the venue a Meetup venue was imported as, creating it the first time.
 *
 * An existing venue is never updated: once it's on the site, the organizer
 * owns it. New venues are created the way `Frontend\REST\resolve_venue_id()`
 * creates them, with the address in `gatherpress_address` so GatherPress
 * geocodes it.
 *
 * @param array|null $venue Normalized venue, or null for none.
 * @return int Venue post ID, or 0.
 */
function find_or_create_venue( ?array $venue ): int {
	if ( ! $venue ) {
		return 0;
	}

	$existing = find_imported_post( Venue::POST_TYPE, VENUE_ID_META, $venue['meetup_id'] );

	if ( $existing ) {
		return $existing->ID;
	}

	$venue_id = wp_insert_post(
		array(
			'post_type'   => Venue::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => wcorg_sanitize_plain_text( $venue['name'] ),
			// With the post, so a rerun can always find it (see `import_event()`).
			'meta_input'  => array( VENUE_ID_META => $venue['meetup_id'] ),
		),
		true
	);

	if ( is_wp_error( $venue_id ) || ! $venue_id ) {
		return 0;
	}

	if ( '' !== $venue['address'] ) {
		update_post_meta( $venue_id, 'gatherpress_address', $venue['address'] );
	}

	return (int) $venue_id;
}

/**
 * Find a post previously imported from Meetup, in any status.
 *
 * @param string $post_type Post type.
 * @param string $meta_key  Meta key holding the Meetup ID.
 * @param string $meetup_id Meetup ID.
 * @return \WP_Post|null
 */
function find_imported_post( string $post_type, string $meta_key, string $meetup_id ): ?\WP_Post {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any,trash',
			'posts_per_page'   => 1,
			'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per imported event.
			'meta_value'       => $meetup_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);

	return $posts[0] ?? null;
}

/**
 * The options table that holds the import lock.
 *
 * Always the Groups network's root site, whichever blog the request is on, so
 * there's one lock for the whole network.
 */
function lock_table(): string {
	global $wpdb;

	return $wpdb->get_blog_prefix( GROUPS_ROOT_BLOG_ID ) . 'options';
}

/**
 * Take the import lock, if nobody else holds it.
 *
 * The same approach as core's `WP_Upgrader::create_lock()`: inserting a row
 * with a unique name is atomic, so only one request can win it. A lock older
 * than `LOCK_TIMEOUT` is assumed abandoned and taken over -- with a
 * compare-and-swap on its old value, so two requests can't both take over the
 * same stale lock.
 *
 * The stored value is the expiry plus a random token, so each holder's value
 * is its own and `release_lock()` can tell whether the lock is still theirs.
 *
 * @return bool Whether this request now holds the lock.
 */
function acquire_lock(): bool {
	global $wpdb;

	$table   = lock_table();
	$expires = ( time() + LOCK_TIMEOUT ) . ':' . wp_generate_password( 12, false );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the lock has to bypass the options cache to be atomic.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `$table` is built from the blog prefix, not input.
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO `$table` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'off' )",
			LOCK_OPTION,
			$expires
		)
	);

	if ( $inserted ) {
		held_lock( $expires );
		return true;
	}

	$current = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `$table` WHERE `option_name` = %s", LOCK_OPTION ) );

	if ( null === $current || (int) $current > time() ) {
		return false;
	}

	$taken_over = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
			$expires,
			LOCK_OPTION,
			$current
		)
	);
	// phpcs:enable

	if ( 1 !== $taken_over ) {
		return false;
	}

	held_lock( $expires );
	return true;
}

/**
 * The lock value this request holds, if any.
 *
 * @param string|null $value New value to remember; '' to forget it; null to read.
 */
function held_lock( ?string $value = null ): string {
	static $held = '';

	if ( null !== $value ) {
		$held = $value;
	}

	return $held;
}

/**
 * Give the import lock back, if this request still holds it.
 *
 * Only the row with this request's own value is deleted: if the lock expired
 * and another import took it over, that one keeps it. Safe to call more than
 * once, which matters because it runs both when the import finishes and at
 * shutdown (see `handle_form_submission()`).
 */
function release_lock(): void {
	global $wpdb;

	if ( '' === held_lock() ) {
		return;
	}

	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		lock_table(),
		array(
			'option_name'  => LOCK_OPTION,
			'option_value' => held_lock(),
		)
	);

	held_lock( '' );
}

/**
 * Run a callback on a site and always switch back.
 *
 * The switch sits inside the try for the same reason as in
 * `network-export.php`: a throwing `switch_blog` listener would otherwise
 * leave the request on the wrong blog.
 *
 * @param int      $site_id  Site to run on.
 * @param callable $callback What to run there.
 * @return mixed The callback's return value.
 */
function with_site( int $site_id, callable $callback ) {
	try {
		switch_to_blog( $site_id );

		return $callback();
	} finally {
		restore_current_blog();
	}
}

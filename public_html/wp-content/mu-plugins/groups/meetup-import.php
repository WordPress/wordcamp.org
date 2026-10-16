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
use WordCamp\Logger;
use WordPressdotorg\MU_Plugins\Utilities\Meetup_Client;
use WP_Error;

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

	$step           = 'import' === $_POST['wcorg_meetup_import_step'] ? 'import' : 'preview';
	$urlname        = parse_group_urlname( isset( $_POST['meetup_group'] ) ? sanitize_text_field( wp_unslash( $_POST['meetup_group'] ) ) : '' );
	$site_id        = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$include_past   = ! empty( $_POST['include_past'] );
	$import_members = ! empty( $_POST['import_members'] );
	$state          = compact( 'step', 'urlname', 'site_id', 'include_past', 'import_members' );
	$is_valid       = validate_target_site( $site_id );

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

	$raw_events  = fetch_group_events( $urlname, $include_past );
	$raw_members = $import_members ? fetch_group_members( $urlname ) : array();

	foreach ( array( $raw_events, $raw_members ) as $fetched ) {
		if ( is_wp_error( $fetched ) ) {
			add_settings_error( 'wcorg-meetup-import', $fetched->get_error_code(), $fetched->get_error_message() );
			request_state( $state );
			return;
		}
	}

	$events  = array_map( __NAMESPACE__ . '\normalize_event', $raw_events );
	$members = array_map( __NAMESPACE__ . '\normalize_member', $raw_members );

	if ( 'preview' === $step ) {
		$state['plan'] = plan_events( $site_id, $events );

		if ( $import_members ) {
			$state['member_counts'] = plan_members( $site_id, $members );
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

	try {
		run_import( $site_id, $events, $members, $import_members, $state );
	} finally {
		release_lock();
	}
}

/**
 * Write the import and report on it. Runs while holding the import lock.
 *
 * @param int   $site_id        Target group site.
 * @param array $events         Normalized events.
 * @param array $members        Normalized members.
 * @param bool  $import_members Whether members were asked for.
 * @param array $state          Request state so far.
 */
function run_import( int $site_id, array $events, array $members, bool $import_members, array $state ): void {
	$results = import_events( $site_id, $events );

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

	if ( $import_members ) {
		$member_counts = import_members( $site_id, $members );

		if ( is_wp_error( $member_counts ) ) {
			add_settings_error( 'wcorg-meetup-import', $member_counts->get_error_code(), $member_counts->get_error_message() );
		} else {
			$state['member_counts'] = $member_counts;
		}
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

	$state          = request_state();
	$urlname        = $state['urlname'] ?? '';
	$site_id        = $state['site_id'] ?? 0;
	$include_past   = ! empty( $state['include_past'] );
	$import_members = ! empty( $state['import_members'] );
	$rows           = $state['plan'] ?? $state['results'] ?? array();
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
			<?php esc_html_e( 'Copies a Meetup group\'s upcoming events and venues into a group site as drafts, and optionally its past events and members. No emails are sent. Running it again refreshes events that are still drafts and leaves published ones alone.', 'wordcamporg' ); ?>
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
								<input name="import_members" type="checkbox" value="1" <?php checked( $import_members ); ?> />
								<?php esc_html_e( 'Members who already have a WordPress.org account with the same email. They join as Members with event emails off. Nobody else gets an account.', 'wordcamporg' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="wcorg_meetup_import_step" value="preview" class="button button-secondary"><?php esc_html_e( 'Preview', 'wordcamporg' ); ?></button>
				<?php if ( isset( $state['plan'] ) && ( $rows || ! empty( $state['member_counts']['join'] ) ) ) : ?>
					<button type="submit" name="wcorg_meetup_import_step" value="import" class="button button-primary"><?php esc_html_e( 'Import', 'wordcamporg' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( isset( $state['plan'] ) || isset( $state['results'] ) ) : ?>
			<h2><?php echo esc_html( isset( $state['plan'] ) ? __( 'Preview', 'wordcamporg' ) : __( 'Results', 'wordcamporg' ) ); ?></h2>

			<?php if ( isset( $state['member_counts'] ) ) : ?>
				<h3><?php esc_html_e( 'Members', 'wordcamporg' ); ?></h3>
				<p><?php echo esc_html( describe_member_counts( $state['member_counts'], isset( $state['plan'] ) ) ); ?></p>
				<h3><?php esc_html_e( 'Events', 'wordcamporg' ); ?></h3>
			<?php endif; ?>

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

				if ( ! $existing ) {
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
 * @param int   $site_id Target group site.
 * @param array $events  Normalized events, from `normalize_event()`.
 * @return array[]|WP_Error One `{ event, status, post_id, message }` per event,
 *                          status `created`, `updated`, `skipped`, or `failed`.
 */
function import_events( int $site_id, array $events ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	if ( ! class_exists( Event::class ) ) {
		return new WP_Error( 'gatherpress_missing', __( 'GatherPress is not loaded.', 'wordcamporg' ) );
	}

	$results = with_site(
		$site_id,
		function () use ( $events ) {
			/*
			 * The recurring-events tables are per-site and created lazily, from
			 * hooks that don't fire for a `switch_to_blog()`. See the same call
			 * in `network-export.php`.
			 */
			if ( class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
				\WordPressdotorg\GatherPress_Recurring_Events\Database::maybe_install();
			}

			return array_map( __NAMESPACE__ . '\import_event', $events );
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
 * @param array $event Normalized event.
 * @return array
 */
function import_event( array $event ): array {
	$existing = find_imported_post( Event::POST_TYPE, EVENT_ID_META, $event['meetup_id'] );

	if ( $existing && 'draft' !== $existing->post_status ) {
		return array(
			'event'   => $event,
			'status'  => 'skipped',
			'post_id' => $existing->ID,
		);
	}

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

	update_post_meta( $post_id, EVENT_ID_META, $event['meetup_id'] );
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

	// Meetup's own counts, kept for history and reporting. There are no
	// individual RSVP records behind them.
	update_post_meta( $post_id, RSVP_YES_META, $event['rsvp_yes'] );
	update_post_meta( $post_id, ATTENDED_META, $event['attended'] );

	// A past event is history, not something to review, so it's published --
	// but only the first time. One that was imported as an upcoming draft and
	// has happened since stays the organizer's to publish.
	if ( ! $existing && $event['is_past'] ) {
		// First publish emails every member of the group. This marks that
		// notification as already handled, so there's nothing to send.
		update_post_meta( $post_id, PUBLISH_NOTIFICATION_SCHEDULED_META, 1 );

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
	}

	return array(
		'event'   => $event,
		'status'  => $existing ? 'updated' : 'created',
		'post_id' => $post_id,
	);
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
		if ( 'join' === $entry['outcome'] ) {
			$added = add_user_to_blog( $site_id, $entry['user_id'], 'subscriber' );

			if ( is_wp_error( $added ) ) {
				$classified[ $index ]['outcome'] = 'failed';
				continue;
			}

			update_user_meta( $entry['user_id'], get_scoped_opt_in_meta_key( $site_id ), '0' );
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
		),
		true
	);

	if ( is_wp_error( $venue_id ) || ! $venue_id ) {
		return 0;
	}

	update_post_meta( $venue_id, VENUE_ID_META, $venue['meetup_id'] );

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
 * @return bool Whether this request now holds the lock.
 */
function acquire_lock(): bool {
	global $wpdb;

	$table   = lock_table();
	$expires = (string) ( time() + LOCK_TIMEOUT );

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

	return 1 === $taken_over;
}

/**
 * Give the import lock back.
 */
function release_lock(): void {
	global $wpdb;

	$wpdb->delete( lock_table(), array( 'option_name' => LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

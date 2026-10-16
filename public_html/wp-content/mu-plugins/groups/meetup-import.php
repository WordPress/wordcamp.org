<?php
/**
 * Network-admin tool for importing a Meetup group's upcoming events.
 *
 * The first step of moving a group off Meetup.com (#1788): pull one Meetup
 * group's upcoming events and venues from the Meetup Pro API and create them
 * as draft GatherPress events on a chosen group site, for the organizer to
 * review and publish.
 *
 * Deliberately narrow for the pilot. Members, RSVPs, and past events are not
 * imported -- members move over by joining the group themselves -- and nothing
 * here publishes an event or sends an email.
 *
 * Re-running the import is safe. Events and venues are keyed on their Meetup
 * IDs: an event still in draft is refreshed from Meetup, so date or venue
 * changes made there before cutover come across, while one the organizer has
 * published (or trashed) is theirs and is never touched again.
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

use function WordCamp\Groups\Frontend\REST\storable_timezone;
use function WordCamp\Groups\Frontend\REST\sync_event_venue_terms;
use function WordCamp\Groups\Frontend\REST\sync_online_event_link;

defined( 'WPINC' ) || die();

const PAGE_SLUG      = 'meetup-import';
const NONCE_ACTION   = 'wcorg_meetup_import';
const NONCE_NAME     = 'wcorg_meetup_import_nonce';
const EVENT_ID_META  = '_wporg_meetup_event_id';
const EVENT_URL_META = '_wporg_meetup_event_url';
const VENUE_ID_META  = '_wporg_meetup_venue_id';

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

	$step     = 'import' === $_POST['wcorg_meetup_import_step'] ? 'import' : 'preview';
	$urlname  = parse_group_urlname( isset( $_POST['meetup_group'] ) ? sanitize_text_field( wp_unslash( $_POST['meetup_group'] ) ) : '' );
	$site_id  = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$state    = compact( 'step', 'urlname', 'site_id' );
	$is_valid = validate_target_site( $site_id );

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

	$raw_events = fetch_group_events( $urlname );

	if ( is_wp_error( $raw_events ) ) {
		add_settings_error( 'wcorg-meetup-import', $raw_events->get_error_code(), $raw_events->get_error_message() );
		request_state( $state );
		return;
	}

	$events = array_map( __NAMESPACE__ . '\normalize_event', $raw_events );

	if ( 'import' === $step ) {
		$results = import_events( $site_id, $events );

		if ( is_wp_error( $results ) ) {
			add_settings_error( 'wcorg-meetup-import', $results->get_error_code(), $results->get_error_message() );
		} else {
			$counts = array_count_values( wp_list_pluck( $results, 'status' ) );

			add_settings_error(
				'wcorg-meetup-import',
				'success',
				sprintf(
					/* translators: 1: Created count, 2: Updated count, 3: Skipped count, 4: Failed count, 5: Site's events admin URL. */
					__( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d failed. <a href="%5$s">Review the drafts</a>', 'wordcamporg' ),
					$counts['created'] ?? 0,
					$counts['updated'] ?? 0,
					$counts['skipped'] ?? 0,
					$counts['failed'] ?? 0,
					esc_url( get_admin_url( $site_id, 'edit.php?post_type=' . Event::POST_TYPE . '&post_status=draft' ) )
				),
				'success'
			);

			$state['results'] = $results;
		}
	} else {
		$state['plan'] = plan_events( $site_id, $events );
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

	$state   = request_state();
	$urlname = $state['urlname'] ?? '';
	$site_id = $state['site_id'] ?? 0;
	$rows    = $state['plan'] ?? $state['results'] ?? array();
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
			<?php esc_html_e( 'Copies a Meetup group\'s upcoming events and venues into a group site as drafts. Members, RSVPs, and past events are not imported, and no emails are sent. Running it again refreshes events that are still drafts and leaves published ones alone.', 'wordcamporg' ); ?>
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
			</table>

			<p class="submit">
				<button type="submit" name="wcorg_meetup_import_step" value="preview" class="button button-secondary"><?php esc_html_e( 'Preview', 'wordcamporg' ); ?></button>
				<?php if ( isset( $state['plan'] ) && ! empty( $rows ) ) : ?>
					<button type="submit" name="wcorg_meetup_import_step" value="import" class="button button-primary"><?php esc_html_e( 'Import as drafts', 'wordcamporg' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( isset( $state['plan'] ) || isset( $state['results'] ) ) : ?>
			<h2><?php echo esc_html( isset( $state['plan'] ) ? __( 'Preview', 'wordcamporg' ) : __( 'Results', 'wordcamporg' ) ); ?></h2>

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
						<tr><td colspan="4"><?php esc_html_e( 'This group has no upcoming events on Meetup.', 'wordcamporg' ); ?></td></tr>
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
							<td><?php echo esc_html( describe_status( $row['status'], $row['message'] ?? '' ) ); ?></td>
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
 * @param string $message Failure detail, if any.
 */
function describe_status( string $status, string $message = '' ): string {
	$labels = array(
		'create'  => __( 'Will be created as a draft', 'wordcamporg' ),
		'update'  => __( 'Draft will be refreshed', 'wordcamporg' ),
		'skip'    => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'created' => __( 'Created as a draft', 'wordcamporg' ),
		'updated' => __( 'Draft refreshed', 'wordcamporg' ),
		'skipped' => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'failed'  => __( 'Failed', 'wordcamporg' ),
	);

	$label = $labels[ $status ] ?? $status;

	return $message ? "$label: $message" : $label;
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
 * Fetch a group's upcoming events from the Meetup API.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error Raw event nodes, as `Meetup_Client::get_group_events()` returns them.
 */
function fetch_group_events( string $urlname ) {
	/**
	 * Short-circuit the Meetup request, e.g. with fixture data.
	 *
	 * The Meetup client can't be constructed without the network's Meetup
	 * credentials, which tests and local environments don't have.
	 *
	 * @param array|WP_Error|null $events  Raw event nodes, or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$events = apply_filters( 'wcorg_meetup_import_pre_fetch', null, $urlname );

	if ( null !== $events ) {
		return $events;
	}

	if ( ! is_meetup_connected() ) {
		return new WP_Error( 'meetup_not_connected', __( 'Meetup is not connected on this network.', 'wordcamporg' ) );
	}

	$client = new Meetup_Client();

	if ( $client->error->has_errors() ) {
		return $client->error;
	}

	$events = $client->get_group_events(
		$urlname,
		array(
			'status' => 'upcoming',
			'fields' => array(
				'howToFindUs',
				'venues { address postalCode }',
			),
		)
	);

	if ( is_wp_error( $events ) ) {
		return $events;
	}

	if ( ! is_array( $events ) || ! array_is_list( $events ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	Logger\log(
		'fetched',
		array(
			'urlname' => $urlname,
			'count'   => count( $events ),
		)
	);

	return $events;
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
 * Create or refresh one draft event on the current site.
 *
 * Writes the same pieces the frontend's draft save does
 * (`Frontend\REST\save_draft()`), so an imported draft is indistinguishable
 * from one an organizer started by hand. No `wporg_groups_frontend_event_draft_saved`
 * action fires and nothing is published, so no notifications go out.
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

	return array(
		'event'   => $event,
		'status'  => $existing ? 'updated' : 'created',
		'post_id' => $post_id,
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

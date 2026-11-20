<?php
/**
 * Implement Meetup Admin class
 *
 * @package WordCamp Post Type
 */

use \WordPress_Community\Applications\Meetup_Application;

require_once WCPT_DIR . 'wcpt-event/class-event-admin.php';
require_once WCPT_DIR . 'wcpt-event/notification.php';


if ( ! class_exists( 'Meetup_Admin' ) ) :

	/**
	 * Implements Meetup Admin class
	 */
	class Meetup_Admin extends Event_Admin {

		/** Editable field linking the group to its site on events.wordpress.org. */
		const GROUP_URL_KEY = 'GatherPress group URL';

		/** The linked group site's ID. Present only for migrated groups. */
		const GROUP_SITE_ID_KEY = '_group_site_id';

		/** Group site meta written by `mu-plugins/groups/group-stats.php`. */
		const GROUP_STATS_KEY = 'wporg_group_stats';

		/** Groups-network cron hook that refreshes one site's stats, from the same file. */
		const GROUP_REFRESH_HOOK = 'wporg_groups_refresh_site_stats';

		/**
		 * Meetup_Admin constructor.
		 */
		public function __construct() {
			parent::__construct();

			// Register at priority `11` because `10` has already run by the time this class is instantiated.
			add_action( 'plugins_loaded', array( $this, 'schedule_cron_jobs' ), 11 );
			add_action( 'wcpt_meetup_api_sync', array( $this, 'meetup_api_sync' ) );
			add_action( 'wcpt_metabox_save_done', array( $this, 'maybe_update_meetup_data' ) );
			add_action( 'wcpt_metabox_save_done', array( $this, 'update_group_site_link' ) );
			add_action( 'wcpt_metabox_value', array( $this, 'render_co_organizers_list' ) );
			add_action( 'wcpt_metabox_save_done', array( $this, 'meetup_organizers_changed' ), 10, 2 );
			add_action( 'transition_post_status', array( $this, 'maybe_update_organizers' ), 10, 3 );
		}

		/**
		 * Return user facing label of event type.
		 *
		 * @return string
		 */
		public static function get_event_label() {
			return Meetup_Application::get_event_label();
		}

		/**
		 * Get post type of event
		 *
		 * @return string
		 */
		public static function get_event_type() {
			return Meetup_Application::get_event_type();
		}

		/**
		 * Get searchable post meta keys for Meetup events.
		 *
		 * Returns a limited list of meta keys that are useful for searching.
		 * Focuses on names, locations, and text fields while excluding URLs, dates, and numeric fields.
		 *
		 * @return array List of meta keys to search.
		 */
		public static function get_searchable_meta_keys() {
			return array(
				'Organizer Name',
				'Meetup Co-organizer names',
				'Primary organizer WordPress.org username',
				'Co-Organizers usernames (seperated by comma)',
				'Meetup Location (From meetup.com)',
				'Meetup Location',
				'Who contacted (Wordpress.org username)',
				'Vetted by (Wordpress.org username)',
				'Oriented by (Wordpress.org username)',
				'Joined chapter by (Wordpress.org username)',
				'Organizer description',
				'Address',
				'Extra Comments',
			);
		}

		/**
		 * TODO: Add valid transition statuses.
		 *
		 * @param string $status Current status of the meetup.
		 *
		 * @return array Valid status transitions.
		 */
		public static function get_valid_status_transitions( $status ) {
			return array_keys( Meetup_Application::get_post_statuses() );
		}

		/**
		 * List of all valid post statuses for Meetup event
		 *
		 * @return array
		 */
		public static function get_post_statuses() {
			return Meetup_Application::get_post_statuses();
		}

		/**
		 * Name of capability required to edit the event
		 *
		 * @return string
		 */
		public static function get_edit_capability() {
			return 'wordcamp_wrangle_meetups';
		}

		/**
		 * Columns to display in admin list view
		 *
		 * @param array $columns
		 *
		 * @return array
		 */
		public function column_headers( $columns ) {
			$columns = array(
				'cb'                   => '<input type="checkbox" />',
				'title'                => __( 'Title',          'wordcamporg' ),
				'taxonomy-meetup_tags' => __( 'Meetup Tags',    'wordcamporg' ),
				'organizer'            => __( 'Organizer',      'wordcamporg' ),
				'date'                 => __( 'Date',           'wordcamporg' ),
				'meetup.com_url'       => __( 'Meetup URL',     'wordcamporg' ),
				'freescout_url'        => __( 'FreeScout Link', 'wordcamporg' ),
			);

			return $columns;
		}

		/**
		 * Return read only meta fields.
		 */
		public static function get_protected_fields() {
			return array(

				// These fields are updated by Meetup API and will be overwritten even if manually changed.
				'Meetup Co-organizer names',
				'Meetup Location (From meetup.com)',
				'Meetup members count',
				'Meetup group created on',
				'Number of past meetups',
				'Last meetup on',
				'Last meetup RSVP count',
			);
		}

		/**
		 * Checks if a field is read only.
		 *
		 * @param string $key Name of the field.
		 *
		 * @return bool Whether `$key` is a protected field.
		 */
		public static function is_protected_field( $key ) {
			return in_array( $key, self::get_protected_fields() );
		}

		/**
		 * Get rendered column data.
		 *
		 * @param string $column Name of the column.
		 * @param int    $post_id
		 */
		public function column_data( $column, $post_id ) {
			if ( empty( $_GET['post_type'] ) || $_GET['post_type'] !== $this->get_event_type() ) {
				return;
			}

			switch ( $column ) {
				case 'organizer':
					echo esc_html( get_post_meta( $post_id, 'Organizer Name', true ) );
					break;
				case 'meetup.com_url':
					$this->print_clickable_link( get_post_meta( $post_id, 'Meetup URL', true ) );
					break;
				case 'freescout_url':
					$this->print_clickable_link( get_post_meta( $post_id, 'FreeScout link', true ) );
					break;
			}
		}

		/**
		 * Helper function to wrap link HTML around a url.
		 *
		 * @param string $link
		 */
		protected function print_clickable_link( $link ) {
			?>
		<a href="<?php echo esc_url( $link ); ?>" target="_blank">
			<?php echo esc_html( $link ); ?>
		</a>
			<?php
		}

		/**
		 * TODO: Remove quickedit action.
		 *
		 * @param array   $actions
		 * @param WP_Post $post
		 *
		 * @return mixed
		 */
		public function post_row_actions( $actions, $post ) {
			return $actions;
		}

		/**
		 * Add metaboxes for meetup events.
		 */
		public function metabox() {

			add_meta_box(
				'wcpt_meetup_meetup_information',
				__( 'Meetup Information', 'wordcamporg' ),
				array( $this, 'wcpt_meetup_information_metabox' ),
				Meetup_Application::POST_TYPE,
				'advanced'
			);

			add_meta_box(
				'wcpt_meetup_application',
				__( 'Application Information', 'wordcamporg' ),
				array( $this, 'wcpt_application_metabox' ),
				Meetup_Application::POST_TYPE,
				'advanced'
			);

			add_meta_box(
				'wcpt_meetup_organizer_info',
				__( 'Organizer Information', 'wordcamporg' ),
				array( $this, 'wcpt_organizer_info_metabox' ),
				Meetup_Application::POST_TYPE,
				'advanced'
			);

			add_meta_box(
				'wcpt_meetup_metadata',
				__( 'Meetup.com API sync', 'wordcamporg' ),
				array( $this, 'wcpt_meetup_sync' ),
				Meetup_Application::POST_TYPE,
				'side',
				'high'
			);

			if ( self::get_group_site( get_the_ID() ) ) {
				add_meta_box(
					'wcpt_meetup_gatherpress_platform',
					__( 'Platform', 'wordcamporg' ),
					array( $this, 'render_platform_metabox' ),
					Meetup_Application::POST_TYPE,
					'side',
					'high'
				);

				add_meta_box(
					'wcpt_meetup_gatherpress_data',
					__( 'Group data', 'wordcamporg' ),
					array( $this, 'render_group_data_metabox' ),
					Meetup_Application::POST_TYPE,
					'side',
					'high'
				);
			}
		}

		/**
		 * The live group site a tracker record is linked to, if any.
		 *
		 * @param int $post_id Tracker record.
		 * @return WP_Site|null
		 */
		public static function get_group_site( $post_id ) {
			$site = get_site( (int) get_post_meta( $post_id, self::GROUP_SITE_ID_KEY, true ) );

			if ( ! $site || GROUPS_NETWORK_ID !== (int) $site->network_id || $site->archived || $site->deleted || $site->spam ) {
				return null;
			}

			return $site;
		}

		/**
		 * Find the group site a URL on events.wordpress.org points to.
		 *
		 * @param string $url Group URL, e.g. `https://events.wordpress.org/group/wp-joinville/`.
		 * @return int Site ID, or 0 when the URL isn't a group's.
		 */
		public static function resolve_group_site( $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( ! $host ) {
				return 0;
			}

			$site = get_site_by_path( $host, trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

			if ( ! $site || GROUPS_NETWORK_ID !== (int) $site->network_id || GROUPS_ROOT_BLOG_ID === (int) $site->blog_id ) {
				return 0;
			}

			return (int) $site->blog_id;
		}

		/**
		 * Link the record to its group site on save, and request a stats refresh if asked.
		 *
		 * @param int $post_id Tracker record.
		 */
		public function update_group_site_link( $post_id ) {
			if ( $this->get_event_type() !== get_post_type( $post_id ) ) {
				return;
			}

			$url     = (string) get_post_meta( $post_id, self::GROUP_URL_KEY, true );
			$site_id = '' === $url ? 0 : self::resolve_group_site( $url );

			if ( ! $site_id ) {
				delete_post_meta( $post_id, self::GROUP_SITE_ID_KEY );

				if ( '' !== $url ) {
					$this->active_admin_notices[] = 'invalid-group-url';
				}

				return;
			}

			update_post_meta( $post_id, self::GROUP_SITE_ID_KEY, $site_id );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in `metabox_save` in class-event-admin.php.
			if ( ! empty( $_POST['refresh_group_stats'] ) ) {
				self::request_group_stats_refresh( $site_id );
				$this->active_admin_notices[] = 'group-refresh-requested';
			}
		}

		/**
		 * Ask the groups network to rebuild a site's stats on its next cron run.
		 *
		 * Core drops a duplicate single event within ten minutes, so repeated
		 * clicks queue one refresh.
		 *
		 * @param int $site_id Group site.
		 */
		public static function request_group_stats_refresh( $site_id ) {
			switch_to_blog( GROUPS_ROOT_BLOG_ID );

			try {
				wp_schedule_single_event( time(), self::GROUP_REFRESH_HOOK, array( (int) $site_id ) );
			} finally {
				restore_current_blog();
			}
		}

		/**
		 * Render the "migrated to GatherPress" box.
		 *
		 * @param WP_Post $post Tracker record.
		 */
		public function render_platform_metabox( $post ) {
			$site = self::get_group_site( $post->ID );
			$url  = get_home_url( $site->blog_id, '/' );
			?>
			<p>
				<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: %s: date the group site was created. */
					esc_html__( 'This community has migrated to GatherPress on %s.', 'wordcamporg' ),
					esc_html( gmdate( 'Y-m-d', strtotime( $site->registered . ' UTC' ) ) )
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( untrailingslashit( preg_replace( '#^https?://#', '', $url ) ) ); ?></a></p>
			<?php
		}

		/**
		 * Render the group's stats from the groups network, with a "Sync now" checkbox.
		 *
		 * @param WP_Post $post Tracker record.
		 */
		public function render_group_data_metabox( $post ) {
			$site  = self::get_group_site( $post->ID );
			$stats = get_site_meta( $site->blog_id, self::GROUP_STATS_KEY, true );
			$date  = static function ( $gmt ) {
				return $gmt ? gmdate( 'Y-m-d', strtotime( $gmt . ' UTC' ) ) : '';
			};

			if ( is_array( $stats ) ) {
				// The stats are refreshed daily, so a "next" event may have happened since.
				$next = $stats['next_event_at'] > gmdate( 'Y-m-d H:i:s' ) ? $stats['next_event_at'] : '';

				$fields = array(
					__( 'Group location', 'wordcamporg' ) => $stats['location'],
					__( 'Members count', 'wordcamporg' ) => $stats['members_count'],
					__( 'Organizers usernames', 'wordcamporg' ) => implode( ', ', $stats['organizers'] ),
					__( 'Number of past events', 'wordcamporg' ) => $stats['past_events_count'],
					__( 'Last event on', 'wordcamporg' ) => $date( $stats['last_event_at'] ),
					__( 'Last event RSVP count', 'wordcamporg' ) => $stats['last_event_at'] ? $stats['last_event_rsvps'] : '',
					__( 'Next event on', 'wordcamporg' ) => $date( $next ),
					__( 'Events in the last 12 months', 'wordcamporg' ) => $stats['events_last_12_months'],
					__( 'Average RSVPs (last 12 months)', 'wordcamporg' ) => $stats['events_last_12_months'] ? $stats['average_rsvps'] : '',
				);
			}
			?>
			<div class="wcb submitbox">
				<?php if ( empty( $fields ) ) : ?>
					<div class="misc-pub-section"><?php esc_html_e( 'Not synced yet.', 'wordcamporg' ); ?></div>
				<?php else : ?>
					<?php foreach ( $fields as $label => $value ) : ?>
						<div class="misc-pub-section">
							<strong><?php echo esc_html( $label ); ?>:</strong>
							<?php echo esc_html( '' === (string) $value ? '—' : $value ); ?>
						</div>
					<?php endforeach; ?>
					<div class="misc-pub-section">
						<?php
						printf(
							/* translators: %s: date and time, UTC. */
							esc_html__( 'Last sync: %s UTC', 'wordcamporg' ),
							esc_html( gmdate( 'Y-m-d H:i', (int) $stats['synced_at'] ) )
						);
						?>
					</div>
				<?php endif; ?>
				<div class="misc-pub-section">
					<label>
						<input type="checkbox" name="refresh_group_stats" />
						<?php esc_html_e( 'Sync now', 'wordcamporg' ); ?>
					</label>
				</div>
			</div>
			<?php
		}

		/**
		 * Render information metabox group.
		 */
		public function wcpt_meetup_information_metabox() {
			$meta_keys = $this->meta_keys( 'information' );
			$this->meetup_metabox( $meta_keys );
		}

		/**
		 * Render application metabox group.
		 */
		public function wcpt_application_metabox() {
			$meta_keys = $this->meta_keys( 'application' );
			$this->meetup_metabox( $meta_keys );
		}

		/**
		 * Render organizer metabox group.
		 */
		public function wcpt_organizer_info_metabox() {
			$meta_keys = $this->meta_keys( 'organizer' );
			$this->meetup_metabox( $meta_keys );
		}

		/**
		 * Render notes metabox group.
		 */
		public function wcpt_notes_metabox() {
			$meta_keys = $this->meta_keys( 'notes' );
			$this->meetup_metabox( $meta_keys );
		}

		/**
		 * Render logs metabox groups.
		 */
		public function wcpt_logs_metabox() {
			$meta_keys = $this->meta_keys( 'logs' );
			$this->meetup_metabox( $meta_keys );
		}

		/**
		 * Display metaboxes for the keys provided in the argument.
		 *
		 * @param array $meta_keys
		 */
		public function meetup_metabox( $meta_keys ) {
			global $post_id;

			self::display_meta_boxes( array(), $meta_keys, array(), $post_id, self::get_protected_fields() );
		}

		/**
		 * Displays a MetaBox which allows option to sync Meetup.com API data with Meetup tracker.
		 */
		public function wcpt_meetup_sync() {
			global $post_id;
			$meta_key       = 'Last meetup.com API sync';
			$last_synced_on = get_post_meta( $post_id, $meta_key, true );
			$element_name   = 'sync_with_meetup_api';

			if ( empty( $last_synced_on ) ) {
				$last_synced_on = 'Never';
			} else {
				$last_synced_on = date( 'Y-m-d',  substr( $last_synced_on, 0, 10 ) );
			}
			?>
			<div class="wcb submitbox">
				<div class="misc-pub-section">
					<label>Last sync: <?php echo esc_html( $last_synced_on ); ?></label>
				</div>
				<div class="misc-pub-section">
					<label>
						<input type="checkbox" name="<?php echo esc_html( $element_name ); ?>" >
						Sync Now
					</label>
				</div>
			</div>
			<?php
		}

		/**
		 * Updates meetup fields using meetup.com API only if Sync now checkbox is checked.
		 *
		 * @param int $post_id
		 */
		public function maybe_update_meetup_data( $post_id ) {
			if ( $this->get_event_type() !== get_post_type() ) {
				return;
			}

			//phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in `metabox_save` in class-event-admin.php.
			$should_sync = $_POST['sync_with_meetup_api'] ?? false;
			if ( ! $should_sync ) {
				return;
			}

			$result = self::update_meetup_data( $post_id );

			if ( is_wp_error( $result ) ) {
				$this->active_admin_notices[] = $result->get_error_code();
				return;
			}
		}

		/**
		 * Update meetup fields using meetup.com API
		 *
		 * @param int $post_id
		 *
		 * @return array|WP_Error
		 */
		public static function update_meetup_data( $post_id ) {

			$meetup_url = get_post_meta( $post_id, 'Meetup URL', true );
			$meetup_path = wp_parse_url( $meetup_url, PHP_URL_PATH );

			if ( ! $meetup_path ) {
				return new WP_Error( 'invalid-url', __('Provided Meetup URL is not a valid URL.', 'wordcamporg' ) );
			}

			$url_path_segments = explode( '/', rtrim( $meetup_path, '/' ) );
			$slug              = array_pop( $url_path_segments );
			$mtp_client        = new WordPressdotorg\MU_Plugins\Utilities\Meetup_Client();

			$group_details = $mtp_client->get_group_details( $slug );

			if ( is_wp_error( $group_details ) ) {
				return $group_details;
			}

			if ( ! is_array( $group_details ) || isset( $group_details['errors'] ) ) {
				return new WP_Error( 'invalid-response', __( 'Received invalid response from Meetup API.', 'wordcamporg' ) );
			}

			$group_leads = $mtp_client->get_group_members(
				$slug,
				array(
					'role' => 'leads',
				)
			);

			if ( is_wp_error( $group_leads ) ) {
				return $group_leads;
			}

			if ( ! is_array( $group_leads ) || isset( $group_leads['errors'] ) ) {
				return new WP_Error( 'invalid-response-leads', __( 'Received invalid response from Meetup API.', 'wordcamporg' ) );
			}

			$event_hosts = array();
			foreach ( $group_leads as $event_host ) {
				if ( ! is_array( $event_host ) ) {
					continue;
				}
				// Skip WordPress admin user.
				if ( WCPT_WORDPRESS_MEETUP_ID === (int) $event_host['id'] ) {
					continue;
				}
				$event_hosts[] = array(
					'name' => $event_host['name'],
					'id'   => $event_host['id'],
				);
			}

			update_post_meta( $post_id, 'Meetup Co-organizer names', $event_hosts );
			update_post_meta( $post_id, 'Meetup Location (From meetup.com)', $group_details['localized_location'] );
			update_post_meta( $post_id, 'Meetup members count', $group_details['members'] );
			update_post_meta( $post_id, 'Meetup group created on', $group_details['created'] );

			if ( isset( $group_details['last_event'] ) && is_array( $group_details['last_event'] ) ) {
				update_post_meta( $post_id, 'Number of past meetups', $group_details['past_event_count'] );
				update_post_meta( $post_id, 'Last meetup on', $group_details['last_event']['time'] );
				update_post_meta( $post_id, 'Last meetup RSVP count', $group_details['last_event']['yes_rsvp_count'] );
			}
			update_post_meta( $post_id, 'Last meetup.com API sync', time() );
		}

		/**
		 * Trigger action `update_meetup_organizers` when new organizers are added.
		 * Note: While we add badges to new organizers, we do not remove them from organizers who has stepped down.
		 * This is because, we want to give credit if someone has organized a meetup in the past even if they are not active presently.
		 *
		 * @param int   $post_id
		 * @param array $original_data
		 */
		public function meetup_organizers_changed( $post_id, $original_data ) {
			global $post;

			if ( $this->get_event_type() !== get_post_type() ) {
				return;
			}

			if ( 'wcpt-mtp-active' !== $post->post_status ) {
				return;
			}

			$organizers_list = $this->get_organizer_list(
				get_post_meta( $post_id, 'Primary organizer WordPress.org username', true ),
				get_post_meta( $post_id, 'Co-Organizers usernames (seperated by comma)', true )
			);

			$original_organizers_list = $this->get_organizer_list(
				$original_data['Primary organizer WordPress.org username'][0] ?? '',
				$original_data['Co-Organizers usernames (seperated by comma)'][0] ?? ''
			);

			$new_organizers = array_diff( $organizers_list, $original_organizers_list );

			$this->update_meetup_organizers( $new_organizers, $post );

		}

		/**
		 * If status is set to `Active in the Chapter` then add badges for all organizers in the list.
		 *
		 * @param string  $new_status
		 * @param string  $old_status
		 * @param WP_Post $post
		 */
		public function maybe_update_organizers( $new_status, $old_status, $post ) {

			if ( $this->get_event_type() !== get_post_type() ) {
				return;
			}

			if ( 'wcpt-mtp-active' !== $post->post_status ) {
				return;
			}

			if ( $new_status === $old_status ) {
				// When both the status are same (and set to active), then we do not need to do anything. This is handled by meetup_organizers_changed function.
				return;
			}

			$organizers_list = $this->get_organizer_list(
				get_post_meta( $post->ID, 'Primary organizer WordPress.org username', true ),
				get_post_meta( $post->ID, 'Co-Organizers usernames (seperated by comma)', true )
			);

			$this->update_meetup_organizers( $organizers_list, $post );

		}

		/**
		 * Send notification to slack when a Meetup becomes active in the chapter or is declined.
		 *
		 * @param string  $new_status
		 * @param string  $old_status
		 * @param WP_Post $meetup
		 *
		 * @return null|bool Will be null if notification was not enabled, or false if notifcation was attempted but failed. true if notification was successful
		 */
		public function notify_application_status_in_slack( $new_status, $old_status, WP_Post $meetup ) {
			if ( 'wcpt-mtp-active' === $new_status && 'wcpt-mtp-active' !== $old_status ) {
				return $this->notify_new_meetup_group_in_slack( $meetup );

			} elseif ( 'wcpt-mtp-rejected' === $new_status && 'wcpt-mtp-rejected' !== $old_status ) {
				$location = get_post_meta( $meetup->ID, 'Meetup Location', true );
				return $this->schedule_decline_notification( $meetup, $this->get_event_label(), $location );
			}
		}

		/**
		 * Send notification when a new Meetup groups is added to the chapter.
		 *
		 * @param WP_Post $meetup Meetup post object.
		 *
		 * @return null|bool|string
		 */
		public static function notify_new_meetup_group_in_slack( $meetup ) {
			$new_group_notification_key = 'sent_new_group_notification';
			if ( get_post_meta( $meetup->ID, $new_group_notification_key, true ) ) {
				return null;
			}
			// Not translating strings here because these will be sent to Slack.
			$city            = get_post_meta( $meetup->ID, 'Meetup Location', true );
			$organizer_slack = get_post_meta( $meetup->ID, 'Slack', true );
			$meetup_link     = get_post_meta( $meetup->ID, 'Meetup URL', true );
			$title           = 'New meetup group added';

			$message = sprintf(
				"Let's welcome the new WordPress meetup group%s%s, to the chapter! :tada: :community: :WordPress:\n%s",
				empty( $city ) ? '' : " in $city,",
				empty( $organizer_slack ) ? '' : " organized by @$organizer_slack",
				$meetup_link
			);

			$attachment = create_event_status_attachment( $message, $meetup->ID, $title );

			$notification_sent = wcpt_slack_notify( COMMUNITY_EVENTS_SLACK, $attachment );
			if ( $notification_sent ) {
				update_post_meta( $meetup->ID, $new_group_notification_key, true );
			}
			return $notification_sent;
		}

		/**
		 * Helper function for getting list of organizers.
		 *
		 * @param string $main_organizer
		 * @param string $co_organizers
		 *
		 * @return array
		 */
		private function get_organizer_list( $main_organizer, $co_organizers ) {
			$organizer_list = array();
			if ( ! empty( $main_organizer ) ) {
				$organizer_list[] = $main_organizer;
			}

			if ( ! empty( $co_organizers ) ) {
				$co_organizers_list = array_map( 'trim', explode( ',', $co_organizers ) );
				$organizer_list     = array_merge( $organizer_list, $co_organizers_list );
			}
			return $organizer_list;
		}

		/**
		 * Helper method which triggers action `update_meetup_organizers`
		 *
		 * @param array   $organizers
		 * @param WP_Post $post
		 */
		protected function update_meetup_organizers( $organizers, $post ) {
			if ( ! empty( $organizers ) ) {
				do_action( 'update_meetup_organizers', $organizers, $post );
			}
		}

		/**
		 * List of admin notices.
		 *
		 * @return array
		 */
		public function get_admin_notices() {

			return array(
				'invalid-url'        => array(
					'type'   => 'notice',
					'notice' => __( 'Invalid meetup.com URL. Meetup fields are not updated.', 'wordcamporg' ),
				),
				'invalid-response'   => array(
					'type'   => 'notice',
					'notice' => __( 'Received invalid response from Meetup API. Please make sure Meetup URL is correct, or try again after some time.', 'wordcamporg' ),
				),
				'group_error'        => array(
					'type'   => 'notice',
					'notice' => __( 'Received invalid response from Meetup API. Please make sure Meetup URL is correct, or try again after some time.', 'wordcamporg' ),
				),
				'http_response_code' => array(
					'type'   => 'notice',
					'notice' => __( 'Received invalid response code from Meetup API. Please make sure Meetup URL is correct, or try again after some time.', 'wordcamporg' ),
				),
				'invalid-group-url'       => array(
					'type'   => 'notice notice-warning',
					'notice' => __( 'The GatherPress group URL doesn\'t match a group on events.wordpress.org, so the group isn\'t marked as migrated.', 'wordcamporg' ),
				),
				'group-refresh-requested' => array(
					'type'   => 'notice notice-success',
					'notice' => __( 'Group data refresh requested. It usually updates within the hour.', 'wordcamporg' ),
				),
			);

		}

		/**
		 * Render list of co-organizer of meetup linking to their profile on meetup.com
		 *
		 * @param string $key Name of meetup field. Should be 'Meetup Co-organizer names'.
		 */
		public function render_co_organizers_list( $key ) {
			global $post_id;
			if ( 'Meetup Co-organizer names' !== $key ) {
				return;
			}
			$organizers = get_post_meta( $post_id, $key, true );
			if ( isset( $organizers ) && is_array( $organizers ) ) {
				$group_slug = get_post_meta( $post_id, 'Meetup URL', true );
				if ( empty( $group_slug ) ) {
					echo 'Invalid Meetup Group URL';
					return;
				}
				echo '<ul>';
				foreach ( $organizers as $organizer ) {
					$organizer_id       = $organizer['id'];
					$meetup_profile_url = "$group_slug/members/$organizer_id";
					?>
					<li>
						<a target="_blank" rel="noopener" href="<?php echo esc_html( $meetup_profile_url ); ?>">
							<?php echo esc_html( $organizer['name'] ); ?>
						</a>
					</li>
					<?php
				}
				echo '</ul>';
			} else {
				esc_html_e( 'No meetup organizers set.', 'wordcamp.org' );
			}
		}

		/**
		 * Meta keys group for Meetup Event.
		 *
		 * @param string $meta_group
		 *
		 * @return array
		 */
		public static function meta_keys( $meta_group = '' ) {

			$info_keys = array(
				'Meetup URL'                                   => 'text',
				self::GROUP_URL_KEY                            => 'text',
				'Meetup Co-organizer names'                  => 'meetup_coorganizers',
				'Primary organizer WordPress.org username'     => 'text',
				'Co-Organizers usernames (seperated by comma)' => 'text',
				'Meetup Location (From meetup.com)'            => 'text',
				'Meetup members count'                         => 'text',
				'Meetup group created on'                      => 'date',
				'Number of past meetups'                       => 'text',
				'Last meetup on'                               => 'date',
				'Last meetup RSVP count'                       => 'text',
				'FreeScout link'                               => 'text',
				'Meetup Location'                              => 'text',
			);

			$application_keys = array(
				'Date Applied'                               => 'date',
				'Date of Last Contact'                       => 'date',
				'Who contacted (Wordpress.org username)'     => 'text',
				'Vetted Date'                                => 'date',
				'Vetted by (Wordpress.org username)'         => 'text',
				'Orientation Date'                           => 'date',
				'Oriented by (Wordpress.org username)'       => 'text',
				'Joined chapter date'                        => 'date',
				'Joined chapter by (Wordpress.org username)' => 'text',
			);

			$organizer_keys = array(
				'Organizer Name'        => 'text',
				'Email'                 => 'text',
				'Organizer description' => 'text',
				'Date closed'           => 'date',
				'Slack'                 => 'text',
				'Region'                => 'text',
				'Language'              => 'select-locale',
				'Address'               => 'textarea',
				'Extra Comments'        => 'textarea',
			);

			$metadata_keys = array(
				'Last meetup.com API sync' => 'date',
			);

			switch ( $meta_group ) {
				case 'information':
					$data = $info_keys;
					break;
				case 'application':
					$data = $application_keys;
					break;
				case 'organizer':
					$data = $organizer_keys;
					break;
				case 'metadata':
					$data = $metadata_keys;
					break;
				case 'all':
				default:
					$data = array_merge(
						$info_keys,
						$application_keys,
						$organizer_keys,
						$metadata_keys
					);
			}
			return $data;
		}

		/**
		 * List of meta keys that can be exposed publicly.
		 *
		 * @return array
		 */
		public static function get_public_meta_keys() {
			return array(
				'Meetup URL',
				'Meetup Location (From meetup.com)',
				'Last meetup on',
			);
		}

		/**
		 * Return a list of valid Event Subtypes.
		 *
		 * @return array
		 */
		public function get_event_subtypes() {
			return array(
				'wp_meetup' => __( 'WordPress Meetup', 'wordcamporg' ),
			);
		}

		/**
		 * Schedule cron job for updating data from meetup API
		 */
		public function schedule_cron_jobs() {
			if ( wp_next_scheduled( 'wcpt_meetup_api_sync' ) ) {
				return;
			}

			wp_schedule_event( current_time( 'timestamp' ), 'weekly', 'wcpt_meetup_api_sync' );
		}


		/**
		 * Cron worker for syncing with Meetup.com API data
		 */
		public static function meetup_api_sync() {
			$query = new WP_Query(
				array(
					'post_type'      => self::get_event_type(),
					'post_status'    => [
						'wcpt-mtp-active',
						'wcpt-mtp-dormant',
						'wcpt-mtp-nds-nw-ow',
					],
					'fields'         => 'ids',
					'posts_per_page' => - 1,
				)
			);

			foreach ( $query->posts as $post_id ) {
				self::update_meetup_data( $post_id );
			}
		}

	}

endif;

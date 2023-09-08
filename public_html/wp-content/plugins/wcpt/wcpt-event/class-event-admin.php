<?php
/**
 * Implement Event_Admin class
 *
 * @package WordCamp Post Type
 */

/**
 * Class Event_Admin
 *
 * Abstract class providing common functions for event admins
 *
 * @package WordCamp Post Type
 * @subpackage Admin
 */
abstract class Event_Admin {

	public $active_admin_notices;

	/**
	 * Event_Admin constructor.
	 */
	public function __construct() {

		$this->active_admin_notices = array();

		add_action( 'add_meta_boxes', array( $this, 'metabox' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_status_metabox' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_log_metabox' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_note_metabox' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_original_application' ) );

		add_action( 'save_post_' . $this->get_event_type(), array( $this, 'metabox_save' ), 10, 2 );

		add_action( 'manage_posts_custom_column', array( $this, 'column_data' ), 10, 2 );

		add_filter( 'post_row_actions', array( $this, 'post_row_actions' ), 10, 2 );

		add_filter(
			'manage_' . $this->get_event_type() . '_posts_columns',
			array(
				$this,
				'column_headers',
			)
		);
		// Forum column headers.
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'common_admin_scripts' ) );

		add_action( 'admin_print_styles', array( $this, 'common_admin_styles' ) );

		add_action( 'transition_post_status', array( $this, 'log_status_changes' ), 10, 3 );

		add_action( 'transition_post_status', array( $this, 'notify_application_status_in_slack' ), 10, 3 );

		add_filter( 'redirect_post_location', array( $this, 'add_admin_notices_to_redirect_url' ), 10, 2 );

		// Admin notices.
		add_action( 'admin_notices', array( $this, 'print_admin_notices' ) );

		add_action( 'send_decline_notification_action',  'Event_Admin::send_decline_notification', 10, 3 );
	}

	/**
	 * Get all possible valid transitions from given status.
	 *
	 * @param string $status Name of status.
	 *
	 * @return array List of all valid transitions.
	 */
	abstract public static function get_valid_status_transitions( $status );

	/**
	 * List of all possible status.
	 *
	 * @return array
	 */
	abstract public static function get_post_statuses();

	/**
	 * Get post type
	 *
	 * @return string
	 */
	abstract public static function get_event_type();

	/**
	 * Get user facing event label
	 *
	 * @return string
	 */
	abstract public static function get_event_label();

	/**
	 * Get meta key group
	 *
	 * @param string $meta_group Name of meta group.
	 *
	 * @return array
	 */
	abstract public static function meta_keys( $meta_group = '' );

	/**
	 * Check if $key is a read-only field
	 *
	 * @param string $key Name of the field.
	 *
	 * @return bool
	 */
	abstract public static function is_protected_field( $key );

	/**
	 * Add metaboxes to admin view
	 */
	abstract public function metabox();

	/**
	 * Render column data for a given column
	 *
	 * @param string $column  Name of column.
	 * @param int    $post_id Post ID.
	 */
	abstract public function column_data( $column, $post_id );

	/**
	 * Change post row actions
	 *
	 * @param array   $actions Array of actions.
	 * @param WP_Post $post    Post object.
	 */
	abstract public function post_row_actions( $actions, $post );

	/**
	 * Render column headers
	 *
	 * @param array $columns List of columns.
	 */
	abstract public function column_headers( $columns );

	/**
	 * Get a list of streaming services.
	 *
	 * The individual event types can override this if different event types need different streaming accounts,
	 * but these are the default accounts available.
	 *
	 * @return array
	 */
	public static function get_streaming_services() {
		return array(
			'crowdcast-wc-1' => 'Crowdcast WordCamp 1',
			'crowdcast-wc-2' => 'Crowdcast WordCamp 2',
			'other' => 'Other',
		);
	}

	/**
	 * Add status metabox
	 */
	public function add_status_metabox() {
		add_meta_box(
			'submitdiv',
			__( 'Status', 'wordcamporg' ),
			array( $this, 'metabox_status' ),
			$this->get_event_type(),
			'side',
			'high'
		);

	}

	/**
	 * Add log meta box to application
	 */
	public function add_log_metabox() {
		if ( ! current_user_can( $this->get_edit_capability() ) ) {
			return;
		}

		if ( ! current_user_can( 'wordcamp_wrangle_wordcamps' ) ) {
			return;
		}

		add_meta_box(
			'wcpt_log',
			'Log',
			'wcpt_log_metabox',
			$this->get_event_type(),
			'advanced',
			'low'
		);

	}

	/**
	 * Add note metabox to application
	 */
	public function add_note_metabox() {
		if ( ! current_user_can( $this->get_edit_capability() ) ) {
			return;
		}

		if ( ! current_user_can( 'wordcamp_wrangle_wordcamps' ) ) {
			return;
		}

		add_meta_box(
			'wcpt_notes',
			__( 'Add Private Note', 'wordcamporg' ),
			'wcpt_add_note_metabox',
			$this->get_event_type(),
			'advanced',
			'high'
		);
	}

	/**
	 * Add original application metabox
	 */
	public function add_original_application() {
		if ( ! current_user_can( $this->get_edit_capability() ) ) {
			return;
		}

		add_meta_box(
			'wcpt_original_application',
			'Original Application',
			array( $this, 'original_application_metabox' ),
			$this->get_event_type(),
			'advanced',
			'low'
		);
	}

	/**
	 * Render the Original application
	 *
	 * @param WP_Post $post Current post.
	 */
	public function original_application_metabox( $post ) {
		$application_data = get_post_meta( $post->ID, '_application_data', true );
		require_once WCPT_DIR . 'views/common/metabox-original-application.php';
	}

	/**
	 * Render the WordCamp status meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function metabox_status( $post ) {
		require_once WCPT_DIR . 'views/wordcamp/metabox-status.php';

		render_event_metabox( $this, $post, $this->get_event_type(), $this->get_event_label(), $this->get_edit_capability() );
	}

	/**
	 * Gets name of capability required to edit the current event
	 *
	 * @return string
	 */
	abstract public static function get_edit_capability();

	/**
	 * Filter: Set the locale to en_US.
	 *
	 * For some purposes, such as internal logging, strings that would normally be translated to the
	 * current user's locale should be in English, so that other users who may not share the same
	 * locale can read them.
	 *
	 * @return string
	 */
	public function set_locale_to_en_us() {
		return 'en_US';
	}

	/**
	 * Log when the post status changes
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Current Post.
	 */
	public function log_status_changes( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || 'auto-draft' === $new_status ) {
			return;
		}

		if ( empty( $post->post_type ) || $this->get_event_type() !== $post->post_type ) {
			return;
		}

		// Ensure status labels are in English.
		add_filter( 'locale', array( $this, 'set_locale_to_en_us' ) );

		$old_status = get_post_status_object( $old_status );
		$new_status = get_post_status_object( $new_status );

		$log_id = add_post_meta(
			$post->ID,
			'_status_change',
			array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'message'   => sprintf( '%s &rarr; %s', $old_status->label, $new_status->label ),
			)
		);

		// Encoding $post_type and status_change meta ID in key so that we can fetch it if needed while simultaneously be able to have a where clause on value.
		// Because of the way MySQL works, it will still be able to use index on meta_key when searching, as long as we are querying just the prefix.
		if ( $log_id ) {
			add_post_meta( $post->ID, "_status_change_log_$post->post_type $log_id", time() );
		}

		// Remove the temporary locale change.
		remove_filter( 'locale', array( $this, 'set_locale_to_en_us' ) );
	}

	/**
	 * Hooked to `transition_post_status`, will send notifications to community slack channels based whenever an application status changes to something that we are interested in. Most likely would be when an application is declined or accepted.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old Status.
	 * @param WP_Post $event
	 */
	abstract public function notify_application_status_in_slack( $new_status, $old_status, WP_Post $event );

	/**
	 * Schedule notification for declined application. Currently supports WordCamp and Meetup.
	 *
	 * @param WP_Post $event Event object.
	 * @param string  $label Could be WordCamp or Meetup.
	 * @param string  $location
	 */
	public static function schedule_decline_notification( $event, $label, $location ) {
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'send_decline_notification_action', array( $event->ID, $label, $location ) );
	}

	/**
	 * Send declined notification to community team slack channel.
	 *
	 * @param int    $event_id
	 * @param string $label
	 * @param string $location
	 *
	 * @return null|bool|string
	 */
	public static function send_decline_notification( $event_id, $label, $location ) {
		$declined_notification_key = 'sent_declined_notification';
		if ( get_post_meta( $event_id, $declined_notification_key, true ) ) {
			return null;
		}

		$message = sprintf(
			'A %s application for %s has been declined, and the applicant has been informed via email.',
			$label,
			$location
		);

		$attachment = create_event_status_attachment( $message, $event_id, '' );

		$notification_sent = wcpt_slack_notify( COMMUNITY_TEAM_SLACK, $attachment );
		if ( $notification_sent ) {
			update_post_meta( $event_id, $declined_notification_key, true );
		}
		return $notification_sent;
	}

	/**
	 * Load common admin side scripts
	 */
	public function common_admin_scripts() {

		if ( $this->get_event_type() !== get_post_type() ) {
			return;
		}

		wp_register_script(
			'wcpt-admin',
			WCPT_URL . 'javascript/wcpt-wordcamp/admin.js',
			array( 'jquery', 'jquery-ui-datepicker' ),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . '/javascript/wcpt-wordcamp/admin.js' ),
			true
		);

		$gutenberg_enabled = false;
		$current_screen    = get_current_screen();
		if ( method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor() ) {
			$gutenberg_enabled = true;
		}

		wp_localize_script(
			'wcpt-admin',
			'wcpt_admin',
			array( 'gutenberg_enabled' => $gutenberg_enabled )
		);

		wp_enqueue_script( 'wcpt-admin' );
		wp_enqueue_script( 'select2' );

	}

	/**
	 * Load common admin styles
	 */
	public function common_admin_styles() {

		if ( $this->get_event_type() !== get_post_type() ) {
			return;
		}

		wp_register_style(
			'wcpt-admin',
			plugins_url( 'css/applications/admin.css', __DIR__ ),
			array(),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . '/css/applications/admin.css' )
		);

		wp_enqueue_style( 'jquery-ui' );
		wp_enqueue_style( 'wp-datepicker-skins' );
		wp_enqueue_style( 'select2' );
		wp_enqueue_style( 'wcpt-admin' );

	}

	/**
	 * Display the status of a WordCamp post
	 *
	 * @param array   $states List of post states.
	 * @param WP_Post $post   Current post.
	 *
	 * @return array
	 */
	public function display_post_states( $states, $post ) {
		if ( $post->post_type !== $this->get_event_type() ) {
			return $states;
		}

		$status = get_post_status_object( $post->post_status );
		if ( get_query_var( 'post_status' ) !== $post->post_status ) {
			$states[ $status->name ] = $status->label;
		}

		return $states;
	}

	/**
	 * Save metadata from form.
	 *
	 * @param int     $post_id      The ID of the post being saved.
	 * @param WP_Post $post         The post being saved.
	 * @param bool    $verify_nonce Whether or not to verify the nonce. Set to false when calling manually, leave
	 *                              true when calling via `save_post` hook.
	 *
	 * @hook save_post
	 */
	public function metabox_save( $post_id, $post, $verify_nonce = true ) {
		// Don't add/remove meta on revisions and auto-saves.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( $this->get_event_type() !== $post->post_type ) {
			return;
		}

		// Don't add/remove meta on trash, untrash, restore, etc.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['action'] ) || 'editpost' !== $_POST['action'] ) {
			return;
		}

		// Make sure the request came from the edit post screen.
		if ( $verify_nonce ) {
			if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post_id ) ) {
				wp_die( 'Unable to verify nonce.' );
			}
		}

		$meta_keys        = $this->meta_keys();
		$orig_meta_values = get_post_meta( $post_id );
		$is_virtual_event = WordCamp_admin::is_virtual_event( $post_id );

		foreach ( $meta_keys as $key => $value ) {
			$post_value     = wcpt_key_to_str( $key, 'wcpt_' );
			$values[ $key ] = isset( $_POST[ $post_value ] ) ? esc_attr( $_POST[ $post_value ] ) : '';

			// Don't update protected fields.
			if ( $this->is_protected_field( $key ) ) {
				continue;
			}

			$username_fields = array(
				'Primary organizer WordPress.org username',
				'Co-Organizers usernames (seperated by comma)',
				'WordPress.org Username',
				'Mentor WordPress.org User Name',
			);

			if ( in_array( $key, $username_fields, true ) ) {
				$usernames_array        = empty( $values[ $key ] ) ? array() : explode( ',', $values[ $key ] );
				$standardized_usernames = self::standardize_usernames( $usernames_array );
				$values[ $key ]         = implode( ', ', $standardized_usernames );
			}

			// Clear out user-facing location when switching event types, to avoid confusion & inaccurate data.
			if ( 'Physical Address' === $key && $is_virtual_event ) {
				$values[ $key ] = '';

			} elseif ( 'Host region' === $key && ! $is_virtual_event ) {
				$values[ $key ] = '';
			}

			switch ( $value ) {
				case 'text':
				case 'deputy_list':
				case 'textarea':
					update_post_meta( $post_id, $key, $values[ $key ] );
					break;

				case 'number':
					update_post_meta( $post_id, $key, floatval( $values[ $key ] ) );
					break;

				case 'checkbox':
					if ( ! empty( $values[ $key ] ) && 'on' == $values[ $key ] ) {
						update_post_meta( $post_id, $key, true );
					} else {
						update_post_meta( $post_id, $key, false );
					}
					break;

				case 'date':
					if ( ! empty( $values[ $key ] ) ) {
						$values[ $key ] = strtotime( $values[ $key ] );
					}

					update_post_meta( $post_id, $key, $values[ $key ] );
					break;

				case 'select-currency':
					$currencies = WordCamp_Budgets::get_currencies();
					$new_value  = ( array_key_exists( $values[ $key ], $currencies ) ) ? $values[ $key ] : '';

					update_post_meta( $post_id, $key, $new_value );
					break;

				case 'select-timezone':
					$allowed_zones = timezone_identifiers_list();
					$new_value     = in_array( $values[ $key ], $allowed_zones, true ) ? $values[ $key ] : '';

					update_post_meta( $post_id, $key, $new_value );
					break;

				case 'select-streaming':
					$allowed_values = array_keys( self::get_streaming_services() );
					$key_other      = wcpt_key_to_str( $key, 'wcpt_' ) . '-other';
					if ( in_array( $values[ $key ], $allowed_values ) ) {
						update_post_meta( $post_id, $key, $values[ $key ] );

						if ( ! empty( $_POST[ $key_other ] ) ) {
							update_post_meta(
								$post_id,
								$key_other,
								sanitize_text_field( $_POST[ $key_other ] )
							);
						}
					} elseif ( ! empty( $values[ $key ] ) ) {
						// The value isn't in the allowed values (anymore?) so we should save it as "other".
						update_post_meta( $post_id, $key, 'other' );
						update_post_meta( $post_id, $key_other, $values[ $key ] );
					} else {
						// The value is empty, any existing value should be removed.
						delete_post_meta( $post_id, $key );
						delete_post_meta( $post_id, $key_other );
					}
					break;

				default:
					do_action( 'wcpt_metabox_save', $key, $value, $post_id );
					break;
			}
		}

		// TODO: This should also pass $_POST params since nonce is verified here.
		do_action( 'wcpt_metabox_save_done', $post_id, $orig_meta_values );

		$this->validate_and_add_note( $post_id );

	}

	/**
	 * Convert `user_nicename` values to `user_login` for compatibility.
	 *
	 * `WordCamp_Participation_Notifier::update_meetup_organizers` expects this to be `user_login`, and adding
	 * a `Meetup Organizer` badge will fail if it's `user_nicename`.
	 *
	 * @return array
	 */
	public static function standardize_usernames( array $raw_usernames, $failure_mode = 'die' ) {
		$standard_usernames = array();

		foreach ( $raw_usernames as $username ) {
			$user = wcorg_get_user_by_canonical_names( $username );

			if ( ! $user instanceof WP_User ) {
				$error_message = sprintf(
					'%s is not a valid WordPress.org username.',
					esc_html( $username )
				);

				if ( 'die' === $failure_mode ) {
					wp_die( $error_message );
				} else {
					return new WP_Error( 'invalid_username', $error_message . " Please click on your browser's back button and enter a valid one." );
				}
			}

			$standard_usernames[] = $user->user_login;
		}

		return $standard_usernames;
	}

	/**
	 * Add our custom admin notice keys to the redirect URL.
	 *
	 * Any member can add a key to $this->active_admin_notices to signify that the corresponding message should
	 * be shown when the redirect finished. When it does, print_admin_notices() will examine the URL and create
	 * a notice with the message that corresponds to the key.
	 *
	 * @param string $location
	 * @param int    $post_id
	 *
	 * @return string
	 */
	public function add_admin_notices_to_redirect_url( $location, $post_id ) {
		if ( $this->active_admin_notices ) {
			$location = add_query_arg( 'wcpt_messages', implode( ',', $this->active_admin_notices ), $location );
		}

		// Don't show conflicting messages like 'Post submitted'.
		if ( in_array( 1, $this->active_admin_notices ) && false !== strpos( $location, 'message=8' ) ) {
			$location = remove_query_arg( 'message', $location );
		}

		return $location;
	}

	/**
	 * Return admin notices for messages that were passed in the URL.
	 */
	abstract public function get_admin_notices();

	/**
	 * Create admin notices for messages that were passed in the URL.
	 *
	 * Any member can add a key to $this->active_admin_notices to signify that the corresponding message should
	 * be shown when the redirect finished. add_admin_notices_to_redirect_url() adds those keys to the redirect
	 * url, and this function examines the URL and create a notice with the message that corresponds to the key.
	 *
	 * $notices[key]['type'] should equal 'error' or 'updated'.
	 */
	public function print_admin_notices() {

		global $post;
		$screen = get_current_screen();

		if ( empty( $post->post_type ) || $this->get_event_type() != $post->post_type || 'post' !== $screen->base ) {
			return;
		}

		$notices = $this->get_admin_notices();

		if ( ! empty( $_REQUEST['wcpt_messages'] ) ) {
			$active_notices = explode( ',', $_REQUEST['wcpt_messages'] );

			foreach ( $active_notices as $notice_key ) {
				if ( isset( $notices[ $notice_key ] ) ) {
					?>

					<div class="<?php echo esc_attr( $notices[ $notice_key ]['type'] ); ?>">
						<p><?php echo wp_kses( $notices[ $notice_key ]['notice'], wp_kses_allowed_html( 'post' ) ); ?></p>
					</div>

					<?php
				}
			}
		}

	}

	/**
	 * Validate and add a new private note
	 *
	 * @param int $post_id
	 */
	protected function validate_and_add_note( $post_id ) {
		if ( empty( $_POST['wcpt_new_note'] ) ) {
			return;
		}

		check_admin_referer( 'wcpt_notes', 'wcpt_notes_nonce' );

		$new_note_message = sanitize_textarea_field( wp_unslash( $_POST['wcpt_new_note'] ) );

		if ( empty( $new_note_message ) ) {
			return;
		}

		// Note that this is private, see `wcpt_get_log_entries()`.
		add_post_meta(
			$post_id,
			'_note',
			array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'message'   => $new_note_message,
			)
		);
	}

	/**
	 * Displays meta boxes in admin view.
	 *
	 * @param array $required_fields Required fields.
	 * @param array $meta_keys List of meta keys.
	 * @param array $messages List of messages to displayed by a key.
	 * @param int   $post_id ID of post.
	 * @param array $protected_fields List of read only fields.
	 */
	public static function display_meta_boxes(
		$required_fields,
		$meta_keys,
		$messages,
		$post_id,
		$protected_fields
	) {

		foreach ( $meta_keys as $key => $value ) :
			$object_name = wcpt_key_to_str( $key, 'wcpt_' );
			$readonly    = in_array( $key, $protected_fields ) ? ' readonly="readonly"' : '';
			$classes     = array(
				'inside',
				'wcpt-field',
				'field__' . $object_name,
				'field__type-' . $value,
			);

			?>

			<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<?php if ( 'checkbox' == $value ) : ?>

					<p>
						<label>
							<strong><?php echo esc_html( $key ); ?></strong>:
							<input
								type="checkbox"
								name="<?php echo esc_attr( $object_name ); ?>"
								id="<?php echo esc_attr( $object_name ); ?>"
								<?php checked( get_post_meta( $post_id, $key, true ) ); ?>
								<?php echo esc_attr( $readonly ); ?>
							/>
						</label>
					</p>

				<?php else : ?>

					<p>
						<label for="<?php echo esc_attr( $object_name ); ?>"><?php echo esc_html( $key ); ?></label>
						<?php if ( in_array( $key, $required_fields, true ) ) : ?>
							<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
						<?php endif; ?>
					</p>

					<p>
						<?php
						switch ( $value ) :
							case 'text':
								?>

								<input type="text" name="<?php echo esc_attr( $object_name ); ?>"
									   id="<?php echo esc_attr( $object_name ); ?>"
									   value="<?php echo esc_attr( get_post_meta( $post_id, $key, true ) ); ?>"<?php echo esc_attr( $readonly ); ?> />

								<?php
								break;
							case 'number':
								?>

								<input
									type="number"
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									value="<?php echo esc_attr( get_post_meta( $post_id, $key, true ) ); ?>"
									step="any"
									min="0"
									<?php echo esc_attr( $readonly ); ?>
								/>

								<?php
								break;
							case 'date':
								// Quick filter on dates.
								$date = get_post_meta( $post_id, $key, true );
								if ( $date ) {
									$date = gmdate( 'Y-m-d', $date );
								}

								?>

								<input
									type="text"
									class="date-field"
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									value="<?php echo esc_attr( $date ); ?>"
									<?php echo esc_attr( $readonly ); ?>
								/>

								<?php
								break;
							case 'textarea':
								?>

								<textarea
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									<?php echo esc_attr( $readonly ); ?>
								><?php echo esc_attr( get_post_meta( $post_id, $key, true ) ); ?></textarea>

								<?php
								break;
							case 'select-currency':
								$currencies = WordCamp_Budgets::get_currencies();
								?>

								<?php
								if ( $readonly ) :
									$value = get_post_meta( $post_id, $key, true );
									?>
								<select
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									<?php echo esc_attr( $readonly ); ?>
								>
									<option value="<?php echo esc_attr( $value ); ?>" selected>
										<?php echo ( $value ) ? esc_html( $currencies[ $value ] . ' (' . $value . ')' ) : ''; ?>
									</option>
								</select>
							<?php else : ?>
								<select
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									class="select-currency"
								>
									<?php foreach ( $currencies as $symbol => $name ) : ?>
										<option
											value="<?php echo esc_attr( $symbol ); ?>"
											<?php selected( $symbol, get_post_meta( $post_id, $key, true ) ); ?>
										>
											<?php echo ( $symbol ) ? esc_html( $name . ' (' . $symbol . ')' ) : ''; ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>

								<?php
								break;

							case 'select-timezone':
								$selected = get_post_meta( $post_id, $key, true );
								?>

								<select
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
								>
									<option value="">
										<?php esc_html_e( 'Choose a timezone', 'wordcamporg' ); ?>
									</option>
									<option value=""></option>

									<?php foreach ( timezone_identifiers_list() as $timezone ) : ?>
										<option
											value="<?php echo esc_attr( $timezone ); ?>"
											<?php if ( $selected === $timezone ) {
												echo 'selected'; } ?>
										>
											<?php echo esc_html( $timezone ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<a href="https://www.zeitverschiebung.net/en/" target="_blank" rel="noopener noreferrer">
									Lookup
									<span class="screen-reader-text">(opens in a new tab)</span>
									<span aria-hidden="true" class="dashicons dashicons-external"></span>
								</a>

								<?php
								break;

							case 'deputy_list':
								wp_dropdown_users(
									array(
										'role__in'         => array(
											'administrator',
											'editor',
										),
										'name'             => esc_attr( $object_name ),
										'id'               => esc_attr( $object_name ),
										'selected'         => get_post_meta( $post_id, $key, true ),
										'show_option_none' => 'None',
									)
								);
								break;
							case 'select-streaming':
								$selected = get_post_meta( $post_id, $key, true );
								$options  = self::get_streaming_services();
								?>

								<select
									name="<?php echo esc_attr( $object_name ); ?>"
									id="<?php echo esc_attr( $object_name ); ?>"
									<?php echo esc_attr( $readonly ); ?>
								>
									<option value="">None, not streaming</option>
									<?php foreach ( $options as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $selected, $val ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<label class="screen-reader-text" for="<?php echo esc_attr( $object_name ); ?>-other">
									Other streaming account:
								</label>
								<input
									type="text"
									placeholder="Other streaming service"
									id="<?php echo esc_attr( $object_name ); ?>-other"
									name="<?php echo esc_attr( $object_name ); ?>-other"
									value="<?php echo esc_attr( get_post_meta( $post_id, $object_name . '-other', true ) ); ?>"
								/>

								<?php
								break;
							default:
								do_action( 'wcpt_metabox_value', $key, $value, $object_name );
								break;

						endswitch;
						?>

						<?php if ( ! empty( $messages[ $key ] ) ) : ?>
							<?php
							if ( 'textarea' == $value ) {
								echo '<br />';
							}
							?>

							<span class="description"><?php echo esc_html( $messages[ $key ] ); ?></span>
						<?php endif; ?>
					</p>

				<?php endif; ?>
			</div>

			<?php
		endforeach;
	}


}

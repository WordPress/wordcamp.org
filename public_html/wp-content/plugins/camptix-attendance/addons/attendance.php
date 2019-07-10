<?php
/**
 * Allows event organizers to track which attendees showed up to the event.
 */
class CampTix_Attendance extends CampTix_Addon {
	public $secret    = '';
	public $questions = array();
	/**
	 * Runs during CampTix init.
	 */
	public function camptix_init() {
		global $camptix;

		// Admin Settings UI.
		if ( current_user_can( $camptix->caps['manage_options'] ) ) {
			add_filter( 'camptix_setup_sections', array( $this, 'setup_sections' ) );
			add_action( 'camptix_menu_setup_controls', array( $this, 'setup_controls' ), 10, 1 );
			add_filter( 'camptix_validate_options', array( $this, 'validate_options' ), 10, 2 );
		}

		$camptix_options = $camptix->get_options();
		if ( empty( $camptix_options['attendance-secret'] ) )
			return;

		$this->secret = $camptix_options['attendance-secret'];

		if ( isset( $camptix_options['attendance-questions'] ) ) {
			$this->questions = $camptix_options['attendance-questions'];
		}

		if ( empty( $camptix_options['attendance-enabled'] ) )
			return;

		add_filter( 'wp_ajax_camptix-attendance', array( $this, 'ajax_callback' ) );
		add_filter( 'wp_ajax_nopriv_camptix-attendance', array( $this, 'ajax_callback' ) );

		if ( ! empty( $_GET['camptix-attendance'] ) && $_GET['camptix-attendance'] == $this->secret ) {
			add_filter( 'template_include', array( $this, 'setup_attendance_ui' ) );
		}
	}

	/**
	 * Initialize the Attendance UI.
	 *
	 * Enqueue all necessary scripts and styles, pass any needed data
	 * via $camptix->tmp(). Note that previously enqueued scripts and
	 * styles will not be loaded.
	 */
	public function setup_attendance_ui( $template ) {
		global $camptix;

		wp_enqueue_script( 'jquery-fastbutton', plugins_url( '/assets/jquery.fastbutton.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_script( 'camptix-attendance-ui', plugins_url( '/assets/attendance-ui.js' , __FILE__ ), array( 'backbone', 'jquery', 'wp-util', 'jquery-fastbutton' ) );
		wp_enqueue_style( 'camptix-attendance-ui', plugins_url( '/assets/attendance-ui.css', __FILE__ ), array( 'dashicons' ) );

		$camptix->tmp( 'attendance_tickets', $this->get_tickets() );
		return dirname( __FILE__ ) . '/attendance-ui.php';
	}

	/**
	 * Callback/router for an AJAX Request.
	 *
	 * Routes to the appropriate callback method depending
	 * on the requested CampTix action. Also validates keys.
	 */
	public function ajax_callback() {
		if ( empty( $_REQUEST['camptix_secret'] ) || $_REQUEST['camptix_secret'] != $this->secret )
			return;

		$action = $_REQUEST['camptix_action'];
		if ( 'sync-model' == $action ) {
			return $this->_ajax_sync_model();
		} elseif ( 'sync-list' == $action ) {
			return $this->_ajax_sync_list();
		}
	}

	/**
	 * Synchronize a single attendee model.
	 *
	 * Sets are removes the attended flag for a given camptix_id.
	 */
	public function _ajax_sync_model() {
		if ( empty( $_REQUEST['camptix_id'] ) )
			return;

		$attendee_id = absint( $_REQUEST['camptix_id'] );
		$attendee = get_post( $attendee_id );

		if ( ! $attendee || 'tix_attendee' != $attendee->post_type || 'publish' != $attendee->post_status )
			return;

		if ( isset( $_REQUEST['camptix_set_attendance'] ) ) {
			if ( 'true' == $_REQUEST['camptix_set_attendance'] ) {
				$this->log( 'Marked attendee as attended.', $attendee->ID );
				update_post_meta( $attendee->ID, 'tix_attended', true );
			} else {
				$this->log( 'Marked attendee as did not attended.', $attendee->ID );
				delete_post_meta( $attendee->ID, 'tix_attended' );
			}
		}

		return wp_send_json_success( array( $this->_make_object( $attendee ) ) );
	}

	/**
	 * Synchronize an attendee list.
	 *
	 * Queries the database for attendees given a query and
	 * returns a batch back to Backbone.sync.
	 */
	public function _ajax_sync_list() {
		global $wpdb;

		$paged = 1;
		if ( ! empty( $_REQUEST['camptix_paged'] ) )
			$paged = absint( $_REQUEST['camptix_paged'] );

		$ticket_ids = wp_list_pluck( $this->get_tickets(), 'ID' );

		$query_args = array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'paged'          => $paged,
			'posts_per_page' => 50,
			'meta_query'     => '',
		);

		/**
		 * Sort Attendee Posts
		 */
		if ( ! empty( $_REQUEST['camptix_filters']['sort'] ) ) {
			switch ( $_REQUEST['camptix_filters']['sort'] ) {
				case 'lastName':
					$query_args['orderby']  = 'meta_value';
					$query_args['meta_key'] = 'tix_last_name';
					break;
				case 'orderDate':
					$query_args['orderby'] = 'date';
					$query_args['order']   = 'DESC';
					break;
				case 'firstName':
				default:
					// each $attendee->post_title is already First Lastname
					break;
			}

			unset( $_REQUEST['camptix_filters']['sort'] );
		}

		$filters = array();
		if ( ! empty( $_REQUEST['camptix_filters'] ) )
			$filters = (array) $_REQUEST['camptix_filters'];

		$filters = wp_parse_args( (array) $_REQUEST['camptix_filters'], array(
			'attendance' => 'none',
			'tickets' => array(),
		) );

		$filters['search'] = ! empty( $_REQUEST['camptix_search'] ) ? trim( $_REQUEST['camptix_search'] ) : '';

		// Filter by attendance.
		if ( in_array( $filters['attendance'], array( 'attending', 'not-attending' ) ) )
			$this->_filter_query_attendance( $filters['attendance'] );

		// Filter by ticket type.
		$filters['tickets'] = array_intersect( $filters['tickets'], $ticket_ids );
		if ( count( array_diff( $ticket_ids, $filters['tickets'] ) ) > 0 ) {

			// No tickets selected.
			if ( empty( $filters['tickets'] ) )
				return wp_send_json_success( array() );

			$this->_filter_query_tickets( $filters['tickets'] );
		}

		// Filter by search query.
		if ( ! empty( $filters['search'] ) )
			$this->_filter_query_search( $filters['search'] );

		$query_args['suppress_filters'] = false;
		$attendees = get_posts( $query_args );

		$output = array();
		foreach ( $attendees as $attendee ) {
			$output[] = $this->_make_object( $attendee );
		}

		return wp_send_json_success( $output );
	}

	/**
	 * Helper method to make an Attendee object.
	 *
	 * Use this helper to return only the necessary data back
	 * with an AJAX method.
	 */
	public function _make_object( $attendee ) {
		$attendee = get_post( $attendee );

		$first_name = get_post_meta( $attendee->ID, 'tix_first_name', true );
		$last_name  = get_post_meta( $attendee->ID, 'tix_last_name', true );
		$avatar_url = sprintf( 'https://secure.gravatar.com/avatar/%s?s=160', md5( get_post_meta( $attendee->ID, 'tix_email', true ) ) );
		$avatar_url = add_query_arg( 'd', 'https://secure.gravatar.com/avatar/ad516503a11cd5ca435acc9bb6523536?s=160', $avatar_url );

		$status = (bool) get_post_meta( $attendee->ID, 'tix_attended', true );

		$extras = array();

		// By default, allow certain questions to be included.
		$questions = get_post_meta( $attendee->ID, 'tix_questions', true );
		foreach ( $this->questions as $question_id ) {
			if ( ! isset( $questions[ $question_id ] ) ) {
				continue;
			}

			$question_post = get_post( $question_id );
			$extras[] = [
				html_entity_decode( apply_filters( 'the_title', $question_post->post_title ) ), // Escaped on display
				// The attendees selection, which may be an array.
				is_array( $questions[ $question_id ] ) ? implode( ', ', $questions[ $question_id ] ) : $questions[ $question_id ],
			];
		}

		/**
		 * Allow other plugins/Camptix Addons to register extra fields.
		 */
		$extras = apply_filters( 'camptix_attendance_ui_extras', $extras, $attendee );

		return array(
			'id'        => $attendee->ID,
			'firstName' => $first_name,
			'lastName'  => $last_name,
			'avatar'    => esc_url_raw( $avatar_url ),
			'status'    => $status,
			'extras'    => $extras,
		);
	}

	/**
	 * Filter the SQL in WP_Query for Search.
	 *
	 * Prior to 4.1 WordPress didn't have nested meta queries, so
	 * we're left with our own JOINs and WHEREs to look for a search
	 * query under various meta keys.
	 */
	public function _filter_query_search( $search ) {
		add_filter( 'posts_clauses', function( $clauses ) use ( $search ) {
			global $wpdb;

			$search = $wpdb->esc_like( wp_unslash( $search ) );

			$clauses['join'] .= "
				INNER JOIN $wpdb->postmeta tix_first_name ON ( ID = tix_first_name.post_id AND tix_first_name.meta_key = 'tix_first_name' )
				INNER JOIN $wpdb->postmeta tix_last_name ON ( ID = tix_last_name.post_id AND tix_last_name.meta_key = 'tix_last_name' )
			";

			$clauses['where'] .= $wpdb->prepare( "
				AND (
					tix_first_name.meta_value LIKE '%%%s%%' OR
					tix_last_name.meta_value LIKE '%%%s%%' OR
					CONCAT( tix_first_name.meta_value, ' ', tix_last_name.meta_value ) LIKE '%%%s%%'
				)
			", $search, $search, $search );

			return $clauses;
		} );
	}

	/**
	 * Filter WP_Query to include only specific tickets.
	 */
	public function _filter_query_tickets( $ticket_ids ) {
		add_filter( 'posts_clauses', function( $clauses ) use ( $ticket_ids ) {
			global $wpdb;

			$clauses['join'] .= " INNER JOIN $wpdb->postmeta tix_ticket_id ON ( ID = tix_ticket_id.post_id AND tix_ticket_id.meta_key = 'tix_ticket_id' ) ";
			$clauses['where'] .= sprintf( " AND ( tix_ticket_id.meta_value IN ( %s ) ) ", implode( ', ', array_map( 'absint', $ticket_ids ) ) );
			return $clauses;
		} );
	}

	/**
	 * Filter WP_Query to include only attending or non-attending attendees.
	 */
	public function _filter_query_attendance( $attendance ) {
		add_filter( 'posts_clauses', function( $clauses ) use ( $attendance ) {
			global $wpdb;

			$clauses['join'] .= " LEFT JOIN $wpdb->postmeta tix_attended ON ( ID = tix_attended.post_id AND tix_attended.meta_key = 'tix_attended' ) ";

			if ( 'attending' == $attendance )
				$clauses['where'] .=  " AND ( tix_attended.meta_value = 1 ) ";
			else
				$clauses['where'] .= " AND ( tix_attended.meta_value IS NULL ) ";

			return $clauses;
		} );
	}

	/**
	 * Add a new section to the Setup screen.
	 */
	public function setup_sections( $sections ) {
		$sections['attendance-ui'] = esc_html__( 'Attendance UI', 'wordcamporg' );

		return $sections;
	}

	/**
	 * Add some controls to our Setup section.
	 */
	public function setup_controls( $section ) {
		global $camptix;

		if ( 'attendance-ui' != $section )
			return;

		add_settings_section( 'general', esc_html__( 'Attendance UI', 'wordcamporg' ), array( $this, 'setup_controls_section' ), 'camptix_options' );

		// Fields
		$camptix->add_settings_field_helper( 'attendance-enabled', esc_html__( 'Enabled', 'wordcamporg' ), 'field_yesno', 'general',
			esc_html__( "Don't forget to disable the UI after the event is over.", 'wordcamporg' )
		);

		add_settings_field( 'attendance-questions', esc_html__( 'Questions', 'wordcamporg' ), array( $this, 'field_questions' ), 'camptix_options', 'general', esc_html__( 'Show these additional ticket questions in the UI.', 'wordcamporg' ) );

		add_settings_field( 'attendance-secret', esc_html__( 'Secret Link', 'wordcamporg' ), array( $this, 'field_secret' ), 'camptix_options', 'general' );
	}

	/**
	 * Secret Link Field
	 *
	 * This is a field that only shows the secret URL, and also has
	 * a "generate" checkbox that allows users to generate a new secret.
	 */
	public function field_secret() {
		$secret_url = ! empty( $this->secret ) ? add_query_arg( 'camptix-attendance', $this->secret, home_url() ) : '';
		?>
		<input type="hidden" name="camptix_options[attendance-secret]" value="1" />
		<textarea class="large-text" rows="4" readonly><?php echo esc_textarea( $secret_url ); ?></textarea>

		<input id="camptix-attendance-generate" type="checkbox" name="camptix_options[attendance-generate]" value="1" />
		<label for="camptix-attendance-generate"><?php esc_html_e( 'Generate a new secret link (old links will expire)', 'wordcamporg' ); ?></label>
		<?php
	}

	/**
	 * Ticket Questions Field
	 *
	 * This is a field that allows selection of any of the Ticket Questions specified
	 * to be output into the Attendance UI.
	 */
	public function field_questions() {
		global $camptix;
		$questions = $camptix->get_all_questions();

		echo '<p>' . esc_html__( 'Show the following ticket questions in the Attendance UI.', 'wordcamporg' ) . '</p>';

		foreach ( $questions as $question ) {
			$selections = get_post_meta( $question->ID, 'tix_values', true );
			printf(
				'<label><input type="checkbox" name="camptix_options[attendance-questions][]" value="%s" %s> %s %s</label><br>',
				esc_attr( $question->ID ),
				checked( in_array( $question->ID, $this->questions, true ), true, false ),
				esc_html( apply_filters( 'the_title', $question->post_title ) ),
				$selections ? '<em>' . esc_html( implode( ', ', $selections ) ) . '</em>' : ''
			);
		}

	}

	/**
	 * Setup section description.
	 */
	public function setup_controls_section() {
		?>
		<p><?php esc_html_e( 'The Attendance UI addon is useful for tracking attendance at the event. It allows registration volunteers to access a mobile-friendly UI during the event, and mark attendees as "attended" or "did not attend" as they register. The UI also offers live search and filters for your convenience.', 'wordcamporg' ); ?></p>

		<p><strong><?php esc_html_e( 'Note: Anyone with the secret link can access the attendance UI and change attendance data. Please keep this URL secret and change it if necessary.', 'wordcamporg' ); ?></strong></p>
		<?php
	}

	/**
	 * Runs whenever the CampTix option is updated.
	 */
	public function validate_options( $output, $input ) {
		if ( isset( $input['attendance-enabled'] ) )
			$output['attendance-enabled'] = (bool) $input['attendance-enabled'];

		if ( ! empty( $input['attendance-generate'] ) )
			$output['attendance-secret'] = wp_generate_password( 32, false, false );

		if ( ! empty( $input['attendance-questions'] ) ) {
			$output['attendance-questions'] = array_map( 'intval', $input['attendance-questions'] );
		} elseif ( isset( $input['attendance-enabled'] ) ) {
			$output['attendance-questions'] = array();
		}

		return $output;
	}

	/**
	 * Get CampTix Tickets (not to be confused with Attendees)
	 *
	 * Returns an array of published tickets registered with CampTix.
	 */
	public function get_tickets() {
		if ( isset( $this->tickets ) )
			return $this->tickets;

		$this->tickets = get_posts( array(
			'post_type' => 'tix_ticket',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		) );

		return $this->tickets;
	}

	/**
	 * Write a log entry to CampTix.
	 */
	public function log( $message, $post_id = 0, $data = null ) {
		global $camptix;
		$camptix->log( $message, $post_id, $data, 'attendance' );
	}

	/**
	 * Register self as a CampTix addon.
	 */
	public static function register_addon() {
		camptix_register_addon( __CLASS__ );
	}
}

CampTix_Attendance::register_addon();

<?php
/**
 * Allows event organizers to track which attendees showed up to the event.
 */
class CampTix_Attendance extends CampTix_Addon {
	public $secret           = '';
	public $secret_generated = '';
	public $questions        = array();
	public $secret_expiry    = '2 weeks';
	public $tickets;

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

		// Attendance file import under Tickets → Tools. Available regardless of
		// whether the front-end Attendance UI / secret link is enabled — importing
		// after the event mustn't require turning the live UI on.
		if ( current_user_can( $camptix->caps['manage_attendees'] ) ) {
			add_filter( 'camptix_menu_tools_tabs', array( $this, 'add_import_tools_tab' ) );
			add_action( 'camptix_menu_tools_attendance-import', array( $this, 'render_import_tools_tab' ) );
		}

		$camptix_options = $camptix->get_options();
		if ( empty( $camptix_options['attendance-secret'] ) )
			return;

		$this->secret = $camptix_options['attendance-secret'];
		$this->secret_generated = $camptix_options['attendance-secret-generated'] ?? '';

		if ( isset( $camptix_options['attendance-questions'] ) ) {
			$this->questions = $camptix_options['attendance-questions'];
		}

		if ( empty( $camptix_options['attendance-enabled'] ) )
			return;

		// If secret has expired, trun the UI off, reset link and do not allow setup for UI use.
		if ( strtotime( $this->secret_generated ) < strtotime( "-{$this->secret_expiry}" ) ) {
			$camptix_options['attendance-enabled'] = 0;
			$camptix_options['attendance-secret'] = '';
			$camptix_options['attendance-secret-generated'] = '';
			update_option( 'camptix_options', $camptix_options );
			return;
		}

		add_action( 'tix_scheduled_daily', array( $this, 'cron_stats_update_attended_count' ) );

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
		} elseif ( 'sync-bulk' == $action ) {
			return $this->_ajax_sync_bulk();
		}
	}

	/**
	 * Bulk-set attendance for every attendee matching the current filters.
	 *
	 * Unlike the per-tap flow, bulk writes are gated to logged-in users with the
	 * manage_attendees capability by default (the secret link alone shouldn't be
	 * able to rewrite the whole event). Disable via the filter at your own risk.
	 */
	public function _ajax_sync_bulk() {
		global $camptix;

		if ( apply_filters( 'camptix_attendance_bulk_require_cap', true )
			&& ! current_user_can( $camptix->caps['manage_attendees'] )
		) {
			return wp_send_json_error( array( 'error' => 'not_allowed' ) );
		}

		// CSRF guard: the capability alone isn't enough — auth cookies ride along
		// on cross-site top-level GETs (SameSite=Lax), so a logged-in organizer
		// clicking a crafted link could otherwise trigger a mass write.
		if ( ! wp_verify_nonce( $_REQUEST['camptix_bulk_nonce'] ?? '', 'camptix-attendance-bulk' ) ) {
			return wp_send_json_error( array( 'error' => 'bad_nonce' ) );
		}

		$filters   = isset( $_REQUEST['camptix_filters'] ) ? (array) $_REQUEST['camptix_filters'] : array();
		$search    = isset( $_REQUEST['camptix_search'] ) ? trim( $_REQUEST['camptix_search'] ) : '';
		$attending = ! empty( $_REQUEST['camptix_set_attendance'] ) && 'true' == $_REQUEST['camptix_set_attendance'];
		$dry_run   = ! empty( $_REQUEST['camptix_dry_run'] );

		// Two-phase guard: the count the user confirmed must still match the live
		// set, so a filter change or new registrations between the preview and the
		// confirmation can't silently widen the write.
		$ids = $this->query_attendee_ids( $filters, $search );

		if ( ! $dry_run && isset( $_REQUEST['camptix_expected_count'] )
			&& absint( $_REQUEST['camptix_expected_count'] ) !== count( $ids )
		) {
			return wp_send_json_error( array(
				'error'  => 'count_mismatch',
				'actual' => count( $ids ),
			) );
		}

		return wp_send_json_success( $this->bulk_set_attendance( $filters, $search, $attending, $dry_run, $ids ) );
	}

	/**
	 * Set or unset attendance for every attendee matching the filters.
	 *
	 * @param array      $filters   Filter settings (attendance, tickets), as sent by the UI.
	 * @param string     $search    Search keyword.
	 * @param bool       $attending True to mark attended, false to unmark.
	 * @param bool       $dry_run   If true, only count the matching set.
	 * @param int[]|null $ids       Precomputed attendee IDs to act on; null queries
	 *                              the filters. Pass the IDs the count guard checked
	 *                              so the confirmed write can't act on a wider set.
	 *
	 * @return array { matched, changed, attending, dry_run }
	 */
	public function bulk_set_attendance( $filters, $search, $attending, $dry_run = false, $ids = null ) {
		if ( null === $ids ) {
			$ids = $this->query_attendee_ids( $filters, $search );
		}

		$summary = array(
			'matched'   => count( $ids ),
			'changed'   => 0,
			'attending' => (bool) $attending,
			'dry_run'   => (bool) $dry_run,
		);

		if ( $dry_run ) {
			return $summary;
		}

		$summary['changed'] = $this->set_attendance_for_ids( $ids, $attending, 'bulk' );

		return $summary;
	}

	/**
	 * The shared write core: set or unset attendance for a list of attendee IDs.
	 *
	 * Only writes when the state actually changes, keeps the running stat in
	 * sync, and logs each change for the audit trail.
	 *
	 * @param int[]  $ids       Attendee post IDs.
	 * @param bool   $attending True to mark attended, false to unmark.
	 * @param string $source    Short label for the log entries ('bulk', 'import').
	 *
	 * @return int Number of attendees actually changed.
	 */
	public function set_attendance_for_ids( array $ids, $attending, $source = 'bulk' ) {
		global $camptix;

		$changed = 0;
		$source  = sanitize_key( $source );

		// Mass actions are organizer actions with a real session — record the
		// actor so a wrong-direction bulk/import is attributable in the log.
		$actor   = wp_get_current_user();
		$context = $actor->exists() ? "$source by {$actor->user_login}" : $source;

		foreach ( $ids as $attendee_id ) {
			$attendee_id  = absint( $attendee_id );
			$is_attending = (bool) get_post_meta( $attendee_id, 'tix_attended', true );

			if ( $is_attending === (bool) $attending ) {
				continue;
			}

			if ( $attending ) {
				update_post_meta( $attendee_id, 'tix_attended', true );
				$camptix->increment_stats( 'attended', 1 );
				$this->log( "Marked attendee as attended ($context).", $attendee_id );
			} else {
				delete_post_meta( $attendee_id, 'tix_attended' );
				$camptix->increment_stats( 'attended', -1 );
				$this->log( "Marked attendee as did not attend ($context).", $attendee_id );
			}

			$changed++;
		}

		return $changed;
	}

	/**
	 * Add the Attendance Import tab to Tickets → Tools.
	 *
	 * @param array $sections
	 *
	 * @return array
	 */
	public function add_import_tools_tab( $sections ) {
		$sections['attendance-import'] = __( 'Attendance Import', 'wordcamporg' );

		return $sections;
	}

	/**
	 * Render (and handle) the Attendance Import tools tab.
	 *
	 * Three states: the upload form; the preview of a parsed file (stored in a
	 * short-lived user-keyed transient, so the file itself is read once and
	 * discarded); and the result summary after applying.
	 */
	public function render_import_tools_tab() {
		global $camptix;

		if ( ! current_user_can( $camptix->caps['manage_attendees'] ) ) {
			return;
		}

		$transient_key = 'tix_attendance_import_' . get_current_user_id();

		// Step 3: apply a previously previewed plan.
		if ( isset( $_POST['tix_attendance_import_apply'] )
			&& wp_verify_nonce( $_POST['tix_attendance_import_nonce'] ?? '', 'tix-attendance-import-apply' )
		) {
			$plan = get_transient( $transient_key );
			delete_transient( $transient_key );

			if ( ! is_array( $plan ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The preview expired. Please upload the file again.', 'wordcamporg' ) . '</p></div>';
			} elseif ( ! hash_equals( $plan['token'], (string) ( $_POST['tix_attendance_import_token'] ?? '' ) ) ) {
				// A newer upload replaced this preview (e.g. another tab) — don't
				// apply a plan the user isn't looking at.
				echo '<div class="notice notice-error"><p>' . esc_html__( 'This preview was replaced by a newer upload. Please review the latest preview and apply again.', 'wordcamporg' ) . '</p></div>';
			} else {
				$marked   = $this->set_attendance_for_ids( $plan['set'], true, 'import' );
				$unmarked = $this->set_attendance_for_ids( $plan['unset'], false, 'import' );

				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html( sprintf(
						__( 'Import applied: %1$d marked attended, %2$d marked did-not-attend, %3$d already correct.', 'wordcamporg' ),
						$marked,
						$unmarked,
						count( $plan['set'] ) + count( $plan['unset'] ) - $marked - $unmarked
					) )
				);
			}

			$this->render_import_form();

			return;
		}

		// Step 2: parse an uploaded file and preview the plan.
		if ( isset( $_FILES['tix_attendance_import_file'] )
			&& wp_verify_nonce( $_POST['tix_attendance_import_nonce'] ?? '', 'tix-attendance-import-upload' )
		) {
			$file = $_FILES['tix_attendance_import_file'];

			if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The upload failed. Please try again.', 'wordcamporg' ) . '</p></div>';
				$this->render_import_form();

				return;
			}

			$rows = $this->parse_attendance_csv( $file['tmp_name'] );

			if ( is_wp_error( $rows ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $rows->get_error_message() ) . '</p></div>';
				$this->render_import_form();

				return;
			}

			$plan = $this->resolve_attendance_rows( $rows );

			set_transient( $transient_key, $plan, 15 * MINUTE_IN_SECONDS );

			$this->render_import_preview( $plan );

			return;
		}

		// Step 1: the upload form.
		$this->render_import_form();
	}

	/**
	 * The upload form for the import tab.
	 */
	protected function render_import_form() {
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'tix_section', 'attendance-import' ) ); ?>">
			<?php wp_nonce_field( 'tix-attendance-import-upload', 'tix_attendance_import_nonce' ); ?>

			<p><?php esc_html_e( 'Upload a CSV of attendees to set their attendance in bulk — e.g. a badge-scanner export, a sign-in sheet, or an edited copy of the attendee Export.', 'wordcamporg' ); ?></p>

			<ul style="list-style: disc; margin-left: 2em;">
				<li><?php esc_html_e( 'The file needs a header row with an "id" or "email" column (or both — id wins).', 'wordcamporg' ); ?></li>
				<li><?php esc_html_e( 'An optional "attended" column (yes/no) sets the direction per row; without it, every row is marked attended.', 'wordcamporg' ); ?></li>
				<li><?php esc_html_e( 'Nothing is written until you confirm a preview of the changes.', 'wordcamporg' ); ?></li>
			</ul>

			<p>
				<input type="file" name="tix_attendance_import_file" accept=".csv,text/csv" required />
			</p>

			<?php submit_button( __( 'Preview Import', 'wordcamporg' ), 'primary', 'tix_attendance_import_preview' ); ?>
		</form>
		<?php
	}

	/**
	 * The preview screen: what the uploaded file will change.
	 *
	 * @param array $plan The resolved plan from resolve_attendance_rows().
	 */
	protected function render_import_preview( $plan ) {
		?>
		<h3><?php esc_html_e( 'Import Preview', 'wordcamporg' ); ?></h3>

		<table class="widefat striped" style="max-width: 500px;">
			<tbody>
			<tr>
				<td><?php esc_html_e( 'Rows in file', 'wordcamporg' ); ?></td>
				<td><?php echo absint( $plan['total_rows'] ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Will be marked attended', 'wordcamporg' ); ?></td>
				<td><?php echo absint( count( $plan['set'] ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Will be marked did-not-attend', 'wordcamporg' ); ?></td>
				<td><?php echo absint( count( $plan['unset'] ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Unmatched rows (will be skipped)', 'wordcamporg' ); ?></td>
				<td><?php echo absint( count( $plan['unmatched'] ) ); ?></td>
			</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $plan['unmatched'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Unmatched rows:', 'wordcamporg' ); ?></strong></p>
			<ul style="list-style: disc; margin-left: 2em;">
				<?php foreach ( array_slice( $plan['unmatched'], 0, 20 ) as $unmatched ) : ?>
					<li><code><?php echo esc_html( $unmatched ); ?></code></li>
				<?php endforeach; ?>
				<?php if ( count( $plan['unmatched'] ) > 20 ) : ?>
					<li><?php echo esc_html( sprintf( __( '… and %d more.', 'wordcamporg' ), count( $plan['unmatched'] ) - 20 ) ); ?></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_section', 'attendance-import' ) ); ?>">
			<?php wp_nonce_field( 'tix-attendance-import-apply', 'tix_attendance_import_nonce' ); ?>
			<input type="hidden" name="tix_attendance_import_token" value="<?php echo esc_attr( $plan['token'] ); ?>" />
			<?php submit_button( __( 'Apply Import', 'wordcamporg' ), 'primary', 'tix_attendance_import_apply', false ); ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'tix_section', 'attendance-import' ) ); ?>"><?php esc_html_e( 'Cancel', 'wordcamporg' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Parse an attendance CSV into normalized rows.
	 *
	 * Strict column allowlist: only id, email, and attended are ever read.
	 *
	 * @param string $file_path Path to the uploaded CSV.
	 *
	 * @return array|WP_Error List of rows: { id: int|0, email: string, attended: bool }.
	 */
	public function parse_attendance_csv( $file_path ) {
		$handle = fopen( $file_path, 'r' );

		if ( ! $handle ) {
			return new WP_Error( 'unreadable', __( 'Could not read the uploaded file.', 'wordcamporg' ) );
		}

		// Explicit $escape: '' is the future PHP default and the correct CSV behavior.
		$header = fgetcsv( $handle, null, ',', '"', '' );

		if ( ! is_array( $header ) ) {
			fclose( $handle );

			return new WP_Error( 'empty', __( 'The file appears to be empty.', 'wordcamporg' ) );
		}

		// Normalize headers; strip a possible UTF-8 BOM off the first cell.
		$normalize = function ( $cell ) {
			return strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $cell ) ) );
		};
		$header    = array_map( $normalize, $header );
		$id_col    = array_search( 'id', $header, true );
		if ( false === $id_col ) {
			$id_col = array_search( 'attendee id', $header, true );
		}
		$email_col = array_search( 'email', $header, true );
		$att_col   = array_search( 'attended', $header, true );

		if ( false === $id_col && false === $email_col ) {
			fclose( $handle );

			return new WP_Error( 'no_key_column', __( 'The file needs an "id" or "email" column in its header row.', 'wordcamporg' ) );
		}

		$rows     = array();
		$max_rows = apply_filters( 'camptix_attendance_import_max_rows', 5000 );

		while ( false !== ( $line = fgetcsv( $handle, null, ',', '"', '' ) ) ) {
			if ( array( null ) === $line ) {
				continue; // Blank line.
			}

			if ( count( $rows ) >= $max_rows ) {
				fclose( $handle );

				return new WP_Error(
					'too_many_rows',
					sprintf( __( 'The file has more than %d rows. Please split it into smaller files.', 'wordcamporg' ), $max_rows )
				);
			}

			$attended = true;

			if ( false !== $att_col ) {
				$raw      = strtolower( trim( (string) ( $line[ $att_col ] ?? '' ) ) );
				$attended = in_array( $raw, array( 'yes', 'y', '1', 'true' ), true );
			}

			$rows[] = array(
				'id'       => false !== $id_col ? absint( $line[ $id_col ] ?? 0 ) : 0,
				'email'    => false !== $email_col ? strtolower( trim( (string) ( $line[ $email_col ] ?? '' ) ) ) : '',
				'attended' => $attended,
			);
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Resolve parsed rows to attendee IDs.
	 *
	 * A row's id column wins when it points at a real published attendee;
	 * otherwise the email is matched against tix_email (all matches count —
	 * duplicate emails each get the row's attendance). Rows matching nothing
	 * are reported, never silently dropped.
	 *
	 * @param array $rows Rows from parse_attendance_csv().
	 *
	 * @return array { set: int[], unset: int[], unmatched: string[], total_rows: int }
	 */
	public function resolve_attendance_rows( array $rows ) {
		$plan = array(
			'set'        => array(),
			'unset'      => array(),
			'unmatched'  => array(),
			'total_rows' => count( $rows ),
			// One-time token binding this exact plan to its preview's Apply button.
			'token'      => wp_generate_password( 20, false, false ),
		);

		foreach ( $rows as $row ) {
			$matched = array();

			if ( $row['id'] ) {
				$post = get_post( $row['id'] );

				if ( $post && 'tix_attendee' === $post->post_type && 'publish' === $post->post_status ) {
					$matched[] = $post->ID;
				}
			}

			if ( ! $matched && '' !== $row['email'] && is_email( $row['email'] ) ) {
				$matched = get_posts( array(
					'post_type'      => 'tix_attendee',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => 'tix_email',
					'meta_value'     => $row['email'],
				) );
			}

			if ( ! $matched ) {
				$plan['unmatched'][] = $row['id'] ? '#' . $row['id'] : $row['email'];

				continue;
			}

			foreach ( $matched as $attendee_id ) {
				if ( $row['attended'] ) {
					$plan['set'][] = (int) $attendee_id;
				} else {
					$plan['unset'][] = (int) $attendee_id;
				}
			}
		}

		$plan['set']   = array_values( array_unique( $plan['set'] ) );
		$plan['unset'] = array_values( array_unique( array_diff( $plan['unset'], $plan['set'] ) ) );

		return $plan;
	}

	/**
	 * Get the IDs of all published attendees matching the filters — the same
	 * matching semantics as the list the volunteer is looking at (_ajax_sync_list),
	 * without pagination.
	 *
	 * @param array  $filters Filter settings (attendance, tickets).
	 * @param string $search  Search keyword.
	 *
	 * @return int[]
	 */
	public function query_attendee_ids( $filters, $search = '' ) {
		$attached = $this->attach_list_filters( $filters, $search );

		if ( false === $attached ) {
			return array();
		}

		$attendee_ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		) );

		$this->detach_list_filters( $attached );

		return array_map( 'absint', $attendee_ids );
	}

	/**
	 * Attach the attendee-list filter clauses for this request.
	 *
	 * The single source of the matching semantics shared by the on-screen list
	 * (_ajax_sync_list) and the bulk actions (query_attendee_ids) — a divergence
	 * between the two would mean bulk-writing attendees the volunteer can't see.
	 *
	 * @param array  $filters Filter settings (attendance, tickets).
	 * @param string $search  Search keyword.
	 *
	 * @return array|false Attached posts_clauses callbacks, or false when the
	 *                     filters cannot match anything (no tickets selected).
	 */
	protected function attach_list_filters( $filters, $search = '' ) {
		$filters = wp_parse_args(
			(array) $filters,
			array(
				'attendance' => 'none',
				'tickets'    => array(),
			)
		);

		$attached = array();

		if ( in_array( $filters['attendance'], array( 'attending', 'not-attending' ), true ) ) {
			$attached[] = $this->_filter_query_attendance( $filters['attendance'] );
		}

		$ticket_ids         = wp_list_pluck( $this->get_tickets(), 'ID' );
		$filters['tickets'] = array_intersect( (array) $filters['tickets'], $ticket_ids );

		if ( count( array_diff( $ticket_ids, $filters['tickets'] ) ) > 0 ) {
			if ( empty( $filters['tickets'] ) ) {
				$this->detach_list_filters( $attached );

				return false;
			}

			$attached[] = $this->_filter_query_tickets( $filters['tickets'] );
		}

		$search = trim( (string) $search );

		if ( ! empty( $search ) ) {
			$attached[] = $this->_filter_query_search( $search );
		}

		return $attached;
	}

	/**
	 * Detach filter clauses attached by attach_list_filters().
	 *
	 * The legacy _filter_query_* methods leak their closures for the rest of the
	 * request; anything running a second query must detach or get silently narrowed.
	 *
	 * @param array $attached Callbacks returned by attach_list_filters().
	 */
	protected function detach_list_filters( array $attached ) {
		foreach ( $attached as $callback ) {
			remove_filter( 'posts_clauses', $callback );
		}
	}

	/**
	 * Synchronize a single attendee model.
	 *
	 * Sets or removes the attended flag for a given camptix_id.
	 */
	public function _ajax_sync_model() {
		global $camptix;
		if ( empty( $_REQUEST['camptix_id'] ) )
			return;

		$attendee_id = absint( $_REQUEST['camptix_id'] );
		$attendee = get_post( $attendee_id );

		if ( ! $attendee || 'tix_attendee' != $attendee->post_type || 'publish' != $attendee->post_status )
			return;

		if ( isset( $_REQUEST['camptix_set_attendance'] ) ) {
			if ( 'true' == $_REQUEST['camptix_set_attendance'] ) {
				$camptix->increment_stats( 'attended', 1 );
				$this->log( 'Marked attendee as attended.', $attendee->ID );
				update_post_meta( $attendee->ID, 'tix_attended', true );
			} else {
				$camptix->increment_stats( 'attended', -1 );
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
		$paged = 1;
		if ( ! empty( $_REQUEST['camptix_paged'] ) )
			$paged = absint( $_REQUEST['camptix_paged'] );

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

		$attached = $this->attach_list_filters( $filters, $filters['search'] );

		if ( false === $attached ) {
			// No tickets selected — same "match nothing" rule the bulk query uses.
			return wp_send_json_success( array() );
		}

		$query_args['suppress_filters'] = false;
		$attendees                      = get_posts( $query_args );

		$this->detach_list_filters( $attached );

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
		$callback = function ( $clauses ) use ( $search ) {
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
		};

		add_filter( 'posts_clauses', $callback );

		return $callback;
	}

	/**
	 * Filter WP_Query to include only specific tickets.
	 */
	public function _filter_query_tickets( $ticket_ids ) {
		$callback = function ( $clauses ) use ( $ticket_ids ) {
			global $wpdb;

			$clauses['join'] .= " INNER JOIN $wpdb->postmeta tix_ticket_id ON ( ID = tix_ticket_id.post_id AND tix_ticket_id.meta_key = 'tix_ticket_id' ) ";
			$clauses['where'] .= sprintf( " AND ( tix_ticket_id.meta_value IN ( %s ) ) ", implode( ', ', array_map( 'absint', $ticket_ids ) ) );
			return $clauses;
		};

		add_filter( 'posts_clauses', $callback );

		return $callback;
	}

	/**
	 * Filter WP_Query to include only attending or non-attending attendees.
	 */
	public function _filter_query_attendance( $attendance ) {
		$callback = function ( $clauses ) use ( $attendance ) {
			global $wpdb;

			$clauses['join'] .= " LEFT JOIN $wpdb->postmeta tix_attended ON ( ID = tix_attended.post_id AND tix_attended.meta_key = 'tix_attended' ) ";

			if ( 'attending' == $attendance )
				$clauses['where'] .=  " AND ( tix_attended.meta_value = 1 ) ";
			else
				$clauses['where'] .= " AND ( tix_attended.meta_value IS NULL ) ";

			return $clauses;
		};

		add_filter( 'posts_clauses', $callback );

		return $callback;
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
		$camptix->add_settings_field_helper( 'attendance-enabled', esc_html__( 'Enabled', 'wordcamporg' ), 'field_yesno', 'general' );

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
		<p class="description">
			<?php if ( empty( $this->secret_generated ) ) {
				echo esc_html( sprintf( __( 'Link will expire automatically after two weeks from generating it.', 'wordcamporg' ), $this->secret_expiry ) );
			} else {
				echo esc_html( sprintf( __( 'Link will expire automatically on %s.', 'wordcamporg' ), wp_date( 'Y-m-d H:i:s', strtotime( "+{$this->secret_expiry}", strtotime( $this->secret_generated ) ) ) ) );
			} ?>
		</p>
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

		if ( ! empty( $input['attendance-generate'] ) ) {
			$output['attendance-secret'] = wp_generate_password( 32, false, false );
			$output['attendance-secret-generated'] = wp_date( 'Y-m-d H:i:s' );
		}

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
	 * Get attended count.
	 *
	 * @return int Number of attendees marked as attended.
	 */
	public function get_attended_count() {
		$attended_query = new WP_Query( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'meta_key'       => 'tix_attended',
			'meta_value'     => '1',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return $attended_query->found_posts;
	}

	/**
	 * Cron job to update attended count in stats.
	 */
	public function cron_stats_update_attended_count() {
		global $camptix;

		$attended_count = $this->get_attended_count();
		if ( ! $attended_count ) {
			$attended_count = 0;
		}

		$camptix->update_stats( array(
			'attended' => $attended_count,
		) );
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

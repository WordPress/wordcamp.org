<?php
/**
 * CampTix QR Check-In.
 *
 * Lets organizers email attendees a personal QR code (on demand, never at purchase) and
 * check those attendees in at the event by scanning the code. Check-in reuses the existing
 * `tix_attended` meta, so the Summarize tool, attendee export and "Attended" column keep
 * working unchanged.
 *
 * Surfaces (all capability-gated):
 *   - A signed, public QR-image endpoint  ( ?tix_action=qr_code )
 *   - A logged-in live-camera scanner page ( ?tix_action=qr_checkin_scanner )
 *   - A logged-in check-in confirmation page used as a generic-camera fallback ( ?tix_action=checkin )
 *   - A per-attendee "Send QR code" admin action and a bulk "Send QR Codes" Tools tab
 *
 * Security: the QR encodes a check-in URL carrying an HMAC-SHA256 token bound to the attendee
 * ID and the current site (blog) ID, verified with hash_equals(). Nothing is stored per token;
 * it is recomputed on demand. A scan fails for a forged/garbled token, a missing attendee, or a
 * ticket whose status is anything other than `publish` (cancelled, refunded, failed, timeout,
 * pending). Re-scanning an attendee who is already checked in is a soft warning, not an error.
 */

defined( 'WPINC' ) || die();

class CampTix_QR_Check_In extends CampTix_Addon {
	/**
	 * The `tix_action` values this addon answers on the front end.
	 */
	const ACTION_QR_IMAGE = 'qr_code';
	const ACTION_CHECKIN  = 'checkin';
	const ACTION_SCANNER  = 'qr_checkin_scanner';

	/**
	 * Meta keys. `tix_attended` is shared with the core track-attendance addon; the other two are
	 * a check-in audit trail owned by this addon.
	 */
	const META_ATTENDED = 'tix_attended';
	const META_TIME     = 'tix_checked_in_time';
	const META_BY       = 'tix_checked_in_by';

	/**
	 * Stand-alone option holding the per-site HMAC signing secret. Kept out of `camptix_options`
	 * so that saving the Setup screen can never overwrite it (which would invalidate issued codes).
	 */
	const SECRET_OPTION = 'camptix_qr_checkin_secret';

	/**
	 * Nonce action for the scanner check-in AJAX request.
	 */
	const NONCE_CHECKIN = 'tix_qr_checkin';

	/**
	 * Result of the most recent check-in handled for the confirmation page template.
	 *
	 * @var array
	 */
	public $checkin_result = array();

	/**
	 * Register hooks. Runs during `camptix_init`.
	 */
	public function camptix_init() {
		global $camptix;

		// Public front-end endpoints. Priority 5 so the QR image and scanner page are handled
		// before canonical-redirect / CampTix's own template_redirect (both at 10).
		add_action( 'template_redirect', array( $this, 'maybe_handle_public_endpoints' ), 5 );

		// Notify shortcodes for the bulk email body and the single-send email.
		add_action( 'camptix_init_notify_shortcodes', array( $this, 'register_notify_shortcodes' ) );

		// Scanner check-in write (logged-in only; not registered for nopriv on purpose).
		add_action( 'wp_ajax_tix_qr_checkin', array( $this, 'ajax_checkin' ) );

		// Admin-bar shortcut to the scanner for organizers (only when the scanner is enabled).
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );

		// Per-attendee "Send QR code": list-table column + edit-screen button + AJAX.
		add_filter( 'manage_tix_attendee_posts_columns', array( $this, 'add_qr_column' ) );
		add_action( 'manage_tix_attendee_posts_custom_column', array( $this, 'render_qr_column' ), 10, 2 );
		add_action( 'camptix_attendee_submitdiv_misc', array( $this, 'render_send_qr_button' ) );
		add_action( 'wp_ajax_tix_send_qr_email', array( $this, 'ajax_send_qr_email' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Bulk "Send QR Codes" tool.
		add_filter( 'camptix_menu_tools_tabs', array( $this, 'add_tools_tab' ) );
		add_action( 'camptix_menu_tools_qr_send', array( $this, 'render_tools_tab' ) );

		// Settings (Setup screen) - only for users who can manage options.
		if ( current_user_can( $camptix->caps['manage_options'] ) ) {
			add_filter( 'camptix_setup_sections', array( $this, 'setup_sections' ) );
			add_action( 'camptix_menu_setup_controls', array( $this, 'setup_controls' ), 10, 1 );
			add_filter( 'camptix_validate_options', array( $this, 'validate_options' ), 10, 2 );
		}

		// Privacy: register the check-in audit meta with the exporter and eraser.
		add_filter( 'camptix_privacy_attendee_props_to_export', array( $this, 'privacy_export_props' ) );
		add_filter( 'camptix_privacy_export_attendee_prop', array( $this, 'privacy_export_value' ), 10, 4 );
		add_filter( 'camptix_privacy_attendee_props_to_erase', array( $this, 'privacy_erase_props' ) );
		add_action( 'camptix_privacy_erase_attendee_prop', array( $this, 'privacy_erase_value' ), 10, 3 );
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Token codec (stateless HMAC).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Fetch (or lazily create) the per-site signing secret.
	 *
	 * @return string
	 */
	protected function get_secret() {
		$secret = get_option( self::SECRET_OPTION );

		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * URL-safe base64 encode, without padding.
	 *
	 * @param string $bin Raw bytes.
	 * @return string
	 */
	protected function base64url_encode( $bin ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a binary HMAC signature for a URL, not obfuscating code.
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	protected function base64url_decode( $value ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a URL-safe token component, not obfuscating code.
		return (string) base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Build a signed token for an attendee.
	 *
	 * Format: base64url( attendee_id ) . "." . base64url( HMAC-SHA256( blog_id|attendee_id ) ).
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	public function sign_token( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		$message     = get_current_blog_id() . '|' . $attendee_id;
		$signature   = hash_hmac( 'sha256', $message, $this->get_secret(), true );

		return $this->base64url_encode( (string) $attendee_id ) . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Verify a signed token and return the attendee ID it authenticates.
	 *
	 * @param string $token The token from the request.
	 * @return int Attendee ID on success, 0 on failure.
	 */
	public function verify_token( $token ) {
		if ( ! is_string( $token ) || ! str_contains( $token, '.' ) ) {
			return 0;
		}

		list( $payload, $signature ) = explode( '.', $token, 2 );

		$attendee_id = absint( $this->base64url_decode( $payload ) );
		if ( $attendee_id < 1 ) {
			return 0;
		}

		$message  = get_current_blog_id() . '|' . $attendee_id;
		$expected = hash_hmac( 'sha256', $message, $this->get_secret(), true );
		$provided = $this->base64url_decode( $signature );

		if ( strlen( $provided ) !== strlen( $expected ) || ! hash_equals( $expected, $provided ) ) {
			return 0;
		}

		return $attendee_id;
	}

	/*
	 * -------------------------------------------------------------------------
	 *  URLs.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Public URL of the signed QR image for an attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	public function get_qr_image_url( $attendee_id ) {
		return add_query_arg(
			array(
				'tix_action'      => self::ACTION_QR_IMAGE,
				'tix_attendee_id' => absint( $attendee_id ),
				'tix_qr_token'    => $this->sign_token( $attendee_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Check-in URL encoded inside the QR (so a generic phone camera opens the confirmation page).
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	public function get_checkin_url( $attendee_id ) {
		return add_query_arg(
			array(
				'tix_action'      => self::ACTION_CHECKIN,
				'tix_attendee_id' => absint( $attendee_id ),
				'tix_qr_token'    => $this->sign_token( $attendee_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * URL of the live-camera scanner page.
	 *
	 * @return string
	 */
	public function get_scanner_url() {
		return add_query_arg( 'tix_action', self::ACTION_SCANNER, home_url( '/' ) );
	}

	/**
	 * Add a "Check-In Scanner" shortcut to the admin bar for organizers.
	 *
	 * Shown only when the scanner is enabled and the current user can manage attendees, so the
	 * link is one tap away on a phone the organizer is already logged in on.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_admin_bar_link( $wp_admin_bar ) {
		global $camptix;

		if ( ! is_object( $camptix ) || empty( $camptix->caps['manage_attendees'] ) ) {
			return;
		}

		if ( ! current_user_can( $camptix->caps['manage_attendees'] ) ) {
			return;
		}

		$options = $camptix->get_options();
		if ( empty( $options['qr-checkin-scanner-enabled'] ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'tix-qr-scanner',
				'title' => esc_html__( 'Check-In Scanner', 'wordcamporg' ),
				'href'  => esc_url( $this->get_scanner_url() ),
				'meta'  => array(
					'title' => esc_attr__( 'Open the live-camera attendee check-in scanner', 'wordcamporg' ),
				),
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Front-end endpoint router.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Dispatch our `tix_action` endpoints from `template_redirect`.
	 */
	public function maybe_handle_public_endpoints() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; endpoints are token- or capability-authenticated below.
		$action = isset( $_GET['tix_action'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_action'] ) ) : '';

		switch ( $action ) {
			case self::ACTION_QR_IMAGE:
				$this->stream_qr_image();
				break;

			case self::ACTION_CHECKIN:
				$this->handle_checkin_page();
				break;

			case self::ACTION_SCANNER:
				$this->handle_scanner_page();
				break;
		}
	}

	/**
	 * Read and validate the attendee ID + token from the current request.
	 *
	 * @return int Verified attendee ID, or 0 if the signature does not match.
	 */
	protected function verified_attendee_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-authenticated endpoint, not a form submission.
		$attendee_id = isset( $_GET['tix_attendee_id'] ) ? absint( $_GET['tix_attendee_id'] ) : 0;
		$token       = isset( $_GET['tix_qr_token'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_qr_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $attendee_id || $this->verify_token( $token ) !== $attendee_id ) {
			return 0;
		}

		return $attendee_id;
	}

	/**
	 * Stream the QR image (PNG, or SVG where GD is unavailable) for a validly-signed attendee.
	 */
	protected function stream_qr_image() {
		$attendee_id = $this->verified_attendee_from_request();

		if ( ! $attendee_id ) {
			status_header( 403 );
			exit;
		}

		$attendee = get_post( $attendee_id );

		// A cancelled/refunded ticket should stop producing a working QR image.
		if ( ! $this->is_attendee( $attendee ) || 'publish' !== $attendee->post_status ) {
			status_header( 404 );
			exit;
		}

		$mime  = '';
		$image = $this->render_qr_bytes( $this->get_checkin_url( $attendee_id ), $mime );

		if ( false === $image ) {
			status_header( 500 );
			exit;
		}

		$etag = '"' . md5( $mime . '|' . $image ) . '"';

		header( 'Content-Type: ' . $mime );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'ETag: ' . $etag );

		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
			status_header( 304 );
			exit;
		}

		header( 'Content-Length: ' . strlen( $image ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image payload.
		echo $image;
		exit;
	}

	/**
	 * Render QR code bytes for the given content.
	 *
	 * Uses chillerlan/php-qrcode (GD PNG, or SVG markup when GD is missing). Returns false when the
	 * library is unavailable or rendering fails, so callers can fall back gracefully.
	 *
	 * @param string $content The data to encode.
	 * @param string $mime    Set by reference to the produced MIME type.
	 * @return string|false
	 */
	protected function render_qr_bytes( $content, &$mime ) {
		if ( ! class_exists( '\chillerlan\QRCode\QRCode' ) ) {
			$this->log( 'QR code library is not installed; cannot render QR image.' );
			return false;
		}

		$use_png = function_exists( 'imagepng' );
		$mime    = $use_png ? 'image/png' : 'image/svg+xml';

		try {
			$options = new \chillerlan\QRCode\QROptions(
				array(
					'outputType'   => $use_png
						? \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG
						: \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
					'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
					'scale'        => 8,
					'outputBase64' => false,
				)
			);

			return ( new \chillerlan\QRCode\QRCode( $options ) )->render( $content );
		} catch ( \Throwable $e ) {
			$this->log( 'QR code rendering failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Render the logged-in, capability-gated live-camera scanner page.
	 */
	protected function handle_scanner_page() {
		global $camptix;

		// Anonymous visitors are sent to log in; auth_redirect() exits for them.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		// auth_redirect() does NOT stop a logged-in user, so the capability gate must terminate
		// explicitly or the organizer-only scanner (and its action nonce) would render for any
		// authenticated user.
		if ( ! current_user_can( $camptix->caps['manage_attendees'] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access the check-in scanner.', 'wordcamporg' ),
				'',
				array( 'response' => 403 )
			);
		}

		$options = $camptix->get_options();
		if ( empty( $options['qr-checkin-scanner-enabled'] ) ) {
			wp_die( esc_html__( 'The QR check-in scanner is not enabled for this event.', 'wordcamporg' ) );
		}

		add_filter( 'template_include', array( $this, 'render_scanner_template' ) );
	}

	/**
	 * Enqueue scanner assets and return the scanner template.
	 *
	 * @param string $template Original template path.
	 * @return string
	 */
	public function render_scanner_template( $template ) {
		wp_enqueue_script(
			'html5-qrcode',
			plugins_url( '/assets/vendor/html5-qrcode.min.js', __FILE__ ),
			array(),
			'2.3.8',
			true
		);

		wp_enqueue_script(
			'camptix-qr-scanner',
			plugins_url( '/assets/qr-check-in-scanner.js', __FILE__ ),
			array( 'html5-qrcode' ),
			filemtime( __DIR__ . '/assets/qr-check-in-scanner.js' ),
			true
		);

		wp_enqueue_style(
			'camptix-qr-scanner',
			plugins_url( '/assets/qr-check-in-scanner.css', __FILE__ ),
			array( 'dashicons' ),
			filemtime( __DIR__ . '/assets/qr-check-in-scanner.css' )
		);

		wp_localize_script(
			'camptix-qr-scanner',
			'camptixQRCheckin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_CHECKIN ),
				'strings' => array(
					'starting'     => __( 'Starting camera...', 'wordcamporg' ),
					'scanning'     => __( 'Point the camera at an attendee QR code.', 'wordcamporg' ),
					'sending'      => __( 'Checking in...', 'wordcamporg' ),
					'noCamera'     => __( 'No camera available. Use manual entry below.', 'wordcamporg' ),
					'cameraDenied' => __( 'Camera permission was denied. Use manual entry below.', 'wordcamporg' ),
					'networkError' => __( 'Network error. Please try again.', 'wordcamporg' ),
					'invalidCode'  => __( 'That is not a valid check-in code.', 'wordcamporg' ),
				),
			)
		);

		return __DIR__ . '/views/qr-check-in-scanner.php';
	}

	/**
	 * Handle the generic-camera check-in confirmation page ( ?tix_action=checkin ).
	 */
	protected function handle_checkin_page() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$attendee_id = $this->verified_attendee_from_request();

		if ( ! $attendee_id ) {
			$this->checkin_result = array(
				'status'  => 'invalid',
				'message' => __( 'This QR code is not valid.', 'wordcamporg' ),
				'name'    => '',
			);
		} else {
			$this->checkin_result = $this->process_checkin( $attendee_id );
		}

		add_filter( 'template_include', array( $this, 'render_checkin_result_template' ) );
	}

	/**
	 * Return the check-in confirmation template.
	 *
	 * @param string $template Original template path.
	 * @return string
	 */
	public function render_checkin_result_template( $template ) {
		wp_enqueue_style(
			'camptix-qr-scanner',
			plugins_url( '/assets/qr-check-in-scanner.css', __FILE__ ),
			array( 'dashicons' ),
			filemtime( __DIR__ . '/assets/qr-check-in-scanner.css' )
		);

		return __DIR__ . '/views/qr-check-in-result.php';
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Check-in write.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Mark an attendee present, with full validation.
	 *
	 * Assumes the caller has already verified the token signature. Returns an array with `status`
	 * (one of: success, already, voided, denied, not_found), a human-readable `message`, and the
	 * attendee `name`.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return array
	 */
	public function process_checkin( $attendee_id ) {
		global $camptix;

		$attendee = get_post( $attendee_id );

		if ( ! $this->is_attendee( $attendee ) ) {
			return array(
				'status'  => 'not_found',
				'message' => __( 'Attendee not found.', 'wordcamporg' ),
				'name'    => '',
			);
		}

		// Check capability before revealing ticket status to a logged-in but unauthorized user.
		if ( ! current_user_can( 'edit_post', $attendee_id ) ) {
			return array(
				'status'  => 'denied',
				'message' => __( 'You do not have permission to check in attendees.', 'wordcamporg' ),
				'name'    => '',
			);
		}

		$name = $this->get_attendee_name( $attendee_id );

		if ( 'publish' !== $attendee->post_status ) {
			$this->log( sprintf( 'QR check-in rejected: ticket status is "%s".', $attendee->post_status ), $attendee_id );

			return array(
				'status'  => 'voided',
				'message' => __( 'This ticket is not valid (cancelled, refunded, or unpaid).', 'wordcamporg' ),
				'name'    => $name,
			);
		}

		if ( get_post_meta( $attendee_id, self::META_ATTENDED, true ) ) {
			return array(
				'status'  => 'already',
				'message' => $this->already_checked_in_message( $attendee_id ),
				'name'    => $name,
			);
		}

		update_post_meta( $attendee_id, self::META_ATTENDED, true );
		update_post_meta( $attendee_id, self::META_TIME, time() );
		update_post_meta( $attendee_id, self::META_BY, get_current_user_id() );
		$camptix->increment_stats( 'attended', 1 );
		$this->log( 'Checked in via QR code.', $attendee_id );

		/* translators: %s: attendee name. */
		$message = sprintf( __( 'Checked in: %s', 'wordcamporg' ), $name );

		return array(
			'status'  => 'success',
			'message' => $message,
			'name'    => $name,
		);
	}

	/**
	 * AJAX handler for a scanner check-in.
	 */
	public function ajax_checkin() {
		if ( ! check_ajax_referer( self::NONCE_CHECKIN, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'status'  => 'invalid',
					'message' => __( 'Security check failed. Reload the scanner and try again.', 'wordcamporg' ),
				)
			);
		}

		$token       = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$attendee_id = $this->verify_token( $token );

		if ( ! $attendee_id ) {
			wp_send_json_error(
				array(
					'status'  => 'invalid',
					'message' => __( 'This QR code is not valid.', 'wordcamporg' ),
				)
			);
		}

		$result = $this->process_checkin( $attendee_id );

		if ( 'success' === $result['status'] || 'already' === $result['status'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Admin: per-attendee single send.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Add a "QR code" column to the attendees list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_qr_column( $columns ) {
		$columns['tix_qr'] = esc_html__( 'QR code', 'wordcamporg' );

		return $columns;
	}

	/**
	 * Render the per-attendee "Send QR code" control in the list table.
	 *
	 * @param string $column      Column key.
	 * @param int    $attendee_id Attendee post ID.
	 */
	public function render_qr_column( $column, $attendee_id ) {
		if ( 'tix_qr' !== $column ) {
			return;
		}

		$attendee = get_post( $attendee_id );

		if ( ! $this->is_attendee( $attendee ) || 'publish' !== $attendee->post_status ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}

		$this->render_send_button( $attendee_id );
	}

	/**
	 * Render the "Send QR code" button on the attendee edit screen.
	 *
	 * @param WP_Post $attendee Attendee post.
	 */
	public function render_send_qr_button( $attendee ) {
		if ( ! $this->is_attendee( $attendee ) || 'publish' !== $attendee->post_status ) {
			return;
		}

		echo '<p>';
		$this->render_send_button( $attendee->ID );
		echo '</p>';
	}

	/**
	 * Output the shared "Send QR code" button markup.
	 *
	 * @param int $attendee_id Attendee post ID.
	 */
	protected function render_send_button( $attendee_id ) {
		printf(
			'<a href="#" class="tix-send-qr button" data-attendee-id="%1$s" data-nonce="%2$s">%3$s</a>',
			esc_attr( $attendee_id ),
			esc_attr( wp_create_nonce( 'tix_send_qr_' . $attendee_id ) ),
			esc_html__( 'Send QR code', 'wordcamporg' )
		);
	}

	/**
	 * Enqueue the admin send-button script on the attendee list/edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'tix_attendee' !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}

		wp_enqueue_script(
			'camptix-qr-admin',
			plugins_url( '/assets/qr-check-in-admin.js', __FILE__ ),
			array( 'jquery' ),
			filemtime( __DIR__ . '/assets/qr-check-in-admin.js' ),
			true
		);

		wp_localize_script(
			'camptix-qr-admin',
			'camptixQRAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'sending' => __( 'Sending...', 'wordcamporg' ),
				'error'   => __( 'Could not send the QR code.', 'wordcamporg' ),
			)
		);
	}

	/**
	 * AJAX handler: email a single attendee their QR code on demand.
	 */
	public function ajax_send_qr_email() {
		global $camptix;

		$attendee_id = isset( $_POST['attendee_id'] ) ? absint( $_POST['attendee_id'] ) : 0;

		if ( ! $attendee_id
			|| ! check_ajax_referer( 'tix_send_qr_' . $attendee_id, 'nonce', false )
			|| ! current_user_can( 'edit_post', $attendee_id )
		) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordcamporg' ) ) );
		}

		$attendee = get_post( $attendee_id );

		if ( ! $this->is_attendee( $attendee ) || 'publish' !== $attendee->post_status ) {
			wp_send_json_error( array( 'message' => __( 'This ticket is not valid, so no QR code was sent.', 'wordcamporg' ) ) );
		}

		$to = get_post_meta( $attendee_id, 'tix_email', true );

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'This attendee does not have a valid email address.', 'wordcamporg' ) ) );
		}

		$sent = $camptix->wp_mail( $to, $this->get_email_subject(), $this->build_email_body( $attendee_id ) );

		if ( $sent ) {
			$this->log( 'QR code emailed to attendee.', $attendee_id );
			wp_send_json_success( array( 'message' => __( 'QR code sent.', 'wordcamporg' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'The email could not be sent.', 'wordcamporg' ) ) );
	}

	/**
	 * Subject line for QR-code emails.
	 *
	 * @return string
	 */
	protected function get_email_subject() {
		global $camptix;
		$options = $camptix->get_options();

		/* translators: %s: event name. */
		return sprintf( __( 'Your check-in QR code for %s', 'wordcamporg' ), $options['event_name'] );
	}

	/**
	 * Build the body of a single-attendee QR-code email.
	 *
	 * The body is plain text containing an <img> tag; on WordCamp.org the `camptix_html_message`
	 * filter renders it as HTML (img is an allowed tag), and the [qr_url] link is the text fallback.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	protected function build_email_body( $attendee_id ) {
		global $camptix;
		$options = $camptix->get_options();

		$first_name = get_post_meta( $attendee_id, 'tix_first_name', true );
		$image_tag  = sprintf(
			'<img src="%1$s" alt="%2$s" width="200" height="200" />',
			esc_url( $this->get_qr_image_url( $attendee_id ) ),
			esc_attr__( 'Your check-in QR code', 'wordcamporg' )
		);

		$lines = array(
			/* translators: %s: attendee first name. */
			sprintf( __( 'Hi %s,', 'wordcamporg' ), $first_name ),
			'',
			/* translators: %s: event name. */
			sprintf( __( 'Here is your check-in QR code for %s. Please have it ready to show at the registration desk when you arrive.', 'wordcamporg' ), $options['event_name'] ),
			'',
			$image_tag,
			'',
			/* translators: %s: check-in URL. */
			sprintf( __( 'If the code does not display, use this link: %s', 'wordcamporg' ), $this->get_checkin_url( $attendee_id ) ),
		);

		return implode( "\n", $lines );
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Admin: bulk send (Tools tab).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Add the "Send QR Codes" tab to the Tools screen.
	 *
	 * @param array $sections Existing tool sections.
	 * @return array
	 */
	public function add_tools_tab( $sections ) {
		$sections['qr_send'] = esc_html__( 'Send QR Codes', 'wordcamporg' );

		return $sections;
	}

	/**
	 * Render and process the bulk "Send QR Codes" tool.
	 */
	public function render_tools_tab() {
		global $camptix;

		if ( ! current_user_can( $camptix->caps['manage_tools'] ) ) {
			return;
		}

		$queued = $this->maybe_queue_bulk_send();

		$tickets = get_posts(
			array(
				'post_type'      => 'tix_ticket',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		?>
		<?php if ( false !== $queued ) : ?>
			<div class="updated notice notice-success"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of attendees queued. */
						_n( 'Queued QR-code email for %d attendee. Emails are sent in the background.', 'Queued QR-code emails for %d attendees. Emails are sent in the background.', $queued, 'wordcamporg' ),
						$queued
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Email attendees their personal check-in QR code. Use the [qr_code] tag to insert the QR image and [qr_url] for a plain-text link. Cancelled or refunded tickets are skipped automatically.', 'wordcamporg' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'tix_qr_bulk_send', 'tix_qr_bulk_send_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="tix-qr-ticket"><?php esc_html_e( 'Tickets', 'wordcamporg' ); ?></label></th>
					<td>
						<select name="tix_qr_ticket_id" id="tix-qr-ticket">
							<option value="0"><?php esc_html_e( 'All tickets', 'wordcamporg' ); ?></option>
							<?php foreach ( $tickets as $ticket ) : ?>
								<option value="<?php echo esc_attr( $ticket->ID ); ?>"><?php echo esc_html( $ticket->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tix-qr-subject"><?php esc_html_e( 'Subject', 'wordcamporg' ); ?></label></th>
					<td><input type="text" class="large-text" name="tix_qr_subject" id="tix-qr-subject" value="<?php echo esc_attr( $this->get_email_subject() ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="tix-qr-body"><?php esc_html_e( 'Message', 'wordcamporg' ); ?></label></th>
					<td><textarea class="large-text" rows="8" name="tix_qr_body" id="tix-qr-body"><?php echo esc_textarea( $this->default_bulk_body() ); ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Queue QR-code emails', 'wordcamporg' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Default body for the bulk send form.
	 *
	 * @return string
	 */
	protected function default_bulk_body() {
		return implode(
			"\n",
			array(
				__( 'Hi [first_name],', 'wordcamporg' ),
				'',
				__( 'Here is your check-in QR code. Please have it ready to show at the registration desk when you arrive.', 'wordcamporg' ),
				'',
				'[qr_code]',
				'',
				__( 'If the code does not display, use this link: [qr_url]', 'wordcamporg' ),
			)
		);
	}

	/**
	 * Queue a bulk QR-code email job from the submitted form.
	 *
	 * @return int|false Number of recipients queued, or false when there is nothing to do.
	 */
	protected function maybe_queue_bulk_send() {
		global $camptix;

		if ( ! isset( $_POST['tix_qr_bulk_send_nonce'] ) ) {
			return false;
		}

		check_admin_referer( 'tix_qr_bulk_send', 'tix_qr_bulk_send_nonce' );

		if ( ! current_user_can( $camptix->caps['manage_tools'] ) ) {
			return false;
		}

		$ticket_id = isset( $_POST['tix_qr_ticket_id'] ) ? absint( $_POST['tix_qr_ticket_id'] ) : 0;
		$subject   = isset( $_POST['tix_qr_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['tix_qr_subject'] ) ) : '';
		$body      = isset( $_POST['tix_qr_body'] ) ? wp_kses_post( wp_unslash( $_POST['tix_qr_body'] ) ) : '';

		if ( '' === trim( $subject ) || '' === trim( $body ) ) {
			return false;
		}

		if ( $ticket_id ) {
			$recipients = $camptix->get_segment(
				'and',
				array(
					array(
						'field' => 'ticket',
						'op'    => 'is',
						'value' => $ticket_id,
					),
				)
			);
		} else {
			$recipients = get_posts(
				array(
					'post_type'      => 'tix_attendee',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
		}

		$recipients = array_filter( array_map( 'absint', (array) $recipients ) );

		if ( empty( $recipients ) ) {
			return 0;
		}

		$email_id = wp_insert_post(
			array(
				'post_type'    => 'tix_email',
				'post_status'  => 'pending',
				'post_title'   => $subject,
				'post_content' => $body,
			)
		);

		if ( ! $email_id || is_wp_error( $email_id ) ) {
			return false;
		}

		foreach ( $recipients as $recipient_id ) {
			add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );
		}

		// Mirror the core Notify tool so the Emails list-table Sent/Total columns report progress.
		update_post_meta( $email_id, 'tix_email_recipients_backup', $recipients );

		$this->log( sprintf( 'Queued a QR-code email to %d recipients.', count( $recipients ) ), $email_id );

		return count( $recipients );
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Notify shortcodes.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Register the [qr_code] and [qr_url] notify shortcodes.
	 */
	public function register_notify_shortcodes() {
		add_shortcode( 'qr_code', array( $this, 'shortcode_qr_code' ) );
		add_shortcode( 'qr_url', array( $this, 'shortcode_qr_url' ) );
	}

	/**
	 * [qr_code] -> an <img> tag for the current recipient's QR image.
	 *
	 * @return string
	 */
	public function shortcode_qr_code() {
		global $camptix;
		$attendee_id = absint( $camptix->tmp( 'attendee_id' ) );

		if ( ! $attendee_id ) {
			return '';
		}

		return sprintf(
			'<img src="%1$s" alt="%2$s" width="200" height="200" />',
			esc_url( $this->get_qr_image_url( $attendee_id ) ),
			esc_attr__( 'Your check-in QR code', 'wordcamporg' )
		);
	}

	/**
	 * [qr_url] -> the current recipient's check-in URL (plain-text fallback).
	 *
	 * @return string
	 */
	public function shortcode_qr_url() {
		global $camptix;
		$attendee_id = absint( $camptix->tmp( 'attendee_id' ) );

		if ( ! $attendee_id ) {
			return '';
		}

		return esc_url( $this->get_checkin_url( $attendee_id ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Settings (Setup screen).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Add the "QR Check-In" section to the Setup screen.
	 *
	 * @param array $sections Existing setup sections.
	 * @return array
	 */
	public function setup_sections( $sections ) {
		$sections['qr-checkin'] = esc_html__( 'QR Check-In', 'wordcamporg' );

		return $sections;
	}

	/**
	 * Register the controls for the QR Check-In setup section.
	 *
	 * @param string $section Current setup section.
	 */
	public function setup_controls( $section ) {
		if ( 'qr-checkin' !== $section ) {
			return;
		}

		add_settings_section( 'general', esc_html__( 'QR Check-In', 'wordcamporg' ), array( $this, 'setup_controls_description' ), 'camptix_options' );
		add_settings_field( 'qr-checkin-scanner-enabled', esc_html__( 'Scanner enabled', 'wordcamporg' ), array( $this, 'field_scanner_enabled' ), 'camptix_options', 'general' );
		add_settings_field( 'qr-checkin-scanner-url', esc_html__( 'Scanner link', 'wordcamporg' ), array( $this, 'field_scanner_url' ), 'camptix_options', 'general' );
	}

	/**
	 * Description for the QR Check-In setup section.
	 */
	public function setup_controls_description() {
		echo '<p>' . esc_html__( 'Email attendees a personal QR code and check them in at the door by scanning it. Attendees never receive a code automatically at purchase; send codes from an attendee row or the "Send QR Codes" tool when you are ready.', 'wordcamporg' ) . '</p>';
	}

	/**
	 * "Scanner enabled" yes/no field.
	 */
	public function field_scanner_enabled() {
		global $camptix;
		$options = $camptix->get_options();
		$value   = ! empty( $options['qr-checkin-scanner-enabled'] );
		?>
		<label class="tix-yes-no description"><input type="radio" name="camptix_options[qr-checkin-scanner-enabled]" value="1" <?php checked( $value, true ); ?>> <?php esc_html_e( 'Yes', 'wordcamporg' ); ?></label>
		<label class="tix-yes-no description"><input type="radio" name="camptix_options[qr-checkin-scanner-enabled]" value="0" <?php checked( $value, false ); ?>> <?php esc_html_e( 'No', 'wordcamporg' ); ?></label>
		<p class="description"><?php esc_html_e( 'Allow logged-in organizers to open the live-camera scanner page.', 'wordcamporg' ); ?></p>
		<?php
	}

	/**
	 * Read-only scanner URL, a button to open it, and an option to rotate the signing secret.
	 */
	public function field_scanner_url() {
		global $camptix;
		$options = $camptix->get_options();
		$enabled = ! empty( $options['qr-checkin-scanner-enabled'] );
		$url     = $this->get_scanner_url();
		?>
		<input type="text" class="large-text" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();" />
		<p class="description"><?php esc_html_e( 'Open this link on a phone while logged in to scan attendee QR codes.', 'wordcamporg' ); ?></p>

		<p>
			<?php if ( $enabled ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" class="button" target="_blank" rel="noopener"><?php esc_html_e( 'Open scanner', 'wordcamporg' ); ?></a>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'Turn the scanner on above and save changes to open it.', 'wordcamporg' ); ?></span>
			<?php endif; ?>
		</p>

		<p>
			<label>
				<input type="checkbox" name="camptix_options[qr-checkin-regenerate]" value="1" />
				<?php esc_html_e( 'Generate a new signing secret. This invalidates every QR code already emailed to attendees.', 'wordcamporg' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Persist QR Check-In settings.
	 *
	 * @param array $output Validated options.
	 * @param array $input  Submitted options.
	 * @return array
	 */
	public function validate_options( $output, $input ) {
		if ( isset( $input['qr-checkin-scanner-enabled'] ) ) {
			$output['qr-checkin-scanner-enabled'] = (bool) $input['qr-checkin-scanner-enabled'];
		}

		if ( ! empty( $input['qr-checkin-regenerate'] ) ) {
			update_option( self::SECRET_OPTION, wp_generate_password( 64, true, true ), false );
		}

		return $output;
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Privacy (GDPR) integration for the check-in audit meta.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Register the check-in time for personal-data export.
	 *
	 * @param array $props Properties to export.
	 * @return array
	 */
	public function privacy_export_props( $props ) {
		$props[ self::META_TIME ] = __( 'Event Check-In Time', 'wordcamporg' );

		return $props;
	}

	/**
	 * Provide the export value for the check-in time.
	 *
	 * @param array   $export Export rows.
	 * @param string  $key    Property key.
	 * @param string  $label  Property label.
	 * @param WP_Post $post   Attendee post.
	 * @return array
	 */
	public function privacy_export_value( $export, $key, $label, $post ) {
		if ( self::META_TIME !== $key ) {
			return $export;
		}

		$time = (int) get_post_meta( $post->ID, self::META_TIME, true );

		if ( $time ) {
			$export[] = array(
				'name'  => $label,
				'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ),
			);
		}

		return $export;
	}

	/**
	 * Register the check-in audit meta for personal-data erasure.
	 *
	 * @param array $props Properties to erase.
	 * @return array
	 */
	public function privacy_erase_props( $props ) {
		$props[ self::META_TIME ] = '';
		$props[ self::META_BY ]   = '';

		return $props;
	}

	/**
	 * Erase the check-in audit meta.
	 *
	 * @param string  $key  Property key.
	 * @param string  $type Data type (unused).
	 * @param WP_Post $post Attendee post.
	 */
	public function privacy_erase_value( $key, $type, $post ) {
		if ( in_array( $key, array( self::META_TIME, self::META_BY ), true ) ) {
			delete_post_meta( $post->ID, $key );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 *  Helpers.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Whether the given value is a CampTix attendee post.
	 *
	 * @param mixed $attendee Possible attendee post.
	 * @return bool
	 */
	protected function is_attendee( $attendee ) {
		return $attendee instanceof WP_Post && 'tix_attendee' === $attendee->post_type;
	}

	/**
	 * Build a display name for an attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	protected function get_attendee_name( $attendee_id ) {
		$first = get_post_meta( $attendee_id, 'tix_first_name', true );
		$last  = get_post_meta( $attendee_id, 'tix_last_name', true );

		return trim( $first . ' ' . $last );
	}

	/**
	 * Human-readable "already checked in" message including the time when known.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	protected function already_checked_in_message( $attendee_id ) {
		$time = (int) get_post_meta( $attendee_id, self::META_TIME, true );

		if ( ! $time ) {
			return __( 'Already checked in.', 'wordcamporg' );
		}

		return sprintf(
			/* translators: %s: date and time of the original check-in. */
			__( 'Already checked in at %s.', 'wordcamporg' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time )
		);
	}

	/**
	 * Write a log entry to the CampTix log under the "qr-checkin" category.
	 *
	 * @param string $message Log message.
	 * @param int    $post_id Related post ID.
	 * @param mixed  $data    Optional structured data.
	 */
	public function log( $message, $post_id = 0, $data = null ) {
		global $camptix;
		$camptix->log( $message, $post_id, $data, 'qr-checkin' );
	}
}

camptix_register_addon( 'CampTix_QR_Check_In' );

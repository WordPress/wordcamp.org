<?php
/**
 * CampTix - Webhook
 *
 * An addon for CampTix that allows 3rd party integration via webhook.
 *
 * @package CampTix_Webhook
 */

namespace CampTix\Webhook\Addon;

use CampTix_Addon;
use CampTix\Webhook;

/**
 * Allows integration with 3rd party services via webhook.
 */
class CampTix_Webhook extends CampTix_Addon {

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

			// Add js for testing webhook.
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		}

		add_action( 'save_post_tix_attendee', array( $this, 'trigger_webhook_async' ), 10, 2 );
		add_action( 'camptix_webhook_trigger', array( $this, 'trigger_webhook' ) );
	}

	/**
	 * Add a new section to the Setup screen.
	 */
	public function setup_sections( $sections ) {
		$sections['webhook-ui'] = esc_html__( 'Webhook', 'wordcamporg' );

		return $sections;
	}

	/**
	 * Add some controls to our Setup section.
	 */
	public function setup_controls( $section ) {
		global $camptix;

		if ( 'webhook-ui' != $section ) {
			return;
		}

		add_settings_section( 'general', esc_html__( 'Attendees Webhook', 'wordcamporg' ), array( $this, 'setup_controls_section' ), 'camptix_options' );

		// Fields
		$camptix->add_settings_field_helper( 'webhook-enabled', esc_html__( 'Enabled', 'wordcamporg' ), 'field_yesno', 'general' );
		$camptix->add_settings_field_helper( 'webhook-url', esc_html__( 'Webhook URL', 'wordcamporg' ), 'field_text', 'general', esc_html__( 'Webhook URL including protocol such as https://', 'wordcamporg' ) );

		add_action( 'camptix_setup_buttons', array( $this, 'setup_buttons_test_webhook' ) );
	}

	/**
	 * Runs whenever the CampTix option is updated.
	 */
	public function validate_options( $output, $input ) {
		if ( isset( $input['webhook-enabled'] ) ) {
			$output['webhook-enabled'] = (bool) $input['webhook-enabled'];
		}

		if ( ! empty( $input['webhook-url'] ) ) {
			$output['webhook-url'] = sanitize_url( $input['webhook-url'] );
		}

		return $output;
	}

	/**
	 * Setup section description.
	 */
	public function setup_controls_section() {
		?>
		<p><?php esc_html_e( 'Enable webhook when attendees data get created/updated.', 'wordcamporg' ); ?></p>

		<p><strong><?php esc_html_e( 'Note: Please do test the webhook before you enable it.', 'wordcamporg' ); ?></strong></p>
		<?php
	}

	/**
	 * Setup buttons.
	 */
	public function setup_buttons_test_webhook() {
		$button = '<button id="camptix-webhook-test-url" type="button" class="button button-secondary" name="camptix_action">' . esc_html__( 'Test Webhook', 'wordcamporg' ) . '</button>';

		echo wp_kses_post( $button );
	}

	/**
	 * Trigger webhook asynchronously.
	 * Use cron to trigger webhook 5 seconds after attendee is updated.
	 * So this process won't block the main process. And prevents multiple triggers.
	 *
	 * @param int     $post_id Attendee ID.
	 * @param WP_Post $post Attendee Post Object.
	 * @return void
	 */
	public function trigger_webhook_async( $post_id, $post ) {
		// Trigger webhook asynchronously.
		if ( ! wp_next_scheduled( 'camptix_webhook_trigger', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'camptix_webhook_trigger', array( $post_id ) );
		}
	}

	/**
	 * Trigger webhook when attendee is updated.
	 *
	 * @param int $post_id Attendee ID.
	 * @return void
	 */
	public function trigger_webhook( $post_id ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$camptix_options = $camptix->get_options();

		$is_enabled  = isset( $camptix_options['webhook-enabled'] ) ? $camptix_options['webhook-enabled'] : false;
		$webhook_url = isset( $camptix_options['webhook-url'] ) ? $camptix_options['webhook-url'] : '';

		if ( ! $is_enabled ) {
			return;
		}

		if ( empty( $webhook_url ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( 'tix_attendee' !== $post->post_type ) {
			return;
		}

		$triggered_number = absint( get_post_meta( $post_id, 'tix_webhook_triggered_number', true ) );

		// Get attendee data.
		$attendee_data = array(
			'timestamp' => time(),
			'status' => $post->post_status,
			'is_new_entry' => $triggered_number === 0,
			'tix_email' => get_post_meta( $post_id, 'tix_email', true ),
			'tix_first_name' => get_post_meta( $post_id, 'tix_first_name', true ),
			'tix_last_name' => get_post_meta( $post_id, 'tix_last_name', true ),
			'tix_ticket_id' => get_post_meta( $post_id, 'tix_ticket_id', true ),
			'tix_coupon' => get_post_meta( $post_id, 'tix_coupon', true ),
		);

		$attendee_data = apply_filters( 'camptix_webhook_attendee_data', $attendee_data, $post_id );

		// Prepare webhook data.
		$response = wp_remote_post(
			$webhook_url,
			array(
				'body' => wp_json_encode( $attendee_data ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		update_post_meta( $post_id, 'tix_webhook_triggered_number', $triggered_number + 1 );

		// Log the response.
		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Webhook failed: %s', $response->get_error_message() ), $post_id, $response );
		}

		$this->log( 'Webhook triggered', $post_id, $response );
	}

	/**
	 * Enqueue scripts for admin.
	 *
	 * @param mixed $hook Current page hook.
	 * @return void
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( $hook !== 'tix_ticket_page_camptix_options' ) {
			return;
		}

		wp_enqueue_script(
			'camptix-webhook-admin',
			plugin_dir_url( Webhook\BASE_FILE ) . 'js/camptix-webhook-admin.js',
			array(),
			'1.0',
			array(
				'strategy' => 'async',
				'footer' => true,
			)
		);
	}

	/**
	 * Write a log entry to CampTix.
	 */
	public function log( $message, $post_id = 0, $data = null ) {
		global $camptix;
		$camptix->log( $message, $post_id, $data, 'webhook' );
	}

	/**
	 * Register self as a CampTix addon.
	 */
	public static function register_addon() {
		camptix_register_addon( __CLASS__ );
	}
}

<?php
/**
 * Implements Events application class
 *
 * @package WordCamp Post Type
 */

namespace WordPress_Community\Applications;

use function WordPress_Community\Applications\Events\render_events_application_form;

require_once dirname( __DIR__ ) . '/wcpt-wordcamp/class-wordcamp-application.php';
require_once dirname( __DIR__ ) . '/views/applications/events/shortcode-application.php';

use Event_Admin;
use WP_Error, WP_Post;
/**
 * Class Events_Application
 *
 * @package WordPress_Events\Applications
 */
class Events_Application extends WordCamp_Application {
	public $post;

	const SHORTCODE_SLUG = 'events-organizer-application';

	/**
	 * Enqueue scripts and stylesheets
	 */
	public function enqueue_assets() {
		global $post;

		wp_register_script(
			'wordcamp-application',
			plugins_url( 'javascript/applications/wordcamp.js', __DIR__ ),
			array( 'jquery' ),
			1,
			true
		);

		if ( isset( $post->post_content ) && has_shortcode( $post->post_content, self::SHORTCODE_SLUG ) ) {
			wp_enqueue_script( 'wordcamp-application' );
		}
	}

	/**
	 * Render application form
	 *
	 * @param array $countries
	 *
	 * @return null|void
	 */
	public function render_application_form( $countries, $prefilled_fields ) {
		render_events_application_form( $countries, $prefilled_fields );
	}

	/**
	 * Get the default values for all application fields
	 *
	 * @return array
	 */
	public function get_default_application_values() {
		// WordCamp uses an ID with questions. Not sure how are they used. Ask @corey.
		$values = array(
			'q_name'                => '',
			'q_email'               => '',
			'q_address_line_1'      => '',
			'q_address_line_2'      => '',
			'q_city'                => '',
			'q_state'               => '',
			'q_country'             => '',
			'q_zip'                 => '',
			'q_mtp_loc'             => '',
			'q_already_a_meetup'    => '',
			'q_existing_meetup_url' => '',
			'q_introduction'        => '',
			'q_socialmedia'         => '',
			'q_reasons_plans'       => '',
			'q_community_interest'  => '',
			'q_wporg_username'      => '',
			'q_wp_slack_username'   => '',
			'q_anything_else'       => '',
		);

		return $values;
	}

	/**
	 * Create a Events post from an application
	 *
	 * @param array $data
	 *
	 * @return bool|WP_Error
	 */
	public function create_post( $data ) {
		// Create the post.
		$wordcamp_user_id = get_user_by( 'email', 'support@wordcamp.org' )->ID;
		$statuses         = self::get_post_statuses();

		$post = array(
			'post_type'   => self::get_event_type(),
			'post_title'  => esc_html( $data['q_mtp_loc'] ),
			'post_status' => self::get_default_status(),
			'post_author' => $wordcamp_user_id,
		);

		$post_id = wp_insert_post( $post, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Populate the meta fields.
		add_post_meta( $post_id, '_application_data', $data );

		$organizer_address = <<<ADDRESS
{$data['q_address_line_1']}
{$data['q_address_line_2']}
{$data['q_city']}, {$data['q_state']}, {$data['q_country']}
{$data['q_zip']}
ADDRESS;

		add_post_meta( $post_id, 'Organizer Name', $data['q_name'] );
		add_post_meta( $post_id, 'Email', $data['q_email'] );
		add_post_meta( $post_id, 'City', $data['q_mtp_loc'] );
		add_post_meta( $post_id, 'Address', $organizer_address );
		add_post_meta( $post_id, 'Meetup URL', $data['q_existing_meetup_url'] );
		add_post_meta( $post_id, 'Primary organizer WordPress.org username', $data['q_wporg_username'] );
		add_post_meta( $post_id, 'Slack', $data['q_wp_slack_username'] );
		add_post_meta( $post_id, 'Date Applied', time() );
		add_post_meta( $post_id, 'Meetup Location', $data['q_mtp_loc'] );

		$status_log_id = add_post_meta(
			$post_id,
			'_status_change',
			array(
				'timestamp' => time(),
				'user_id'   => $wordcamp_user_id,
				'message'   => sprintf( '%s &rarr; %s', 'Application', $statuses[ self::get_default_status() ] ),
			)
		);

		// See Event_admin::log_status_changes().
		if ( $status_log_id ) {
			add_post_meta( $post_id, "_status_change_log_{$post['post_type']} $status_log_id", time() );
		}

		$this->post = get_post( $post_id );

		return true;
	}

}

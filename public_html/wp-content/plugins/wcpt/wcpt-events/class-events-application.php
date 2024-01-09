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
			'events-application',
			plugins_url( 'javascript/applications/events.js', __DIR__ ),
			array( 'jquery' ),
			1,
			true
		);

		if ( isset( $post->post_content ) && has_shortcode( $post->post_content, self::SHORTCODE_SLUG ) ) {
			wp_enqueue_script( 'events-application' );
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
		$values = array(
			'q_first_name'                => '',
			'q_last_name'                 => '',
			'q_email'                     => '',
			'q_wporg_username'            => '',
			'q_slack_username'            => '',
			'q_add1'                      => '',
			'q_add2'                      => '',
			'q_city'                      => '',
			'q_state'                     => '',
			'q_country'                   => '',
			'q_zip'                       => '',
			'q_active_meetup'             => '',
			'q_meetup_url'                => '',
			'q_camps_been_to'             => '',
			'q_role_in_meetup'            => '',
			'q_where_find_online'         => '',
			'q_wordcamp_location'         => '',
			'q_wordcamp_date'             => '',
			'q_in_person_online'          => '',
			'q_describe_events'           => '',
			'q_describe_goals'            => '',
			'q_describe_event'            => '',
			'q_describe_event_other'      => '',
			'q_how_many_attendees'        => '',
			'q_co_organizer_contact_info' => '',
			'q_event_url'                 => '',
			'q_venues_considering'        => '',
			'q_estimated_cost'            => '',
			'q_raise_money'               => '',
			'q_anything_else'             => '',
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
			'post_title'  => esc_html( $data['q_wordcamp_location'] ),
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
{$data['q_add1']}
{$data['q_add2']}
{$data['q_city']}, {$data['q_state']}, {$data['q_country']}
{$data['q_zip']}
ADDRESS;

		add_post_meta( $post_id, 'Organizer Name', $data['q_first_name'] . ' ' . $data['q_last_name'] );
		add_post_meta( $post_id, 'Email', $data['q_email'] );
		add_post_meta( $post_id, 'City', $data['q_wordcamp_location'] );
		add_post_meta( $post_id, 'Address', $organizer_address );
		add_post_meta( $post_id, 'Primary organizer WordPress.org username', $data['q_wporg_username'] );
		add_post_meta( $post_id, 'Slack', $data['q_slack_username'] );
		add_post_meta( $post_id, 'Date Applied', time() );
		add_post_meta( $post_id, 'Meetup Location', $data['q_wordcamp_location'] );

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

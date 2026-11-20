<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key;
use const WordCamp\Groups\Frontend\Group_Message\BATCH_SIZE;
use const WordCamp\Groups\Frontend\Group_Message\CRON_HOOK;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers organizers messaging the whole group without an event (#2409).
 */
class Test_Groups_Group_Message extends Groups_TestCase {

	/**
	 * Post a group message as the current user.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Response
	 */
	private function post_message( array $params ) {
		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-message' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * An organizer's message is scheduled and logged, then emailed as plain
	 * text to the members with event emails on, and not to members who
	 * turned them off.
	 */
	public function test_organizer_message_reaches_opted_in_members() {
		$organizer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$opted_in     = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$opted_out    = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		update_user_meta( $opted_in->ID, get_scoped_opt_in_meta_key(), '1' );
		update_user_meta( $opted_out->ID, get_scoped_opt_in_meta_key(), '0' );

		wp_set_current_user( $organizer_id );
		$response = $this->post_message(
			array(
				'subject' => 'Volunteers wanted',
				'message' => 'We need two people to help set up the room.',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$entry = \WordCamp\Groups\Action_Log\get_entries()['entries'][0];
		$this->assertSame( 'group_messaged', $entry['action'] );
		$this->assertSame( $organizer_id, $entry['user_id'] );

		$args = array( 'Volunteers wanted', 'We need two people to help set up the room.', 0 );
		$this->assertNotFalse( wp_next_scheduled( CRON_HOOK, $args ) );

		$sent    = array();
		$capture = static function ( $short_circuit, $atts ) use ( &$sent ) {
			$sent[ $atts['to'][0] ] = $atts;
			return true;
		};

		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		do_action_ref_array( CRON_HOOK, $args );
		remove_filter( 'pre_wp_mail', $capture, 10 );

		$to_opted_in = array_values( array_filter( $sent, static fn( $to ) => str_contains( $to, $opted_in->user_email ), ARRAY_FILTER_USE_KEY ) );

		$this->assertCount( 1, $to_opted_in );
		$this->assertSame( 'Volunteers wanted', $to_opted_in[0]['subject'] );
		$this->assertStringContainsString( 'We need two people to help set up the room.', $to_opted_in[0]['message'] );
		$this->assertStringContainsString( 'turn off event emails for this group', $to_opted_in[0]['message'] );
		$this->assertContains( 'Content-Type: text/plain; charset=UTF-8', $to_opted_in[0]['headers'] );
		$this->assertStringNotContainsString( $opted_out->user_email, implode( ',', array_keys( $sent ) ) );
	}

	/**
	 * A full batch schedules the next one, so large groups are mailed in steps.
	 */
	public function test_full_batch_schedules_the_next() {
		self::factory()->user->create_many( BATCH_SIZE, array( 'role' => 'subscriber' ) );

		add_filter( 'pre_wp_mail', '__return_true' );
		do_action( CRON_HOOK, 'Hello', 'Hello all', 0 );
		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertNotFalse( wp_next_scheduled( CRON_HOOK, array( 'Hello', 'Hello all', BATCH_SIZE ) ) );
	}

	/**
	 * Event Organizers can't message the whole group, and an empty message is refused.
	 */
	public function test_message_refusals() {
		$hello = array(
			'subject' => 'Hi',
			'message' => 'Hello',
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->post_message( $hello )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 400, $this->post_message( array_merge( $hello, array( 'subject' => ' ' ) ) )->get_status() );
	}
}

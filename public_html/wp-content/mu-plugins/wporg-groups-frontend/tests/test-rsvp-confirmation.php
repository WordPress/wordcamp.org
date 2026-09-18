<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Rsvp\Rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers the RSVP confirmation email (#2062): a member who RSVPs as
 * attending gets one plain-text confirmation, and nothing else does.
 */
class Test_Groups_RSVP_Confirmation extends Groups_TestCase {

	/**
	 * Emails captured during the current test, via `pre_wp_mail`.
	 *
	 * @var array[]
	 */
	protected $sent_mail = array();

	/**
	 * Intercept outgoing mail, and re-add the real hook this class's own
	 * `Groups_TestCase::setUp()` removes for every other test in the suite.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sent_mail = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_action( 'set_object_terms', 'WordCamp\Groups\Frontend\RSVP_Confirmation\send_confirmation', 10, 6 );
	}

	/**
	 * Remove the mail interceptor and the hook re-added above.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_action( 'set_object_terms', 'WordCamp\Groups\Frontend\RSVP_Confirmation\send_confirmation', 10 );

		parent::tearDown();
	}

	/**
	 * Record mail instead of sending it.
	 *
	 * @param null|bool $short_circuit Whether to short-circuit `wp_mail()`.
	 * @param array     $atts          `wp_mail()` arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent_mail[] = $atts;

		return true;
	}

	/**
	 * A published event with saved datetimes.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_dated_event( string $status = 'publish' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => $status,
				'post_title'  => 'Confirmation Test Event',
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => '2031-05-20 10:00:00',
				'datetime_end'   => '2031-05-20 12:00:00',
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * A group member who can RSVP.
	 *
	 * @return int
	 */
	private function create_member(): int {
		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

		return $user_id;
	}

	/**
	 * RSVPing as attending sends exactly one confirmation, addressed to the
	 * member, naming the event.
	 */
	public function test_sends_confirmation_on_attending_rsvp() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$this->assertCount( 1, $this->sent_mail, 'Expected exactly one confirmation email.' );

		$mail = $this->sent_mail[0];
		$user = get_userdata( $user_id );

		$this->assertStringContainsString( $user->user_email, implode( ',', (array) $mail['to'] ) );
		$this->assertStringContainsString( 'Confirmation Test Event', $mail['subject'] );
		$this->assertStringContainsString( 'Confirmation Test Event', $mail['message'] );
	}

	/**
	 * The confirmation is plain text, not GatherPress's HTML event email.
	 */
	public function test_confirmation_is_plain_text() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$headers = implode( ' ', (array) $this->sent_mail[0]['headers'] );

		$this->assertStringContainsString( 'text/plain', $headers );
		$this->assertStringNotContainsString( '<html', $this->sent_mail[0]['message'] );
	}

	/**
	 * The event's date and its permalink both make it into the body, so the
	 * email is usable as the record of the event testers asked for.
	 */
	public function test_confirmation_carries_date_and_link() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$message = $this->sent_mail[0]['message'];

		$this->assertStringContainsString( '2031', $message, 'Expected the event date in the body.' );
		$this->assertStringContainsString( get_permalink( $event_id ), $message );
	}

	/**
	 * Declining sends nothing: the confirmation is for attendance only.
	 */
	public function test_sends_nothing_on_not_attending_rsvp() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();

		( new Rsvp( $event_id ) )->save( $user_id, 'not_attending' );

		$this->assertCount( 0, $this->sent_mail );
	}

	/**
	 * Re-saving an RSVP that already reads attending does not confirm again.
	 * `Storage::save()` rewrites the status term on every save, so without
	 * the unchanged-status guard this would mail on each one.
	 */
	public function test_does_not_resend_when_status_is_unchanged() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();
		$rsvp     = new Rsvp( $event_id );

		$rsvp->save( $user_id, 'attending' );
		$rsvp->save( $user_id, 'attending' );

		$this->assertCount( 1, $this->sent_mail, 'A re-save at the same status should not confirm again.' );
	}

	/**
	 * Cancelling and re-RSVPing is a real change of mind each way, so the
	 * member is confirmed again when they come back.
	 */
	public function test_resends_after_cancelling_and_rsvping_again() {
		$event_id = $this->create_dated_event();
		$user_id  = $this->create_member();
		$rsvp     = new Rsvp( $event_id );

		$rsvp->save( $user_id, 'attending' );
		$rsvp->save( $user_id, 'not_attending' );
		$rsvp->save( $user_id, 'attending' );

		$this->assertCount( 2, $this->sent_mail );
	}

	/**
	 * Nothing in the body or the subject arrives HTML-escaped. `the_title`
	 * runs `wptexturize()`, and the group name is stored escaped, so both
	 * would otherwise reach the member as `&#8217;` and `&quot;` in what is
	 * a plain-text message.
	 */
	public function test_entities_are_decoded_for_plain_text() {
		update_option( 'blogname', 'Jess\'s "Group"' );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Let\'s build Jess\'s "demo" site',
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => '2031-05-20 10:00:00',
				'datetime_end'   => '2031-05-20 12:00:00',
				'timezone'       => 'UTC',
			)
		);

		( new Rsvp( $event_id ) )->save( $this->create_member(), 'attending' );

		$mail = $this->sent_mail[0];

		$this->assertStringNotContainsString( '&#', $mail['subject'], 'The subject should carry no HTML entities.' );
		$this->assertStringNotContainsString( '&#', $mail['message'], 'The body should carry no HTML entities.' );
		$this->assertStringNotContainsString( '&quot;', $mail['message'] );
		$this->assertStringContainsString( 'Jess', $mail['message'] );
	}

	/**
	 * Terms set on some other taxonomy never reach the mailer.
	 */
	public function test_ignores_other_taxonomies() {
		$post_id = self::factory()->post->create();

		wp_set_object_terms( $post_id, 'uncategorized', 'category' );

		$this->assertCount( 0, $this->sent_mail );
	}
}

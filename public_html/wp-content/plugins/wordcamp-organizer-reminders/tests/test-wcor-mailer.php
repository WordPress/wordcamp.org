<?php

namespace WordCamp\Organizer_Reminders\Tests;
use WP_UnitTest_Factory;
use WCOR_Reminder, WCOR_Mailer;
use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_WCOR_Mailer
 *
 * These are intentionally closer to integration tests than unit tests.
 *
 * @group organizer-reminders
 */
class Test_WCOR_Mailer extends Database_TestCase {
	/**
	 * @var int $triggered_reminder_post_id The ID of an Organizer Reminder post which is configured to be sent on a trigger.
	 */
	protected static $triggered_reminder_post_id;

	/**
	 * @var int $timed_reminder_post_id The ID of an Organizer Reminder post which is configured to be sent at a specific time.
	 */
	protected static $timed_reminder_post_id;

	/**
	 * @var int $not_for_wordcamp_post_id The ID of an Organizer Reminder post which is not configured to be sent for WordCamps.
	 */
	protected static $not_for_wordcamp_post_id;

	/**
	 * @var int $wordcamp_dayton_post_id The ID of a WordCamp post for Dayton, Ohio, USA.
	 */
	protected static $wordcamp_dayton_post_id;

	/**
	 * @var int $other_event_post_id The ID of a non-WordCamp event post.
	 */
	protected static $other_event_post_id;

	/**
	 * Set up the mocked PHPMailer instance before each test method.
	 */
	protected function setUp() : void {
		parent::setUp();
		reset_phpmailer_instance();
	}

	/**
	 * Create fixtures that are shared by multiple test cases.
	 *
	 * @param WP_UnitTest_Factory $factory The base factory object.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		/*
		 * Reminders must be created _before_ WordCamps, to avoid triggering the early return in
		 * `timed_email_is_ready_to_send()`. To test that early return, you can modify the
		 * `post_date` inside that specific test function.
		 */
		self::$triggered_reminder_post_id = $factory->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => '[wordcamp_name] has been added to the final schedule',
				'post_content' => "Huzzah! A new WordCamp is coming soon to [wordcamp_location]! The lead organizer is [lead_organizer_username], and the venue is at:\n\n[venue_address]",
			)
		);

		update_post_meta( self::$triggered_reminder_post_id, 'wcor_send_when',  'wcor_send_trigger'    );
		update_post_meta( self::$triggered_reminder_post_id, 'wcor_send_where', 'wcor_send_organizers' );

		self::$timed_reminder_post_id = $factory->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => "It's time to submit [wordcamp_name] reimbursement requests",
				'post_content' => "Howdy [budget_wrangler_name], now's the perfect time to request reimbursement for any out of pocket expenses. You can do that at [wordcamp_url]/wp-admin/edit.php?post_type=wcb_reimbursement.",
			)
		);

		update_post_meta( self::$timed_reminder_post_id, 'wcor_send_where', 'wcor_send_budget_wrangler' );

		self::$not_for_wordcamp_post_id = $factory->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => 'This reminder is not for WordCamps',
				'post_content' => 'So it should not be sent to WordCamp.',
			)
		);

		update_post_meta( self::$not_for_wordcamp_post_id, 'wcor_send_where', 'wcor_send_organizers' );
		update_post_meta( self::$not_for_wordcamp_post_id, 'wcor_event_subtypes', [ 'other' ] );

		self::$wordcamp_dayton_post_id = $factory->post->create(
			array(
				'post_type'  => WCPT_POST_TYPE_ID,
				'post_title' => 'WordCamp Dayton',
			)
		);

		update_post_meta( self::$wordcamp_dayton_post_id, 'Location',                       'Dayton, Ohio, USA'                      );
		update_post_meta( self::$wordcamp_dayton_post_id, 'URL',                            'https://2019.dayton.wordcamp.org'       );
		update_post_meta( self::$wordcamp_dayton_post_id, 'E-mail Address',                 'dayton@wordcamp.org'                    );
		update_post_meta( self::$wordcamp_dayton_post_id, 'WordPress.org Username',         'janedoe'                                );
		update_post_meta( self::$wordcamp_dayton_post_id, 'Physical Address',               '3640 Colonel Glenn Hwy, Dayton, OH, US' );
		update_post_meta( self::$wordcamp_dayton_post_id, 'Start Date (YYYY-mm-dd)',        strtotime( 'Jan 1st, 2019' )             );
		update_post_meta( self::$wordcamp_dayton_post_id, 'Budget Wrangler Name',           'Sally Smith'                            );
		update_post_meta( self::$wordcamp_dayton_post_id, 'Budget Wrangler E-mail Address', 'sally.smith+trez@gmail.com'             );

		self::$other_event_post_id = $factory->post->create(
			array(
				'post_type'  => WCPT_POST_TYPE_ID,
				'post_title' => 'Some Other Event',
			)
		);

		update_post_meta( self::$other_event_post_id, 'E-mail Address', 'other@wordcamp.org' );
		update_post_meta( self::$other_event_post_id, 'event_subtype',  'other'              );
	}

	/**
	 * Reset the mocked PHPMailer instance after each test method.
	 */
	protected function tearDown() : void {
		reset_phpmailer_instance();
		parent::tearDown();
	}

	/**
	 * Assert that an email was successfully sent.
	 *
	 * @param string $to      The expected recipient of the message.
	 * @param string $subject The expected subject of the message.
	 * @param string $body    The expected body content (needle to search for in the email body).
	 * @param bool   $result  The returned value from `wp_mail()`, if available. It defaults to `true` because it
	 *                        isn't always accessible to the testing function.
	 */
	protected function assert_mail_succeeded( $to, $subject, $body, $result = true ) {
		$mailer                 = tests_retrieve_phpmailer_instance();

		$this->assertNotFalse( $mailer->get_sent(), 'No email was sent.' );

		$normalized_actual_body = str_replace( "\r\n", "\n", $mailer->get_sent()->body );

		$this->assertSame( true, $result );
		$this->assertSame( 0, did_action( 'wp_mail_failed' ) );

		$this->assertSame( $to,      $mailer->get_recipient( 'to' )->address );
		$this->assertSame( $subject, $mailer->get_sent()->subject );
		$this->assertStringContainsString( $body, $normalized_actual_body );
	}

	/**
	 * Test that triggered reminders are sent.
	 *
	 * @covers WCOR_Mailer::send_trigger_added_to_schedule
	 */
	public function test_triggered_message_sent() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		global $WCOR_Mailer;

		update_post_meta( self::$triggered_reminder_post_id, 'wcor_which_trigger', 'wcor_added_to_schedule' );

		$wordcamp = get_post( self::$wordcamp_dayton_post_id );

		$this->assertSame( '', $wordcamp->wcor_sent_email_ids );

		do_action( 'wcpt_added_to_final_schedule', $wordcamp );

		$this->assert_mail_succeeded(
			'dayton@wordcamp.org',
			'WordCamp Dayton has been added to the final schedule',
			"<p>Huzzah! A new WordCamp is coming soon to Dayton, Ohio, USA! The lead organizer is janedoe, and the venue is at:</p>\n<p>3640 Colonel Glenn Hwy, Dayton, OH, US</p>\n"
		);

		$this->assertIsArray( $wordcamp->wcor_sent_email_ids );
		$this->assertContains( self::$triggered_reminder_post_id, $wordcamp->wcor_sent_email_ids );
	}

	/**
	 * Test that timed messages are sent.
	 *
	 * @dataProvider data_timed_messages_sent
	 *
	 * @param string $send_when        The type of schedule when the email is sent (e.g., before the camp).
	 * @param string $send_when_period Which period of time the message is scheduled for (e.g., days before the camp).
	 * @param int    $send_when_days   The number of days before/after the period when the message is scheduled for.
	 * @param string $compare_date     The date that the scheduled message is compared do, in order to determine if
	 *                                 it's ready to be sent (e.g., the start date of the camp when sending before
	 *                                 the camp starts).
	 *
	 * @covers WCOR_Mailer::send_timed_emails
	 */
	public function test_timed_messages_sent( $send_when, $send_when_period, $send_when_days, $compare_date, $wordcamp_post_status ) {
		/** @var WCOR_Mailer $WCOR_Mailer */
		global $WCOR_Mailer;

		update_post_meta( self::$timed_reminder_post_id, 'wcor_send_when',  $send_when      );
		update_post_meta( self::$timed_reminder_post_id, $send_when_period, $send_when_days );

		wp_update_post( array(
			'ID'          => self::$wordcamp_dayton_post_id,
			'post_status' => $wordcamp_post_status,
		) );

		if ( in_array( $send_when, array( 'wcor_send_before', 'wcor_send_after' ) ) ) {
			update_post_meta( self::$wordcamp_dayton_post_id, 'Start Date (YYYY-mm-dd)', $compare_date );
		} elseif ( 'wcor_send_after_pending' === $send_when ) {
			update_post_meta( self::$wordcamp_dayton_post_id, 'Start Date (YYYY-mm-dd)', $compare_date );
			update_post_meta( self::$wordcamp_dayton_post_id, '_timestamp_added_to_planning_schedule', $compare_date );
		}

		$wordcamp = get_post( self::$wordcamp_dayton_post_id );

		$this->assertSame( '', $wordcamp->wcor_sent_email_ids );

		do_action( 'wcor_send_timed_emails' );

		if ( 'wcor_send_after' === $send_when && 'wcpt-cancelled' === $wordcamp_post_status ) {
			$this->assertSame( '', $wordcamp->wcor_sent_email_ids );
		} else {
			$this->assert_mail_succeeded(
				'sally.smith+trez@gmail.com',
				"It's time to submit WordCamp Dayton reimbursement requests",
				'<p>Howdy Sally Smith, now\'s the perfect time to request reimbursement for any out of pocket expenses. You can do that at <a href="https://2019.dayton.wordcamp.org/wp-admin/edit.php?post_type=wcb_reimbursement" rel="nofollow">https://2019.dayton.wordcamp.org/wp-admin/edit.php?post_type=wcb_reimbursement</a>.</p>' . "\n"
			);

			$this->assertIsArray( $wordcamp->wcor_sent_email_ids );
			$this->assertContains( self::$timed_reminder_post_id, $wordcamp->wcor_sent_email_ids );
		}
	}

	/**
	 * Provide test cases for test_timed_messages_sent().
	 *
	 * @return array See `test_timed_messages_sent()` for parameter documentation.
	 */
	public function data_timed_messages_sent() {
		return array(
			// Before the camp starts.
			array(
				'wcor_send_before',
				'wcor_send_days_before',
				3,
				strtotime( 'now + 3 days' ),
				'wcpt-scheduled',
			),

			// After the camp ends.
			array(
				'wcor_send_after',
				'wcor_send_days_after',
				3,
				strtotime( 'now - 3 days' ),
				'wcpt-scheduled',
			),

			// After the camp ends but it does not have public status.
			array(
				'wcor_send_after',
				'wcor_send_days_after',
				3,
				strtotime( 'now - 3 days' ),
				'wcpt-cancelled',
			),

			// After added to the pending schedule.
			array(
				'wcor_send_after_pending',
				'wcor_send_days_after_pending',
				3,
				strtotime( 'now - 3 days' ),
				'wcpt-scheduled',
			),
		);
	}

	/**
	 * Test that manual reminders are sent.
	 *
	 * @covers WCOR_Mailer::send_manual_email
	 */
	public function test_manual_message_sent() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		global $WCOR_Mailer;

		$message  = get_post( self::$triggered_reminder_post_id );
		$wordcamp = get_post( self::$wordcamp_dayton_post_id );
		$result   = $WCOR_Mailer->send_manual_email( $message, $wordcamp );

		$this->assert_mail_succeeded(
			'dayton@wordcamp.org',
			'WordCamp Dayton has been added to the final schedule',
			"<p>Huzzah! A new WordCamp is coming soon to Dayton, Ohio, USA! The lead organizer is janedoe, and the venue is at:</p>\n<p>3640 Colonel Glenn Hwy, Dayton, OH, US</p>\n",
			$result
		);
	}

	/**
	 * Test that event subtype is respected when sending reminders.
	 *
	 * @covers WCOR_Mailer::applies_to_wordcamp
	 */
	public function test_not_sent_to_non_wordcamp() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		global $WCOR_Mailer;

		$message  = get_post( self::$not_for_wordcamp_post_id );
		$wordcamp = get_post( self::$wordcamp_dayton_post_id );
		$other    = get_post( self::$other_event_post_id );

		// Test that WordCamp doesn't send.
		$result = $WCOR_Mailer->send_manual_email( $message, $wordcamp );

		// Verify the email wasn't sent.
		$this->assertFalse( $result );
		$this->assertSame( 0, did_action( 'wp_mail_failed' ) );
		$this->assertSame( 0, did_action( 'wp_mail_succeeded' ) );

		// Test that it sends to Other event.
		$result = $WCOR_Mailer->send_manual_email( $message, $other );

		$this->assertTrue( $result );

		$this->assert_mail_succeeded(
			'other@wordcamp.org',
			'This reminder is not for WordCamps',
			"<p>So it should not be sent to WordCamp.</p>\n",
			$result
		);
	}

	/**
	 * Test that HTML content is preserved in emails.
	 *
	 * @covers WCOR_Mailer::mail
	 * @covers WCOR_Mailer::maybe_send_html_email
	 */
	public function test_html_content_preserved() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		global $WCOR_Mailer;

		$html_reminder_id = self::factory()->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => 'HTML Email Test',
				'post_content' => 'Check out this <a href="https://make.wordpress.org/community/">link</a> and this <strong>bold text</strong>.',
			)
		);

		update_post_meta( $html_reminder_id, 'wcor_send_where', 'wcor_send_organizers' );

		$message  = get_post( $html_reminder_id );
		$wordcamp = get_post( self::$wordcamp_dayton_post_id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$result   = $WCOR_Mailer->send_manual_email( $message, $wordcamp );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertTrue( $result );
		$this->assertNotFalse( $mailer->get_sent(), 'No email was sent.' );

		$body = str_replace( "\r\n", "\n", $mailer->get_sent()->body );

		// Verify HTML tags are preserved.
		$this->assertStringContainsString( '<a href="https://make.wordpress.org/community/">link</a>', $body );
		$this->assertStringContainsString( '<strong>bold text</strong>', $body );

		// Verify wpautop added paragraph tags.
		$this->assertStringContainsString( '<p>', $body );
	}

	/**
	 * Test that dangerous HTML is sanitized.
	 *
	 * @covers WCOR_Mailer::mail
	 */
	public function test_html_sanitization() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		global $WCOR_Mailer;

		$dangerous_reminder_id = self::factory()->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => 'Sanitization Test',
				'post_content' => 'This has a <table><tr><td>table</td></tr></table> and <code>code tags</code> and <pre>preformatted text</pre> which should be removed.',
			)
		);

		update_post_meta( $dangerous_reminder_id, 'wcor_send_where', 'wcor_send_organizers' );

		$message  = get_post( $dangerous_reminder_id );
		$wordcamp = get_post( self::$wordcamp_dayton_post_id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$result   = $WCOR_Mailer->send_manual_email( $message, $wordcamp );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertTrue( $result );
		$this->assertNotFalse( $mailer->get_sent(), 'No email was sent.' );

		$body = str_replace( "\r\n", "\n", $mailer->get_sent()->body );

		// Verify email-unsafe tags are removed.
		$this->assertStringNotContainsString( '<table>', $body );
		$this->assertStringNotContainsString( '<code>', $body );
		$this->assertStringNotContainsString( '<pre>', $body );

		// Verify content is still present.
		$this->assertStringContainsString( 'table', $body );
		$this->assertStringContainsString( 'code tags', $body );
		$this->assertStringContainsString( 'preformatted text', $body );
	}

	/**
	 * Test that plain-text fallback is generated.
	 *
	 * @covers WCOR_Mailer::maybe_send_html_email
	 */
	public function test_plain_text_fallback() {
		/** @var WCOR_Mailer $WCOR_Mailer */
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		global $WCOR_Mailer;

		$html_reminder_id = self::factory()->post->create(
			array(
				'post_type'    => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
				'post_title'   => 'Plain Text Fallback Test',
				'post_content' => 'Visit <a href="https://central.wordcamp.org/">WordCamp Central</a> for more info.',
			)
		);

		update_post_meta( $html_reminder_id, 'wcor_send_where', 'wcor_send_organizers' );

		$message  = get_post( $html_reminder_id );
		$wordcamp = get_post( self::$wordcamp_dayton_post_id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$result   = $WCOR_Mailer->send_manual_email( $message, $wordcamp );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertTrue( $result );
		$this->assertNotFalse( $mailer->get_sent(), 'No email was sent.' );

		// Get the MIME body which contains both HTML and plain-text parts.
		$mime_body = str_replace( "\r\n", "\n", $mailer->get_sent()->body );

		// Extract the plain-text part from the MIME body.
		// Look for the plain text section between Content-Type: text/plain and the next boundary.
		preg_match( '/Content-Type: text\/plain.*?\n\n(.*?)\n--/s', $mime_body, $matches );
		$this->assertNotEmpty( $matches, 'Plain-text part not found in MIME body.' );

		$alt_body = isset( $matches[1] ) ? trim( $matches[1] ) : '';

		// Verify plain text version has no HTML tags.
		$this->assertStringNotContainsString( '<a', $alt_body );
		$this->assertStringNotContainsString( '<p>', $alt_body );

		// Verify content is still present.
		$this->assertStringContainsString( 'Visit', $alt_body );
		$this->assertStringContainsString( 'WordCamp Central', $alt_body );

		// Verify the URL is preserved in markdown-style format [text](URL).
		$this->assertStringContainsString( 'https://central.wordcamp.org/', $alt_body );
		$this->assertStringContainsString( '[WordCamp Central](https://central.wordcamp.org/)', $alt_body );
	}
}

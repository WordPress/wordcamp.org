<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Event_Email\fix_event_email_markup;
use function WordCamp\Groups\Frontend\Event_Email\fix_featured_image;
use function WordCamp\Groups\Frontend\Event_Email\add_missing_button;
use function WordCamp\Groups\Frontend\Event_Email\make_button_bulletproof;
use function WordCamp\Groups\Frontend\Event_Email\sanitize_message;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers #2054: GatherPress's event email overlapped its RSVP button in
 * Help Scout and stretched the featured image on phones.
 */
class Test_Groups_Event_Email extends Groups_TestCase {

	/**
	 * GatherPress 0.36.0's rendered markup, copied from the template's
	 * output rather than hand-written. A copy cannot notice the template
	 * drifting -- `Test_Groups_Notifications` renders the real one, and is
	 * what caught 0.36.0 renaming the button's marker comment.
	 *
	 * @return string
	 */
	private function gatherpress_body(): string {
		return '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif;">
		<!-- Featured Image -->
			<img
				src="https://example.org/probe.jpg"
				width="600"
				height="315"
				alt="Event Image"
				style="max-width: 100%; height: auto;"
			/>
		<!-- Event Title -->
		<h1 style="text-align: center;">Markup Probe</h1>

		<!-- RSVP Button: only when registration is still open. -->
			<div style="text-align: center; margin-top: 20px;">
				<a href="https://example.org/event/markup-probe/" style="background-color: #0a58ca; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px; display: inline-block;">
					RSVP Now				</a>
			</div>
		
		<!-- Excerpt -->
		<p style="text-align: left;">An excerpt.</p>
		</body></html>';
	}

	/**
	 * The button's marker as GatherPress 0.35 wrote it, before 0.36.0
	 * appended a note to the comment. Both shapes have to match, because the
	 * code and the plugin version are not deployed in the same instant.
	 *
	 * @return string
	 */
	private function legacy_button_body(): string {
		return '<h1 style="text-align: center;">Markup Probe</h1>
		<!-- RSVP Button -->
		<div style="text-align: center; margin-top: 20px;">
			<a href="https://example.org/event/markup-probe/" style="background-color: #007bff; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">
				RSVP Now			</a>
		</div>';
	}

	/**
	 * The button becomes a table, so a client that ignores padding on an
	 * inline element still draws a filled box instead of overlapping text.
	 */
	public function test_rsvp_button_becomes_a_table() {
		$fixed = make_button_bulletproof( $this->gatherpress_body() );

		$this->assertStringContainsString( '<table role="presentation"', $fixed );
		$this->assertStringContainsString( 'bgcolor="#0a58ca"', $fixed );
		$this->assertStringContainsString( 'line-height: 20px', $fixed );
		$this->assertStringNotContainsString(
			'<a href="https://example.org/event/markup-probe/" style="background-color',
			$fixed,
			'The padded inline anchor is the bug; it should be gone.'
		);
	}

	/**
	 * The link and its label survive the rebuild. A button nobody can
	 * click, or one that reads differently in a translation, would be a
	 * worse bug than the one being fixed.
	 */
	public function test_rebuilt_button_keeps_link_and_label() {
		$fixed = make_button_bulletproof( $this->gatherpress_body() );

		$this->assertStringContainsString( 'href="https://example.org/event/markup-probe/"', $fixed );
		$this->assertStringContainsString( '>RSVP Now</a>', $fixed );
	}

	/**
	 * #2074 removes the button from the organizer's own copy by matching
	 * the marker comment and its wrapping div. Rebuilding the button must
	 * leave that structure intact, or the two fixes fight each other.
	 */
	public function test_rebuilt_button_is_still_strippable() {
		$fixed    = make_button_bulletproof( $this->gatherpress_body() );
		$stripped = \WordCamp\Groups\Frontend\Notifications\strip_rsvp_button( $fixed );

		$this->assertStringNotContainsString( 'RSVP Now', $stripped );
		$this->assertStringContainsString( 'Markup Probe', $stripped, 'Only the button should go.' );
	}

	/**
	 * The pre-0.36.0 marker comment is still recognised, both for the
	 * rebuild and for removing the button from the organizer's copy.
	 */
	public function test_legacy_marker_is_still_recognised() {
		$fixed = make_button_bulletproof( $this->legacy_button_body() );

		$this->assertStringContainsString( '<table role="presentation"', $fixed );
		$this->assertStringNotContainsString(
			'RSVP Now',
			\WordCamp\Groups\Frontend\Notifications\strip_rsvp_button( $fixed )
		);
	}

	/**
	 * A closed event's email has the marker but no button: 0.36.0 leaves the
	 * comment in and skips the `<div>`. Nothing after it may be mistaken for
	 * the button and removed.
	 */
	public function test_marker_without_a_button_is_left_alone() {
		$body = '<!-- RSVP Button: only when registration is still open. -->
		<!-- Excerpt -->
		<div><p>An excerpt.</p></div>';

		$this->assertSame( $body, make_button_bulletproof( $body ) );
		$this->assertSame( $body, \WordCamp\Groups\Frontend\Notifications\strip_rsvp_button( $body ) );
	}

	/**
	 * `wp_get_attachment_image()` writes real width and height attributes,
	 * so capping the width alone squashes the picture on a phone.
	 */
	public function test_featured_image_gets_height_auto() {
		$body  = '<!-- Featured Image -->
			<img width="1200" height="630" src="https://example.org/probe.jpg" class="attachment-full size-full" alt="Event Image" style="max-width: 100%;" decoding="async" loading="lazy" />';
		$fixed = fix_featured_image( $body );

		$this->assertStringContainsString( 'style="max-width: 100%; height: auto;"', $fixed );
	}

	/**
	 * Running twice must not stack a second `height: auto` or a second
	 * table -- mail can pass through the filter more than once.
	 */
	public function test_fixes_are_idempotent() {
		$once  = fix_event_email_markup( array( 'message' => $this->gatherpress_body() ) )['message'];
		$twice = fix_event_email_markup( array( 'message' => $once ) )['message'];

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'height: auto' ) );
		$this->assertSame( 1, substr_count( $twice, '<table role="presentation"' ) );
	}

	/**
	 * An image with no inline style at all still gets constrained, rather
	 * than being left to overflow.
	 */
	public function test_image_without_a_style_attribute_gets_one() {
		$body  = '<!-- Featured Image --><img width="1200" height="630" src="https://example.org/p.jpg" />';
		$fixed = fix_featured_image( $body );

		$this->assertStringContainsString( 'style="max-width: 100%; height: auto;"', $fixed );
	}

	/**
	 * Every other email the site sends goes through this filter untouched.
	 */
	public function test_leaves_unrelated_mail_alone() {
		$atts = array(
			'to'      => 'someone@example.org',
			'subject' => 'Unrelated',
			'message' => '<p>An ordinary email with an <img src="https://example.org/x.jpg" /> and a <a href="https://example.org/">link</a>.</p>',
		);

		$this->assertSame( $atts, fix_event_email_markup( $atts ) );
	}

	/**
	 * A body that no longer looks like the template is returned as-is: if
	 * GatherPress changes shape the email should still send, unmodified,
	 * rather than be mangled by a half-matching pattern.
	 */
	public function test_unrecognized_body_is_returned_unchanged() {
		$body = '<!-- RSVP Button --><p>Something else entirely</p>';

		$this->assertSame( $body, make_button_bulletproof( $body ) );
		$this->assertSame( '', fix_event_email_markup( array( 'message' => '' ) )['message'] );
	}

	/**
	 * The filter is registered, and reaches a real `wp_mail()` call.
	 */
	public function test_filter_is_wired_up_to_wp_mail() {
		$captured = null;

		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) use ( &$captured ) {
				$captured = $atts;

				return true;
			},
			20,
			2
		);

		wp_mail( 'someone@example.org', 'Event', $this->gatherpress_body(), array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_all_filters( 'pre_wp_mail' );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( '<table role="presentation"', $captured['message'] );
		$this->assertStringContainsString( 'height: auto;', $captured['message'] );
	}

	/**
	 * Send GatherPress's own "Message members" email about an event, to one
	 * new member, and return what reached `wp_mail()`.
	 *
	 * @param string $start Event start, site time.
	 * @return array `wp_mail()` arguments for the member's copy.
	 */
	private function send_member_email( string $start ): array {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => $start,
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $start ) + 2 * HOUR_IN_SECONDS ),
				'timezone'       => 'UTC',
			)
		);

		$member_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $member_id, 'subscriber' );

		$sent = array();
		$grab = static function ( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;

			return true;
		};

		add_filter( 'pre_wp_mail', $grab, 10, 2 );

		\GatherPress\Core\Event\Rest_Api::get_instance()->send_emails(
			$event_id,
			array(
				'all'           => true,
				'attending'     => false,
				'waiting_list'  => false,
				'not_attending' => false,
			),
			'Hello'
		);

		remove_filter( 'pre_wp_mail', $grab, 10 );

		$email = get_userdata( $member_id )->user_email;

		foreach ( $sent as $atts ) {
			if ( $email === $atts['to'] ) {
				return $atts + array( 'event_id' => $event_id );
			}
		}

		$this->fail( 'The member did not get the email.' );
	}

	/**
	 * GatherPress 0.36.0 drops the RSVP button once an event has passed, and
	 * with it the email's only link. A series is judged by its first date
	 * here, so this is what every email about a running series looked like.
	 */
	public function test_email_without_rsvp_button_still_links_to_the_event() {
		$mail = $this->send_member_email( '2020-05-20 10:00:00' );

		$this->assertStringNotContainsString( 'RSVP Now', $mail['message'] );
		$this->assertStringContainsString( 'View event', $mail['message'] );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $mail['event_id'] ) ) . '"', $mail['message'] );
	}

	/**
	 * An email that still has its RSVP button gets no second one.
	 */
	public function test_email_with_rsvp_button_gets_no_extra_link() {
		$mail = $this->send_member_email( '2031-05-20 10:00:00' );

		$this->assertStringContainsString( 'RSVP Now', $mail['message'] );
		$this->assertStringNotContainsString( 'View event', $mail['message'] );
	}

	/**
	 * The added button sits where the RSVP button would, so the publish
	 * notification can still take it out of the organizer's own copy.
	 */
	public function test_added_button_is_strippable() {
		$body  = '<h1>Probe</h1><!-- RSVP Button: only when registration is still open. --><!-- Excerpt --><p>An excerpt.</p>';
		$added = add_missing_button( $body, self::factory()->post->create() );

		$this->assertStringContainsString( 'View event', $added );
		$this->assertSame(
			'<h1>Probe</h1><!-- Excerpt --><p>An excerpt.</p>',
			\WordCamp\Groups\Frontend\Notifications\strip_rsvp_button( $added )
		);
	}

	/**
	 * Without a noted event there is nothing to link to, so the body is left
	 * as GatherPress rendered it.
	 */
	public function test_missing_button_needs_an_event() {
		$body = '<!-- RSVP Button: only when registration is still open. --><p>An excerpt.</p>';

		$this->assertSame( $body, add_missing_button( $body, 0 ) );
	}

	/**
	 * Core's text sanitizers delete every `%XX` sequence, which takes the
	 * path out of a link to a page with a non-Latin slug.
	 */
	public function test_message_keeps_percent_encoding() {
		$url = 'https://example.org/%E3%82%A4%E3%83%99%E3%83%B3%E3%83%88/';

		$this->assertSame(
			"See {$url}\nbold &amp; plain",
			sanitize_message( "  See {$url}\n<b>bold</b> & plain  " )
		);
		$this->assertSame( '', sanitize_message( array( 'not a string' ) ) );
	}

	/**
	 * GatherPress's email route runs the message through our sanitizer, all
	 * the way to the cron job that sends it.
	 */
	public function test_email_route_keeps_percent_encoding() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);
		$message  = 'Slides: https://example.org/%D1%81%D0%BB%D0%B0%D0%B9%D0%B4%D1%8B/';

		$request = new \WP_REST_Request( 'POST', '/gatherpress/v1/event/email' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'post_id' => $event_id,
					'message' => $message,
					'send'    => array(
						'all'           => true,
						'attending'     => false,
						'waiting_list'  => false,
						'not_attending' => false,
					),
				)
			)
		);

		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$scheduled = array();
		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks['gatherpress_send_emails'] ?? array() as $job ) {
				$scheduled[] = $job['args'][2];
			}
		}

		$this->assertContains( $message, $scheduled );
	}
}

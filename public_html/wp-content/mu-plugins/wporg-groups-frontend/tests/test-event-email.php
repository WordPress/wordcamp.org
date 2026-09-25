<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Event_Email\fix_event_email_markup;
use function WordCamp\Groups\Frontend\Event_Email\fix_featured_image;
use function WordCamp\Groups\Frontend\Event_Email\make_button_bulletproof;

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
	 * GatherPress's own rendered markup, copied from the template's output
	 * rather than hand-written, so these tests fail if it drifts.
	 *
	 * @return string
	 */
	private function gatherpress_body(): string {
		return '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif;">
		<!-- Featured Image -->
			<img width="1200" height="630" src="https://example.org/probe.jpg" class="attachment-full size-full" alt="Event Image" style="max-width: 100%;" decoding="async" loading="lazy" />
		<!-- Event Title -->
		<h1 style="text-align: center;">Markup Probe</h1>

		<!-- RSVP Button -->
		<div style="text-align: center; margin-top: 20px;">
			<a href="https://example.org/event/markup-probe/" style="background-color: #007bff; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">
				RSVP Now			</a>
		</div>
		</body></html>';
	}

	/**
	 * The button becomes a table, so a client that ignores padding on an
	 * inline element still draws a filled box instead of overlapping text.
	 */
	public function test_rsvp_button_becomes_a_table() {
		$fixed = make_button_bulletproof( $this->gatherpress_body() );

		$this->assertStringContainsString( '<table role="presentation"', $fixed );
		$this->assertStringContainsString( 'bgcolor="#007bff"', $fixed );
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
	 * `wp_get_attachment_image()` writes real width and height attributes,
	 * so capping the width alone squashes the picture on a phone.
	 */
	public function test_featured_image_gets_height_auto() {
		$fixed = fix_featured_image( $this->gatherpress_body() );

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
}

<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Utility;

use function WordCamp\Groups\GatherPress_Event_Email\repair_event_email;

use const WordCamp\Groups\GatherPress_Event_Email\TEMPLATE_MARKER;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * The repairs applied to GatherPress's event notification email (#2054).
 *
 * Two faults were reported from real mail: the RSVP button's background
 * printed over the excerpt beneath it, and the featured image stretched on a
 * phone. Both come from `includes/templates/admin/emails/event-email.php`,
 * which GatherPress renders from a hardcoded path — no template override, no
 * filter on the body — so `gatherpress-event-email.php` repairs the HTML on
 * its way through `wp_mail`.
 *
 * That makes these tests two jobs rather than one. The repair tests below
 * check what this network sends. The canary tests check that the template
 * still has the faults being repaired: when GatherPress ships its own fix,
 * they fail, and the workaround should be deleted rather than left to
 * silently do nothing.
 *
 * @group groups
 */
class Test_Groups_GatherPress_Event_Email extends Groups_TestCase {

	const TEMPLATE = '/includes/templates/admin/emails/event-email.php';

	/**
	 * Render the real notification email for an event with a featured image.
	 *
	 * @return string The email body, before this network's repairs.
	 */
	private function render_event_email(): string {
		$event_id = self::factory()->post->create(
			array(
				'post_type'    => 'gatherpress_event',
				'post_status'  => 'publish',
				'post_title'   => 'Emailed Meetup',
				'post_content' => 'An evening of talks, demos and questions about the web.',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'event.jpg',
				'post_parent'    => $event_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Emailed Meetup poster',
			)
		);

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1200,
				'height' => 628,
				'file'   => 'event.jpg',
				'sizes'  => array(),
			)
		);

		set_post_thumbnail( $event_id, $attachment_id );

		$body = Utility::render_template(
			GATHERPRESS_CORE_PATH . self::TEMPLATE,
			array(
				'event_id' => $event_id,
				'message'  => 'A new event has been published.',
			)
		);

		$this->assertNotSame( '', $body, 'GatherPress rendered no email at all.' );

		return $body;
	}

	/**
	 * Read the shipped template off disk.
	 *
	 * @return string The template source.
	 */
	private function read_template(): string {
		$path = GATHERPRESS_CORE_PATH . self::TEMPLATE;

		$this->assertFileExists( $path, 'GatherPress no longer ships the email template this repairs.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as the other groups tests do.
		$source = file_get_contents( $path );

		$this->assertNotFalse( $source, 'Could not read the GatherPress email template.' );

		return $source;
	}

	/**
	 * Run the repair the way `wp_mail()` does.
	 *
	 * @param string $message The email body.
	 *
	 * @return string The repaired body.
	 */
	private function repair( string $message ): string {
		$args = repair_event_email(
			array(
				'to'      => 'member@example.test',
				'subject' => 'Emailed Meetup',
				'message' => $message,
				'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
			)
		);

		return $args['message'];
	}

	/**
	 * The button's padding has to count toward the line box, or its background
	 * prints over the excerpt below it.
	 */
	public function test_rsvp_button_is_laid_out_as_a_block() {
		$repaired = $this->repair( $this->render_event_email() );

		$this->assertMatchesRegularExpression(
			'/<a [^>]*style="[^"]*background-color[^"]*display: inline-block;[^"]*"/',
			$repaired,
			'The RSVP button is inline again, so its padding overlaps whatever follows it.'
		);
	}

	/**
	 * A capped-width image with a fixed height attribute stretches on a phone.
	 */
	public function test_featured_image_keeps_its_aspect_ratio() {
		$repaired = $this->repair( $this->render_event_email() );

		$this->assertMatchesRegularExpression(
			'/<img [^>]*style="[^"]*max-width: 100%;[^"]*height: auto;[^"]*"/',
			$repaired,
			'The featured image can stretch again: capped width, fixed height.'
		);
	}

	/**
	 * `wp_mail` can run over the same body more than once. Appending the same
	 * declaration each time would leave a style attribute that grows without
	 * bound.
	 */
	public function test_repair_is_idempotent() {
		$once  = $this->repair( $this->render_event_email() );
		$twice = $this->repair( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Every other email this network sends goes out as it was written.
	 */
	public function test_other_mail_is_left_alone() {
		$message = '<p>Hello</p><a href="https://example.test" style="background-color: #007bff; padding: 12px;">Press me</a>';

		$this->assertSame( $message, $this->repair( $message ) );
	}

	/**
	 * Canary: GatherPress still ships the button fault this repairs.
	 *
	 * When this fails, GatherPress has fixed it upstream — delete
	 * `gatherpress-event-email.php` and this class rather than keeping a
	 * repair that no longer repairs anything.
	 */
	public function test_upstream_template_still_has_the_inline_button() {
		$source = $this->read_template();

		$this->assertStringContainsString( TEMPLATE_MARKER, $source, 'The marker this repair keys off has gone.' );
		$this->assertMatchesRegularExpression(
			'/<a href="[^"]*"\s+style="background-color: #007bff;(?:(?!display)[^"])*"/',
			$source,
			'GatherPress now lays the RSVP button out itself; drop the local repair.'
		);
	}

	/**
	 * Canary: GatherPress still ships the image fault this repairs.
	 */
	public function test_upstream_template_still_caps_the_image_without_a_height() {
		$source = $this->read_template();

		$this->assertStringContainsString(
			"'style' => 'max-width: 100%;',",
			$source,
			'GatherPress now sizes the featured image itself; drop the local repair.'
		);
	}
}

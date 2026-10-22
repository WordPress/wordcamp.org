<?php
/**
 * Email-client fixes for GatherPress's event notification emails.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Email;

defined( 'WPINC' ) || die();

/**
 * The marker comments GatherPress's own `event-email.php` template emits.
 *
 * Matching on these is what keeps this filter off every other email the
 * site sends, and what makes it fail safely: if the template changes
 * shape, nothing matches and the email goes out exactly as it does today.
 *
 * The button's marker is a prefix, because GatherPress 0.36.0 appended a
 * note to it (`<!-- RSVP Button: only when registration is still open. -->`)
 * and an exact match silently stopped finding the button.
 * `BUTTON_MARKER_PATTERN` is the whole comment, in either shape.
 */
const IMAGE_MARKER          = '<!-- Featured Image -->';
const BUTTON_MARKER         = '<!-- RSVP Button';
const BUTTON_MARKER_PATTERN = '<!-- RSVP Button\b[^>]*-->';

/**
 * A button email clients actually render.
 *
 * The background and radius move onto the cell, so a client that ignores
 * padding on an inline element still draws a filled box, and the explicit
 * `line-height` gives the padded anchor a box of its own instead of
 * letting it grow over the line above. The colour follows the template's
 * own, which GatherPress 0.36.0 darkened to pass WCAG AA against white.
 *
 * %1$s is the event URL, %2$s the label.
 */
const BUTTON_MARKUP = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto;"><tr><td align="center" bgcolor="#0a58ca" style="background-color: #0a58ca; border-radius: 4px;"><a href="%1$s" style="display: inline-block; padding: 12px 20px; font-family: Arial, sans-serif; font-size: 16px; line-height: 20px; font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 4px;">%2$s</a></td></tr></table>';

/**
 * Register the filters.
 */
function bootstrap(): void {
	add_filter( 'wp_mail', __NAMESPACE__ . '\fix_event_email_markup' );
	add_filter( 'gatherpress_email_subject', __NAMESPACE__ . '\remember_event_id', 10, 2 );
	add_filter( 'rest_endpoints', __NAMESPACE__ . '\keep_message_percent_encoding' );
}

/**
 * Sanitize the email route's message without dropping percent-encoded text.
 *
 * GatherPress 0.36.0 runs the message through `sanitize_textarea_field()`,
 * which removes every `%XX` sequence, so a link to
 * `/%E3%82%A4%E3%83%99%E3%83%B3%E3%83%88/` in "Message members" arrives with
 * its path gone. Tags still go: the template's own `wp_kses()` would drop
 * them anyway.
 *
 * @param array $endpoints Registered REST endpoints.
 * @return array
 */
function keep_message_percent_encoding( array $endpoints ): array {
	$route = '/gatherpress/v1/event/email';

	if ( empty( $endpoints[ $route ] ) ) {
		return $endpoints;
	}

	foreach ( $endpoints[ $route ] as $index => $handler ) {
		if ( isset( $handler['args']['message']['sanitize_callback'] ) ) {
			$endpoints[ $route ][ $index ]['args']['message']['sanitize_callback'] = __NAMESPACE__ . '\sanitize_message';
		}
	}

	return $endpoints;
}

/**
 * Plain text with line breaks and percent-encoding intact.
 *
 * @param mixed $message Submitted message.
 */
function sanitize_message( $message ): string {
	if ( ! is_string( $message ) ) {
		return '';
	}

	return trim( wp_kses( wp_check_invalid_utf8( $message ), array() ) );
}

/**
 * Note which event GatherPress is about to email about.
 *
 * `wp_mail` only sees the rendered body, and the button that carried the
 * event's link may be missing from it. GatherPress applies this filter once
 * per recipient, right before it renders and sends that recipient's copy.
 *
 * @param string $subject Email subject.
 * @param int    $post_id Event post ID.
 * @return string Unchanged subject.
 */
function remember_event_id( $subject, $post_id ) {
	current_event_id( (int) $post_id );

	return $subject;
}

/**
 * The event the next event email is about, as noted by `remember_event_id()`.
 *
 * @param int|null $event_id Event post ID to note, or null to read.
 */
function current_event_id( ?int $event_id = null ): int {
	static $current = 0;

	if ( null !== $event_id ) {
		$current = $event_id;
	}

	return $current;
}

/**
 * Repair the two bits of GatherPress's event email that break in real clients (#2054).
 *
 * The template is GatherPress's, and it is not filterable or
 * theme-overridable -- `Rest_Api::send_event_email_to_recipient()` renders
 * it from a hardcoded path -- so the rendered body is corrected on its way
 * out instead.
 *
 * Runs for every event email, not just the publish notification: the
 * "Message all members" and "Message attendees" actions render the same
 * template and had the same two problems.
 *
 * @param array $atts `wp_mail()` arguments.
 * @return array
 */
function fix_event_email_markup( array $atts ): array {
	$message  = $atts['message'] ?? '';
	$event_id = current_event_id();

	// Each noted ID is for one email only.
	current_event_id( 0 );

	if ( ! is_string( $message ) || '' === $message ) {
		return $atts;
	}

	// Cheap rejection first: this filter sees every email the site sends.
	if ( ! str_contains( $message, IMAGE_MARKER ) && ! str_contains( $message, BUTTON_MARKER ) ) {
		return $atts;
	}

	$atts['message'] = add_missing_button( make_button_bulletproof( fix_featured_image( $message ) ), $event_id );

	return $atts;
}

/**
 * Stop the featured image stretching on narrow screens.
 *
 * `wp_get_attachment_image()` writes real `width` and `height` attributes
 * and the template caps the width at 100%. On a phone the width shrinks
 * and the height attribute does not, so the picture is squashed out of
 * proportion. `height: auto` restores the aspect ratio.
 *
 * @param string $message Rendered email body.
 * @return string
 */
function fix_featured_image( string $message ): string {
	$fixed = preg_replace_callback(
		'#(' . preg_quote( IMAGE_MARKER, '#' ) . '\s*<img\b)([^>]*?)(/?>)#s',
		static function ( array $matches ): string {
			$attributes = $matches[2];

			if ( str_contains( $attributes, 'height: auto' ) ) {
				return $matches[0];
			}

			$styled = preg_replace( '#(\sstyle=")([^"]*?)\s*"#', '$1$2 height: auto;"', $attributes, 1, $count );

			// No inline style to extend: give it one rather than leaving
			// the image unconstrained.
			$attributes = $count ? $styled : $attributes . ' style="max-width: 100%; height: auto;"';

			return $matches[1] . $attributes . $matches[3];
		},
		$message,
		1
	);

	return null === $fixed ? $message : $fixed;
}

/**
 * Rebuild the RSVP button as a table.
 *
 * The template's button is an inline `<a>` carrying its own padding and
 * background. Clients that do not honour padding on an inline element --
 * Help Scout in the report, Outlook generally -- draw it overlapping the
 * text above it.
 *
 * The marker comment and the wrapping `<div>` are deliberately preserved:
 * they are what the publish notification matches on when it removes the
 * button from the organizer's own copy (#2074).
 *
 * @param string $message Rendered email body.
 * @return string
 */
function make_button_bulletproof( string $message ): string {
	$fixed = preg_replace_callback(
		'#(' . BUTTON_MARKER_PATTERN . '\s*<div\b[^>]*>)\s*<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>\s*(</div>)#s',
		static function ( array $matches ): string {
			// The label is already escaped -- it comes from the template's
			// own `esc_html_e()` -- so re-escaping here would double-encode
			// an apostrophe in a translation.
			$button = sprintf( BUTTON_MARKUP, esc_url( $matches[2] ), trim( $matches[3] ) );

			return $matches[1] . $button . $matches[4];
		},
		$message,
		1
	);

	return null === $fixed ? $message : $fixed;
}

/**
 * Link to the event when GatherPress left the RSVP button out.
 *
 * Since 0.36.0 the template shows the button only while the event hasn't
 * passed and RSVP is open, and the button is the email's only link to the
 * event. Emails are sent outside any request for a date, so a recurring
 * series is judged by its first date: once that has passed, every
 * "Message members" email about the series went out with no link at all.
 *
 * The button goes back under the same marker and wrapper, so the publish
 * notification can still strip it from the organizer's own copy.
 *
 * @param string $message  Rendered email body.
 * @param int    $event_id Event the email is about, or 0 when unknown.
 * @return string
 */
function add_missing_button( string $message, int $event_id ): string {
	if ( ! $event_id || preg_match( '#' . BUTTON_MARKER_PATTERN . '\s*<div\b#', $message ) ) {
		return $message;
	}

	$url = get_permalink( $event_id );

	if ( ! $url ) {
		return $message;
	}

	$button = sprintf( BUTTON_MARKUP, esc_url( $url ), esc_html__( 'View event', 'wordcamporg' ) );

	$fixed = preg_replace_callback(
		'#' . BUTTON_MARKER_PATTERN . '#',
		static fn( array $matches ): string => $matches[0] . '<div style="text-align: center; margin-top: 20px;">' . $button . '</div>',
		$message,
		1
	);

	return null === $fixed ? $message : $fixed;
}

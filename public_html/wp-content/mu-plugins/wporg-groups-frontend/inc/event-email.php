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
 */
const IMAGE_MARKER  = '<!-- Featured Image -->';
const BUTTON_MARKER = '<!-- RSVP Button -->';

/**
 * A button email clients actually render.
 *
 * The background and radius move onto the cell, so a client that ignores
 * padding on an inline element still draws a filled box, and the explicit
 * `line-height` gives the padded anchor a box of its own instead of
 * letting it grow over the line above.
 *
 * %1$s is the event URL, %2$s the label.
 */
const BUTTON_MARKUP = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto;"><tr><td align="center" bgcolor="#007bff" style="background-color: #007bff; border-radius: 4px;"><a href="%1$s" style="display: inline-block; padding: 12px 20px; font-family: Arial, sans-serif; font-size: 16px; line-height: 20px; font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 4px;">%2$s</a></td></tr></table>';

/**
 * Register the filter.
 */
function bootstrap(): void {
	add_filter( 'wp_mail', __NAMESPACE__ . '\fix_event_email_markup' );
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
	$message = $atts['message'] ?? '';

	if ( ! is_string( $message ) || '' === $message ) {
		return $atts;
	}

	// Cheap rejection first: this filter sees every email the site sends.
	if ( ! str_contains( $message, IMAGE_MARKER ) && ! str_contains( $message, BUTTON_MARKER ) ) {
		return $atts;
	}

	$atts['message'] = make_button_bulletproof( fix_featured_image( $message ) );

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
		'#(' . preg_quote( BUTTON_MARKER, '#' ) . '\s*<div\b[^>]*>)\s*<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>\s*(</div>)#s',
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

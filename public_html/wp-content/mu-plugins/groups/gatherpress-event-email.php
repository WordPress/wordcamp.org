<?php
/**
 * Repairs to GatherPress's event notification email.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\GatherPress_Event_Email;

defined( 'WPINC' ) || die();

/**
 * A comment unique to the template being repaired.
 *
 * Used to recognise the email rather than guessing from its content, so no
 * other mail this network sends is rewritten. See
 * `includes/templates/admin/emails/event-email.php` in GatherPress.
 */
const TEMPLATE_MARKER = '<!-- RSVP Button -->';

/**
 * Fix the RSVP button and the featured image in GatherPress's event email.
 *
 * Both faults were reported from real mail (#2054), and both come from the
 * same template, which GatherPress renders from a hardcoded path — there is
 * no template override and no filter on the rendered body, so the repair
 * happens on the way out through `wp_mail`.
 *
 *   - The RSVP button is an `<a>` with vertical padding and no `display`.
 *     Padding on an inline box doesn't push the following block out of the
 *     way, so the button's blue background printed over the excerpt beneath
 *     it. `inline-block` makes the padding count toward the line box. This is
 *     what GatherPress's own RSVP-token email already does to the same kind
 *     of button; only this template was missed.
 *   - The featured image carries `max-width: 100%` and the `width`/`height`
 *     attributes of the full-size file. Narrow the viewport and the width
 *     obeys the cap while the height stays where the attribute put it, so
 *     the picture stretches. `height: auto` restores the ratio.
 *
 * Worth sending upstream. Until that lands and this network runs a GatherPress
 * carrying it, `Test_Groups_GatherPress_Event_Email` fails loudly if the
 * template stops matching what this repairs, rather than letting the fix
 * silently stop applying.
 *
 * @param array $args The `wp_mail()` arguments.
 *
 * @return array The arguments, with the message repaired where it applies.
 */
function repair_event_email( array $args ): array {
	$message = $args['message'] ?? '';

	if ( ! is_string( $message ) || ! str_contains( $message, TEMPLATE_MARKER ) ) {
		return $args;
	}

	$args['message'] = add_inline_block_to_button( add_intrinsic_height_to_images( $message ) );

	return $args;
}
add_filter( 'wp_mail', __NAMESPACE__ . '\repair_event_email' );

/**
 * Let a capped-width image keep its aspect ratio.
 *
 * @param string $message The email body.
 *
 * @return string The body, with `height: auto` on every capped image.
 */
function add_intrinsic_height_to_images( string $message ): string {
	$processor = new \WP_HTML_Tag_Processor( $message );

	while ( $processor->next_tag( 'img' ) ) {
		$style = $processor->get_attribute( 'style' );

		// Only images the template caps, and only ones not already sized —
		// `wp_mail` can run more than once over the same body when a plugin
		// re-sends it, and this must not append twice.
		if ( ! is_string( $style ) || ! str_contains( $style, 'max-width' ) || str_contains( $style, 'height' ) ) {
			continue;
		}

		$processor->set_attribute( 'style', append_declaration( $style, 'height: auto' ) );
	}

	return $processor->get_updated_html();
}

/**
 * Make the RSVP button's padding count toward the line box.
 *
 * @param string $message The email body.
 *
 * @return string The body, with the button laid out as a block.
 */
function add_inline_block_to_button( string $message ): string {
	$processor = new \WP_HTML_Tag_Processor( $message );

	while ( $processor->next_tag( 'a' ) ) {
		$style = $processor->get_attribute( 'style' );

		// The button is the only filled link in this template; a plain link
		// has no background to overlap anything with.
		if ( ! is_string( $style ) || ! str_contains( $style, 'background-color' ) || str_contains( $style, 'display' ) ) {
			continue;
		}

		$processor->set_attribute( 'style', append_declaration( $style, 'display: inline-block' ) );
	}

	return $processor->get_updated_html();
}

/**
 * Add one declaration to an inline style, however the existing one is punctuated.
 *
 * @param string $style       The existing `style` attribute.
 * @param string $declaration The declaration to add, without a trailing semicolon.
 *
 * @return string The combined style.
 */
function append_declaration( string $style, string $declaration ): string {
	return rtrim( trim( $style ), ';' ) . '; ' . $declaration . ';';
}

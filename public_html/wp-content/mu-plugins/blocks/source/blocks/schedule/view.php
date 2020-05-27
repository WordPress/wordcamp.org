<?php

namespace WordCamp\Blocks\Schedule;

defined( 'WPINC' ) || die();

/**
 * @var array $attributes
 *
 * @todo If needed for SEO, or as a fallback for runtime JS errors, this could output a simple text version of
 * the schedule data.
 */

?>

<div
	class="wp-block-wordcamp-schedule align<?php echo esc_attr( $attributes['align'] ); ?> is-loading"
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- todo add to custom escaping functions. ?>
	data-attributes="<?php echo wcorg_json_encode_attr_i18n( $attributes ); ?>"
>
	<?php esc_html_e( 'Loading...', 'wordcamporg' ); ?>
</div>

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- todo add to custom escaping functions.
echo fav_session_share_form();

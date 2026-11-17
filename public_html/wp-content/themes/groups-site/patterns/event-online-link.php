<?php
/**
 * Title: Event - online event link
 * Slug: groups-site/event-online-link
 * Categories: groups-site
 * Inserter: no
 *
 * The online event link in the single event info card. Its link text and
 * the attendees-only tooltip are a block attribute, so the block is
 * rendered from a pattern to make them translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventOnlineLink;

defined( 'ABSPATH' ) || exit;

$attributes = array(
	'fontSize' => 'small',
	'linkText' => sprintf(
		'<span class="gatherpress-tooltip" data-gatherpress-tooltip="%1$s">%2$s</span>',
		esc_attr__( 'Link available for attendees only.', 'wordcamporg' ),
		esc_html__( 'Online event', 'wordcamporg' )
	),
);
?>
<!-- wp:gatherpress/online-event-link <?php echo serialize_block_attributes( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> /-->

<?php
/**
 * Title: Archive - events search
 * Slug: groups-site/archive-events-search
 * Categories: groups-site
 * Inserter: no
 *
 * The search field above the events archive. Its placeholder and button
 * text are block attributes, so the block is rendered from a pattern to
 * make them translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ArchiveEventsSearch;

defined( 'ABSPATH' ) || exit;

$attributes = array(
	'showLabel'      => false,
	'placeholder'    => __( 'Search events…', 'wordcamporg' ),
	'width'          => 100,
	'widthUnit'      => '%',
	'buttonText'     => __( 'Search', 'wordcamporg' ),
	'buttonPosition' => 'button-inside',
	'buttonUseIcon'  => true,
	'className'      => 'is-style-secondary-search-control',
);
?>
<!-- wp:search <?php echo serialize_block_attributes( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> /-->

<?php
/**
 * Title: Search result excerpt
 * Slug: groups-site/search-result-excerpt
 * Categories: groups-site
 * Inserter: no
 *
 * The excerpt shown for each search result. Its "Continue reading" link
 * text is a block attribute, so the block is rendered from a pattern to
 * make it translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\SearchResultExcerpt;

defined( 'ABSPATH' ) || exit;

$attributes = array(
	'moreText' => __( 'Continue reading', 'wordcamporg' ),
);
?>
<!-- wp:post-excerpt <?php echo serialize_block_attributes( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> /-->

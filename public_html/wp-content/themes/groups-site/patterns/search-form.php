<?php
/**
 * Title: Search form
 * Slug: groups-site/search-form
 * Categories: groups-site
 * Inserter: no
 *
 * The search field on the search results page. Its label, placeholder, and
 * button text are block attributes, so the block is rendered from a pattern
 * to make them translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\SearchForm;

defined( 'ABSPATH' ) || exit;

$attributes = array(
	'label'       => __( 'Search', 'wordcamporg' ),
	'showLabel'   => false,
	'placeholder' => __( 'Search this group…', 'wordcamporg' ),
	'buttonText'  => __( 'Search', 'wordcamporg' ),
	'style'       => array(
		'spacing' => array(
			'margin' => array(
				'top' => 'var:preset|spacing|30',
			),
		),
	),
);
?>
<!-- wp:search <?php echo serialize_block_attributes( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> /-->

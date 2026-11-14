<?php
/**
 * Title: Front page - about this group
 * Slug: groups-site/front-page-about
 * Categories: groups-site
 * Inserter: no
 *
 * The front page excerpt of the group's About page. Its heading is a block
 * attribute, so the block is rendered from a pattern to make that heading
 * translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\FrontPageAbout;

defined( 'ABSPATH' ) || exit;

$attributes = array(
	'slug'         => 'about',
	'heading'      => __( 'About this group', 'wordcamporg' ),
	'headingLevel' => 2,
	'excerpt'      => true,
	'className'    => 'groups-site-about',
	'lock'         => array(
		'move'   => true,
		'remove' => true,
	),
);
?>
<!-- wp:wporg/page-content <?php echo serialize_block_attributes( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> /-->

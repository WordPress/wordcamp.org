<?php
/**
 * Block registration for the groups frontend mu-plugin.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Blocks;

use GatherPress\Core\Blocks\Setup as Blocks_Setup;
use GatherPress\Core\Rsvp\Rsvp;

defined( 'WPINC' ) || die();

/**
 * Bootstrap block registration.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\register_blocks' );
	add_filter( 'render_block_gatherpress/rsvp-count', __NAMESPACE__ . '\hide_empty_rsvp_count', 10, 2 );
}

/**
 * Register all blocks provided by this mu-plugin.
 */
function register_blocks(): void {
	$blocks = array(
		'event-rsvp',
		'event-manage',
		'group-settings',
		'group-location',
		'group-membership',
		'group-news',
		'group-members',
		'event-language',
		'event-hosts',
		'event-flyer-link',
		'event-flyer-qr',
		'event-topics',
		'event-speakers',
		'event-attendees',
		'my-events',
		'page-content',
		'sponsors',
	);

	foreach ( $blocks as $block ) {
		$block_dir  = dirname( __DIR__ ) . '/build/blocks/' . $block;
		$block_type = register_block_type_from_metadata( $block_dir );

		if ( $block_type instanceof \WP_Block_Type ) {
			set_script_translations( $block_type );
			version_styles( $block_type, $block_dir );
		}
	}
}

/**
 * Version one block's stylesheets by when each file last changed.
 *
 * Core versions a block's scripts by the content hash in their
 * `*.asset.php`, but its stylesheets by the `version` in `block.json`, which
 * nobody bumps. Every stylesheet was served as `?ver=1.0.0` under a long
 * browser cache, so after a deploy a returning organizer got the new markup
 * with the old CSS: the "Edit this event" form lost its padding and label
 * styles (#2153).
 *
 * @param \WP_Block_Type $block_type The block that was just registered.
 * @param string         $block_dir  The block's build directory.
 */
function version_styles( \WP_Block_Type $block_type, string $block_dir ): void {
	$handles = array_merge(
		(array) $block_type->editor_style_handles,
		(array) $block_type->style_handles,
		(array) $block_type->view_style_handles
	);

	foreach ( array_unique( $handles ) as $handle ) {
		$style = wp_styles()->query( $handle, 'registered' );

		if ( ! $style || ! is_string( $style->src ) ) {
			continue;
		}

		$file = $block_dir . '/' . wp_basename( (string) wp_parse_url( $style->src, PHP_URL_PATH ) );

		if ( is_readable( $file ) ) {
			$style->ver = (string) filemtime( $file );
		}
	}
}

/**
 * Point one block's scripts at the `wordcamporg` translations.
 *
 * Registering a block doesn't tell WordPress where its script's strings are
 * translated, so `__()` in our JS returned English whatever the visitor's
 * language was. The PHP half of the same blocks was already translatable, so
 * a settings panel could come out half in one language and half in another.
 *
 * Driven off the block type we just registered rather than off the registry,
 * because the registry also holds `wporg/*` blocks from `wporg-mu-plugins`,
 * whose strings belong to their own domain.
 *
 * `event-rsvp` renders through a `viewScriptModule`, which this does not
 * cover -- script modules have no i18n API yet. It doesn't need one: its
 * labels are resolved in PHP and handed over through the block's context,
 * which `src/blocks/event-rsvp/view.js` documents as the reason it works
 * that way.
 *
 * @param \WP_Block_Type $block_type The block that was just registered.
 */
function set_script_translations( \WP_Block_Type $block_type ): void {
	$handles = array_merge(
		(array) $block_type->editor_script_handles,
		(array) $block_type->script_handles,
		(array) $block_type->view_script_handles
	);

	foreach ( array_unique( $handles ) as $handle ) {
		wp_set_script_translations( $handle, 'wordcamporg' );
	}
}

/**
 * Hide the core `gatherpress/rsvp-count` block when its resolved count is 0.
 *
 * GatherPress's own block always renders (e.g. "0 Attendees"); our templates
 * rely on the block disappearing entirely for events with no RSVPs yet, so
 * we suppress the empty case here rather than reintroducing a duplicate
 * `event-rsvp-count` block.
 */
function hide_empty_rsvp_count( ?string $block_content, ?array $block ): ?string {
	if ( is_null( $block_content ) || is_null( $block )
		|| ! class_exists( Rsvp::class ) || ! class_exists( Blocks_Setup::class )
	) {
		return $block_content;
	}

	$post_id = Blocks_Setup::get_instance()->get_post_id( $block );
	if ( ! $post_id ) {
		return $block_content;
	}

	$status    = $block['attrs']['status'] ?? 'attending';
	$responses = ( new Rsvp( $post_id ) )->responses();
	$count     = (int) ( $responses[ $status ]['count'] ?? 0 );

	return $count > 0 ? $block_content : '';
}

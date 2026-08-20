<?php
/**
 * Groups Site theme functions.
 *
 * Default theme for individual WordPress Group sites on events.wordpress.org.
 * Designed to pair with the GatherPress plugin (events, RSVPs, venues).
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/event-cards.php';

/**
 * Theme support.
 */
function setup() {
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'automatic-feed-links' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\setup' );

/**
 * Enqueue theme stylesheets.
 *
 * Loads the theme's own CSS on top of the parent (`wporg-parent-2021`) sheet
 * and the wporg global font stack, mirroring the pattern in
 * `wporg-events-2023`.
 */
function enqueue_assets() {
	wp_enqueue_style(
		'groups-site-custom',
		get_theme_file_uri( 'assets/css/custom.css' ),
		array_filter( array(
			wp_style_is( 'wporg-parent-2021-style', 'registered' ) ? 'wporg-parent-2021-style' : '',
			wp_style_is( 'wporg-global-fonts', 'registered' ) ? 'wporg-global-fonts' : '',
		) ),
		filemtime( get_theme_file_path( 'assets/css/custom.css' ) )
	);

	wp_enqueue_style(
		'groups-site-responsive',
		get_theme_file_uri( 'assets/css/responsive.css' ),
		array( 'groups-site-custom' ),
		filemtime( get_theme_file_path( 'assets/css/responsive.css' ) )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_assets' );

/**
 * Adjust the featured image inside event card grids.
 *
 * Two changes, both scoped so the single-event hero is left alone. The guard
 * tests `is_singular( 'gatherpress_event' )` rather than a bare
 * `is_singular()`: the group front page is a static Page, so it is singular
 * too, and the broader test excluded the very cards this is meant to fix.
 *
 *   - Drop the image link from the keyboard tab order. The title link already
 *     provides keyboard access to the event, so a second focusable link on
 *     the image is redundant and slows tab navigation.
 *   - Substitute a placeholder when the event has no thumbnail. Core renders
 *     the block as an empty string in that case, so one image-less event in a
 *     row of three used to start its date where its neighbours started their
 *     image — the cards shared a grid row but no baseline. The placeholder
 *     holds the same 16:9 region; `custom.css` fills it with flat
 *     Blueberry 4 rather than invented artwork.
 */
add_filter(
	'render_block_core/post-featured-image',
	static function ( string $content, array $block ): string {
		$post_type = $block['context']['postType'] ?? get_post_type();

		if ( 'gatherpress_event' !== $post_type || is_singular( 'gatherpress_event' ) ) {
			return $content;
		}

		if ( '' === trim( $content ) ) {
			return '<div class="wp-block-post-featured-image groups-site-featured-placeholder" aria-hidden="true"></div>';
		}

		return str_replace( '<a href=', '<a tabindex="-1" href=', $content );
	},
	10,
	2
);

/**
 * Describe the events archive's view state on `<body>` so the stylesheet can
 * react to it.
 *
 * Adds `groups-site-events-view-{upcoming|past|all}`, mirroring the Time
 * filter. Past events shouldn't wear the same blue "coming up" date
 * treatment as upcoming ones, and the query loop itself has no idea which
 * mode it rendered in.
 */
function event_archive_body_classes( array $classes ): array {
	if ( ! is_post_type_archive( 'gatherpress_event' ) ) {
		return $classes;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	$time = isset( $_GET['event_time'] ) ? sanitize_key( wp_unslash( $_GET['event_time'] ) ) : 'upcoming';
	if ( ! in_array( $time, array( 'upcoming', 'past', 'all' ), true ) ) {
		$time = 'upcoming';
	}

	$classes[] = 'groups-site-events-view-' . $time;

	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\event_archive_body_classes' );

/**
 * Register a block pattern category for the theme.
 */
function register_pattern_category() {
	register_block_pattern_category(
		'groups-site',
		array(
			'label' => __( 'Groups Site', 'groups-site' ),
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_pattern_category' );

/**
 * Trim the auto-generated page list in the theme's primary navigation.
 *
 * Hides:
 *   - The static front page (`page_on_front`). The theme renders it from
 *     a "Home" page so the page would otherwise appear in the nav as a
 *     redundant entry — clicking the site title already lands you there.
 *   - Any page named "Leave Feedback" / slug `feedback`. WordCamp.org
 *     auto-creates this on every subsite at provisioning time as a way
 *     to collect feedback during a camp; on a Group site it's not
 *     useful and just clutters the nav.
 *
 * Filters `get_pages` (used by the `core/page-list` block) rather than
 * `wp_list_pages_excludes` (used by the legacy `wp_list_pages()`).
 *
 * @param \WP_Post[]|false $pages List of page objects, false on early bail.
 * @return \WP_Post[]|false
 */
function filter_nav_page_list( $pages ) {
	if ( ! is_array( $pages ) ) {
		return $pages;
	}

	$front_id = (int) get_option( 'page_on_front' );

	return array_values(
		array_filter(
			$pages,
			static function ( $page ) use ( $front_id ) {
				if ( $front_id && (int) $page->ID === $front_id ) {
					return false;
				}
				if ( 'feedback' === $page->post_name || 'Leave Feedback' === $page->post_title ) {
					return false;
				}
				return true;
			}
		)
	);
}
add_filter( 'get_pages', __NAMESPACE__ . '\filter_nav_page_list' );

/**
 * Inject the theme's custom GatherPress templates into the template hierarchy.
 *
 * The templates are registered via `customTemplates` in `theme.json` so they're
 * pickable in the editor, but `customTemplates` doesn't auto-apply them. This
 * filter prepends the matching template slug to the hierarchy so a fresh
 * `gatherpress_event` / `gatherpress_venue` post picks up `single-event` /
 * `single-venue` without anyone having to set it by hand.
 *
 * Note: the archive template uses the standard slug `archive-gatherpress_event`
 * which WordPress resolves automatically for block themes.
 */
function single_template_hierarchy( $templates ) {
	$post_type = get_post_type();
	if ( 'gatherpress_event' === $post_type ) {
		array_unshift( $templates, 'single-event' );
	} elseif ( 'gatherpress_venue' === $post_type ) {
		array_unshift( $templates, 'single-venue' );
	}
	return $templates;
}
add_filter( 'single_template_hierarchy', __NAMESPACE__ . '\single_template_hierarchy' );


/**
 * Strip GatherPress metadata blocks from `the_content` on the single-event view.
 *
 * GatherPress seeds new events with starter blocks (event-date, venue, RSVP,
 * add-to-calendar, etc.) baked into `post_content`. The `single-event.html`
 * template renders those exact same blocks in the sidebar info card, so we
 * end up with each one twice. Strip the metadata blocks here so `post-content`
 * only renders the user's actual description prose.
 */
function strip_event_metadata_blocks( $content ) {
	if ( ! is_singular( 'gatherpress_event' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// Only strip the static metadata blocks we re-render in the sidebar info
	// card. Leave `gatherpress/rsvp` and `gatherpress/rsvp-response` in place:
	// those are inner-block wrappers (save: <InnerBlocks.Content />) and only
	// render when their inner blocks are present in `post_content`. They're
	// part of the default GatherPress event template, and need to live in the
	// main column where users interact with them.
	$strip = array(
		'gatherpress/event-date',
		'gatherpress/venue',
		'gatherpress/add-to-calendar',
		'gatherpress/online-event',
	);

	$blocks = parse_blocks( $content );
	$kept   = array_filter(
		$blocks,
		static function ( $block ) use ( $strip ) {
			return ! in_array( $block['blockName'], $strip, true );
		}
	);

	return serialize_blocks( $kept );
}
add_filter( 'the_content', __NAMESPACE__ . '\strip_event_metadata_blocks', 5 );

/**
 * Reshape the comment form into a compact "leave a reply" composer.
 *
 * Drops the "Leave a Reply" heading, the "Logged in as…" boilerplate, and the
 * notes-before/after copy. Replaces the comment field with a placeholder
 * textarea so it reads like a meetup-style discussion box. Only applies on
 * `gatherpress_event` singulars so we don't change comment forms elsewhere.
 */
function event_comment_form_defaults( $defaults ) {
	if ( ! is_singular( 'gatherpress_event' ) ) {
		return $defaults;
	}

	$defaults['title_reply']          = '';
	$defaults['title_reply_to']       = '';
	$defaults['title_reply_before']   = '';
	$defaults['title_reply_after']    = '';
	$defaults['comment_notes_before'] = '';
	$defaults['comment_notes_after']  = '';
	$defaults['logged_in_as']         = '';
	$defaults['label_submit']         = __( 'Post comment', 'groups-site' );
	$defaults['class_submit']         = 'submit wp-element-button';

	$defaults['comment_field'] = sprintf(
		'<p class="comment-form-comment"><label class="screen-reader-text" for="comment">%1$s</label><textarea id="comment" name="comment" cols="45" rows="3" maxlength="65525" required placeholder="%2$s"></textarea></p>',
		esc_html__( 'Comment', 'groups-site' ),
		esc_attr__( 'Add a comment&hellip;', 'groups-site' )
	);

	return $defaults;
}
add_filter( 'comment_form_defaults', __NAMESPACE__ . '\event_comment_form_defaults' );

/**
 * Change "Reply" link text to "Reply" (keeping it, but ensuring the cancel
 * text also says the right thing). Override the default "Leave a Reply" title.
 */
function event_comment_reply_link_args( $args ) {
	if ( is_singular( 'gatherpress_event' ) ) {
		$args['reply_text']  = __( 'Reply', 'groups-site' );
		$args['login_text']  = __( 'Log in to comment', 'groups-site' );
	}
	return $args;
}
add_filter( 'comment_reply_link_args', __NAMESPACE__ . '\event_comment_reply_link_args' );

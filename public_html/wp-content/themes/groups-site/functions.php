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
 *
 * @param string $content The rendered featured-image block.
 * @param array  $block   The parsed block, including its context.
 */
function filter_event_card_featured_image( string $content, array $block ): string {
	$post_type = $block['context']['postType'] ?? get_post_type();

	if ( 'gatherpress_event' !== $post_type || is_singular( 'gatherpress_event' ) ) {
		return $content;
	}

	if ( '' === trim( $content ) ) {
		return '<div class="wp-block-post-featured-image groups-site-featured-placeholder" aria-hidden="true"></div>';
	}

	return str_replace( '<a href=', '<a tabindex="-1" href=', $content );
}
add_filter( 'render_block_core/post-featured-image', __NAMESPACE__ . '\filter_event_card_featured_image', 10, 2 );

/**
 * Prime the featured-image cache for the theme's event card grids.
 *
 * `core/post-template` primes it itself — but only after
 * `block_core_post_template_uses_featured_image()` finds a literal
 * `core/post-featured-image` among its *parsed* inner blocks. The event
 * grids on the front page and the events archive reference their card
 * through `wp:pattern`, which core only expands at render time, so that
 * scan sees a lone `core/pattern` and skips the priming. Every card then
 * resolves its own thumbnail: one `get_post()` for the attachment plus one
 * `wp_get_attachment_metadata()` lookup, up to twelve cards per archive
 * page.
 *
 * `loop_start` fires from the first `the_post()` of the loop, before any
 * card renders, which is exactly where core would have primed. Scoped to
 * secondary `gatherpress_event` queries: the main query is primed by
 * whichever template renders it, and no other loop on these sites draws
 * card thumbnails.
 *
 * @param \WP_Query $query The query starting its loop.
 */
function prime_event_card_thumbnails( $query ) {
	if ( ! $query instanceof \WP_Query || $query->is_main_query() ) {
		return;
	}

	$post_types = (array) $query->get( 'post_type' );

	if ( array( 'gatherpress_event' ) !== $post_types ) {
		return;
	}

	update_post_thumbnail_cache( $query );
}
add_action( 'loop_start', __NAMESPACE__ . '\prime_event_card_thumbnails' );

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
 * Let the `post-author-name` block render just the name.
 *
 * The parent theme prefixes every instance with "By " (see
 * `render_author_prefix()` in its `inc/gutenberg-tweaks.php`). Both uses in
 * this theme supply their own context — the event hero writes "Hosted by"
 * ahead of the block, and the news byline pairs the name with the author's
 * avatar — so the prefix only produces "Hosted by By admin".
 *
 * Removed on `after_setup_theme` because the child theme's `functions.php`
 * loads first: at parse time the parent hasn't added the filter yet.
 */
function remove_parent_author_prefix() {
	remove_filter(
		'render_block_core/post-author-name',
		'WordPressdotorg\Theme\Parent_2021\Gutenberg_Tweaks\render_author_prefix',
		10
	);
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\remove_parent_author_prefix', 11 );

/**
 * Point author links at WordPress.org profiles.
 *
 * The single-event hero credits the event's author as its host through the
 * `post-author-name` block, whose link targets the local author archive — a
 * view these sites don't offer. People are always linked to their
 * WordPress.org profile here (speaker cards, the member directory), so send
 * author links to the same place.
 *
 * @param string $link             The author archive URL.
 * @param int    $author_id        The author's user ID.
 * @param string $author_nicename  The author's nicename (profile slug).
 */
function author_profile_link( $link, $author_id, $author_nicename ) {
	return sprintf( 'https://profiles.wordpress.org/%s/', $author_nicename );
}
add_filter( 'author_link', __NAMESPACE__ . '\author_profile_link', 10, 3 );

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
 * Supply the menu items for the header's local navigation bar.
 *
 * The `header-local-navigation` pattern renders a
 * `core/navigation {"menuSlug":"local-navigation"}` block, which the wporg
 * navigation extension (`wporg-mu-plugins/pub-sync/blocks/navigation`)
 * resolves through this filter — hardcoded items, no per-site nav menu to
 * provision.
 *
 * Built at render time, so the account item can react to the visitor:
 * logged-out visitors — the ones served from the page cache — all get the
 * same "Log in" link, while logged-in views bypass the cache, so the
 * per-user nonce in the logout URL is safe.
 *
 * @param array $menus Menus keyed by slug, each an array of label/url items.
 * @return array
 */
function add_local_navigation_menus( $menus ) {
	global $wp;

	// Return the visitor to the page they logged in or out from.
	// `$wp->request` is the current path relative to the site's home, so
	// this stays correct on path-based multisite; it's empty in the admin,
	// where the fallback is harmless.
	$current_url = home_url( empty( $wp->request ) ? '/' : trailingslashit( $wp->request ) );

	$menus['local-navigation'] = array(
		array(
			'label' => __( 'All Events', 'groups-site' ),
			'url'   => get_post_type_archive_link( 'gatherpress_event' ) ?: home_url( '/event/' ),
		),
		is_user_logged_in()
			? array(
				'label' => __( 'Log out', 'groups-site' ),
				'url'   => wp_logout_url( $current_url ),
			)
			: array(
				'label' => __( 'Log in', 'groups-site' ),
				'url'   => wp_login_url( $current_url ),
			),
	);

	return $menus;
}
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_local_navigation_menus' );

/**
 * Correct the breadcrumb trail for views the block can't infer.
 *
 * The `header-local-navigation` pattern renders a `wporg/site-breadcrumbs`
 * block in the bar's left slot. Its default trail is "{site} / {the title}",
 * which covers singular views — event, page, news post, venue — but the
 * group's other views need help: `get_the_title()` on the events archive
 * yields the first event in the loop, and the block's stock labels
 * ("Archives", "Results") don't match the h1s our templates render.
 *
 * On the group's front page the trail collapses to just the group name,
 * unlinked: it's the current page, not a route back to somewhere else.
 *
 * @param array $breadcrumbs Crumbs as [url => string|false, title => string];
 *                           a crumb without a URL renders as the current page.
 * @return array
 */
function filter_site_breadcrumbs( $breadcrumbs ) {
	if ( is_front_page() ) {
		return array(
			array(
				'url'   => false,
				'title' => get_bloginfo( 'name', 'display' ),
			),
		);
	}

	$title = '';

	if ( is_post_type_archive( 'gatherpress_event' ) ) {
		$title = __( 'Events', 'groups-site' );
	} elseif ( is_home() ) {
		$title = __( 'Latest posts', 'groups-site' );
	} elseif ( is_search() ) {
		$title = __( 'Search results', 'groups-site' );
	} elseif ( is_404() ) {
		$title = __( 'Page not found', 'groups-site' );
	}

	if ( $title ) {
		$breadcrumbs[ array_key_last( $breadcrumbs ) ]['title'] = $title;
	}

	return $breadcrumbs;
}
add_filter( 'wporg_block_site_breadcrumbs', __NAMESPACE__ . '\filter_site_breadcrumbs' );

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
 * Post types whose singular view uses the compact discussion composer.
 *
 * `single-event.html` and `single.html` render the same "Discussion" section
 * and share one block of comment styling, so both need the same form
 * defaults. See `compact_comment_form_defaults()` for what a post type left
 * off this list gets instead.
 */
const COMPACT_COMMENT_FORM_POST_TYPES = array( 'gatherpress_event', 'post' );

/**
 * Whether the current request is a singular view that uses that composer.
 */
function has_compact_comment_form(): bool {
	return is_singular( COMPACT_COMMENT_FORM_POST_TYPES );
}

/**
 * Reshape the comment form into a compact "leave a reply" composer.
 *
 * Drops the "Leave a Reply" heading, the "Logged in as…" boilerplate, and the
 * notes-before/after copy. Replaces the comment field with a placeholder
 * textarea so it reads like a meetup-style discussion box.
 *
 * Emptying `title_reply_before`/`title_reply_after` also takes the
 * `#reply-title` wrapper out of the markup, which is load-bearing: core nests
 * `#cancel-comment-reply-link` inside that wrapper and `custom.css` hides it,
 * so without this the Cancel reply link is unreachable and a reader who clicks
 * Reply can't get back to writing a top-level comment.
 */
function compact_comment_form_defaults( $defaults ) {
	if ( ! has_compact_comment_form() ) {
		return $defaults;
	}

	$defaults['title_reply']          = '';
	$defaults['title_reply_to']       = '';
	$defaults['title_reply_before']   = '';
	$defaults['title_reply_after']    = '';
	$defaults['comment_notes_before'] = '';
	$defaults['comment_notes_after']  = '';
	$defaults['logged_in_as']         = '';

	// Core wraps the Cancel reply link in a `<small>`. Dropping the wrapper
	// leaves the link a direct child of `#respond`, so while it's hidden it
	// generates no line box above the composer — and `custom.css` sizes it
	// rather than the browser's `<small>` default.
	$defaults['cancel_reply_before'] = '';
	$defaults['cancel_reply_after']  = '';

	$defaults['label_submit'] = __( 'Post comment', 'groups-site' );
	$defaults['class_submit'] = 'submit wp-element-button';

	$defaults['comment_field'] = sprintf(
		'<p class="comment-form-comment"><label class="screen-reader-text" for="comment">%1$s</label><textarea id="comment" name="comment" cols="45" rows="3" maxlength="65525" required placeholder="%2$s"></textarea></p>',
		esc_html__( 'Comment', 'groups-site' ),
		esc_attr__( 'Add a comment&hellip;', 'groups-site' )
	);

	return $defaults;
}
add_filter( 'comment_form_defaults', __NAMESPACE__ . '\compact_comment_form_defaults' );

/**
 * Say "Reply" and "Log in to comment" on the singulars that use the compact
 * composer, rather than core's longer defaults.
 */
function compact_comment_reply_link_args( $args ) {
	if ( has_compact_comment_form() ) {
		$args['reply_text'] = __( 'Reply', 'groups-site' );
		$args['login_text'] = __( 'Log in to comment', 'groups-site' );
	}
	return $args;
}
add_filter( 'comment_reply_link_args', __NAMESPACE__ . '\compact_comment_reply_link_args' );

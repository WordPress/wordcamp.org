<?php
/**
 * Global sponsors for the groups network.
 *
 * Sponsors are network-level data: the same list is shown on every group
 * site, so it can't live in a per-site table the way events, venues and
 * members do. The store is a `wporg_sponsor` post type on the events root
 * site (`EVENTS_ROOT_BLOG_ID`), edited in wp-admin there by network admins,
 * and read at render time by every group site via `switch_to_blog()`.
 * Nothing is copied into the group sites, so there is exactly one copy of
 * each sponsor to keep up to date (cf. the `multi-event-sponsors` plugin on
 * central.wordcamp.org, which forks its posts out to each camp site and then
 * has to push edits back over them).
 *
 * The events root rather than the groups network root, which would otherwise
 * be the obvious home, because sponsor logos are attachments and have to be
 * publicly fetchable: this network serves uploads through ms-files rewriting,
 * and `sunrise-groups.php` bounces every non-admin request for the groups
 * root site to the events root — including its own `/group/files/…` uploads,
 * which 302 away instead of serving. `events.wordpress.org/files/…` serves
 * normally, so the logos load with no rewrite or redirect changes needed.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Sponsors;

use WP_Post;

defined( 'WPINC' ) || die();

/**
 * The sponsor post type. Registered on every group site (so `get_post_type_object()`
 * resolves when the block reads the store site's posts) but only editable on the
 * store site — see `register_post_type()` below.
 */
const POST_TYPE = 'wporg_sponsor';

/**
 * Post meta holding the sponsor's website URL.
 */
const URL_META_KEY = '_wporg_sponsor_url';

/**
 * Transient caching the render-ready sponsor list.
 *
 * Lives on the store site itself, not on the sites that read it: the store is
 * on the events network while the group sites are on the groups network, so a
 * *network*-scoped transient written when a sponsor is saved would never be
 * the one a group site reads. Invalidated whenever a sponsor changes; the
 * expiry is only a backstop for edits this plugin doesn't see (e.g. a direct
 * database change, or an attachment being edited).
 */
const CACHE_KEY = 'wporg_groups_sponsors';

/**
 * How long a non-empty sponsor list stays cached, in seconds.
 */
const CACHE_TTL = HOUR_IN_SECONDS;

/**
 * How long an *empty* sponsor list stays cached, in seconds.
 *
 * Empty results are cached, because "no sponsors yet" is the normal state of
 * a group site and leaving it uncached would mean a `switch_to_blog()` and a
 * query on every page view of every group site. But an empty result is also
 * what a failure looks like — a database blip, a stray `pre_get_posts`
 * filter, `EVENTS_ROOT_BLOG_ID` pointing at a site that no longer exists —
 * and those are indistinguishable from the real thing here. A shorter expiry
 * keeps the common path cached while bounding how long a bad read can hide
 * every sponsor network-wide.
 */
const EMPTY_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

/**
 * Bootstrap the sponsors feature.
 *
 * Runs on both the groups network (where the block reads sponsors) and the
 * events network (where they're edited — see
 * `mu-plugins/events/wporg-groups-sponsors.php`). Deliberately called outside
 * the main plugin's GatherPress guard: sponsors don't touch events, and the
 * store site doesn't run GatherPress at all.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\register_post_type' );
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\add_meta_boxes' );
	add_action( 'save_post_' . POST_TYPE, __NAMESPACE__ . '\save_sponsor_url' );
	add_action( 'admin_notices', __NAMESPACE__ . '\render_invalid_url_notice' );

	// Keep the cached list honest.
	add_action( 'save_post_' . POST_TYPE, __NAMESPACE__ . '\flush_cache' );
	add_action( 'deleted_post', __NAMESPACE__ . '\flush_cache_for_post', 10, 2 );
	add_action( 'trashed_post', __NAMESPACE__ . '\flush_cache_for_post' );
	add_action( 'untrashed_post', __NAMESPACE__ . '\flush_cache_for_post' );
}

/**
 * The blog ID that stores the sponsors — see the file header for why it's
 * this site.
 *
 * Returns 0 when the constant isn't defined (an environment that predates
 * it), which callers treat as "no sponsors".
 */
function get_store_blog_id(): int {
	return defined( 'EVENTS_ROOT_BLOG_ID' ) ? (int) EVENTS_ROOT_BLOG_ID : 0;
}

/**
 * Run a callback in the store site's context.
 *
 * @param callable $callback Receives no arguments; its return value is passed through.
 * @return mixed The callback's return value, or null when there's no store site.
 */
function with_store_site( callable $callback ) {
	$store_blog_id = get_store_blog_id();

	if ( ! $store_blog_id ) {
		return null;
	}

	$switched = get_current_blog_id() !== $store_blog_id;

	if ( $switched ) {
		switch_to_blog( $store_blog_id );
	}

	try {
		return $callback();
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Register the sponsor post type.
 *
 * The admin UI is only exposed on the store site, so a group organiser never
 * sees the post type on their own site, and every capability maps to
 * `manage_network` so only network admins can add, edit or delete sponsors
 * even if they somehow reach the screen.
 */
function register_post_type(): void {
	$is_store = get_current_blog_id() === get_store_blog_id();

	// Every primitive capability the post type can ask for, all mapped to
	// `manage_network`. `map_meta_cap` then resolves `edit_post`/`delete_post`
	// through these, so there's no route to editing a sponsor without being a
	// network admin.
	$network_admin_only = array_fill_keys(
		array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',
			'create_posts',
		),
		'manage_network'
	);

	\register_post_type(
		POST_TYPE,
		array(
			'labels'          => array(
				'name'               => __( 'Sponsors', 'wporg-groups-frontend' ),
				'singular_name'      => __( 'Sponsor', 'wporg-groups-frontend' ),
				'add_new_item'       => __( 'Add New Sponsor', 'wporg-groups-frontend' ),
				'edit_item'          => __( 'Edit Sponsor', 'wporg-groups-frontend' ),
				'new_item'           => __( 'New Sponsor', 'wporg-groups-frontend' ),
				'view_item'          => __( 'View Sponsor', 'wporg-groups-frontend' ),
				'search_items'       => __( 'Search Sponsors', 'wporg-groups-frontend' ),
				'not_found'          => __( 'No sponsors found', 'wporg-groups-frontend' ),
				'not_found_in_trash' => __( 'No sponsors found in Trash', 'wporg-groups-frontend' ),
				'all_items'          => __( 'Sponsors', 'wporg-groups-frontend' ),
				'menu_name'          => __( 'Sponsors', 'wporg-groups-frontend' ),
			),
			// Sponsors have no pages of their own — they're rendered by the
			// `wporg/sponsors` block and link out to the sponsor's own site.
			'public'          => false,
			'show_ui'         => $is_store,
			'show_in_menu'    => $is_store,
			'show_in_rest'    => $is_store,
			'menu_icon'       => 'dashicons-awards',
			'menu_position'   => 25,
			'hierarchical'    => false,
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
			'map_meta_cap'    => true,
			'capability_type' => POST_TYPE,
			'capabilities'    => $network_admin_only,
			// `page-attributes` gives admins an explicit sponsor order via
			// `menu_order`; `excerpt` is the short description in the block.
			'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ),
		)
	);

	register_post_meta(
		POST_TYPE,
		URL_META_KEY,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_url',
			'show_in_rest'      => $is_store,
			'auth_callback'     => function () {
				return current_user_can( 'manage_network' );
			},
		)
	);
}

/**
 * Add the sponsor website meta box.
 */
function add_meta_boxes(): void {
	add_meta_box(
		'wporg-sponsor-url',
		__( 'Sponsor Website', 'wporg-groups-frontend' ),
		__NAMESPACE__ . '\render_url_meta_box',
		POST_TYPE,
		'side'
	);
}

/**
 * Render the sponsor website meta box.
 *
 * @param WP_Post $post The sponsor being edited.
 */
function render_url_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'wporg_sponsor_url', 'wporg_sponsor_url_nonce' );
	$url = (string) get_post_meta( $post->ID, URL_META_KEY, true );
	?>
	<p>
		<label class="screen-reader-text" for="wporg-sponsor-url-field">
			<?php esc_html_e( 'Sponsor website URL', 'wporg-groups-frontend' ); ?>
		</label>
		<input
			type="url"
			class="widefat"
			id="wporg-sponsor-url-field"
			name="wporg_sponsor_url"
			value="<?php echo esc_attr( $url ); ?>"
			placeholder="https://example.org/"
		/>
	</p>
	<p class="description">
		<?php esc_html_e( 'Where the sponsor card links to. Leave empty to render the sponsor without a link.', 'wporg-groups-frontend' ); ?>
	</p>
	<?php
}

/**
 * Persist the sponsor website URL.
 *
 * @param int $post_id The sponsor being saved.
 */
function save_sponsor_url( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// The block editor saves meta through the REST API, so an absent field
	// here means "this request isn't the meta box" rather than "clear it".
	if ( ! isset( $_POST['wporg_sponsor_url'] ) ) {
		return;
	}

	// Sanitised with `sanitize_text_field()` rather than `sanitize_key()`: the
	// latter lowercases, which only happens to be harmless because
	// `wp_create_nonce()` returns lowercase hex from `wp_hash()`. No reason to
	// depend on that.
	$nonce = isset( $_POST['wporg_sponsor_url_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['wporg_sponsor_url_nonce'] ) )
		: '';

	if ( ! wp_verify_nonce( $nonce, 'wporg_sponsor_url' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw = trim( (string) wp_unslash( $_POST['wporg_sponsor_url'] ) );
	$url = sanitize_url( $raw );

	if ( '' === $raw ) {
		delete_post_meta( $post_id, URL_META_KEY );

		return;
	}

	// `sanitize_url()` returns '' for anything it can't parse — a mistyped
	// scheme (`htps://…`), a disallowed one (`javascript:…`) — which is
	// indistinguishable from the field having been cleared. Deleting on that
	// would let a typo silently wipe a working sponsor link, so keep what's
	// stored and tell whoever saved it that their input didn't take.
	if ( '' === $url ) {
		set_transient( invalid_url_notice_key( $post_id ), $raw, MINUTE_IN_SECONDS );

		return;
	}

	update_post_meta( $post_id, URL_META_KEY, $url );
}

/**
 * Transient key for the "that URL wasn't usable" notice.
 *
 * Scoped to the user as well as the post so a notice raised by one editor
 * isn't shown to — or swallowed by — another editing the same sponsor.
 *
 * @param int $post_id The sponsor being edited.
 */
function invalid_url_notice_key( int $post_id ): string {
	return sprintf( 'wporg_sponsor_url_invalid_%d_%d', $post_id, get_current_user_id() );
}

/**
 * Show the notice raised by `save_sponsor_url()` for an unusable URL.
 */
function render_invalid_url_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen || POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
		return;
	}

	$post_id = (int) get_the_ID();
	$key     = invalid_url_notice_key( $post_id );
	$raw     = get_transient( $key );

	if ( false === $raw ) {
		return;
	}

	delete_transient( $key );

	wp_admin_notice(
		sprintf(
			/* translators: %s: the URL the user entered. */
			esc_html__( '%s isn\'t a usable web address, so the sponsor\'s existing link was kept. Include a full address, e.g. https://example.org/.', 'wporg-groups-frontend' ),
			'<code>' . esc_html( $raw ) . '</code>'
		),
		array(
			'type'               => 'warning',
			'additional_classes' => array( 'is-dismissible' ),
		)
	);
}

/**
 * Drop the cached sponsor list.
 */
function flush_cache(): void {
	with_store_site(
		function () {
			delete_transient( CACHE_KEY );
		}
	);
}

/**
 * Drop the cached sponsor list when a sponsor post is trashed or deleted.
 *
 * @param int          $post_id The post being removed.
 * @param WP_Post|null $post    The post object, when the hook passes one.
 */
function flush_cache_for_post( int $post_id, ?WP_Post $post = null ): void {
	$post_type = $post ? $post->post_type : get_post_type( $post_id );

	if ( POST_TYPE === $post_type ) {
		flush_cache();
	}
}

/**
 * Get the network's sponsors, ready for rendering.
 *
 * Each entry is a flat array — `name`, `description`, `url`, `logo`,
 * `logo_width`, `logo_height` — rather than a `WP_Post`, because the posts
 * belong to another site: passing them back would leave callers calling
 * `get_the_title()` and friends in the wrong blog context. Resolving
 * everything here, inside the `switch_to_blog()`, keeps that boundary in one
 * place and makes the result cacheable as-is.
 *
 * @return array<int, array<string, mixed>> Sponsors in display order.
 */
function get_sponsors(): array {
	$sponsors = with_store_site(
		function () {
			$cached = get_transient( CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$posts = get_posts(
				array(
					'post_type'        => POST_TYPE,
					'post_status'      => 'publish',
					'numberposts'      => 100,
					'orderby'          => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'suppress_filters' => false,
				)
			);

			$sponsors = array_map( __NAMESPACE__ . '\prepare_sponsor', $posts );

			set_transient(
				CACHE_KEY,
				$sponsors,
				$sponsors ? CACHE_TTL : EMPTY_CACHE_TTL
			);

			return $sponsors;
		}
	);

	return is_array( $sponsors ) ? $sponsors : array();
}

/**
 * Repair an attachment URL resolved while `switch_to_blog()` is active.
 *
 * On a network with ms-files rewriting — which this one uses, so uploads live
 * at `…/group/files/…` rather than `wp-content/uploads/…` — `_wp_upload_dir()`
 * deliberately ignores the `UPLOADS` constant whenever the site is switched,
 * because `ms_upload_constants()` hardcodes that constant to the blog the
 * request started on. The upshot is that every sponsor logo we resolve from a
 * group site comes back pointing at a path that doesn't exist, and 404s.
 *
 * The base URL core would have produced if we weren't switched is
 * `siteurl` + `/files`, and `get_option()` is already blog-scoped, so it can
 * be rebuilt from inside the switch. The conditions below mirror
 * `_wp_upload_dir()` so this only rewrites URLs core actually got wrong:
 * with rewriting disabled it appends `/sites/{id}` using the *switched* blog
 * ID, which is correct as-is.
 *
 * @param string $url An attachment URL resolved in the store site's context.
 * @return string The URL a visitor can actually load.
 */
function correct_switched_upload_url( string $url ): string {
	if ( ! upload_url_needs_correcting( $url ) ) {
		return $url;
	}

	$uploads = wp_get_upload_dir();

	return rebase_url(
		$url,
		$uploads['baseurl'],
		trailingslashit( get_option( 'siteurl' ) ) . 'files'
	);
}

/**
 * Whether `correct_switched_upload_url()` has anything to fix.
 *
 * Split out from the rewriting itself so the rewriting can be tested without
 * a live ms-files network: the conditions here are all process-level state
 * (`UPLOADS`, the switch stack) that a test can't reasonably fake.
 *
 * @param string $url The URL about to be rewritten.
 */
function upload_url_needs_correcting( string $url ): bool {
	if ( '' === $url || ! ms_is_switched() || ! defined( 'UPLOADS' ) ) {
		return false;
	}

	if ( ! get_site_option( 'ms_files_rewriting' ) ) {
		return false;
	}

	// Mirrors `_wp_upload_dir()`: the main site of the main network keeps
	// using `wp-content/uploads`, so its URLs are already right.
	return ! ( is_main_network() && is_main_site() && defined( 'MULTISITE' ) );
}

/**
 * Swap the upload base URL at the front of an attachment URL.
 *
 * @param string $url       The attachment URL.
 * @param string $from_base The base URL the URL currently carries.
 * @param string $to_base   The base URL it should carry.
 * @return string The rebased URL, or the original when it wasn't under `$from_base`.
 */
function rebase_url( string $url, string $from_base, string $to_base ): string {
	$from_base = untrailingslashit( $from_base );

	if ( '' === $from_base || ! str_starts_with( $url, $from_base ) ) {
		return $url;
	}

	return untrailingslashit( $to_base ) . substr( $url, strlen( $from_base ) );
}

/**
 * Flatten a sponsor post into the array the block renders from.
 *
 * Must be called in the store site's context — see `get_sponsors()`.
 *
 * @param WP_Post $post A `wporg_sponsor` post.
 * @return array<string, mixed>
 */
function prepare_sponsor( WP_Post $post ): array {
	$description = $post->post_excerpt
		? $post->post_excerpt
		: wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

	$logo        = '';
	$logo_width  = 0;
	$logo_height = 0;
	$thumbnail   = get_post_thumbnail_id( $post );

	if ( $thumbnail ) {
		$image = wp_get_attachment_image_src( $thumbnail, 'medium' );

		if ( $image ) {
			list( $logo, $logo_width, $logo_height ) = $image;
			$logo                                    = correct_switched_upload_url( $logo );
		}
	}

	return array(
		'name'        => $post->post_title,
		'description' => trim( wp_trim_words( $description, 20, "\u{2026}" ) ),
		'url'         => (string) get_post_meta( $post->ID, URL_META_KEY, true ),
		'logo'        => (string) $logo,
		'logo_width'  => (int) $logo_width,
		'logo_height' => (int) $logo_height,
	);
}

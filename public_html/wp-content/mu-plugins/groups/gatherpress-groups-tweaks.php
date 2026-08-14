<?php
/**
 * GatherPress tweaks for WordPress Group sites.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\GatherPress_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Check whether the current request URI is the GatherPress event archive.
 *
 * @return bool
 */
function is_event_archive_request_uri(): bool {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$archive_url = get_post_type_archive_link( 'gatherpress_event' );
	if ( ! $archive_url ) {
		return false;
	}

	$archive_path = wp_parse_url( $archive_url, PHP_URL_PATH );
	$request_uri  = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( ! $archive_path || ! $request_path ) {
		return false;
	}

	return trailingslashit( $archive_path ) === trailingslashit( $request_path );
}

/**
 * Resolve the GatherPress venue post assigned to an event.
 *
 * @param int $event_id Event post ID.
 * @return int Venue post ID, or 0 when no venue post can be resolved.
 */
function get_event_venue_post_id( int $event_id ): int {
	$terms = wp_get_object_terms(
		$event_id,
		\GatherPress\Core\Venue\Venue::TAXONOMY,
		array( 'fields' => 'all' )
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return 0;
	}

	$venue_slug = ltrim( $terms[0]->slug, '_' );
	if ( '' === $venue_slug ) {
		return 0;
	}

	$venue_post = get_page_by_path( $venue_slug, OBJECT, \GatherPress\Core\Venue\Venue::POST_TYPE );

	return $venue_post ? (int) $venue_post->ID : 0;
}

/**
 * Disable the "Show Timezone" GatherPress setting so event date blocks
 * never append "GMT+0000" or similar suffixes.
 *
 * Also disable anonymous RSVP and Open RSVP at the global setting level.
 *
 * Uses `option_`/`default_option_` (not `pre_option_`) so the forced keys
 * overlay the stored settings (or defaults when unset) instead of replacing them.
 */
$force_gatherpress_settings = static function ( $value ) {
	if ( ! is_array( $value ) ) {
		$value = array();
	}

	$value['show_timezone']         = 0;
	$value['enable_anonymous_rsvp'] = 0;
	$value['enable_open_rsvp']      = 0;

	return $value;
};
add_filter( 'option_gatherpress_settings', $force_gatherpress_settings );
add_filter( 'default_option_gatherpress_settings', $force_gatherpress_settings );

/**
 * Force anonymous RSVP off for all events on group sites.
 *
 * GatherPress checks `get_post_meta( $id, 'gatherpress_enable_anonymous_rsvp', true )`
 * to decide whether to show the anonymous checkbox. Returning a non-null value
 * from `get_post_metadata` short-circuits the real lookup; wrapping in an array
 * mirrors what WP would return for a single meta value of empty-string.
 */
add_filter(
	'get_post_metadata',
	static function ( $value, $object_id, $meta_key ) {
		if ( 'gatherpress_enable_anonymous_rsvp' === $meta_key ) {
			return array( '' );
		}

		return $value;
	},
	10,
	3
);

/**
 * Override the default Gravatar type for RSVP avatars.
 *
 * GatherPress hardcodes 'mystery' as the default and bakes it into the
 * URL via get_avatar_url(). This filter runs after (priority 20) and
 * rewrites the d= parameter in the already-built URL.
 */
add_filter(
	'get_avatar_data',
	static function ( array $args ): array {
		$default = get_option( 'avatar_default', 'wavatar' );

		if ( ! empty( $args['url'] ) && str_contains( $args['url'], 'd=mm' ) ) {
			$args['url'] = str_replace( 'd=mm', 'd=' . rawurlencode( $default ), $args['url'] );
		}

		if ( isset( $args['default'] ) && 'mystery' === $args['default'] ) {
			$args['default'] = $default;
		}

		return $args;
	},
	20
);

/**
 * Require login to post comments (Discussion section) on group sites.
 */
add_filter(
	'pre_option_comment_registration',
	static function () {
		return '1';
	}
);

/**
 * Grant edit_theme_options to editors so they can use the Site Editor
 * to customise their group site appearance (templates, colors, etc.).
 */
add_filter(
	'user_has_cap',
	static function ( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! in_array( 'edit_theme_options', $caps, true ) ) {
			return $allcaps;
		}

		// Grant to editors (group organisers).
		if ( ! empty( $allcaps['edit_others_posts'] ) ) {
			$allcaps['edit_theme_options'] = true;
		}

		return $allcaps;
	},
	10,
	4
);

/**
 * When searching on the events archive, keep the archive template active
 * instead of switching to the search template. WordPress treats ?s= as a
 * search query which loads search.html — we want to stay on the archive
 * so the Query Loop handles the filtering.
 */
add_action(
	'pre_get_posts',
	static function ( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$post_type          = $query->get( 'post_type' );
		$is_event_post_type = 'gatherpress_event' === $post_type
			|| ( is_array( $post_type ) && in_array( 'gatherpress_event', $post_type, true ) );

		// If this is a search on the events archive path, force it back to archive.
		if ( $query->is_search() && ( isset( $_GET['event_time'] ) || $is_event_post_type || is_event_archive_request_uri() ) ) {
			$query->is_search            = false;
			$query->is_archive           = true;
			$query->is_post_type_archive = true;
			$query->set( 'post_type', 'gatherpress_event' );
		}
	},
	1
);

/**
 * Register event speakers post meta.
 *
 * Stores an array of user IDs who are speaking at the event.
 */
function register_event_speakers_meta(): void {
	register_post_meta(
		'gatherpress_event',
		'_event_speakers',
		array(
			'type'          => 'array',
			'single'        => true,
			'default'       => array(),
			'show_in_rest'  => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
			'auth_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_event_speakers_meta' );

/**
 * Rewrite the search block form action on the events archive to submit
 * to the archive URL instead of the default search URL, so search results
 * stay scoped to events.
 */
add_filter(
	'render_block_core/search',
	static function ( string $content ): string {
		if ( ! is_post_type_archive( 'gatherpress_event' ) ) {
			return $content;
		}

		$archive_url = get_post_type_archive_link( 'gatherpress_event' );
		$content     = preg_replace(
			'/action="[^"]*"/',
			'action="' . esc_url( $archive_url ) . '"',
			$content
		);

		// Add hidden field to default to "all" time when searching.
		$content = str_replace(
			'</form>',
			'<input type="hidden" name="event_time" value="all" /></form>',
			$content
		);

		return $content;
	}
);

/**
 * Register query filter options for event archive filtering.
 */
add_filter(
	'wporg_query_filter_options_event_time',
	static function (): array {
		$current = isset( $_GET['event_time'] ) ? sanitize_text_field( wp_unslash( $_GET['event_time'] ) ) : 'upcoming';

		$selected = array();
		if ( $current && 'upcoming' !== $current ) {
			$selected[] = $current;
		}

		return array(
			'label'    => __( 'Time', 'wporg-groups-frontend' ),
			'title'    => __( 'Filter by time', 'wporg-groups-frontend' ),
			'key'      => 'event_time',
			'action'   => get_post_type_archive_link( 'gatherpress_event' ),
			'options'  => array(
				'upcoming' => __( 'Upcoming', 'wporg-groups-frontend' ),
				'past'     => __( 'Past', 'wporg-groups-frontend' ),
				'all'      => __( 'All events', 'wporg-groups-frontend' ),
			),
			'selected' => $selected,
		);
	}
);

/**
 * Add event_time as an allowed query var.
 */
add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = 'event_time';
		return $vars;
	}
);

/**
 * Apply the event archive controls to GatherPress Event Query Loop blocks.
 *
 * GatherPress supplies the SQL for upcoming and past event queries. This
 * filter only maps the archive's event_time control and search field onto
 * the native query arguments.
 */
add_filter(
	'query_loop_block_query_vars',
	static function ( array $query_vars, \WP_Block $block ): array {
		$block_query = $block->context['query'] ?? array();

		if (
			! isset( $block_query['gatherpress_event_query'] ) ||
			! isset( $query_vars['post_type'] ) ||
			'gatherpress_event' !== $query_vars['post_type']
		) {
			return $query_vars;
		}

		$time_filter = isset( $_GET['event_time'] ) ? sanitize_text_field( wp_unslash( $_GET['event_time'] ) ) : 'upcoming';

		if ( 'past' === $time_filter ) {
			$query_vars['gatherpress_event_query'] = 'past';
			$query_vars['include_unfinished']      = 0;
			$query_vars['order']                   = 'DESC';
		} elseif ( 'all' === $time_filter ) {
			unset( $query_vars['gatherpress_event_query'] );

			// GatherPress's datetime ordering is tied to its upcoming/past
			// filters, so use a stable core ordering when showing both.
			$query_vars['orderby'] = 'date';
			$query_vars['order']   = 'DESC';
		}

		// Pass through search if present.
		if ( ! empty( $_GET['s'] ) ) {
			$query_vars['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}

		return $query_vars;
	},
	20,
	2
);

/**
 * Prevent GatherPress from 404-ing the event archive.
 *
 * GatherPress expects a WordPress page with the event rewrite slug to be
 * configured as an archive page. Without it, handle_event_archive_redirect()
 * sets a 404. We use a block theme template instead, so remove the redirect.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( ! class_exists( '\GatherPress\Core\Event\Setup' ) ) {
			return;
		}
		$setup = \GatherPress\Core\Event\Setup::get_instance();
		remove_action( 'template_redirect', array( $setup, 'handle_event_archive_redirect' ) );
	},
	1
);

/**
 * Append venue description and access requirements to the venue block output.
 *
 * GatherPress venue blocks only render address, phone, and website. This
 * filter adds the venue post content (description) and the
 * accessRequirements field from the venue information meta.
 *
 * Priority 20: GatherPress\Core\Blocks\Venue::render_block() hooks this same
 * filter at the default priority 10 and rebuilds $content from scratch
 * (ignoring whatever was passed in), discarding anything appended by a
 * same-priority callback registered earlier. Because mu-plugins load before
 * regular plugins, our default-priority add_filter() call was always first
 * in the queue, so GatherPress's callback ran after us and silently dropped
 * this append. Running after it (priority 20) is the only way our content
 * survives.
 */
add_filter(
	'render_block_gatherpress/venue',
	static function ( string $content ): string {
		// Venue posts store their content with a nested `wp:gatherpress/venue`
		// wrapper (GatherPress's default seeded content). Calling `do_blocks()`
		// on that content re-triggers this filter → infinite recursion → OOM.
		// Guard with a static flag so we only ever run the filter body once
		// per outermost render.
		static $rendering = false;
		if ( $rendering ) {
			return $content;
		}

		if ( ! is_singular( 'gatherpress_event' ) ) {
			return $content;
		}

		$event_id = get_the_ID();
		if ( ! $event_id ) {
			return $content;
		}

		$venue_id = get_event_venue_post_id( $event_id );
		if ( ! $venue_id ) {
			return $content;
		}

		$venue_desc  = get_post_field( 'post_content', $venue_id );
		$access      = get_post_meta( $venue_id, 'gatherpress_access_requirements', true );

		$extra = '';

		if ( $venue_desc ) {
			$rendering = true;
			$plain     = wp_strip_all_tags( do_blocks( $venue_desc ) );
			$rendering = false;
			$plain     = trim( $plain );
			if ( $plain ) {
				$extra .= '<p class="wporg-venue-description">' . esc_html( $plain ) . '</p>';
			}
		}

		if ( $access ) {
			$extra .= '<p class="wporg-venue-access"><strong>'
				. esc_html__( 'Access:', 'wporg-groups-frontend' ) . '</strong> '
				. esc_html( $access ) . '</p>';
		}

		if ( $extra ) {
			$content .= '<div class="wporg-venue-extra">' . $extra . '</div>';
		}

		return $content;
	},
	20
);

/**
 * Make the gatherpress_venue post type non-public so it has no front-end
 * archive or singular URLs. Venues are only used as metadata on events.
 */
add_filter(
	'register_post_type_args',
	static function ( array $args, string $post_type ): array {
		if ( 'gatherpress_venue' === $post_type ) {
			$args['public']             = false;
			$args['publicly_queryable'] = false;
			$args['has_archive']        = false;
		}

		return $args;
	},
	10,
	2
);

/**
 * Require an editing capability to read venues over the REST API.
 *
 * Making the post type non-public closes the front-end routes, but it does
 * not close the REST ones: WP_REST_Posts_Controller gates anonymous reads on
 * `show_in_rest` alone, and grants them for any `publish` post. GatherPress
 * needs `show_in_rest` for block-editor venue authoring, so it cannot simply
 * be turned off, and the collection endpoint otherwise hands out venue
 * addresses, descriptions and meta to unauthenticated callers.
 *
 * Wrap the read handlers' permission callbacks rather than replacing them,
 * so the controller's own checks still run for anyone who passes this gate.
 * Front-end venue output is server-rendered (see the
 * `render_block_gatherpress/venue` filter above) and the event form's venue
 * list comes from `wporg-groups/v1`, so neither depends on these routes.
 */
add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		$post_type = get_post_type_object( 'gatherpress_venue' );

		if ( ! $post_type || empty( $post_type->show_in_rest ) ) {
			return $endpoints;
		}

		$base   = '/' . ( $post_type->rest_namespace ?: 'wp/v2' ) . '/' . ( $post_type->rest_base ?: $post_type->name );
		$routes = array_filter(
			array_keys( $endpoints ),
			static function ( string $route ) use ( $base ): bool {
				return $route === $base || str_starts_with( $route, $base . '/' );
			}
		);

		foreach ( $routes as $route ) {
			foreach ( $endpoints[ $route ] as $index => $handler ) {
				$methods = $handler['methods'] ?? array();

				if ( is_string( $methods ) ) {
					$methods = array_fill_keys( array_map( 'trim', explode( ',', $methods ) ), true );
				}

				// Writes already require capabilities; only reads are open.
				if ( empty( $methods['GET'] ) ) {
					continue;
				}

				$original = $handler['permission_callback'] ?? null;

				$endpoints[ $route ][ $index ]['permission_callback'] = static function ( $request ) use ( $original ) {
					if ( ! current_user_can( 'edit_posts' ) ) {
						return new \WP_Error(
							'rest_forbidden',
							__( 'Sorry, you are not allowed to view venues.', 'wporg-groups-frontend' ),
							array( 'status' => rest_authorization_required_code() )
						);
					}

					return is_callable( $original ) ? $original( $request ) : true;
				};
			}
		}

		return $endpoints;
	}
);

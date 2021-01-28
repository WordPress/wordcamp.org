<?php
/**
 * WordCamp Central Functions
 *
 * (Almost) everything in this file works around the base class called WordCamp_Central_Theme,
 * which is a static class, and should never have an instance (hence the trigger_error trick
 * in the class constructor.)
 */

/**
 * WordCamp_Central_Theme Class
 *
 * Static class, used a lot throughout the WordCamp Central theme,
 * so please be careful when changing names, extending, etc. Everything
 * starts from the on_load method. The __construct method triggers an error.
 */
class WordCamp_Central_Theme {

	/**
	 * Constructor, triggers an error message.
	 * Please use the class directly, without creating an instance.
	 */
	function __construct() {
		trigger_error( 'Please use class, not instance! ' . __CLASS__ );
	}

	/**
	 * Use this class method instead of the usual constructor.
	 * Add more actions and filters from within this method.
	 */
	public static function on_load() {
		add_action( 'after_setup_theme', array( __CLASS__, 'after_setup_theme' ), 11 );
		add_action( 'widgets_init', array( __CLASS__, 'widgets_init' ), 11 );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_action( 'init', array( __CLASS__, 'process_forms' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_filter( 'excerpt_more', array( __CLASS__, 'excerpt_more' ), 11 );
		add_filter( 'nav_menu_css_class', array( __CLASS__, 'nav_menu_css_class' ), 10, 3 );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_links_to_footer_menu' ), 10, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'add_year_to_title' ), 10 );

		add_shortcode( 'wcc_map',         array( __CLASS__, 'shortcode_map'         ) );
		add_shortcode( 'wcc_about_stats', array( __CLASS__, 'shortcode_about_stats' ) );
	}

	/**
	 * Fired during after_setup_theme.
	 */
	static function after_setup_theme() {
		add_theme_support( 'post-formats', array( 'link', 'image' ) );
		$GLOBALS['custom_background']   = 'WordCamp_Central_Theme_Kill_Features';
		$GLOBALS['custom_image_header'] = 'WordCamp_Central_Theme_Kill_Features';

		// Add some new image sizes, also site shot is 205x148, minimap is 130x70
		add_image_size( 'wccentral-thumbnail-small', 82, 37, true );
		add_image_size( 'wccentral-thumbnail-large', 926, 160, true );
		add_image_size( 'wccentral-thumbnail-past', 130, 60, true );
		add_image_size( 'wccentral-thumbnail-hero', 493, 315, true );

		// Can I haz editor style?
		add_editor_style();

		// Let WordPress manage the document title.
		add_theme_support( 'title-tag' );
	}

	/**
	 * Fired during widgets_init, removes some Twenty Ten sidebars.
	 */
	static function widgets_init() {
		unregister_sidebar( 'fourth-footer-widget-area' );
		unregister_sidebar( 'secondary-widget-area' );

		register_sidebar( array(
			'name'          => __( 'Pages Widget Area', 'twentyten' ),
			'id'            => 'pages-widget-area',
			'description'   => __( 'Widgets displayed on pages.', 'twentyten' ),
			'before_widget' => '<li id="%1$s" class="widget-container %2$s">',
			'after_widget'  => '</li>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		) );
		register_sidebar( array(
			'name'          => __( 'Blog Widget Area', 'twentyten' ),
			'id'            => 'blog-widget-area',
			'description'   => __( 'Widgets displayed on the blog.', 'twentyten' ),
			'before_widget' => '<li id="%1$s" class="widget-container %2$s">',
			'after_widget'  => '</li>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		) );
		register_sidebar( array(
			'name'          => __( 'Sponsors Widget Area', 'twentyten' ),
			'id'            => 'sponsors-widget-area',
			'description'   => __( 'Widgets displayed in the Sponsors column on the homepage, one-by-one, as a slideshow.', 'twentyten' ),
			'before_widget' => '<li id="%1$s" class="widget-container %2$s">',
			'after_widget'  => '</li>',
			'before_title'  => '<h4 class="widget-title">',
			'after_title'   => '</h4>',
		) );
	}

	/**
	 * Fired during pre_get_posts, $query is passed by reference.
	 * Removes pages and WordCamps from search results.
	 */
	static function pre_get_posts( $query ) {
		if ( $query->is_search && $query->is_main_query() && ! is_admin() ) {
			$query->set( 'post_type', 'post' );
		}
	}

	/**
	 * Forms Processing
	 *
	 * Fired during init, checks REQUEST data for any submitted forms,
	 * does the whole form processing and redirects if necessary.
	 */
	static function process_forms() {
		$available_actions = array( 'subscribe' );
		if ( ! isset( $_REQUEST['wccentral-form-action'] ) || ! in_array( $_REQUEST['wccentral-form-action'], $available_actions ) ) {
			return;
		}

		$action = $_REQUEST['wccentral-form-action'];
		switch ( $action ) {

			// Subscribe to mailing list
			case 'subscribe':
				if ( ! call_user_func( array( __CLASS__, 'can_subscribe' ) ) ) {
					return;
				}

				// Jetpack will do the is_email check for us
				$jetpack_subscriptions = Jetpack_Subscriptions::init();
				$email                 = $_REQUEST['wccentral-subscribe-email'];
				$subscribe             = $jetpack_subscriptions->subscribe( $email, 0, false );

				// The following part is taken from the Jetpack subscribe widget (subscriptions.php)
				if ( is_wp_error( $subscribe ) ) {
					$error = $subscribe->get_error_code();
				} else {
					$error = false;
					foreach ( $subscribe as $response ) {
						if ( is_wp_error( $response ) ) {
							$error = $response->get_error_code();
							break;
						}
					}
				}

				if ( $error ) {
					switch ( $error ) {
						case 'invalid_email':
							$redirect = add_query_arg( 'subscribe', 'invalid_email' );
							break;
						case 'active':
						case 'pending':
							$redirect = add_query_arg( 'subscribe', 'already' );
							break;
						default:
							$redirect = add_query_arg( 'subscribe', 'error' );
							break;
					}
				} else {
					$redirect = add_query_arg( 'subscribe', 'success' );
				}

				wp_safe_redirect( esc_url_raw( $redirect ) );
				exit;
				break;
		}

		return;
	}

	/**
	 * Enqueue scripts and styles.
	 */
	static function enqueue_scripts() {
		wp_enqueue_style(
			'central',
			get_stylesheet_uri(),
			array(),
			filemtime( __DIR__ . '/style.css' )
		);

		wp_enqueue_script(
			'wordcamp-central',
			get_stylesheet_directory_uri() . '/js/central.js',
			array( 'jquery', 'underscore' ),
			filemtime( __DIR__ . '/js/central.js' ),
			true
		);

		wp_localize_script( 'wordcamp-central', 'wordcampCentralOptions', self::get_javascript_options() );

		/*
		 * We add some JavaScript to pages with the comment form
		 * to support sites with threaded comments (when in use).
		 */
		if ( is_singular() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}

		if ( is_page( 'about' ) || is_page( 'schedule' ) ) {
			$url = 'https://maps.googleapis.com/maps/api/js';

			$key = apply_filters( 'wordcamp_google_maps_api_key', '' );

			if ( $key ) {
				$url = add_query_arg( array(
					'key' => $key,
				), $url );
			}

			wp_enqueue_script( 'google-maps', $url, array(), false, true );
		}
	}

	/**
	 * Build the array of options to pass to the client side
	 *
	 * @return array
	 */
	protected static function get_javascript_options() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return array();
		}

		$options = array( 'ajaxURL' => admin_url( 'admin-ajax.php' ) );

		if ( $map_id = self::get_map_id( $post->post_content ) ) {
			$options['mapContainer']            = "wcc-map-$map_id";
			$options['markerIconBaseURL']       = get_stylesheet_directory_uri() . '/images/';
			$options['markerClusterIcon']       = 'icon-marker-clustered.png';
			$options['markerIconAnchorXOffset'] = 24;
			$options['markerIconHeight']        = 94;
			$options['markerIconWidth']         = 122;

			$map_markers = self::get_map_markers( $map_id );

			if ( $map_markers ) {
				$options['mapMarkers'] = $map_markers;
			}
		}

		return $options;
	}

	/**
	 * Get the ID of the map called in the given page
	 *
	 * @param string $post_content
	 *
	 * @return mixed A string of the map name on success, or false on failure
	 */
	protected static function get_map_id( $post_content ) {
		$map_id = false;

		if ( has_shortcode( $post_content, 'wcc_map' ) ) {
			preg_match_all( '/' . get_shortcode_regex() . '/s', $post_content, $shortcodes, PREG_SET_ORDER );

			foreach ( $shortcodes as $shortcode ) {
				if ( 'wcc_map' === $shortcode[2] ) {
					$attributes = shortcode_parse_atts( $shortcode[3] );
					$map_id     = sanitize_text_field( $attributes['id'] );
					break;
				}
			}
		}

		return $map_id;
	}

	/**
	 * Get the markers assigned to the given map
	 *
	 * @param string $map_id
	 *
	 * @return array
	 */
	protected static function get_map_markers( $map_id ) {
		$transient_key = "wcc_map_markers_$map_id";
		$markers       = get_transient( $transient_key );

		if ( $markers ) {
			return $markers;
		} else {
			$markers = array();
		}

		// Get the raw marker posts for the given map.
		$parameters = array(
			'post_type'      => 'wordcamp',
			'posts_per_page' => -1,
			'post_status'    => array_merge(
				WordCamp_Loader::get_public_post_statuses(),
				WordCamp_Loader::get_pre_planning_post_statuses()
			),
		);

		switch ( $map_id ) {
			case 'schedule':
				$parameters['meta_query'][] = array(
					'key'     => 'Start Date (YYYY-mm-dd)',
					'value'   => strtotime( '-2 days' ),
					'compare' => '>',
				);
				break;
		}

		$raw_markers = get_posts( $parameters );

		// Convert the raw markers into prepared objects that are ready to be used on the JavaScript side.
		foreach ( $raw_markers as $marker ) {
			if ( 'schedule' === $map_id ) {
				$marker_type = 'upcoming';
			} else {
				$marker_type = get_post_meta( $marker->ID, 'Start Date (YYYY-mm-dd)', true ) > strtotime( '-2 days' ) ? 'upcoming' : 'past';
			}

			$coordinates = get_post_meta( $marker->ID, '_venue_coordinates', true );

			// Try the host coordinates (for online events).
			if ( ! $coordinates ) {
				$coordinates = get_post_meta( $marker->ID, '_host_coordinates', true );
			}

			// No location found, skip this one.
			if ( ! $coordinates ) {
				continue;
			}

			$markers[ $marker->ID ] = array(
				'id'        => $marker->ID,
				'name'      => wcpt_get_wordcamp_title( $marker->ID ),
				'dates'     => wcpt_get_wordcamp_start_date( $marker->ID ),
				'location'  => get_post_meta( $marker->ID, 'Location', true ),
				'venueName' => get_post_meta( $marker->ID, 'Venue Name', true ),
				'url'       => self::get_best_wordcamp_url( $marker->ID ),
				'latitude'  => $coordinates['latitude'],
				'longitude' => $coordinates['longitude'],
				'iconURL'   => "icon-marker-{$marker_type}-2x.png",
			);
		}

		$markers          = apply_filters( 'wcc_get_map_markers', $markers );
		$cache_expiration = 'about' === $map_id ? WEEK_IN_SECONDS : DAY_IN_SECONDS;

		set_transient( $transient_key, $markers, $cache_expiration );

		return $markers;
	}

	/**
	 * Filters excerpt_more.
	 */
	public static function excerpt_more( $more ) {
		return '&nbsp;&hellip;';
	}

	/**
	 * Filters nav_menu_css_class.
	 *
	 * Make sure Schedule is current-menu-item when viewing WordCamps.
	 */
	public static function nav_menu_css_class( $classes, $item, $args ) {
		if ( 'wordcamp' == get_post_type() ) {
			if ( home_url( '/schedule/' ) == trailingslashit( $item->url ) ) {
				$classes[] = 'current-menu-item';
			} else {
				$remove = array( 'current-menu-item', 'current_page_parent', 'current_page_ancestor' );

				foreach ( $remove as $class ) {
					$classes = array_splice( $classes, array_search( $class, $classes ), 1 );
				}
			}
		}

		return $classes;
	}

	/**
	 * Add links to the footer menu.
	 *
	 * @param string $items HTML markup of all <li> elements.
	 * @param array  $args  The arguments that were passed to `wp_nav_menu()`.
	 *
	 * @return string
	 */
	public static function add_links_to_footer_menu( $items, $args ) {
		if ( 'menu-footer' === $args->container_class ) {
			ob_start();

			?>

			<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="<?php echo esc_url( get_feed_link() ); ?>">RSS (posts)</a></li>
			<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="<?php echo esc_url( get_post_type_archive_feed_link( 'wordcamp' ) ); ?>">RSS (WordCamps)</a></li>
			<li class="menu-item menu-item-type-custom menu-item-object-custom">
				<?php function_exists( 'the_privacy_policy_link' ) && the_privacy_policy_link(); ?>
			</li>

			<?php

			$items .= ob_get_clean();
		}

		return $items;
	}

	/**
	 * Get Session List.
	 *
	 * Uses the WordCamp post type to loop through the latest
	 * WordCamps, if WordCamp URLs are valid network blogs, switches
	 * to blog and queries for Session.
	 *
	 * @uses switch_to_blog, get_blog_details, wp_object_cache
	 * @return assoc array with session and WC info.
	 */
	public static function get_sessions( $count = 4 ) {
		if ( ! function_exists( 'wcpt_has_wordcamps' ) ) {
			return false;
		}

		// Check cache.
		$sessions = (bool) wp_cache_get( 'wccentral_sessions_' . $count );
		if ( $sessions ) {
			return $sessions;
		}

		// Take latest WordCamps.
		$args = array(
			'posts_per_page' => $count + 10,
			'meta_key'       => 'Start Date (YYYY-mm-dd)',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',

			'meta_query' => array(
				array(
					'key'     => 'Start Date (YYYY-mm-dd)',
					'value'   => strtotime( '-2 days' ),
					'compare' => '>',
				),
			),
		);

		if ( ! wcpt_has_wordcamps( $args ) ) {
			return false;
		}

		// We'll hold the sessions here.
		$sessions = array();

		// Loop through the latest WCs.
		while ( wcpt_wordcamps() ) {
			wcpt_the_wordcamp();

			// Store WC data (will be unavailable after switch_to_blog).
			$domain       = wp_parse_url( wcpt_get_wordcamp_url(), PHP_URL_HOST );
			$blog_details = get_blog_details( array( 'domain' => $domain ), false );

			$wordcamp_date  = wcpt_get_wordcamp_start_date( 0, 'F ' );
			$wordcamp_date .= wcpt_get_wordcamp_start_date( 0, 'j' );
			if ( wcpt_get_wordcamp_end_date( 0, 'j' ) ) {
				$wordcamp_date .= '-' . wcpt_get_wordcamp_end_date( 0, 'j' );
			}

			// Valid for all sessions in this WC.
			$session = array(
				'wordcamp_title'     => wcpt_get_wordcamp_title(),
				'wordcamp_permalink' => wcpt_get_wordcamp_permalink(),
				'wordcamp_date'      => $wordcamp_date,
				'wordcamp_thumb'     => get_the_post_thumbnail( get_the_ID(), 'wccentral-thumbnail-small' ),
			);

			if ( isset( $blog_details->blog_id ) && $blog_details->blog_id ) {
				$my_blog_id = (int) $blog_details->blog_id;

				switch_to_blog( $my_blog_id );

					// Look through 5 sessions, store in $sessions array.
					$sessions_query = new WP_Query( array(
						'post_type'      => 'wcb_session',
						'posts_per_page' => 5,
						'post_status'    => 'publish',
					) );
				while ( $sessions_query->have_posts() ) {
					$sessions_query->the_post();

					// Add the extra fields to $session and push to $sessions.
					$sessions[] = array_merge( $session, array(
						'name'      => apply_filters( 'the_title', get_the_title() ),
						'speakers'  => get_post_meta( get_the_ID(), '_wcb_session_speakers', true ),
						'permalink' => get_permalink( get_the_ID() ),
					) );
				}

				restore_current_blog();
			}
		}

		// Randomize and pick $count.
		shuffle( $sessions );
		$sessions = array_slice( $sessions, 0, $count );

		// Cache in transients.
		wp_cache_set( 'wccentral_sessions_' . $count, $sessions );
		return $sessions;
	}

	/**
	 * Retrieve Subscription Status from $_REQUEST.
	 */
	public static function get_subscription_status() {
		return isset( $_REQUEST['subscribe'] ) ? strtolower( $_REQUEST['subscribe'] ) : false;
	}

	/**
	 * Subscription Check.
	 *
	 * Returns true if subscriptions are available.
	 */
	public static function can_subscribe() {
		return class_exists( 'Jetpack_Subscriptions' ) && is_callable( array( 'Jetpack_Subscriptions', 'subscribe' ) );
	}

	/**
	 * Override `twentyten_comment()` in the parent theme.
	 *
	 * @param WP_Comment $comment
	 * @param array      $args
	 * @param int        $depth
	 */
	public static function twentyten_comment( $comment, $args, $depth ) {
		$GLOBALS['comment'] = $comment;

		switch ( $comment->comment_type ) :
			case '': ?>
				<li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>">
					<div id="comment-<?php comment_ID(); ?>" class="comment-container">
						<div class="comment-author vcard">
							<?php echo get_avatar( $comment, 60 ); ?>
							<?php printf(
								wp_kses_post( __( '%s <span class="says">says:</span>', 'twentyten' ) ),
								sprintf( '<cite class="fn">%s</cite>', wp_kses_post( get_comment_author_link() ) )
							); ?>
						</div>

						<?php if ( '0' == $comment->comment_approved ) : ?>
							<em class="comment-awaiting-moderation">
								<?php esc_html_e( 'Your comment is awaiting moderation.', 'twentyten' ); ?>
							</em>
							<br />
						<?php endif; ?>

						<div class="comment-meta commentmetadata">
							<a href="<?php echo esc_url( get_comment_link( $comment->comment_ID ) ); ?>">
								<?php
									/* translators: 1: date, 2: time */
									printf(
										esc_html__( '%1$s at %2$s', 'twentyten' ),
										get_comment_date(),
										get_comment_time()
									);
								?>
							</a>

							<?php edit_comment_link( __( '(Edit)', 'twentyten' ), ' ' ); ?>
						</div>

						<div class="comment-body">
							<?php comment_text(); ?>
						</div>

						<div class="reply">
							<?php comment_reply_link( array_merge( $args,
								array(
									'depth'      => $depth,
									'max_depth'  => $args['max_depth'],
									'reply_text' => '&#10149; Reply',
								)
							) ); ?>
						</div>
				</div> <!-- #comment-##  -->

				<?php
				break;

			case 'pingback':
			case 'trackback': ?>
				<li class="post pingback">
					<p>
						<?php esc_html_e( 'Pingback:', 'twentyten' ); ?>
						<?php comment_author_link(); ?>
						<?php edit_comment_link( __( '(Edit)', 'twentyten' ), ' ' ); ?>
					</p>
				<?php
				break;
		endswitch;
	}

	/**
	 * Run the query to get upcoming WordCamps.
	 *
	 * @param int $count
	 *
	 * @return WP_Query
	 */
	public static function get_upcoming_wordcamps_query( $count = 10 ) {
		$query = new WP_Query(
			array(
				'post_type'      => WCPT_POST_TYPE_ID,
				'post_status'    => WordCamp_Loader::get_public_post_statuses(),
				'posts_per_page' => $count,
				'meta_key'       => 'Start Date (YYYY-mm-dd)',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',

				'meta_query' => array(
					array(
						'key'     => 'Start Date (YYYY-mm-dd)',
						'value'   => strtotime( '-2 days' ),
						'compare' => '>',
					),
				),
			)
		);

		return $query;
	}

	/**
	 * Output the date or date range of a WordCamp event.
	 *
	 * @param int  $wordcamp_id The ID of the WordCamp post.
	 * @param bool $show_year   Optional. True to include the year in the date output.
	 */
	public static function the_wordcamp_date( $wordcamp_id = 0, $show_year = false ) {
		$start_day   = wcpt_get_wordcamp_start_date( $wordcamp_id, 'j' );
		$start_month = wcpt_get_wordcamp_start_date( $wordcamp_id, 'F' );
		$end_day     = wcpt_get_wordcamp_end_date( $wordcamp_id, 'j' );
		$end_month   = wcpt_get_wordcamp_end_date( $wordcamp_id, 'F' );

		$one_day_event = wcpt_get_wordcamp_start_date( $wordcamp_id, 'Y-m-d' ) === wcpt_get_wordcamp_end_date( $wordcamp_id, 'Y-m-d' );

		if ( $show_year ) {
			$start_year = wcpt_get_wordcamp_start_date( $wordcamp_id, 'Y' );
			$end_year   = wcpt_get_wordcamp_end_date( $wordcamp_id, 'Y' );
		}

		echo esc_html( "$start_month $start_day" );

		if ( $end_day && ! $one_day_event ) {
			if ( $show_year && $start_year !== $end_year ) {
				echo esc_html( ", $start_year" );
			}

			echo '&ndash;';

			if ( $start_month !== $end_month ) {
				echo esc_html( "$end_month " );
			}

			echo esc_html( $end_day );

			if ( $show_year ) {
				echo esc_html( ", $end_year" );
			}
		} elseif ( $show_year ) {
			echo esc_html( ", $start_year" );
		}
	}

	/**
	 * Group an array of WordCamps by year
	 *
	 * @param array $wordcamps
	 *
	 * @return array
	 */
	public static function group_wordcamps_by_year( $wordcamps ) {
		$grouped_wordcamps = array();

		foreach ( $wordcamps as $wordcamp ) {
			$date = get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true );

			if ( $date && $year = date( 'Y', (int) $date ) ) {
				$grouped_wordcamps[ $year ][] = $wordcamp;
			}
		}

		return $grouped_wordcamps;
	}

	/**
	 * Returns a WordCamp's website URL if it's available, or their Central page if is isn't.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function get_best_wordcamp_url( $post_id = 0 ) {
		$url = wcpt_get_wordcamp_url( $post_id );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$url = wcpt_get_wordcamp_permalink( $post_id );
		}

		return $url;
	}

	/**
	 * Render the [wcc_map] shortcode
	 *
	 * @param array $attributes
	 *
	 * @return string
	 */
	public static function shortcode_map( $attributes ) {
		$attributes = shortcode_atts( array( 'id' => '' ), $attributes );

		ob_start();
		require( __DIR__ . '/shortcode-about-map.php' );
		return ob_get_clean();
	}

	/**
	 * Render the [wcc_about_stats] shortcode
	 *
	 * @param array $attributes
	 *
	 * @return string
	 */
	public static function shortcode_about_stats( $attributes ) {
		// Allow stat values to be overridden with shortcode attributes.
		$map_stats = shortcode_atts( self::get_map_stats(), $attributes, 'wcc_about_stats' );

		// Sanitize stat values.
		$map_stats = array_map( 'absint', $map_stats );

		ob_start();
		require( __DIR__ . '/shortcode-about-stats.php' );
		return ob_get_clean();
	}

	/**
	 * Gather the stats for the [wcc_about_stats] shortcode
	 *
	 * There isn't an easy way to collect the country stats programmatically, but it just takes a minute to
	 * manually count the number countries on the map that have pins.
	 *
	 * @return array
	 */
	protected static function get_map_stats() {
		$transient_key = 'wcc_about_map_stats';
		$map_stats     = get_transient( $transient_key );

		if ( ! $map_stats ) {
			$cities    = array();
			$wordcamps = new WP_Query( array(
				'post_type'      => 'wordcamp',
				'post_status'    => WordCamp_Loader::get_public_post_statuses(),
				'posts_per_page' => -1,
			) );

			// Count the number of cities.
			// @todo use _venue_city field since it'll be more accurate, but need to populate older camps first.
			foreach ( $wordcamps->posts as $wordcamp ) {
				$url      = get_post_meta( $wordcamp->ID, 'URL', true );
				$hostname = wp_parse_url( $url, PHP_URL_HOST );

				if ( $hostname ) {
					$city               = explode( '.', $hostname );
					$cities[ $city[0] ] = true;
				}
			}

			// @todo generate countries automatically from _venue_country_code field, but need to populate older camps first.

			// Compile the results.
			$map_stats = array(
				'wordcamps'  => $wordcamps->found_posts,
				'cities'     => count( $cities ),
				'countries'  => 65,
				'continents' => 6,
			);

			set_transient( $transient_key, $map_stats, 2 * WEEK_IN_SECONDS );
		}

		return $map_stats;
	}

	/**
	 * Get T-shirt Sizes, a caching wrapper for _get_tshirt_sizes.
	 *
	 * @param int $wordcamp_id The WordCamp post ID.
	 *
	 * @return array An array of sizes.
	 */
	public static function get_tshirt_sizes( $wordcamp_id ) {
		// TODO: Implement some caching.
		$sizes = self::_get_tshirt_sizes( $wordcamp_id );
		return $sizes;
	}

	/**
	 * Get T-shirt Sizes.
	 *
	 * @param int $wordcamp_id The WordCamp post ID.
	 *
	 * @return array An array of sizes.
	 */
	private static function _get_tshirt_sizes( $wordcamp_id ) {
		$wordcamp = get_post( $wordcamp_id );
		$sizes    = array();

		$wordcamp_site_id = absint( get_wordcamp_site_id( $wordcamp ) );
		if ( ! $wordcamp_site_id ) {
			return $sizes;
		}

		wp_suspend_cache_addition( true );
		switch_to_blog( $wordcamp_site_id );

		$questions = get_posts( array(
			'post_type'      => 'tix_question',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );

		// Aggregate only t-shirt questions.
		$tshirt_questions = array();
		foreach ( $questions as $question_id ) {
			if ( get_post_meta( $question_id, 'tix_type', true ) !== 'tshirt' ) {
				continue;
			}

			$tshirt_questions[] = $question_id;
		}

		$paged = 1;
		while ( $attendees = get_posts( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => 200,
			'paged'          => $paged++,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) ) ) {
			foreach ( $attendees as $attendee_id ) {
				$answers = get_post_meta( $attendee_id, 'tix_questions', true );
				foreach ( $answers as $question_id => $answer ) {
					if ( in_array( $question_id, $tshirt_questions, true ) ) {
						if ( ! isset( $sizes[ $answer ] ) ) {
							$sizes[ $answer ] = 0;
						}

						$sizes[ $answer ]++;
					}
				}
			}
		}

		restore_current_blog();
		wp_suspend_cache_addition( false );
		arsort( $sizes );
		return $sizes;
	}

	/**
	 * Include the year in single WordCamp <title> tag.
	 *
	 * @param array $title The document title parts.
	 * @return array
	 */
	public static function add_year_to_title( $title ) {
		if ( defined( 'WCPT_POST_TYPE_ID' ) && is_singular( WCPT_POST_TYPE_ID ) ) {
			$title['title'] .= ' ' . wcpt_get_wordcamp_start_date( get_the_ID(), 'Y' );
		}

		return $title;
	}
}

// Load the theme class, this is where it all starts.
WordCamp_Central_Theme::on_load();

/**
 * Override the parent's comment function with ours.
 *
 * @param WP_Comment $comment
 * @param array      $args
 * @param int        $depth
 */
function twentyten_comment( $comment, $args, $depth ) {
	WordCamp_Central_Theme::twentyten_comment( $comment, $args, $depth );
}

/**
 * Class WordCamp_Central_Theme_Kill_Features
 *
 * This class is used to kill header images and custom background added by 2010.
 */
class WordCamp_Central_Theme_Kill_Features {
	/**
	 * Disable theme features.
	 *
	 * @return bool
	 */
	public function init() {
		return false;
	}
}

/**
 * Randomize the order of the widgets in the Sponsors widget area.
 *
 * @param array $sidebars_widgets
 *
 * @return array
 */
function wordcamp_central_randomize_sponsor_widget_order( $sidebars_widgets ) {
	if ( isset( $sidebars_widgets['sponsors-widget-area'] ) ) {
		shuffle( $sidebars_widgets['sponsors-widget-area'] );
	}

	return $sidebars_widgets;
}

add_filter( 'sidebars_widgets', 'wordcamp_central_randomize_sponsor_widget_order' );

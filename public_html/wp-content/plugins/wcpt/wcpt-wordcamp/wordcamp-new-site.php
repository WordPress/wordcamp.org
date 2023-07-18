<?php

// PHPCS note: Nonces are verified in Event_Admin::metabox_save (if applicable), we don't need to re-verify.
// phpcs:disable WordPress.WP.CapitalPDangit -- This has false positives on all the `events.wordpress.org` URLs in this file.

use \WordCamp\Logger;

use function WordCamp\Sunrise\get_top_level_domain;

use const WordCamp\Sunrise\{ PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, PATTERN_CITY_YEAR_TYPE_PATH };

class WordCamp_New_Site {
	protected $new_site_id;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->new_site_id = false;

		add_action( 'wcpt_metabox_value',     array( $this, 'render_site_url_field' ), 10, 3 );
		add_action( 'wcpt_metabox_save',      array( $this, 'save_site_url_field'   ), 10, 3 );
		add_action( 'wcpt_metabox_save_done', array( $this, 'maybe_create_new_site' ), 10, 1 );
		add_action( 'wcpt_metabox_save_done', array( $this, 'maybe_push_mes'        ), 10, 1 );
	}

	/**
	 * Render the URL field
	 *
	 * @action wcpt_metabox_value
	 *
	 * @param string $key
	 * @param string $field_type
	 * @param string $object_name
	 */
	public function render_site_url_field( $key, $field_type, $object_name ) {
		global $post_id;

		if ( 'URL' == $key && 'wc-url' == $field_type ) : ?>
			<input
				type="text"
				size="36"
				name="<?php echo esc_attr( $object_name ); ?>"
				id="<?php echo esc_attr( $object_name ); ?>"
				value="<?php echo esc_attr( get_post_meta( $post_id, $key, true ) ); ?>"
				placeholder="https://city.wordcamp.org/<?php echo esc_attr( wp_date( 'Y' ) ); ?>/"
			/>

			<?php if ( current_user_can( 'manage_sites' ) ) : ?>
				<?php
				$url        = trailingslashit( get_post_meta( $post_id, $key, true ) );
				$url        = wp_parse_url( filter_var( $url, FILTER_VALIDATE_URL ) );
				$valid_url  = isset( $url['host'], $url['path'] );
				$tld        = get_top_level_domain();
				$network_id = "events.wordpress.$tld" === $url['host'] ? EVENTS_NETWORK_ID : WORDCAMP_NETWORK_ID;
				?>

				<?php if ( $valid_url && domain_exists( $url['host'], $url['path'], $network_id ) ) : ?>
					<?php
						$blog_details = get_blog_details(
							array(
								'domain' => $url['host'],
								'path'   => $url['path'],
							),
							true
						);
						$edit_url     = add_query_arg( 'id', $blog_details->blog_id, network_admin_url( 'site-info.php' ) );

					if ( "events.wordpress.$tld" === $url['host'] ) {
						$edit_url = str_replace( '://wordcamp.', '://events.wordpress.', $edit_url );
					}
					?>

					<a target="_blank" href="<?php echo esc_url( $edit_url ); ?>">Edit</a> |
					<a target="_blank" href="<?php echo esc_url( $blog_details->siteurl ); ?>/wp-admin/">Dashboard</a> |
					<a target="_blank" href="<?php echo esc_url( $blog_details->siteurl ); ?>">Visit</a>

				<?php else : ?>
					<?php $checkbox_id = wcpt_key_to_str( 'create-site-in-network', 'wcpt_' ); ?>

					<label for="<?php echo esc_attr( $checkbox_id ); ?>">
						<input id="<?php echo esc_attr( $checkbox_id ); ?>" type="checkbox" name="<?php echo esc_attr( $checkbox_id ); ?>" />
						Create site in network
					</label>
				<?php endif; // Domain exists. ?>

				<?php if ( $valid_url && ! self::url_matches_expected_format( $url['host'], $url['path'], $post_id ) ) : ?>
					<br /><br />

					<span class="notice notice-large notice-warning">
						Warning: This URL doesn't match the expected format. It should be either <code>city.wordcamp.org/year/</code> or <code>events.wordpress.org/city/year/type/</code>.
					</span>
				<?php endif; ?>
			<?php endif; // User can manage sites. ?>
		<?php endif;
	}

	/**
	 * Save the URL field value
	 *
	 * @param string $key
	 * @param string $field_type
	 * @param int    $wordcamp_id
	 */
	public function save_site_url_field( $key, $field_type, $wordcamp_id ) {
		global $switched;

		// No updating if the blog has been switched.
		if ( $switched || 1 !== did_action( 'wcpt_metabox_save' ) ) {
			return;
		}

		$field_name = wcpt_key_to_str( $key, 'wcpt_' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note at top of file
		if ( 'URL' !== $key || 'wc-url' !== $field_type || ! isset( $_POST[ $field_name ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note at top of file
		if ( empty( $_POST[ $field_name ] ) ) {
			delete_post_meta( $wordcamp_id, 'URL' );
			delete_post_meta( $wordcamp_id, '_site_id' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note at top of file
		$url = strtolower( substr( $_POST[ $field_name ], 0, 4 ) ) == 'http' ? $_POST[ $field_name ] : 'http://' . $_POST[ $field_name ];
		$url = set_url_scheme( esc_url_raw( $url ), 'https' );
		$url = filter_var( $url, FILTER_VALIDATE_URL );

		if ( ! $url ) {
			return;
		}

		$url        = trailingslashit( $url );
		$parsed_url = wp_parse_url( $url );

		if ( ! self::url_matches_expected_format( $parsed_url['host'], $parsed_url['path'], $wordcamp_id ) ) {
			wp_die( "The URL doesn't match the expected format. It should be either <code>city.wordcamp.org/year/</code> or <code>events.wordpress.org/city/year/type/</code>. Please press the back button and update it." );
		}

		update_post_meta( $wordcamp_id, $key, esc_url( $url ) );

		// If this site exists make sure we update the _site_id mapping.
		$existing_site_id = domain_exists( $parsed_url['host'], $parsed_url['path'], 1 );

		if ( $existing_site_id ) {
			update_post_meta( $wordcamp_id, '_site_id', absint( $existing_site_id ) );
		} else {
			delete_post_meta( $wordcamp_id, '_site_id' );
		}
	}

	/**
	 * Check if the given URL matches the expected format.
	 *
	 * @param string $domain
	 * @param string $path
	 * @param int    $wordcamp_id
	 *
	 * @return bool
	 */
	public static function url_matches_expected_format( $domain, $path, $wordcamp_id ) {
		$tld                            = get_top_level_domain();
		$last_permitted_external_domain = 2341;
		$external_domain_exceptions     = array( 169459 );
		$is_external_domain             = ! preg_match( "@ \.wordcamp\.$tld | \.buddycamp\.$tld | events\.wordpress\.$tld @ix", $domain );
		$can_have_external_domain       = $wordcamp_id <= $last_permitted_external_domain || in_array( $wordcamp_id, $external_domain_exceptions );

		if ( $is_external_domain && $can_have_external_domain ) {
			// Many old camps had external websites.
			return true;
		}

		if ( "events.wordpress.$tld" === $domain ) {
			$match = preg_match( PATTERN_CITY_YEAR_TYPE_PATH, $path );
		} else {
			$match = preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $domain . $path );
		}

		return 1 === $match;
	}

	/**
	 * Maybe create a new site in the network
	 *
	 * @param int $wordcamp_id
	 */
	public function maybe_create_new_site( $wordcamp_id ) {
		$wordcamp = get_post( $wordcamp_id );

		if ( ! $wordcamp instanceof WP_Post || WCPT_POST_TYPE_ID !== $wordcamp->post_type ) {
			return;
		}

		/*
		 * If this were to be called again before it had finished -- e.g., when `WCORMailer::replace_placeholders()`
		 * calls `WordCamp_Admin::metabox_save()` -- then it would `wpmu_create_blog()` would return a `blog_taken`
		 * `WP_Error` and `configure_site()` would never be called.
		 *
		 * @todo - If no other problems crop up with new site creation by 2016-12-01, then all of the logging
		 * that was added in r4254 can be removed, to make the code less cluttered and more readable.
		 */
		if ( 1 !== did_action( 'wcpt_metabox_save_done' ) ) {
			Logger\log( 'return_redundant_call' );
			return;
		}

		if ( ! current_user_can( 'manage_sites' ) ) {
			$current_user_id = get_current_user_id();
			Logger\log( 'return_no_cap', compact( 'wordcamp_id', 'current_user_id' ) );
			return;
		}

		// The sponsor region is required so we can import the relevant sponsors and levels.
		$sponsor_region = get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true );
		if ( ! $sponsor_region ) {
			Logger\log( 'return_no_region', compact( 'wordcamp_id', 'sponsor_region' ) );
			return;
		}

		$url = get_post_meta( $wordcamp_id, 'URL', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note at top of file
		if ( ! isset( $_POST[ wcpt_key_to_str( 'create-site-in-network', 'wcpt_' ) ] ) || empty( $url ) ) {
			Logger\log( 'return_no_request_or_url', compact( 'wordcamp_id', 'url' ) );
			return;
		}

		$url_components = wp_parse_url( $url );
		if ( ! $url_components || empty( $url_components['scheme'] ) || empty( $url_components['host'] ) ) {
			Logger\log( 'return_invalid_url', compact( 'wordcamp_id', 'url', 'url_components' ) );
			return;
		}

		$path           = isset( $url_components['path'] ) ? $url_components['path'] : '';
		$wordcamp_meta  = get_post_custom( $wordcamp_id );
		$lead_organizer = $this->get_user_or_current_user( $wordcamp_meta['WordPress.org Username'][0] );

		$blog_name = apply_filters( 'the_title', $wordcamp->post_title );
		if ( ! empty( $wordcamp->{'Start Date (YYYY-mm-dd)'} ) ) {
			$blog_name .= wp_date( ' Y', $wordcamp->{'Start Date (YYYY-mm-dd)'} );
		}

		$tld = get_top_level_domain();

		$this->new_site_id = wp_insert_site( array(
			'network_id' => "events.wordpress.$tld" === $url_components['host'] ? EVENTS_NETWORK_ID : WORDCAMP_NETWORK_ID,
			'domain'  => $url_components['host'],
			'path'    => $path,
			'title'   => $blog_name,
			'user_id' => $lead_organizer->ID,
		) );

		if ( is_int( $this->new_site_id ) ) {
			// `_site_id` is used in other plugins to map the `wordcamp` post to it's corresponding site.
			update_post_meta( $wordcamp_id, '_site_id', $this->new_site_id );
			do_action( 'wcor_wordcamp_site_created', $wordcamp_id );

			add_post_meta(
				$wordcamp_id,
				'_note',
				array(
					'timestamp' => time(),
					'user_id'   => get_current_user_id(),
					'message'   => sprintf( 'Created site at <a href="%s">%s</a>', $url, $url ),
				)
			);

			$this->configure_new_site( $wordcamp_id, $wordcamp );

			$new_site_id = $this->new_site_id;
			Logger\log( 'finished', compact( 'wordcamp_id', 'url', 'lead_organizer', 'new_site_id', 'blog_name' ) );
		} else {
			$new_site_id = $this->new_site_id;
			Logger\log( 'no_site_id', compact( 'wordcamp_id', 'url', 'lead_organizer', 'new_site_id', 'blog_name' ) );
		}
	}

	/**
	 * Maybe push multi-event sponsors out.
	 *
	 * This is only used when _manually_ pushing new sponsors to an existing site via the 'Push Sponsor to Site' checkbox.
	 * create_post_stubs() is used when the sponsors are automatically pushed to a newly-created site.
	 *
	 * @param int $wordcamp_id The WordCamp post id.
	 */
	public function maybe_push_mes( $wordcamp_id ) {
		if ( ! current_user_can( 'manage_sites' ) ) {
			return;
		}

		// The sponsor region is required so we can import the relevant sponsors and levels.
		if ( ! get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note at top of file
		if ( empty( $_POST[ wcpt_key_to_str( 'push-mes-sponsors', 'wcpt_' ) ] ) ) {
			return;
		}

		if ( WordCamp_Admin::is_protected_field( 'Multi-Event Sponsor Region' ) ) {
			return;
		}

		$wordcamp              = get_post( $wordcamp_id );
		$meta                  = get_post_custom( $wordcamp_id );
		$blog_id               = get_wordcamp_site_id( $wordcamp );
		$lead_organizer        = $this->get_user_or_current_user( $meta['WordPress.org Username'][0] );
		$assigned_sponsor_data = $this->get_assigned_sponsor_data( $wordcamp->ID );
		$me_sponsor_stubs      = $this->get_stub_me_sponsors( $assigned_sponsor_data );
		$existing_me_sponsors  = array();

		switch_to_blog( $blog_id );

		$site_sponsors = get_posts( array(
			'fields'         => 'ids',
			'post_type'      => 'wcb_sponsor',
			'post_status'    => 'any',
			'posts_per_page' => - 1,
			'cache_results'  => false,
		) );

		update_meta_cache( 'post', $site_sponsors );

		foreach ( $site_sponsors as $new_post_id ) {
			$mes_id = get_post_meta( $new_post_id, '_mes_id', true );
			if ( $mes_id ) {
				$existing_me_sponsors[] = absint( $mes_id );
			}
		}

		add_filter( 'upload_dir', array( $this, '_fix_wc_upload_dir' ) );

		foreach ( $me_sponsor_stubs as $me_stub ) {
			// Skip existing sponsors.
			if ( in_array( absint( $me_stub['meta']['_mes_id'] ), $existing_me_sponsors ) ) {
				continue;
			}

			$new_post_id = wp_insert_post( array(
				'post_type'    => $me_stub['type'],
				'post_status'  => 'draft',
				'post_author'  => $lead_organizer->ID,
				'post_title'   => $me_stub['title'],
				'post_content' => $me_stub['content'],
			) );

			if ( $new_post_id ) {
				foreach ( $me_stub['meta'] as $key => $value ) {
					update_post_meta( $new_post_id, $key, $value );
				}

				// Set featured image.
				if ( ! empty( $me_stub['featured_image'] ) ) {
					$results = media_sideload_image( $me_stub['featured_image'], $new_post_id );

					if ( ! is_wp_error( $results ) ) {
						$attachment_id = get_posts( array(
							'posts_per_page' => 1,
							'post_type'      => 'attachment',
							'post_parent'    => $new_post_id,
						) );

						if ( isset( $attachment_id[0]->ID ) ) {
							set_post_thumbnail( $new_post_id, $attachment_id[0]->ID );
						}
					}
				}
			}
		}

		remove_filter( 'upload_dir', array( $this, '_fix_wc_upload_dir' ) );

		restore_current_blog();
	}

	/**
	 * Fix upload directories when in a switched to blog context.
	 *
	 * WordCamp.org runs with WordPress in its own directory (mu) as an external.
	 * When switching to a subsite, WordPress thinks the upload directory is
	 * relative to ABSPATH, so we need so trip out the /mu part.
	 *
	 * @param array $data Data from wp_upload_dir().
	 *
	 * @return array Result.
	 */
	public function _fix_wc_upload_dir( $data ) {
		$data['path'] = str_replace(
			'public_html/mu/wp-content',
			'public_html/wp-content',
			$data['path']
		);

		$data['basedir'] = str_replace(
			'public_html/mu/wp-content',
			'public_html/wp-content',
			$data['basedir']
		);

		return $data;
	}

	/**
	 * Get the requested user, but fall back to the current user
	 *
	 * @param string $username
	 *
	 * @return WP_User
	 */
	protected function get_user_or_current_user( $username ) {
		$lead_organizer = get_user_by( 'login', $username );

		if ( ! $lead_organizer ) {
			$lead_organizer = wp_get_current_user();
		}

		return $lead_organizer;
	}

	/**
	 * Configure a new site and populate it with default content
	 *
	 * @param int     $wordcamp_id
	 * @param WP_Post $wordcamp
	 */
	protected function configure_new_site( $wordcamp_id, $wordcamp ) {
		if ( ! defined( 'WCPT_POST_TYPE_ID' ) || WCPT_POST_TYPE_ID != $wordcamp->post_type || ! is_numeric( $this->new_site_id ) ) {
			$new_site_id = $this->new_site_id;
			Logger\log( 'return_invalid_type_or_id', compact( 'wordcamp_id', 'new_site_id' ) );
			return;
		}

		$meta = get_post_custom( $wordcamp_id );

		$mentor = wcorg_get_user_by_canonical_names( $meta['Mentor WordPress.org User Name'][0] );
		if ( $mentor ) {
			add_user_to_blog( get_wordcamp_site_id( $wordcamp ), $mentor->ID, 'administrator' );
		}

		switch_to_blog( $this->new_site_id );

		add_filter( 'upload_dir', array( $this, '_fix_wc_upload_dir' ) );

		$lead_organizer = $this->get_user_or_current_user( $meta['WordPress.org Username'][0] );

		switch_theme( 'twentytwenty' );

		$this->set_default_options( $wordcamp, $meta );
		$this->create_post_stubs( $wordcamp, $meta, $lead_organizer );

		Jetpack::activate_default_modules( false, false, array(), false, false, false, false );

		/**
		 * Hook into the configuration process for a new WordCamp site.
		 *
		 * This fires in the context of the newly created site, after the theme has been set and the
		 * default options and post stubs have been created.
		 *
		 * @param int     $wordcamp_id The ID of the new site.
		 * @param WP_Post $wordcamp    The post object of the WordCamp on Central.
		 */
		do_action( 'wcpt_configure_new_site', $wordcamp_id, $wordcamp );

		remove_filter( 'upload_dir', array( $this, '_fix_wc_upload_dir' ) );

		restore_current_blog();

		Logger\log( 'finished', compact( 'wordcamp_id', 'wordcamp', 'meta', 'lead_organizer' ) );
	}

	/**
	 * Set the default options
	 *
	 * @param WP_Post $wordcamp
	 * @param array   $meta
	 */
	protected function set_default_options( $wordcamp, $meta ) {
		/** @var $WCCSP_Settings WCCSP_Settings */
		global $WCCSP_Settings; // phpcs:ignore WordPress.NamingConventions
		global $wp_rewrite;

		$admin_email                     = is_email( $meta['E-mail Address'][0] ) ? $meta['E-mail Address'][0] : get_site_option( 'admin_email' );
		$coming_soon_settings            = $WCCSP_Settings->get_settings(); // phpcs:ignore WordPress.NamingConventions
		$coming_soon_settings['enabled'] = 'on';

		update_option( 'admin_email',                  $admin_email );
		update_option( 'blogdescription',              __( 'Just another WordCamp', 'wordcamporg' ) );
		update_option( 'timezone_string',              $meta['Event Timezone'][0] );
		update_option( 'close_comments_for_old_posts', 1 );
		update_option( 'close_comments_days_old',      30 );
		update_option( 'wccsp_settings',               $coming_soon_settings );

		/*
		 * Avoids URLs like `narnia.wordcamp.org/2020/2010/06/04/foo`. See `redirect_date_permalinks_to_post_slug()`.
		 *
		 * If ths ever changes, the link in `stubs/page/sponsors.php may need to be updated.
		 */
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		delete_option( 'rewrite_rules' ); // Delete because can't be flushed during `switch_to_blog()`.

		// Make sure the new blog is https.
		update_option( 'siteurl', set_url_scheme( get_option( 'siteurl' ), 'https' ) );
		update_option( 'home',    set_url_scheme( get_option( 'home' ),    'https' ) );

		Logger\log( 'finished', compact( 'admin_email' ) );
	}

	/**
	 * Create stubs for commonly-used posts
	 *
	 * @param WP_Post $wordcamp
	 * @param array   $meta
	 * @param WP_User $lead_organizer
	 */
	protected function create_post_stubs( $wordcamp, $meta, $lead_organizer ) {
		$assigned_sponsor_data = $this->get_assigned_sponsor_data( $wordcamp->ID );
		$this->create_sponsorship_levels( $assigned_sponsor_data['assigned_sponsors'] );

		// Get stub content.
		$stubs = array_merge(
			$this->get_stub_posts( $wordcamp, $meta ),
			$this->get_stub_pages( $wordcamp, $meta ),
			$this->get_stub_me_sponsors( $assigned_sponsor_data ),
			$this->get_stub_me_sponsor_thank_yous( $assigned_sponsor_data['assigned_sponsors'] )
		);

		// Create actual posts from stubs.
		foreach ( $stubs as $page ) {
			$page_id = wp_insert_post(
				array(
					'post_type'    => $page['type'],
					'post_status'  => $page['status'],
					'post_author'  => $lead_organizer->ID,
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				Logger\log( 'insert_post_failed', compact( 'page_id', 'page' ) );
				continue;
			}

			// Save post meta.
			if ( ! empty( $page['meta'] ) ) {
				foreach ( $page['meta'] as $key => $value ) {
					update_post_meta( $page_id, $key, $value );
				}
			}

			// Set featured image.
			if ( isset( $page['featured_image'] ) ) {
				$results = media_sideload_image( $page['featured_image'], $page_id );

				Logger\log( 'featured_image', compact( 'wordcamp', 'page', 'results' ) );

				if ( ! is_wp_error( $results ) ) {
					$attachment_id = get_posts( array(
						'posts_per_page' => 1,
						'post_type'      => 'attachment',
						'post_parent'    => $page_id,
					) );

					if ( isset( $attachment_id[0]->ID ) ) {
						set_post_thumbnail( $page_id, $attachment_id[0]->ID );
					}
				}
			}

			// Assign sponsorship level.
			if ( 'wcb_sponsor' == $page['type'] && isset( $page['term'] ) ) {
				wp_set_object_terms( $page_id, $page['term'], 'wcb_sponsor_level', true );
			}
		}

		Logger\log( 'finished', compact( 'assigned_sponsor_data', 'stubs' ) );
	}

	/**
	 * Get the content for page stubs
	 *
	 * @param WP_Post $wordcamp
	 * @param array   $meta
	 *
	 * @return array
	 */
	protected function get_stub_pages( $wordcamp, $meta ) {
		$pages = array(
			array(
				'title'   => __( 'Schedule', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'schedule' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Speakers', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'speakers' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Sessions', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'sessions' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Sponsors', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'sponsors' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Location', 'wordcamporg' ),
				'content' => '',
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Organizers', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'organizers' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Tickets', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'tickets' ),
				'status'  => 'draft',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Attendees', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'attendees' ),
				'status'  => 'draft',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Videos', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'videos' ),
				'status'  => 'draft',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Slideshow', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'slideshow' ),
				'status'  => 'draft',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Contact', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'contact' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Social Media Stream', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'social-media-stream' ),
				'status'  => 'publish',
				'type'    => 'page',
			),

			array(
				'title'   => __( 'Offline', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'offline', $wordcamp ),
				'status'  => 'publish',
				'type'    => 'page',
				'meta'    => array(
					'wc_page_offline' => 'yes',
				),
			),

			array(
				'title'   => __( 'Day Of Event', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'day-of-event', $wordcamp ),
				'status'  => 'draft',
				'type'    => 'page',
			),
		);

		if ( isset( $meta['Virtual event only'][0] ) && $meta['Virtual event only'][0] ) {
			$pages[] = array(
				'title'   => __( 'Code of Conduct', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'code-of-conduct-online' ),
				'status'  => 'publish',
				'type'    => 'page',
			);
		} else {
			$pages[] = array(
				'title'   => __( 'Code of Conduct', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'page', 'code-of-conduct' ),
				'status'  => 'publish',
				'type'    => 'page',
			);
		}

		return $pages;
	}

	/**
	 * Get the content for post stubs
	 *
	 * @param WP_Post $wordcamp
	 * @param array   $meta
	 *
	 * @return array
	 */
	protected function get_stub_posts( $wordcamp, $meta ) {
		$posts = array(
			array(
				// translators: %s: site title.
				'title'   => sprintf( __( 'Welcome to %s', 'wordcamporg' ), get_option( 'blogname' ) ),
				'content' => $this->get_stub_content( 'post', 'welcome' ),
				'status'  => 'publish',
				'type'    => 'post',
			),

			array(
				'title'   => __( 'Call for Sponsors', 'wordcamporg' ),
				// Update the slug in `sponsors.php` if the slug below ever changes.
				'content' => $this->get_stub_content( 'post', 'call-for-sponsors' ),
				'status'  => 'draft',
				'type'    => 'post',
			),

			array(
				'title'   => __( 'Call for Speakers', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'post', 'call-for-speakers' ),
				'status'  => 'draft',
				'type'    => 'post',
				'meta'    => array(
					'wcfd-key' => 'call-for-speakers',
				),
			),

			array(
				'title'   => __( 'Call for Volunteers', 'wordcamporg' ),
				'content' => $this->get_stub_content( 'post', 'call-for-volunteers' ),
				'status'  => 'draft',
				'type'    => 'post',
				'meta'    => array(
					'wcfd-key' => 'call-for-volunteers',
				),
			),
		);

		return $posts;
	}

	/**
	 * Load the content for a stub from an include file.
	 *
	 * @param string  $post_type
	 * @param string  $stub_name
	 * @param WP_Post $wordcamp
	 *
	 * @return string
	 */
	protected function get_stub_content( $post_type, $stub_name, $wordcamp = false ) {
		$content   = '';
		$stub_file = WCPT_DIR . "stubs/$post_type/$stub_name.php";

		if ( is_readable( $stub_file ) ) {
			ob_start();
			require $stub_file;
			$content = ob_get_clean();
		}

		return $content;
	}

	/**
	 * Create the sponsorship levels for the assigned Multi-Event Sponsors
	 *
	 * @param array $assigned_sponsors
	 */
	protected function create_sponsorship_levels( $assigned_sponsors ) {
		foreach ( $assigned_sponsors as $sponsorship_level_id ) {
			$sponsorship_level = $sponsorship_level_id[0]->sponsorship_level;

			wp_insert_term(
				$sponsorship_level->post_title,
				'wcb_sponsor_level',
				array(
					'slug' => $sponsorship_level->post_name,
				)
			);
		}
	}

	/**
	 * Get the content for sponsor stubs
	 *
	 * These are just the multi-event sponsors. Each camp will also have local sponsors, but they'll add those manually.
	 *
	 * @param array $assigned_sponsor_data
	 *
	 * @return array
	 */
	protected function get_stub_me_sponsors( $assigned_sponsor_data ) {
		$me_sponsors = array();

		foreach ( $assigned_sponsor_data['assigned_sponsors'] as $sponsorship_level_id => $assigned_sponsors ) {
			foreach ( $assigned_sponsors as $assigned_sponsor ) {
				$me_sponsors[] = array(
					'title'          => $assigned_sponsor->post_title,
					'content'        => $assigned_sponsor->post_content,
					'status'         => 'publish',
					'type'           => 'wcb_sponsor',
					'term'           => $assigned_sponsor->sponsorship_level->post_name,
					'featured_image' => isset( $assigned_sponsor_data['featured_images'][ $assigned_sponsor->ID ] ) ? $assigned_sponsor_data['featured_images'][ $assigned_sponsor->ID ] : '',
					'meta'           => $this->get_stub_me_sponsors_meta( $assigned_sponsor ),
				);
			}
		}

		return $me_sponsors;
	}

	/**
	 * Get the meta data for a `mes` post and prepare it for importing into a `wcb_sponsor` post
	 *
	 * @param WP_Post $assigned_sponsor
	 *
	 * @return array
	 */
	public static function get_stub_me_sponsors_meta( $assigned_sponsor ) {
		$sponsor_meta    = array( '_mes_id' => $assigned_sponsor->ID );
		$meta_field_keys = array(
			'company_name', 'website', 'first_name', 'last_name', 'email_address', 'phone_number',
			'twitter_handle', 'street_address1', 'street_address2', 'city', 'state', 'zip_code', 'country',
		);

		switch_to_blog( BLOG_ID_CURRENT_SITE ); // Switch to central.wordcamp.org.

		foreach ( $meta_field_keys as $key ) {
			$sponsor_meta[ "_wcpt_sponsor_$key" ] = get_post_meta( $assigned_sponsor->ID, "mes_$key", true );
		}

		restore_current_blog();

		return $sponsor_meta;
	}

	/**
	 * Get the assigned Multi-Event Sponsors and their sponsorship levels for the given WordCamp
	 *
	 * @param int $wordcamp_id
	 *
	 * @return array
	 */
	protected function get_assigned_sponsor_data( $wordcamp_id ) {
		/** @var $multi_event_sponsors Multi_Event_Sponsors */
		global $multi_event_sponsors;
		$data = array();

		switch_to_blog( BLOG_ID_CURRENT_SITE ); // Switch to central.wordcamp.org.

		$data['featured_images']   = array();
		$data['assigned_sponsors'] = $multi_event_sponsors->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		foreach ( $data['assigned_sponsors'] as $sponsorship_level_id => $sponsors ) {
			foreach ( $sponsors as $sponsor ) {
				$attachment_id = get_post_thumbnail_id( $sponsor->ID );
				if ( ! $attachment_id ) {
					continue;
				}

				$attachment = wp_get_attachment_image_src( $attachment_id, 'full' );
				if ( ! $attachment ) {
					continue;
				}

				$data['featured_images'][ $sponsor->ID ] = $attachment[0];
			}
		}

		restore_current_blog();

		return $data;
	}

	/**
	 * Generate stub posts for thanking Multi-Event Sponsors
	 *
	 * The MES_Sponsorship_Level post excerpts contain the intro text for these messages, and the MES_Sponsor
	 * post excerpts contain the blurb for each sponsor.
	 *
	 * @param array $assigned_sponsor_data
	 *
	 * @return array
	 */
	protected function get_stub_me_sponsor_thank_yous( $assigned_sponsor_data ) {
		/** @var $multi_event_sponsors Multi_Event_Sponsors */
		global $multi_event_sponsors;
		$pages = array();

		foreach ( $assigned_sponsor_data as $sponsorship_level_id ) {
			$sponsorship_level = $sponsorship_level_id[0]->sponsorship_level;

			$pages[] = array(
				// translators: %s: sponsorship level.
				'title'   => sprintf( __( 'Thank you to our %s sponsors', 'wordcamporg' ), $sponsorship_level->post_title ),
				'content' => sprintf(
					'%s %s',
					str_replace(
						'[sponsor_names]',
						$multi_event_sponsors->get_sponsor_names( $sponsorship_level_id ),
						$sponsorship_level->post_excerpt
					),
					$multi_event_sponsors->get_sponsor_excerpts( $sponsorship_level_id )
				),
				'status'  => 'draft',
				'type'    => 'post',
			);
		}

		return $pages;
	}
} // WordCamp_New_Site

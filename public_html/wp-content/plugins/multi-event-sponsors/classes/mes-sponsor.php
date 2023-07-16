<?php

/*
 * Register the Multi-Event Sponsors custom post type and manage all of its functionality
 *
 * The use of custom post types and taxonomies for the regions and sponsorships levels is a little questionable,
 * since neither fits perfectly. In an abstract sense, they're taxonomies, but they map between terms for a given post,
 * rather than mapping terms to posts directly. Also, the sponsorship levels have extra meta data associated with them.
 *
 * So, a custom taxonomy is used for regions, and a custom post type is used for sponsorship levels, and then meta data
 * is used on each sponsor post to map each region to a sponsorship level.
 */

class MES_Sponsor {
	const POST_TYPE_SLUG = 'mes';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',                  array( $this, 'create_post_type' ) );
		add_action( 'rest_api_init',         array( $this, 'register_routes'  ) );
		add_action( 'admin_init',            array( $this, 'add_meta_boxes'   ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue'    ), 20 );
		add_action( 'save_post',             array( $this, 'save_post'        ), 10, 2 );
		add_filter( 'the_content',           array( $this, 'add_header_footer_text' ) );
	}

	/**
	 * Registers the custom post type
	 */
	public function create_post_type() {
		if ( post_type_exists( self::POST_TYPE_SLUG ) ) {
			return;
		}

		$labels = array(
			'name'               => __( 'Multi-Event Sponsors',                   'wordcamporg' ),
			'singular_name'      => __( 'Multi-Event Sponsor',                    'wordcamporg' ),
			'add_new'            => __( 'Add New',                                'wordcamporg' ),
			'add_new_item'       => __( 'Add New Multi-Event Sponsor',            'wordcamporg' ),
			'edit'               => __( 'Edit',                                   'wordcamporg' ),
			'edit_item'          => __( 'Edit Multi-Event Sponsor',               'wordcamporg' ),
			'new_item'           => __( 'New Multi-Event Sponsor',                'wordcamporg' ),
			'view'               => __( 'View Multi-Event Sponsors',              'wordcamporg' ),
			'view_item'          => __( 'View Multi-Event Sponsor',               'wordcamporg' ),
			'search_items'       => __( 'Search Multi-Event Sponsors',            'wordcamporg' ),
			'not_found'          => __( 'No Multi-Event Sponsors found',          'wordcamporg' ),
			'not_found_in_trash' => __( 'No Multi-Event Sponsors found in Trash', 'wordcamporg' ),
			'parent'             => __( 'Parent Multi-Event Sponsor',             'wordcamporg' ),
		);

		$post_type_params = array(
			'labels'          => $labels,
			'singular_label'  => __( 'Multi-Event Sponsor', 'wordcamporg' ),
			'public'          => true,
			'show_in_rest'    => true,
			'menu_position'   => 20,
			'hierarchical'    => false,
			'capability_type' => 'page',
			'has_archive'     => true,
			'rewrite'         => array(
				'slug'       => 'multi-event-sponsor',
				'with_front' => false,
			),
			'query_var'       => true,
			'menu_icon'       => 'dashicons-heart',
			'supports'        => array( 'title', 'editor', 'author', 'excerpt', 'revisions', 'thumbnail' ),
			'taxonomies'      => array( MES_Region::TAXONOMY_SLUG ),
		);

		register_post_type( self::POST_TYPE_SLUG, $post_type_params );
	}

	/**
	 * Register endpoints for the REST API.
	 */
	public function register_routes() : void {
		register_rest_route(
			'multi-event-sponsors/v1',
			'/push-to-active-camps',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'push_to_active_camps' ),
					'permission_callback' => function() {
						return current_user_can( 'manage_network' );
					},
				),
			)
		);
	}

	/**
	 * Copy the contents of MES posts to their forked posts on active camp sites.
	 */
	public function push_to_active_camps( WP_REST_Request $request ) : array {
		$skipped_posts = array();
		$source_post   = get_post( $request->get_param( 'sponsorId' ) );
		$wordcamps     = get_wordcamps( array(
			'post_status' => array_merge(
				// Any status where the camp might have a site created, and the event isn't over.
				WordCamp_Loader::get_pre_planning_post_statuses(),
				WordCamp_Loader::get_active_wordcamp_statuses(),
			),
		) );

		foreach ( $wordcamps as $wordcamp ) {
			if ( ! isset( $wordcamp->meta['_site_id'][0] ) ) {
				continue;
			}

			switch_to_blog( $wordcamp->meta['_site_id'][0] );

			$fork_post = get_posts( array(
				'post_type'    => 'wcb_sponsor',
				'meta_key'     => '_mes_id',
				'meta_value'   => $source_post->ID,
				'meta_compare' => '=',

				// Only published ones will be updated, but the others should still be added to `$skipped_posts`.
				'post_status'  => 'any',
			) );
			$fork_post = array_pop( $fork_post );

			if ( ! $fork_post ) {
				restore_current_blog();
				continue;
			}

			// Organizers might have made changes that are specific to this camp, so don't overwrite them.
			if ( self::post_has_been_edited( $fork_post->ID ) ) {
				$skipped_posts[] = array(
					// Can't use `get_post_edit_link()` because the `wcb_sponsor` post type isn't registered on Central or while switching blogs.
					'edit_url'  => admin_url( "post.php?post={$fork_post->ID}&action=edit" ),
					'site_name' => get_wordcamp_name(),
				);
				restore_current_blog();
				continue;
			}

			wp_update_post( array(
				'ID'           => $fork_post->ID,
				'post_title'   => $source_post->post_title,
				'post_content' => $source_post->post_content,

				// This triggers a database query for every item, so it's a good optimization candidate if needed.
				'meta_input'   => WordCamp_New_Site::get_stub_me_sponsors_meta( $source_post ),
			) );

			// Reset date so that post_has_been_edited() will continue detecting that it hasn't been edited (by a user).
			self::reset_post_modified_date( $fork_post );

			// Default to Gutenberg since all the source posts now use it.
			if ( is_callable( array( 'Classic_Editor', 'remember_block_editor' ) ) ) {
				Classic_Editor::remember_block_editor( array(), $fork_post );
			}

			restore_current_blog();
		}

		return array(
			'success'       => true,
			'skipped_posts' => $skipped_posts,
		);
	}

	/**
	 * Check if the post has been edited since it was created.
	 *
	 * WP will update modified date when saving posts, even if only post meta is changed and the title/content are untouched.
	 */
	public static function post_has_been_edited( int $post_id ) : bool {
		return get_the_modified_time( 'U', $post_id ) !== get_the_time( 'U', $post_id );
	}

	/**
	 * Revert the post modified date back to the creation date.
	 */
	public static function reset_post_modified_date( WP_Post $post ) : void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $post->post_date,
				'post_modified_gmt' => $post->post_date_gmt,
			),
			array( 'ID' => $post->ID )
		);
	}

	/**
	 * Adds meta boxes for the custom post type
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'mes_regional_sponsorships',
			__( 'Regional Sponsorships', 'wordcamporg' ),
			array( $this, 'markup_meta_boxes' ),
			self::POST_TYPE_SLUG,
			'normal',
			'default'
		);

		add_meta_box(
			'mes_contact_information',
			__( 'Contact Information', 'wordcamporg' ),
			array( $this, 'markup_meta_boxes' ),
			self::POST_TYPE_SLUG,
			'normal',
			'default'
		);

		add_meta_box(
			'sponsor-agreement',
			__( 'Sponsor Agreement', 'wordcamporg' ),
			array( $this, 'markup_meta_boxes' ),
			self::POST_TYPE_SLUG,
			'side'
		);
	}

	/**
	 * Builds the markup for all meta boxes
	 *
	 * @param WP_Post $post
	 * @param array   $box
	 */
	public function markup_meta_boxes( $post, $box ) {
		/** @var $view string */

		switch ( $box['id'] ) {
			case 'mes_regional_sponsorships':
				$regions               = get_terms( MES_Region::TAXONOMY_SLUG, array( 'hide_empty' => false ) );
				$sponsorship_levels    = get_posts( array(
					'post_type'   => MES_Sponsorship_Level::POST_TYPE_SLUG,
					'numberposts' => - 1,
				) );
				$regional_sponsorships = $this->populate_default_regional_sponsorships( get_post_meta( $post->ID, 'mes_regional_sponsorships', true ), $regions );
				$view                  = 'metabox-regional-sponsorships.php';
				break;

			case 'mes_contact_information':
				$company_name   = get_post_meta( $post->ID, 'mes_company_name',   true );
				$website        = get_post_meta( $post->ID, 'mes_website',        true );
				$first_name     = get_post_meta( $post->ID, 'mes_first_name',     true );
				$last_name      = get_post_meta( $post->ID, 'mes_last_name',      true );
				$email_address  = get_post_meta( $post->ID, 'mes_email_address',  true );
				$phone_number   = get_post_meta( $post->ID, 'mes_phone_number',   true );
				$twitter_handle = get_post_meta( $post->ID, 'mes_twitter_handle', true );

				$street_address1 = get_post_meta( $post->ID, 'mes_street_address1', true );
				$street_address2 = get_post_meta( $post->ID, 'mes_street_address2', true );
				$city            = get_post_meta( $post->ID, 'mes_city',            true );
				$state           = get_post_meta( $post->ID, 'mes_state',           true );
				$zip_code        = get_post_meta( $post->ID, 'mes_zip_code',        true );
				$country         = get_post_meta( $post->ID, 'mes_country',         true );

				if ( wcorg_skip_feature( 'cldr-countries' ) ) {
					$available_countries = array( 'Abkhazia', 'Afghanistan', 'Aland', 'Albania', 'Algeria', 'American Samoa', 'Andorra', 'Angola', 'Anguilla', 'Antigua and Barbuda', 'Argentina', 'Armenia', 'Aruba', 'Ascension', 'Ashmore and Cartier Islands', 'Australia', 'Australian Antarctic Territory', 'Austria', 'Azerbaijan', 'Bahamas, The', 'Bahrain', 'Baker Island', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bermuda', 'Bhutan', 'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Bouvet Island', 'Brazil', 'British Antarctic Territory', 'British Indian Ocean Territory', 'British Sovereign Base Areas', 'British Virgin Islands', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Canada', 'Cape Verde', 'Cayman Islands', 'Central African Republic', 'Chad', 'Chile', "China, People's Republic of", 'China, Republic of (Taiwan)', 'Christmas Island', 'Clipperton Island', 'Cocos (Keeling) Islands', 'Colombia', 'Comoros', 'Congo, (Congo  Brazzaville)', 'Congo, (Congo  Kinshasa)', 'Cook Islands', 'Coral Sea Islands', 'Costa Rica', "Cote d'Ivoire (Ivory Coast)", 'Croatia', 'Cuba', 'Cyprus', 'Czech Republic', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'Ecuador', 'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Ethiopia', 'Falkland Islands (Islas Malvinas)', 'Faroe Islands', 'Fiji', 'Finland', 'France', 'French Guiana', 'French Polynesia', 'French Southern and Antarctic Lands', 'Gabon', 'Gambia, The', 'Georgia', 'Germany', 'Ghana', 'Gibraltar', 'Greece', 'Greenland', 'Grenada', 'Guadeloupe', 'Guam', 'Guatemala', 'Guernsey', 'Guinea', 'Guinea-Bissau', 'Guyana', 'Haiti', 'Heard Island and McDonald Islands', 'Honduras', 'Hong Kong', 'Howland Island', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland', 'Isle of Man', 'Israel', 'Italy', 'Jamaica', 'Japan', 'Jarvis Island', 'Jersey', 'Johnston Atoll', 'Jordan', 'Kazakhstan', 'Kenya', 'Kingman Reef', 'Kiribati', 'Korea, North', 'Korea, South', 'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Macau', 'Macedonia', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Martinique', 'Mauritania', 'Mauritius', 'Mayotte', 'Mexico', 'Micronesia', 'Midway Islands', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Montserrat', 'Morocco', 'Mozambique', 'Myanmar (Burma)', 'Nagorno-Karabakh', 'Namibia', 'Nauru', 'Navassa Island', 'Nepal', 'Netherlands', 'Netherlands Antilles', 'New Caledonia', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'Niue', 'Norfolk Island', 'Northern Cyprus', 'Northern Mariana Islands', 'Norway', 'Oman', 'Pakistan', 'Palau', 'Palmyra Atoll', 'Panama', 'Papua New Guinea', 'Paraguay', 'Peru', 'Peter I Island', 'Philippines', 'Pitcairn Islands', 'Poland', 'Portugal', 'Pridnestrovie (Transnistria)', 'Puerto Rico', 'Qatar', 'Queen Maud Land', 'Reunion', 'Romania', 'Ross Dependency', 'Russia', 'Rwanda', 'Saint Barthelemy', 'Saint Helena', 'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Martin', 'Saint Pierre and Miquelon', 'Saint Vincent and the Grenadines', 'Samoa', 'San Marino', 'Sao Tome and Principe', 'Saudi Arabia', 'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia', 'Somaliland', 'South Africa', 'South Georgia & South Sandwich Islands', 'South Ossetia', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Svalbard', 'Swaziland', 'Sweden', 'Switzerland', 'Syria', 'Tajikistan', 'Tanzania', 'Thailand', 'Timor-Leste (East Timor)', 'Togo', 'Tokelau', 'Tonga', 'Trinidad and Tobago', 'Tristan da Cunha', 'Tunisia', 'Turkey', 'Turkmenistan', 'Turks and Caicos Islands', 'Tuvalu', 'U.S. Virgin Islands', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam', 'Wake Island', 'Wallis and Futuna', 'Yemen', 'Zambia', 'Zimbabwe' );
				} else {
					$available_countries = wcorg_get_countries();
				}

				$view = 'metabox-contact-information.php';
				break;

			case 'sponsor-agreement':
				$agreement_id  = get_post_meta( $post->ID, 'mes_sponsor_agreement', true );
				$agreement_url = wp_get_attachment_url( $agreement_id );
				$mes_id        = false;
				$view          = 'metabox-sponsor-agreement.php';
				break;
		}

		require_once dirname( __DIR__ ) . '/views/'. $view;
	}

	/**
	 * Enqueue admin scripts.
	 */
	function admin_enqueue() {
		$screen = get_current_screen();

		if ( self::POST_TYPE_SLUG !== $screen->id ) {
			return;
		}

		if ( current_user_can( 'manage_network' ) ) {
			$asset                   = require_once dirname( __DIR__ ) . '/build/index.asset.php';
			$asset['dependencies'][] = 'wp-sanitize';

			wp_enqueue_script(
				'multi-event-sponsor',
				plugins_url( 'build/index.js', __DIR__ ),
				$asset['dependencies'],
				$asset['version'],
				true
			);

			$data = array(
				'admin_url' => admin_url(),
			);
			wp_add_inline_script(
				'multi-event-sponsor',
				sprintf(
					'var MultiEventSponsor = JSON.parse( decodeURIComponent( \'%s\' ) );',
					rawurlencode( wp_json_encode( $data ) )
				),
				'before'
			);

			wp_set_script_translations( 'multi-event-sponsor', 'wordcamporg' );

			wp_enqueue_style(
				'multi-event-sponsor',
				plugins_url( 'css/multi-event-sponsor.css', __DIR__ ),
				array(),
				filemtime( dirname( __DIR__ ) . '/css/multi-event-sponsor.css' )
			);
		}

		// Enqueues scripts and styles for sponsors admin page
		if ( wp_script_is( 'wcb-spon', 'registered' ) ) {
			wp_enqueue_script( 'wcb-spon' );
		}
	}

	/**
	 * Populate the regional sponsorships array with default values.
	 *
	 * This helps to avoid any PHP notices from trying to access undefined indices.
	 *
	 * @param array $regional_sponsorships
	 * @param array $regions
	 *
	 * @return array
	 */
	protected function populate_default_regional_sponsorships( $regional_sponsorships, $regions ) {
		$region_ids = wp_list_pluck( $regions, 'term_id' );

		foreach ( $region_ids as $region_id ) {
			if ( empty ( $regional_sponsorships[ $region_id ] ) ) {
				$regional_sponsorships[ $region_id ] = 'null';
			}
		}

		return $regional_sponsorships;
	}

	/**
	 * Save the post data
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save_post( $post_id, $post ) {
		$ignored_actions = array( 'trash', 'untrash', 'restore' );

		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $ignored_actions ) ) {
			return;
		}

		if ( ! $post || $post->post_type != self::POST_TYPE_SLUG || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || $post->post_status == 'auto-draft' ) {
			return;
		}

		$this->save_post_meta( $post_id, $_POST );
	}

	/**
	 * Save the post's meta fields
	 *
	 * @param int   $post_id
	 * @param array $new_values
	 */
	protected function save_post_meta( $post_id, $new_values ) {
		if ( isset( $new_values['mes_regional_sponsorships'] ) ) {
			array_walk( $new_values['mes_regional_sponsorships'], 'absint' );
			update_post_meta( $post_id, 'mes_regional_sponsorships', $new_values['mes_regional_sponsorships'] );
		}

		if ( isset( $new_values['mes_email_address'] ) ) {
			$new_values['mes_email_address'] = is_email( $new_values['mes_email_address'] );
		}

		$text_fields = array(
			'company_name', 'website', 'first_name', 'last_name', 'email_address', 'phone_number', 'twitter_handle',
			'street_address1', 'street_address2', 'city', 'state', 'zip_code', 'country',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $new_values[ "mes_$field" ] ) ) {
				update_post_meta( $post_id, "mes_$field", sanitize_text_field( $new_values[ "mes_$field" ] ) );
			}
		}

		$sponsor_agreement = filter_input( INPUT_POST, '_wcpt_sponsor_agreement', FILTER_SANITIZE_NUMBER_INT );
		if ( $sponsor_agreement ) {
			update_post_meta( $post_id, 'mes_sponsor_agreement', $sponsor_agreement );
		}
	}

	/**
	 * Add the header and footer copy to the sponsorship level content
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function add_header_footer_text( $content ) {
		global $post;

		if ( ! empty ( $post->post_type ) && self::POST_TYPE_SLUG == $post->post_type ) {
			$content = sprintf(
				'<p>Here’s the company description for %s for your website:</p>
				<div>%s</div>',
				$post->post_title,
				$content
			);
		}

		return $content;
	}
} // end MES_Sponsor

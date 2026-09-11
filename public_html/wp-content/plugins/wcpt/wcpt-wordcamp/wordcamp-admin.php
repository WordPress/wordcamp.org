<?php

use WordCamp\Logger;
use WordCamp\Mentors_Dashboard;
use WordPress_Community\Applications\WordCamp_Application;

require_once WCPT_DIR . 'wcpt-event/class-event-admin.php';
require_once WCPT_DIR . 'wcpt-event/notification.php';

if ( ! class_exists( 'WordCamp_Admin' ) ) :
	/**
	 * WCPT_Admin
	 *
	 * Loads plugin admin area
	 *
	 * @package WordCamp Post Type
	 * @subpackage Admin
	 * @since WordCamp Post Type (0.1)
	 */
	class WordCamp_Admin extends Event_Admin {

		/**
		 * Applications each user authored or mentors, once looked up.
		 *
		 * Keyed by user ID: the instance outlives a `wp_set_current_user()` switch.
		 *
		 * @var array<int, WP_Post[]>
		 */
		protected $own_wordcamps = array();

		/**
		 * Initialize WCPT Admin
		 */
		public function __construct() {

			parent::__construct();

			// Add some general styling to the admin area.
			add_action( 'wcpt_admin_head', array( $this, 'admin_head' ) );

			// Scripts and CSS.
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			// Post status transitions.
			add_action( 'transition_post_status', array( $this, 'trigger_schedule_actions' ), 10, 3 );
			add_action( 'wcpt_approved_for_pre_planning', array( $this, 'add_organizer_to_central' ), 10 );
			add_action( 'wcpt_approved_for_pre_planning', array( $this, 'mark_date_added_to_planning_schedule' ), 10 );
			// Priority 11 so the organizer email (sent by WCOR_Mailer at 10) goes out first.
			add_action( 'wcpt_cc_needs_orientation', array( $this, 'handle_cc_needs_orientation' ), 11 );

			add_filter(
				'wp_insert_post_data',
				array(
					$this,
					'require_complete_meta_to_publish_wordcamp',
				),
				11,
				2
			); // after WordCamp_Status_Guard::enforce_post_status().

			// Filters - Subtype filtering on the WordCamp list table.
			add_filter( 'views_edit-wordcamp', array( $this, 'alter_views' ) );
			add_action( 'parse_query', array( $this, 'filter_by_subtype' ) );

			// Cron jobs.
			add_action( 'plugins_loaded', array( $this, 'schedule_cron_jobs' ), 11 );
			add_action( 'wcpt_close_wordcamps_after_event', array( $this, 'close_wordcamps_after_event' ) );
			add_action( 'wcpt_metabox_save_done', array( $this, 'update_venue_address' ), 10, 2 );
			add_action( 'wcpt_metabox_save_done', array( $this, 'update_mentor' ), 10, 2 );

			add_action( 'parse_query', array( $this, 'default_sortby' ), 9 );
			add_action( 'parse_query', array( $this, 'sort_by_event_date' ) );

			// WordCamp list table query filters: the "Mine (Mentoring)" view, and who sees what.
			add_action( 'pre_get_posts', array( $this, 'filter_mentoring_view' ) );
			add_action( 'pre_get_posts', array( $this, 'limit_list_to_editable_wordcamps' ) );
		}

		/**
		 * Add the metabox
		 *
		 * @uses add_meta_box
		 */
		public function metabox() {
			add_meta_box(
				'wcpt_information',
				__( 'WordCamp Information', 'wordcamporg' ),
				'wcpt_wordcamp_metabox',
				WCPT_POST_TYPE_ID,
				'advanced'
			);

			add_meta_box(
				'wcpt_organizer_info',
				__( 'Organizing Team', 'wordcamporg' ),
				'wcpt_organizer_metabox',
				WCPT_POST_TYPE_ID,
				'advanced'
			);

			add_meta_box(
				'wcpt_venue_info',
				__( 'Venue Information', 'wordcamporg' ),
				'wcpt_venue_metabox',
				WCPT_POST_TYPE_ID,
				'advanced'
			);

			add_meta_box(
				'wcpt_contributor_info',
				__( 'Contributor Day Information', 'wordcamporg' ),
				'wcpt_contributor_metabox',
				WCPT_POST_TYPE_ID,
				'advanced'
			);

		}

		/**
		 * Get label for event type
		 *
		 * @return string
		 */
		public static function get_event_label() {
			return WordCamp_Application::get_event_label();
		}

		/**
		 * Get wordcamp post type
		 *
		 * @return string
		 */
		public static function get_event_type() {
			return WordCamp_Application::get_event_type();
		}

		/**
		 * Get searchable post meta keys for WordCamp events.
		 *
		 * Returns a limited list of meta keys that are useful for searching.
		 * Focuses on names, locations, and text fields while excluding URLs, dates, and numeric fields.
		 *
		 * @return array List of meta keys to search.
		 */
		public static function get_searchable_meta_keys() {
			return array(
				'Organizer Name',
				'WordPress.org Username',
				'Location',
				'Venue Name',
				'Physical Address',
				'Sponsor Wrangler Name',
				'Budget Wrangler Name',
				'Venue Wrangler Name',
				'Speaker Wrangler Name',
				'Food/Beverage Wrangler Name',
				'Swag Wrangler Name',
				'Volunteer Wrangler Name',
				'Printing Wrangler Name',
				'Design Wrangler Name',
				'Website Wrangler Name',
				'Social Media/Publicity Wrangler Name',
				'A/V Wrangler Name',
				'Party Wrangler Name',
				'Travel Wrangler Name',
				'Safety Wrangler Name',
			);
		}

		/**
		 * Check if a field is readonly.
		 *
		 * @param string $key
		 *
		 * @return bool
		 */
		public function _is_protected_field( $key ) {
			return self::is_protected_field( $key );
		}

		/**
		 * Update mentor username, and fire the mentor assigned/changed trigger if the mentor has changed.
		 *
		 * @param int   $post_id
		 * @param array $original_meta_values Original meta values before save.
		 */
		public function update_mentor( $post_id, $original_meta_values = array() ) {
			if ( $this->get_event_type() !== get_post_type() ) {
				return;
			}

			if ( ! current_user_can( 'wordcamp_manage_mentors' ) ) {
				return;
			}

			//phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in `metabox_save` in class-event-admin.php.
			$username = $_POST[ wcpt_key_to_str( 'Mentor WordPress.org User Name', 'wcpt_' ) ] ?? '';

			$this->add_mentor( get_post( $post_id ), $username );

			$old_username = $original_meta_values['Mentor WordPress.org User Name'][0] ?? '';

			if ( ! empty( $username ) && $username !== $old_username ) {
				do_action( 'wcor_mentor_assigned_or_changed', get_post( $post_id ) );
			}
		}

		/**
		 * Update venue or host region geolocation data if address has changed.
		 *
		 * These are used for the maps on Central, stats, etc.
		 *
		 * @param int   $post_id              Post id.
		 * @param array $original_meta_values Original meta values before save.
		 * @param bool  $force_update         `true` to force an update even if the address hasn't changed.
		 */
		public function update_venue_address( $post_id, $original_meta_values, $force_update = false ) {
			if ( $this->get_event_type() !== get_post_type( $post_id ) ) {
				return;
			}

			//phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in `metabox_save` in class-event-admin.php.
			$address_key = self::get_address_key( $post_id );
			$new_address = $_POST[ wcpt_key_to_str( $address_key, 'wcpt_' ) ];
			$address_changed = empty( $original_meta_values[ $address_key ][0] ) || $new_address !== $original_meta_values[ $address_key ][0];

			// No need to geocode if it hasn't changed.
			if ( ! $address_changed && ! $force_update ) {
				return;
			}

			/*
			 * Clear out old values in case the event type switched. It's simpler to clear them for the current type too, since they'll get re-added next.
			 *
			 * They're deleted even if the geocoding request failed, because the old ones won't match the new address value. The user will be shown an error
			 * if the geocoding didn't work, so they'll know they need to try again.
			 */
			foreach ( self::get_venue_address_meta_keys() as $key ) {
				delete_post_meta( $post_id, $key );
			}

			if ( empty( $new_address ) ) {
				return;
			}

			$request_url = add_query_arg(
				array(
					'address' => rawurlencode( $new_address ),
				),
				'https://maps.googleapis.com/maps/api/geocode/json'
			);

			$key = apply_filters( 'wordcamp_google_maps_api_key', '', 'server' );

			if ( $key ) {
				$request_url = add_query_arg(
					array( 'key' => $key ),
					$request_url
				);
			}

			$response = wcorg_redundant_remote_get( $request_url );
			$body     = json_decode( wp_remote_retrieve_body( $response ) );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || 'OK' !== $body->status ) {
				Logger\log( 'geocoding_failure', compact( 'request_url', 'response' ) );
			}

			$meta_values = $this->parse_geocode_response( $response );
			$key_prefix  = self::get_address_key_prefix( $post_id );

			foreach ( $meta_values as $key => $value ) {
				$key = $key_prefix . $key;

				if ( ! is_null( $value ) ) {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		/**
		 * Get the name of the field that stores the address.
		 *
		 * @param int $post_id
		 *
		 * @return string
		 */
		public static function get_address_key( $post_id ) {
			return self::is_virtual_event( $post_id ) ? 'Host region' : 'Physical Address';
		}

		/**
		 * Get the prefix used with geocoded address parts.
		 *
		 * @param int $post_id
		 *
		 * @return string
		 */
		public static function get_address_key_prefix( $post_id ) {
			return self::is_virtual_event( $post_id ) ? '_host_' : '_venue_';
		}

		/**
		 * Parse the values we want out of the Geocode API response
		 *
		 * @see https://developers.google.com/maps/documentation/geocoding/intro#Types API response schema
		 *
		 * @param array $response
		 *
		 * @return array
		 */
		protected function parse_geocode_response( $response ) {
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			$body = isset( $body->results[0] ) ? $body->results[0] : null;

			if ( isset( $body->geometry->location->lat ) ) {
				$coordinates = array(
					'latitude'  => $body->geometry->location->lat,
					'longitude' => $body->geometry->location->lng,
				);
			}

			if ( isset( $body->address_components ) ) {
				foreach ( $body->address_components as $component ) {
					foreach ( $component->types as $type ) {
						switch ( $type ) {
							case 'locality':
							case 'administrative_area_level_1':
							case 'postal_code':
							case 'street_number':
							case 'route':
								$$type = $component->long_name;
								break;

							case 'country':
								$country_code = $component->short_name; // This is not guaranteed to be ISO 3166-1 alpha-2, but should match in most cases.
								$country_name = $component->long_name;
								break;

						}
					}
				}
			}

			$values = array(
				'coordinates'   => $coordinates ?? null,
				'street_name'   => $route ?? null,
				'street_number' => $street_number ?? null,
				'city'          => $locality ?? null,
				'state'         => $administrative_area_level_1 ?? null,
				'country_code'  => $country_code ?? null,
				'country_name'  => $country_name ?? null,
				'zip'           => $postal_code ?? null,
			);

			return $values;
		}

		/**
		 * Add the Mentor as an administrator on the given site.
		 *
		 * @param WP_Post $wordcamp        WordCamp post object.
		 * @param string  $mentor_username Mentor's WP.org user login.
		 */
		protected function add_mentor( $wordcamp, $mentor_username ) {
			$blog_id    = get_wordcamp_site_id( $wordcamp );
			$new_mentor = wcorg_get_user_by_canonical_names( $mentor_username );

			if ( ! $blog_id || ! $new_mentor ) {
				return;
			}

			add_user_to_blog( $blog_id, $new_mentor->ID, 'administrator' );
		}

		/**
		 * Returns the names and types of post meta fields that have corresponding UI fields.
		 *
		 * For keys that don't have UI, see `get_venue_address_meta_keys()` and any similar functions.
		 *
		 * @param string $meta_group
		 *
		 * @return array
		 */
		public static function meta_keys( $meta_group = '' ) {
			/*
			 * Warning: These keys are used for both the input field label and the postmeta key, so if you want to
			 * modify an existing label then you'll also need to migrate any rows in the database to use the new key.
			 *
			 * Some of them are also exposed via the JSON API, so you'd need to build in a back-compat layer for that
			 * as well.
			 *
			 * When adding new keys, updating the wcorg-json-api plugin to either whitelist it, or test that it's not
			 * being exposed.
			 */

			switch ( $meta_group ) {
				case 'organizer':
					$retval = array(
						'Organizer Name'                   => 'text',
						'WordPress.org Username'           => 'text',
						'Email Address'                    => 'text', // Note: This is the lead organizer's e-mail address, which is different than the "E-mail Address" field.
						'Telephone'                        => 'text',
						'Mailing Address'                  => 'textarea',
						'Sponsor Wrangler Name'            => 'text',
						'Sponsor Wrangler E-mail Address'  => 'text',
						'Budget Wrangler Name'             => 'text',
						'Budget Wrangler E-mail Address'   => 'text',
						'Venue Wrangler Name'              => 'text',
						'Venue Wrangler E-mail Address'    => 'text',
						'Speaker Wrangler Name'            => 'text',
						'Speaker Wrangler E-mail Address'  => 'text',
						'Food/Beverage Wrangler Name'      => 'text',
						'Food/Beverage Wrangler E-mail Address' => 'text',
						'Swag Wrangler Name'               => 'text',
						'Swag Wrangler E-mail Address'     => 'text',
						'Volunteer Wrangler Name'          => 'text',
						'Volunteer Wrangler E-mail Address' => 'text',
						'Printing Wrangler Name'           => 'text',
						'Printing Wrangler E-mail Address' => 'text',
						'Design Wrangler Name'             => 'text',
						'Design Wrangler E-mail Address'   => 'text',
						'Website Wrangler Name'            => 'text',
						'Website Wrangler E-mail Address'  => 'text',
						'Social Media/Publicity Wrangler Name' => 'text',
						'Social Media/Publicity Wrangler E-mail Address' => 'text',
						'A/V Wrangler Name'                => 'text',
						'A/V Wrangler E-mail Address'      => 'text',
						'Party Wrangler Name'              => 'text',
						'Party Wrangler E-mail Address'    => 'text',
						'Travel Wrangler Name'             => 'text',
						'Travel Wrangler E-mail Address'   => 'text',
						'Safety Wrangler Name'             => 'text',
						'Safety Wrangler E-mail Address'   => 'text',
						'Mentor WordPress.org User Name'   => 'text',
						'Mentor Name'                      => 'text',
						'Mentor E-mail Address'            => 'text',
					);

					break;

				case 'venue':
					$retval = array(
						// Online
						'Virtual event only'         => 'checkbox',
						'Streaming account to use'   => 'select-streaming',
						'Host region'                 => 'textarea',

						// In-person
						'Venue Name'                 => 'text',
						'Physical Address'           => 'textarea',
						'Maximum Capacity'           => 'text',
						'Available Rooms'            => 'text',
						'Website URL'                => 'text',
						'Contact Information'        => 'textarea',
						'Exhibition Space Available' => 'checkbox',
					);
					break;

				case 'contributor':
					// These fields names need to be unique, hence the 'Contributor' prefix on each one.
					$retval = array(
						'Contributor Day'                => 'checkbox',
						'Contributor Day Date (YYYY-mm-dd)' => 'date',
						'Contributor Venue Name'         => 'text',
						'Contributor Venue Address'      => 'textarea',
						'Contributor Venue Capacity'     => 'text',
						'Contributor Venue Website URL'  => 'text',
						'Contributor Venue Contact Info' => 'textarea',
					);
					break;

				case 'wordcamp':
					$retval = array(
						'Start Date (YYYY-mm-dd)'           => 'date',
						'End Date (YYYY-mm-dd)'             => 'date',
						'Event Timezone'                    => 'select-timezone',
						'Location'                          => 'text',
						'URL'                               => 'wc-url',
						'Secondary Site'                    => 'wc-url', // Any "secondary" site for an event, ie. Second language site or a private team site.
						'E-mail Address'                    => 'text', // The entire organizing team.
						'Twitter'                           => 'text',
						'WordCamp Hashtag'                  => 'text',
						'Number of Anticipated Attendees'   => 'text',
						'Actual Attendees'                  => 'number',
						'Language'                          => 'select-locale',
						'Multi-Event Sponsor Region'        => 'mes-dropdown',
						'Global Sponsorship Grant Currency' => 'select-currency',
						'Global Sponsorship Grant Amount'   => 'number',
						'Global Sponsorship Grant'          => 'text',
						'Running money through WPCS PBC'    => 'checkbox',
						'Transparency Report Received'      => 'checkbox',
						'Hide from Event Feeds'             => 'checkbox-delete-on-unset',
						'Series Event'                      => 'checkbox', // Campus Connect.
					);

					/*
					 * The "Transparency Report Received" checkbox can only be checked or unchecked when the current user is admin or super admin.
					 * See https://github.com/WordPress/wordcamp.org/issues/1280#issuecomment-2058571557.
					 *
					 * Same for 'Series Event'.
					 */
					if ( ! current_user_can( 'manage_options' ) ) {
						unset( $retval['Transparency Report Received'] );
						unset( $retval['Series Event'] );
					}

					/*
					 * The "Actual Attendees" field is only able to be set after the event is concluded.
					 *
					 * get_post() allows this to target the editor, allowing for report export.
					 */
					if ( get_post() && get_post_status() !== 'wcpt-closed' ) {
						unset( $retval['Actual Attendees'] );
					}

					break;

				case 'all':
				default:
					$retval = array(
						'Start Date (YYYY-mm-dd)'           => 'date',
						'End Date (YYYY-mm-dd)'             => 'date',
						'Event Timezone'                    => 'select-timezone',
						'Location'                          => 'text',
						'URL'                               => 'wc-url',
						'Secondary Site'                    => 'wc-url', // Any "secondary" site for an event, ie. Second language site or a private team site.
						'E-mail Address'                    => 'text', // The entire organizing team.
						'Twitter'                           => 'text',
						'WordCamp Hashtag'                  => 'text',
						'Number of Anticipated Attendees'   => 'text',
						'Actual Attendees'                  => 'number',
						'Language'                          => 'select-locale',
						'Multi-Event Sponsor Region'        => 'mes-dropdown',
						'Global Sponsorship Grant Currency' => 'select-currency',
						'Global Sponsorship Grant Amount'   => 'number',
						'Global Sponsorship Grant'          => 'text',
						'Running money through WPCS PBC'    => 'checkbox',
						'Transparency Report Received'      => 'checkbox',
						'Hide from Event Feeds'             => 'checkbox-delete-on-unset',
						'Series Event'                      => 'checkbox', // Campus Connect.
					);

					/*
					 * The "Transparency Report Received" checkbox can only be checked or unchecked when the current user is admin or super admin.
					 * See https://github.com/WordPress/wordcamp.org/issues/1280#issuecomment-2058571557.
					 *
					 * Same for 'Series Event'.
					 */
					if ( ! current_user_can( 'manage_options' ) ) {
						unset( $retval['Transparency Report Received'] );
						unset( $retval['Series Event'] );
					}

					/*
					 * The "Actual Attendees" field is only able to be set after the event is concluded.
					 *
					 * get_post() allows this to target the editor, allowing for report export.
					 */
					if ( get_post() && get_post_status() !== 'wcpt-closed' ) {
						unset( $retval['Actual Attendees'] );
					}

					$retval = array_merge(
						$retval,
						array(
							'Organizer Name'                   => 'text',
							'WordPress.org Username'           => 'text',
							'Email Address'                    => 'text', // Lead organizer.
							'Telephone'                        => 'text',
							'Mailing Address'                  => 'textarea',
							'Sponsor Wrangler Name'            => 'text',
							'Sponsor Wrangler E-mail Address'  => 'text',
							'Budget Wrangler Name'             => 'text',
							'Budget Wrangler E-mail Address'   => 'text',
							'Venue Wrangler Name'              => 'text',
							'Venue Wrangler E-mail Address'    => 'text',
							'Speaker Wrangler Name'            => 'text',
							'Speaker Wrangler E-mail Address'  => 'text',
							'Food/Beverage Wrangler Name'      => 'text',
							'Food/Beverage Wrangler E-mail Address' => 'text',
							'Swag Wrangler Name'               => 'text',
							'Swag Wrangler E-mail Address'     => 'text',
							'Volunteer Wrangler Name'          => 'text',
							'Volunteer Wrangler E-mail Address' => 'text',
							'Printing Wrangler Name'           => 'text',
							'Printing Wrangler E-mail Address' => 'text',
							'Design Wrangler Name'             => 'text',
							'Design Wrangler E-mail Address'   => 'text',
							'Website Wrangler Name'            => 'text',
							'Website Wrangler E-mail Address'  => 'text',
							'Social Media/Publicity Wrangler Name' => 'text',
							'Social Media/Publicity Wrangler E-mail Address' => 'text',
							'A/V Wrangler Name'                => 'text',
							'A/V Wrangler E-mail Address'      => 'text',
							'Party Wrangler Name'              => 'text',
							'Party Wrangler E-mail Address'    => 'text',
							'Travel Wrangler Name'             => 'text',
							'Travel Wrangler E-mail Address'   => 'text',
							'Safety Wrangler Name'             => 'text',
							'Safety Wrangler E-mail Address'   => 'text',
							'Mentor WordPress.org User Name'   => 'text',
							'Mentor Name'                      => 'text',
							'Mentor E-mail Address'            => 'text',

							'Virtual event only'               => 'checkbox',
							'Streaming account to use'         => 'select-streaming',
							'Host region'                      => 'textarea',
							'Venue Name'                       => 'text',
							'Physical Address'                 => 'textarea',
							'Maximum Capacity'                 => 'text',
							'Available Rooms'                  => 'text',
							'Website URL'                      => 'text',
							'Contact Information'              => 'textarea',
							'Exhibition Space Available'       => 'checkbox',

							'Contributor Day'                  => 'checkbox',
							'Contributor Day Date (YYYY-mm-dd)' => 'date',
							'Contributor Venue Name'           => 'text',
							'Contributor Venue Address'        => 'textarea',
							'Contributor Venue Capacity'       => 'text',
							'Contributor Venue Website URL'    => 'text',
							'Contributor Venue Contact Info'   => 'textarea',
						)
					);

					break;

			}

			return apply_filters( 'wcpt_admin_meta_keys', $retval, $meta_group );
		}

		/**
		 * Returns the slugs of the post meta fields for the venue's address.
		 *
		 * These aren't included in `meta_keys()` because they have no corresponding UI.
		 *
		 * @return array
		 */
		public static function get_venue_address_meta_keys() {
			return array(
				'_venue_coordinates',
				'_venue_street_name',
				'_venue_street_number',
				'_venue_city',
				'_venue_state',
				'_venue_country_code',
				'_venue_country_name',
				'_venue_zip',

				'_host_coordinates',
				'_host_street_name',
				'_host_street_number',
				'_host_city',
				'_host_state',
				'_host_country_code',
				'_host_country_name',
				'_host_zip',
			);
		}

		/**
		 * Fired during admin_print_styles
		 * Adds jQuery UI
		 */
		public function admin_scripts() {

			// Edit WordCamp screen.
			if ( WCPT_POST_TYPE_ID === get_post_type() ) {

				// Default data.
				$data = array(
					'Mentors' => array(
						'l10n' => array(
							'selectLabel' => esc_html__( 'Available mentors', 'wordcamporg' ),
							'confirm'     => esc_html__( 'Update Mentor field contents?', 'wordcamporg' ),
						),
					),
				);

				// Only include mentor data if the Mentor username field is editable.
				if ( current_user_can( 'wordcamp_manage_mentors' ) ) {
					$data['Mentors']['data'] = Mentors_Dashboard\get_all_mentor_data();
				}

				wp_localize_script(
					'wcpt-admin',
					'wordCampPostType',
					$data
				);
			}
		}

		/**
		 * Add some general styling to the admin area
		 */
		public function admin_head() {
			if ( ! empty( $_GET['post_type'] ) && WCPT_POST_TYPE_ID == $_GET['post_type'] ) : ?>

			.column-title { width: 40%; }
			.column-wcpt_location, .column-wcpt_date, column-wcpt_organizer { white-space: nowrap; }

				<?php
		endif;
		}

		/**
		 * Manage the column headers
		 *
		 * @param array $columns
		 *
		 * @return array $columns
		 */
		public function column_headers( $columns ) {
			$columns = array(
				'cb'             => '<input type="checkbox" />',
				'title'          => __( 'Title',     'wordcamporg' ),
				// 'wcpt_location'    => __( 'Location', 'wordcamporg' ),
				'wcpt_date'      => __( 'Date',      'wordcamporg' ),
				'wcpt_organizer' => __( 'Organizer', 'wordcamporg' ),
				'wcpt_mentor'    => __( 'Mentor', 'wordcamporg' ),
				'wcpt_venue'     => __( 'Venue',     'wordcamporg' ),
				'wcpt_tickets'   => __( 'Tickets',   'wordcamporg' ),
				'date'           => __( 'Status',    'wordcamporg' ),
			);
			return $columns;
		}

		/**
		 * Customize the sortable columns
		 *
		 * @param array $columns List of columns.
		 * @return array $columns
		 */
		public function sortable_columns( $columns ) {
			$columns['wcpt_date'] = 'wcpt_date';

			return $columns;
		}

		/**
		 * Set default sortby to event date when viewing certain statuses.
		 */
		public function default_sortby( $query ) {
			$sortby = $_GET['orderby'] ?? '';
			$status = $_GET['post_status'] ?? '';
			if (
				! is_admin() ||
				! $query->is_main_query() ||
				$sortby ||
				WCPT_POST_TYPE_ID !== $query->get( 'post_type' )
			) {
				return;
			}

			// Mark anything between 'Approved for pre-planning' to 'Scheduled' as sorting by soonest.
			$all_status = array_keys( WordCamp_Loader::get_post_statuses() );
			$soon_status = array_slice(
				$all_status,
				array_search( 'wcpt-approved-pre-pl', $all_status, true ),
				array_search( 'wcpt-scheduled', $all_status, true ) - array_search( 'wcpt-approved-pre-pl', $all_status, true ) + 1
			);

			if ( in_array( $status, $soon_status, true ) ) {
				$query->set( 'orderby', 'wcpt_date' );
				$query->set( 'order', 'ASC' );

				// Set in the global to ensure the UI matches the query.
				$_GET['orderby'] = 'wcpt_date';
				$_GET['order']   = 'ASC';
			}
		}

		/**
		 * Customize the orderby behavior for sortable columns.
		 *
		 * @param WP_Query $query The current WP_Query instance.
		 */
		public function sort_by_event_date( $query ) {
			$orderby = $query->get( 'orderby' );
			if (
				! is_admin() ||
				! $query->is_main_query() ||
				WCPT_POST_TYPE_ID !== $query->get( 'post_type' )
			) {
				return;
			}

			if ( 'wcpt_date' === $orderby ) {
				$orderby = array(
					'key' => 'Start Date (YYYY-mm-dd)',
					'compare' => 'DATE',
				);
				$meta_query = $query->get( 'meta_query' ) ?: [];

				$meta_query['wcpt_date'] = $orderby;

				$query->set( 'meta_query', $meta_query );
				$query->set( 'orderby', 'wcpt_date' );
			}
		}

		/**
		 * Print extra columns
		 *
		 * @param string $column
		 * @param int    $post_id
		 */
		public function column_data( $column, $post_id ) {
			$post_type = wp_unslash( $_GET['post_type'] ?? '' );
			if ( WCPT_POST_TYPE_ID !== $post_type ) {
				return $column;
			}

			switch ( $column ) {
				case 'wcpt_location':
					echo esc_html( wcpt_get_wordcamp_location() ? wcpt_get_wordcamp_location() : __( 'No Location', 'wordcamporg' ) );
					break;

				case 'wcpt_date':
					// Has a start date.
					$start = wcpt_get_wordcamp_start_date( $post_id, 'Y-m-d' );
					if ( $start ) {
						// Has an end date.
						$end = wcpt_get_wordcamp_end_date( $post_id, 'Y-m-d' );
						if ( $end ) {
							$string_date = sprintf( __( 'Start: %1$s<br />End: %2$s', 'wordcamporg' ), $start, $end );
							// No end date.
						} else {
							$string_date = sprintf( __( 'Start: %1$s', 'wordcamporg' ), $start );
						}

						// No date.
					} else {
						$string_date = __( 'No Date', 'wordcamporg' );
					}

					echo wp_kses( $string_date, array( 'br' => array() ) );
					break;

				case 'wcpt_organizer':
					echo esc_html( wcpt_get_wordcamp_organizer_name() ? wcpt_get_wordcamp_organizer_name() : __( 'No Organizer', 'wordcamporg' ) );
					break;

				case 'wcpt_mentor':
					$mentor_by = get_post_meta( $post_id, 'Mentor WordPress.org User Name', true );
					$mentor_by_field = 'login';
					if ( empty( $mentor_by ) ) {
						$mentor_by = get_post_meta( $post_id, 'Mentor E-mail Address', true );
						$mentor_by_field = 'email';
					}

					$mentor = get_user_by( $mentor_by_field, $mentor_by );

					echo esc_html( is_a( $mentor, 'WP_User' ) ? $mentor->display_name : __( 'No Mentor', 'wordcamporg' ) );
					break;

				case 'wcpt_venue':
					echo esc_html( wcpt_get_wordcamp_venue_name() ? wcpt_get_wordcamp_venue_name() : __( 'No Venue', 'wordcamporg' ) );
					break;

				case 'wcpt_tickets':
					// Fetch the Camptix Stats option from the WordCamp site, if it's created.
					$site_id = get_wordcamp_site_id( get_post( $post_id ) );
					if ( ! $site_id ) {
						return;
					}
					$admin_url = get_admin_url( $site_id, 'edit.php?post_type=tix_ticket' );

					$stats             = get_blog_option( $site_id, 'camptix_stats', array() );
					$tickets_sold      = $stats['sold'] ?? 0;
					$tickets_proposed  = absint( get_post_meta( $post_id, 'Number of Anticipated Attendees', true ) );
					$tickets_capacity  = ( $tickets_sold + ( $stats['remaining'] ?? 0 ) ) ?: $tickets_proposed;

					if ( ! $tickets_sold && ! $tickets_capacity && ! $tickets_proposed ) {
						return;
					}

					printf(
						/* translators: 1: number of tickets sold, 2: total ticket capacity */
						'<a href="%s">%s</a>',
						esc_url( $admin_url ),
						esc_html(
							sprintf(
								/* translators: 1: number of tickets sold, 2: total ticket capacity */
								_x( '%1$s of %2$s', 'Tickets sold of capacity', 'wordcamporg' ),
								number_format_i18n( $tickets_sold ),
								number_format_i18n( $tickets_capacity )
							)
						)
					);
					if ( $tickets_sold && $tickets_capacity ) {
						echo '<br>' . number_format_i18n( $tickets_sold / $tickets_capacity * 100 ) . '%';
					}
					if ( $tickets_proposed ) {
						echo '<br>';
						printf(
							/* translators: %s is the number of expected tickets. */
							esc_html_x( '%s expected', 'Tickets expected', 'wordcamporg' ),
							number_format_i18n( $tickets_proposed )
						);
					}

					break;
			}
		}

		/**
		 * Remove the quick-edit action link and display the description under
		 *
		 * @param array $actions
		 * @param array $post
		 * @return array $actions
		 */
		public function post_row_actions( $actions, $post ) {
			if ( WCPT_POST_TYPE_ID == $post->post_type ) {
				unset( $actions['inline hide-if-no-js'] );

				$wc = array();

				$wc_location = wcpt_get_wordcamp_location();
				if ( $wc_location ) {
					$wc['location'] = $wc_location;
				}

				$wc_url = make_clickable( wcpt_get_wordcamp_url() );
				if ( $wc_url ) {
					$wc['url'] = $wc_url;
				}

				echo wp_kses( implode( ' - ', (array) $wc ), wp_kses_allowed_html() );
			}

			return $actions;
		}

		/**
		 * Trigger actions related to WordCamps being scheduled.
		 *
		 * @param string  $new_status
		 * @param string  $old_status
		 * @param WP_Post $post
		 */
		public function trigger_schedule_actions( $new_status, $old_status, $post ) {
			if ( empty( $post->post_type ) || WCPT_POST_TYPE_ID != $post->post_type ) {
				return;
			}

			if ( $new_status == $old_status ) {
				return;
			}

			if ( 'wcpt-pre-planning' == $new_status ) {
				do_action( 'wcpt_approved_for_pre_planning', $post );
			} elseif ( 'wcpt-needs-schedule' == $old_status && 'wcpt-scheduled' == $new_status ) {
				do_action( 'wcpt_added_to_final_schedule', $post );
			} elseif ( 'wcpt-needs-orientati' === $new_status
				&& 'campusconnect' === get_post_meta( $post->ID, 'event_subtype', true ) ) {
				// Fires when a Campus Connect application transitions to Needs Orientation.
				// Uses a dedicated action to avoid triggering the non-CC hooks that listen
				// to wcpt_approved_for_pre_planning (e.g. add_organizer_to_central).
				do_action( 'wcpt_cc_needs_orientation', $post );
			}
		}


		/**
		 * Handle a Campus Connect application transitioning to Needs Orientation.
		 *
		 * Fires on wcpt_cc_needs_orientation (see trigger_schedule_actions()), after
		 * WCOR_Mailer has triggered the organizer notification email on the same action.
		 * Writes a permanent audit note to the post log and queues a one-time admin
		 * notice so the wrangler sees confirmation on the next page load.
		 *
		 * @param WP_Post $post The Campus Connect post that needs orientation.
		 */
		public function handle_cc_needs_orientation( WP_Post $post ) {
			// Audit log note — a permanent, timestamped record of the transition. The admin
			// notice below is its transient, on-screen counterpart (similar, not identical, text).
			add_post_meta(
				$post->ID,
				'_note',
				array(
					'timestamp' => time(),
					'user_id'   => get_current_user_id(),
					'message'   => __( 'Application moved to Needs Orientation. Organizer notification email triggered.', 'wordcamporg' ),
				)
			);

			// Queue the one-time admin notice that will display after the save redirect.
			$this->active_admin_notices[] = 5;
		}

		/**
		 * Add the lead organizer to Central when a WordCamp application is accepted.
		 *
		 * Adding the lead organizer to Central allows them to enter all the `wordcamp`
		 * meta info themselves, and also post updates to the Central blog.
		 *
		 * @param WP_Post $post
		 */
		public function add_organizer_to_central( $post ) {

			// Only the admin edit screen posts this, and the same transition is reachable from
			// the Jetpack application bridge, WP-CLI and cron, where there is no form at all.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The admin edit screen has already verified its nonce.
			$username = $_POST['wcpt_wordpress_org_username'] ?? '';

			if ( ! $username ) {
				return;
			}

			$lead_organizer = get_user_by( 'login', $username );

			if ( $lead_organizer && add_user_to_blog( get_current_blog_id(), $lead_organizer->ID, 'contributor' ) ) {
				do_action( 'wcor_organizer_added_to_central', $post );
			}
		}

		/**
		 * Record when the WordCamp was added to the planning schedule.
		 *
		 * This is used by the Organizer Reminders plugin to send automated e-mails at certain points after the camp
		 * has been added to the planning schedule.
		 *
		 * @param WP_Post $wordcamp
		 */
		public function mark_date_added_to_planning_schedule( $wordcamp ) {
			update_post_meta( $wordcamp->ID, '_timestamp_added_to_planning_schedule', time() );
		}

		/**
		 * Send notification to slack when a WordCamp is scheduled or declined. Runs whenever status of an applications changes
		 *
		 * @param string  $new_status
		 * @param string  $old_status
		 * @param WP_Post $wordcamp
		 *
		 * @return null|bool
		 */
		public function notify_application_status_in_slack( $new_status, $old_status, WP_Post $wordcamp ) {
			if ( 'wcpt-scheduled' === $new_status && 'wcpt-scheduled' !== $old_status ) {
				return $this->notify_new_wordcamp_in_slack( $wordcamp );

			} elseif ( 'wcpt-rejected' === $new_status && 'wcpt-rejected' !== $old_status ) {
				$location = get_post_meta( $wordcamp->ID, 'Location', true );
				return $this->schedule_decline_notification( $wordcamp, 'WordCamp', $location );
			}
		}

		/**
		 * Send notification when a new WordCamp comes in scheduled status.
		 *
		 * @param WP_Post $wordcamp
		 *
		 * @return null|bool|string
		 */
		public static function notify_new_wordcamp_in_slack( $wordcamp ) {
			$scheduled_notification_key = 'sent_scheduled_notification';
			if ( get_post_meta( $wordcamp->ID, $scheduled_notification_key, true ) ) {
				return null;
			}

			// Not translating any string because they will be sent to slack.
			$start_date   = absint( get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true ) );
			$wordcamp_url = get_post_meta( $wordcamp->ID, 'URL', true );
			$is_event     = is_event_url( $wordcamp_url );
			$title        = sprintf( 'New %s scheduled!!!', $is_event ? 'Next Generation Event' : 'WordCamp' );

			/*
			 * `post_title` can hold `&lt;` for a `<` the applicant typed, see
			 * `wcorg_sanitize_plain_text()`. Slack decodes that back to `<` in mrkdwn, so it reads
			 * correctly here -- worth knowing before this string is reused somewhere that does not.
			 */
			$message = sprintf(
				"<%s|%s> has been scheduled for a start date of %s. :tada: :community: :WordPress:\n\n%s",
				$wordcamp_url,
				$wordcamp->post_title,
				$start_date ? gmdate( 'F j, Y', $start_date ) : '(not set)',
				$wordcamp_url
			);

			$attachment = create_event_status_attachment( $message, $wordcamp->ID, $title );

			$notification_sent = wcpt_slack_notify( COMMUNITY_EVENTS_SLACK, $attachment );
			if ( $notification_sent ) {
				update_post_meta( $wordcamp->ID, $scheduled_notification_key, true );
			}
			return $notification_sent;
		}

		/**
		 * Prevent WordCamp posts from being set to pending or published until all the required fields are completed.
		 *
		 * @param array $post_data
		 * @param array $post_data_raw
		 * @return array
		 */
		public function require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw ) {
			if ( WCPT_POST_TYPE_ID != $post_data['post_type'] ) {
				return $post_data;
			}

			// The ID of the last site that was created before this rule went into effect, so that we don't apply the rule retroactively.
			$min_site_id = apply_filters( 'wcpt_require_complete_meta_min_site_id', '2416297' );

			$required_needs_site_fields = $this->get_required_fields( 'needs-site', $post_data_raw['ID'] );
			$required_scheduled_fields  = $this->get_required_fields( 'scheduled', $post_data_raw['ID'] );

			// Needs Site.
			if ( 'wcpt-needs-site' == $post_data['post_status'] && absint( $post_data_raw['ID'] ) > $min_site_id ) {
				foreach ( $required_needs_site_fields as $field ) {

					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce check would have done in `metabox_save`.
					$value = $_POST[ wcpt_key_to_str( $field, 'wcpt_' ) ] ?? '';

					if ( empty( $value ) || 'null' == $value ) {
						$post_data['post_status']     = 'wcpt-needs-email';
						$this->active_admin_notices[] = 1;
						break;
					}
				}
			}

			// Scheduled.
			if ( 'wcpt-scheduled' == $post_data['post_status'] && isset( $post_data_raw['ID'] ) && absint( $post_data_raw['ID'] ) > $min_site_id ) {
				foreach ( $required_scheduled_fields as $field ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce check would have done in `metabox_save`.
					$value = $_POST[ wcpt_key_to_str( $field, 'wcpt_' ) ] ?? '';

					if ( empty( $value ) || 'null' == $value ) {
						// Campus Connect posts revert to Approved For Pre-Planning on validation failure;
						// non-CC posts use the standard Needs to be Added to Official Schedule fallback.
						$post_data['post_status']     = WordCamp_Status_Guard::is_campus_connect_post_for_save( $post_data_raw['ID'] )
							? 'wcpt-approved-pre-pl'
							: 'wcpt-needs-schedule';
						$this->active_admin_notices[] = 3;
						break;
					}
				}
			}

			return $post_data;
		}

		/**
		 * Get a list of fields required to move to a certain post status
		 *
		 * @param string $status 'needs-site' | 'scheduled' | 'any'.
		 *
		 * @return array
		 */
		public static function get_required_fields( $status, $post_id ) {
			$needs_site = array(
				'E-mail Address', // The entire organizing team.
				'Event Timezone',
			);

			$scheduled = array(
				// WordCamp.
				'Start Date (YYYY-mm-dd)',
				'Location',
				'URL',
				'E-mail Address', // The entire organizing team.
				'Number of Anticipated Attendees',
				'Multi-Event Sponsor Region',

				// Organizing Team.
				'Organizer Name',
				'WordPress.org Username',
				'Email Address', // Lead organizer.
				'Telephone',
				'Mailing Address',
				'Sponsor Wrangler Name',
				'Sponsor Wrangler E-mail Address',
				'Budget Wrangler Name',
				'Budget Wrangler E-mail Address',
			);

			// Venue Contact Information is required for Campus Connect events.
			if ( 'campusconnect' === get_post_meta( $post_id, 'event_subtype', true ) ) {
				$scheduled[] = 'Contact Information';
			}

			// Required because the Events Widget needs a physical address in order to show events.
			$scheduled[] = self::get_address_key( $post_id );

			switch ( $status ) {
				case 'needs-site':
					$required_fields = $needs_site;
					break;

				case 'scheduled':
					$required_fields = $scheduled;
					break;

				case 'any':
				default:
					$required_fields = array_merge( $needs_site, $scheduled );
					break;
			}

			return $required_fields;
		}

		/**
		 * Determine if this WordCamp is virtual or in-person.
		 *
		 * @param $post_id
		 *
		 * @return bool
		 */
		public static function is_virtual_event( $post_id ) {
			$is_virtual_event = false;
			$submitting_form  = isset( $_POST['action'] ) && 'editpost' === $_POST['action'];

			/*
			 * Using the database value when the form is being submitted could result in the wrong value being
			 * returned; e.g., when changing from in-person to online.
			 */
			if ( $submitting_form ) {
				$form_value = $_POST[ wcpt_key_to_str( 'Virtual event only', 'wcpt_' ) ] ?? false;

				if ( 'on' === $form_value ) {
					$is_virtual_event = true;
				}
			} else {
				$database_value = get_post_meta( $post_id, 'Virtual event only', true );

				if ( '1' === $database_value ) {
					$is_virtual_event = true;
				}
			}

			return $is_virtual_event;
		}

		/**
		 * TODO: Add description.
		 *
		 * @return array
		 */
		public static function get_protected_fields() {
			$protected_fields = array();

			if ( ! current_user_can( 'wordcamp_manage_mentors' ) ) {
				$protected_fields = array_merge(
					$protected_fields,
					array(
						'Mentor WordPress.org User Name',
						'Mentor Name',
						'Mentor E-mail Address',
					)
				);
			}

			if ( ! current_user_can( 'wordcamp_wrangle_wordcamps' ) ) {
				$protected_fields = array_merge(
					$protected_fields,
					array(
						'Multi-Event Sponsor Region',
						'Series Event',
					)
				);
			}

			return $protected_fields;
		}

		/**
		 * Check if a field should be readonly, based on the current user's caps.
		 *
		 * @param string $field_name The field to check.
		 *
		 * @return bool
		 */
		public static function is_protected_field( $field_name ) {
			$protected_fields = self::get_protected_fields();

			return in_array( $field_name, $protected_fields );
		}

		/**
		 * Return admin notices for messages that were passed in the URL.
		 */
		public function get_admin_notices() {
			global $post;

			$screen = get_current_screen();

			if ( empty( $post->post_type ) || $this->get_event_type() != $post->post_type || 'post' !== $screen->base ) {
				return array();
			}

			// Show this error permanently, not just after updating.
			$address = get_post_meta( $post->ID, self::get_address_key( $post->ID ), true );

			if ( $address && ! self::have_geocoded_location( $post->ID ) ) {
				$_REQUEST['wcpt_messages'] = empty( $_REQUEST['wcpt_messages'] ) ? '4' : $_REQUEST['wcpt_messages'] . ',4';
			}

			return array(
				1 => array(
					'type'   => 'error',
					'notice' => sprintf(
						__( 'This WordCamp cannot be moved to Needs Site until all of its required metadata is filled in: %s.', 'wordcamporg' ),
						implode( ', ', $this->get_required_fields( 'needs-site', $post->ID ) )
					),
				),

				3 => array(
					'type'   => 'error',
					'notice' => sprintf(
						__( 'This WordCamp cannot be added to the schedule until all of its required metadata is filled in: %s.', 'wordcamporg' ),
						implode( ', ', $this->get_required_fields( 'scheduled', $post->ID ) )
					),
				),

				4 => array(
					'type'   => 'error',
					// translators: %s is the name of a form field, either 'Physical Address', or 'Host region'.
					'notice' => sprintf(
						__( 'The %s could not be geocoded, which prevents the camp from showing up in the Events Widget. Please tweak the address so that Google Maps can parse it.', 'wordcamporg' ),
						self::get_address_key( $post->ID )
					),
				),

				5 => array(
					'type'   => 'updated',
					'notice' => __( 'This Campus Connect application has been moved to Needs Orientation. The organizer notification email has been triggered and a note has been added to the log.', 'wordcamporg' ),
				),
			);

		}

		/**
		 * Check if the post has geolocation data.
		 *
		 * @param $post_id
		 *
		 * @return bool
		 */
		public static function have_geocoded_location( $post_id ) {
			$address_value = get_post_meta( $post_id, self::get_address_key( $post_id ), true );
			$coordinates   = get_post_meta( $post_id, self::get_address_key_prefix( $post_id ) . 'coordinates', true );

			// Some bits like `city` are expected to be missing sometimes, but we should always have `lat/long`.
			return ! empty( $address_value ) && ! empty( $coordinates['latitude'] );
		}

		/**
		 * Get list of valid status transitions from given status.
		 *
		 * For Campus Connect posts, returns the CC-specific transition map.
		 *
		 * @param string $status
		 * @return array
		 */
		public static function get_valid_status_transitions( $status ) {
			if ( self::is_campus_connect_post() ) {
				return WordCamp_Loader::get_campus_connect_status_transitions( $status );
			}

			return WordCamp_Loader::get_valid_status_transitions( $status );
		}

		/**
		 * Get list of all available post statuses.
		 *
		 * For Campus Connect posts, returns the nine CC-specific statuses.
		 * For all other subtypes, returns the full global list minus the
		 * CC-exclusive status (wcpt-needs-action).
		 *
		 * @return array Associative array of status slug => label.
		 */
		public static function get_post_statuses() {
			if ( self::is_campus_connect_post() ) {
				return WordCamp_Loader::get_campus_connect_statuses();
			}

			$statuses = WordCamp_Loader::get_post_statuses();
			unset( $statuses['wcpt-needs-action'] );

			return $statuses;
		}

		/**
		 * Return the human-readable label for a post status slug.
		 *
		 * For Campus Connect posts, returns CC-specific labels (e.g. "Approved For Pre-Planning"
		 * instead of the global "Approved for Pre-Planning Pending Agreement").
		 *
		 * @param string  $status Post status slug.
		 * @param WP_Post $post   The post being transitioned.
		 * @return string Human-readable label.
		 */
		protected function get_status_label( $status, $post ) {
			if ( 'campusconnect' === get_post_meta( $post->ID, 'event_subtype', true ) ) {
				$cc_statuses = WordCamp_Loader::get_campus_connect_statuses();

				return $cc_statuses[ $status ] ?? parent::get_status_label( $status, $post );
			}

			return parent::get_status_label( $status, $post );
		}

		/**
		 * Check whether the post currently being edited is a Campus Connect event.
		 *
		 * Reads `event_subtype` post meta (stored as lowercase with underscore by
		 * class-event-admin.php via update_post_meta). Falls back to the `post` query
		 * variable on admin edit screens where get_the_ID() is not yet populated.
		 *
		 * @return bool
		 */
		protected static function is_campus_connect_post() {
			$post_id = get_the_ID();

			// Fallback for admin edit screens where get_the_ID() may not be set yet.
			if ( ! $post_id ) {
				$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) );
			}

			return $post_id && 'campusconnect' === get_post_meta( $post_id, 'event_subtype', true );
		}

		/**
		 * Capability required to edit wordcamp posts
		 *
		 * @return string
		 */
		public static function get_edit_capability() {
			return 'wordcamp_wrangle_wordcamps';
		}

		/**
		 * Return a list of valid Event Subtypes.
		 *
		 * @return array
		 */
		public function get_event_subtypes() {
			return array(
				'wordcamp'      => __( 'WordCamp', 'wordcamporg' ),
				'doaction'      => __( 'DoAction', 'wordcamporg' ),
				'campusconnect' => __( 'Campus Connect', 'wordcamporg' ),
				'student-club'  => __( 'Student Club', 'wordcamporg' ),
				'other'         => __( 'Other Event', 'wordcamporg' ),
			);
		}

		/**
		 * Return the Event Subtype the list table is filtered to, if it names a real one.
		 *
		 * `alter_views()` uses the value as an array key into `get_event_subtypes()` and
		 * splices it into the view markup, and `filter_by_subtype()` puts it in a meta
		 * query, so anything outside the list has to collapse to no filter rather than
		 * travel on to either.
		 *
		 * `$_GET` because the only producer is a link, the subtype links below.
		 * `edit.php`'s `posts-filter` form carries no `type` field, so searching or
		 * date-filtering drops the subtype. That is how it behaved before too.
		 *
		 * @return string A key of `get_event_subtypes()`, or an empty string.
		 */
		public function get_requested_subtype() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter for the list table.
			$subtype = sanitize_text_field( wp_unslash( $_GET['type'] ?? '' ) );

			return array_key_exists( $subtype, $this->get_event_subtypes() ) ? $subtype : '';
		}

		/**
		 * Schedule cron jobs
		 */
		public function schedule_cron_jobs() {
			if ( wp_next_scheduled( 'wcpt_close_wordcamps_after_event' ) ) {
				return;
			}

			wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'wcpt_close_wordcamps_after_event' );
		}

		/**
		 * Set WordCamp posts to the Closed status after the event is over
		 */
		public function close_wordcamps_after_event() {
			$scheduled_wordcamps = get_posts(
				array(
					'post_type'      => WCPT_POST_TYPE_ID,
					'post_status'    => 'wcpt-scheduled',
					'posts_per_page' => -1,
				)
			);

			foreach ( $scheduled_wordcamps as $wordcamp ) {
				$start_date = get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true );
				$end_date   = get_post_meta( $wordcamp->ID, 'End Date (YYYY-mm-dd)', true );

				if ( empty( $start_date ) ) {
					continue;
				}

				if ( empty( $end_date ) ) {
					$end_date = $start_date;
				}

				$end_date_at_midnight = strtotime( '23:59', $end_date );    // $end_date is the date at time 00:00, but the event isn't over until 23:59

				if ( $end_date_at_midnight > time() ) {
					continue;
				}

				wp_update_post(
					array(
						'ID'          => $wordcamp->ID,
						'post_status' => 'wcpt-closed',
					)
				);

				// If the 'Actual Attendees' field isn't yet set, set it to the camptix sales.
				$actual_attendees = get_post_meta( $wordcamp->ID, 'Actual Attendees', true );
				if ( empty( $actual_attendees ) ) {
					$site_id = get_wordcamp_site_id( $wordcamp );
					if ( $site_id ) {
						// Use attendees checked in, falling back to tickets sold.
						$camptix_stats      = get_blog_option( $site_id, 'camptix_stats', array() );
						$attendees_attended = ( $camptix_stats['attended'] ?? 0 ) ?: ( $camptix_stats['sold'] ?? 0 );

						// Assume sales were not through Camptix if less than 10 tickets total.
						if ( $attendees_attended >= 10 ) {
							update_post_meta( $wordcamp->ID, 'Actual Attendees', $attendees_attended );
						}
					}
				}
			}
		}

		/**
		 * Add a dropdown to filter by Event Subtype, and a "Mine (Mentoring)" view.
		 *
		 * This is hacked in by abusing the `views` filter for the wordcamp PT.
		 */
		public function alter_views( $views ) {
			global $wp_list_table;

			// Everyone else keeps the plain status links, counted over their own applications
			// by `scope_status_counts()`. The views below are wrangler tools.
			if ( ! current_user_can( self::get_edit_capability() ) ) {
				return $views;
			}

			// Add the "Mine (Mentoring)" view right after the "Mine" view.
			$current_user = wp_get_current_user();

			if ( $current_user && $current_user->exists() ) {
				$count = new WP_Query( array(
					'post_type'      => WCPT_POST_TYPE_ID,
					'meta_key'       => 'Mentor WordPress.org User Name',
					'meta_value'     => $current_user->user_login,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				) );

				$class = '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used for highlighting the active tab.
				if ( isset( $_GET['mentoring'] ) ) {
					$class = 'current';
				}

				$url = add_query_arg(
					array(
						'post_type' => WCPT_POST_TYPE_ID,
						'mentoring' => $current_user->user_login,
					),
					admin_url( 'edit.php' )
				);

				$mentoring_view = sprintf(
					'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
					esc_url( $url ),
					esc_attr( $class ),
					__( 'Mine (Mentoring)', 'wordcamporg' ),
					$count->found_posts
				);

				// Insert after the "Mine" view if it exists, otherwise append.
				$mine_pos = array_search( 'mine', array_keys( $views ), true );

				if ( false !== $mine_pos ) {
					$views = array_slice( $views, 0, $mine_pos + 1, true )
						+ array( 'mentoring' => $mentoring_view )
						+ array_slice( $views, $mine_pos + 1, null, true );
				} else {
					$views['mentoring'] = $mentoring_view;
				}
			}

			$current_subtype = $this->get_requested_subtype();

			// If we're currently filtering to a type, regenerate the Views, as the counts and available statii need updating.
			if ( $current_subtype ) {
				static $filtering = false;
				if ( $filtering ) {
					return $views;
				}
				$filtering = true;

				add_filter(
					'wp_count_posts',
					$cb = function ( $counts ) use( $current_subtype ) {
						global $wpdb;

						// NOTE: This skips the $permission checks, as these are not sensitive statii.

						$results = (array) $wpdb->get_results(
							$wpdb->prepare(
								"SELECT post_status, COUNT( * ) AS num_posts
								FROM {$wpdb->posts}
									JOIN {$wpdb->postmeta} ON ( {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id AND {$wpdb->postmeta}.meta_key = 'event_subtype' AND {$wpdb->postmeta}.meta_value = %s )
								WHERE post_type = %s
								GROUP BY post_status",
								$current_subtype,
								WCPT_POST_TYPE_ID
							)
						);

						$counts = array_fill_keys( array_keys( (array) $counts ), 0 );

						foreach ( $results as $row ) {
							$counts[ $row->post_status ] = $row->num_posts;
						}

						return (object) $counts;
					}
				);

				$views = $wp_list_table->get_views();

				remove_filter( 'wp_count_posts', $cb );

				$filtering = false;
			}

			$base_url = admin_url( 'edit.php?post_type=' . WCPT_POST_TYPE_ID );
			if ( isset( $_GET['post_status'] ) ) {
				$base_url = add_query_arg( 'post_status', sanitize_text_field( wp_unslash( $_GET['post_status'] ) ), $base_url );
			}
			?>
			<ul class="subsubsub" style="float: none">
				<li class="all">
					<a href="<?php echo esc_url( $base_url ); ?>" <?php if ( ! $current_subtype ) echo 'class="current"'; ?>>All Events</a>
				</li>
				<?php foreach ( $this->get_event_subtypes() as $subtype_key => $subtype_label ) : ?>
					<li class="<?php echo esc_attr( $subtype_key ); ?>">
						| <a href="<?php echo esc_url( add_query_arg( 'type', $subtype_key, $base_url ) ); ?>"  <?php if ( $current_subtype === $subtype_key ) echo 'class="current"'; ?>>
							<?php echo esc_html( $subtype_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php

			if ( $current_subtype ) {
				foreach ( $views as $key => &$html ) {
					$html = str_replace( 'post_type=wordcamp', 'post_type=wordcamp&#038;type=' . $current_subtype, $html );

					// Replace the Label too, e.g., "WordCamp (10)" becomes "DoAction (10)". Only applies to the views list.
					// Core echoes these strings unescaped, so the label is escaped here like the one above.
					$html = str_replace( 'WordCamp', esc_html( $this->get_event_subtypes()[ $current_subtype ] ), $html );
				}

				// Remove the "Mine" filter, as this isn't compatible with subtype filtering.. and isn't relevant usually for wranglers.
				unset( $views['mine'] );
			}

			return $views;
		}

		/**
		 * Filter the WordCamp list query when the "Mine (Mentoring)" view is active.
		 *
		 * @param WP_Query $query The query to filter.
		 */
		public function filter_mentoring_view( $query ) {
			if ( ! $this->is_wordcamp_list_query( $query ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter for list table view.
			if ( ! isset( $_GET['mentoring'] ) ) {
				return;
			}

			$current_user = wp_get_current_user();

			$meta_query   = $query->get( 'meta_query' ) ?: [];
			$meta_query[] = array(
				'key'   => 'Mentor WordPress.org User Name',
				'value' => $current_user->user_login,
			);
			$query->set( 'meta_query', $meta_query );
		}

		/**
		 * Limit the WordCamp list table to the applications the current user can edit.
		 *
		 * `wp_edit_posts_query()` asks for `perm => 'readable'` when a status is filtered, and
		 * `readable` author-restricts the literal `private` status only. This workflow is
		 * expressed in custom statuses, so the scope belongs here.
		 *
		 * @param WP_Query $query The query to filter.
		 */
		public function limit_list_to_editable_wordcamps( $query ) {
			if ( ! $this->is_wordcamp_list_query( $query ) ) {
				return;
			}

			/*
			 * `WP_Posts_List_Table::__construct()` sets `$_GET['author']` for a viewer who has
			 * authored one of these and lacks the type's `edit_others_posts`, which is
			 * `edit_others_wordcamps` and maps to the curating capability. That narrows the
			 * default screen to their own rows, which is wrong for everyone here: it hides the
			 * camps a scoped viewer only mentors while the status links still count them, and
			 * it opens an exempt viewer on Mine when they are entitled to the whole list. Core
			 * only injects when the request named no author, so choosing Mine survives it.
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which view was asked for, not acting on it.
			if ( empty( $_REQUEST['author'] ) ) {
				$query->set( 'author', '' );

				// `get_views()` and `is_base_request()` read `$_GET` rather than the query, so
				// leaving it there marks Mine active over a table showing more than that.
				unset( $_GET['author'] );
			}

			// Wranglers curate the pipeline, and a Central administrator already administers
			// everything on this site, so both keep the whole list.
			if ( current_user_can( self::get_edit_capability() ) || current_user_can( 'manage_options' ) ) {
				return;
			}

			$ids = wp_list_pluck( $this->get_authored_or_mentored_wordcamps(), 'ID' );

			// An empty set has to be spelled out, or `post__in` is ignored and the
			// unrestricted list comes back.
			$query->set( 'post__in', $ids ?: array( 0 ) );

			// The status links come from `wp_count_posts()`, which has the same blind spot.
			add_filter( 'wp_count_posts', array( $this, 'scope_status_counts' ), 10, 2 );
		}

		/**
		 * Count the statuses over the same applications the list table is showing.
		 *
		 * Only rewrites this post type, so anything else counting in the request is untouched.
		 *
		 * @param object $counts Status counts, keyed by status name.
		 * @param string $type   The post type being counted.
		 *
		 * @return object
		 */
		public function scope_status_counts( $counts, $type ) {
			if ( WCPT_POST_TYPE_ID !== $type ) {
				return $counts;
			}

			$scoped = array_count_values(
				wp_list_pluck( $this->get_authored_or_mentored_wordcamps(), 'post_status' )
			);

			// Keep core's keys, so a status with none left reads 0 rather than disappearing.
			return (object) array_merge( array_fill_keys( array_keys( (array) $counts ), 0 ), $scoped );
		}

		/**
		 * The WordCamp posts the current user authored or mentors.
		 *
		 * Wider than what they can edit: an author cannot edit their own camp once it is
		 * scheduled, but should still see it listed. Matches a mentor by login or nicename,
		 * the pair `map_subrole_caps()` resolves.
		 *
		 * @return WP_Post[]
		 */
		protected function get_authored_or_mentored_wordcamps() {
			$user = wp_get_current_user();

			if ( ! $user->exists() ) {
				return array();
			}

			if ( isset( $this->own_wordcamps[ $user->ID ] ) ) {
				return $this->own_wordcamps[ $user->ID ];
			}

			$common = array(
				'post_type'              => WCPT_POST_TYPE_ID,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$authored = get_posts( array( 'author' => $user->ID ) + $common );

			$mentored = get_posts(
				array(
					'update_post_meta_cache' => true,
					'meta_query'             => array(
						array(
							'key'     => 'Mentor WordPress.org User Name',
							'value'   => array( $user->user_login, $user->user_nicename ),
							'compare' => 'IN',
						),
					),
				) + $common
			);

			/*
			 * That compare is a prefilter, not the answer. It also matches a camp whose mentor
			 * name is somebody else's login that happens to be this user's nicename, and
			 * `map_subrole_caps()` resolves the stored name login-first, so the two would
			 * name different mentors for the same camp. Resolve it the same way, so the list
			 * and the capability system agree, and keep what comes back as this user.
			 */
			// Keyed by name, not by camp: `WP_User::get_data_by()` does not cache a miss, so a
			// name stored as a nicename costs a failed `user_login` lookup every time it is
			// resolved, and a mentor's camps normally carry one or two distinct spellings.
			$resolved = array();

			$mentored = array_filter(
				$mentored,
				function ( $camp ) use ( $user, &$resolved ) {
					$name = get_post_meta( $camp->ID, 'Mentor WordPress.org User Name', true );

					if ( ! isset( $resolved[ $name ] ) ) {
						$mentor            = wcorg_get_user_by_canonical_names( $name );
						$resolved[ $name ] = $mentor ? $mentor->ID : 0;
					}

					return $resolved[ $name ] === $user->ID;
				}
			);

			// A user can both author and mentor the same camp.
			$this->own_wordcamps[ $user->ID ] = array_values(
				array_column( array_merge( $authored, $mentored ), null, 'ID' )
			);

			return $this->own_wordcamps[ $user->ID ];
		}

		/**
		 * Whether a query is the one behind the WordCamp admin list table.
		 *
		 * @param WP_Query $query The query to test.
		 *
		 * @return bool
		 */
		protected function is_wordcamp_list_query( $query ) {
			return is_admin() && $query->is_main_query() && self::get_event_type() === $query->get( 'post_type' );
		}

		/**
		 * Filter the WordCamp list by Event Subtype.
		 */
		public function filter_by_subtype( $query ) {
			if (
				! $query->is_main_query() ||
				WCPT_POST_TYPE_ID !== $query->get( 'post_type' )
			) {
				return;
			}

			$type = $this->get_requested_subtype();

			if ( ! $type ) {
				return;
			}

			$meta_query = $query->get( 'meta_query' ) ?: [];

			$meta_query[] = array(
				'key'     => 'event_subtype',
				'value'   => $type,
				'compare' => '=',
			);

			$query->set( 'meta_query', $meta_query );
		}
	}
endif; // class_exists check.

/**
 * Functions for displaying specific meta boxes
 */
function wcpt_wordcamp_metabox( $post, $metabox ) {
	$meta_keys = $GLOBALS['wordcamp_admin']->meta_keys( 'wordcamp' );
	wcpt_metabox( $meta_keys, $metabox['id'] );
}

/**
 * Displays organizer metabox
 */
function wcpt_organizer_metabox( $post, $metabox ) {
	$meta_keys = $GLOBALS['wordcamp_admin']->meta_keys( 'organizer' );
	wcpt_metabox( $meta_keys, $metabox['id'] );
}

/**
 * Displays venue metabox
 */
function wcpt_venue_metabox( $post, $metabox ) {
	$meta_keys = $GLOBALS['wordcamp_admin']->meta_keys( 'venue' );
	wcpt_metabox( $meta_keys, $metabox['id'] );
}

/**
 * Displays contributor metabox
 */
function wcpt_contributor_metabox( $post, $metabox ) {
	$meta_keys = $GLOBALS['wordcamp_admin']->meta_keys( 'contributor' );
	wcpt_metabox( $meta_keys, $metabox['id'] );
}

/**
 * The metabox that holds all of the additional information
 *
 * @package WordCamp Post Type
 * @subpackage Template Tags
 * @since WordCamp Post Type (0.1)
 */
function wcpt_metabox( $meta_keys, $metabox ) {
	global $post_id;

	$required_fields = WordCamp_Admin::get_required_fields( 'any', $post_id );

	// @todo When you refactor meta_keys() to support changing labels -- see note in meta_keys() -- also make it support these notes.
	$messages = array(
		'Telephone'                       => 'Required for shipping. Please use the +12.3456... format.',
		'Mailing Address'                 => 'Shipping address.',
		'Location'                        => "Please use the format '{City}, {Country}' or {City}, {StateCode}, {Country} for USA.",
		'Twitter'                         => 'Should begin with @. Ex. @wordpress',
		'WordCamp Hashtag'                => 'Should begin with #. Ex. #wcus',
		'Actual Attendees'                => 'Number of attendees who actually attended the event.',
		'Global Sponsorship Grant Amount' => 'No commas, thousands separators or currency symbols. Ex. 1234.56',
		'Global Sponsorship Grant'        => 'Deprecated.',
		'Hide from Event Feeds'           => 'Do not show in the public schedule and dashboard feeds, the site is still publicly accessible.',
		'Series Event'                    => '(Campus Connect only) Event is part of a multi-venue or multi-session series (e.g., workshops held across several campuses)',
		'Contact Information'             => 'Please provide a contact email address for the venue.',
	);

	if ( 'wcpt_venue_info' === $metabox ) {
		$address_instructions = 'Please include the city, state/province and country.';

		if ( WordCamp_Admin::have_geocoded_location( $post_id ) ) {
			$key_prefix = WordCamp_Admin::get_address_key_prefix( $post_id );
			$city       = get_post_meta( $post_id, $key_prefix . 'city',         true );
			$state      = get_post_meta( $post_id, $key_prefix . 'state',        true );
			$country    = get_post_meta( $post_id, $key_prefix . 'country_name', true );

			$address_instructions = sprintf(
				'%s Geocoded as: %s%s%s.',
				$address_instructions,
				esc_html( $city    ? $city  . ', ' : '' ),
				esc_html( $state   ? $state . ', ' : '' ),
				esc_html( $country ? $country      : '' )
			);

		} else {
			$address_instructions = "Error: could not geocode. $address_instructions";
		}

		$messages['Physical Address'] = $address_instructions;
		$messages['Host region']      = $address_instructions;
	}

	Event_Admin::display_meta_boxes( $required_fields, $meta_keys, $messages, $post_id, WordCamp_Admin::get_protected_fields() );
}

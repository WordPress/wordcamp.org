<?php

use Dotorg\Slack\Send;
use function WordCamp\Sunrise\get_top_level_domain;


/*
 * Miscellaneous snippets that don't warrant their own file
 */

/*
 * Prevents 'index.php' from being prepended to permalink options.
 *
 * is_nginx() returns false on WordCamp.org because $_SERVER['SERVER_SOFTWARE'] is empty.
 */
add_filter( 'got_url_rewrite', '__return_true' );

/**
 * Create a context for `wp_raise_memory_limit()` that allocates a large amount of memory.
 *
 * Suitable for cron jobs, reports, and other operations that legitimately need more than normal.
 */
function wcorg_high_memory_context() : string {
	return '512M';
}
add_filter( 'wordcamp_high_memory_limit', 'wcorg_high_memory_context' );

/*
 * Register an extra directory for private themes.
 *
 * These are all old and no longer used on new sites, but must remain active for old sites. They haven't been
 * open-sourced yet because they need to be audited and cleaned up first, which is a low priority.
 *
 * Having these in a separate folder lets us make wp-content/themes a single `svn:external` with the current
 * public themes.
 */
if ( is_dir( WP_CONTENT_DIR . '/themes-private' ) ) {
	register_theme_directory( WP_CONTENT_DIR . '/themes-private' );
}

/**
 * Include pages in the list of posts types that can have comments closed automatically
 */
function wcorg_close_comments_for_post_types( $post_types ) {
	$post_types[] = 'page';
	return $post_types;
}
add_filter( 'close_comments_for_post_types', 'wcorg_close_comments_for_post_types' );

/**
 * Force the `blog_public` option to be a specific value based on the site
 *
 * This ensures that normal camp sites are always indexed by search engines, and also
 * that they receive SSL certificates, because our Let's Encrypt script only installs
 * certificates for public sites.
 *
 * @return string
 */
function wcorg_enforce_public_blog_option() {
	if ( is_wordcamp_test_site() ) {
		$value = '0';
	} else {
		$value = '1';
	}

	return $value;
}
add_filter( 'pre_option_blog_public', 'wcorg_enforce_public_blog_option' );
add_filter( 'pre_update_option_blog_public', 'wcorg_enforce_public_blog_option' );

/**
 * We want to let organizers use shortcodes inside Text widgets.
 */
add_filter( 'widget_text', 'do_shortcode' );
// todo can remove this after ugprade to 4.9

/**
 * Output a menu via a shortcode
 *
 * @param array $attributes
 *
 * @return string
 */
function wcorg_shortcode_menu( $attributes ) {
	$attributes = shortcode_atts(
		array(
			'menu'       => '',
			'menu_class' => 'menu',
			'depth'      => 1,
		),
		$attributes
	);

	$attributes['depth'] = absint( $attributes['depth'] );
	$attributes['echo']  = false;

	return wp_nav_menu( $attributes );
}
add_shortcode( 'menu', 'wcorg_shortcode_menu' );

/**
 * Remove menu items on certain sites.
 *
 * This works together with `wcorg_disable_network_activated_plugins_on_sites()`. There are some plugins that we
 * need running on sites so we can use some of their internals, but don't use the main features, and don't want
 * them cluttering the UI.
 */
function wcorg_remove_admin_menu_pages_on_sites() {
	if ( get_current_blog_id() === BLOG_ID_CURRENT_SITE ) {
		remove_menu_page( 'edit.php?post_type=tix_ticket' );
	}
}
add_action( 'admin_menu', 'wcorg_remove_admin_menu_pages_on_sites', 11 );

/**
 * Show Tagregator's log to network admins
 */
function wcorg_show_tagregator_log() {
	if ( current_user_can( 'manage_network' ) ) {
		add_filter( 'tggr_show_log', '__return_true' );
	}
}
add_action( 'init', 'wcorg_show_tagregator_log' );

/**
 * Tell Tagregator to stop importing items for a camp 2 weeks after it has occurred.
 *
 * @param DateTime|null $end_date The date that Tagregator should stop importing items for a camp. Default null.
 *
 * @return DateTime|null
 */
function wcorg_set_per_camp_tagregator_end_date( $end_date ) {
	$details = get_wordcamp_post();

	// Despite its key/label, the start date value is actually stored as a Unix timestamp.
	$camp_start_timestamp = $details->meta['Start Date (YYYY-mm-dd)'][0] ?? 0;

	if ( $camp_start_timestamp ) {
		$offset   = '2 weeks';
		$end_date = date_create( date( 'Y-m-d', $camp_start_timestamp ) . '  ' . $offset );
	}

	return $end_date;
}

add_filter( 'tggr_end_date', 'wcorg_set_per_camp_tagregator_end_date' );

/**
 * Modify the space allocation on a per-size basis.
 *
 * @param int $size
 *
 * @return int
 */
function wcorg_modify_default_space_allotment( $size ) {
	switch ( get_current_blog_id() ) {
		case '364': // 2014.sf
			$size = 750;
			break;
	}

	return $size;
}
add_filter( 'get_space_allowed', 'wcorg_modify_default_space_allotment' );

/**
 * Add the post's slug to the body tag
 *
 * For CSS developers, this is better than relying on the post ID, because that often changes between their local
 * development environment and production, and manually importing/exporting is inconvenient.
 *
 * @param array $body_classes
 *
 * @return array
 */
function wcorg_content_slugs_to_body_tag( $body_classes ) {
	global $wp_query;
	$post = $wp_query->get_queried_object();

	if ( is_a( $post, 'WP_Post' ) ) {
		$body_classes[] = $post->post_type . '-slug-' . sanitize_html_class( $post->post_name, $post->ID );
	}

	return $body_classes;
}
add_filter( 'body_class', 'wcorg_content_slugs_to_body_tag' );

/**
 * Load the `wordcamporg` text domain.
 *
 * `wordcamporg` is used by all the custom plugins and themes, so that translators only have to deal with a single
 * GlotPress project, and we only have to install/update a single mofile per locale.
 *
 * @todo We can probably revert this back to an older/simpler version that doesn't hook into `change_locale` once
 * https://core.trac.wordpress.org/ticket/39210 is resolved.
 *
 * @param string|null Null when called on `plugins_loaded`; the new locale when called from `change_locale`.
 */
function wcorg_load_wordcamp_textdomain( $new_locale = null ) {
	// Use the requested locale when switching/restoring, and the user's locale when initially loading the site.
	if ( empty( $new_locale ) ) {
		$new_locale = get_user_locale();
	}

	// Avoid merging strings partially-localized locales.
	unload_textdomain( 'wordcamporg' );

	load_textdomain(
		'wordcamporg',
		sprintf(
			'%s/languages/wordcamporg/wordcamporg-%s.mo',
			WP_CONTENT_DIR,
			$new_locale
		)
	);
}
add_action( 'plugins_loaded', 'wcorg_load_wordcamp_textdomain' );
add_action( 'change_locale',  'wcorg_load_wordcamp_textdomain' );

/**
 * Update the site's locale when switching between blogs.
 *
 * @todo This can be removed if https://core.trac.wordpress.org/ticket/49263 is resolved. Add `'locale' => true` to
 *       `switch_to_blog()` calls for any custom cron jobs that send mail, like in `wordcamp-payments-network`.
 */
function wcorg_switch_to_blog_locale( $new_blog_id, $prev_blog_id, $context ) {
	/*
	 * Return early when creating a new site, to avoid an infinite loop.
	 *
	 * The database tables don't exist yet, so the `get_option()` call below would trigger a `wp_die()` from WPDB,
	 * which would call `get_language_attributes()`, which also calls `get_option()`, then repeat.
	 *
	 * @todo This can be removed when https://core.trac.wordpress.org/ticket/50228 is fixed.
	 */
	if ( did_action( 'wp_initialize_site' ) ) {
		return;
	}

	switch ( $context ) {
		case 'switch':
			/*
			 * Bypass `get_locale()` because it caches the original site's locale. This doesn't handle user
			 * locales, but is good enough until #49263-core is resolved.
			 */
			$site_locale = get_option( 'WPLANG', 'en_US' );

			// Sometimes sites have an empty string, `false`, etc saved in the db.
			if ( empty( $site_locale ) ) {
				$site_locale = 'en_US';
			}

			switch_to_locale( $site_locale );
			break;

		case 'restore':
			if ( is_locale_switched() ) {
				restore_previous_locale();
			}
			break;
	}
}

// $GLOBALS['wp_locale_switcher'] isn't initialized before this.
add_action( 'after_setup_theme', function() {
	add_action( 'switch_blog', 'wcorg_switch_to_blog_locale', 10, 3 );
} );

/**
 * Prevent `switch_to_locale` from unloading all plugin and theme translations.
 *
 * See https://core.trac.wordpress.org/ticket/39210
 *
 * The combination of `wcorg_switch_to_blog_locale` and something like `get_wordcamp_post`
 * (which gets called on init in `\WordCamp\Jetpack_Tweaks\disable_jetpack_spam_delete`) means that
 * all of the plugin and theme translations get unloaded on almost every request. This is a workaround
 * suggested here: https://core.trac.wordpress.org/ticket/39210#comment:17
 *
 * Hopefully this won't be necessary after core-39210 is fixed.
 */
add_filter( 'change_locale', function() {
	$GLOBALS['l10n_unloaded'] = array();
}, 99 );

// WordCamp.org QBO Integration.
add_filter( 'wordcamp_qbo_options', function( $options ) {
	// Secrets.
	$options['hmac_key'] = WORDCAMP_QBO_HMAC_KEY;

	// WordCamp Payments to QBO categories mapping.
	$options['categories_map'] = array(
		'after-party'     => array( 'value' => 72, 'name' => 'After Party'                 ),
		'audio-visual'    => array( 'value' => 79, 'name' => 'Audio-Visual'                ),
		'food-beverages'  => array( 'value' => 64, 'name' => 'Food & Beverage-WordCamps'   ),
		'office-supplies' => array( 'value' => 70, 'name' => 'Office Expense'              ),
		'signage-badges'  => array( 'value' => 73, 'name' => 'Printing/Signage/Badges'     ),
		'speaker-event'   => array( 'value' => 76, 'name' => 'Speaker Events'              ),
		'swag'            => array( 'value' => 74, 'name' => 'Swag'                        ),
		'venue'           => array( 'value' => 78, 'name' => 'Venue Rental'                ),
		'other'           => array( 'value' => 71, 'name' => 'Other Miscellaneous Expense' ),
	);

	return $options;
} );

add_filter( 'wordcamp_qbo_client_options', function( $options ) {
	if ( ! defined( 'WORDCAMP_QBO_HMAC_KEY' ) ) {
		return $options;
	}

	$options['hmac_key'] = WORDCAMP_QBO_HMAC_KEY;
	$options['api_base'] = sprintf(
		'https://central.wordcamp.%s/wp-json/wordcamp-qbo/v1',
		( 'local' === get_wordcamp_environment() ) ? 'test' : 'org'
	);

	return $options;
});

// Sponsorship payments (Stripe) credentials.
add_filter( 'wcorg_sponsor_payment_stripe', function( $options ) {
	$environment = ( defined('WORDCAMP_ENVIRONMENT') ) ? WORDCAMP_ENVIRONMENT : 'development';

	switch ( $environment ) {
		case 'production':
			$options['publishable'] = WORDCAMP_PAYMENT_STRIPE_PUBLISHABLE_LIVE;
			$options['secret']      = WORDCAMP_PAYMENT_STRIPE_SECRET_LIVE;
			break;

		case 'development':
		default:
			$options['publishable'] = ( defined( 'WORDCAMP_PAYMENT_STRIPE_PUBLISHABLE' ) ) ? WORDCAMP_PAYMENT_STRIPE_PUBLISHABLE : '';
			$options['secret']      = ( defined( 'WORDCAMP_PAYMENT_STRIPE_SECRET' ) ) ? WORDCAMP_PAYMENT_STRIPE_SECRET : '';
			break;
	}

	$options['hmac_key'] = ( defined( 'WORDCAMP_PAYMENT_STRIPE_HMAC' ) ) ? WORDCAMP_PAYMENT_STRIPE_HMAC : '';

	return $options;
} );

// Google Maps API.
add_filter( 'wordcamp_google_maps_api_key', function( $key, $scope = 'client' ) {
	$environment = ( defined('WORDCAMP_ENVIRONMENT') ) ? WORDCAMP_ENVIRONMENT : 'development';

	switch ( $environment ) {
		case 'production':
			if ( 'client' === $scope && defined( 'WORDCAMP_PROD_GOOGLE_MAPS_API_KEY' ) ) {
				$key = WORDCAMP_PROD_GOOGLE_MAPS_API_KEY;
			} elseif ( 'server' === $scope && defined( 'WORDCAMP_PROD_GOOGLE_MAPS_SERVER_API_KEY') ) {
				$key = WORDCAMP_PROD_GOOGLE_MAPS_SERVER_API_KEY;
			}
			break;

		case 'development':
		default:
			if ( defined( 'WORDCAMP_DEV_GOOGLE_MAPS_API_KEY' ) ) {
				$key = WORDCAMP_DEV_GOOGLE_MAPS_API_KEY;
			}
			break;
	}

	return $key;
}, 10, 2 );

/**
 * Disable admin pointers
 */
function wcorg_disable_admin_pointers() {
	remove_action( 'admin_enqueue_scripts', array( 'WP_Internal_Pointers', 'enqueue_scripts' ) );
}
add_action( 'admin_init', 'wcorg_disable_admin_pointers' );

// Prevent password resets, since they need to be done on w.org.
add_filter( 'allow_password_reset', '__return_false' );
add_filter( 'show_password_fields', '__return_false' );

/**
 * Redirect users to WordPress.org to reset their passwords.
 *
 * Otherwise, there's nothing to indicate where they can reset it.
 */
function wcorg_reset_passwords_at_wporg() {
	wp_redirect( 'https://login.wordpress.org/lostpassword/' );
	die();
}
add_action( 'login_form_lostpassword', 'wcorg_reset_passwords_at_wporg' );

/**
 * Register scripts and styles.
 */
function wcorg_register_scripts() {
	/*
	 * Select2 can be removed if/when it's bundled with Core, see #31696-core.
	 * If the handle changes, we'll need to update any of our plugins that are using it.
	 */
	wp_register_script(
		'select2',
		plugins_url( '/includes/select2/js/selectWoo.min.js', __FILE__ ),
		array( 'jquery' ),
		'1.0.6',
		true
	);

	wp_register_style(
		'select2',
		plugins_url( '/includes/select2/css/selectWoo.min.css', __FILE__ ),
		array(),
		'1.0.6'
	);
}
add_action( 'wp_enqueue_scripts',    'wcorg_register_scripts' );
add_action( 'admin_enqueue_scripts', 'wcorg_register_scripts' );

/**
 * Conditionally omit incident report submission feedback posts from post query results.
 *
 * @param WP_Query $wp_query
 *
 * @return WP_Query
 */
function wcorg_central_omit_incident_reports( $wp_query ) {
	if ( ! $wp_query instanceof WP_Query ) {
		return $wp_query;
	}

	$post_types = $wp_query->get( 'post_type' );

	if ( BLOG_ID_CURRENT_SITE === get_current_blog_id()
		&& in_array( 'feedback', (array) $post_types, true )
		&& ! current_user_can( 'manage_network' ) // TODO add a subrole for this.
	) {
		$meta_query = $wp_query->get( 'meta_query', array() );

		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_feedback_email',
				'value'   => 'report@wordcamp.org',
				'compare' => 'NOT LIKE',
			),
			// This catches non-feedback posts, but may cause a performance issue.
			// See https://developer.wordpress.org/reference/classes/wp_query/#comment-2315.
			array(
				'key'   => '_feedback_email',
				'value' => 'NOT EXISTS',
			),
		);

		$wp_query->set( 'meta_query', $meta_query );
	}

	return $wp_query;
}
add_filter( 'pre_get_posts', 'wcorg_central_omit_incident_reports' );

/**
 * Modify the capabilities necessary for exporting content from WordCamp Central.
 *
 * This effectively makes it so that only super admins and trusted deputies can export.
 *
 * The intention is to prevent the export of incident report submission feedback posts, which don't seem to be filtered
 * out by `wcorg_central_omit_incident_reports` when exporting all content.
 *
 * @param array  $primitive_caps The original list of primitive caps mapped to the given meta cap.
 * @param string $meta_cap       The meta cap in question.
 *
 * @return array
 */
function wcorg_central_modify_export_caps( $primitive_caps, $meta_cap ) {
	if ( BLOG_ID_CURRENT_SITE === get_current_blog_id() && 'export' === $meta_cap ) {
		return array_merge( (array) $primitive_caps, array( 'manage_network' ) ); // TODO add a subrole for this.
	}

	return $primitive_caps;
}
add_filter( 'map_meta_cap', 'wcorg_central_modify_export_caps', 10, 2 );

/**
 * Allow individual site administrators to activate and deactivate optional plugins.
 *
 * @param array  $required_capabilities The primitive capabilities that are required to perform the requested meta
 *                                      capability.
 * @param string $requested_capability  The requested meta capability.
 * @param int    $user_id               The user ID.
 * @param array  $args                  Optional data for the given capability. In this case, the plugin slug to
 *                                      activate/deactivate.
 *
 * @return array The primitive capabilities that are required to perform the requested meta capability.
 */
function wcorg_let_admins_activate_some_plugins( $required_capabilities, $requested_capability, $user_id, $args ) {
	$target_plugin    = $args[0] ?? null;
	$optional_plugins = array(
		'campt-indian-payment-gateway/campt-indian-payment-gateway.php',
		'camptix-invoices/camptix-invoices.php',
		'camptix-mailchimp/camptix-mailchimp.php',
		'camptix-mercadopago/camptix-mercadopago.php',
		'camptix-pagseguro/camptix-pagseguro.php',
		'camptix-payfast-gateway/camptix-payfast.php',
		'camptix-trustcard/camptix-trustcard.php',
		'camptix-trustpay/camptix-trustpay.php',
		'edit-flow/edit_flow.php',
		'lang-attribute/lang-attribute.php',
		'liveblog/liveblog.php',
		'public-post-preview/public-post-preview.php',
		'pwa/pwa.php',
	);

	switch ( $requested_capability ) {
		// Let regular admins visit the Plugins screen.
		case 'activate_plugins':
		case 'deactivate_plugins':
			$required_capabilities = array( 'manage_options' );
			break;

		// Let regular admins toggle specific plugins on/off.
		case 'activate_plugin':
		case 'deactivate_plugin':
			if ( in_array( $target_plugin, $optional_plugins, true ) ) {
				$required_capabilities = array( 'manage_options' );
			}
			break;
	}

	return $required_capabilities;
}
add_filter( 'map_meta_cap', 'wcorg_let_admins_activate_some_plugins', 10, 4 );

/**
 * Display a network admin notice if there are plugins or themes with updates available.
 */
function wcorg_network_updates_notifier() {
	if ( ! is_super_admin() ) {
		return;
	}

	// todo Maybe check core updates here as well?
	$update_plugins = get_site_transient( 'update_plugins' );
	$update_themes  = get_site_transient( 'update_themes' );

	if ( ! empty( $update_plugins->response ) || ! empty( $update_themes->response ) ) {
		?>

		<div class="notice notice-error">
			<?php if ( ! empty( $update_plugins->response ) ) : ?>
				<p>The following plugins have updates available:</p>

				<ul class="ul-disc">
					<?php foreach ( $update_plugins->response as $plugin ) : ?>
						<li>
							<?php printf(
								'%s %s',
								esc_html( $plugin->slug ),
								esc_html( $plugin->new_version )
							); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $update_themes->response ) ) : ?>
				<p>The following themes have updates available:</p>

				<ul class="ul-disc">
					<?php foreach ( $update_themes->response as $theme ) : ?>
						<li>
							<?php printf(
								'%s %s',
								esc_html( $theme['theme'] ),
								esc_html( $theme['new_version'] )
							); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php
	}
}
add_action( 'network_admin_notices', 'wcorg_network_updates_notifier' );

/**
 * Add a 'WordCamp Post' link to the admin bar menu on camp sites.
 *
 * This provides an easy way to pull up the WCPT post that corresponds to the camp site you're currently on.
 */
function add_wcpt_cross_link( WP_Admin_Bar $wp_admin_bar ) {
	if ( ! current_user_can( 'wordcamp_wrangle_wordcamps' ) ) {
		return;
	}

	$wordcamp = get_wordcamp_post();

	if ( ! $wordcamp ) {
		return;
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'site-name',
			'id'     => 'wordcamp-post',
			'title'  => __( 'WordCamp Post', 'wordcamporg' ),

			'href' => sprintf(
				'https://central.wordcamp.%s/wp-admin/post.php?post=%s&action=edit',
				get_top_level_domain(),
				$wordcamp->ID
			),
		)
	);
}
// The Priority positions the link after the Dashboard link on the front end.
add_action( 'admin_bar_menu', 'add_wcpt_cross_link', 35 );

/**
 * Log requests to the WordPress.org Events API and their responses, to aid debugging.
 *
 * @param array|WP_Error $response     HTTP response or WP_Error object.
 * @param string         $context      Context under which the hook is fired.
 * @param string         $transport    HTTP transport used.
 * @param array          $request_args HTTP request arguments.
 * @param string         $request_url  The request URL.
 */
function debug_community_events_response( $response, $context, $transport, $request_args, $request_url ) {
	if ( false === strpos( $request_url, 'api.wordpress.org/events' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/slack/send.php';

	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

	// Avoid bloating the log with all the event data, but the titles are enough to know what was returned.
	$response_body['events'] = array_column( $response_body['events'], 'title' );

	$message = sprintf(
		'%s %s',
		$response_code,
		is_wp_error( $response ) ? $response->get_error_message() : 'Valid response received'
	);

	$attachment = array(
		'author_name' => __FUNCTION__,
		'color'       => '#00A0D2',

		'fields' => array(
			array(
				'title' => 'User',
				'value' => _wp_get_current_user()->get( 'user_login' ),
				'short' => false,
			),

			array(
				'title' => 'Result',
				'value' => $message,
				'short' => false,
			),

			array(
				'title' => 'Request URL',
				'value' => add_query_arg( $request_args['body'], $request_url ),
				'short' => false,
			),

			array(
				'title' => 'Response Body',
				'value' => print_r( $response_body, true ),
				'short' => false,
			),
		),
	);

	$slack = new Send( SLACK_ERROR_REPORT_URL );
	$slack->add_attachment( $attachment );
	$slack->send( WORDCAMP_LOGS_SLACK_CHANNEL );
}
// Comment this out when not needed, but leave the code for future use.
// add_action( 'http_api_debug', 'debug_community_events_response', 10, 5 );

/**
 * Modify CLDR country data temporarily while awaiting an update to the data in the WP CLDR plugin.
 *
 * @param array $countries
 *
 * @return array
 */
function wcorg_country_list_mods( $countries ) {
	if ( isset( $countries['MK'] ) ) {
		$countries['MK']['name'] = 'North Macedonia';
	}

	return $countries;
}
add_filter( 'wcorg_get_countries', 'wcorg_country_list_mods' );

/**
 * Fix malformed URLs for the `mu-plugins-private` folder.
 *
 * If `plugins_url()` is called for a file in the `mu-plugins-private` directory, then the URL will contain the
 * absolute path to it, rather than just the URL path. That's because `plugin_basename()` only checks for the regular
 * `mu-plugins` directory, and doesn't know about `mu-plugins-private`.
 */
function fix_mu_plugins_private_urls( string $url ) : string {
	$search  = 'mu-plugins' . WPMU_PLUGIN_DIR . '-private';
	$replace = 'mu-plugins-private';

	return str_replace( $search, $replace, $url );
}
add_filter( 'plugins_url', 'fix_mu_plugins_private_urls' );

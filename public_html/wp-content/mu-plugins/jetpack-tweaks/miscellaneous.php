<?php

namespace WordCamp\Jetpack_Tweaks;
use WP_Service_Worker_Caching_Routes, WP_Service_Worker_Scripts;
use Grunion_Contact_Form;

defined( 'WPINC' ) || die();

// Allow Photon to fetch images that are served via HTTPS.
add_filter( 'jetpack_photon_reject_https',    '__return_false' );

/**
 * Filter the post types Jetpack has access to, and can synchronize with WordPress.com.
 *
 * @see Jetpack's WPCOM_JSON_API_ENDPOINT::_get_whitelisted_post_types();
 *
 * @param array $allowed_types Array of whitelisted post types.
 *
 * @return array Modified array of whitelisted post types.
 */
function add_post_types_to_rest_api( $allowed_types ) {
	$allowed_types += array( 'wcb_speaker', 'wcb_session', 'wcb_sponsor' );

	return $allowed_types;
}

add_filter( 'rest_api_allowed_post_types', __NAMESPACE__ . '\add_post_types_to_rest_api' );

/**
 * Prepend a unique string to contact form subjects.
 *
 * Otherwise some e-mail clients and management systems -- *cough* SupportPress *cough* -- will incorrectly group
 * separate messages into the same thread.
 *
 * It'd be better to have the key appended rather than prepended, but SupportPress won't always recognize the
 * subject as unique if we do that :|
 *
 * @param string $subject
 *
 * @return string
 */
function grunion_unique_subject( $subject ) {
	return sprintf( '[%s] %s', wp_generate_password( 8, false ), $subject );
}
add_filter( 'contact_form_subject', __NAMESPACE__ . '\grunion_unique_subject' );

/**
 * Lower the timeout for requests to the Brute Protect API to avoid unintentional DDoS.
 *
 * The default timeout is 30 seconds, but when the API goes down, the long timeouts will occupy php-fpm threads,
 * which will stack up until there are no more available, and the site will crash.
 *
 * @link https://wordpress.slack.com/archives/G02QCEMRY/p1553203877064600
 *
 * @param int $timeout
 *
 * @return int
 */
function lower_brute_protect_api_timeout( $timeout ) {
	return 8; // seconds.
}
add_filter( 'jetpack_protect_connect_timeout', __NAMESPACE__ . '\lower_brute_protect_api_timeout' );

/**
 * Register caching routes for Jetpack with the frontend service worker.
 *
 * Jetpack uses wp.com domains for loading assets, which need to be cached regexes that match from the start of
 * the URL. This prevents unintentional caching of 3rd-party scripts by broad regexes.
 *
 * @param WP_Service_Worker_Scripts $scripts
 */
function register_caching_routes( WP_Service_Worker_Scripts $scripts ) {
	/*
	 * Set up jetpack cache strategy to pull from the cache first, with no network request if the resource is
	 * found, and save up to 50 cached entries for 1 day.
	 */
	$asset_cache_strategy_args = array(
		'strategy'  => WP_Service_Worker_Caching_Routes::STRATEGY_CACHE_FIRST,
		'cacheName' => 'wc-jetpack',
		'plugins'   => array(
			'expiration' => array(
				'maxEntries'    => 50,
				'maxAgeSeconds' => DAY_IN_SECONDS,
			),
		),
	);

	/*
	 * Cache Jetpack core assets.
	 * It's possible that some Jetpack assets are loaded from wp.com servers. Anything off a `s0.`, `s1.`, or
	 * `s2.wp.com` domain should be locally cached.
	 */
	$scripts->caching_routes()->register(
		'https?://s[0-2]{1}.wp.com/.*\.(png|gif|jpg|jpeg)(\?.*)?$',
		$asset_cache_strategy_args
	);

	/*
	 * Cache assets from "Site Accelerator".
	 * Jetpack can use the wp.com CDN for CSS and JS. This uses the `c0.wp.com` domain.
	 */
	$scripts->caching_routes()->register(
		'https?://c0.wp.com/.*\.(css|js)(\?.*)?$',
		$asset_cache_strategy_args
	);

	/*
	 * Cache files from Photon.
	 * Images loaded by Photon use wp.com servers, and are loaded from `i0.`, `i1.`, or `i2.wp.com`.
	 */
	$scripts->caching_routes()->register(
		'https?://i[0-2]{1}.wp.com/.*/files/.*\.(png|gif|jpg|jpeg)(\?.*)?$',
		$asset_cache_strategy_args
	);
}
add_action( 'wp_front_service_worker', __NAMESPACE__ . '\register_caching_routes' );

/**
 * Disable Jetpack's email notifications for following a WordCamp if not already set.
 *
 * Jetpack defaults to send an email about each subscriber to each WordCamp to the owner
 * of the Jetpack connection.  No need to receive these emails.
 */
function disable_jetpack_blog_follow_emails() {
	$social_notifications_subscribe = get_option( 'social_notifications_subscribe' );
	if ( false === $social_notifications_subscribe ) {
		update_option( 'social_notifications_subscribe', 'off' );
	}
}
add_filter( 'admin_init', __NAMESPACE__ . '\disable_jetpack_blog_follow_emails' );

/**
 * Disable Jetpack's automatic spam deletion if the WordCamp is in future or has ended less than month ago.
 *
 * Jetpack deletes normally spam submissions after 15 days. Sometimes there are false positivies and
 * organisers do miss important messages because those get deleted before team manually checks spam folder.
 * Keep the spam submissions until month has passed from the start of WordCamp, just in case of something
 * is needed from submissions after WordCamp has ended.
 */
function disable_jetpack_spam_delete() {
	$wordcamp = get_wordcamp_post();
	$wc_start = $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ?? '';

	/**
	 * Bail if WordCamp start date has not been set.
	 * Allow spam deletion in order to keep database clean.
	 */
	if ( empty( $wc_start ) ) {
		return;
	}

	/**
	 * Bail if month has passed after WordCamp started.
	 * Allow spam deletion.
	 */
	if ( absint( $wc_start ) < strtotime( '-30 days' ) ) {
		return;
	}

	/**
	 * Remove spam deletion actions.
	 * One for the actual submit and second for Akismet metadata attached to each submission.
	 */
	remove_action( 'grunion_scheduled_delete', 'grunion_delete_old_spam' );
	if ( class_exists( '\Grunion_Contact_Form_Plugin', false ) ) {
		remove_action( 'wp_scheduled_delete', array( \Grunion_Contact_Form_Plugin::init(), 'daily_akismet_meta_cleanup' ) );
	}
}
add_action( 'init', __NAMESPACE__ . '\disable_jetpack_spam_delete' );

/**
 * Filter the "is_frontend" check.
 *
 * The contact forms are not shown in cached pages because this function incorrectly thinks this isn't a
 * frontend request. Filtering the value here is a nuclear option, intended to be a short-term fix while this
 * is addressed in Jetpack itself.
 * See https://github.com/Automattic/jetpack/issues/22410.
 *
 * @param bool $is_frontend Whether the current request is for accessing the frontend.
 */
function workaround_is_frontend( $is_frontend ) {
	$is_frontend = true;

	// Leave this check to prevent RSS feeds from showing the form.
	if ( is_feed() ) {
		$is_frontend = false;
	}

	return $is_frontend;
}
add_filter( 'jetpack_is_frontend', __NAMESPACE__ . '\workaround_is_frontend' );

/**
 * Filter the contact form HTML to replace the wrapper with a fieldset & legend
 * for radio & multi-checkbox fields. This is necessary for screen reader users
 * to understand the form questions.
 *
 * The single checkbox field is okay as-is.
 *
 * Upstream issue: https://github.com/Automattic/jetpack/issues/16685.
 * When that is fixed, this & `inject_css_for_fieldset` can be removed.
 *
 * @param string   $rendered_field Contact Form HTML output.
 * @param string   $field_label    Field label.
 * @param int|null $post_id        Post ID.
 */
function wrap_checkbox_radio_fieldset( $rendered_field, $field_label, $post_id ) {
	// Get the current form style, if it's anything other than default, return early.
	$class_name = Grunion_Contact_Form::$current_form->get_attribute( 'className' );
	preg_match( '/is-style-([^\s]+)/i', $class_name, $matches );
	$style = count( $matches ) >= 2 ? $matches[1] : 'default';
	if ( 'default' !== $style ) {
		return $rendered_field;
	}

	if (
		str_contains( $rendered_field, 'grunion-checkbox-multiple-options' ) ||
		str_contains( $rendered_field, 'grunion-radio-options' )
	) {
		// remove any whitespace so the offsets below work.
		$rendered_field = trim( $rendered_field );
		// replace wrapper div.
		$rendered_field = substr_replace( $rendered_field, '<fieldset ', 0, 5 );
		$rendered_field = substr_replace( $rendered_field, '</fieldset> ', -6, 6 );
		// replace only the first label, others are for the options.
		$rendered_field = preg_replace( '/<label/', '<legend', $rendered_field, 1);
		$rendered_field = preg_replace( '/<\/label>/', '</legend>', $rendered_field, 1);
		// Pull out the legend text so we can create a separate visual element.
		// See https://adrianroselli.com/2022/07/use-legend-and-fieldset.html.
		if ( preg_match( '/<legend[^>]*>(.*)<\/legend>/i', $rendered_field, $matches ) ) {
			$visible_label = sprintf(
				'<div class="grunion-field-label" aria-hidden="true">%s</div>',
				$matches[1]
			);
			$rendered_field = str_replace( $matches[0], $matches[0] . $visible_label, $rendered_field );
		}
	}
	return $rendered_field;
}
add_filter( 'grunion_contact_form_field_html', __NAMESPACE__ . '\wrap_checkbox_radio_fieldset', 10, 3 );

/**
 * Add styles for the injected fieldset & legend.
 *
 * This resets the spacing around the fieldset, and styles the legend to match the label.
 *
 * See https://github.com/Automattic/jetpack/blob/trunk/projects/plugins/jetpack/modules/contact-form/css/grunion.css.
 */
function inject_css_for_fieldset() {
	$form_css = <<<CSS
:where(.contact-form) fieldset {
	border: none;
	padding: 0;
}
:where(.contact-form) legend {
	position: absolute;
	overflow: hidden;
	clip: rect(0 0 0 0); 
	clip-path: inset(50%);
	width: 1px;
	height: 1px;
	white-space: nowrap; 
}
.grunion-field-label {
	margin-bottom: 0.25em;
	float: none;
	font-weight: bold;
	display: block;
}
.grunion-field-label span {
	font-size: 85%;
	margin-left: 0.25em;
	font-weight: normal;
	opacity: 0.45;
}
CSS;

	wp_add_inline_style( 'grunion.css', $form_css );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\inject_css_for_fieldset' );

// Default forms in Jetpack got switched in 12.2, and the fix for accessibility (wrap_checkbox_radio_fieldset) breaks when upgrading: to 12.2+.
add_filter( 'jetpack_contact_form_use_package', '__return_false' );

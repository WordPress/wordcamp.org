<?php
/**
 * Fills the Playground preview of Sponsor Vetting with made-up camps, sponsors, companies and sponsorships.
 *
 * Everything goes through the plugin's own intake and workflow, so the records are ones it could have produced.
 * Dates are relative to when this runs, and histories are pushed into the past so the timelines read like real ones.
 *
 * @package WordCamp\Sponsor_Vetting\Preview
 */

declare( strict_types = 1 );

use function WordCamp\Sponsor_Vetting\acting_as_system;
use function WordCamp\Sponsor_Vetting\add_note;
use function WordCamp\Sponsor_Vetting\approve_sponsorship;
use function WordCamp\Sponsor_Vetting\link_sponsorship;
use function WordCamp\Sponsor_Vetting\request_vetting;
use function WordCamp\Sponsor_Vetting\send_compliance_request;

/*
 * Multisite finds its site by the request's address, which a script Playground runs doesn't have: without it,
 * WordPress stops with a database error. Playground defines `WP_HOME` before any script starts.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress isn't loaded yet.
$_SERVER['HTTP_HOST']   = parse_url( WP_HOME, PHP_URL_HOST );
$_SERVER['REQUEST_URI'] = parse_url( WP_HOME, PHP_URL_PATH ) . '/';
$_SERVER['HTTPS']       = 'https' === parse_url( WP_HOME, PHP_URL_SCHEME ) ? 'on' : 'off';
// phpcs:enable

// With an address, Playground takes the script for a visitor to log in and redirect, which ends it.
$_COOKIE['playground_auto_login_already_happened'] = '1';

require_once '/wordpress/wp-load.php';

if ( ! function_exists( 'WordCamp\Sponsor_Vetting\request_vetting' ) ) {
	throw new RuntimeException( 'Sponsor Vetting isn’t loaded.' );
}

if ( get_site_option( 'sv_preview_seeded' ) ) {
	return;
}

/**
 * Creates a camp site and its event on Central.
 *
 * @param string $slug The site's path.
 * @param string $city The city the camp is in.
 * @param int    $days How many days from now it starts; negative for one that's over.
 * @return int The camp site.
 * @throws RuntimeException When the plugin refuses.
 */
function sv_seed_camp( string $slug, string $city, int $days ): int {
	$network = get_network();
	$start   = strtotime( gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . ' UTC' );
	$name    = sprintf( 'WordCamp %s %s', $city, gmdate( 'Y', $start ) );
	$blog_id = wpmu_create_blog( $network->domain, $network->path . $slug . '/', $name, 1, array( 'public' => 1 ), $network->id );

	if ( is_wp_error( $blog_id ) ) {
		throw new RuntimeException( esc_html( $blog_id->get_error_message() ) );
	}

	wp_insert_post(
		array(
			'post_type'   => 'wordcamp',
			'post_status' => 'publish',
			'post_title'  => $name,
			'meta_input'  => array(
				'_site_id'                => $blog_id,
				'Start Date (YYYY-mm-dd)' => $start,
				'E-mail Address'          => $slug . '@wordcamp.example',
			),
		)
	);

	return $blog_id;
}

/**
 * Adds a sponsor to a camp and requests vetting for it, as its organizer.
 *
 * @param int    $blog_id The camp site.
 * @param string $company The company's name.
 * @param string $website Its website, or an empty string for none.
 * @param string $contact The contact's first and last name.
 * @return int The sponsorship.
 * @throws RuntimeException When the plugin refuses.
 */
function sv_seed_sponsor( int $blog_id, string $company, string $website, string $contact ): int {
	list( $first_name, $last_name ) = explode( ' ', $contact, 2 );

	$domain = (string) wp_parse_url( $website ? $website : 'https://mail.example', PHP_URL_HOST );

	wp_set_current_user( SV_SEED_ORGANIZER );
	switch_to_blog( $blog_id );

	$sponsor_id = wp_insert_post(
		array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => $company,
			'meta_input'  => array(
				'_wcpt_sponsor_company_name'  => $company,
				'_wcpt_sponsor_website'       => $website,
				'_wcpt_sponsor_first_name'    => $first_name,
				'_wcpt_sponsor_last_name'     => $last_name,
				'_wcpt_sponsor_email_address' => strtolower( $first_name ) . '@' . preg_replace( '/^www\./', '', $domain ),
			),
		)
	);

	restore_current_blog();

	$sponsorship_id = request_vetting( $blog_id, $sponsor_id );

	wp_set_current_user( SV_SEED_VETTER );

	if ( is_wp_error( $sponsorship_id ) ) {
		throw new RuntimeException( esc_html( $sponsorship_id->get_error_message() ) );
	}

	return $sponsorship_id;
}

/**
 * Stops the seeding when the plugin refuses a step, so a preview never opens half filled.
 *
 * @param mixed $result What the plugin answered.
 * @return mixed The same, when it isn't an error.
 * @throws RuntimeException When the plugin refuses.
 */
function sv_seed_check( mixed $result ): mixed {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( esc_html( $result->get_error_message() ) );
	}

	return $result;
}

/**
 * Gets the company a sponsorship is linked to.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return int
 */
function sv_seed_company( int $sponsorship_id ): int {
	return (int) get_post_field( 'post_parent', $sponsorship_id );
}

/**
 * Moves a company to a status, as the vetter.
 *
 * @param int    $company_id The company.
 * @param string $status     The status.
 * @param array  $meta       Optional. Meta to save with it.
 * @throws RuntimeException When the workflow keeps the company where it was.
 */
function sv_seed_status( int $company_id, string $status, array $meta = array() ): void {
	wp_update_post(
		array(
			'ID'          => $company_id,
			'post_status' => $status,
			'meta_input'  => $meta,
		)
	);

	if ( get_post_status( $company_id ) !== $status ) {
		throw new RuntimeException( esc_html( sprintf( 'Company %d did not move to %s.', $company_id, $status ) ) );
	}
}

/**
 * Pushes everything that has happened to records so far further into the past.
 *
 * @param int $days    How many days.
 * @param int ...$ids  The companies and sponsorships. A company takes its sponsorships with it.
 */
function sv_seed_age( int $days, int ...$ids ): void {
	$shift = $days * DAY_IN_SECONDS;

	foreach ( $ids as $id ) {
		$ids = array_merge(
			$ids,
			get_posts(
				array(
					'post_type'      => 'sv_sponsorship',
					// Not `any`, which leaves out statuses that are kept from search, as all of these are.
					'post_status'    => array( 'svs-pending', 'svs-approved', 'svs-not-approved', 'svs-canceled' ),
					'post_parent'    => $id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	foreach ( array_unique( $ids ) as $id ) {
		foreach ( array( '_sv_note', '_sv_status_change', '_sv_conversation', '_sv_good_faith' ) as $key ) {
			foreach ( get_post_meta( $id, $key, false ) as $entry ) {
				$aged          = $entry;
				$aged['time'] -= $shift;

				update_post_meta( $id, $key, $aged, $entry );
			}
		}

		foreach ( array( '_sv_last_vetted', '_sv_approved_at' ) as $key ) {
			$time = (int) get_post_meta( $id, $key, true );

			if ( $time ) {
				update_post_meta( $id, $key, $time - $shift );
			}
		}
	}
}

define( 'SV_SEED_VETTER', 1 );
define(
	'SV_SEED_ORGANIZER',
	wp_insert_user(
		array(
			'user_login'   => 'organizer',
			'user_pass'    => wp_generate_password(),
			'user_email'   => 'organizer@wordcamp.example',
			'display_name' => 'Olu (organizer)',
			'role'         => 'editor',
		)
	)
);

wp_update_user(
	array(
		'ID'           => SV_SEED_VETTER,
		'display_name' => 'Vera (vetter)',
	)
);
wp_set_current_user( SV_SEED_VETTER );
update_option( 'blogname', 'WordCamp Central (preview)' );

$vienna  = sv_seed_camp( 'vienna', 'Vienna', -40 );
$lisboa  = sv_seed_camp( 'lisboa', 'Lisboa', 18 );
$kansai  = sv_seed_camp( 'kansai', 'Kansai', 55 );
$nairobi = sv_seed_camp( 'nairobi', 'Nairobi', 120 );

// Compliant and recently vetted: later requests are approved on their own.
$northwind = sv_seed_company( sv_seed_sponsor( $vienna, 'Northwind Hosting', 'https://northwind-hosting.example', 'Nadia Okafor' ) );
sv_seed_age( 60, $northwind );
sv_seed_status( $northwind, 'sv-in-progress' );
add_note( $northwind, 'Checked the site footer, pricing page and their plugin: everything is GPL, and “WordPress” is only used descriptively.' );
sv_seed_age( 3, $northwind );
sv_seed_status( $northwind, 'sv-compliant' );
sv_seed_age( 38, $northwind );
sv_seed_sponsor( $lisboa, 'Northwind Hosting', 'https://www.northwind-hosting.example/', 'Nadia Okafor' );
sv_seed_age( 10, $northwind );
sv_seed_sponsor( $kansai, 'Northwind Hosting', 'northwind-hosting.example', 'Kenji Mori' );
sv_seed_age( 2, $northwind );

// Asked for changes, approved in good faith for the camp that can't wait, and the sponsor has replied.
$heron_lisboa = sv_seed_sponsor( $lisboa, 'Blue Heron Plugins', 'https://blueheron-plugins.example', 'Tomás Reis' );
$heron        = sv_seed_company( $heron_lisboa );
sv_seed_age( 6, $heron );
sv_seed_status(
	$heron,
	'sv-in-progress',
	array(
		'_sv_gpl_findings'       => "- The **Pro add-on** is sold under a proprietary license. It needs to be GPL-compatible, like the free plugin.\n- The license page says the code “may not be redistributed”.",
		'_sv_trademark_findings' => "- The product is named **WordPress Booking Pro**. Please rename it, for example to “Blue Heron Booking for WordPress”.\n- The site’s logo uses the WordPress logo as the letter “o”.",
	)
);
sv_seed_age( 1, $heron );
sv_seed_check( send_compliance_request( $heron, $heron_lisboa ) );
sv_seed_age( 8, $heron );
sv_seed_check( approve_sponsorship( $heron_lisboa, true ) );
add_note( $heron, 'Tomás committed to renaming the add-on and relicensing it before the camp. Lisboa needs the sponsor on its site this week.' );
sv_seed_age( 4, $heron );
sv_seed_check( link_sponsorship( sv_seed_sponsor( $kansai, 'Blue Heron Plugins', 'https://blueheron-plugins.example', 'Tomás Reis' ), $heron ) );
sv_seed_age( 1, $heron );
update_post_meta( $heron, '_sv_sponsor_replied', time() - 3 * HOUR_IN_SECONDS );
update_post_meta( $heron, '_sv_good_faith_due', gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) );

// Being looked at.
$tidewater = sv_seed_company( sv_seed_sponsor( $kansai, 'Tidewater Themes', 'https://tidewater-themes.example', 'Aiko Tanaka' ) );
sv_seed_age( 3, $tidewater );
sv_seed_status(
	$tidewater,
	'sv-in-progress',
	array( '_sv_gpl_findings' => '- Two of the premium themes ship with a split license: PHP is GPL, CSS and images are not.' )
);
add_note( $tidewater, 'Still need to check the theme club terms.' );
sv_seed_age( 1, $tidewater );

// New, and a second camp has asked for the same company: its request waits for a vetter to link it.
$lumen = sv_seed_company( sv_seed_sponsor( $nairobi, 'Lumen Analytics', 'https://lumen-analytics.example', 'Wanjiru Kamau' ) );
sv_seed_age( 2, $lumen );
sv_seed_sponsor( $kansai, 'Lumen Analytics Japan', 'https://www.lumen-analytics.example/jp/', 'Haruto Sato' );

// New, with the soonest event.
sv_seed_age( 1, sv_seed_company( sv_seed_sponsor( $lisboa, 'Brightwater SEO', 'https://brightwater-seo.example', 'Inês Duarte' ) ) );
sv_seed_sponsor( $kansai, 'Harborline Payments', 'https://harborline-payments.example', 'Ren Fujita' );

// Vetted long enough ago that it has lapsed, and a new request is waiting on it.
$old_mill = sv_seed_company( sv_seed_sponsor( $vienna, 'Old Mill Agency', 'https://oldmill.example', 'Lukas Gruber' ) );
sv_seed_status( $old_mill, 'sv-compliant' );
sv_seed_age( 150, $old_mill );
sv_seed_age( 5, sv_seed_sponsor( $nairobi, 'Old Mill Agency', 'https://oldmill.example', 'Amina Njoroge' ) );

// Not eligible for a while.
$copperleaf_vienna = sv_seed_sponsor( $vienna, 'Copperleaf Commerce', 'https://copperleaf-commerce.example', 'Mara Steiner' );
$copperleaf        = sv_seed_company( $copperleaf_vienna );
sv_seed_age( 12, $copperleaf );
sv_seed_status( $copperleaf, 'sv-in-progress' );
sv_seed_age( 4, $copperleaf );
sv_seed_status(
	$copperleaf,
	'sv-not-eligible',
	array(
		'_sv_gpl_findings'       => '- Sells a closed-source fork of a GPL plugin and declined to relicense it.',
		'_sv_not_eligible_until' => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
	)
);
wp_update_post(
	array(
		'ID'          => $copperleaf_vienna,
		'post_status' => 'svs-not-approved',
	)
);
add_note( $copperleaf, 'They can reapply once the fork is relicensed.' );
sv_seed_age( 62, $copperleaf );

// Never answered its compliance request, so the daily job set it aside once its camp was over.
$saltmarsh_vienna = sv_seed_sponsor( $vienna, 'Saltmarsh Security', 'https://saltmarsh-security.example', 'Felix Bauer' );
$saltmarsh        = sv_seed_company( $saltmarsh_vienna );
sv_seed_age( 5, $saltmarsh );
sv_seed_status(
	$saltmarsh,
	'sv-in-progress',
	array( '_sv_trademark_findings' => '- The domain **wordpress-shield.example** redirects to the product page.' )
);
sv_seed_check( send_compliance_request( $saltmarsh, $saltmarsh_vienna ) );
sv_seed_age( 31, $saltmarsh );
acting_as_system( true );
sv_seed_status( $saltmarsh, 'sv-inactive' );
add_note( $saltmarsh, 'Moved to Inactive automatically: the sponsor hasn’t replied to its compliance request in 30 days, and the events it was being vetted for are over.' );
acting_as_system( false );
sv_seed_age( 33, $saltmarsh );

// Compliant, with nothing else going on.
$quillfeather = sv_seed_company( sv_seed_sponsor( $nairobi, 'Quillfeather Forms', 'https://quillfeather.example', 'Zawadi Mwangi' ) );
sv_seed_age( 9, $quillfeather );
sv_seed_status( $quillfeather, 'sv-compliant' );
sv_seed_age( 7, $quillfeather );

// No website, so there's no company to suggest.
sv_seed_age( 2, sv_seed_sponsor( $nairobi, 'Paper Crane Studio', '', 'Njeri Otieno' ) );

update_site_option( 'sv_preview_seeded', 1 );

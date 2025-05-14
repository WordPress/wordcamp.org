<?php
namespace WordCamp\RobotsTxt;
use Jetpack_Options;

defined( 'WPINC' ) || die();

add_action( 'template_redirect', __NAMESPACE__ . '\template_redirect', 1 );

/**
 * Maybe serve the robots.txt file for this domain.
 */
function template_redirect() {
	if ( '/robots.txt' !== $_SERVER['REQUEST_URI'] ) {
		return;
	}

	$sites = get_sites( array(
		'network_id' => get_current_network_id(),
		'domain'     => get_blog_details()->domain,
		'public'     => 1,
		'deleted'    => 0,
		'archived'   => 0,
		'orderby'    => 'registered',
		'order'      => 'desc',
	) );

	$sitemaps            = '';
	$path_allow_disallow = '';
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );

		// Note: We must create a new instance for each site.
		$coming_soon_enabled = (
			class_exists( 'WCCSP_Settings' ) &&
			'on' === ( new \WCCSP_Settings() )->get_settings()['enabled']
		);
		if ( $coming_soon_enabled ) {
			// Skip this site, don't even mention it for now.
			continue;
		}

		$site_url = parse_url( site_url() );
		$path     = ( ! empty( $site_url['path'] ) ) ? $site_url['path'] : '';

		// A non-public site should not be indexed.
		if ( '1' != get_option( 'blog_public' ) ) {
			$path_allow_disallow .= "Disallow: $path/\n";
			continue;
		}

		// Remove any wp-admin links from search indexing.
		$path_allow_disallow .= "Disallow: $path/wp-admin/\n";
		$path_allow_disallow .= "Allow: $path/wp-admin/admin-ajax.php\n";

		// Are Jetpack Sitemaps enabled?
		$using_jetpack_sitemaps = (
			class_exists( 'Jetpack_Options' ) &&
			in_array( 'sitemaps', (array) Jetpack_Options::get_option( 'active_modules' ), true )
		);

		// Add a sitemap link.
		$sitemaps .= 'Sitemap: ' . esc_url( site_url( $using_jetpack_sitemaps ? 'sitemap.xml' : 'wp-sitemap.xml' ) ) . "\n";
	}

	header( 'Content-Type: text/plain; charset=utf-8' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo "{$sitemaps}\nUser-agent: *\n{$path_allow_disallow}";

	die();
}

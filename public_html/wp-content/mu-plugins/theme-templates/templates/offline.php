<?php
/**
 * Template Name: Offline Notice
 *
 * The service worker will show this template to visitors who are browsing offline.
 *
 * To test changes…
 * - Temporarily enable the `Bypass for network` setting in dev tools.
 * - View the template at this URL: https://your-site-name.com/?wp_error_template=offline
 *
 * Can also test by going offline in dev tools, but the template is precached so you'll need to update the service
 * worker and reload it before going offline.
 * See https://github.com/xwp/pwa-wp/issues/167#issuecomment-501004695
 */

namespace WordCamp\Theme_Templates;

get_header();
?>

	<main>
		<h1>
			<?php echo esc_html( _x( 'Offline', 'Page Title', 'wordcamporg' ) ); ?>
		</h1>

		<p>
			<?php esc_html_e( "This page couldn't be loaded because you appear to be offline. Please try again once you have a network connection.", 'wordcamporg' ); ?>
		</p>

		<p>
			<?php esc_html_e( 'In the mean time, hopefully this information is useful:', 'wordcamporg' ); ?>
		</p>

		<?php
			require_once dirname( __DIR__ ) . '/parts/dates.php';
			require_once dirname( __DIR__ ) . '/parts/location.php';
		?>

		<h3>
			<?php esc_html_e( 'Schedule' ); ?>
		</h3>

		<?php echo do_shortcode( '[schedule]' ); ?>
	</main>

<?php

get_footer();

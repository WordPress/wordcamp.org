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

$offline_page = get_offline_content();
$site_description = get_bloginfo( 'description' );
?><!DOCTYPE html>

<html class="no-js" <?php language_attributes(); ?>>

<head>

	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0" >

	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
	<style>
		.offline-container {
			margin: 5vh auto;
			padding: 2em;
			max-width: 46em;
		}
		.theme-twentyfourteen .offline-container {
			padding-top: 1px; /* Prevents margin collapse. */
			background: white;
		}
		.theme-twentyfifteen:before { /* Remove the sidebar background– there is no sidebar. */
			display: none;
		}
		.theme-twentyfifteen .offline-container,
		.theme-twentysixteen .offline-container {
			background: white;
		}
		.theme-twentytwenty section {
			padding-top: 0;
		}
	</style>

</head>

<body <?php body_class( array( 'theme-' . get_template(), 'page-offline' ) ); ?>>
	<div class="offline-container">
		<header>
			<h1>
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</h1>
			<?php if ( $site_description ) : ?>
				<p><?php echo esc_html( $site_description ); ?></p>
			<?php endif; ?>
		</header>
		<main>
			<section>
				<header>
					<h2>
						<?php echo wp_kses_post( $offline_page['title'] ); ?>
					</h2>
				</header>

				<div>
					<?php echo wp_kses_post( $offline_page['content'] ); ?>
				</div>
			</section>
		</main>
	</div>
	<?php wp_footer(); ?>
</body>
</html>

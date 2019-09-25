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

$offline_page = get_offline_content();
?>

	<main id="main" class="site-main">
		<section class="error-offline">
			<header class="page-header">
				<h1 class="page-title">
					<?php echo wp_kses_post( $offline_page['title'] ); ?>
				</h1>
			</header>

			<div class="page-content">
				<?php echo wp_kses_post( $offline_page['content'] ); ?>
			</div>
		</section>
	</main>

<?php

get_footer();

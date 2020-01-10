<?php
/**
 * Template Name: Offline Notice
 *
 * This is the Twenty Twenty-specific offline template, which will be used for offline views of a PWA-enabled site.
 * See `./offline.php` for more information.
 */

namespace WordCamp\Theme_Templates;

get_header();

$offline_page = get_offline_content();
?>

<main id="site-content" role="main">

	<article <?php post_class(); ?> id="post-<?php the_ID(); ?>">

		<header class="entry-header has-text-align-center">

			<div class="entry-header-inner section-inner medium">

				<h1 class="entry-title"><?php echo wp_kses_post( $offline_page['title'] ); ?></h1>

			</div><!-- .entry-header-inner -->

		</header><!-- .entry-header -->

		<div class="post-inner">

			<div class="entry-content">

				<?php echo wp_kses_post( $offline_page['content'] ); ?>

			</div><!-- .entry-content -->

		</div><!-- .post-inner -->

	</article><!-- .post -->

</main><!-- #site-content -->

<?php get_template_part( 'template-parts/footer-menus-widgets' ); ?>

<?php get_footer(); ?>

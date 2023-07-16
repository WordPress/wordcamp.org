<?php
/**
 * Template Name: WordCamp Home
 *
 * The home page template.
 */

get_header(); ?>

	<div id="wc-content-blocks" class="group">


		<div class="wc-upcoming">
			<h3><strong>Upcoming</strong> WordCamps</h3>

			<?php // Get the upcoming WordCamps
			if ( function_exists( 'wcpt_has_wordcamps' ) &&
				wcpt_has_wordcamps( array(
					'post_status'    => WordCamp_Loader::get_public_post_statuses(),
					'posts_per_page' => 5,
					'meta_key'       => 'Start Date (YYYY-mm-dd)',
					'orderby'        => 'meta_value',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'     => 'Start Date (YYYY-mm-dd)',
							'value'   => strtotime( '-2 days' ),
							'compare' => '>',
						),
						array(
							'key'     => 'End Date (YYYY-mm-dd)',
							'value'   => strtotime( 'today' ),
							'compare' => '>',
						),
					)
				) )
			) :
			?>

			<ul>
			<?php while ( wcpt_wordcamps() ) :
				wcpt_the_wordcamp(); ?>

				<li>
					<a href="<?php wcpt_wordcamp_permalink(); ?>"><strong><?php wcpt_wordcamp_title(); ?></strong>
						<?php WordCamp_Central_Theme::the_wordcamp_date(); ?>
					</a>
				</li>

			<?php endwhile; // wcpt_wordcamps ?>
			</ul>

			<a href="<?php echo esc_url( home_url( '/schedule/' ) ); ?>" class="more">
				More WordCamps &rarr;
			</a>

			<?php endif; // wcpt_has_wordcamps ?>

		</div><!-- .wc-upcoming -->

		<div class="wc-news">

			<h3><strong>News</strong></h3>

			<?php
				// Removes links and other post formats from the home page
				// props mfields: http://wordpress.mfields.org/2011/post-format-queries/
				$formats = get_post_format_slugs();
				foreach ( (array) $formats as $i => $format )
					$formats[$i] = 'post-format-' . $format;

				$news = new WP_Query( array(
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => 1,
					'tax_query'           => array(
						array(
							'taxonomy' => 'post_format',
							'field'    => 'slug',
							'terms'    => $formats,
							'operator' => 'NOT IN',
						),
					),
				) );
			?>
			<?php if ( $news->have_posts() ) : $post_counter = 0; ?>
				<?php while ( $news->have_posts() ) : $news->the_post(); ?>
					<?php $news_class_last = 'last'; // (bool) ( ++$post_counter % 2 ) ? '' : 'last'; ?>

					<div class="news-item <?php echo esc_attr( $news_class_last ); ?>">
						<h4>
							<a href="<?php the_permalink(); ?>">
								<?php the_title(); ?>
							</a>
						</h4>

						<span class="wc-news-meta">
							by <?php the_author_posts_link(); ?> on <strong><?php the_date(); ?></strong>
						</span>

						<?php the_excerpt('keep reading'); ?>

						<a href="<?php the_permalink(); ?>" class="wc-news-more">
							Keep Reading &raquo;
						</a>
					</div>

				<?php endwhile; ?>
			<?php endif; ?>

			<a href="<?php echo esc_url( home_url( '/news/' ) ); ?>" class="more">
				More News &rarr;
			</a>
		</div><!-- .wc-news -->

		<div class="wc-global-sponsors last">
			<h3><strong>Global Community Sponsors</strong></h3>

			<ul id="home-sponsors-slideshow" class="widget-container sponsors-widget-list cycle-me">
				<?php dynamic_sidebar( 'sponsors-widget-area' ); ?>
			</ul>

			<a href="<?php echo esc_attr( get_permalink( get_page_by_path( 'global-community-sponsors' ) ) ); ?>" class="more">More sponsors &rarr;</a>
		</div>  <!-- .wc-global-sponsors -->

	</div> <!-- #wc-content-blocks -->

<?php get_footer(); ?>

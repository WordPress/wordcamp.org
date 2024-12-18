<?php
/**
 * Template Name: WordCamp Schedule
 *
 * A custom page template for the Upcoming WordCamp schedule.
 */

get_header(); ?>

		<div id="container" class="wc-schedule">
			<div id="content" role="main">

				<?php if ( have_posts() ) :
					the_post(); ?>

				<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<h1 class="entry-title"><?php the_title(); ?></h1>
					<div class="entry-content">
						<?php the_content(); ?>
					</div><!-- .entry-content -->
				</div><!-- #post-## -->

				<?php endif; // end of the loop. ?>

					<?php // Get the upcoming approved (published) WordCamps
					if ( function_exists( 'wcpt_has_wordcamps' ) &&
						wcpt_has_wordcamps( array(
							'post_status'    => WordCamp_Loader::get_public_post_statuses(),
							'posts_per_page' => -1,
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
						global $wcpt_template;
						$wordcamps = WordCamp_Central_Theme::group_wordcamps_by_year( $wcpt_template->posts );
					?>

					<?php foreach ( $wordcamps as $year => $posts ) : ?>
						<h3 class="wc-schedule-year"><?php echo esc_html( $year ); ?></h3>

						<ul class="wc-schedule-list">
							<?php foreach ( $posts as $post ) :
								setup_postdata( $post ); ?>

								<li>
									<a href="<?php echo esc_url( WordCamp_Central_Theme::get_best_wordcamp_url( $post->ID ) ); ?>">
										<?php if ( has_post_thumbnail() ) : ?>
											<?php the_post_thumbnail( 'wccentral-thumbnail-small', array( 'class' => 'wc-image' ) ); ?>
										<?php else : ?>
											<div class="wc-image wp-post-image wordcamp-placeholder-thumb" title="<?php the_title(); ?>"></div>
										<?php endif; ?>

										<h2 class="wc-title"><?php wcpt_wordcamp_title(); ?></h2>
										<span class="wc-country"><?php wcpt_wordcamp_location( $post->ID ); ?></span>
										<?php if ( $post->{'Virtual event only'} ) : ?>
											<span class="wc-online-event">Online Event</span>
										<?php endif; ?>

										<span class="wc-date">
											<?php WordCamp_Central_Theme::the_wordcamp_date( $post->ID, true ); ?>
										</span>
									</a>
								</li>

							<?php endforeach; // $posts as post ?>
						</ul>
					<?php wp_reset_postdata(); endforeach; ?>

				<a href="<?php echo esc_url( home_url( '/schedule/past-wordcamps/' ) ); ?>" class="wc-schedule-more">
					Past WordCamps &rarr;
				</a>

				<?php endif; // wcpt_has_wordcamps ?>

				<h2>Stay Informed About Upcoming Events</h2>

				<p>There are several ways to keep track of upcoming WordPress Events, including WordCamps, and stay connected with the vibrant WordPress community:</p>

				<ul>
					<li><strong>Upcoming WordPress Events:</strong> Check out our <a href="https://events.wordpress.org/">landing page with all types of WordPress Events</a> and filter them by format, type, month and country.</li>
					<li><strong>RSS Feed:</strong> <a href="https://central.wordcamp.org/news/2013/12/30/rss-feed-now-available-for-newly-announced-wordcamps/">Subscribe to our RSS feed</a> to get updates on new events and schedule changes directly in your feed reader.</li>
					<li><strong>ICS Calendar:</strong> Add this URL to your calendar application to keep track of all upcoming events: <a href="https://central.wordcamp.org/calendar.ics"><?php echo esc_url( site_url( 'calendar.ics' ) ); ?></a></li>
					<li><strong>JSON API:</strong> Developers can use our <a href="<?php echo esc_url( get_rest_url( null, 'wp/v2/wordcamps' ) ); ?>">JSON API</a> 	to integrate event information into mobile apps, websites, and more. For Meetup events and other WordPress community gatherings, consider using <a href="https://codex.wordpress.org/WordPress.org_API#Events">api.wordpress.org/events</a>.
					</li>
				</ul>
				<p>Stay tuned and get ready to join us at an upcoming WordPress Event near you!</p>

			</div><!-- #content -->
		</div><!-- #container -->

<?php
	get_sidebar( 'schedule' );
	get_footer();

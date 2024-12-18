<?php
/**
 * Sidebar for schedules page template.
 */
?>

		<div id="primary" class="wc-planned" role="complementary">
			<h3>Planned WordCamps or WordPress Events</h3>

			<p>These WordCamps and WordPress Events are in the early stages of planning and don’t yet have confirmed dates. Once their schedules are finalized, they will be added to our comprehensive list of approved events.</p>

			<?php
				// Get the upcoming approved (published) WordCamps *with dates*
				$args = array(
					'posts_per_page' => -1,
					'post_status'    => WordCamp_Loader::get_pre_planning_post_statuses(),
					'meta_key'       => 'Start Date (YYYY-mm-dd)',
					'orderby'        => 'meta_value',
					'order'          => 'ASC',
					'meta_query'     => array( array(
						'key'        => 'Start Date (YYYY-mm-dd)',
						'value'      => 1,
						'compare'    => '>' // Only with dates
					) )
				);
			?>

			<?php if ( function_exists( 'wcpt_has_wordcamps' ) ) : ?>

			<ul class="xoxo">

				<?php wcpt_has_wordcamps( $args ); ?>
				<?php while ( wcpt_wordcamps() ) :
					wcpt_the_wordcamp(); ?>

					<li>
						<strong>
							<?php if ( wcpt_get_wordcamp_url() ) : ?>
								<a href="<?php echo esc_url( wcpt_get_wordcamp_url() ); ?>"><?php wcpt_wordcamp_title(); ?></a>
							<?php else : ?>
								<?php wcpt_wordcamp_title(); ?>
							<?php endif; ?>
						</strong><br />
						<?php if ( wcpt_get_wordcamp_start_date( 0, 'F, Y' ) ) : ?>
						<?php wcpt_wordcamp_start_date( 0, 'F, Y' ); ?><br />
						<?php endif; ?>
						<?php wcpt_wordcamp_location(); ?>
					</li>

				<?php endwhile; // wcpt_wordcamps ?>

				<?php
					// Change the query args, this time get the ones without dates
					// and run the query again
					$args['meta_query'][0]['compare'] = '<';
					$args['orderby']                  = 'date';
					wcpt_has_wordcamps( $args );
				?>

				<?php while ( wcpt_wordcamps() ) :
					wcpt_the_wordcamp(); ?>

					<li>
						<strong>
							<?php if ( wcpt_get_wordcamp_url() ) : ?>
								<a href="<?php echo esc_url( wcpt_get_wordcamp_url() ); ?>">
									<?php wcpt_wordcamp_title(); ?>
								</a>
							<?php else : ?>
								<?php wcpt_wordcamp_title(); ?>
							<?php endif; ?>
						</strong><br />
						<?php wcpt_wordcamp_location(); ?>
					</li>

				<?php endwhile; // wcpt_wordcamps ?>

			</ul>
			<h3>Looking for a WordCamp or WordPress Event in Your City?</h3>

			<p>If you don&#8217;t see your city listed but are excited about the prospect of hosting a local WordCamp or WordPress Event, why not get involved? <a href="https://central.wordcamp.org/become-an-organizer/">Discover what it takes to become an organizer</a> and bring a WordCamp or other WordPress event to your area!</p>

			<?php endif; // wcpt_has_wordcamps / function_exists ?>



		</div><!-- #primary .widget-area -->

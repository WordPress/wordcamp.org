<?php

$gallery_term = get_term_by( 'name', _x( 'gallery', 'gallery category slug', 'wordcamporg' ), 'category' );
if ( ! $gallery_term ) {
	$gallery_term = get_term_by( 'slug', _x( 'gallery', 'gallery category slug', 'wordcamporg' ), 'category' );
}

?>

<?php /* Display navigation to next/previous pages when applicable */ ?>
<?php if ( $wp_query->max_num_pages > 1 ) : ?>
	<div id="nav-above" class="navigation">
		<div class="nav-previous">
			<?php next_posts_link( '<span class="meta-nav">&larr;</span> ' . esc_html__( 'Older posts', 'wordcamporg' ) ); ?>
		</div>

		<div class="nav-next">
			<?php previous_posts_link( esc_html__( 'Newer posts', 'wordcamporg' ) . ' <span class="meta-nav">&rarr;</span>' ); ?>
		</div>
	</div>
<?php endif; ?>

<?php /* If there are no posts to display, such as an empty archive page */ ?>
<?php if ( ! have_posts() ) : ?>
	<div id="post-0" class="post error404 not-found">
		<h1 class="entry-title">
			<?php esc_html_e( 'Not Found', 'wordcamporg' ); ?>
		</h1>

		<div class="entry-content">
			<p><?php esc_html_e( 'Apologies, but no results were found for the requested archive. Perhaps searching will help find a related post.', 'wordcamporg' ); ?></p>
			<?php get_search_form(); ?>
		</div>
	</div>
<?php endif; ?>

<?php while ( have_posts() ) :
	the_post(); ?>
	<?php /* How to display posts in the Gallery category. */ ?>
	<?php if ( $gallery_term && in_category( $gallery_term->term_id ) ) : ?>
		<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<h2 class="entry-title">
				<a href="<?php the_permalink(); ?>" title="<?php printf( esc_attr__( 'Permalink to %s', 'wordcamporg' ), the_title_attribute( 'echo=0' ) ); ?>" rel="bookmark">
					<?php the_title(); ?>
				</a>
			</h2>

			<div class="entry-meta">
				<?php twentyten_posted_on(); ?>
			</div>

			<div class="entry-content">
				<?php if ( post_password_required() ) : ?>
					<?php the_content(); ?>
				<?php else : ?>
					<?php
					$images = get_children( array(
						'post_parent'    => $post->ID,
						'post_type'      => 'attachment',
						'post_mime_type' => 'image',
						'orderby'        => 'menu_order',
						'order'          => 'ASC',
						'numberposts'    => 999,
					) );

					if ( $images ) :
						$total_images  = count( $images );
						$image         = array_shift( $images );
						$image_img_tag = wp_get_attachment_image( $image->ID, 'thumbnail' );
						?>

						<div class="gallery-thumb">
							<a class="size-thumbnail" href="<?php the_permalink(); ?>">
								<?php echo $image_img_tag; ?>
							</a>
						</div>

						<p><em>
							<?php printf(
								__( 'This gallery contains <a %1$s>%2$s photos</a>.', 'wordcamporg' ),
								'href="' . get_permalink() . '" title="' . sprintf( esc_attr__( 'Permalink to %s', 'wordcamporg' ), the_title_attribute( 'echo=0' ) ) . '" rel="bookmark"',
								$total_images
							); ?>
						</em></p>
					<?php endif; ?>

					<?php the_excerpt(); ?>
				<?php endif; ?>
			</div><!-- .entry-content -->

			<div class="entry-utility">
				<a
					href="<?php echo esc_url( get_term_link( $gallery_term->term_id, 'category' ) ); ?>"
					title="<?php esc_attr_e( 'View posts in the Gallery category', 'wordcamporg' ); ?>
				">
					<?php esc_html_e( 'More Galleries', 'wordcamporg' ); ?>
				</a>
				<span class="meta-sep">|</span>
				<span class="comments-link">
					<?php comments_popup_link( esc_html__( 'Leave a comment', 'wordcamporg' ), esc_html__( '1 Comment', 'wordcamporg' ), esc_html__( '% Comments', 'wordcamporg' ) ); ?>
				</span>

				<?php edit_post_link( esc_html__( 'Edit', 'wordcamporg' ), '<span class="meta-sep">|</span> <span class="edit-link">', '</span>' ); ?>
			</div>
		</div><!-- #post-## -->

		<?php /* How to display posts in the asides category */ ?>
	<?php elseif ( in_category( _x( 'asides', 'asides category slug', 'wordcamporg' ) ) ) : ?>
		<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<?php if ( is_archive() || is_search() ) : // Display excerpts for archives and search. ?>
				<div class="entry-summary">
					<?php the_excerpt(); ?>
				</div>
			<?php else : ?>
				<div class="entry-content">
					<?php the_content( esc_html__( 'Continue reading', 'wordcamporg' ) . ' <span class="meta-nav">&rarr;</span>' ); ?>
				</div>
			<?php endif; ?>

			<div class="entry-utility">
				<?php twentyten_posted_on(); ?>
				<span class="meta-sep">|</span>
				<span class="comments-link">
					<?php comments_popup_link( esc_html__( 'Leave a comment', 'wordcamporg' ), esc_html__( '1 Comment', 'wordcamporg' ), esc_html__( '% Comments', 'wordcamporg' ) ); ?>
				</span>
				<?php edit_post_link( esc_html__( 'Edit', 'wordcamporg' ), '<span class="meta-sep">|</span> <span class="edit-link">', '</span>' ); ?>
			</div>
		</div><!-- #post-## -->

		<?php /* How to display all other posts. */ ?>

	<?php else : ?>
		<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<h2 class="entry-title">
				<a href="<?php the_permalink(); ?>" title="<?php printf( esc_attr__( 'Permalink to %s', 'wordcamporg' ), the_title_attribute( 'echo=0' ) ); ?>" rel="bookmark">
					<?php the_title(); ?>
				</a>
			</h2>

			<div class="entry-meta">
				<?php twentyten_posted_on(); ?>
			</div>

			<?php if ( is_archive() || is_search() ) : // Only display excerpts for archives and search. ?>
				<div class="entry-summary">
					<?php the_excerpt(); ?>
				</div>

			<?php else : ?>
				<div class="entry-content">
					<?php
					$more_title = get_the_title();

					the_content(
						sprintf(
							/* translators: %s - the title of the post to continue reading */
							esc_html__( 'Continue reading %s', 'wordcamporg' ),
							$more_title ? '<span class="screen-reader-text">' . esc_html( $more_title ) . '</span> ' : ''
						) . '<span class="meta-nav">&rarr;</span>'
					);
					?>

					<?php
					wp_link_pages( array(
						'before' => '<div class="page-link">' . esc_html__( 'Pages:', 'wordcamporg' ),
						'after'  => '</div>',
					) );
					?>
				</div>
			<?php endif; ?>

			<div class="entry-utility">
				<?php if ( count( get_the_category() ) ) : ?>
					<span class="cat-links">
						<?php printf(
							wp_kses_post( __( '<span class="%1$s">Posted in</span> %2$s', 'wordcamporg' ) ),
							'entry-utility-prep entry-utility-prep-cat-links',
							get_the_category_list( ', ' )
						); ?>
					</span>
					<span class="meta-sep">|</span>
				<?php endif; ?>

				<?php
				$tags_list = get_the_tag_list( '', ', ' );
				if ( $tags_list ) : ?>
					<span class="tag-links">
						<?php printf(
							wp_kses_post( __( '<span class="%1$s">Tagged</span> %2$s', 'wordcamporg' ) ),
							'entry-utility-prep entry-utility-prep-tag-links',
							$tags_list
						); ?>
					</span>
					<span class="meta-sep">|</span>
				<?php endif; ?>

				<span class="comments-link">
					<?php comments_popup_link( esc_html__( 'Leave a comment', 'wordcamporg' ), esc_html__( '1 Comment', 'wordcamporg' ), esc_html__( '% Comments', 'wordcamporg' ) ); ?>
				</span>

				<?php edit_post_link( esc_html__( 'Edit', 'wordcamporg' ), '<span class="meta-sep">|</span> <span class="edit-link">', '</span>' ); ?>
			</div><!-- .entry-utility -->
		</div><!-- #post-## -->

		<?php comments_template( '', true ); ?>

	<?php endif; // This was the if statement that broke the loop into three parts based on categories. ?>
<?php endwhile; // End the loop. Whew. ?>

<?php /* Display navigation to next/previous pages when applicable */ ?>
<?php if ( $wp_query->max_num_pages > 1 ) : ?>
	<div id="nav-below" class="navigation">
		<div class="nav-previous">
			<?php next_posts_link( '<span class="meta-nav">&larr;</span> ' . esc_html__( 'Older posts', 'wordcamporg' ) ); ?>
		</div>

		<div class="nav-next">
			<?php previous_posts_link( esc_html__( 'Newer posts', 'wordcamporg' ) . ' <span class="meta-nav">&rarr;</span>' ); ?>
		</div>
	</div>
<?php endif; ?>

<?php
/**
 * @package WCBS
 * @since WCBS 1.0
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header class="entry-header">
		<h1 class="entry-title"><a href="<?php the_permalink(); ?>" title="<?php printf( esc_attr__( 'Permalink to %s', 'wordcamporg' ), the_title_attribute( 'echo=0' ) ); ?>" rel="bookmark"><?php the_title(); ?></a></h1>

		<?php if ( 'post' == get_post_type() ) : ?>
		<div class="entry-meta">
			<?php wcbs_posted_on(); ?>
		</div><!-- .entry-meta -->
		<?php endif; ?>
	</header><!-- .entry-header -->

	<?php if ( is_search() ) : // Only display Excerpts for Search ?>
	<div class="entry-summary">
		<?php the_excerpt(); ?>
	</div><!-- .entry-summary -->
	<?php else : ?>
	<div class="entry-content">
		<?php
		the_content(
			sprintf(
				/* translators: %s - the title of the post to continue reading */
				esc_html__( 'Continue reading %s', 'wordcamporg' ),
				'<span class="assistive-text">' . esc_html( get_the_title() ) . '</span> '
			) . '<span class="meta-nav">&rarr;</span>'
		);

		wp_link_pages(
			array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'wordcamporg' ),
				'after'  => '</div>',
			)
		);
		?>
	</div><!-- .entry-content -->
	<?php endif; ?>

	<footer class="entry-meta">
		<?php if ( 'post' == get_post_type() ) : // Hide category and tag text for pages on Search ?>
			<?php
				/* translators: used between list items, there is a space after the comma */
				$categories_list = get_the_category_list( __( ', ', 'wordcamporg' ) );
				if ( $categories_list && wcbs_categorized_blog() ) :
			?>
			<span class="cat-links">
				<?php printf( __( 'Posted in %1$s', 'wordcamporg' ), $categories_list ); ?>
			</span>
			<?php endif; // End if categories ?>

			<?php
				/* translators: used between list items, there is a space after the comma */
				$tags_list = get_the_tag_list( '', __( ', ', 'wordcamporg' ) );
				if ( $tags_list ) :
			?>
			<span class="sep"> | </span>
			<span class="tag-links">
				<?php printf( __( 'Tagged %1$s', 'wordcamporg' ), $tags_list ); ?>
			</span>
			<?php endif; // End if $tags_list ?>
		<?php endif; // End if 'post' == get_post_type() ?>

		<?php if ( ! post_password_required() && ( comments_open() || '0' != get_comments_number() ) ) : ?>
		<span class="sep"> | </span>
		<span class="comments-link"><?php comments_popup_link( __( 'Leave a comment', 'wordcamporg' ), __( '1 Comment', 'wordcamporg' ), __( '% Comments', 'wordcamporg' ) ); ?></span>
		<?php endif; ?>

		<?php edit_post_link( esc_html__( 'Edit', 'wordcamporg' ), '<span class="sep"> | </span><span class="edit-link">', '</span>' ); ?>
	</footer><!-- #entry-meta -->
</article><!-- #post-<?php the_ID(); ?> -->

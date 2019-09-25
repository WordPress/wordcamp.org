<?php
/**
 * Template Name: Day of Event
 *
 * Shows attendees the content that is most relevant during the event (directions to the venue, schedule, etc).
 */

namespace WordCamp\Theme_Templates;

defined( 'WPINC' ) || die();

get_header();
?>

<main id="main" class="site-main">
<?php while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<header class="entry-header">
			<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
		</header>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>
	</article>
<?php endwhile; ?>
</main>

<?php

get_footer();

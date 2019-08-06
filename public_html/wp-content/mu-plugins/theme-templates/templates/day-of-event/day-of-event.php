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

<main id="day-of-event">
	<?php esc_html_e( 'Loading...', 'wordcamporg' ); ?>
</main>

<?php

the_content();

get_footer();

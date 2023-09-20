<?php

/**
 * Top level template for the output of the Site Cloner Customizer Control
 */

namespace WordCamp\Site_Cloner;
defined( 'WPINC' ) or die();

$wordcamp = get_wordcamp_post();

?>

<div id="wcsc-cloner">
	<h3>
		<?php esc_html_e( 'WordCamp Sites', 'wordcamporg' ); ?>
		<span id="wcsc-sites-count" class="title-count wcsc-sites-count"></span>
	</h3>

	<?php if ( 'wcpt-closed' === $wordcamp->post_status ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php echo esc_html( sprintf(
					// translators: %s is the name of the WordCamp.
					__( '%s has already completed, are you sure you want to overwrite it with styles and settings from another site?', 'wordcamporg' ),
					get_wordcamp_name()
				) ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="filters"></div>

	<div class="wcsc-search">
		<ul id="wcsc-results"></ul>
	</div>
</div>

<?php

defined( 'WPINC' ) || die();

?>

rework this to be a metabox, probably rename file



<p>
	<?php _e( 'Submit the form below to immediately send the message to the chosen recipients for WordCamps that match the selected criteria.', 'wordcamporg' ); ?>
</p>

<p>
	<?php printf(
		__(
			'The placeholders in each message will use data from the corresponding camp;
			e.g., the email to WordCamp Chicago would use <code>Chicago, IL, USA</code> as the <code>[wordcamp_location]</code>.',
			'wordcamporg'
		),
		date( 'Y' )
	); ?>
</p>


<p>
	pick template
	 - if textarea not empty, prompt before overwriting
</p>

// get feedback on UI from sarah
// get feedback on code from corey/vedanshu
// don't need form anymore

<form name="manually-send" action="" method="POST">
	<textarea></textarea>

	<div>
		placeholders
			- floated to right side while editor on left, use grid though
	</div>

	<div>
		recipients
		- get DRY list
	</div>

	<div>
		send by
		- individual wordcamp: <?php echo get_wordcamp_dropdown(); ?>
		- all camps in status: <?php echo get_wordcamp_status_dropdown( 'wcor_manually_send_by_status' ); ?>
	</div>




	<p>
		<?php submit_button( 'Send', 'primary', 'manually-send' ); ?>
	</p>
</form>

<?php

defined( 'WPINC' ) || die();

// todo this'll be removed b/c have page now

?>

<p>
	<?php _e( 'Check the box below and save the post to manually send this message to all WordCamps that have the given status. The assigned recipient(s) will <strong>not</strong> receive a copy.', 'wordcamporg' ); ?>
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
	<?php _e( 'It will be sent immediately, regardless of when it is scheduled to be sent automatically, and regardless of whether or not it has already been sent automatically.', 'wordcamporg' ); ?>
</p>

<p>
	<?php echo get_wordcamp_status_dropdown( 'wcor_manually_send_by_status' ); ?>
</p>

<p>
	<input id="wcor_manually_send_checkbox" name="wcor_manually_send" type="checkbox">
	<label for="wcor_manually_send_checkbox"><?php _e( 'Manually send this e-mail by status', 'wordcamporg' ); ?></label>
</p>

<?php

namespace WordCamp\SpeakerFeedback\View;

defined( 'WPINC' ) || die();

/** @var string $message */
?>

<hr />

<p>
	<?php echo wp_kses_data( $message ); ?>
</p>

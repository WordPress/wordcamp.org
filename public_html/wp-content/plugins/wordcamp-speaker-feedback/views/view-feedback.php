<?php

namespace WordCamp\SpeakerFeedback\View;

use WordCamp\SpeakerFeedback\Walker_Feedback;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\get_assets_path;
use function WordCamp\SpeakerFeedback\Comment\get_feedback;

defined( 'WPINC' ) || die();

?>
<hr />
<div class="speaker-feedback">
	<?php
	$feedback = get_feedback( array( get_the_ID() ), array( 'approve' ) );
	wp_list_comments(
		array(
			// Note: `Walker_Feedback` does not support the callback or format args.
			'walker' => new Walker_Feedback(),
			'style' => 'div',
		),
		$feedback
	);
	?>
</div>

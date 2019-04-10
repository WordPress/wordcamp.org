<?php

namespace WordCamp\Blocks\Schedule;
use function WordCamp\Blocks\Shared\{ get_all_the_content };

defined( 'WPINC' ) || die();

/**
 * @var array  $attributes
 * @var array  $sessions
 * @var string $container_classes
 */

if ( empty( $sessions ) ) {
	return;
}

?>

this will be a grid


<?php
/*update class names to match this, then make js work w/ both
probalby need to add data-track columns, maybe use those instead of classes, since can still target w/ css
don't want this in editor? well, probably want it for accuracy, but clicking on it shouldn't do anything
but if it doesn't do anything, it might just confuse people
*/

// is there a good way to reuse logic? probably not until that G issue is solved, which wont be anytime soon :(
?>

<div class="wcb-session-favourite-icon">
	<a class="fav-session-button">
		<span class="dashicons dashicons-star-filled"></span>
	</a>
</div>

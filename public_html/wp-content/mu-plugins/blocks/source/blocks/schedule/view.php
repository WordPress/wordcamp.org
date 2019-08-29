<?php

namespace WordCamp\Blocks\Schedule;

defined( 'WPINC' ) || die();

/*
 * todo
 *
 * test normal/wide/full width setting on various screen sizes
 * this commit fixes https://meta.trac.wordpress.org/ticket/3842 and 3117, props mark, mel, etc
 */

/**
 * @var array $sessions
 */

if ( empty( $sessions ) ) {
	return;
}

?>

This will be the front-end view.

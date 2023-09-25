<?php
namespace WordPressdotorg\MU_Plugins\Admin;

// Delay loading until admin_init.
add_action( 'admin_init', function() {
	require_once __DIR__ . '/user-list-last-logged-in.php';
}, 1 );

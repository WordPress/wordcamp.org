<?php

/**
 * ⚠️ These tests run against the production database on wordcamp.org and profiles.w.org.
 * Make sure that any modifications are hardcoded to only affect test sites and test user accounts.
 *
 * usage: wp eval-file e2e.php test_name
 */

namespace WordCamp\Participation_Notifier\Tests;
use Exception, WordCamp_Participation_Notifier, WP_User, WP_Post;

ini_set( 'display_errors', 'On' ); // won't do anything if fatal errors

if ( 'staging' !== wp_get_environment_type() || 'cli' !== php_sapi_name() ) {
	die( 'Error: Wrong environment.' );
}

const TEST_POST_ID  = 2529;
const TEST_USERNAME = 'iandunn-test';

/** @var array $args */
main( $args[0] );

function main( $case ) {
	switch_to_blog( 1056 ); // testing.wordcamp.org/2019
	$user = get_user_by( 'slug', TEST_USERNAME );
	$post = get_post( TEST_POST_ID );

	try {
		require_once dirname( __DIR__ ) . '/wordcamp-participation-notifier.php';
		call_user_func( __NAMESPACE__ . "\\test_$case", $GLOBALS['WordCamp_Participation_Notifier'], $user, $post );

	} catch ( Exception $exception ) {
		echo $exception->getMessage();

	} finally {
		restore_current_blog();
	}
}

function test_add( WordCamp_Participation_Notifier $notifier, WP_User $user, WP_Post $post ) {
	$duplication_protection_key = sprintf( 'wc_published_activity_%s_%s', get_current_blog_id(), $post->ID );

	delete_user_meta( $user->ID, $duplication_protection_key ); // Allow re-testing activity addition.
	$notifier->username_meta_add( $post->ID, '_wcpt_user_id', $user->ID );

	echo "\nThere should be a new speaker confirmation activity on https://profiles.wordpress.org/$user->user_nicename/ \n";
}

function test_delete( WordCamp_Participation_Notifier $notifier, WP_User $user, WP_Post $post ) {
	$notifier->username_meta_delete( array(), $post->ID, '_wcpt_user_id' );

	echo "\nThere shouldn't be a speaker badge anymore at https://profiles.wordpress.org/$user->user_nicename/. The activity should still be there. \n";
}

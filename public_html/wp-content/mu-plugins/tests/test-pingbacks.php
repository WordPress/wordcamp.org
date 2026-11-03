<?php

namespace WordCamp\Pingbacks\Tests;
use WP_UnitTestCase;
use wp_xmlrpc_server;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group pingbacks
 */
class Test_Pingbacks extends WP_UnitTestCase {
	/**
	 * Load the XML-RPC server, which Core only loads for `xmlrpc.php` requests.
	 */
	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
	}

	/**
	 * @covers WordCamp\Pingbacks\close_pings
	 *
	 * Verify that pings are closed even on a post whose own setting allows them, and comments are left alone.
	 */
	public function test_pings_closed_on_every_post() {
		$post_id = self::factory()->post->create( array(
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		) );

		$this->assertFalse( pings_open( $post_id ), 'Pings should be closed even when the post allows them.' );
		$this->assertTrue( comments_open( $post_id ), 'Comments should not be affected.' );
	}

	/**
	 * @covers WordCamp\Pingbacks\remove_pingback_methods
	 *
	 * Verify that the pingback and trackback XML-RPC methods are gone, and the rest of XML-RPC is still there.
	 */
	public function test_pingback_methods_removed() {
		$methods = ( new wp_xmlrpc_server() )->methods;

		$this->assertArrayNotHasKey( 'pingback.ping', $methods );
		$this->assertArrayNotHasKey( 'pingback.extensions.getPingbacks', $methods );
		$this->assertArrayNotHasKey( 'mt.getTrackbackPings', $methods );

		// Jetpack and the editing clients still need these. The `system.*` methods are added later, by `IXR_Server`.
		foreach ( array( 'wp.getOptions', 'mt.supportedMethods', 'wp.getPosts', 'metaWeblog.newPost' ) as $method ) {
			$this->assertArrayHasKey( $method, $methods, "$method should still be available." );
		}
	}
}

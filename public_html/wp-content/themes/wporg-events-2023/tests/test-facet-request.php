<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/events-query.php';

/**
 * Tests for what a filtered request can and can't change about the main query.
 *
 * `Database_TestCase` rather than `WP_UnitTestCase`: `go_to()` fires `wp`, where the Latest Site Hints
 * mu-plugin looks the site up on central, and only the former provisions that site.
 *
 * @group events-2023
 */
class Test_Facet_Request extends Database_TestCase {
	/**
	 * A filter path can't change which post statuses the main query returns.
	 *
	 * This checks a private post rather than a draft: `is_admin()` is true in this suite, and in the admin
	 * core already shows drafts to everyone, so a draft would be returned whatever the facets said.
	 *
	 * @dataProvider data_status_requests
	 *
	 * @covers \WordPressdotorg\Events_2023\set_query_vars_from_pretty_url
	 *
	 * @param string $url
	 */
	public function test_facets_cannot_set_the_post_status( $url ) {
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );

		wp_set_current_user( 0 );
		$this->go_to( $url );

		$this->assertSame( '', get_query_var( 'post_status' ) );
		$this->assertNotContains( $private, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * Requests that put `post_status` in the facets.
	 *
	 * @return array
	 */
	public function data_status_requests() {
		return array(
			'search feed' => array( '/?s=&feed=rss2&event_facets=post_status/private-draft/' ),
			'search'      => array( '/?s=&event_facets=post_status/private/' ),
		);
	}
}

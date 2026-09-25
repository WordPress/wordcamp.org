<?php

namespace WordCamp\Helpers\WCPT\Tests;
use WordCamp\Tests\Database_TestCase;
use WP_Post;

defined( 'WPINC' ) || die();


/**
 * Tests for `wordcamp` post helpers that need the mock network of sites.
 *
 * Split out from `Test_Helpers_WCPT` so that the rest of those tests don't pay for building it.
 *
 * @group mu-plugins
 * @group helpers
 * @group helpers-wcpt
 */
class Test_Helpers_WCPT_Sites extends Database_TestCase {
	/**
	 * `_site_id` is used when it's set.
	 *
	 * @covers ::get_wordcamp_site_id
	 */
	public function test_get_wordcamp_site_id_prefers_the_site_id_meta() {
		$wordcamp = $this->create_wordcamp( array(
			'URL'      => 'https://vancouver.wordcamp.test/2020/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$this->assertEquals( self::$slash_year_2016_site_id, get_wordcamp_site_id( $wordcamp ) );
	}

	/**
	 * The `URL` meta resolves to the site it names when `_site_id` is empty.
	 *
	 * @covers ::get_wordcamp_site_id
	 */
	public function test_get_wordcamp_site_id_falls_back_to_the_url_meta() {
		$wordcamp = $this->create_wordcamp( array( 'URL' => 'https://vancouver.wordcamp.test/2016/' ) );

		$this->assertEquals( self::$slash_year_2016_site_id, get_wordcamp_site_id( $wordcamp ) );
	}

	/**
	 * The `URL` fallback only ever resolves to the site the URL names.
	 *
	 * Resolving by path prefix would walk up to somebody else's site, which is what the automation then acts on.
	 *
	 * @covers ::get_wordcamp_site_id
	 *
	 * @dataProvider data_get_wordcamp_site_id_ignores_sites_the_url_does_not_name
	 *
	 * @param string $url
	 */
	public function test_get_wordcamp_site_id_ignores_sites_the_url_does_not_name( $url ) {
		$wordcamp = $this->create_wordcamp( array( 'URL' => $url ) );

		$this->assertEmpty( get_wordcamp_site_id( $wordcamp ) );
	}

	/**
	 * Test cases for `test_get_wordcamp_site_id_ignores_sites_the_url_does_not_name()`.
	 *
	 * @return array
	 */
	public function data_get_wordcamp_site_id_ignores_sites_the_url_does_not_name() {
		return array(
			'extra path segment'    => array( 'https://vancouver.wordcamp.test/2016/x/' ),
			'domain with root site' => array( 'https://japan.wordcamp.test/2099/' ),
			'events network root'   => array( 'https://events.wordpress.test/paris/2027/training/' ),
			'no site at all'        => array( 'https://barcelona.wordcamp.test/2026/' ),
		);
	}

	/**
	 * Create a `wordcamp` post with the given meta.
	 *
	 * Created on the root blog, which is where `get_wordcamp_site_id()` looks for it.
	 *
	 * @param array $meta
	 *
	 * @return WP_Post
	 */
	protected function create_wordcamp( array $meta ) {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'wordcamp',
			'post_status' => 'publish',
		) );

		foreach ( $meta as $key => $value ) {
			add_post_meta( $post_id, $key, $value );
		}

		$wordcamp = get_post( $post_id );

		restore_current_blog();

		return $wordcamp;
	}
}

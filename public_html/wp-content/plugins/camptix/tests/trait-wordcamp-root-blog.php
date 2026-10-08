<?php

defined( 'WPINC' ) || die();

/**
 * Provisions the central WordCamp.org root blog for a test class.
 *
 * Saving a `tix_attendee` post runs `CampTix_Plugin::is_wordcamp_closed()`,
 * which calls `get_wordcamp_post()`, which does
 * `switch_to_blog( WORDCAMP_ROOT_BLOG_ID )`. `switch_to_blog()` doesn't
 * check that the blog actually exists, so without this fixture the
 * multisite test install -- which only creates the default blog --
 * throws "table doesn't exist" DB errors on every attendee-post test.
 *
 * `WordCamp\Tests\Database_TestCase` already provisions this site (plus ten
 * others most CampTix tests don't need); this trait provisions just the one,
 * to avoid that heavier setup for tests that don't otherwise need it.
 */
trait CampTix_Root_Blog_Fixture {
	/**
	 * @var int
	 */
	protected static $wordcamp_root_blog_id;

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	protected static function create_wordcamp_root_blog( $factory ) {
		self::$wordcamp_root_blog_id = $factory->blog->create( array(
			'blog_id'    => WORDCAMP_ROOT_BLOG_ID,
			'network_id' => WORDCAMP_NETWORK_ID,
		) );
	}

	/**
	 * Tears down the root blog created by `create_wordcamp_root_blog()`.
	 */
	protected static function delete_wordcamp_root_blog() {
		wp_delete_site( self::$wordcamp_root_blog_id );
	}
}

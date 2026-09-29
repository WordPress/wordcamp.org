<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use function WordCamp\Blocks\Utilities\{ get_all_the_content, get_trimmed_content };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/blocks.php';
require_once dirname( __DIR__ ) . '/blocks/includes/definitions.php';
require_once dirname( __DIR__ ) . '/blocks/includes/content.php';
require_once dirname( __DIR__ ) . '/blocks/source/components/item/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/components/image/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/components/post-list/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/blocks/organizers/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/blocks/sessions/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/blocks/speakers/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/blocks/sponsors/controller.php';

/**
 * Tests for how the listing blocks treat password-protected posts.
 *
 * The content helpers read the stored content rather than going through
 * `get_the_content()`, which is where the password check lives. The post itself
 * stays in the list, the way core's loop shows it on an archive -- only the body
 * is withheld.
 *
 * @group blocks
 */
class Test_Blocks_Content extends WP_UnitTestCase {
	/**
	 * The body that should never reach an anonymous visitor.
	 *
	 * @var string
	 */
	const PROTECTED_BODY = 'Unannounced keynote, still under embargo.';

	/**
	 * Render blocks as a logged-out visitor, from a page rather than a session.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );

		// The suite runs as admin by default, where core suppresses the
		// "Protected:" title prefix. These blocks only ever render on the
		// front end.
		set_current_screen( 'front' );

		// The blocks read their current post from the global, and refuse to
		// render inside a session.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored in tear_down().
		$GLOBALS['post'] = get_post(
			self::factory()->post->create( array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Schedule',
			) )
		);
	}

	/**
	 * Reset the global the blocks read their current post from.
	 */
	public function tear_down() {
		unset( $GLOBALS['post'] );

		// Cleared here rather than inline, so a test that fails part way
		// through cannot leave a later one thinking the visitor holds the
		// password.
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		set_current_screen( 'dashboard' );

		parent::tear_down();
	}

	/**
	 * Create a published, password-protected post of the given type.
	 *
	 * @param string $post_type Post type to create.
	 * @return int The new post's ID.
	 */
	private function create_protected_post( string $post_type ): int {
		return self::factory()->post->create( array(
			'post_type'     => $post_type,
			'post_status'   => 'publish',
			'post_title'    => 'Protected item',
			// The factory fills in a placeholder excerpt, which would mask
			// the helper's fallback to `post_content`.
			'post_excerpt'  => '',
			'post_content'  => self::PROTECTED_BODY,
			'post_password' => 'hunter2',
		) );
	}

	/**
	 * The full-content helper withholds a password-protected body, and
	 * stands the password form in its place the way `get_the_content()`
	 * does. The `the_content` filter runs after core's password check, so a
	 * caller that applies the filter to the stored content has to repeat it.
	 */
	public function test_get_all_the_content_withholds_protected_body() {
		$post_id = $this->create_protected_post( 'wcb_session' );
		$output  = get_all_the_content( $post_id );

		$this->assertStringNotContainsString( self::PROTECTED_BODY, $output );
		$this->assertStringContainsString( 'post-password-form', $output );
	}

	/**
	 * The excerpt helper withholds it too, standing in the same sentence
	 * `get_the_excerpt()` uses. Posts rarely carry a manual excerpt, so the
	 * helper's fallback would otherwise serve the first 55 words of the
	 * protected body.
	 */
	public function test_get_trimmed_content_withholds_protected_body() {
		$post_id = $this->create_protected_post( 'wcb_session' );
		$output  = get_trimmed_content( $post_id );

		$this->assertStringNotContainsString( self::PROTECTED_BODY, $output );
		$this->assertSame( 'There is no excerpt because this is a protected post.', $output );
	}

	/**
	 * A manual excerpt on a protected post is withheld as well, matching
	 * `get_the_excerpt()`.
	 */
	public function test_get_trimmed_content_withholds_protected_manual_excerpt() {
		$post_id = self::factory()->post->create( array(
			'post_type'     => 'wcb_session',
			'post_status'   => 'publish',
			'post_excerpt'  => 'Manual excerpt of the embargoed talk.',
			'post_content'  => self::PROTECTED_BODY,
			'post_password' => 'hunter2',
		) );

		$this->assertStringNotContainsString( 'Manual excerpt of the embargoed talk.', get_trimmed_content( $post_id ) );
	}

	/**
	 * A post with no password is unaffected.
	 */
	public function test_helpers_still_return_unprotected_content() {
		$post_id = self::factory()->post->create( array(
			'post_type'    => 'wcb_session',
			'post_status'  => 'publish',
			'post_excerpt' => '',
			'post_content' => 'Public talk description.',
		) );

		$this->assertStringContainsString( 'Public talk description.', get_all_the_content( $post_id ) );
		$this->assertStringContainsString( 'Public talk description.', get_trimmed_content( $post_id ) );
	}

	/**
	 * A visitor holding the page's password sees the content, the same way
	 * they would on the post itself.
	 */
	public function test_helpers_return_content_for_visitor_holding_the_password() {
		$post_id = $this->create_protected_post( 'wcb_session' );

		// Core matches the cookie against the password with phpass, the same
		// way `wp-login.php?action=postpass` writes it.
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = new \PasswordHash( 8, true );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'hunter2' );

		$content = get_all_the_content( $post_id );
		$excerpt = get_trimmed_content( $post_id );

		$this->assertStringContainsString( self::PROTECTED_BODY, $content );
		$this->assertStringContainsString( self::PROTECTED_BODY, $excerpt );
	}

	/**
	 * The queries behind the four listing blocks keep protected posts, the
	 * way core's archive queries do. Withholding the body is the content
	 * helpers' job; the post's existence is not the secret.
	 *
	 * @dataProvider data_listing_queries
	 *
	 * @param string $post_type The post type the block lists.
	 * @param string $callback  The controller function that queries it.
	 */
	public function test_listing_queries_keep_protected_posts( string $post_type, string $callback ) {
		$protected_id = $this->create_protected_post( $post_type );
		$public_id    = self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => 'Public item',
		) );

		$posts = $callback( array(
			'mode' => 'all',
			'sort' => 'title_asc',
		) );
		$found = wp_list_pluck( $posts, 'ID' );

		$this->assertContains( $public_id, $found );
		$this->assertContains( $protected_id, $found );
	}

	/**
	 * Post types and the controller functions that query them.
	 *
	 * @return array
	 */
	public function data_listing_queries(): array {
		return array(
			'sessions'   => array( 'wcb_session', 'WordCamp\Blocks\Sessions\get_session_posts' ),
			'speakers'   => array( 'wcb_speaker', 'WordCamp\Blocks\Speakers\get_speaker_posts' ),
			'sponsors'   => array( 'wcb_sponsor', 'WordCamp\Blocks\Sponsors\get_sponsor_posts' ),
			'organizers' => array( 'wcb_organizer', 'WordCamp\Blocks\Organizers\get_organizer_posts' ),
		);
	}

	/**
	 * End to end: an anonymous render of each block lists the protected post
	 * without its body. The title stays, carrying core's "Protected:"
	 * prefix, and the body is replaced the way core's loop replaces it.
	 *
	 * @dataProvider data_rendered_blocks
	 *
	 * @param string $post_type The post type the block lists.
	 * @param string $callback  The block's render callback.
	 * @param string $content   The block's `content` attribute.
	 */
	public function test_rendered_block_lists_protected_post_without_its_body( string $post_type, string $callback, string $content ) {
		$this->create_protected_post( $post_type );

		self::factory()->post->create( array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => 'Public item',
			'post_excerpt' => '',
			'post_content' => 'Public body.',
		) );

		$output = $callback( array(
			'mode'    => 'all',
			'sort'    => 'title_asc',
			'content' => $content,
		) );

		$this->assertStringContainsString( 'Public item', $output );
		$this->assertStringNotContainsString( self::PROTECTED_BODY, $output );

		// Core's `protected_title_format`, so the item is still listed.
		$this->assertStringContainsString( 'Protected: Protected item', $output );

		if ( 'full' === $content ) {
			$this->assertStringContainsString( 'post-password-form', $output );
		} else {
			$this->assertStringContainsString( 'this is a protected post', $output );
		}
	}

	/**
	 * Each listing block in each content mode.
	 *
	 * @return array
	 */
	public function data_rendered_blocks(): array {
		$blocks = array(
			'sessions'   => array( 'wcb_session', 'WordCamp\Blocks\Sessions\render' ),
			'speakers'   => array( 'wcb_speaker', 'WordCamp\Blocks\Speakers\render' ),
			'sponsors'   => array( 'wcb_sponsor', 'WordCamp\Blocks\Sponsors\render' ),
			'organizers' => array( 'wcb_organizer', 'WordCamp\Blocks\Organizers\render' ),
		);

		$cases = array();

		foreach ( $blocks as $name => $block ) {
			foreach ( array( 'full', 'excerpt' ) as $content ) {
				$cases[ "$name, $content" ] = array( $block[0], $block[1], $content );
			}
		}

		return $cases;
	}
}

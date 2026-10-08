<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use Automattic\Jetpack\Post_Media\Twitter_Cards;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/jetpack-tweaks/sharing.php';

/**
 * Tests that the Twitter card type added for home pages doesn't override the one Jetpack picks.
 *
 * @group mu-plugins
 * @group jetpack
 */
class Test_Jetpack_Sharing extends WP_UnitTestCase {
	/**
	 * Hook up Jetpack's own Twitter Cards tags, the same way Jetpack does.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( Twitter_Cards::class ) ) {
			$this->markTestSkipped( 'Jetpack is not available.' );
		}

		Twitter_Cards::init();

		// The Remote CSS test bootstrap defines `WP_ADMIN` for the whole run, and `WP_Query` never treats an
		// admin request as the home page. `go_to()` clears the current screen first, so set it again while
		// the request is being parsed. `WP_UnitTestCase` resets the screen and the hooks after each test.
		add_action(
			'parse_request',
			function () {
				set_current_screen( 'front' );
			}
		);

		// `go_to()` fires `wp`, where `maybe_add_latest_site_hints()` switches to a
		// central blog the suite doesn't provision and logs DB errors.
		remove_action( 'wp', 'WordCamp\\Latest_Site_Hints\\maybe_add_latest_site_hints' );
	}

	/**
	 * Run the Open Graph tags filter for the current request, like Jetpack does when printing them.
	 *
	 * @return array
	 */
	protected function get_og_tags() {
		return apply_filters( 'jetpack_open_graph_tags', array( 'og:description' => 'A WordCamp.' ) );
	}

	/**
	 * A static front page with a big enough featured image gets Jetpack's large card.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\add_og_twitter_summary
	 */
	public function test_static_front_page_keeps_large_card() {
		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$image_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/09/banner.png',
				'post_mime_type' => 'image/png',
				'post_parent'    => $page_id,
			)
		);

		wp_update_attachment_metadata(
			$image_id,
			array(
				'width'  => 1200,
				'height' => 630,
				'file'   => '2026/09/banner.png',
			)
		);
		set_post_thumbnail( $page_id, $image_id );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 'summary_large_image', $this->get_og_tags()['twitter:card'] ?? null );
	}

	/**
	 * Jetpack doesn't add a card to a home page that lists the latest posts, so a summary card is added instead.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\add_og_twitter_summary
	 */
	public function test_latest_posts_front_page_gets_summary_card() {
		update_option( 'show_on_front', 'posts' );
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 'summary', $this->get_og_tags()['twitter:card'] ?? null );
	}
}

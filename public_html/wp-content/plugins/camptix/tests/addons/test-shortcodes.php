<?php
defined( 'WPINC' ) || die();

/**
 * @covers CampTix_Addon_Shortcodes
 */
class Test_Camptix_Shortcodes_Addon extends \WP_UnitTestCase {
	/**
	 * Locate the running CampTix_Addon_Shortcodes instance from $camptix->addons_loaded.
	 */
	protected function get_shortcodes_addon() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		foreach ( $camptix->addons_loaded as $addon ) {
			if ( $addon instanceof CampTix_Addon_Shortcodes ) {
				return $addon;
			}
		}

		$this->fail( 'CampTix_Addon_Shortcodes was not loaded.' );
	}

	/**
	 * Capture the ORDER BY clause emitted while rendering [camptix_attendees] with the given attrs.
	 *
	 * @param array $attr Shortcode attributes (e.g. `[ 'orderby' => 'title' ]`).
	 *
	 * @return string The post-filter ORDER BY clause that hits the SQL query.
	 */
	protected function capture_attendees_orderby( $attr ) {
		$addon          = $this->get_shortcodes_addon();
		$captured       = '';
		$capture_filter = function ( $orderby ) use ( &$captured ) {
			$captured = $orderby;
			return $orderby;
		};

		// Priority 99 runs after the addon's own `posts_orderby` filter at the default priority 10.
		add_filter( 'posts_orderby', $capture_filter, 99 );
		$addon->get_attendees_shortcode_content( $addon->sanitize_attendees_atts( $attr ), true );
		remove_filter( 'posts_orderby', $capture_filter, 99 );

		return $captured;
	}

	/**
	 * Ordering by title (the default) must apply the Unicode 5.2 collation so locale-specific
	 * characters like Polish Ł sort with L instead of after Z.
	 *
	 * @covers CampTix_Addon_Shortcodes::render_attendees_list
	 */
	public function test_attendees_shortcode_applies_unicode_520_collation_when_ordering_by_title() {
		$orderby = $this->capture_attendees_orderby( array( 'orderby' => 'title' ) );

		$this->assertStringContainsString( 'post_title COLLATE utf8mb4_unicode_520_ci', $orderby );
	}

	/**
	 * Ordering by date does not touch post_title, so the COLLATE override must not be injected.
	 *
	 * @covers CampTix_Addon_Shortcodes::render_attendees_list
	 */
	public function test_attendees_shortcode_does_not_apply_collation_when_ordering_by_date() {
		$orderby = $this->capture_attendees_orderby( array( 'orderby' => 'date' ) );

		$this->assertStringNotContainsString( 'COLLATE utf8mb4_unicode_520_ci', $orderby );
	}

	/**
	 * The collation filter is added scoped to a single get_posts() call and must be removed
	 * immediately after — a later, unrelated query must not pick up the COLLATE clause.
	 *
	 * @covers CampTix_Addon_Shortcodes::render_attendees_list
	 */
	public function test_attendees_shortcode_removes_collate_filter_after_query() {
		// Run the shortcode once so the filter is added and then (we expect) removed.
		$this->capture_attendees_orderby( array( 'orderby' => 'title' ) );

		// Now run an unrelated query and capture its ORDER BY — it must not be rewritten.
		$captured       = '';
		$capture_filter = function ( $orderby ) use ( &$captured ) {
			$captured = $orderby;
			return $orderby;
		};
		add_filter( 'posts_orderby', $capture_filter, 99 );
		get_posts( array(
			'post_type'        => 'post',
			'posts_per_page'   => 1,
			'orderby'          => 'title',
			'suppress_filters' => false,
		) );
		remove_filter( 'posts_orderby', $capture_filter, 99 );

		$this->assertStringNotContainsString( 'COLLATE utf8mb4_unicode_520_ci', $captured );
	}

	/**
	 * Create a page with an attendee-only section, and an ordinary page, that both match "afterparty".
	 *
	 * @return int[] The IDs of the private and the ordinary page, in that order.
	 */
	protected function create_pages_for_search() {
		$private_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_title'   => 'Livestream',
			'post_content' => 'Watch here: [camptix_private]Afterparty stream: example.org/live/1234[/camptix_private]',
		) );

		$public_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_title'   => 'Schedule',
			'post_content' => 'The afterparty starts at 7pm.',
		) );

		return array( $private_id, $public_id );
	}

	/**
	 * Run a page search as the current user.
	 *
	 * @param string $terms Search terms.
	 *
	 * @return int[] Matching page IDs.
	 */
	protected function search_pages( $terms ) {
		$query = new WP_Query( array(
			'post_type' => 'page',
			's'         => $terms,
			'fields'    => 'ids',
			'orderby'   => 'ID',
			'order'     => 'ASC',
		) );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Visitors don't find pages with attendee-only sections through search.
	 *
	 * @covers CampTix_Addon_Shortcodes::leave_private_pages_out_of_search
	 */
	public function test_search_leaves_private_pages_out_for_visitors() {
		list( $private_id, $public_id ) = $this->create_pages_for_search();

		wp_set_current_user( 0 );

		$this->assertSame( array( $public_id ), $this->search_pages( 'afterparty' ) );
		$this->assertSame( array(), $this->search_pages( 'example.org/live/1234' ) );
		$this->assertNotContains( $private_id, $this->search_pages( 'Livestream' ) );
	}

	/**
	 * Subscribers and authors are treated like visitors.
	 *
	 * @covers CampTix_Addon_Shortcodes::leave_private_pages_out_of_search
	 */
	public function test_search_leaves_private_pages_out_for_authors() {
		list( , $public_id ) = $this->create_pages_for_search();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( array( $public_id ), $this->search_pages( 'afterparty' ) );
	}

	/**
	 * Editors still find every page.
	 *
	 * @covers CampTix_Addon_Shortcodes::leave_private_pages_out_of_search
	 */
	public function test_search_includes_private_pages_for_editors() {
		list( $private_id, $public_id ) = $this->create_pages_for_search();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array( $private_id, $public_id ), $this->search_pages( 'afterparty' ) );
	}

	/**
	 * The REST API's page and site-wide search follow the same rule.
	 *
	 * @covers CampTix_Addon_Shortcodes::leave_private_pages_out_of_search
	 */
	public function test_rest_search_leaves_private_pages_out_for_visitors() {
		list( , $public_id ) = $this->create_pages_for_search();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/pages' );
		$request->set_param( 'search', 'afterparty' );
		$this->assertSame( array( $public_id ), wp_list_pluck( rest_do_request( $request )->get_data(), 'id' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', 'afterparty' );
		$this->assertSame( array( $public_id ), wp_list_pluck( rest_do_request( $request )->get_data(), 'id' ) );
	}

	/**
	 * Queries without search terms are left alone.
	 *
	 * @covers CampTix_Addon_Shortcodes::leave_private_pages_out_of_search
	 */
	public function test_listing_pages_without_search_includes_private_pages() {
		list( $private_id, $public_id ) = $this->create_pages_for_search();

		wp_set_current_user( 0 );

		$this->assertSame( array( $private_id, $public_id ), $this->search_pages( '' ) );
	}
}

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
}

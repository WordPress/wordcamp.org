<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\GatherPress_Tweaks\normalize_event_time_filter;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_GatherPress_Tweaks extends Groups_TestCase {

	/**
	 * Groups-network sites should never show a timezone suffix or offer
	 * anonymous RSVP, regardless of GatherPress's own defaults.
	 */
	public function test_gatherpress_settings_overridden() {
		$settings = get_option( 'gatherpress_settings' );

		$this->assertSame( 0, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_anonymous_rsvp'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
	}

	/**
	 * Unrelated GatherPress settings saved on a group site must survive the
	 * forced overrides: the filter overlays the forced keys, it does not
	 * replace the whole option.
	 */
	public function test_gatherpress_settings_preserved() {
		update_option(
			'gatherpress_settings',
			array(
				'max_guest_limit'  => 5,
				'enable_open_rsvp' => 1,
				'show_timezone'    => 1,
			)
		);

		$settings = get_option( 'gatherpress_settings' );

		// Unrelated stored setting is preserved.
		$this->assertSame( 5, $settings['max_guest_limit'] );

		// Forced keys win regardless of what was stored.
		$this->assertSame( 0, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
	}

	/**
	 * Login is required to comment on the groups network.
	 */
	public function test_comment_registration_required_on_groups_network() {
		$this->assertSame( '1', get_option( 'comment_registration' ) );
	}

	/**
	 * Editors ("Organizers") are granted `edit_theme_options` so they can use
	 * the Site Editor to customise their group site — but nothing broader.
	 * See the `promote_users` regression test in test-capabilities.php for
	 * the capability that must NOT be granted this way.
	 */
	public function test_editor_has_edit_theme_options() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $editor_id, 'edit_theme_options' ) );
	}

	/**
	 * The `edit_theme_options` grant is scoped to editors only.
	 */
	public function test_subscriber_does_not_have_edit_theme_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $subscriber_id, 'edit_theme_options' ) );
	}

	/**
	 * `_event_speakers` is registered on `init`, which only fires once for
	 * the whole test run; `WP_UnitTestCase::tearDown()` unregisters all meta
	 * keys after every test (a known core testing quirk), so by the time
	 * this test runs the registration from bootstrap is long gone. Re-fire
	 * the real registration function directly (rather than
	 * `do_action( 'init' )`, which would also re-run block registration and
	 * trip "already registered" `_doing_it_wrong` notices) so there's only
	 * one place the args can drift from.
	 */
	public function test_event_speakers_meta_registered_with_array_default() {
		\WordCamp\Groups\GatherPress_Tweaks\register_event_speakers_meta();

		$registered = get_registered_meta_keys( 'post', 'gatherpress_event' );

		$this->assertArrayHasKey( '_event_speakers', $registered );
		$this->assertSame( array(), $registered['_event_speakers']['default'] );
		$this->assertSame( 'array', $registered['_event_speakers']['type'] );
	}

	/**
	 * Build a `wporg/query-total` block carrying a query context.
	 *
	 * The block itself lives in the wporg design system, outside this repo,
	 * so it isn't registered here — the filter under test only reads
	 * `$block->context`, which is public and can be set directly.
	 *
	 * @param string $post_type The post type the surrounding query loop runs.
	 */
	private function make_query_total_block( string $post_type ): \WP_Block {
		$block = new \WP_Block(
			array(
				'blockName'   => 'wporg/query-total',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);

		$block->context = array( 'query' => array( 'postType' => $post_type ) );

		return $block;
	}

	/**
	 * The events archive counts events, not "items" — on a page whose only
	 * content is events, the generic label reads like placeholder copy.
	 */
	public function test_query_total_label_counts_events_on_event_queries() {
		$block = $this->make_query_total_block( 'gatherpress_event' );

		$this->assertSame(
			'%s event',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
		$this->assertSame(
			'%s events',
			apply_filters( 'wporg_query_total_label', '%s items', 12, $block )
		);
	}

	/**
	 * Every other query loop keeps the design system's own label.
	 */
	public function test_query_total_label_is_untouched_on_other_queries() {
		$block = $this->make_query_total_block( 'post' );

		$this->assertSame(
			'%s item',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
	}

	/**
	 * Read the archive's Time filter options as the query-filter block would.
	 *
	 * @param string|null $event_time The `event_time` query arg to simulate.
	 */
	private function get_event_time_filter( ?string $event_time ): array {
		if ( null === $event_time ) {
			unset( $_GET['event_time'] );
		} else {
			$_GET['event_time'] = $event_time;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_time', array() );

		unset( $_GET['event_time'] );

		return $filter;
	}

	/**
	 * Single-select filters get no count badge from the wporg block, so the
	 * toggle itself has to say which view is applied.
	 */
	public function test_event_time_filter_names_the_applied_choice() {
		$filter = $this->get_event_time_filter( 'past' );

		$this->assertSame( 'Time: Past', $filter['label'] );
		$this->assertSame( array( 'past' ), $filter['selected'] );
	}

	/**
	 * "Upcoming" is the default view, so the toggle stays unannotated — but its
	 * radio remains selected so the filter exposes the view currently on screen.
	 */
	public function test_event_time_filter_marks_upcoming_selected_on_the_default_view() {
		$filter = $this->get_event_time_filter( null );

		$this->assertSame( 'Time', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * A hand-typed `event_time` that isn't one of the three views falls back
	 * to the default rather than naming itself in the toggle.
	 */
	public function test_event_time_filter_ignores_an_unknown_value() {
		$filter = $this->get_event_time_filter( 'whenever' );

		$this->assertSame( 'Time', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * Venues are metadata on events, not their own front-end destination —
	 * confirm the post type stays non-public even though GatherPress itself
	 * registers it.
	 */
	public function test_gatherpress_venue_post_type_is_non_public() {
		$post_type_object = get_post_type_object( 'gatherpress_venue' );

		$this->assertNotNull( $post_type_object, 'GatherPress must be active for this assertion to be meaningful.' );
		$this->assertFalse( $post_type_object->public );
		$this->assertFalse( $post_type_object->publicly_queryable );
		$this->assertFalse( $post_type_object->has_archive );
	}

	/**
	 * Search block on the events archive rewrites form action, removes required,
	 * adds the event_time hidden input, and marks the form for events search clear.
	 */
	public function test_search_block_removes_required_and_handles_clearing() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$archive_url = get_post_type_archive_link( 'gatherpress_event' );
		$this->assertStringContainsString( 'action="' . esc_url( $archive_url ) . '"', $output );
		$this->assertStringContainsString( 'data-events-search-form="1"', $output );
		$this->assertStringNotContainsString( 'required', $output );
		$this->assertStringContainsString( '<input type="hidden" name="event_time" value="all" />', $output );
		$this->assertStringNotContainsString( '<script', $output );

		$processor = new \WP_HTML_Tag_Processor( $output );
		$this->assertTrue( $processor->next_tag( 'form' ) );
		$this->assertSame( '1', $processor->get_attribute( 'data-events-search-form' ) );
		$this->assertTrue( $processor->next_tag( 'input' ) );
		$this->assertNull( $processor->get_attribute( 'required' ) );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * Search block on other pages remains untouched.
	 */
	public function test_search_block_untouched_outside_event_archive() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = false;

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$this->assertSame( $input_html, $output );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * Empty search query parameter reverts the default all-time view back to upcoming.
	 */
	public function test_event_time_filter_reverts_to_upcoming_on_empty_search() {
		$_GET['s'] = '';
		$filter    = $this->get_event_time_filter( 'all' );
		unset( $_GET['s'] );

		$this->assertSame( 'Time', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * Normalizing event time filter reverts 'all' to 'upcoming' on empty search query.
	 */
	public function test_normalize_event_time_filter() {
		// When search query is empty string or whitespace and time is 'all'.
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all', '' ) );
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all', '   ' ) );

		// When search query has text, 'all' is preserved.
		$this->assertSame( 'all', normalize_event_time_filter( 'all', 'wordcamp' ) );

		// When search is not set (null), 'all' is preserved.
		unset( $_GET['s'] );
		$this->assertSame( 'all', normalize_event_time_filter( 'all' ) );

		// When reading from $_GET['s'].
		$_GET['s'] = '';
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all' ) );
		$_GET['s'] = 'community';
		$this->assertSame( 'all', normalize_event_time_filter( 'all' ) );
		unset( $_GET['s'] );

		// When time is not 'all', it is preserved.
		$this->assertSame( 'past', normalize_event_time_filter( 'past', '' ) );
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'upcoming', '' ) );
	}
}

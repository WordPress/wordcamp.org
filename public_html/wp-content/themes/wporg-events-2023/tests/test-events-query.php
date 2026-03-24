<?php

namespace WordPressdotorg\Events_2023\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group wporg-events-2023
 *
 * @coversDefaultClass \WordPressdotorg\Events_2023
 */
class Test_Events_Query extends WP_UnitTestCase {

	/**
	 * Sample search form HTML to use in tests.
	 *
	 * @var string
	 */
	private $sample_form = '<form role="search" method="get" action="http://example.org/" class="wp-block-search__button-outside"><label class="wp-block-search__label" for="wp-block-search__input-1">Search</label><div class="wp-block-search__inside-wrapper"><input class="wp-block-search__input" id="wp-block-search__input-1" placeholder="" value="" name="s" /><button aria-label="Search" class="wp-block-search__button" type="submit">Search</button></div></form>';

	/**
	 * Clean up query vars after each test.
	 */
	public function tear_down(): void {
		global $wp_query;

		set_query_var( 'event_type', '' );
		set_query_var( 'format_type', '' );
		set_query_var( 'month', '' );
		set_query_var( 'country', '' );

		parent::tear_down();
	}

	/**
	 * Test that hidden inputs are injected when active filters exist.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_injects_hidden_inputs_for_active_filters(): void {
		set_query_var( 'event_type', array( 'meetup' ) );
		set_query_var( 'country', array( 'US' ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringContainsString(
			'<input type="hidden" name="event_type[]" value="meetup" />',
			$result
		);
		$this->assertStringContainsString(
			'<input type="hidden" name="country[]" value="US" />',
			$result
		);
	}

	/**
	 * Test that multiple values for a single filter produce multiple hidden inputs.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_injects_multiple_values_for_same_filter(): void {
		set_query_var( 'event_type', array( 'meetup', 'wordcamp' ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringContainsString(
			'<input type="hidden" name="event_type[]" value="meetup" />',
			$result
		);
		$this->assertStringContainsString(
			'<input type="hidden" name="event_type[]" value="wordcamp" />',
			$result
		);
	}

	/**
	 * Test that hidden inputs are placed before the closing form tag.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_hidden_inputs_placed_before_closing_form_tag(): void {
		set_query_var( 'event_type', array( 'meetup' ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringContainsString(
			'value="meetup" /></form>',
			$result
		);
	}

	/**
	 * Test that form HTML has no hidden inputs when no filters are active.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_no_hidden_inputs_when_no_filters_active(): void {
		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringNotContainsString( '<input type="hidden"', $result );
	}

	/**
	 * Test that the form action URL is updated.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_form_action_url_is_updated(): void {
		$post_id = self::factory()->post->create( array(
			'post_title'  => 'Upcoming Events',
			'post_status' => 'publish',
		) );

		$this->go_to( get_permalink( $post_id ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		// The action should no longer be the original example.org URL.
		$this->assertStringNotContainsString( 'action="http://example.org/"', $result );
		// It should contain an action attribute with a URL pointing to the post.
		$this->assertMatchesRegularExpression( '/action="https?:\/\/[^"]+?"/', $result );
	}

	/**
	 * Test that empty filter values are not injected.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_empty_filter_values_are_not_injected(): void {
		set_query_var( 'event_type', array( '' ) );
		set_query_var( 'month', array( '' ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringNotContainsString( '<input type="hidden"', $result );
	}

	/**
	 * Test that filter values are properly escaped in HTML output.
	 *
	 * @covers ::inject_filters_into_search_form
	 */
	public function test_filter_values_are_escaped(): void {
		set_query_var( 'event_type', array( '<script>alert(1)</script>' ) );

		$result = \WordPressdotorg\Events_2023\inject_filters_into_search_form( $this->sample_form );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}
}

<?php

namespace WordCamp\Tests;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/hooks/latest-posts/controller.php';

/**
 * Tests for the Latest Posts hooks: the block-renderer safelist and the
 * container rewrite that adds the live-update markup.
 *
 * The block polls the renderer route from the front end with no session, so the
 * safelist re-runs the route callback after core's permission check denied it.
 * That filter fires either way, so the re-run stays pinned to the one request
 * shape it exists for.
 *
 * @group blocks
 */
class Test_Latest_Posts_Block extends WP_UnitTestCase {
	/**
	 * A post no anonymous visitor should be able to read.
	 *
	 * @var int
	 */
	protected $draft_id;

	/**
	 * Whether this test added the attribute the hook registers at `init`.
	 *
	 * @var bool
	 */
	protected $added_live_update_attribute = false;

	/**
	 * Boot a REST server so `rest_do_request()` runs the real dispatch.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( 0 );

		$this->register_live_update_attribute();

		$this->draft_id = self::factory()->post->create( array(
			'post_status'  => 'draft',
			'post_title'   => 'Draft title',
			'post_content' => 'Draft body',
		) );

		self::factory()->post->create( array(
			'post_status' => 'publish',
			'post_title'  => 'Published title',
		) );
	}

	/**
	 * Reset the REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		if ( $this->added_live_update_attribute ) {
			$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/latest-posts' );

			if ( $block_type ) {
				unset( $block_type->attributes['liveUpdateEnabled'] );
			}

			$this->added_live_update_attribute = false;
		}

		parent::tear_down();
	}

	/**
	 * Give the block the attribute the hook adds at `init`.
	 *
	 * This suite loads `controller.php` directly, long after `init` has fired, so the
	 * hook's own registration never runs. Without it the block's schema is missing the
	 * attribute the front end sends, and the renderer route rejects it.
	 */
	protected function register_live_update_attribute() {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/latest-posts' );

		if ( ! $block_type || isset( $block_type->attributes['liveUpdateEnabled'] ) ) {
			return;
		}

		$block_type->attributes['liveUpdateEnabled'] = array(
			'type'    => 'boolean',
			'default' => false,
		);

		$this->added_live_update_attribute = true;
	}

	/**
	 * Dispatch an anonymous GET against the block renderer.
	 *
	 * @param string $route_block Block name in the route path.
	 * @param array  $params      Query parameters.
	 * @return \WP_REST_Response
	 */
	protected function render( $route_block, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $route_block );
		$request->set_url_params( array( 'name' => $route_block ) );
		$request->set_query_params( array_merge( array( 'context' => 'edit' ), $params ) );

		return rest_do_request( $request );
	}

	/**
	 * The block the safelist exists for still renders for anonymous visitors.
	 */
	public function test_anonymous_can_render_latest_posts() {
		$response = $this->render( 'core/latest-posts', array( 'attributes' => array( 'postsToShow' => 5 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Published title', $response->get_data()['rendered'] );
	}

	/**
	 * Only published posts are listed, so the safelist cannot widen the block itself.
	 */
	public function test_rendered_latest_posts_excludes_drafts() {
		$response = $this->render( 'core/latest-posts', array( 'attributes' => array( 'postsToShow' => 5 ) ) );

		$this->assertStringNotContainsString( 'Draft title', $response->get_data()['rendered'] );
	}

	/**
	 * `name` in the query string outranks the route path, so the safelisted path
	 * must not carry another block's render.
	 */
	public function test_query_string_block_name_is_not_safelisted() {
		foreach ( array( 'core/post-content', 'core/post-title', 'core/post-excerpt' ) as $block ) {
			$response = $this->render(
				'core/latest-posts',
				array(
					'name'    => $block,
					'post_id' => $this->draft_id,
				)
			);

			$this->assertSame( 401, $response->get_status(), $block . ' should not have rendered.' );
		}
	}

	/**
	 * The `name` guard has to stand on its own, without `post_id` backing it up.
	 */
	public function test_query_string_block_name_alone_is_not_safelisted() {
		$response = $this->render( 'core/latest-posts', array( 'name' => 'core/post-content' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A post-scoped request is what core gates on `edit_post`, and this block never
	 * needs one.
	 */
	public function test_post_scoped_request_is_not_safelisted() {
		$response = $this->render(
			'core/latest-posts',
			array(
				'name'    => 'core/latest-posts',
				'post_id' => $this->draft_id,
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * The route renders every post it is asked for, and `postsToShow` has no maximum
	 * in the block's schema, so an oversized request is not the live block polling.
	 */
	public function test_oversized_posts_to_show_is_not_safelisted() {
		$response = $this->render( 'core/latest-posts', array( 'attributes' => array( 'postsToShow' => 500 ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * The bound is inclusive, so a block sitting on it still polls.
	 */
	public function test_posts_to_show_at_the_bound_is_safelisted() {
		$response = $this->render( 'core/latest-posts', array( 'attributes' => array( 'postsToShow' => 100 ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * An objection other than core's authorization error keeps its response, so a
	 * Coming Soon lockdown is not discarded along with it.
	 */
	public function test_other_errors_are_not_discarded() {
		$lockdown = function () {
			return new WP_Error( 'rest_cannot_access', 'Locked down.', array( 'status' => 403 ) );
		};

		add_filter( 'rest_request_before_callbacks', $lockdown, 99 );
		$response = $this->render( 'core/latest-posts', array( 'attributes' => array( 'postsToShow' => 5 ) ) );
		remove_filter( 'rest_request_before_callbacks', $lockdown, 99 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_access', $response->get_data()['code'] );
	}

	/**
	 * Run the `render_block` hook over one container's markup.
	 *
	 * @param string $content Rendered block markup.
	 * @param array  $attrs   Block attributes.
	 * @return string
	 */
	protected function render_container( $content, array $attrs = array( 'liveUpdateEnabled' => true ) ) {
		return \WordCamp\Blocks\Hooks\Latest_Posts\render(
			$content,
			array(
				'blockName' => 'core/latest-posts',
				'attrs'     => $attrs,
			)
		);
	}

	/**
	 * Markup core escaped correctly, carrying the rewrite's needles in a value.
	 *
	 * `esc_attr()` escapes `& < > " '`, and neither needle contains any of them, so
	 * both reach the page intact.
	 *
	 * @return string
	 */
	protected function markup_with_needles_in_values() {
		return '<ul class="wp-block-latest-posts__list wp-block-latest-posts is-layout-flow">'
			. '<li><a href="https://example.org/p/" aria-label="ul class=t rel=next t2">'
			. '<img src="https://example.org/i.png" alt="ul class=x rel=next z" /></a></li>'
			. '</ul>';
	}

	/**
	 * The rewrite must not reach into a value: the string it writes carries a quote
	 * that would close the attribute and leave the rest to be parsed as markup.
	 */
	public function test_attribute_values_are_not_rewritten() {
		$output = $this->render_container( $this->markup_with_needles_in_values() );

		$this->assertStringContainsString( 'alt="ul class=x rel=next z"', $output );
		$this->assertStringContainsString( 'aria-label="ul class=t rel=next t2"', $output );
		$this->assertStringNotContainsString( 'alt="div data-attributes=', $output );
		$this->assertStringNotContainsString( 'aria-label="div data-attributes=', $output );
	}

	/**
	 * The class rewrite is the same shape, and must not reach into a value either.
	 */
	public function test_attribute_values_do_not_gain_container_classes() {
		$output = $this->render_container(
			'<ul class="wp-block-latest-posts__list wp-block-latest-posts is-layout-flow">'
			. '<li><img src="https://example.org/i.png" alt="wp-block-latest-posts trailing" /></li></ul>'
		);

		$this->assertStringContainsString( 'alt="wp-block-latest-posts trailing"', $output );
	}

	/**
	 * The live-update markup the front-end script keys on still gets written.
	 *
	 * `front-end.js` matches `.wp-block-latest-posts.has-live-update` and reads
	 * `data-attributes` off it. The element itself is left as core rendered it.
	 */
	public function test_container_still_gets_live_update_markup() {
		$output = $this->render_container( $this->markup_with_needles_in_values() );

		$this->assertStringStartsWith( '<ul ', $output );
		$this->assertStringEndsWith( '</ul>', $output );
		$this->assertStringContainsString( 'has-live-update', $output );
		$this->assertStringContainsString( 'is-loading', $output );
		$this->assertStringContainsString(
			'data-attributes="' . rawurlencode( wp_json_encode( array( 'liveUpdateEnabled' => true ) ) ) . '"',
			$output
		);
	}

	/**
	 * Attributes the renderer route would reject stay out of `data-attributes`.
	 *
	 * A block saved years ago can carry keys core has since dropped from the schema,
	 * `postLayout` and `columns` among them. The render path still honours those and
	 * they only feed container classes, which the container on the page keeps, but the
	 * route validates against the current schema and refuses the whole request. The
	 * fixture uses a name core will never register, so the test covers the mechanism
	 * rather than whichever keys core happens to declare today.
	 */
	public function test_unregistered_attributes_are_not_polled() {
		$output = $this->render_container(
			'<ul class="wp-block-latest-posts is-grid"><li>a</li></ul>',
			array(
				'postsToShow'         => 3,
				'wcorgRetiredSetting' => 'grid',
				'liveUpdateEnabled'   => true,
			)
		);

		$this->assertSame( 1, preg_match( '/data-attributes="([^"]*)"/', $output, $matches ), 'the container carries no data-attributes.' );
		$polled = json_decode( rawurldecode( $matches[1] ), true );

		$this->assertArrayNotHasKey( 'wcorgRetiredSetting', $polled );
		$this->assertSame( 3, $polled['postsToShow'] );
		$this->assertTrue( $polled['liveUpdateEnabled'] );
	}

	/**
	 * The attributes the container carries are ones the renderer route accepts.
	 *
	 * This is the loop the rewrite exists for. The front end reads `data-attributes`
	 * off the container and sends it straight back to the route, so if the route
	 * refuses them the block renders once and never updates again.
	 */
	public function test_polled_attributes_are_accepted_by_the_route() {
		$markup = $this->render_container(
			'<ul class="wp-block-latest-posts"><li>a</li></ul>',
			array(
				'postsToShow'         => 2,
				'wcorgRetiredSetting' => 'grid',
				'liveUpdateEnabled'   => true,
			)
		);

		$this->assertSame( 1, preg_match( '/data-attributes="([^"]*)"/', $markup, $matches ), 'the container carries no data-attributes.' );
		$attributes = json_decode( rawurldecode( $matches[1] ), true );

		$response = $this->render( 'core/latest-posts', array( 'attributes' => $attributes ) );

		$this->assertSame( 200, $response->get_status(), 'the route refused the attributes the container carries.' );
	}

	/**
	 * Without the toggle the hook is a no-op, so the markup is core's own.
	 */
	public function test_markup_is_untouched_without_the_toggle() {
		$markup = $this->markup_with_needles_in_values();

		$this->assertSame( $markup, $this->render_container( $markup, array() ) );
	}

	/**
	 * Markup around the container no longer matters.
	 *
	 * Nothing is renamed any more, so the classes go on the block's own element and
	 * whatever sits beside it is irrelevant. These shapes used to opt out of live
	 * update entirely, and silently.
	 */
	public function test_markup_around_the_container_still_gets_marked() {
		$cases = array(
			'leading'  => '<!-- x --><ul class="wp-block-latest-posts"><li>a</li></ul>',
			'trailing' => '<ul class="wp-block-latest-posts"><li>a</li></ul><style>.x{}</style>',
			'siblings' => '<ul class="wp-block-latest-posts"><li>a</li></ul><ul class="other"><li>b</li></ul>',
		);

		foreach ( $cases as $label => $markup ) {
			$output = $this->render_container( $markup );

			$this->assertStringContainsString( 'has-live-update', $output, $label . ': container was not marked.' );
			$this->assertStringContainsString( 'data-attributes=', $output, $label . ': no attributes were written.' );
		}
	}

	/**
	 * A list nested inside the container is left where it is.
	 */
	public function test_nested_list_is_untouched() {
		$output = $this->render_container(
			'<ul class="wp-block-latest-posts"><li>a<ul><li>b</li></ul></li></ul>'
		);

		$this->assertStringStartsWith( '<ul ', $output );
		$this->assertStringEndsWith( '</ul>', $output );
		$this->assertStringContainsString( 'has-live-update', $output );
		$this->assertSame( 2, substr_count( $output, '<ul' ), 'the nested list should survive' );
		$this->assertSame( 2, substr_count( $output, '</ul>' ) );
	}
}

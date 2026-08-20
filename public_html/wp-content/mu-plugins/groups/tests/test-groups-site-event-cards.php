<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Regression coverage for the `groups-site` theme's shared event card.
 *
 * The front page and the events archive draw the same card, defined once in
 * `patterns/event-card.php` and pulled into both `core/post-template`s with a
 * `wp:pattern` reference. That indirection costs something core can't see
 * through: `render_block_core_post_template()` scans its *parsed* inner
 * blocks for a literal `core/post-featured-image`
 * (`block_core_post_template_uses_featured_image()`) and only then calls
 * `update_post_thumbnail_cache()`. A lone `core/pattern` — which core expands
 * at render time — fails that scan, so each card would resolve its own
 * thumbnail: one `get_post()` for the attachment plus one
 * `wp_get_attachment_metadata()` read, up to twelve cards per archive page.
 * `WordCamp\Groups\Site\prime_event_card_thumbnails()` puts the batched fetch
 * back on `loop_start`, and the query-count test below is what keeps it there.
 *
 * The rest of the class covers the card itself, which — like the patterns
 * this file's predecessor tested (see the compat-test skill's section 9) —
 * has no unit test of its own beyond the pages that render it.
 *
 * @group groups
 */
class Test_Groups_Site_Event_Cards extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * A card grid shaped exactly the way both templates shape theirs: a
	 * `core/post-template` whose only inner block is the shared pattern.
	 *
	 * The query itself is deliberately plainer than the archive's — no
	 * `gatherpress_event_query`, so GatherPress's upcoming/past SQL stays out
	 * of a test that is about thumbnails, and the loop returns whichever
	 * events the test created.
	 */
	const GRID = '<!-- wp:query {"query":{"postType":"gatherpress_event","perPage":12,"offset":0,"inherit":false},"className":"gatherpress-event-query"} -->
		<div class="wp-block-query gatherpress-event-query">
			<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
				<!-- wp:pattern {"slug":"groups-site/event-card"} /-->
			<!-- /wp:post-template -->
		</div>
		<!-- /wp:query -->';

	/**
	 * The templates whose card grids share the pattern.
	 */
	const CARD_GRID_TEMPLATES = array( 'front-page.html', 'archive-gatherpress_event.html' );

	/**
	 * Load the theme's hooks and register its card pattern.
	 *
	 * `groups-site` isn't the active theme in this suite, so neither its
	 * `functions.php` nor its `patterns/` directory is picked up on its own.
	 * Both are what's under test here: the pattern draws the card, and
	 * `functions.php` supplies the thumbnail priming and the placeholder that
	 * stands in for a missing image.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';

		ob_start();
		include self::THEME_DIR . 'patterns/event-card.php';
		$card_content = ob_get_clean();

		register_block_pattern(
			'groups-site/event-card',
			array(
				'title'   => 'Event card',
				'content' => $card_content,
			)
		);
	}

	/**
	 * Drops the pattern registered above so it can't leak into other classes.
	 */
	public static function wpTearDownAfterClass() {
		unregister_block_pattern( 'groups-site/event-card' );

		parent::wpTearDownAfterClass();
	}

	/**
	 * Re-add the theme's hooks for every test.
	 *
	 * `WP_UnitTestCase` snapshots the hook table once per process and
	 * restores that snapshot after every test, so anything a class fixture
	 * registers only survives the fixture's first test. `add_filter()` is a
	 * no-op for a callback already at the same priority, so re-adding here
	 * is safe either way.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter(
			'render_block_core/post-featured-image',
			'WordCamp\\Groups\\Site\\filter_event_card_featured_image',
			10,
			2
		);
		add_action( 'loop_start', 'WordCamp\\Groups\\Site\\prime_event_card_thumbnails' );
	}

	/**
	 * Create a published event, optionally with a featured image.
	 *
	 * @param string $title      The event title.
	 * @param bool   $with_image Whether to attach a featured image.
	 *
	 * @return int The attachment ID, or 0 when the event has no image.
	 */
	private function create_event( string $title, bool $with_image ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_content' => 'An evening of talks, demos and questions about the web.',
			)
		);

		if ( ! $with_image ) {
			return 0;
		}

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'event.jpg',
				'post_parent'    => $event_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title . ' poster',
			)
		);

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1200,
				'height' => 675,
				'file'   => 'event.jpg',
				'sizes'  => array(),
			)
		);

		set_post_thumbnail( $event_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Every card image comes out of one batched fetch, not one lookup per
	 * card. Without the priming on `loop_start`, each card runs its own
	 * `SELECT ... WHERE ID = <attachment>` (plus a meta read behind it), so
	 * a full archive page of twelve cards adds two dozen queries.
	 */
	public function test_card_grid_does_not_look_up_thumbnails_card_by_card() {
		$attachment_ids = array();

		for ( $i = 1; $i <= 6; $i++ ) {
			$attachment_ids[] = $this->create_event( "Event {$i}", true );
		}

		// Creating the attachments left them all in the object cache, which
		// would hide the very lookups this test is counting.
		foreach ( $attachment_ids as $attachment_id ) {
			clean_post_cache( $attachment_id );
		}

		$statements = array();
		$logger     = static function ( $sql ) use ( &$statements ) {
			$statements[] = $sql;

			return $sql;
		};

		add_filter( 'query', $logger );
		$output = do_blocks( self::GRID );
		remove_filter( 'query', $logger );

		$this->assertSame(
			count( $attachment_ids ),
			substr_count( $output, 'wp-post-image' ),
			'Every card should have rendered its featured image.'
		);

		$looked_up_individually = array();

		foreach ( $attachment_ids as $attachment_id ) {
			foreach ( $statements as $sql ) {
				if ( preg_match( '/\bID\s*=\s*' . $attachment_id . '\b/', $sql ) ) {
					$looked_up_individually[] = $attachment_id;
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$looked_up_individually,
			'Card thumbnails were fetched one at a time; the loop_start priming is no longer reaching this query.'
		);
	}

	/**
	 * The card renders the event's featured image, with the image link kept
	 * out of the tab order — the stretched title link already opens the card.
	 */
	public function test_card_renders_the_featured_image() {
		$this->create_event( 'Winter Meetup', true );

		$output = do_blocks( self::GRID );

		$this->assertStringContainsString( 'Winter Meetup', $output );
		$this->assertStringContainsString( 'wp-post-image', $output );
		$this->assertStringContainsString( '<a tabindex="-1" href=', $output );
		$this->assertStringNotContainsString( 'groups-site-featured-placeholder', $output );
	}

	/**
	 * An event with no image keeps the same 16:9 region so its neighbours in
	 * the grid row don't start their titles at a different height. Core
	 * renders the featured-image block as an empty string in that case, so
	 * the theme substitutes a decorative placeholder — flat blueberry-4 in
	 * the stylesheet, per docs/design/groups-site.md.
	 */
	public function test_card_falls_back_to_the_placeholder_without_an_image() {
		$this->create_event( 'Imageless Meetup', false );

		$output = do_blocks( self::GRID );

		$this->assertStringContainsString( 'Imageless Meetup', $output );
		$this->assertStringContainsString( 'groups-site-featured-placeholder', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		$this->assertStringNotContainsString( 'wp-post-image', $output );
	}

	/**
	 * Both grids draw the one shared card. This is also the condition that
	 * makes the priming above necessary: core can't see a featured image
	 * through `core/pattern`, so a post-template holding nothing else has no
	 * thumbnail cache of its own.
	 */
	public function test_both_card_grids_reference_the_shared_pattern() {
		foreach ( self::CARD_GRID_TEMPLATES as $template ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as test-groups-site-event-info-card.php does.
			$markup = file_get_contents( self::THEME_DIR . 'templates/' . $template );

			$this->assertNotFalse( $markup, "Could not read {$template}." );

			$post_template = $this->find_block( parse_blocks( $markup ), 'core/post-template' );

			$this->assertNotNull( $post_template, "{$template} no longer holds a core/post-template." );

			$inner_names = wp_list_pluck( $post_template['innerBlocks'], 'blockName' );

			$this->assertSame(
				array( 'core/pattern' ),
				$inner_names,
				"{$template}'s card is no longer the shared pattern alone."
			);
			$this->assertSame(
				'groups-site/event-card',
				$post_template['innerBlocks'][0]['attrs']['slug'],
				"{$template} references a different card pattern."
			);
		}
	}

	/**
	 * Find the first block of a given name in a parsed block tree.
	 *
	 * @param array  $blocks Parsed blocks to walk.
	 * @param string $name   The block name to look for.
	 *
	 * @return array|null The block, or null when it isn't present.
	 */
	private function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === $block['blockName'] ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->find_block( $block['innerBlocks'], $name );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}

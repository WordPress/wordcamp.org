<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * What keeps a group site's pages light, from a Lighthouse pass.
 *
 * @group groups
 */
class Test_Groups_Site_Page_Weight extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	const TAGLINE = 'Monthly meetups for people who build with WordPress';

	/**
	 * Load the theme's hooks: it isn't the active theme in this suite.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * Put back what a test enqueued.
	 */
	protected function tearDown(): void {
		wp_dequeue_script_module( 'groups-site-venue-map' );
		wp_deregister_script_module( 'groups-site-venue-map' );

		parent::tearDown();
	}

	/**
	 * Create a published event.
	 */
	private function create_event( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'gatherpress_event',
					'post_status'  => 'publish',
					'post_title'   => 'Winter Meetup',
					'post_content' => '<!-- wp:paragraph --><p>An evening of talks, demos and questions about the web.</p><!-- /wp:paragraph -->',
					// The factory writes an excerpt of its own otherwise.
					'post_excerpt' => '',
				),
				$args
			)
		);
	}

	/**
	 * Make an event the page being viewed.
	 */
	private function view_event( int $event_id ): void {
		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
	}

	/**
	 * Render a new event's featured image in a block with this class, stopped on its `<img>`.
	 */
	private function render_featured_image( string $class_name ): \WP_HTML_Tag_Processor {
		$event_id      = $this->create_event();
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'event.jpg',
				'post_parent'    => $event_id,
				'post_mime_type' => 'image/jpeg',
			)
		);

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1200,
				'height' => 675,
				'file'   => 'event.jpg',
				'sizes'  => array(
					// Core only prints `srcset` and `sizes` with a second size to offer.
					'medium' => array(
						'file'      => 'event-300x169.jpg',
						'width'     => 300,
						'height'    => 169,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		set_post_thumbnail( $event_id, $attachment_id );

		// `WP_UnitTestCase` drops the hooks `functions.php` added after each test.
		add_filter( 'wp_calculate_image_sizes', 'WordCamp\\Groups\\Site\\hero_image_sizes' );

		$block = new \WP_Block(
			parse_blocks( sprintf( '<!-- wp:post-featured-image {"aspectRatio":"16/9","className":"%s"} /-->', $class_name ) )[0],
			array(
				'postId'   => $event_id,
				'postType' => 'gatherpress_event',
			)
		);

		$processor = new \WP_HTML_Tag_Processor( $block->render() );

		$this->assertTrue( $processor->next_tag( 'img' ), 'The block rendered no image.' );

		return $processor;
	}

	/**
	 * Render GatherPress's own map block for a new venue, stopped on its wrapper.
	 */
	private function render_venue_map( array $attrs = array() ): \WP_HTML_Tag_Processor {
		$venue_id = self::factory()->post->create( array( 'post_type' => 'gatherpress_venue' ) );

		update_post_meta( $venue_id, 'gatherpress_address', '253 David Low Way, Peregian Beach QLD 4573' );
		update_post_meta( $venue_id, 'gatherpress_latitude', '-26.4806' );
		update_post_meta( $venue_id, 'gatherpress_longitude', '153.0963' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The block reads its venue from the current post.
		$GLOBALS['post'] = get_post( $venue_id );

		add_filter( 'render_block_gatherpress/venue-map', 'WordCamp\\Groups\\Site\\defer_venue_map' );

		// `groups-site` isn't the suite's active theme.
		add_filter(
			'theme_file_path',
			static function ( $path, $file ) {
				return self::THEME_DIR . $file;
			},
			10,
			2
		);

		$block     = parse_blocks( sprintf( '<!-- wp:gatherpress/venue-map %s /-->', wp_json_encode( (object) $attrs ) ) )[0];
		$processor = new \WP_HTML_Tag_Processor( render_block( $block ) );

		$this->assertTrue( $processor->next_tag(), 'GatherPress rendered no map.' );

		return $processor;
	}

	/**
	 * A hero image gets the `sizes` measured for its column. The flyer draws
	 * the same image at another size, and keeps core's.
	 */
	public function test_only_hero_images_get_the_hero_sizes() {
		foreach ( \WordCamp\Groups\Site\HERO_IMAGE_SIZES as $class_name => $sizes ) {
			// Outside the main loop the image is lazy, so core prefixes `auto`.
			$this->assertSame( 'auto, ' . $sizes, $this->render_featured_image( $class_name )->get_attribute( 'sizes' ), $class_name );
		}

		$flyer = $this->render_featured_image( 'groups-site-flyer__image' );

		$this->assertStringEndsWith( '(max-width: 1200px) 100vw, 1200px', $flyer->get_attribute( 'sizes' ) );
	}

	/**
	 * GatherPress's interactive map hands its start-up to the theme's script.
	 * Its static map has nothing to start. Fails if GatherPress renames the
	 * callback its block starts from.
	 */
	public function test_interactive_venue_map_is_deferred() {
		$static = $this->render_venue_map( array( 'renderMode' => 'static' ) );

		$this->assertNull( $static->get_attribute( 'data-wp-init' ) );
		$this->assertNotContains( 'groups-site-venue-map', wp_script_modules()->get_queue() );

		$interactive = $this->render_venue_map();

		$this->assertSame( 'groups-site/venue-map::callbacks.init', $interactive->get_attribute( 'data-wp-init' ) );
		$this->assertSame( 'gatherpress/venue-map', $interactive->get_attribute( 'data-wp-interactive' ) );
		$this->assertContains( 'groups-site-venue-map', wp_script_modules()->get_queue() );
	}

	/**
	 * An event's page carries a description in the event's own words.
	 *
	 * @dataProvider provide_event_descriptions
	 */
	public function test_event_is_described_in_its_own_words( array $post_fields, string $expected ) {
		$this->view_event( $this->create_event( $post_fields ) );

		$this->assertSame(
			'<meta name="description" content="' . $expected . '" />' . "\n",
			get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' )
		);
	}

	/**
	 * Events, and the description each should get.
	 */
	public function provide_event_descriptions(): array {
		return array(
			'its content'                      => array(
				array(),
				'An evening of talks, demos and questions about the web.',
			),
			'its excerpt, with quotes escaped' => array(
				array( 'post_excerpt' => 'A "show and tell" night.' ),
				'A &quot;show and tell&quot; night.',
			),
			'a tag written out as text'        => array(
				array( 'post_content' => '<!-- wp:paragraph --><p>Learn how the <code>&lt;button&gt;</code> element works.</p><!-- /wp:paragraph -->' ),
				'Learn how the &lt;button&gt; element works.',
			),
			'no more than thirty words'        => array(
				array( 'post_content' => implode( ' ', array_fill( 0, 40, 'word' ) ) ),
				implode( ' ', array_fill( 0, 30, 'word' ) ) . '&hellip;',
			),
		);
	}

	/**
	 * A protected event's words aren't public.
	 */
	public function test_protected_event_is_described_by_the_group() {
		update_option( 'blogdescription', self::TAGLINE );

		$this->view_event(
			$this->create_event(
				array(
					'post_password' => 'secret',
					'post_excerpt'  => 'The venue is the back room.',
				)
			)
		);

		$this->assertSame( self::TAGLINE, \WordCamp\Groups\Site\get_meta_description() );
	}

	/**
	 * Anything that isn't a single post gets the tagline.
	 */
	public function test_front_page_is_described_by_the_tagline() {
		update_option( 'blogdescription', self::TAGLINE );
		$this->create_event();

		$this->go_to( home_url( '/' ) );

		$this->assertSame( self::TAGLINE, \WordCamp\Groups\Site\get_meta_description() );
	}
}

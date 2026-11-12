<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Coverage for what keeps a group site's pages light.
 *
 * Each of these came out of a Lighthouse pass over a live group: a hero
 * image downloaded at several times its size, a map library loaded for a map
 * below the fold, sharing styles on a page with nothing to share, and no
 * description for search engines. All but the image size live in the
 * `groups-site` theme.
 *
 * @group groups
 */
class Test_Groups_Site_Page_Weight extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * The tagline of the group these tests describe.
	 */
	const TAGLINE = 'Monthly meetups for people who build with WordPress';

	/**
	 * Load the theme's hooks. `groups-site` isn't the active theme in this
	 * suite, so its `functions.php` isn't picked up on its own.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
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
	 *
	 * @param array $args Post fields that differ from the defaults.
	 *
	 * @return int The event's post ID.
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
	 *
	 * @param int $event_id The event's post ID.
	 */
	private function view_event( int $event_id ): void {
		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
	}

	/**
	 * Render the featured image of a new event, in a block carrying a class,
	 * and return its `<img>`.
	 *
	 * @param string $class_name The class the template gives the block.
	 *
	 * @return \WP_HTML_Tag_Processor A processor stopped on the image.
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
	 * The classes on every featured-image block in one of the theme's templates.
	 *
	 * @param string $template The template's file name.
	 *
	 * @return string[] One `className` per featured-image block.
	 */
	private function featured_image_classes( string $template ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk.
		$blocks  = new \WP_Block_Processor( file_get_contents( self::THEME_DIR . 'templates/' . $template ) );
		$classes = array();

		while ( $blocks->next_block( 'core/post-featured-image' ) ) {
			$classes[] = $blocks->allocate_and_return_parsed_attributes()['className'] ?? '';
		}

		return $classes;
	}

	/**
	 * A hero image tells the browser the width of the column it sits in.
	 * Core's default says the viewport, and a desktop browser then downloads
	 * a file several times too wide.
	 */
	public function test_hero_images_describe_their_columns() {
		foreach ( \WordCamp\Groups\Site\HERO_IMAGE_SIZES as $class_name => $sizes ) {
			// Rendered outside a page's main loop the image is lazy, and core
			// puts `auto` ahead of the width. A real hero is the page's first image.
			$this->assertSame( 'auto, ' . $sizes, $this->render_featured_image( $class_name )->get_attribute( 'sizes' ), $class_name );
		}
	}

	/**
	 * A hero's width is only true in the hero. The flyer draws the same
	 * image across a printed page.
	 */
	public function test_other_featured_images_keep_cores_sizes() {
		$image = $this->render_featured_image( 'groups-site-flyer__image' );

		$this->assertStringEndsWith( '(max-width: 1200px) 100vw, 1200px', $image->get_attribute( 'sizes' ) );
	}

	/**
	 * Each template marks its hero image, and only that one, with the class
	 * the theme sizes it by.
	 */
	public function test_templates_mark_their_hero_images() {
		$this->assertSame( array( 'groups-site-event-hero-image' ), $this->featured_image_classes( 'single-event.html' ) );
		$this->assertSame( array( 'groups-site-home-hero-image' ), $this->featured_image_classes( 'front-page.html' ) );
		$this->assertSame( array( 'groups-site-flyer__image' ), $this->featured_image_classes( 'single-event-flyer.html' ) );
	}

	/**
	 * The network registers a size between `medium` and `medium_large`,
	 * scaled and never cropped, so core offers it in the `srcset` of any
	 * image. It's registered for every request on the network, not by the
	 * theme, so a photo a Meetup import sideloads gets the file too.
	 */
	public function test_network_registers_a_card_sized_image() {
		$this->assertSame( 10, has_action( 'after_setup_theme', 'WordCamp\\Groups\\GatherPress_Tweaks\\register_event_card_image_size' ) );

		\WordCamp\Groups\GatherPress_Tweaks\register_event_card_image_size();

		$this->assertSame(
			array(
				'width'  => 480,
				'height' => 0,
				'crop'   => false,
			),
			wp_get_registered_image_subsizes()['groups-event-card'] ?? null
		);
	}

	/**
	 * An interactive map hands its start-up to the theme's script, which
	 * waits for the map to be on screen.
	 */
	public function test_interactive_venue_map_waits_until_it_is_on_screen() {
		// The suite's active theme isn't `groups-site`, so point the lookup
		// for the script's file at it.
		add_filter(
			'theme_file_path',
			static function ( $path, $file ) {
				return self::THEME_DIR . $file;
			},
			10,
			2
		);

		$map = '<div class="wp-block-gatherpress-venue-map" data-wp-interactive="gatherpress/venue-map" data-wp-init="callbacks.init" data-wp-context="{}"><img src="map.png" alt="" /></div>';

		$processor = new \WP_HTML_Tag_Processor( \WordCamp\Groups\Site\defer_venue_map( $map ) );

		$this->assertTrue( $processor->next_tag() );
		$this->assertSame( 'groups-site/venue-map::callbacks.init', $processor->get_attribute( 'data-wp-init' ) );
		$this->assertSame( 'gatherpress/venue-map', $processor->get_attribute( 'data-wp-interactive' ), 'GatherPress\'s callback reads its context from its own namespace.' );
		$this->assertContains( 'groups-site-venue-map', wp_script_modules()->get_queue() );
	}

	/**
	 * A static map has nothing to start, so it's left alone and the script
	 * stays off the page.
	 */
	public function test_static_venue_map_is_left_alone() {
		$map = '<div class="wp-block-gatherpress-venue-map" data-render-mode="static"><img src="map.png" alt="" /></div>';

		$this->assertSame( $map, \WordCamp\Groups\Site\defer_venue_map( $map ) );
		$this->assertSame( '', \WordCamp\Groups\Site\defer_venue_map( '' ) );
		$this->assertNotContains( 'groups-site-venue-map', wp_script_modules()->get_queue() );
	}

	/**
	 * The deferral leans on two names inside GatherPress: the store its map
	 * registers, and the callback that mounts the map. If a GatherPress
	 * update renames either, the map stays a static image for good, and
	 * nothing else would say so.
	 */
	public function test_gatherpress_still_starts_its_map_from_the_callback_the_theme_defers() {
		$block_dir = GATHERPRESS_CORE_PATH . '/build/blocks/venue-map/';

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's files from disk.
		$render = file_get_contents( $block_dir . 'render.php' );
		$view   = file_get_contents( $block_dir . 'view.js' );
		// phpcs:enable

		$this->assertStringContainsString( "'gatherpress/venue-map'", $render );
		$this->assertStringContainsString( "'callbacks.init'", $render );
		$this->assertStringContainsString( '"gatherpress/venue-map"', $view );
		$this->assertMatchesRegularExpression( '/callbacks:\s*\{\s*init\b/', $view );
	}

	/**
	 * The members page draws a member list in place of content, so Jetpack
	 * has nowhere to put sharing buttons and no reason to load their styles.
	 * Any other page still gets them.
	 */
	public function test_members_page_loads_no_sharing_assets() {
		add_filter( 'sharing_enqueue_scripts', 'WordCamp\\Groups\\Site\\skip_sharing_assets_on_members_page' );

		$members_id = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'members',
			)
		);
		$about_id   = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);

		$this->go_to( home_url( "?page_id={$members_id}" ) );
		$this->assertFalse( apply_filters( 'sharing_enqueue_scripts', true ) );

		$this->go_to( home_url( "?page_id={$about_id}" ) );
		$this->assertTrue( apply_filters( 'sharing_enqueue_scripts', true ) );
	}

	/**
	 * An event is described in its own words.
	 *
	 * @dataProvider provide_event_descriptions
	 *
	 * @param array  $post_fields The event's fields that differ from the defaults.
	 * @param string $expected    The description of that event.
	 */
	public function test_event_is_described_in_its_own_words( array $post_fields, string $expected ) {
		$this->view_event( $this->create_event( $post_fields ) );

		$this->assertSame( $expected, \WordCamp\Groups\Site\get_meta_description() );
	}

	/**
	 * Events, and the description each should get.
	 *
	 * @return array[]
	 */
	public function provide_event_descriptions(): array {
		return array(
			'its content'                      => array(
				array(),
				'An evening of talks, demos and questions about the web.',
			),
			// An excerpt someone wrote says it better than the first lines of the content.
			'its excerpt, ahead of the content' => array(
				array( 'post_excerpt' => 'Three talks and a <em>lot</em> of questions.' ),
				'Three talks and a lot of questions.',
			),
			// A block that draws a control would leave its labels in the middle of the sentence.
			'only the blocks that are prose'   => array(
				array(
					'post_content' => '<!-- wp:gatherpress/add-to-calendar --><div class="wp-block-gatherpress-add-to-calendar"><a href="#">Add to calendar</a></div><!-- /wp:gatherpress/add-to-calendar -->'
						. '<!-- wp:paragraph --><p>Bring a laptop.</p><!-- /wp:paragraph -->',
				),
				'Bring a laptop.',
			),
			// The event form saves blocks with nothing between them.
			'blocks saved back to back'        => array(
				array(
					'post_content' => '<!-- wp:paragraph --><p>Doors at six.</p><!-- /wp:paragraph -->'
						. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Bring a laptop</li><!-- /wp:list-item -->'
						. '<!-- wp:list-item --><li>Bring a question</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
				),
				'Doors at six. Bring a laptop Bring a question',
			),
			'no more than thirty words'        => array(
				array( 'post_content' => implode( ' ', array_fill( 0, 40, 'word' ) ) ),
				implode( ' ', array_fill( 0, 30, 'word' ) ) . '&hellip;',
			),
		);
	}

	/**
	 * What's written on an event behind a password isn't public, so its
	 * description is the group's.
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
	 * A page that isn't a single post, like the front page, is described by
	 * the group's tagline.
	 */
	public function test_front_page_is_described_by_the_tagline() {
		update_option( 'blogdescription', self::TAGLINE );
		$this->create_event();

		$this->go_to( home_url( '/' ) );

		$this->assertSame( self::TAGLINE, \WordCamp\Groups\Site\get_meta_description() );
	}

	/**
	 * The description is printed as a meta tag, with its quotes escaped.
	 */
	public function test_description_is_printed_as_a_meta_tag() {
		$this->view_event( $this->create_event( array( 'post_excerpt' => 'A "show and tell" night.' ) ) );

		$this->assertSame(
			'<meta name="description" content="A &quot;show and tell&quot; night." />' . "\n",
			get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' )
		);
	}

	/**
	 * With nothing to say, nothing is printed: a group with no tagline, or a
	 * page that doesn't exist.
	 */
	public function test_no_meta_tag_without_a_description() {
		update_option( 'blogdescription', '' );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' ) );

		update_option( 'blogdescription', self::TAGLINE );

		// This bootstrap defines `WP_ADMIN`, where a request for a missing
		// post is never turned into a 404, so the query is told directly.
		$GLOBALS['wp_query']->set_404();

		$this->assertSame( '', get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' ) );
	}
}

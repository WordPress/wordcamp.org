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
	 * The class on each featured-image block in a template.
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
	 * A hero image gets the `sizes` measured for its column.
	 */
	public function test_hero_images_describe_their_columns() {
		foreach ( \WordCamp\Groups\Site\HERO_IMAGE_SIZES as $class_name => $sizes ) {
			// Outside the main loop the image is lazy, so core prefixes `auto`.
			$this->assertSame( 'auto, ' . $sizes, $this->render_featured_image( $class_name )->get_attribute( 'sizes' ), $class_name );
		}
	}

	/**
	 * The flyer draws the same image at another size.
	 */
	public function test_other_featured_images_keep_cores_sizes() {
		$image = $this->render_featured_image( 'groups-site-flyer__image' );

		$this->assertStringEndsWith( '(max-width: 1200px) 100vw, 1200px', $image->get_attribute( 'sizes' ) );
	}

	/**
	 * Each template marks only its hero image.
	 */
	public function test_templates_mark_their_hero_images() {
		$this->assertSame( array( 'groups-site-event-hero-image' ), $this->featured_image_classes( 'single-event.html' ) );
		$this->assertSame( array( 'groups-site-home-hero-image' ), $this->featured_image_classes( 'front-page.html' ) );
		$this->assertSame( array( 'groups-site-flyer__image' ), $this->featured_image_classes( 'single-event-flyer.html' ) );
	}

	/**
	 * Registered network-wide, not by the theme, so Meetup imports get it too.
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
	 * An interactive map hands its start-up to the theme's script.
	 */
	public function test_interactive_venue_map_waits_until_it_is_on_screen() {
		// `groups-site` isn't the suite's active theme.
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
	 * A static map has nothing to start.
	 */
	public function test_static_venue_map_is_left_alone() {
		$map = '<div class="wp-block-gatherpress-venue-map" data-render-mode="static"><img src="map.png" alt="" /></div>';

		$this->assertSame( $map, \WordCamp\Groups\Site\defer_venue_map( $map ) );
		$this->assertSame( '', \WordCamp\Groups\Site\defer_venue_map( '' ) );
		$this->assertNotContains( 'groups-site-venue-map', wp_script_modules()->get_queue() );
	}

	/**
	 * If GatherPress renames its store or callback, the map silently stays static.
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
	 * Only the members page drops the sharing styles.
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
	 */
	public function test_event_is_described_in_its_own_words( array $post_fields, string $expected ) {
		$this->view_event( $this->create_event( $post_fields ) );

		$this->assertSame( $expected, \WordCamp\Groups\Site\get_meta_description() );
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
			'its excerpt, ahead of the content' => array(
				array( 'post_excerpt' => 'Three talks and a <em>lot</em> of questions.' ),
				'Three talks and a lot of questions.',
			),
			'only the blocks that are prose'   => array(
				array(
					'post_content' => '<!-- wp:gatherpress/add-to-calendar --><div class="wp-block-gatherpress-add-to-calendar"><a href="#">Add to calendar</a></div><!-- /wp:gatherpress/add-to-calendar -->'
						. '<!-- wp:paragraph --><p>Bring a laptop.</p><!-- /wp:paragraph -->',
				),
				'Bring a laptop.',
			),
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
	 * Nothing is printed for a group with no tagline, or on a 404.
	 */
	public function test_no_meta_tag_without_a_description() {
		update_option( 'blogdescription', '' );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' ) );

		update_option( 'blogdescription', self::TAGLINE );

		// The bootstrap defines `WP_ADMIN`, where a missing post never becomes a 404.
		$GLOBALS['wp_query']->set_404();

		$this->assertSame( '', get_echo( 'WordCamp\\Groups\\Site\\print_meta_description' ) );
	}
}

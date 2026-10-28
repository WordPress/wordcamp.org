<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Blocks\Event_Query;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Every GatherPress block the `groups-site` theme places is still registered.
 *
 * The API contract test only sees PHP. A block GatherPress renames or removes
 * doesn't fatal anything: the template renders it as nothing, so the date or
 * venue silently drops off the page. 0.35.0 did exactly that to
 * `gatherpress/icon`. The list comes from the theme's own markup, so a block
 * added to a template is covered without editing this file.
 *
 * @group groups
 */
class Test_Groups_Site_GatherPress_Blocks extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * Blocks the theme is known to use, as of GatherPress 0.36.0. Only a
	 * floor for the scan, so it can't pass by finding nothing.
	 */
	const KNOWN_BLOCKS = array(
		'gatherpress/add-to-calendar',
		'gatherpress/event-date',
		'gatherpress/online-event',
		'gatherpress/rsvp-count',
		'gatherpress/venue',
		'gatherpress/venue-detail',
		'gatherpress/venue-map',
	);

	/**
	 * Read every template, part and pattern in the theme.
	 *
	 * @return string[] Markup, keyed by path relative to the theme.
	 */
	private static function theme_markup(): array {
		$markup = array();

		foreach ( array( 'templates', 'parts', 'patterns' ) as $dir ) {
			foreach ( glob( self::THEME_DIR . $dir . '/*.{html,php}', GLOB_BRACE ) as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme file from disk.
				$markup[ $dir . '/' . basename( $path ) ] = file_get_contents( $path );
			}
		}

		return $markup;
	}

	/**
	 * Data provider: one case per GatherPress block the theme places.
	 */
	public function provide_theme_blocks(): array {
		$cases = array();

		foreach ( self::theme_markup() as $file => $markup ) {
			preg_match_all( '/<!--\s+wp:(gatherpress\/[a-z0-9-]+)/', $markup, $matches );

			foreach ( $matches[1] as $block ) {
				$cases[ $block ] ??= array( $block, $file );
			}
		}

		ksort( $cases );

		return $cases;
	}

	/**
	 * @dataProvider provide_theme_blocks
	 */
	public function test_theme_block_is_registered( string $block, string $file ) {
		$this->assertTrue(
			\WP_Block_Type_Registry::get_instance()->is_registered( $block ),
			"`{$file}` places `{$block}`, which GatherPress no longer registers, so it renders blank. Check the GatherPress changelog for its replacement and update every template using it."
		);
	}

	/**
	 * The scan finds what the theme is known to use.
	 */
	public function test_scan_finds_the_known_blocks() {
		$found = array_keys( $this->provide_theme_blocks() );

		foreach ( self::KNOWN_BLOCKS as $block ) {
			$this->assertContains( $block, $found, "The scan should find `{$block}` in the theme. If the theme stopped using it, drop it from KNOWN_BLOCKS." );
		}
	}

	/**
	 * The event Query Loops carry GatherPress's variation name in their
	 * `namespace` attribute, and GatherPress only applies its event query
	 * when that matches. A renamed variation would leave the loops listing
	 * events by publish date with past ones mixed in.
	 */
	public function test_query_loops_name_gatherpress_event_variation() {
		$namespaces = array();

		foreach ( self::theme_markup() as $file => $markup ) {
			if ( preg_match_all( '/<!--\s+wp:query\s+(\{.*?\})\s+-->/', $markup, $matches ) ) {
				foreach ( $matches[1] as $json ) {
					$attributes = json_decode( $json, true );

					if ( 'gatherpress_event' === ( $attributes['query']['postType'] ?? '' ) ) {
						$namespaces[ $file . ' #' . count( $namespaces ) ] = $attributes['namespace'] ?? '';
					}
				}
			}
		}

		$this->assertNotEmpty( $namespaces, 'Expected the theme to have event Query Loops.' );

		foreach ( $namespaces as $file => $namespace ) {
			$this->assertSame( Event_Query::BLOCK_NAME, $namespace, "`{$file}`'s event Query Loop" );
		}

		$this->assertNotFalse( has_filter( 'pre_render_block', array( Event_Query::get_instance(), 'pre_render_block' ) ) );
	}
}

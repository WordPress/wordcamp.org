<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * The theme's templates close their `main` group before the footer.
 *
 * A template missing the `main` group's closing comment still renders the
 * footer after `</main>`, but the Site Editor saves it with the footer inside
 * `main`, nesting the site footer in the main landmark.
 *
 * @group groups
 */
class Test_Groups_Site_Templates extends WP_UnitTestCase {

	/**
	 * Find blocks matching a callback, at any depth.
	 *
	 * @param array    $blocks  Parsed blocks.
	 * @param callable $matches Receives a block, returns whether it matches.
	 *
	 * @return array Matching blocks.
	 */
	private static function find_blocks( array $blocks, callable $matches ): array {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( $matches( $block ) ) {
				$found[] = $block;
			}

			array_push( $found, ...self::find_blocks( $block['innerBlocks'], $matches ) );
		}

		return $found;
	}

	/**
	 * No template parses with the footer inside its `main` group.
	 */
	public function test_footer_is_outside_main() {
		$templates = glob( SUT_WP_CONTENT_DIR . 'themes/groups-site/templates/*.html' );
		$checked   = 0;
		$nested    = array();

		foreach ( $templates as $path ) {
			$file = basename( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme file from disk.
			$blocks = parse_blocks( file_get_contents( $path ) );

			$mains = self::find_blocks(
				$blocks,
				fn( $block ) => 'core/group' === $block['blockName'] && 'main' === ( $block['attrs']['tagName'] ?? '' )
			);

			foreach ( $mains as $main ) {
				$footers = self::find_blocks(
					$main['innerBlocks'],
					fn( $block ) => 'core/template-part' === $block['blockName'] && 'footer' === ( $block['attrs']['slug'] ?? '' )
				);

				if ( $footers ) {
					$nested[] = $file;
				}

				++$checked;
			}
		}

		$this->assertGreaterThan( 5, $checked, 'Expected to find the `main` group in the theme templates.' );
		$this->assertSame( array(), $nested, 'These templates have the footer inside their `main` group. Close the group with `<!-- /wp:group -->` after `</main>`.' );
	}
}

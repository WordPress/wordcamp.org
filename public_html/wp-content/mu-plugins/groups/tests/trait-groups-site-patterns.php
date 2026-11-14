<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

/**
 * Registers the groups-site theme's patterns for tests that read its templates.
 *
 * `groups-site` isn't the active theme in this suite, so core never registers
 * its `patterns/` directory. The templates pull their copy in through
 * `wp:pattern` so it can be translated (#2376); a test that parses a template
 * resolves those references first, to see the blocks the site renders.
 */
trait Groups_Site_Patterns {

	/**
	 * Slugs this trait registered, so it only unregisters its own.
	 *
	 * @var string[]
	 */
	private static $registered_theme_patterns = array();

	/**
	 * Register every pattern in the theme the way core does for an active
	 * theme: by file, so each one renders for the current user and locale.
	 */
	protected static function register_groups_site_patterns(): void {
		$registry = \WP_Block_Patterns_Registry::get_instance();

		foreach ( glob( SUT_WP_CONTENT_DIR . 'themes/groups-site/patterns/*.php' ) as $file ) {
			$headers = get_file_data(
				$file,
				array(
					'title' => 'Title',
					'slug'  => 'Slug',
				)
			);

			if ( $registry->is_registered( $headers['slug'] ) ) {
				continue;
			}

			register_block_pattern(
				$headers['slug'],
				array(
					'title'    => $headers['title'],
					'filePath' => $file,
				)
			);

			self::$registered_theme_patterns[] = $headers['slug'];
		}
	}

	/**
	 * Drop the patterns registered above so they can't leak into other classes.
	 */
	protected static function unregister_groups_site_patterns(): void {
		foreach ( self::$registered_theme_patterns as $slug ) {
			unregister_block_pattern( $slug );
		}

		self::$registered_theme_patterns = array();
	}

	/**
	 * Parse a theme template or part, with its pattern references resolved.
	 *
	 * @param string $path Path relative to the theme, e.g. `templates/single-event.html`.
	 *
	 * @return array[] The parsed blocks.
	 */
	protected function parse_groups_site_template( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk.
		$markup = file_get_contents( SUT_WP_CONTENT_DIR . 'themes/groups-site/' . $path );

		$this->assertNotFalse( $markup, "Could not read {$path}." );

		return resolve_pattern_blocks( parse_blocks( $markup ) );
	}
}

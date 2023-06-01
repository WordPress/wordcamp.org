<?php
namespace WordCamp\Blocks\Patterns;

use WP_Block_Patterns_Registry;

/**
 * Actions & filters.
 */
add_action( 'init', __NAMESPACE__ . '\register_patterns', 5 ); // Register our patterns early, so they're first in the list.

/**
 * Register the patterns in the `./patterns/` directory. This matches the core
 * theme behavior to register patterns in a theme's `./patterns/` directory.
 *
 * See _register_theme_block_patterns().
 *
 * The pattern fields include:
 *   - Title            (required)
 *   - Slug             (required)
 *   - Description
 *   - Viewport Width
 *   - Inserter         (yes/no)
 *   - Categories       (comma-separated values)
 *   - Keywords         (comma-separated values)
 *   - Block Types      (comma-separated values)
 *   - Post Types       (comma-separated values)
 *   - Template Types   (comma-separated values)
 */
function register_patterns() {
	// Set up some categories (?).
	register_block_pattern_category( 'wordcamp', array( 'label' => _x( 'WordCamp', 'Block pattern category', 'wordcamporg' ) ) );

	$default_headers = array(
		'title'         => 'Title',
		'slug'          => 'Slug',
		'description'   => 'Description',
		'viewportWidth' => 'Viewport Width',
		'inserter'      => 'Inserter',
		'categories'    => 'Categories',
		'keywords'      => 'Keywords',
		'blockTypes'    => 'Block Types',
		'postTypes'     => 'Post Types',
		'templateTypes' => 'Template Types',
	);

	$dirpath = __DIR__ . '/patterns/';
	if ( ! is_dir( $dirpath ) || ! is_readable( $dirpath ) ) {
		return;
	}

	if ( file_exists( $dirpath ) ) {
		$files = glob( $dirpath . '*.php' );
		if ( $files ) {
			foreach ( $files as $file ) {
				$pattern_data = get_file_data( $file, $default_headers );

				if ( empty( $pattern_data['slug'] ) ) {
					_doing_it_wrong(
						'register_patterns',
						esc_html(
							sprintf(
								/* translators: %s: file name. */
								__( 'Could not register file "%s" as a block pattern ("Slug" field missing)', 'wordcamp' ),
								$file
							)
						),
						''
					);
					continue;
				}

				if ( ! preg_match( '/^[A-z0-9\/_-]+$/', $pattern_data['slug'] ) ) {
					_doing_it_wrong(
						'register_patterns',
						esc_html(
							sprintf(
								/* translators: %1s: file name; %2s: slug value found. */
								__( 'Could not register file "%1$s" as a block pattern (invalid slug "%2$s")', 'wordcamp' ),
								$file,
								$pattern_data['slug']
							)
						),
						''
					);
				}

				if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $pattern_data['slug'] ) ) {
					continue;
				}

				// Title is a required property.
				if ( ! $pattern_data['title'] ) {
					_doing_it_wrong(
						'register_patterns',
						esc_html(
							sprintf(
								/* translators: %1s: file name; %2s: slug value found. */
								__( 'Could not register file "%s" as a block pattern ("Title" field missing)', 'wordcamp' ),
								$file
							)
						),
						''
					);
					continue;
				}

				// For properties of type array, parse data as comma-separated.
				foreach ( array( 'categories', 'keywords', 'blockTypes', 'postTypes', 'templateTypes' ) as $property ) {
					if ( ! empty( $pattern_data[ $property ] ) ) {
						$pattern_data[ $property ] = array_filter(
							preg_split(
								'/[\s,]+/',
								(string) $pattern_data[ $property ]
							)
						);
					} else {
						unset( $pattern_data[ $property ] );
					}
				}

				// Parse properties of type int.
				foreach ( array( 'viewportWidth' ) as $property ) {
					if ( ! empty( $pattern_data[ $property ] ) ) {
						$pattern_data[ $property ] = (int) $pattern_data[ $property ];
					} else {
						unset( $pattern_data[ $property ] );
					}
				}

				// Parse properties of type bool.
				foreach ( array( 'inserter' ) as $property ) {
					if ( ! empty( $pattern_data[ $property ] ) ) {
						$pattern_data[ $property ] = in_array(
							strtolower( $pattern_data[ $property ] ),
							array( 'yes', 'true' ),
							true
						);
					} else {
						unset( $pattern_data[ $property ] );
					}
				}

				//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				$pattern_data['title'] = translate_with_gettext_context( $pattern_data['title'], 'Pattern title', 'wordcamp' );
				if ( ! empty( $pattern_data['description'] ) ) {
					//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					$pattern_data['description'] = translate_with_gettext_context( $pattern_data['description'], 'Pattern description', 'wordcamp' );
				}

				// The actual pattern content is the output of the file.
				ob_start();
				include $file;
				$pattern_data['content'] = ob_get_clean();
				if ( ! $pattern_data['content'] ) {
					continue;
				}

				register_block_pattern( $pattern_data['slug'], $pattern_data );
			}
		}
	}
}

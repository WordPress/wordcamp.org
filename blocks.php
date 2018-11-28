<?php
namespace WordCamp\Blocks;
defined( 'WPINC' ) || die();

use WP_Post;

/**
 * Load files.
 *
 * @return void
 */
function load() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/speakers.php';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

/**
 * Add block categories for custom blocks.
 *
 * @param array   $default_categories
 * @param WP_Post $post
 *
 * @return array
 */
function register_block_categories( $default_categories, $post ) {
	$default_categories[] = array(
		'slug'  => 'wordcamp',
		'title' => __( 'WordCamp Blocks', 'wordcamporg' ),
	);

	return $default_categories;
}

add_filter( 'block_categories', __NAMESPACE__ . '\register_block_categories', 10, 2 );

/**
 * Enqueue assets.
 *
 * @return void
 */
function enqueue_assets() {
	wp_enqueue_style(
		'wordcamp-blocks',
		plugins_url( 'assets/blocks.min.css', __FILE__ ),
		[],
		filemtime( plugin_dir_path( __FILE__ ) . 'assets/blocks.min.css' )
	);

	wp_enqueue_script(
		'wordcamp-blocks',
		plugins_url( 'assets/blocks.min.js', __FILE__ ),
		array(
			'lodash',
			'wp-blocks',
			'wp-components',
			'wp-compose',
			'wp-data',
			'wp-editor',
			'wp-element',
			'wp-html-entities',
			'wp-i18n',
			'wp-url',
		),
		filemtime( plugin_dir_path( __FILE__ ) . 'assets/blocks.min.js' ),
		false
	);

	$data = array(
		'localeData' => gutenberg_get_jed_locale_data( 'wordcamporg' )
	);

	/**
	 * Filter: Add/modify data sent to WordCamp Blocks JS scripts.
	 *
	 * @param array $data Associative multidimensional array of data.
	 */
	$data = apply_filters( 'wordcamp_blocks_script_data', $data );

	wp_add_inline_script(
		'wordcamp-blocks',
		sprintf( "
			var WordCampBlocks = %s;
			wp.i18n.setLocaleData( WordCampBlocks.localeData, 'wordcamporg' );",
			wp_json_encode( $data )
		),
		'before'
	);
}

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_assets' );

/**
 * Fix a CSS bug in Gutenberg when PanelRows are used with RangeControls.
 *
 * @todo Remove this when https://github.com/WordPress/gutenberg/pull/4564 is fixed.
 */
function fix_core_max_width_bug() {
	?>

	<style>
		.components-panel__row label {
			max-width: 100% !important;
		}
	</style>

	<?php
}

add_action( 'admin_print_styles', __NAMESPACE__ . '\fix_core_max_width_bug' );

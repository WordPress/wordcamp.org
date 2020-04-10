<?php
/**
 * Embeds for virtual events on WordCamp.org:
 *  - CrowdCast block to embed iframe
 *  - StreamText block which saves as the streamtext shortcode
 *  - YouTube Live Chat block to embed iframe.
 */

namespace WordCamp\Virtual_Embeds;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );

/**
 * Register assets.
 *
 * @return void
 */
function register_assets() {
	$script_info = require PLUGIN_DIR . 'build/index.asset.php';

	wp_register_script(
		'virtual-embeds',
		PLUGIN_URL . 'build/index.js',
		$script_info['dependencies'],
		$script_info['version'],
		false
	);

	wp_register_style(
		'virtual-embeds',
		PLUGIN_URL . 'style.css',
		array(),
		filemtime( __DIR__ . '/style.css' )
	);

	wp_set_script_translations( 'virtual-embeds', 'wordcamporg' );

	register_block_type(
		'wordcamp/crowdcast-embed',
		array(
			'editor_script'   => 'virtual-embeds',
			'editor_style'    => 'virtual-embeds',
			'render_callback' => __NAMESPACE__ . '\render_crowdcast_block',
		)
	);

	register_block_type(
		'wordcamp/streamtext-embed',
		array(
			'editor_script' => 'virtual-embeds',
			'editor_style'  => 'virtual-embeds',
		)
	);

	register_block_type(
		'wordcamp/youtube-live-chat-embed',
		array(
			'editor_script'   => 'virtual-embeds',
			'editor_style'    => 'virtual-embeds',
			'style'           => 'virtual-embeds',
			'render_callback' => __NAMESPACE__ . '\render_youtube_live_chat_block',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_assets', 9 );

/**
 * Render the CrowdCast embed on the front end.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_crowdcast_block( $attributes ) {
	if ( ! isset( $attributes['channel'] ) ) {
		return '';
	}

	$channel = sanitize_text_field( $attributes['channel'] );
	$url     = sprintf( 'https://www.crowdcast.io/e/%s?navlinks=false&embed=true', $channel );
	$embed   = '<iframe width="100%" height="800" frameborder="0" marginheight="0" marginwidth="0" allowtransparency="true" src="' . esc_url( $url ) . '" style="border: 1px solid #EEE;border-radius:3px" allowfullscreen="true" webkitallowfullscreen="true" mozallowfullscreen="true" allow="microphone; camera;"></iframe>';

	$classes = '';
	if ( isset( $attributes['align'] ) && $attributes['align'] ) {
		$classes .= 'align' . sanitize_html_class( $attributes['align'] );
	}

	return '<div class="wp-block-wordcamp-crowdcast-embed ' . $classes . '">' . $embed . '</div>';
}

/**
 * Render the YouTube Live Chat embed on the front end.
 *
 * @param array $attributes Block attributes.
 *
 * @return string
 */
function render_youtube_live_chat_block( $attributes ) {
	$classes      = 'wp-block-wordcamp-youtube-live-chat-embed';
	$embed_domain = wp_parse_url( site_url(), PHP_URL_HOST );
	$query_parts  = wp_parse_args( wp_parse_url( $attributes['videoUrl'] ?? '', PHP_URL_QUERY ) );

	if ( ! isset( $query_parts['v'] ) ) {
		return '';
	}

	if ( ! empty( $attributes['align'] ) ) {
		$classes .= ' align' . sanitize_html_class( $attributes['align'] );
	}

	$iframe_src = sprintf(
		'https://www.youtube.com/live_chat?v=%s&embed_domain=%s',
		$query_parts['v'],
		$embed_domain
	);

	ob_start();
	?>

	<div class="<?php echo esc_attr( $classes ); ?>">
		<iframe
			id="wp-block-wordcamp-youtube-live-chat-embed__video-<?php echo esc_attr( $query_parts['v'] ); ?>"
			title="Embedded YouTube live chat"
			src="<?php echo esc_url( $iframe_src ); ?>"
			sandbox="allow-same-origin allow-scripts allow-popups"
		>
		</iframe>

		<?php
		/*
		 * Apparently YouTube artificially blocks this entire feature on phones and tablets, and only mentions
		 * that in passing in their documentation.
		 *
		 * There don't seem to be any practical workarounds. See https://stackoverflow.com/a/12845483/450127
		 * and https://stackoverflow.com/a/58739531/450127.
		 *
		 * So... yeah. We're left with this.
		 */
		?>

		<p class="wp-block-wordcamp-youtube-live-chat-embed__availability-warning">
			<?php esc_html_e( "Note: If you're using a mobile device, YouTube may block you from viewing the live chat above. You may be able to access it through their native apps.", 'wordcamporg' );
			?>
		</p>
	</div>

	<?php

	return ob_get_clean();
}

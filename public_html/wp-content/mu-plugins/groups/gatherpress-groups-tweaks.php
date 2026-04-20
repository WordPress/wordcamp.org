<?php
/**
 * GatherPress tweaks for WordPress Group sites.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\GatherPress_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Disable the "Show Timezone" GatherPress setting so event date blocks
 * never append "GMT+0000" or similar suffixes.
 *
 * Also disable anonymous RSVP at the global setting level.
 */
add_filter(
	'pre_option_gatherpress_settings',
	static function ( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$value['show_timezone']        = 0;
		$value['enable_anonymous_rsvp'] = 0;

		return $value;
	}
);

/**
 * Force anonymous RSVP off for all events on group sites.
 *
 * GatherPress checks `get_post_meta( $id, 'gatherpress_enable_anonymous_rsvp', true )`
 * to decide whether to show the anonymous checkbox. Returning a non-null value
 * from `get_post_metadata` short-circuits the real lookup; wrapping in an array
 * mirrors what WP would return for a single meta value of empty-string.
 */
add_filter(
	'get_post_metadata',
	static function ( $value, $object_id, $meta_key ) {
		if ( 'gatherpress_enable_anonymous_rsvp' === $meta_key ) {
			return array( '' );
		}

		return $value;
	},
	10,
	3
);

/**
 * Override the default Gravatar type for RSVP avatars.
 *
 * GatherPress hardcodes 'mystery' as the default and bakes it into the
 * URL via get_avatar_url(). This filter runs after (priority 20) and
 * rewrites the d= parameter in the already-built URL.
 */
add_filter(
	'get_avatar_data',
	static function ( array $args ): array {
		$default = get_option( 'avatar_default', 'wavatar' );

		if ( ! empty( $args['url'] ) && str_contains( $args['url'], 'd=mm' ) ) {
			$args['url'] = str_replace( 'd=mm', 'd=' . rawurlencode( $default ), $args['url'] );
		}

		if ( isset( $args['default'] ) && 'mystery' === $args['default'] ) {
			$args['default'] = $default;
		}

		return $args;
	},
	20
);

/**
 * Require login to post comments (Discussion section) on group sites.
 */
add_filter(
	'pre_option_comment_registration',
	static function () {
		return '1';
	}
);

/**
 * Make the gatherpress_venue post type non-public so it has no front-end
 * archive or singular URLs. Venues are only used as metadata on events.
 */
add_filter(
	'register_post_type_args',
	static function ( array $args, string $post_type ): array {
		if ( 'gatherpress_venue' === $post_type ) {
			$args['public']             = false;
			$args['publicly_queryable'] = false;
			$args['has_archive']        = false;
		}

		return $args;
	},
	10,
	2
);

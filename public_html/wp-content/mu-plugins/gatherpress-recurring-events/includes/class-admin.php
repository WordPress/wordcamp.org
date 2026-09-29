<?php
/**
 * Block editor recurrence controls.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

defined( 'WPINC' ) || die();

final class Admin {

	/** Registers recurrence post metadata. */
	public static function register_meta(): void {
		$definitions = array(
			'frequency'       => array(
				'type' => 'string', 'default' => '',
			),
			'interval'        => array(
				'type' => 'integer', 'default' => 1,
			),
			'weekdays'        => array(
				'type'         => 'array',
				'default'      => array(),
				'show_in_rest' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'monthly_mode'    => array(
				'type' => 'string', 'default' => 'day',
			),
			'monthly_day'     => array(
				'type' => 'integer', 'default' => 1,
			),
			'monthly_order'   => array(
				'type' => 'string', 'default' => 'first',
			),
			'monthly_weekday' => array(
				'type' => 'string', 'default' => 'MO',
			),
			'end_type'        => array(
				'type' => 'string', 'default' => 'never',
			),
			'until'           => array(
				'type' => 'string', 'default' => '',
			),
			'count'           => array(
				'type' => 'integer', 'default' => 12,
			),
			'rrule'           => array(
				'type' => 'string', 'default' => '',
			),
		);

		foreach ( $definitions as $name => $args ) {
			$show_in_rest = $args['show_in_rest'] ?? true;
			register_post_meta(
				'gatherpress_event',
				Rule::META_PREFIX . $name,
				array(
					'type'              => $args['type'],
					'single'            => true,
					'default'           => $args['default'],
					'show_in_rest'      => $show_in_rest,
					'sanitize_callback' => array( self::class, 'sanitize_meta' ),
					'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/**
	 * Sanitizes recurrence metadata.
	 *
	 * @param mixed  $value    Submitted value.
	 * @param string $meta_key Metadata key.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_meta( $value, string $meta_key ) {
		if ( Rule::META_PREFIX . 'weekdays' === $meta_key ) {
			return is_array( $value ) ? array_values( array_intersect( Rule::weekdays(), array_map( static fn( $day ) => strtoupper( sanitize_key( $day ) ), $value ) ) ) : array();
		}

		if ( in_array( $meta_key, array( Rule::META_PREFIX . 'interval', Rule::META_PREFIX . 'monthly_day', Rule::META_PREFIX . 'count' ), true ) ) {
			return max( 1, (int) $value );
		}

		return sanitize_text_field( $value );
	}

	/** Enqueues the document-sidebar controls for events. */
	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'gatherpress_event' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'gpre-editor',
			plugin_dir_url( FILE ) . 'assets/editor.js',
			array( 'gatherpress-panels', 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			(string) filemtime( DIR . '/assets/editor.js' ),
			true
		);
		wp_set_script_translations( 'gpre-editor', 'wordcamporg' );
	}
}

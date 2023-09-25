<?php
/**
 * Render the query filter.
 */

/**
 * Get configuration for this filter from a filter, so that child themes can
 * dynamically configure the output without needing to rebuild the HTML.
 *
 * @param array $settings {
 *     Array of settings for this filter.
 *
 *     The return value should use the following format.
 *
 *     @type string $label    The label for this filter (ex, a taxonomy name),
 *                            including a span with selected count if applicable.
 *     @type string $title    A collective label for the filter options, likely
 *                            the same as $label without the selected count.
 *     @type string $key      The key to use in the URL.
 *     @type string $action   The URL for the form action (ex, archives page).
 *     @type array  $options  Set of key => label pairs, used to build filter
 *                            options. The array key will be used as the query
 *                            parameter in the URL to apply the filter.
 *     @type array  $selected Array of the selected values, this should match
 *                            the array keys in $options.
 * }
 * @param WP_Block $block   The current block being rendered.
 */
$settings = apply_filters( "wporg_query_filter_options_{$attributes['key']}", array(), $block );

// If the filter is not configured, don't render anything.
if ( ! isset( $settings['options'] ) || ! count( $settings['options'] ) ) {
	return;
}

// Initial state to pass to Interactivity API.
$init_state = [
	'isOpen' => false,
	'hasHover' => false,
];
$encoded_state = wp_json_encode( [ 'wporg' => [ 'queryFilter' => $init_state ] ] );

$has_multiple = isset( $attributes['multiple'] ) && $attributes['multiple'];

// Set up a unique ID for this filter.
$html_id = wp_unique_id( "filter-{$settings['key']}-" );

$button_classes = array_keys(
	array_filter(
		array(
			'wporg-query-filter__toggle' => true,
			'has-no-filter-applied' => ! count( $settings['selected'] ),
			'is-single-select' => ! $has_multiple,
		)
	)
);
?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore ?>
	data-wp-interactive
	data-wp-context="<?php echo esc_attr( $encoded_state ); ?>"
	data-wp-effect="effects.wporg.queryFilter.init"
	data-wp-class--is-modal-open="context.wporg.queryFilter.isOpen"
	data-wp-on--keydown="actions.wporg.queryFilter.handleKeydown"
>
	<button
		class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>"
		data-wp-class--is-active="context.wporg.queryFilter.isOpen"
		data-wp-on--click="actions.wporg.queryFilter.toggle"
		data-wp-bind--aria-expanded="context.wporg.queryFilter.isOpen"
		aria-controls="<?php echo esc_attr( $html_id ); ?>"
	><?php echo wp_kses_post( $settings['label'] ); ?></button>

	<div
		class="wporg-query-filter__modal-backdrop"
		data-wp-bind--hidden="!context.wporg.queryFilter.isOpen"
		data-wp-on--click="actions.wporg.queryFilter.toggle"
	></div>

	<div
		class="wporg-query-filter__modal"
		id="<?php echo esc_attr( $html_id ); ?>"
		data-wp-bind--hidden="!context.wporg.queryFilter.isOpen"
		data-wp-effect="effects.wporg.queryFilter.focusFirstElement"
	>
		<form
			action="<?php echo esc_attr( $settings['action'] ); ?>"
			data-wp-on--change="actions.wporg.queryFilter.handleFormChange"
		>
			<div class="wporg-query-filter__modal-header">
				<h2><?php echo wp_kses_post( $settings['title'] ); ?></h2>
				<input
					type="button"
					class="wporg-query-filter__modal-close"
					data-wp-on--click="actions.wporg.queryFilter.toggle"
					aria-label="<?php esc_attr_e( 'Close', 'wporg' ); ?>"
				/>
			</div> <!-- /.wporg-query-filter__modal-header -->
			<fieldset class="wporg-query-filter__modal-content">
				<legend class="screen-reader-text"><?php echo wp_kses_post( $settings['title'] ); ?></legend>
				<?php foreach ( $settings['options'] as $value => $label ) : ?>
				<div class="wporg-query-filter__option">
					<?php if ( ! $has_multiple ) : ?>
					<input
						type="radio"
						name="<?php echo esc_attr( $settings['key'] ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						id="<?php echo esc_attr( $html_id . '-' . $value ); ?>"
						<?php checked( in_array( $value, $settings['selected'] ) ); ?>
					/>
					<?php else : ?>
					<input
						type="checkbox"
						name="<?php echo esc_attr( $settings['key'] ); ?>[]"
						value="<?php echo esc_attr( $value ); ?>"
						id="<?php echo esc_attr( $html_id . '-' . $value ); ?>"
						<?php checked( in_array( $value, $settings['selected'] ) ); ?>
					/>
					<?php endif; ?>
					<label for="<?php echo esc_attr( $html_id . '-' . $value ); ?>"><?php echo esc_html( $label ); ?></label>
				</div>
				<?php endforeach; ?>
			</fieldset>

			<?php
			/**
			 * Fires inside the filter form, right before action buttons.
			 *
			 * @param string   $key   The key for the current filter.
			 * @param WP_Block $block The current block being rendered.
			 */
			do_action( 'wporg_query_filter_in_form', $settings['key'], $block );
			?>

			<div class="wporg-query-filter__modal-actions">
				<input
					type="button"
					class="wporg-query-filter__modal-action-clear"
					value="<?php esc_attr_e( 'Clear', 'wporg' ); ?>"
					data-wp-on--click="actions.wporg.queryFilter.clearSelection"
				/>
				<input
					type="submit"
					value="<?php esc_html_e( 'Apply', 'wporg' ); ?>"
				/>
			</div> <!-- /.wporg-query-filter__modal-actions -->
		</form>
	</div> <!-- /.wporg-query-filter__modal -->
</div> <!-- /.wporg-query-filter -->

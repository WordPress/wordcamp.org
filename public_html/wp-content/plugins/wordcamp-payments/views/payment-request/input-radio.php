<tr id="row-<?php echo esc_attr( str_replace( '_', '-', $name ) ); ?>" class="<?php echo true === $is_visible ? 'active' : 'hidden'; ?>">
	<th>
		<?php echo esc_html( $label ); ?>:
	</th>

	<td>
		<?php foreach ( $options as $slug => $label ) : ?>
			<?php $option_name = $name . '_' . sanitize_title_with_dashes( str_replace( ' ', '_', $slug ) ); ?>

			<span id="<?php echo esc_attr( $option_name ) . '_container'; ?>">
				<input
					type="radio"
					id="<?php echo esc_attr( $option_name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $slug ); ?>"
					<?php checked( $slug, $selected ); ?>
					<?php __checked_selected_helper( $required, true, true, 'required' ); ?>
					/>

				<label for="<?php echo esc_attr( $option_name ); ?>">
					<?php echo esc_html( $label ); ?>:
				</label>
			</span>
		<?php endforeach; ?>

		<?php
		if ( $required ) {
			WordCamp_Budgets::render_form_field_required_indicator();
		}
		?>
	</td>
</tr>

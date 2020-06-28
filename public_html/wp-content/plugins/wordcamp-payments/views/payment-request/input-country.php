<tr id="row-<?php echo esc_attr( str_replace( '_', '-', $name ) ); ?>">
	<th>
		<label for="<?php echo esc_attr( $name ); ?>">
			<?php echo esc_html( $label ); ?>:
		</label>
	</th>

	<td>
		<select
			id="<?php echo esc_attr( $name ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			class="regular-text"
			<?php __checked_selected_helper( $required, true, true, 'required' ); ?>
		>
			<option value=""><?php esc_html_e( '(None)', 'wordcamporg' ); ?></option>
			<?php foreach ( $options as $value => $option_label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $selected ); ?>><?php
					echo esc_html( $option_label['name'] );
				?></option>
			<?php endforeach; ?>
		</select>

		<?php
			if ( $required ) {
				WordCamp_Budgets::render_form_field_required_indicator();
			}
		?>
	</td>
</tr>

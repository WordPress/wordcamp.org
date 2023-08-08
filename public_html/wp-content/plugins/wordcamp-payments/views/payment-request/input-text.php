<tr id="row-<?php echo esc_attr( str_replace( '_', '-', $name ) ); ?>" class="<?php echo esc_attr( $row_classes ); ?>">
	<th>
		<label for="<?php echo esc_attr( $name ); ?>">
			<?php echo esc_html( $label ); ?>:
		</label>
	</th>

	<td>
		<input
			type="<?php echo esc_attr( $variant ); ?>"
			id="<?php echo esc_attr( $name ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			<?php if ( $readonly ) {
				echo 'readonly="readonly"'; } ?>
			<?php __checked_selected_helper( $required, true, true, 'required' ); ?>
			class="regular-text"
			/>

		<?php
		if ( $required ) {
			WordCamp_Budgets::render_form_field_required_indicator();
		}
		?>

		<?php if ( ! empty( $description ) ) : ?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</label>
		<?php endif; ?>
	</td>
</tr>

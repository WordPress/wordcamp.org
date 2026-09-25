<?php
/**
 * Multi-select of the sponsor groups a WordCamp belongs to.
 *
 * @var string $field_name Name attribute for the field.
 * @var array  $groups     Available mes_sponsor_group terms.
 * @var array  $selected   Term IDs currently assigned to the camp.
 * @var bool   $protected  Whether the field is locked for this user.
 */

?>
<select
	id="<?php echo esc_attr( $field_name ); ?>"
	name="<?php echo esc_attr( $field_name ); ?>[]"
	multiple
	size="<?php echo esc_attr( min( 8, max( 3, count( $groups ) ) ) ); ?>"
	<?php disabled( $protected ); ?>
>
	<?php foreach ( $groups as $group ) : ?>
		<option value="<?php echo esc_attr( $group->term_id ); ?>" <?php selected( in_array( $group->term_id, $selected, true ) ); ?>>
			<?php echo esc_html( $group->name ); ?>
		</option>
	<?php endforeach; ?>
</select>

<br />
<em>A camp can belong to multiple groups. Sponsors targeting any of them are placed on this camp.</em>

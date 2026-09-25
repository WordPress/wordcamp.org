<?php
/**
 * Adds a Country question type with a dropdown of all countries in the world.
 */
class CampTix_Addon_Country_Field extends CampTix_Addon {

	/**
	 * Register hook callbacks
	 */
	function camptix_init() {
		add_filter( 'camptix_question_field_types',   array( $this, 'question_field_types'   ) );
		add_action( 'camptix_question_field_country', array( $this, 'question_field_country' ), 10, 4 );
	}

	/**
	 * Add Country to the list of question types.
	 *
	 * @param array $types
	 *
	 * @return array
	 */
	function question_field_types( $types ) {
		return array_merge( $types, array(
			'country' => 'Country',
		) );
	}

	/**
	 * Render the Country `select` field on the front-end.
	 */
	function question_field_country( $name, $user_value, $question, $required = false ) {
		global $camptix;
		$countries = wp_list_pluck( wcorg_get_countries(), 'name' );
		?>

		<select
			id="<?php echo esc_attr( $camptix->get_field_id( $name ) ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			<?php if ( $required ) echo 'required'; ?>
		>
			<option value="" disabled <?php selected( '', $user_value ); ?>>
				-- <?php esc_html_e( 'Select', 'wordcamporg' ); ?> --
			</option>

			<?php foreach ( $countries as $country ) : ?>
				<option value="<?php echo esc_attr( $country ); ?>" <?php selected( $country, $user_value ); ?>>
					<?php echo esc_html( $country ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Country_Field' );

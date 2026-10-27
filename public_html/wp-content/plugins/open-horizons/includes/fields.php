<?php
/**
 * The application's questions, shared by the form, validation, the review screen and the privacy exporter.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

/**
 * Gets the application questions, in the order the form asks them.
 *
 * @return array[] Fields with `label`, `type` (text, email, textarea, select, multi, checkbox or country),
 *                 `required`, and `options` for select and multi; keyed by field name.
 */
function get_fields(): array {
	return array(
		'name'               => array(
			'label'    => __( 'Full name', 'wordcamporg' ),
			'type'     => 'text',
			'required' => true,
		),
		'email'              => array(
			'label'    => __( 'Email address', 'wordcamporg' ),
			'type'     => 'email',
			'required' => true,
		),
		'city'               => array(
			'label'    => __( 'City', 'wordcamporg' ),
			'type'     => 'text',
			'required' => true,
		),
		'country'            => array(
			'label'    => __( 'Country', 'wordcamporg' ),
			'type'     => 'country',
			'required' => true,
		),
		'role'               => array(
			'label'    => __( 'Your role at the event', 'wordcamporg' ),
			'type'     => 'select',
			'required' => true,
			'options'  => array(
				'organizer' => __( 'Organizer', 'wordcamporg' ),
				'speaker'   => __( 'Speaker', 'wordcamporg' ),
				'volunteer' => __( 'Volunteer', 'wordcamporg' ),
				'other'     => __( 'Other', 'wordcamporg' ),
			),
		),
		'role_other'         => array(
			'label'    => __( 'If other, which role?', 'wordcamporg' ),
			'type'     => 'text',
			'required' => false,
		),
		'role_confirmed'     => array(
			'label'    => __( 'The event’s organizing team has confirmed my role.', 'wordcamporg' ),
			'type'     => 'checkbox',
			'required' => true,
		),
		'contributions'      => array(
			'label'    => __( 'Please describe your past contributions to the WordPress project. Include teams, initiatives, or specific contributions you’re proud of, with links where possible.', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'contributing_since' => array(
			'label'    => __( 'How long have you been contributing to WordPress?', 'wordcamporg' ),
			'type'     => 'select',
			'required' => true,
			'options'  => array(
				'under-1'  => __( 'Less than 1 year', 'wordcamporg' ),
				'1-5'      => __( '1–5 years', 'wordcamporg' ),
				'5-10'     => __( '5–10 years', 'wordcamporg' ),
				'over-10'  => __( 'More than 10 years', 'wordcamporg' ),
			),
		),
		'current_work'       => array(
			'label'    => __( 'What are you currently working on in the WordPress community?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'goals'              => array(
			'label'    => __( 'What are your goals or plans for future contributions?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'impact'             => array(
			'label'    => __( 'How has contributing to WordPress impacted your personal or professional life?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'benefit'            => array(
			'label'    => __( 'How do you believe attending this event will benefit your contributions and the WordPress project overall?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'sharing'            => array(
			'label'    => __( 'How will you share your learnings or experience from the event with your local community or team?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => true,
		),
		'support'            => array(
			'label'    => __( 'What specific financial support are you requesting?', 'wordcamporg' ),
			'type'     => 'multi',
			'required' => true,
			'options'  => array(
				'travel'        => __( 'Travel', 'wordcamporg' ),
				'accommodation' => __( 'Accommodation', 'wordcamporg' ),
				'visa'          => __( 'Visa fees', 'wordcamporg' ),
				'childcare'     => __( 'Childcare', 'wordcamporg' ),
				'accessibility' => __( 'Personal assistance for accessibility needs', 'wordcamporg' ),
				'other'         => __( 'Other', 'wordcamporg' ),
			),
		),
		'travel_documents'   => array(
			'label'    => __( 'Do you already have the documentation required to travel to the country where the event takes place (e.g. passport, visa)?', 'wordcamporg' ),
			'type'     => 'select',
			'required' => true,
			'options'  => array(
				'yes'      => __( 'Yes, I have all necessary documentation', 'wordcamporg' ),
				'visa'     => __( 'No, I will need to apply for a visa', 'wordcamporg' ),
				'not-sure' => __( 'I’m not sure', 'wordcamporg' ),
			),
		),
		'anything_else'      => array(
			'label'    => __( 'Is there anything else you’d like us to know about you or your situation?', 'wordcamporg' ),
			'type'     => 'textarea',
			'required' => false,
		),
	);
}

/**
 * Gets the optional Identity & Background questions.
 *
 * Their answers are special-category data under GDPR Article 9, so they're only kept with explicit consent,
 * and stored apart from the rest of the application.
 *
 * @return array[] Fields, as get_fields() describes them.
 */
function get_identity_fields(): array {
	return array(
		'gender'       => array(
			'label'    => __( 'What is your gender identity? Select all that apply.', 'wordcamporg' ),
			'type'     => 'multi',
			'required' => false,
			'options'  => array(
				'woman'           => __( 'Woman', 'wordcamporg' ),
				'man'             => __( 'Man', 'wordcamporg' ),
				'non-binary'      => __( 'Non-binary', 'wordcamporg' ),
				'transgender'     => __( 'Transgender', 'wordcamporg' ),
				'genderqueer'     => __( 'Genderqueer/Gender non-conforming', 'wordcamporg' ),
				'prefer-not'      => __( 'Prefer not to say', 'wordcamporg' ),
				'self-describe'   => __( 'Prefer to self-describe', 'wordcamporg' ),
			),
		),
		'gender_other' => array(
			'label'    => __( 'If you prefer to self-describe:', 'wordcamporg' ),
			'type'     => 'text',
			'required' => false,
		),
		'groups'       => array(
			'label'    => __( 'Do you identify as part of any underrepresented or marginalized group within the tech or open source ecosystem? Select all that apply.', 'wordcamporg' ),
			'type'     => 'multi',
			'required' => false,
			'options'  => array(
				'lgbtiq'        => __( 'LGBTIQ+', 'wordcamporg' ),
				'neurodivergent' => __( 'Neurodivergent (for example: autistic, ADHD, dyslexic, dyspraxic, or other cognitive differences)', 'wordcamporg' ),
				'disability'    => __( 'Person with a disability (including physical, cognitive, sensory, or mental health conditions)', 'wordcamporg' ),
				'racialized'    => __( 'Racialized communities (for example: Black or Person of Color, Indigenous, First Nations, Roma, Latin American, Arab, South Asian, East Asian, or other communities marginalized based on perceived race or ethnicity)', 'wordcamporg' ),
				'limited-access' => __( 'From a region or country with limited access to global tech opportunities', 'wordcamporg' ),
				'other'         => __( 'Other', 'wordcamporg' ),
			),
		),
		'groups_other' => array(
			'label'    => __( 'If other, please specify:', 'wordcamporg' ),
			'type'     => 'text',
			'required' => false,
		),
	);
}

/**
 * Cleans and checks answers against their fields.
 *
 * @param array[] $fields Fields, as get_fields() describes them.
 * @param array   $input  Raw answers, unslashed, keyed by field name.
 * @return array|\WP_Error Clean answers keyed by field name, or the problems found.
 */
function validate_answers( array $fields, array $input ): array|\WP_Error {
	$answers = array();
	$errors  = new \WP_Error();

	foreach ( $fields as $key => $field ) {
		$value = $input[ $key ] ?? '';

		switch ( $field['type'] ) {
			case 'email':
				$value = is_string( $value ) ? trim( $value ) : '';

				// Refused rather than run through sanitize_email(), which would quietly change the address.
				if ( '' !== $value && ! is_email( $value ) ) {
					/* translators: %s: The question. */
					$errors->add( 'oh_invalid_' . $key, sprintf( __( 'Please enter a valid email address for: %s', 'wordcamporg' ), $field['label'] ) );
				}
				break;

			case 'textarea':
				$value = sanitize_textarea_field( is_string( $value ) ? $value : '' );
				break;

			case 'select':
				$value = is_string( $value ) && isset( $field['options'][ $value ] ) ? $value : '';
				break;

			case 'country':
				$value = is_string( $value ) && isset( wcorg_get_countries()[ $value ] ) ? $value : '';
				break;

			case 'multi':
				$value = array_values( array_intersect( array_keys( $field['options'] ), (array) $value ) );
				break;

			case 'checkbox':
				$value = ! empty( $value );
				break;

			default:
				$value = sanitize_text_field( is_string( $value ) ? $value : '' );
		}

		if ( $field['required'] && ( '' === $value || array() === $value || false === $value ) ) {
			/* translators: %s: The question. */
			$errors->add( 'oh_missing_' . $key, sprintf( __( 'Please answer: %s', 'wordcamporg' ), $field['label'] ) );
		}

		$answers[ $key ] = $value;
	}

	return $errors->has_errors() ? $errors : $answers;
}

/**
 * Turns an answer into text for people to read.
 *
 * @param array $field Field, as get_fields() describes it.
 * @param mixed $value The stored answer.
 * @return string
 */
function format_answer( array $field, mixed $value ): string {
	switch ( $field['type'] ) {
		case 'select':
			return (string) ( $field['options'][ $value ] ?? '' );

		case 'country':
			return (string) ( wcorg_get_countries()[ $value ]['name'] ?? '' );

		case 'multi':
			return implode( ', ', array_intersect_key( $field['options'], array_flip( (array) $value ) ) );

		case 'checkbox':
			return $value ? __( 'Yes', 'wordcamporg' ) : __( 'No', 'wordcamporg' );

		default:
			return (string) $value;
	}
}

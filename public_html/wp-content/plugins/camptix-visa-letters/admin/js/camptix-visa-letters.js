/* global jQuery */

jQuery( document ).ready( function( $ ) {
	function toggleVisaLetterDetailsForm( showForm ) {
		const $camptixVisaLetterDetailsForm = $( '.camptix-visa-letter-details' );
		const $camptixVisaLetterDetailsFormFields = $camptixVisaLetterDetailsForm.find( 'input,textarea,select' );

		if ( showForm ) {
			$camptixVisaLetterDetailsForm.show();
			$camptixVisaLetterDetailsFormFields.prop( 'required', true );
		} else {
			$camptixVisaLetterDetailsForm.hide();
			$camptixVisaLetterDetailsFormFields.prop( 'required', false );
		}
	}

	$( document ).on( 'change', '#camptix-need-visa-letter', function( event ) {
		toggleVisaLetterDetailsForm( event.target.checked );
	} );

	toggleVisaLetterDetailsForm( $( '#camptix-need-visa-letter' ).prop( 'checked' ) );
} );

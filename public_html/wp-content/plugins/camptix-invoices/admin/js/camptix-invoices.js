/* global jQuery */

jQuery( document ).ready( function( $ ) {
	function toggleInvoiceDetailsForm( showForm ) {
		const $camptixInvoiceDetailsForm = $( '.camptix-invoice-details' );
		const $camptixInvoiceDetailsFormFields = $camptixInvoiceDetailsForm.find( 'input,textarea,select' );

		if ( showForm ) {
			$camptixInvoiceDetailsForm.show();
			$camptixInvoiceDetailsFormFields.prop( 'required', true );
		} else {
			$camptixInvoiceDetailsForm.hide();
			$camptixInvoiceDetailsFormFields.prop( 'required', false );
		}
	}

	$( document ).on( 'change', '#camptix-need-invoice', function( event ) {
		toggleInvoiceDetailsForm( event.target.checked );
	} );

	toggleInvoiceDetailsForm( $( '#camptix-need-invoice' ).prop( 'checked' ) );
} );

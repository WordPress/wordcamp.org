/* global jQuery, camptixQRAdmin */
/**
 * Admin "Send QR code" action for the attendees list table and edit screen.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.camptixQRAdmin || {};

	$( document ).on( 'click', '.tix-send-qr', function ( event ) {
		event.preventDefault();

		var $button = $( this );

		if ( $button.data( 'sending' ) ) {
			return;
		}

		var original = $button.text();
		$button.data( 'sending', true ).text( cfg.sending || '' );

		$.post( cfg.ajaxUrl, {
			action: 'tix_send_qr_email',
			attendee_id: $button.data( 'attendeeId' ),
			nonce: $button.data( 'nonce' )
		} ).done( function ( response ) {
			if ( response && response.success ) {
				var message = ( response.data && response.data.message ) ? response.data.message : '';
				$button.replaceWith( $( '<span class="tix-qr-sent" />' ).text( message ) );
				return;
			}

			var error = ( response && response.data && response.data.message ) ? response.data.message : cfg.error;
			window.alert( error );
			$button.data( 'sending', false ).text( original );
		} ).fail( function () {
			window.alert( cfg.error || '' );
			$button.data( 'sending', false ).text( original );
		} );
	} );
}( jQuery ) );

/* eslint-disable */
jQuery( document ).ready( function( $ ) {
	'use strict';

	var wcb = window.WordCampBudgets;
	var app = wcb.PaymentRequests = {

		/**
		 * Main entry point
		 */
		init: function () {
			try {
				app.registerEventHandlers();
				wcb.setupSelect2( '#wcp_general_info select' );
				wcb.setupSelect2( '#wcp_payment_details select' );
				wcb.setupSelect2( '#vendor_country_iso3166' );
				wcb.attachedFilesView = new wcb.AttachedFilesView( { el: $( '#row-files' ) } );
				wcb.setupDatePicker( '#wcp_general_info' );
				wcb.setupDatePicker( '#submitpost.wcb'   );
			} catch ( exception ) {
				wcb.log( exception );
			}
		},

		/**
		 * Registers event handlers
		 */
		registerEventHandlers : function() {
			var currency        = $( '#currency' );
			var paymentCategory = $( '#payment_category' ),
				paymentDetails  = $( '#wcp_payment_details' );

			paymentDetails.find( 'input[name=payment_method]' ).change( wcb.togglePaymentMethodFields );
			paymentDetails.find( 'input[name=payment_method]:checked' ).trigger( 'change' ); // Set the initial state

			paymentCategory.change( app.toggleOtherCategoryDescription );
			paymentCategory.trigger( 'change' );   // Set the initial state

			$( '#row-files' ).find( 'a.wcb-insert-media' ).click( wcb.showUploadModal );

			$('[name="post_status"]').on('change', function() {
				var $notes = $('.wcb-mark-incomplete-notes'),
					state = $(this).val() == 'wcb-incomplete';

				$notes.toggle(state);
				$notes.find('textarea').attr('required', state);
			}).trigger('change');

			$('#payment_receipt_country_iso3166').on('select2:select', function(e) {
				const selectedValue = $(this).val();
			
				if (selectedValue === 'US') {
					$('#payment_method_direct_deposit_container, #payment_method_check_container').show();
				} else {
					$('#payment_method_direct_deposit_container, #payment_method_check_container').hide();
				}

				$('#row-payment-method').show();
			}).trigger('select2:select');
		},

		/**
		 * Toggle the extra input field when the user selects the Other category
		 *
		 * @param {object} event
		 */
		toggleOtherCategoryDescription : function( event ) {
			try {
				var otherCategoryDescription = $( '#row-other-category-explanation' );

				if ( 'other' == $( this ).find( 'option:selected' ).val() ) {
					$( otherCategoryDescription ).removeClass( 'hidden' );
					$( otherCategoryDescription ).find( ':input' ).prop( 'required', true );
				} else {
					$( otherCategoryDescription ).addClass( 'hidden' );
					$( otherCategoryDescription ).find( ':input' ).prop( 'required', false );
				}

				// todo make the transition smoother
			} catch ( exception ) {
				wcb.log( exception );
			}
		}
	};

	app.init();
} );

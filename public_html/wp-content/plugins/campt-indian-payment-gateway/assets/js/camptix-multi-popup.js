/**
 * Give - Razorpay Popup Checkout JS
 */
var camptix_l10n, camptix_inr_vars;

/**
 * On document ready setup Razorpay events.
 */
jQuery(document).ready(function ($) {
	// Cache donation button title early to reset it if razorpay checkout popup close.
	var razorpay_handler = [],
		$container       = $('#tix'),
		$form            = $('form', $container),
		ticket_quantity  = $('.tix_tickets_table td.tix-column-quantity', $container).text(),
		order_id         = $('input[name="razorpay_order_id"]', $form).val(),
		receipt_id       = $('input[name="razorpay_receipt_id"]', $form).val();

	/**
	 * Validate extra attendee information fields.
	 *
	 * @returns {boolean}
	 */
	var validate_fields = function () {
		for (var i = 1; i <= ticket_quantity; i++) {
			if (!$('input[name="tix_attendee_info[' + i + '][phone]"]', $form).val()) {
				return false;
			}
		}
		return true;
	};

	/**
	 * Show errors.
	 *
	 * @param error_html
	 */
	var show_errors = function (error_html) {
		var $errors = '';

		// Remove old errors html.
		$('#tix-errors', $container).remove();

		// Set new error html.
		$errors = $('<div id="tix-errors"></div>').html(error_html);
		$container.prepend($errors);

		// Scroll to error div.
		$('html,body').animate({
				scrollTop: $container.offset().top
			},
			'slow'
		);
	};

	/**
	 * Show/ Hide extra attendee fields.
	 *
	 * @param show
	 */
	var show_custom_attendee_fields = function (show) {
		var $field_container;

		for (var i = 1; i <= 2; i++) {
			$field_container = $('input[name="tix_attendee_info[' + i + '][phone]"]', $form).closest('tr');

			if (show) {
				$field_container.show();
			} else {
				$field_container.hide();
			}
		}
	};

	/**
	 * Show extra attendee fields only if razorpay selected
	 */
	$('select[name="tix_payment_method"]', $form).on('change', function () {
		
	}).change();

	/**
	 * Increase razorpay's z-index to appear above of all content.
	 */
	$('.razorpay-container').css('z-index', '2147483543');

	/**
	 * On form submit prevent submission if the phone number is invalid.
	 */
	$form.on( 'submit', function( event ) {
		let phone = $( '.mobile', $form ).val();
		phone = phone.replace( /[^0-9]/g, '' );

		if ( ! $.isNumeric( phone ) ) {
			$( '.message', $form ).text( 'Please Enter Only Numbers' );
			$( '.message', $form ).css( 'color', 'red' );
			$( '.mobile', $form ).val( '' );
			$( '.mobile', $form ).focus();

			event.preventDefault();
			return false;
		} else if ( phone.length < 10 ) {
			$( '.message', $form ).text( 'Please Enter correct Mobile Number Or Number with STD Code' );
			$( '.message', $form ).css( 'color', 'red' );
			$( '.mobile', $form ).val( '' );
			$( '.mobile', $form ).focus();

			event.preventDefault();
			return false;
		}

		return true;
	} );
} );

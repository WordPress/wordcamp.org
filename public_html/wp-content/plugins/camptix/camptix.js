/*
 * MDN Cookie Framework
 * https://developer.mozilla.org/en-US/docs/Web/API/document.cookie#A_little_framework.3A_a_complete_cookies_reader.2Fwriter_with_full_unicode_support
 * Updated: 2014-08-28
 */
var docCookies={getItem:function(e){return decodeURIComponent(document.cookie.replace(new RegExp("(?:(?:^|.*;)\\s*"+encodeURIComponent(e).replace(/[\-\.\+\*]/g,"\\$&")+"\\s*\\=\\s*([^;]*).*$)|^.*$"),"$1"))||null},setItem:function(e,o,n,t,c,r){if(!e||/^(?:expires|max\-age|path|domain|secure)$/i.test(e))return!1;var s="";if(n)switch(n.constructor){case Number:s=1/0===n?"; expires=Fri, 31 Dec 9999 23:59:59 GMT":"; max-age="+n;break;case String:s="; expires="+n;break;case Date:s="; expires="+n.toUTCString()}return document.cookie=encodeURIComponent(e)+"="+encodeURIComponent(o)+s+(c?"; domain="+c:"")+(t?"; path="+t:"")+(r?"; secure":""),!0},removeItem:function(e,o,n){return e&&this.hasItem(e)?(document.cookie=encodeURIComponent(e)+"=; expires=Thu, 01 Jan 1970 00:00:00 GMT"+(n?"; domain="+n:"")+(o?"; path="+o:""),!0):!1},hasItem:function(e){return new RegExp("(?:^|;\\s*)"+encodeURIComponent(e).replace(/[\-\.\+\*]/g,"\\$&")+"\\s*\\=").test(document.cookie)},keys:function(){for(var e=document.cookie.replace(/((?:^|\s*;)[^\=]+)(?=;|$)|^\s*|\s*(?:\=[^;]*)?(?:\1|$)/g,"").split(/\s*(?:\=[^;]*)?;\s*/),o=0;o<e.length;o++)e[o]=decodeURIComponent(e[o]);return e}};

/**
 * CampTix Javascript
 *
 * Hopefully runs during wp_footer.
 */
(function($){
	var tix = $( '#tix' );
	$( tix ).addClass( 'tix-js' );

	if ( $( tix ).hasClass( 'tix-has-dynamic-receipts' ) ) {
		refresh_receipt_emails = function() {
			var fields = $('.tix-field-email');
			var html = '';
			var previously_checked = $('[name="tix_receipt_email_js"]:checked').val();
			var checked = false;

			for ( var i = 0; i < fields.length; i++ ) {
				var value = fields[i].value;
				if ( value.length < 1 ) continue;

				var field = $('<div><label><input type="radio" name="tix_receipt_email_js" /> <span>container</span></label><br /></div>');
				$(field).find('span').text(value);
				$(field).find('input').attr('value', value);

				if ( previously_checked != undefined && previously_checked == value && ! checked )
					checked = $(field).find('input').attr('checked','checked');

				html += $(field).html();
			}

			if ( html.length < 1 )
				html = '<label>' + camptix_l10n.enterEmail + '</label>';

			if ( html == $('#tix-receipt-emails-list').html() )
				return;

			$('#tix-receipt-emails-list').html(html);

			previously_checked = $('[name="tix_receipt_email_js"]:checked').val();
			if ( previously_checked == undefined || previously_checked.length < 1 )
				$('#tix-receipt-emails-list input:first').attr('checked','checked');
		};

		$('.tix-field-email').change(refresh_receipt_emails);
		$('.tix-field-email').keyup(refresh_receipt_emails);
		$(document).ready(refresh_receipt_emails);
	}

	/**
	 * Automatically prepend http:// to URL fields if the user didn't.
	 *
	 * Some browsers will reject input like "example.org" as invalid because
	 * it's missing the protocol. This confuses users who don't realize that
	 * the protocol is required.
	 */
	tix.find( 'input[type=url]' ).on( 'blur', function( event ) {
		var url = $( this ).val();

		if ( '' == url ) {
			return;
		}

		if ( url.match( '^https?:\/\/.*' ) === null ) {
			$( this ).val( 'http://' + url );
		}
	} );

	// Get a cookie object
	function tixGetCookie( name ) {
		var cookie = docCookies.getItem( name );

		if ( null == cookie ) {
			cookie = {};
		} else {
			cookie = $.parseJSON( cookie );
		}

		return cookie;
	}

	// Count unique visitors to [tickets] page
	// TODO: Refactor to use wpCookies instead of MDN Cookie Framework
	$( document ).ready( function() {
		if ( ! tix.length ) {
			return;
		}

		var cookie  = tixGetCookie( 'camptix_client_stats' ),
			ajaxURL = camptix_l10n.ajaxURL;

		// Do nothing if we've already counted them
		if ( cookie.hasOwnProperty( 'visited_tickets_form' ) ) {
			return;
		}

		// If it's their first visit, bump the counter on the server and set the client cookie
		cookie.visited_tickets_form = true;

		if ( window.location.href.indexOf( 'tix_reservation_token' ) > -1 ) {
			ajaxURL += window.location.search;
		}

		$.post(
			ajaxURL,
			{
				action:  'camptix_client_stats',
				command: 'increment',
				stat:    'tickets_form_unique_visitors'
			},

			function( response ) {
				if ( true != response.success ) {
					return;
				}

				docCookies.setItem(
					'camptix_client_stats',
					JSON.stringify( cookie ),
					60 * 60 * 24 * 365
				);
			}
		);
	} );

	// Hide unknown attendee fields when reloading the page
	$( document ).ready( function() {
		tix.find( 'input.unknown-attendee' ).each( hide_input_rows_for_unknown_attendee );
	} );

	// Hide unknown attendee fields when checkbox is clicked
	tix.find( 'input.unknown-attendee' ).change( hide_input_rows_for_unknown_attendee );

	/**
	 * Hide the input fields for unknown attendees
	 */
	function hide_input_rows_for_unknown_attendee() {
		// Select core input rows. There aren't any question rows because those are removed by filter_unconfirmed_attendees_questions().
		var input_rows = $( this ).parents( 'table' ).find( 'tr.tix-row-first-name, tr.tix-row-last-name, tr.tix-row-email' );

		if ( this.checked ) {
			input_rows.each( function() {
				$( this ).addClass( 'tix-hidden' );
			} );
		} else {
			input_rows.each( function() {
				$( this ).removeClass( 'tix-hidden' );
			} );
		}
	}

}(jQuery));

window.CampTixStripeData = window.CampTixStripeData || {};

/**
 * Class for utility functions
 * Methods of this class are intended to be over written if needed for a customization.
 *
 * For egs, to over write `getSelectedPaymentOption`, do it like so:
 *
 * CampTixUtilities.getSelectedPaymentOption = function() {
 * 		// code for selecting payment method
 * 	}
 */
var CampTixUtilities = new function() {

	/**
	 * Gets the currently selected payment option. If a new payment options
	 * layout is implemented, then over write this function to select proper
	 * payment option
	 *
	 * @returns {*|string}
	 */
	this.getSelectedPaymentOption = function() {
		return jQuery( '#tix [name="tix_payment_method"]' ).val() || 'stripe';
	}
};
window.CampTixUtilities = CampTixUtilities;

/**
 * Functionality for the Stripe payment gateway.
 */
var CampTixStripe = new function() {
	var self = this;

	self.data = CampTixStripeData;
	self.form = false;

	self.init = function() {
		self.form = jQuery( '#tix_checkout_form' );
		if ( ! self.form.length ) {
			return;
		}

		self.form.on( 'submit', CampTixStripe.form_handler );

		// On a failed attendee data request, we'll have the previous stripe token
		if ( self.data.token ) {
			self.add_stripe_token_hidden_fields( self.data.token, self.data.receipt_email || '' );
		}
	};

	self.form_handler = function(e) {
		// Verify Stripe is the selected method.
		var method = CampTixUtilities.getSelectedPaymentOption();

		if ( 'stripe' !== method ) {
			return;
		}

		// Check if Stripe checkout is available (stripe's js is not added to free orders, etc).
		if ( typeof StripeCheckout === 'undefined') {
			return;
		}

		// If the form already has a Stripe token, bail.
		var tokenised = self.form.find('input[name="tix_stripe_token"]');
		if ( tokenised.length ) {
			return;
		}

		self.stripe_checkout();

		e.preventDefault();
	};

	self.stripe_checkout = function() {
		var emails = jQuery.uniqueSort(
			self.form.find('input[type="email"]')
				.filter( function () { return this.value.length; })
				.map( function() { return this.value; } )
		);

		var StripeHandler = StripeCheckout.configure({
			key: self.data.public_key,
			image: self.data.image,
			locale: 'auto',
			amount: parseInt( this.data.amount ),
			currency: self.data.currency,
			description: self.data.description,
			name: self.data.name,
			zipCode: true,
			email: ( emails.length === 1 ? emails[0] : '' ) || '',
			token: self.stripe_token_callback,
		});

		// Close the popup if they hit back.
		window.addEventListener('popstate', function() {
			StripeHandler.close();
		});

		StripeHandler.open();
	};

	self.stripe_token_callback = function( token ) {
		self.add_stripe_token_hidden_fields( token.id, token.receipt_email || token.email );

		// Prevent calling form_handler multiple times.
		self.form.off( 'submit', CampTixStripe.form_handler );
		self.form.submit();
	};

	self.add_stripe_token_hidden_fields = function( token_id, email ) {
		jQuery('<input>').attr({
			type: 'hidden',
			id: 'tix_stripe_token',
			name: 'tix_stripe_token',
			value: token_id,
		}).appendTo( self.form );

		if ( email ) {
			jQuery('<input>').attr({
				type: 'hidden',
				id: 'tix_stripe_receipt_email',
				name: 'tix_stripe_receipt_email',
				value: email,
			}).appendTo( self.form );

			/**
			 * for backward compatibility. we renamed `tix_stripe_reciept_email`
			 * to `tix_stripe_receipt_email` in 1.7, but older stripe plugin
			 * would still be expecting `tix_stripe_reciept_email`
			 */
			jQuery( '<input>' ).attr({
				type: 'hidden',
				id: 'tix_stripe_reciept_email',
				name: 'tix_stripe_reciept_email',
				value: email,
			}).appendTo( self.form );
		}

	};
};

jQuery(document).ready( function($) {
	CampTixStripe.init()
});

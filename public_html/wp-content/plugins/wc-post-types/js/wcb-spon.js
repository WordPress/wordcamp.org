/* eslint-disable */
/* global jQuery, Backbone, wp, wcbSponsors */

( function( $ ) {

	'use strict';

	wcbSponsors.view    = wcbSponsors.view    || {};
	wcbSponsors.metabox = wcbSponsors.metabox || {};

	wcbSponsors.view.Agreement = Backbone.View.extend( {
		/**
		 * Set up the metabox view.
		 *
		 * @returns {wcbSponsors.view.Agreement}
		 */
		initialize: function() {
			var self = this;

			this.$input = $( '#sponsor-agreement-id' );

			// Bail if the input field doesn't exist, which indicates that this is
			// a multi-event sponsor whose agreement can't be modified on individual sites.
			if ( this.$input.length < 1 ) {
				return this;
			}

			this.frame = wp.media( {
				id: 'sponsor-agreement-media-modal',
				title: wcbSponsors.l10n.modalTitle,
				multiple: false,
				library: {
					type: wcbSponsors.modal.allowedTypes
				}
			} );

			this.frame.on( 'select', function() {
				var attachment = self.frame.state().get( 'selection' ).first().toJSON();

				self.$input.val( attachment.id );
				self.elements.$view.find( 'a' ).first().attr( 'href', attachment.url ).removeClass( 'hidden' );
				self.toggleElements();
			} );

			this.markOwnUploads();
			this.toggleElements();

			return this;
		},

		/**
		 * Mark this modal's uploads as agreements while they are on their way to the server.
		 *
		 * A file uploaded here is an agreement whether or not the organizer goes on to select it and save
		 * the sponsor, and until something says so it is an ordinary attachment on a public post. Sending
		 * the answer with the file means it never has to be chased afterwards.
		 *
		 * The uploader is built when the modal first opens, so the field can only be set from here. A PDF
		 * is taken as an agreement without it, so this failing quietly costs the images only.
		 *
		 * @returns {wcbSponsors.view.Agreement}
		 */
		markOwnUploads: function() {
			var self = this;

			if ( 'undefined' === typeof wcbSponsorAgreement ) {
				return this;
			}

			this.frame.on( 'open', function() {
				// Core leaves the frame without an uploader when uploading is unavailable, and there is
				// then nothing arriving to mark.
				var uploader = self.frame.uploader && self.frame.uploader.uploader;

				if ( uploader && 'function' === typeof uploader.param ) {
					uploader.param( wcbSponsorAgreement.param, 1 );
				}
			} );

			return this;
		},

		/**
		 * Cache metabox elements.
		 */
		elements: {
			$description: $( '#sponsor-agreement-description-container' ),
			$upload     : $( '#sponsor-agreement-upload-container' ),
			$view       : $( '#sponsor-agreement-view-container' ),
			$remove     : $( '#sponsor-agreement-remove-container' )
		},

		/**
		 * Bind events.
		 */
		events: {
			'click #sponsor-agreement-upload': 'upload',
			//'click #sponsor-agreement-view'  : 'view',
			'click #sponsor-agreement-remove': 'remove'
		},

		/**
		 * Toggle the visibility of metabox elements based on the value of the hidden field.
		 *
		 * @returns {wcbSponsors.view.Agreement}
		 */
		toggleElements: function() {
			var agreementId         = this.$input.val(),
				$uiWithoutAgreement = this.elements.$upload.add( this.elements.$description ),
				$uiWithAgreement    = this.elements.$view.add( this.elements.$remove );

			if ( agreementId ) {
				$uiWithoutAgreement.hide();
				$uiWithAgreement.show();
			} else {
				$uiWithoutAgreement.show();
				$uiWithAgreement.hide();
			}

			return this;
		},

		/**
		 * Callback for the Upload button.
		 *
		 * @param event
		 * @returns {wcbSponsors.view.Agreement}
		 */
		upload: function( event ) {
			event.preventDefault();

			this.frame.open();

			return this;
		},

		/**
		 * Callback for the View button.
		 *
		 * @todo Show the agreement file in a modal window instead of opening a new tab
		 *
		view: function( event ) {
			event.preventDefault();

			// ...

			return this;
		},
		*/

		/**
		 * Callback for the Remove button.
		 *
		 * @param event
		 * @returns {wcbSponsors.view.Agreement}
		 */
		remove: function( event ) {
			event.preventDefault();

			this.$input.val( '' );
			this.elements.$view.find( 'a' ).first().attr( 'href', '#' );
			this.toggleElements();

			return this;
		}
	} );

	wcbSponsors.metabox.agreement = new wcbSponsors.view.Agreement( { el: '#sponsor-agreement' } );

} )( jQuery );
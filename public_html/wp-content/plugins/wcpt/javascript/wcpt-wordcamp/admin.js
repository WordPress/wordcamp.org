window.wordCampPostType = window.wordCampPostType || {};

window.wordCampPostType.WcptWordCamp = ( function( $ ) {
	'use strict';

	var self = {};

	/**
	 * Initialize
	 */
	self.initialize = function() {
		var createSiteCheckbox = $( '#wcpt_create-site-in-network' ),
			$mentorUserName = $( '#wcpt_mentor_wordpress_org_user_name' ),
			hasContributor = $( '#wcpt_contributor_day' ),
			$virtualEventCheckbox = $( '#wcpt_virtual_event_only' ),
			$streamingSelection = $( '.field__type-select-streaming' );

		// Sponsor region
		createSiteCheckbox.change( self.toggleSponsorRegionRequired );
		createSiteCheckbox.trigger( 'change' );

		// Contributor day info
		hasContributor.change( self.toggleContributorInfo );
		hasContributor.trigger( 'change' );

		// Date fields
		$( '.date-field' ).datepicker( {
			dateFormat: 'yy-mm-dd',
			changeMonth: true,
			changeYear:  true
		} );

		// Mentor username picker
		if ( $mentorUserName.length && ! $mentorUserName.is( '[readonly]' ) ) {
			self.initializeMentorPicker( $mentorUserName );
		}

		$virtualEventCheckbox.change( self.togglePhysicalVenueFields );
		$virtualEventCheckbox.trigger( 'change' );
		
		$streamingSelection.find( 'select' ).change( function( event ) {
			if ( 'other' === $( event.target ).val() ) {
				$streamingSelection.find( 'input' ).show();
			} else {
				$streamingSelection.find( 'input' ).val( '' ).hide();
			}
		} );
		$streamingSelection.find( 'select' ).trigger( 'change' );
	};

	/**
	 * Toggle the "required" label on the physical address field.
	 *
	 * @param {object} event
	 */
	self.togglePhysicalVenueFields = function( event ) {
		var $container = $( event.target ).closest( '.inside' ).parent();
		var $items = $container
			.find( '.inside' )
			.not( '.field__wcpt_virtual_event_only,.field__wcpt_streaming_account_to_use' );
		if ( $( event.target ).is( ':checked' ) ) {
			$items.hide();
		} else {
			$items.show();
		}
	}

	/**
	 * Toggle whether the Sponsor Region field is required or not.
	 *
	 * \WordCamp_New_Site::maybe_create_new_site() requires it to be set to create a new site.
	 *
	 * @param {object} event
	 */
	self.toggleSponsorRegionRequired = function( event ) {
		var sponsorRegion = $( '#wcpt_multi-event_sponsor_region' );

		if ( $( this ).is( ':checked' ) ) {
			sponsorRegion.prop( 'required', true );
		} else {
			sponsorRegion.prop( 'required', false );
		}
	};

	/**
	 * Insert a Mentor picker after the Mentor username field.
	 *
	 * @param $el jQuery object for the Mentor username field.
	 */
	self.initializeMentorPicker = function( $el ) {
		if ( 'undefined' === typeof window.wordCampPostType.Mentors.data ) {
			return;
		}

		var data     = window.wordCampPostType.Mentors.data,
			l10n     = window.wordCampPostType.Mentors.l10n,
			$select  = $( '<select id="wcpt-mentor-picker"><option></option></select>' ),
			$wrapper = $( '<span class="description">' ),
			$label   = $( '<label>' ).text( l10n.selectLabel );

		$.each( data, function( key, value ) {
			var $option = $( '<option>' );

			$option.val(key)
				.data({
					name: value.name,
					email: value.email
				})
				.text( value.name );

			if ( $option.val() === $el.val() ) {
				$option.prop( 'selected', 'selected' );
			}

			$select.append( $option );
		});

		$wrapper.append( $label )
			.append( $select )
			.insertAfter( $el );

		// Bind events
		$select.on( 'change', function() {
			var $option = $( this ).find( 'option:selected' );
			self.updateMentor( $option );
		});

		if ( $.fn.hasOwnProperty( 'select2' ) ) {
			$select.select2();
		}
	};

	/**
	 * Toggle the display of the Contributor Day Info fields
	 *
	 * @param {object} event
	 */
	self.toggleContributorInfo = function( event ) {

		// Selects all the div enclosing input elements for contributing info,
		// except for the one which has the checkbox with ID wcpt_contributor_day
		var contributorInputElements = $( "#wcpt_contributor_info .inside .inside:not( :has( #wcpt_contributor_day ) )" );

		if ( $( '#wcpt_contributor_day' ).is( ':checked' ) ) {
			contributorInputElements.slideDown();
		} else {
			contributorInputElements.slideUp();
		}

	};

	/**
	 * Update the Mentor fields with the data for the mentor chosen in the picker.
	 *
	 * @param $option jQuery object for the selected option element.
	 */
	self.updateMentor = function( $option ) {
		var l10n            = window.wordCampPostType.Mentors.l10n,
			$mentorUserName = $( '#wcpt_mentor_wordpress_org_user_name' ),
			$mentorName     = $( '#wcpt_mentor_name' ),
			$mentorEmail    = $( '#wcpt_mentor_e-mail_address' );

		// Confirm before changing Mentor field contents
		if ( $option.val() && confirm( l10n.confirm ) ) {
			$mentorUserName.val( $option.val() );
			$mentorName.val( $option.data('name') );
			$mentorEmail.val( $option.data('email') );
		}
	};

	/**
	 * Initialize select2 for currency.
	 */
	$( document ).ready( function initializeSelect2() {
		if ( ! $.fn.hasOwnProperty( 'select2' ) ) {
			return;
		}
		$( '#wcpt_information select' ).select2();
	});

	/**
	 * Bind Update button with Save Draft button in pages with block editor
	 */
	$( document ).ready(
		function () {
			if ( ! window.wcpt_admin || window.wcpt_admin[ 'gutenberg_enabled' ] !== "1" ) {
				return;
			}

			function checkAndEnableUpdateButton() {
				if ( $( ".editor-post-save-draft" ).length > 0 ) {
					$( "#wcpt-update" ).removeAttr( 'disabled' );
				} else {
					setTimeout( checkAndEnableUpdateButton, 500 );
				}
			}

			$( "#wcpt-update" ).click( function() {
				$( ".editor-post-save-draft" ).click();
			} );

			$( "body" ).on( "click", ".editor-post-save-draft", function() {
				$( "#wcpt-update" ).attr( 'disabled', 'disabled' );
				setTimeout( checkAndEnableUpdateButton );
			} );

		}
	);

	/**
	 * Kick things off
	 */
	$( document ).ready( function( $ ) {
		self.initialize();
	} );

	return self;

} ( jQuery ) );

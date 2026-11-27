/* global camptixEmailBanner */
jQuery( function( $ ) {
	var frame;
	var $field = $( '.camptix-email-banner' );

	$field.on( 'click', '.camptix-email-banner-choose', function( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: camptixEmailBanner.title,
				button: { text: camptixEmailBanner.button },
				library: { type: camptixEmailBanner.types },
				multiple: false,
			} );

			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				// Uploads are auto-selected even when the library filter hides their type.
				if ( -1 === camptixEmailBanner.types.indexOf( attachment.mime ) ) {
					return;
				}

				var preview = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium : attachment;

				$field.find( '.camptix-email-banner-id' ).val( attachment.id );
				$field.find( '.camptix-email-banner-preview' ).html(
					$( '<img />', { src: preview.url, alt: '' } ).css( { maxWidth: '300px', height: 'auto' } )
				);
				$field.find( '.camptix-email-banner-remove' ).show();
			} );
		}

		frame.open();
	} );

	$field.on( 'click', '.camptix-email-banner-remove', function( event ) {
		event.preventDefault();

		$field.find( '.camptix-email-banner-id' ).val( '' );
		$field.find( '.camptix-email-banner-preview' ).empty();
		$( this ).hide();
	} );
} );

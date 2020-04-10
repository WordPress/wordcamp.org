/**
 * Handle any frontend activity for the Speaker Feedback forms.
 */
( function( $ ) {
	function onFormNavigate( event ) {
		event.preventDefault();
		var value = event.target[ 0 ].value;
		// Use the fact that post IDs will redirect to the right page.
		window.location = '/?p=' + value + '&sft_feedback=1';
	}

	function onFormSubmit( event ) {
		event.preventDefault();
		var form = event.target;
		var rawData = $( form ).serializeArray().reduce( function( acc, item ) {
			acc[item.name] = item.value;
			return acc;
		}, {} );
		var data = {
			post: rawData['sft-post'],
			meta: {
				rating: rawData['sft-rating'],
				q1: rawData['sft-q1'],
				q2: rawData['sft-q2'],
				q3: rawData['sft-q3'],
			},
		};

		var author = rawData['sft-author'];
		if ( '0' !== author ) {
			data.author = author;
		} else {
			data.author_name = rawData['sft-author-name'];
			data.author_email = rawData['sft-author-email'];
		}

		var $messageContainer = $( document.getElementById( 'speaker-feedback-notice' ) );
		// Reset the notices before submission.
		$messageContainer.removeClass( 'speaker-feedback__notice is-error' );
		$messageContainer.html( '' );
		$( form ).find( '.speaker-feedback__notice.is-error' ).remove();

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback',
			method: 'POST',
			data: data,
		} )
			.then( function() {
				$messageContainer.addClass( 'speaker-feedback__notice is-success' );
				$messageContainer.append( $( '<p>' ).text( SpeakerFeedbackData.messages.submitSuccess ) );
				$( form ).replaceWith( $messageContainer );
			} )
			.catch( function( error ) {
				$messageContainer.addClass( 'speaker-feedback__notice is-error' );
				$messageContainer.append( $( '<p>' ).text( error.message ) );
				if ( error.data ) {
					$.each( error.data, function( key, value ) {
						var field = document.getElementById( 'sft-' + key );
						if ( field.parentElement ) {
							// Create item.
							var item = document.createElement( 'p' );
							item.setAttribute( 'class', 'speaker-feedback__notice is-error' );
							item.id = 'sft-' + key + '-help';
							item.innerText = value;

							// Attach item.
							field.parentElement.insertBefore( item, null );
							field.setAttribute( 'aria-describedby', item.id );
						}
					} );
				}
			} );
	}

	function onHelpfulClick( event ) {
		event.preventDefault();
		var button = event.target;
		var isHelpful = 'true' === button.getAttribute( 'aria-pressed' );

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback/' + button.dataset.commentId,
			method: 'POST',
			data: {
				meta: { helpful: isHelpful ? 'false' : 'true' },
			},
		} )
			.then( function() {
				button.setAttribute( 'aria-pressed', isHelpful ? 'false' : 'true' );
			} );
	}

	var navForm = document.getElementById( 'sft-navigation' );
	if ( navForm ) {
		navForm.addEventListener( 'submit', onFormNavigate, true );
	}

	var feedbackForm = document.getElementById( 'sft-feedback' );
	if ( feedbackForm ) {
		feedbackForm.addEventListener( 'submit', onFormSubmit, true );
	}

	var helpfulButtons = document.querySelectorAll( '.speaker-feedback__helpful button' );
	if ( helpfulButtons.length ) {
		helpfulButtons.forEach( function( el ) {
			el.addEventListener( 'click', onHelpfulClick, true );
		} );
	}

	// Submit the form if any value changes.
	$( '#sft-filter-sort, #sft-filter-helpful' ).change( function( event ) {
		$( event.target ).closest( 'form' ).submit();
	} );
}( jQuery ) );

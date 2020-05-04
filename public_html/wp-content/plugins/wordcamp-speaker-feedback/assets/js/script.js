/**
 * Handle any frontend activity for the Speaker Feedback forms.
 */
( function( $ ) {
	function onFormNavigate( event ) {
		event.preventDefault();
		var value = event.target[ 0 ].value;
		// Use the fact that post IDs will redirect to the right page.
		window.location = '/?p=' + value + '&sft_feedback=1#sft-feedback';
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
				$messageContainer.attr( 'tabIndex', -1 );
				$messageContainer.focus();
				form.scrollIntoView();
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
				$messageContainer.attr( 'tabIndex', -1 );
				$messageContainer.focus();
				form.scrollIntoView();
			} );
	}

	function characterCounter( event ) {
		// Some characters (like 🖖) are represented by a pair of code points, which JS counts as 2 separate
		// characters. In PHP, we use `mb_strlen`, which correctly counts this as 1 character. For the same result
		// in JS, we need to replace the 2-character sequence with a single character, then we can use `.length`
		// to get the correct character count.
		// Note: This counts combined characters (ex: 🧑🏽, ñ) separately, which matches `mb_strlen`'s behavior.
		// @see https://mathiasbynens.be/notes/javascript-unicode#accounting-for-astral-symbols
		var regexAstralSymbols = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;
		var len = event.target.value.replace( regexAstralSymbols, '_' ).length;
		var maxLen = Number( event.target.dataset.maxlength );
		if ( len > maxLen ) {
			$( event.target ).addClass( 'has-error' );
		} else {
			$( event.target ).removeClass( 'has-error' );
		}
		$( event.target ).siblings( '.speaker-feedback__field-help' ).text( len + '/' + maxLen );
	}

	function onHelpfulClick( event ) {
		var $container = $( event.target ).closest( 'footer' );
		if ( $container.hasClass( 'is-inflight' ) ) {
			return;
		}
		$container.addClass( 'is-inflight' );

		var input = $container.find( 'input[type="checkbox"]' ).get( 0 );
		var isHelpful = $container.hasClass( 'is-helpful' );

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback/' + input.dataset.commentId,
			method: 'POST',
			data: {
				meta: { helpful: isHelpful ? 'false' : 'true' },
			},
		} )
			.then( function() {
				$container.removeClass( 'is-inflight' );
				$container.toggleClass( 'is-helpful' );
				if ( isHelpful ) {
					// Previous state was helpful, has been un-marked, label should flip back to "mark as helpful".
					wp.a11y.speak( SpeakerFeedbackData.messages.markedHelpful, 'polite' );
				} else {
					wp.a11y.speak( SpeakerFeedbackData.messages.unmarkedHelpful, 'polite' );
				}
			} );
	}

	var navForm = document.getElementById( 'sft-navigation' );
	if ( navForm ) {
		$( navForm.querySelectorAll( 'select' ) ).select2();
		navForm.addEventListener( 'submit', onFormNavigate, true );
	}

	var feedbackForm = document.getElementById( 'sft-feedback' );
	if ( feedbackForm ) {
		feedbackForm.addEventListener( 'submit', onFormSubmit, true );
		$( feedbackForm ).on( 'keyup', 'textarea[data-maxlength]', lodash.debounce( characterCounter, 250 ) );
	}

	var helpfulButtons = document.querySelectorAll( '.speaker-feedback__helpful input' );
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

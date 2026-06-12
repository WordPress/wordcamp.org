/**
 * Handle any frontend activity for the Speaker Feedback forms.
 *
 * @param {Object} $ jQuery
 */

( function ( $ ) {
	function onFormNavigate( event ) {
		event.preventDefault();
		const value = event.target[ 0 ].value;
		// Use the fact that post IDs will redirect to the right page.
		window.location = SpeakerFeedbackData.url + '/?p=' + value + '&sft_feedback=1#sft-feedback';
	}

	function onFormSubmit( event ) {
		event.preventDefault();
		const form = event.target;
		const rawData = $( form )
			.serializeArray()
			.reduce( function ( acc, item ) {
				acc[ item.name ] = item.value;
				return acc;
			}, {} );
		const data = {
			post: rawData[ 'sft-post' ],
			meta: {
				rating: rawData[ 'sft-rating' ],
				q1: rawData[ 'sft-q1' ],
				q2: rawData[ 'sft-q2' ],
				q3: rawData[ 'sft-q3' ],
			},
		};

		const author = rawData[ 'sft-author' ];
		if ( '0' !== author ) {
			data.author = author;
		} else {
			data.author_name = rawData[ 'sft-author-name' ];
			data.author_email = rawData[ 'sft-author-email' ];
		}

		const $messageContainer = $( document.getElementById( 'speaker-feedback-notice' ) );
		// Reset the notices before submission.
		$messageContainer.removeClass( 'speaker-feedback__notice is-error' );
		$messageContainer.html( '' );
		$( form ).find( '.speaker-feedback__notice.is-error' ).remove();

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback',
			method: 'POST',
			data,
		} )
			.then( function () {
				$messageContainer.addClass( 'speaker-feedback__notice is-success' );
				$messageContainer.append( $( '<p>' ).text( SpeakerFeedbackData.messages.submitSuccess ) );
				$messageContainer.attr( 'tabIndex', -1 );
				$messageContainer.focus();
				form.scrollIntoView();
				$( form ).replaceWith( $messageContainer );
			} )
			.catch( function ( error ) {
				$messageContainer.addClass( 'speaker-feedback__notice is-error' );
				$messageContainer.append( $( '<p>' ).text( error.message ) );
				if ( error.data ) {
					$.each( error.data, function ( key, value ) {
						const field = document.getElementById( 'sft-' + key );
						if ( field.parentElement ) {
							// Create item.
							const item = document.createElement( 'p' );
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
		const regexAstralSymbols = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;
		const len = event.target.value.replace( regexAstralSymbols, '_' ).length;
		const maxLen = Number( event.target.dataset.maxlength );
		if ( len > maxLen ) {
			$( event.target ).addClass( 'has-error' );
		} else {
			$( event.target ).removeClass( 'has-error' );
		}
		$( event.target )
			.siblings( '.speaker-feedback__field-help' )
			.text( len + '/' + maxLen );
	}

	function onNotificationClick( event ) {
		const $container = $( event.target ).closest( 'div' );
		if ( $container.hasClass( 'is-inflight' ) ) {
			return;
		}
		$container.addClass( 'is-inflight' );

		const input = $container.find( 'input[type="checkbox"]' ).get( 0 );
		const isDisabled = $container.hasClass( 'is-disabled' );

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/notifications/' + input.dataset.userId,
			method: 'POST',
			data: {
				speaker_opt_out: isDisabled ? 'false' : 'true',
			},
		} ).then( function () {
			const $labelText = $container.find( '.speaker-feedback__notifications-label-text' ),
				$toggleText = $container.find( '.speaker-feedback__notifications-toggle-text' );

			$container.removeClass( 'is-inflight' );
			$container.toggleClass( 'is-disabled' );
			if ( isDisabled ) {
				// Previous state was disabled, has been enabled.
				$labelText.text( SpeakerFeedbackData.messages.notificationsEnabled );
				$toggleText.text( SpeakerFeedbackData.messages.disableNotifications );
				wp.a11y.speak( SpeakerFeedbackData.messages.notificationsEnabled, 'polite' );
			} else {
				$labelText.text( SpeakerFeedbackData.messages.notificationsDisabled );
				$toggleText.text( SpeakerFeedbackData.messages.enableNotifications );
				wp.a11y.speak( SpeakerFeedbackData.messages.notificationsDisabled, 'polite' );
			}
		} );
	}

	function onHelpfulClick( event ) {
		const $container = $( event.target ).closest( 'footer' );
		if ( $container.hasClass( 'is-inflight' ) ) {
			return;
		}
		$container.addClass( 'is-inflight' );

		const input = $container.find( 'input[type="checkbox"]' ).get( 0 );
		const isHelpful = $container.hasClass( 'is-helpful' );

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback/' + input.dataset.commentId,
			method: 'POST',
			data: {
				meta: { helpful: isHelpful ? 'false' : 'true' },
			},
		} ).then( function () {
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

	const navForm = document.getElementById( 'sft-navigation' );
	if ( navForm ) {
		$( navForm.querySelectorAll( 'select' ) ).select2();
		navForm.addEventListener( 'submit', onFormNavigate, true );
	}

	const feedbackForm = document.getElementById( 'sft-feedback' );
	if ( feedbackForm ) {
		feedbackForm.addEventListener( 'submit', onFormSubmit, true );
		$( feedbackForm ).on( 'keyup', 'textarea[data-maxlength]', lodash.debounce( characterCounter, 250 ) );
	}

	const notificationButtons = document.querySelectorAll( '.speaker-feedback__notifications input' );
	if ( notificationButtons.length ) {
		notificationButtons.forEach( function ( button ) {
			button.addEventListener( 'click', onNotificationClick, true );
		} );
	}

	const helpfulButtons = document.querySelectorAll( '.speaker-feedback__helpful input' );
	if ( helpfulButtons.length ) {
		helpfulButtons.forEach( function ( button ) {
			button.addEventListener( 'click', onHelpfulClick, true );
		} );
	}

	// Submit the form if any value changes.
	$( '#sft-filter-sort, #sft-filter-helpful' ).change( function ( event ) {
		$( event.target ).closest( 'form' ).submit();
	} );
} )( jQuery );

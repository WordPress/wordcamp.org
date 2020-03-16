/**
 * Handle any frontend activity for the Speaker Feedback forms.
 */
( function() {
	function onFormNavigate( event ) {
		event.preventDefault();
		var value = event.target[ 0 ].value;
		// Use the fact that post IDs will redirect to the right page.
		window.location = '/?p=' + value + '&sft_feedback=1';
	}

	function onFormSubmit( event ) {
		event.preventDefault();
		var rawData = new FormData( event.target );
		var data = {
			post: rawData.get( 'sft-post' ),
			author: rawData.get( 'sft-author' ),
			meta: {
				rating: rawData.get( 'sft-rating' ),
				q1: rawData.get( 'sft-question-1' ),
				q2: rawData.get( 'sft-question-2' ),
				q3: rawData.get( 'sft-question-3' ),
			},
		};

		var messageContainer = document.getElementById( 'speaker-feedback-notice' );
		// Reset the notice before submission.
		messageContainer.setAttribute( 'class', '' );
		messageContainer.innerText = '';

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback',
			method: 'POST',
			data: data,
		} )
			.then( function() {
				messageContainer.setAttribute( 'class', 'speaker-feedback__notice is-success' );
				messageContainer.innerText = 'Feedback submitted.';
				event.target.replaceWith( messageContainer );
			} )
			.catch( function( error ) {
				messageContainer.setAttribute( 'class', 'speaker-feedback__notice is-error' );
				messageContainer.innerText = error.message;
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
} )();

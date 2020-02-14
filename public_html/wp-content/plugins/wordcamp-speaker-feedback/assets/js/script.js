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
			rating: rawData.get( 'sft-rating' ),
			q1: rawData.get( 'sft-question-1' ),
			q2: rawData.get( 'sft-question-2' ),
			q3: rawData.get( 'sft-question-3' ),
		};

		wp.apiFetch( {
			path: '/wordcamp-speaker-feedback/v1/feedback',
			method: 'POST',
			data: data,
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

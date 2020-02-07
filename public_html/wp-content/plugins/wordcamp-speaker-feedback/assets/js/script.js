/**
 * Handle any frontend activity for the Speaker Feedback forms.
 */
( function() {
	function onFormNavigate( event ) {
		event.preventDefault();
		const value = event.target[0].value;
		// Use the fact that post IDs will redirect to the right page.
		window.location = '/?p=' + value + '&sft_feedback=1';
	}

	const form = document.getElementById( 'sft-navigation' );
	if ( form ) {
		form.addEventListener( 'submit', onFormNavigate, true );
	}
} )();

/**
 * Add event listener to test button.
 */
function addEventHandler() {
	const testWebhookButton = document.getElementById( 'camptix-webhook-test-url' );

	if ( ! testWebhookButton ) {
		return;
	}

	testWebhookButton.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		try {
			// Get current url.
			const url = new URL( window.location.href );

			// Add search param test_webhook to url.
			url.searchParams.append( 'test_webhook', '1' );

			// Redirect to new url.
			window.location = url.href;
		} catch ( error ) {
			// Do nothing.
		}
	} );
}

// Run event listener when window load.
window.addEventListener( 'load', addEventHandler );

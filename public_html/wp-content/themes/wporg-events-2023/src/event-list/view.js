// Converts server-side Unix timestamps to a human-readable format and client-side timezone.
window.addEventListener( 'load', () => {
	const timeElements = document.querySelectorAll( '.wporg-marker-list__container .wporg-marker-list-item__date-time' );

	timeElements.forEach( ( element ) => {
		const timestamp = element.getAttribute( 'data-wc-events-list-timestamp' );
		const options = {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		};
		const localDate = new Date( parseInt( timestamp ) * 1000 ).toLocaleString( 'en-US', options );
		element.textContent = localDate;
	} );
} );

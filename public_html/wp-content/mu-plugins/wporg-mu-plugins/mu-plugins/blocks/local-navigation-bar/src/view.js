function debounce( callback ) {
	// This holds the requestAnimationFrame reference, so we can cancel it if we wish
	let frame;

	// The debounce function returns a new function that can receive a variable number of arguments
	return ( ...params ) => {
		// If the frame variable has been defined, clear it now, and queue for next frame
		if ( frame ) {
			window.cancelAnimationFrame( frame );
		}

		// Queue our function call for the next frame
		frame = window.requestAnimationFrame( () => {
			// Call our function and pass any params we received
			callback( ...params );
		} );
	};
}

function init() {
	const container = document.querySelector( '.wp-block-wporg-local-navigation-bar' );
	// The div will hit the "sticky" position when the top offset is 0, or if
	// the admin bar exists, 32px (height of admin bar). The bar unstickies
	// on smaller screens, so the admin bar height change does not affect this.
	const topOffset = document.body.classList.contains( 'admin-bar' ) ? 32 : 0;
	if ( container ) {
		const onScroll = () => {
			const { top } = container.getBoundingClientRect();

			if ( top <= topOffset ) {
				container.classList.add( 'is-sticking' );
			} else {
				container.classList.remove( 'is-sticking' );
			}
		};

		document.addEventListener( 'scroll', debounce( onScroll ), { passive: true } );
		onScroll();
	}
}
window.addEventListener( 'load', init );

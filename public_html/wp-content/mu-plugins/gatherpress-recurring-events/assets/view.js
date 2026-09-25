document.querySelectorAll( '.gpre-occurrence-selector' ).forEach( ( selector ) => {
	const list = selector.querySelector( ':scope > ul' );
	const previous = selector.querySelector( '.gpre-occurrence-selector__control.is-previous' );
	const next = selector.querySelector( '.gpre-occurrence-selector__control.is-next' );

	if ( ! list || ! previous || ! next ) {
		return;
	}

	const updateControls = () => {
		const maximum = Math.max( 0, list.scrollWidth - list.clientWidth );
		selector.classList.toggle( 'is-scrollable', maximum > 1 );
		previous.disabled = list.scrollLeft <= 1;
		next.disabled = list.scrollLeft >= maximum - 1;
	};

	const scroll = ( direction ) => {
		list.scrollBy( {
			left: direction * Math.max( 240, list.clientWidth * 0.8 ),
			behavior: 'smooth',
		} );
	};

	previous.addEventListener( 'click', () => scroll( -1 ) );
	next.addEventListener( 'click', () => scroll( 1 ) );
	list.addEventListener( 'scroll', updateControls, { passive: true } );

	const current = list.querySelector( '[aria-current="date"]' );
	if ( current ) {
		const listRect = list.getBoundingClientRect();
		const currentRect = current.getBoundingClientRect();
		list.scrollLeft += currentRect.left + currentRect.width / 2 - ( listRect.left + listRect.width / 2 );
	}

	updateControls();

	if ( 'ResizeObserver' in window ) {
		new window.ResizeObserver( updateControls ).observe( list );
	}
} );

/*
 * Tells GatherPress's own RSVP requests which date they are about.
 *
 * Its `rsvp-template` view module re-renders the attendee list on every page
 * load from `gatherpress/v1/event/rsvp-status-html`, sending only a post ID.
 * On a recurring series that endpoint answers with the whole series' roster,
 * which then replaces the correctly scoped list the server rendered: the
 * flash of the right names followed by everyone who ever RSVPed (#2072).
 *
 * Those modules are upstream code, and they all read one `eventApiUrl` out of
 * a shared interactivity store, so there is no per-block seam to hook and no
 * way to hand them a parameter. Wrapping `fetch` is the one point every one
 * of them passes through. Requests to anything else are handed straight on,
 * untouched.
 */
( () => {
	const occurrence = window.gpreOccurrence;

	if ( ! occurrence || ! occurrence.recurrenceId || ! occurrence.eventApi || 'function' !== typeof window.fetch ) {
		return;
	}

	const originalFetch = window.fetch;

	const withOccurrence = ( input ) => {
		let url;

		try {
			url = new URL( String( input ), window.location.href );
		} catch ( error ) {
			return null;
		}

		if ( ! url.href.startsWith( occurrence.eventApi ) || url.searchParams.has( 'gpre_occurrence' ) ) {
			return null;
		}

		// A site on plain permalinks carries the route in `rest_route`, where
		// rewriting the query would re-encode the route itself. The server
		// falls back to the referring page's URL for those.
		if ( url.searchParams.has( 'rest_route' ) ) {
			return null;
		}

		url.searchParams.set( 'gpre_occurrence', occurrence.recurrenceId );

		return url.href;
	};

	window.fetch = function ( input, init ) {
		if ( 'undefined' !== typeof Request && input instanceof Request ) {
			const url = withOccurrence( input.url );

			return originalFetch.call( this, url ? new Request( url, input ) : input, init );
		}

		const url = withOccurrence( input );

		return originalFetch.call( this, null === url ? input : url, init );
	};
} )();

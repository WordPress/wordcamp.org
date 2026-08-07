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

document.querySelectorAll( '.gpre-occurrence-selector > ul' ).forEach( ( list ) => {
	const current = list.querySelector( '[aria-current="date"]' );
	if ( ! current ) {
		return;
	}

	const listRect = list.getBoundingClientRect();
	const currentRect = current.getBoundingClientRect();
	list.scrollLeft += currentRect.left + currentRect.width / 2 - ( listRect.left + listRect.width / 2 );
} );

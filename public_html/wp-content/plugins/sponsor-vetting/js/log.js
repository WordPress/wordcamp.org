/**
 * Switches the notes and history log between all activity and notes only.
 */
document.querySelectorAll( '[data-sv-log-filter]' ).forEach( ( button ) => {
	button.addEventListener( 'click', () => {
		const box = button.closest( '.inside' );

		box.querySelectorAll( '[data-sv-log-filter]' ).forEach( ( other ) => {
			other.setAttribute( 'aria-pressed', String( other === button ) );
		} );

		box.classList.toggle(
			'sv-log-notes-only',
			'notes' === button.dataset.svLogFilter
		);
	} );
} );

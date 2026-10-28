/**
 * Handles clearing the events search input on the archive page to restore the full list of upcoming events.
 */
( function() {
	function init() {
		const forms = document.querySelectorAll( '.wp-block-search[data-events-search-form]' );
		forms.forEach( function( form ) {
			const input = form.querySelector( 'input[type="search"], input[name="s"]' );
			if ( ! input ) {
				return;
			}
			input.addEventListener( 'search', function() {
				if ( ! this.value ) {
					location.href = form.action;
				}
			} );
			form.addEventListener( 'submit', function( e ) {
				if ( ! input.value.trim() ) {
					e.preventDefault();
					location.href = form.action;
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

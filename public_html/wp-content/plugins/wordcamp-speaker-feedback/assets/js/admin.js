/**
 * Update the `dimAfter` callback for feedback replies. This function is called after a comment is unapproved or
 * approved. In the comments table, it triggers the HTML updates in the admin menu & admin bar (among others).
 * We can override this by passing in our own function, which only handles the top view + count links.
 *
 * See https://core.trac.wordpress.org/browser/trunk/src/js/_enqueues/lib/lists.js?rev=46800#L181
 * See https://core.trac.wordpress.org/browser/trunk/src/js/_enqueues/lib/lists.js?rev=46800#L593
 * See https://core.trac.wordpress.org/browser/trunk/src/js/_enqueues/admin/edit-comments.js?rev=47233#L349
 */

jQuery( document ).ready( function( $ ) {
	// The `.load()` function can't load into itself, so we need to wrap the views with a container.
	var $container = $( '<div>' ).html( $( '.subsubsub' ).get( 0 ).outerHTML );
	$( '.subsubsub' ).replaceWith( $container );

	function loadTopCounts() {
		// Reload the page, but only the top view + count links.
		$container.load( location.href + ' .subsubsub' );
	}

	// We can assume window.theList exists because we're after `edit-comments.js`
	// And if we know that wpList is an object, we can assume the structure of it is stable.
	if ( window.theList[ 0 ] && 'object' === typeof window.theList[ 0 ].wpList ) {
		window.theList[ 0 ].wpList.settings.dimAfter = loadTopCounts;
		window.theList[ 0 ].wpList.settings.delAfter = loadTopCounts;
	}
} );

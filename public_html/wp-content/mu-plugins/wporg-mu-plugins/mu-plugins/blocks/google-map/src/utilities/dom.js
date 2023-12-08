/*
 * WordPress dependencies
 */
import { createRoot, flushSync } from '@wordpress/element';

/**
 * Render the given React element and return its HTML.
 *
 * This is useful when you need to pass an element to the Maps API. The need for it might also be a smell that
 * we're not leveraging the Google Maps React library as much as we could, though.
 *
 * @param {JSX.Element} element
 *
 * @return {string} The HTML for the given element.
 */
export default function getElementHTML( element ) {
	const div = document.createElement( 'div' );
	const root = createRoot( div );

	flushSync( () => {
		root.render( element );
	} );

	return div.innerHTML;
}

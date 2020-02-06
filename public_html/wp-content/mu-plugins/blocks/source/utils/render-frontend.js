/**
 * External dependencies
 */
import { render } from 'react-dom';

const getPropsFallback = () => ( {
	attributes: {},
} );

/**
 * Renders a block component in the place of a specified set of selectors.
 *
 * @param {string}   selector   CSS selector to match the elements to replace.
 * @param {Function} Block      React block to use as a replacement.
 * @param {Function} [getProps] Function to generate the props object for the
 * block.
 */
export default ( selector, Block, getProps = getPropsFallback ) => {
	const containers = document.querySelectorAll( selector );

	if ( containers.length ) {
		// Use Array.forEach for IE11 compatibility
		Array.prototype.forEach.call( containers, ( element ) => {
			const props = getProps( element ) || {};
			const attributes = {
				...element.dataset,
				...props.attributes,
			};

			element.classList.remove( 'is-loading' );

			render( <Block { ...props } attributes={ attributes } />, element );
		} );
	}
};

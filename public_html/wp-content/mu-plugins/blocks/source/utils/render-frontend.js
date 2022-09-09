/**
 * WordPress dependencies
 */
import { Component, createInterpolateElement, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const getPropsFallback = () => ( {
	attributes: {},
} );

/*
 * Catch errors thrown by children and display a friendly error message.
 *
 * Gutenberg does this in the back end via the internal `BlockCrashBoundary` class, but we need to replicate that
 * for the front end.
 */
class FrontBlockCrashBoundary extends Component {
	/**
	 * Constructor.
	 *
	 * @param {Object} props
	 */
	constructor( props ) {
		super( props );

		this.state = { hasError: false };
	}

	/**
	 * Catch errors thrown by children components and update state.
	 *
	 * @return {Object}
	 */
	static getDerivedStateFromError() {
		return { hasError: true };
	}

	/**
	 * Render an error message in place of the child component.
	 *
	 * @return {Element}
	 */
	render() {
		const { hasError } = this.state;
		let content;

		if ( hasError ) {
			content = createInterpolateElement(
				__( 'There was an error trying to render this content, please try another browser or device. If that doesn\'t work, please <a>contact us</a>.', 'wordcamporg' ),
				{
					a: <a href={ 'https://central.wordcamp.org/contact-us/' } >#21441-gutenberg</a>,
				}
			);
		} else {
			content = this.props.block;
		}

		return content;
	}
}

/**
 * Renders a block component in the place of a specified set of selectors.
 *
 * @param {string}   selector   CSS selector to match the elements to replace.
 * @param {Function} Block      React block to use as a replacement.
 * @param {Function} [getProps] Function to generate the props object for the
 *                              block.
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

			render(
				<FrontBlockCrashBoundary
					block={ <Block { ...props } attributes={ attributes } /> }
				/>,
				element
			);
		} );
	}
};

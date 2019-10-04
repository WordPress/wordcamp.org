/* eslint-disable require-jsdoc */
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Component } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import renderFrontend from '../../utils/render-frontend';

class LivePosts extends Component {
	constructor( props ) {
		super( props );
		this.renderInterval = setInterval(
			() => {
				// `forceUpdate` is a React internal that triggers a render cycle.
				this.forceUpdate();
			},
			60 * 1000 // 1 minutes in ms.
		);
	}

	componentWillUnmount() {
		clearInterval( this.renderInterval );
	}

	render() {
		const { attributes } = this.props;
		// Remove the helper data-attribute.
		delete attributes.attributes;

		// Note: the `LoadingResponsePlaceholder` is intentionally an anonymous function to trigger re-render when
		// `forceUpdate` happens.
		return (
			<ServerSideRender
				block="core/latest-posts"
				attributes={ attributes }
				LoadingResponsePlaceholder={ () => ( <p>{ __( 'Loading', 'wordcamporg' ) }</p> ) }
			/>
		);
	}
}

const getAttributesFromData = ( element ) => {
	let parsedAttributes = {};
	const { attributes } = element.dataset;
	if ( attributes ) {
		parsedAttributes = JSON.parse( decodeURIComponent( attributes ) );
	}
	return { attributes: parsedAttributes };
};

renderFrontend( '.wp-block-latest-posts.has-live-update', LivePosts, getAttributesFromData );

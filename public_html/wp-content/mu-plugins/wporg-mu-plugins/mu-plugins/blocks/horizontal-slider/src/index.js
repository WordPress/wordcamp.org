/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Block from './block';

const init = ( containerClassName = 'wporg-horizontal-slider-js' ) => {
	const blockElements = document.getElementsByClassName( containerClassName );

	if ( ! blockElements.length ) {
		return;
	}
	for ( let i = 0; i < blockElements.length; i++ ) {
		const blockEl = blockElements[ i ];
		const items = JSON.parse( blockEl.dataset.items );
		const title = blockEl.dataset.title;

		if ( items.length ) {
			render( <Block items={ items } title={ title } />, blockEl );
		}
	}
};

document.addEventListener( 'DOMContentLoaded', init );

/**
 * We export the init function for parts of the website that are controlled
 * by Third-Party JavaScript so we can re-render.
 */
window.__wporg_horizontal_slider_render = init;

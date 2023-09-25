/**
 * WordPress dependencies
 */
import { createElement, render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Block from './block.js';

const init = ( containerClassName = 'wporg-screenshot-preview-js' ) => {
	const blockElements = document.getElementsByClassName( containerClassName );

	if ( ! blockElements ) {
		return;
	}

	for ( let i = 0; i < blockElements.length; i++ ) {
		const blockEl = blockElements[ i ];

		render( createElement( Block, blockEl.dataset ), blockEl );
	}
};

document.addEventListener( 'DOMContentLoaded', init );

/**
 * We export the init function for parts of the website that are controlled
 * by Third-Party JavaScript so we can re-render.
 */
window.__wporg_screenshot_preview_render = init;

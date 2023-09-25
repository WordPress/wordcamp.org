/* global wporgGoogleMap */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Main from './components/main';

const init = () => {
	const wrapper = document.getElementById( wporgGoogleMap.id );

	if ( ! wrapper ) {
		throw "Map container element isn't present in the DOM.";
	}

	const root = createRoot( wrapper );

	root.render( <Main { ...wporgGoogleMap } /> );
};

document.addEventListener( 'DOMContentLoaded', init );

/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.css';
import LiveSchedule from './block.js';

const containers = document.querySelectorAll(
	'.wp-block-wordcamp-live-schedule'
);

if ( containers.length ) {
	Array.prototype.forEach.call( containers, ( element ) => {
		render( <LiveSchedule config={ window.blockLiveSchedule } />, element );
	} );
}


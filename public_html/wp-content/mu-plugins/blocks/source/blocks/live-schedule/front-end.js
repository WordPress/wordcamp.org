/**
 * Internal dependencies
 */
import './front-end.scss';
import './edit.scss';
import LiveSchedule from './block.js';
import renderFrontend from '../../utils/render-frontend';

renderFrontend( '.wp-block-wordcamp-live-schedule', LiveSchedule, ( element ) => {
	const rawAttributes = element.dataset || {};

	return {
		attributes: {
			level: Number( rawAttributes.level ),
			next: rawAttributes.next,
			now: rawAttributes.now,
		},
		config: window.WordCampBlocks[ 'live-schedule' ],
	};
} );

/**
 * Internal dependencies
 */
import './front-end.scss';
import './edit.scss';
import LiveSchedule from './block.js';
import renderFrontend from '../../utils/render-frontend';

renderFrontend( '.wp-block-wordcamp-live-schedule', LiveSchedule, () => ( {
	config: window.WordCampBlocks[ 'live-schedule' ],
} ) );

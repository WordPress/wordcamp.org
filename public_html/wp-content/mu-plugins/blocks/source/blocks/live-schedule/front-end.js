/**
 * Internal dependencies
 */
import './style.css';
import LiveSchedule from './block.js';
import renderFrontend from '../../utils/render-frontend';

renderFrontend( '.wp-block-wordcamp-live-schedule', LiveSchedule, () => ( {
	config: window.blockLiveSchedule,
} ) );

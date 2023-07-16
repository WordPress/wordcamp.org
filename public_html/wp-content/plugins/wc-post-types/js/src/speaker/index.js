/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SpeakerInfoPanel from './panel-info';

registerPlugin( 'wordcamp-speaker-settings', {
	render: () => <SpeakerInfoPanel />,
	icon: '',
} );

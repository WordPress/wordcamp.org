/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SessionInfo from './panel-info';
import SessionSpeakers from './panel-speakers';

registerPlugin( 'wordcamp-session-settings', {
	render: () => (
		<>
			<SessionInfo />
			<SessionSpeakers />
		</>
	),
	icon: '',
} );

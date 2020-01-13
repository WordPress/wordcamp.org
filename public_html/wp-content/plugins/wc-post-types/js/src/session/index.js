/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SessionInfo from './info';
import SessionSpeakers from './speakers';

registerPlugin( 'wordcamp-session-settings', {
	render: () => (
		<>
			<SessionInfo />
			<SessionSpeakers />
		</>
	),
	icon: '',
} );

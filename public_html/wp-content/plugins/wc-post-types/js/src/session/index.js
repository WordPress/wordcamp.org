/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SessionInfo from './info';
// @todo bring in speakers.

registerPlugin( 'wordcamp-session-settings', {
	render: () => (
		<>
			<SessionInfo />
		</>
	),
	icon: '',
} );

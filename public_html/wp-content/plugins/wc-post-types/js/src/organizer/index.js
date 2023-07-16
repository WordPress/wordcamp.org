/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import OrganizerInfoPanel from './panel-info';

registerPlugin( 'wordcamp-organizer-settings', {
	render: () => <OrganizerInfoPanel />,
	icon: '',
} );

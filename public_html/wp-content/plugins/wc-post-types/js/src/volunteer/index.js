/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import VolunteerInfoPanel from './panel-info';

registerPlugin( 'wordcamp-volunteer-settings', {
	render: () => <VolunteerInfoPanel />,
	icon: '',
} );

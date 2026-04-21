/**
 * Group Settings — Design tab.
 *
 * Placeholder for accent color, logo, and hero image settings.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement as h } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function DesignTab() {
	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		h( 'p', {}, __( 'Design settings coming soon — accent colors, logo, and hero image.', 'wporg-groups-frontend' ) )
	);
}

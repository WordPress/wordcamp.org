/**
 * Group Settings — Design tab.
 *
 * Links to the Full Site Editor for visual customisation.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement as h } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function DesignTab() {
	const editorUrl = window.wporgGroupsEventModal?.siteEditorUrl || '/wp-admin/site-editor.php';

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		h( 'p', {},
			__( 'Use the WordPress Site Editor to customize your group site — change colors, fonts, the hero image, page layouts, and more.', 'wporg-groups-frontend' )
		),
		h(
			Button,
			{
				variant: 'primary',
				href: editorUrl,
				target: '_self',
			},
			__( 'Open Site Editor', 'wporg-groups-frontend' )
		)
	);
}

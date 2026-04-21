/**
 * Group Settings block — view script entry point.
 *
 * Listens for clicks on settings trigger buttons and mounts the
 * SettingsApp React component into the mount point.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import SettingsApp from './components/settings-app';

( function () {
	const container = document.getElementById( 'wporg-group-settings-root' );
	if ( ! container ) {
		return;
	}

	let root = null;

	function openSettings( initialTab, eventId ) {
		if ( root ) {
			return;
		}

		root = createRoot( container );
		root.render(
			createElement( SettingsApp, {
				initialTab: initialTab || 'events',
				eventId: eventId || 0,
				onClose: () => {
					if ( root ) {
						root.unmount();
						root = null;
					}
				},
			} )
		);
	}

	document.addEventListener( 'click', ( ev ) => {
		const trigger = ev.target.closest( '[data-wporg-settings-open]' );
		if ( ! trigger ) {
			return;
		}

		ev.preventDefault();
		const tab = trigger.dataset.wporgSettingsOpen || '';
		const eventId = parseInt( trigger.dataset.wporgSettingsEventId || '0', 10 );
		openSettings( tab, eventId );
	} );
} )();

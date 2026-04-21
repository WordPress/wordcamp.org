/**
 * Group Settings block — view script entry point.
 *
 * Listens for clicks on settings trigger buttons and mounts the
 * SettingsApp React component into the mount point.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement, render } from '@wordpress/element';
import SettingsApp from './components/settings-app';

( function () {
	const root = document.getElementById( 'wporg-group-settings-root' );
	if ( ! root ) {
		return;
	}

	let mounted = false;

	function openSettings( initialTab, eventId ) {
		if ( mounted ) {
			return;
		}
		mounted = true;

		render(
			createElement( SettingsApp, {
				initialTab: initialTab || 'events',
				eventId: eventId || 0,
				onClose: () => {
					render( null, root );
					mounted = false;
				},
			} ),
			root
		);
	}

	// Listen for any trigger button clicks.
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

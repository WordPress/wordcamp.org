/**
 * Group Settings block — view script entry point.
 *
 * Listens for clicks on settings trigger buttons and mounts the
 * SettingsApp React component into the mount point.
 *
 * Uses the same mount pattern as the working event-manage block.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	render,
	useState,
} from '@wordpress/element';
import SettingsApp from './components/settings-app';

( function () {
	const root = document.getElementById( 'wporg-group-settings-root' );
	if ( ! root ) {
		return;
	}

	const siteName = root.dataset.siteName || '';

	function SettingsRoot() {
		const [ isOpen, setIsOpen ] = useState( false );
		const [ initialTab, setInitialTab ] = useState( '' );
		const [ eventId, setEventId ] = useState( 0 );

		// Listen for trigger clicks via a global handler that sets state.
		if ( ! window.__wporgSettingsOpen ) {
			window.__wporgSettingsOpen = ( tab, evId ) => {
				// This will be replaced by the latest component instance.
			};

			document.addEventListener( 'click', ( ev ) => {
				const trigger = ev.target.closest( '[data-wporg-settings-open]' );
				if ( ! trigger ) {
					return;
				}
				ev.preventDefault();
				window.__wporgSettingsOpen(
					trigger.dataset.wporgSettingsOpen || '',
					parseInt( trigger.dataset.wporgSettingsEventId || '0', 10 )
				);
			} );
		}

		// Update the global handler to point to this component instance.
		window.__wporgSettingsOpen = ( tab, evId ) => {
			setInitialTab( tab );
			setEventId( evId );
			setIsOpen( true );
		};

		if ( ! isOpen ) {
			return null;
		}

		return h( SettingsApp, {
			initialTab: initialTab || 'events',
			eventId,
			siteName,
			onClose: () => setIsOpen( false ),
		} );
	}

	render( h( SettingsRoot ), root );
} )();

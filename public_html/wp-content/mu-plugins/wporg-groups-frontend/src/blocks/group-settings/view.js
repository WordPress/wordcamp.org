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
	useEffect,
	useState,
} from '@wordpress/element';
import SettingsApp from './components/settings-app';
import 'leaflet/dist/leaflet.css';

( function () {
	const root = document.getElementById( 'wporg-group-settings-root' );
	if ( ! root ) {
		return;
	}

	const siteName = root.dataset.siteName || '';
	const canManageRoles = root.dataset.canManageRoles === 'true';

	function SettingsRoot() {
		const [ isOpen, setIsOpen ] = useState( false );
		const [ initialTab, setInitialTab ] = useState( '' );
		const [ eventId, setEventId ] = useState( 0 );

		useEffect( () => {
			const previousOpen = window.__wporgSettingsOpen;
			const openSettings = ( tab, evId ) => {
				setInitialTab( tab );
				setEventId( evId );
				setIsOpen( true );
			};
			const handleClick = ( ev ) => {
				const target = ev.target instanceof Element ? ev.target : null;
				const trigger = target?.closest( '[data-wporg-settings-open]' );
				if ( ! trigger ) {
					return;
				}
				ev.preventDefault();
				openSettings(
					trigger.dataset.wporgSettingsOpen || '',
					parseInt( trigger.dataset.wporgSettingsEventId || '0', 10 )
				);
			};

			window.__wporgSettingsOpen = openSettings;
			document.addEventListener( 'click', handleClick );

			return () => {
				document.removeEventListener( 'click', handleClick );
				if ( window.__wporgSettingsOpen === openSettings ) {
					if ( previousOpen ) {
						window.__wporgSettingsOpen = previousOpen;
					} else {
						delete window.__wporgSettingsOpen;
					}
				}
			};
		}, [] );

		if ( ! isOpen ) {
			return null;
		}

		return h( SettingsApp, {
			initialTab: initialTab || 'events',
			eventId,
			siteName,
			canManageRoles,
			onClose: () => setIsOpen( false ),
		} );
	}

	render( h( SettingsRoot ), root );
} )();

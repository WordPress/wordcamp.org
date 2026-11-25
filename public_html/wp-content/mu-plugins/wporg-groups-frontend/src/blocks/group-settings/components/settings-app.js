/**
 * Group Settings — main app component.
 *
 * Renders a full-screen modal with tabbed navigation for all group
 * management features. Uses @wordpress/components Modal and TabPanel.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import { Modal, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import EventsTab from './events-tab';
import VenuesTab from './venues-tab';
import MembersTab from './members-tab';
import DesignTab from './design-tab';
import AboutTab from './about-tab';
import ExportTab from './export-tab';

const TABS = [
	{ name: 'events', title: __( 'Events', 'wordcamporg' ) },
	{ name: 'venues', title: __( 'Venues', 'wordcamporg' ) },
	{ name: 'members', title: __( 'Members', 'wordcamporg' ) },
	{ name: 'design', title: __( 'Design', 'wordcamporg' ) },
	{ name: 'about', title: __( 'About', 'wordcamporg' ) },
	{ name: 'export', title: __( 'Export', 'wordcamporg' ) },
];

export default function SettingsApp( {
	onClose,
	initialTab,
	siteName,
	canManageRoles,
} ) {
	const [ activeTab, setActiveTab ] = useState( initialTab || 'events' );

	// Global escape handler — prompt before closing if needed.
	useEffect( () => {
		const onEscape = ( ev ) => {
			// While a modal is open on top of Settings, like "Message all
			// members", Escape is that modal's to handle, wherever focus is.
			if ( document.querySelector( '.components-modal__frame:not(.wporg-group-settings-modal)' ) ) {
				return;
			}

			if ( ev.key === 'Escape' ) {
				ev.stopPropagation();
				ev.preventDefault();
				onClose();
			}
		};
		document.addEventListener( 'keydown', onEscape, true );
		return () => document.removeEventListener( 'keydown', onEscape, true );
	}, [ onClose ] );

	const renderTab = useCallback(
		( tab ) => {
			switch ( tab.name ) {
				case 'events':
					return h( EventsTab );
				case 'venues':
					return h( VenuesTab );
				case 'members':
					return h( MembersTab, { canManageRoles } );
				case 'design':
					return h( DesignTab );
				case 'about':
					return h( AboutTab );
				case 'export':
					return h( ExportTab );
				default:
					return null;
			}
		},
		[ canManageRoles ]
	);

	return h(
		Modal,
		{
			title: siteName || __( 'Group Settings', 'wordcamporg' ),
			onRequestClose: onClose,
			className: 'wporg-groups-modal-accent wporg-group-settings-modal',
			isFullScreen: true,
			shouldCloseOnClickOutside: false,
		},
		h(
			TabPanel,
			{
				className: 'wporg-group-settings-modal__tabs',
				tabs: TABS,
				initialTabName: activeTab,
				onSelect: setActiveTab,
			},
			renderTab
		)
	);
}

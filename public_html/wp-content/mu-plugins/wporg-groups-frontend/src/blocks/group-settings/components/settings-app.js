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

const TABS = [
	{ name: 'events', title: __( 'Events', 'wporg-groups-frontend' ) },
	{ name: 'venues', title: __( 'Venues', 'wporg-groups-frontend' ) },
	{ name: 'members', title: __( 'Members', 'wporg-groups-frontend' ) },
	{ name: 'design', title: __( 'Design', 'wporg-groups-frontend' ) },
	{ name: 'about', title: __( 'About', 'wporg-groups-frontend' ) },
];

export default function SettingsApp( {
	onClose,
	initialTab,
	eventId,
	siteName,
	canManageRoles,
} ) {
	const [ activeTab, setActiveTab ] = useState( initialTab || 'events' );

	// Global escape handler — prompt before closing if needed.
	useEffect( () => {
		const onEscape = ( ev ) => {
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
					return h( EventsTab, { eventId, onClose } );
				case 'venues':
					return h( VenuesTab );
				case 'members':
					return h( MembersTab, { canManageRoles } );
				case 'design':
					return h( DesignTab );
				case 'about':
					return h( AboutTab );
				default:
					return null;
			}
		},
		[ eventId, onClose, canManageRoles ]
	);

	return h(
		Modal,
		{
			title: siteName || __( 'Group Settings', 'wporg-groups-frontend' ),
			onRequestClose: onClose,
			className: 'wporg-group-settings-modal',
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

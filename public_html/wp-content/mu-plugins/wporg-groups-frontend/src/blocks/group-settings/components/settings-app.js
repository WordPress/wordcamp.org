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
	{ name: 'events', title: __( 'Events', 'wporg-groups-frontend' ) },
	{ name: 'venues', title: __( 'Venues', 'wporg-groups-frontend' ) },
	{ name: 'members', title: __( 'Members', 'wporg-groups-frontend' ) },
	{ name: 'design', title: __( 'Design', 'wporg-groups-frontend' ) },
	{ name: 'about', title: __( 'About', 'wporg-groups-frontend' ) },
	{ name: 'export', title: __( 'Export', 'wporg-groups-frontend' ) },
];

export default function SettingsApp( {
	onClose,
	initialTab,
	eventId,
	siteName,
	canManageRoles,
} ) {
	const [ activeTab, setActiveTab ] = useState( initialTab || 'events' );

	/*
	 * An event id means the visitor arrived from "Edit this event" on the
	 * event page, so the modal is that one event's editing surface and
	 * nothing else. The group-wide tabs only invite a wander into unrelated
	 * configuration there (#2073); the group page's Settings button is still
	 * the route to them.
	 */
	const isSingleEvent = !! eventId;

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
				case 'export':
					return h( ExportTab );
				default:
					return null;
			}
		},
		[ eventId, onClose, canManageRoles ]
	);

	return h(
		Modal,
		{
			title: isSingleEvent
				? __( 'Edit event', 'wporg-groups-frontend' )
				: siteName || __( 'Group Settings', 'wporg-groups-frontend' ),
			onRequestClose: onClose,
			className: 'wporg-groups-modal-accent wporg-group-settings-modal',
			isFullScreen: true,
			shouldCloseOnClickOutside: false,
		},
		isSingleEvent
			? h(
				'div',
				{ className: 'wporg-group-settings-modal__single' },
				h( EventsTab, { eventId, onClose, singleEvent: true } )
			)
			: h(
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

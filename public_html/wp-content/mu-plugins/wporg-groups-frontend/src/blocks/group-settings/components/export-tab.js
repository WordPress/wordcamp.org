/**
 * Group Settings — Export tab.
 *
 * Downloads the group's event + RSVP history as CSV or JSON, with optional
 * column selection, event selection, and date-range filtering — same shape
 * as WooCommerce's product exporter. Empty selections mean "everything".
 * Access is enforced server-side; anyone who can open this modal is an
 * Organizer.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import {
	Button,
	Notice,
	FormTokenField,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

// Keys mirror the endpoint's CSV_COLUMNS; labels are what the picker shows.
const COLUMN_OPTIONS = [
	{ key: 'event_id', label: __( 'Event ID', 'wporg-groups-frontend' ) },
	{ key: 'event_title', label: __( 'Event title', 'wporg-groups-frontend' ) },
	{ key: 'event_start_gmt', label: __( 'Event start (GMT)', 'wporg-groups-frontend' ) },
	{ key: 'event_end_gmt', label: __( 'Event end (GMT)', 'wporg-groups-frontend' ) },
	{ key: 'venue', label: __( 'Venue', 'wporg-groups-frontend' ) },
	{ key: 'organiser', label: __( 'Organizer', 'wporg-groups-frontend' ) },
	{ key: 'attending_count', label: __( 'Attending count', 'wporg-groups-frontend' ) },
	{ key: 'waiting_list_count', label: __( 'Waiting list count', 'wporg-groups-frontend' ) },
	{ key: 'not_attending_count', label: __( 'Not attending count', 'wporg-groups-frontend' ) },
	{ key: 'occurrence_start_gmt', label: __( 'Occurrence start (GMT)', 'wporg-groups-frontend' ) },
	{ key: 'occurrence_end_gmt', label: __( 'Occurrence end (GMT)', 'wporg-groups-frontend' ) },
	{ key: 'attendee_name', label: __( 'Attendee name', 'wporg-groups-frontend' ) },
	{ key: 'attendee_login', label: __( 'Attendee username', 'wporg-groups-frontend' ) },
	{ key: 'rsvp_status', label: __( 'RSVP status', 'wporg-groups-frontend' ) },
	{ key: 'rsvp_timestamp_gmt', label: __( 'RSVP timestamp (GMT)', 'wporg-groups-frontend' ) },
	{ key: 'rsvp_guests', label: __( 'RSVP guests', 'wporg-groups-frontend' ) },
];

const RANGE_OPTIONS = [
	{ label: __( 'All events', 'wporg-groups-frontend' ), value: 'all' },
	{ label: __( 'Upcoming events', 'wporg-groups-frontend' ), value: 'upcoming' },
	{ label: __( 'Past events', 'wporg-groups-frontend' ), value: 'past' },
	{ label: __( 'Custom date range', 'wporg-groups-frontend' ), value: 'custom' },
];

// The "(#123)" suffix keeps tokens unambiguous when a recurring meetup
// reuses the same title.
const eventToken = ( event ) => `${ event.title } (#${ event.id })`;

export default function ExportTab() {
	const [ downloading, setDownloading ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ columns, setColumns ] = useState( [] );
	const [ eventIds, setEventIds ] = useState( [] );
	const [ eventOptions, setEventOptions ] = useState( [] );
	const [ range, setRange ] = useState( 'all' );
	const [ after, setAfter ] = useState( '' );
	const [ before, setBefore ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		// Walk every page so long-running groups can pick any event, the same
		// X-WP-TotalPages pattern the members tab paginates with.
		const loadAllEvents = async () => {
			const collected = [];
			let page = 1;
			let totalPages = 1;

			do {
				const response = await apiFetch( {
					path: `/wp/v2/gatherpress_events?per_page=100&page=${ page }&_fields=id,title&status=publish&orderby=date&order=desc`,
					parse: false,
				} );
				const batch = await response.json();
				collected.push(
					...batch.map( ( e ) => ( { id: e.id, title: e.title.rendered } ) )
				);
				totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1;
				page++;
			} while ( page <= totalPages );

			if ( ! cancelled ) {
				setEventOptions( collected );
			}
		};

		loadAllEvents().catch( () => {
			// The picker degrades to "all events"; downloads still work.
			if ( ! cancelled ) {
				setEventOptions( [] );
			}
		} );

		return () => {
			cancelled = true;
		};
	}, [] );

	const downloadExport = useCallback(
		async ( format ) => {
			setDownloading( format );
			setNotice( '' );

			const params = new URLSearchParams( { format, range } );
			columns.forEach( ( key ) => params.append( 'columns[]', key ) );
			eventIds.forEach( ( id ) => params.append( 'events[]', String( id ) ) );
			if ( range === 'custom' ) {
				if ( after ) {
					params.set( 'after', after );
				}
				if ( before ) {
					params.set( 'before', before );
				}
			}

			try {
				const response = await apiFetch( {
					path: `/wporg-groups/v1/export?${ params.toString() }`,
					parse: false,
				} );
				const blob = await response.blob();

				const disposition = response.headers.get( 'Content-Disposition' ) || '';
				const match = disposition.match( /filename="?([^";]+)"?/ );
				const filename = match ? match[ 1 ] : `group-events-export.${ format }`;

				const url = window.URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = filename;
				document.body.appendChild( link );
				link.click();
				link.remove();
				window.URL.revokeObjectURL( url );
			} catch ( err ) {
				// With `parse: false`, apiFetch rejects with the raw Response —
				// the server's error message is in its (JSON) body.
				let message = err?.message;
				if ( ! message && typeof err?.json === 'function' ) {
					try {
						message = ( await err.json() ).message;
					} catch ( parseErr ) {
						// Non-JSON error body; fall through to the generic notice.
					}
				}
				setNotice( message || __( 'Export failed.', 'wporg-groups-frontend' ) );
			} finally {
				setDownloading( '' );
			}
		},
		[ columns, eventIds, range, after, before ]
	);

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h( Notice, { status: 'error', isDismissible: true, onDismiss: () => setNotice( '' ) }, notice ),
		h( 'p', {},
			__(
				'Download this group’s event history, including the RSVP breakdown for every event. Anonymous RSVPs are included as a non-identifying token instead of the member’s name.',
				'wporg-groups-frontend'
			)
		),
		h( 'div', { className: 'wporg-export-tab__field' },
			h( FormTokenField, {
				label: __( 'Which columns should be exported?', 'wporg-groups-frontend' ),
				placeholder: columns.length
					? undefined
					: __( 'All columns', 'wporg-groups-frontend' ),
				value: columns.map(
					( key ) => COLUMN_OPTIONS.find( ( option ) => option.key === key ).label
				),
				suggestions: COLUMN_OPTIONS.map( ( option ) => option.label ),
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				onChange: ( tokens ) =>
					setColumns(
						tokens
							.map( ( token ) => COLUMN_OPTIONS.find( ( option ) => option.label === token )?.key )
							.filter( Boolean )
					),
			} )
		),
		h( 'div', { className: 'wporg-export-tab__field' },
			h( FormTokenField, {
				label: __( 'Which events should be exported?', 'wporg-groups-frontend' ),
				placeholder: eventIds.length
					? undefined
					: __( 'All events', 'wporg-groups-frontend' ),
				value: eventIds.map( ( id ) => {
					const event = eventOptions.find( ( option ) => option.id === id );
					return event ? eventToken( event ) : `#${ id }`;
				} ),
				suggestions: eventOptions.map( eventToken ),
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				onChange: ( tokens ) =>
					setEventIds(
						tokens
							.map( ( token ) => {
								const match = token.match( /\(#(\d+)\)\s*$/ );
								return match ? Number( match[ 1 ] ) : null;
							} )
							.filter( Boolean )
					),
			} )
		),
		h( 'div', { className: 'wporg-export-tab__field' },
			h( SelectControl, {
				label: __( 'Which dates should be exported?', 'wporg-groups-frontend' ),
				value: range,
				options: RANGE_OPTIONS,
				onChange: setRange,
				__nextHasNoMarginBottom: true,
			} )
		),
		range === 'custom' &&
			h( 'div', { className: 'wporg-export-tab__field wporg-export-tab__dates' },
				h( TextControl, {
					label: __( 'Starting on or after', 'wporg-groups-frontend' ),
					type: 'date',
					value: after,
					onChange: setAfter,
					__nextHasNoMarginBottom: true,
				} ),
				h( TextControl, {
					label: __( 'Starting on or before', 'wporg-groups-frontend' ),
					type: 'date',
					value: before,
					onChange: setBefore,
					__nextHasNoMarginBottom: true,
				} )
			),
		h(
			'div',
			{ className: 'wporg-export-tab__buttons' },
			h(
				Button,
				{
					variant: 'primary',
					isBusy: downloading === 'csv',
					disabled: !! downloading,
					onClick: () => downloadExport( 'csv' ),
				},
				__( 'Download CSV', 'wporg-groups-frontend' )
			),
			h(
				Button,
				{
					variant: 'secondary',
					isBusy: downloading === 'json',
					disabled: !! downloading,
					onClick: () => downloadExport( 'json' ),
				},
				__( 'Download JSON', 'wporg-groups-frontend' )
			)
		)
	);
}

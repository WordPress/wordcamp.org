/**
 * Group Settings — Events tab.
 *
 * Lists events with create/edit. The event form is shown inline
 * when creating or editing.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	Fragment,
	useState,
	useEffect,
	useRef,
} from '@wordpress/element';
import {
	Button,
	Spinner,
	FormTokenField,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import VenueEditor from '../../event-manage/venue-editor';
import EventForm, { NS } from '../../../components/event-form/event-form';


// === Event Form (inline) ===

function InlineEventForm( { eventId, onDone, onCancel } ) {
	const isEdit = !! eventId;
	const [ loading, setLoading ] = useState( true );
	const [ venueEditorId, setVenueEditorId ] = useState( null );
	const [ speakers, setSpeakers ] = useState( [] );
	const [ memberOptions, setMemberOptions ] = useState( [] );
	const formRef = useRef( null );

	// Load speakers for this event and member list for autocomplete.
	useEffect( () => {
		Promise.all( [
			eventId
				? apiFetch( { path: `/wp/v2/gatherpress_events/${ eventId }?_fields=meta` } )
					.then( ( ev ) => ev.meta?._event_speakers || [] )
					.catch( () => [] )
				: Promise.resolve( [] ),
			apiFetch( { path: `/${ NS }/members?per_page=200` } )
				.catch( () => [] ),
		] ).then( ( [ speakerIds, members ] ) => {
			setSpeakers( speakerIds );
			setMemberOptions( members );
			setLoading( false );
		} );
	}, [ eventId ] );

	if ( loading ) return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );

	const submitPayload = async ( payload ) => {
		const result = await apiFetch( {
			path: isEdit ? `/${ NS }/event/${ eventId }` : `/${ NS }/event`,
			method: 'POST',
			data: payload,
		} );

		// Save speakers meta.
		if ( result.id ) {
			await apiFetch( {
				path: `/wp/v2/gatherpress_events/${ result.id }`,
				method: 'POST',
				data: { meta: { _event_speakers: speakers } },
			} ).catch( () => {} );
		}

		if ( result.permalink ) {
			window.location.href = result.permalink;
		} else {
			onDone();
		}
	};

	const venueEditorOpen = venueEditorId !== null;

	// The venue editor is a sibling, not a replacement, so the form (and the
	// description editor's block state) stays mounted while it's open.
	return h( Fragment, {},
		venueEditorOpen && h( 'div', { className: 'wporg-settings-tab' },
			h( VenueEditor, {
				venueId: venueEditorId, inline: true,
				onSave: ( saved ) => {
					setVenueEditorId( null );
					formRef.current.selectVenue( saved );
				},
				onCancel: () => setVenueEditorId( null ),
			} )
		),
		h( 'div', { hidden: venueEditorOpen },
			h( EventForm, {
				ref: formRef,
				mode: isEdit ? 'edit' : 'create',
				eventId,
				classPrefix: 'wporg-event-form',
				className: 'wporg-event-form',
				onSubmitPayload: submitPayload,
				onCancel,
				onOpenVenueEditor: ( id ) => setVenueEditorId( id ),
				header: h( 'div', { className: 'wporg-event-form__header' },
					h( Button, { variant: 'tertiary', onClick: onCancel, icon: 'arrow-left-alt2' }, __( 'Back to events', 'wporg-groups-frontend' ) ),
				),
			},
				h( 'div', { className: 'wporg-event-form__field' },
					h( FormTokenField, {
						label: __( 'Speakers', 'wporg-groups-frontend' ),
						value: speakers.map( ( id ) => {
							const member = memberOptions.find( ( m ) => m.id === id );
							return member ? member.name : String( id );
						} ),
						suggestions: memberOptions.map( ( m ) => m.name ),
						onChange: ( tokens ) => {
							const ids = tokens.map( ( token ) => {
								const member = memberOptions.find( ( m ) => m.name === token );
								return member ? member.id : null;
							} ).filter( Boolean );
							setSpeakers( ids );
						},
						__experimentalExpandOnFocus: true,
						__nextHasNoMarginBottom: true,
					} )
				)
			)
		)
	);
}

// === Events Tab (list + form) ===

export default function EventsTab( { eventId: initialEventId, onClose } ) {
	const [ events, setEvents ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ editingId, setEditingId ] = useState( initialEventId || null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ cloning, setCloning ] = useState( false );

	useEffect( () => {
		if ( editingId !== null ) return;
		setLoading( true );
		apiFetch( { path: '/wp/v2/gatherpress_events?per_page=100&status=publish,draft&_fields=id,title,meta,status&orderby=date&order=desc' } )
			.then( ( data ) => {
				setEvents( data.map( ( e ) => {
					const dtStart = e.meta?.gatherpress_datetime_start || '';
					return {
						id: e.id,
						title: e.title.rendered,
						status: e.status,
						dateStart: dtStart,
						isPast: dtStart ? new Date( dtStart ) < new Date() : false,
					};
				} ) );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [ refreshKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const backToList = () => {
		setEditingId( null );
		setRefreshKey( ( k ) => k + 1 );
	};

	if ( editingId !== null ) {
		return h( InlineEventForm, {
			eventId: editingId === 0 ? 0 : editingId,
			onDone: backToList,
			onCancel: backToList,
		} );
	}

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	const cloneEvent = async ( sourceId ) => {
		setCloning( true );
		try {
			const params = `?event_id=${ sourceId }`;
			const res = await apiFetch( { path: `/${ NS }/event-form-data${ params }` } );
			// Create a draft with the source data but a new date.
			const draftData = {
				title: res.fields.title + ' ' + __( '(copy)', 'wporg-groups-frontend' ),
				description: res.fields.description || '',
				time_start: res.fields.time_start || '',
				time_end: res.fields.time_end || '',
				venue_id: res.fields.venue_id || 0,
				is_online: !! res.fields.is_online,
				online_event_link: res.fields.online_event_link || '',
				featured_image_id: res.fields.featured_image_id || 0,
			};
			const result = await apiFetch( {
				path: `/${ NS }/draft`,
				method: 'POST',
				data: draftData,
			} );
			if ( result.id ) {
				setEditingId( result.id );
			}
		} catch {
			// Silently fail.
		} finally {
			setCloning( false );
		}
	};

	const drafts = events.filter( ( e ) => e.status === 'draft' );
	const upcoming = events.filter( ( e ) => ! e.isPast && e.status !== 'draft' );
	const past = events.filter( ( e ) => e.isPast && e.status !== 'draft' );

	const renderEventItem = ( event, showClone ) =>
		h( 'div', {
			key: event.id,
			className: 'wporg-settings-tab__list-item',
			onClick: () => setEditingId( event.id ),
			role: 'button',
			tabIndex: 0,
			onKeyDown: ( ev ) => { if ( ev.key === 'Enter' ) setEditingId( event.id ); },
		},
			h( 'div', { className: 'wporg-settings-tab__list-item-info' },
				h( 'strong', {}, event.title ),
				h( 'span', {},
					event.dateStart ? formatEventDate( event.dateStart ) : '',
					event.status === 'draft' ? ( event.dateStart ? ' — ' : '' ) + __( 'Draft', 'wporg-groups-frontend' ) : ''
				)
			),
			showClone && h( 'div', { className: 'wporg-settings-tab__list-item-actions',
				onClick: ( ev ) => ev.stopPropagation() },
				h( Button, {
					variant: 'tertiary', isSmall: true,
					onClick: () => cloneEvent( event.id ),
					disabled: cloning,
				}, __( 'Clone', 'wporg-groups-frontend' ) )
			)
		);

	return h( 'div', { className: 'wporg-settings-tab' },
		h( 'div', { className: 'wporg-settings-tab__header' },
			h( 'p', {}, __( 'Manage your group events.', 'wporg-groups-frontend' ) ),
			h( Button, { variant: 'primary', onClick: () => setEditingId( 0 ) },
				__( '+ Create event', 'wporg-groups-frontend' ) )
		),

		drafts.length > 0 && h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Drafts', 'wporg-groups-frontend' ) ),
		drafts.length > 0 && h( 'div', { className: 'wporg-settings-tab__list' },
			drafts.map( ( e ) => renderEventItem( e, false ) ) ),

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Upcoming', 'wporg-groups-frontend' ) ),
		upcoming.length === 0
			? h( 'p', { className: 'wporg-settings-tab__empty' }, __( 'No upcoming events.', 'wporg-groups-frontend' ) )
			: h( 'div', { className: 'wporg-settings-tab__list' }, upcoming.map( ( e ) => renderEventItem( e, true ) ) ),

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Past', 'wporg-groups-frontend' ) ),
		past.length === 0
			? h( 'p', { className: 'wporg-settings-tab__empty' }, __( 'No past events.', 'wporg-groups-frontend' ) )
			: h( 'div', { className: 'wporg-settings-tab__list' }, past.map( ( e ) => renderEventItem( e, true ) ) )
	);
}

function formatEventDate( dateStr ) {
	try {
		const d = new Date( dateStr );
		return d.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			year: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );
	} catch {
		return dateStr;
	}
}

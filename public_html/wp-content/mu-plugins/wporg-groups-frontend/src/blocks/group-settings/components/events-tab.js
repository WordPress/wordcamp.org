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
	const [ venueEditorId, setVenueEditorId ] = useState( null );
	const formRef = useRef( null );

	const submitPayload = async ( payload ) => {
		const result = await apiFetch( {
			path: isEdit ? `/${ NS }/event/${ eventId }` : `/${ NS }/event`,
			method: 'POST',
			data: payload,
		} );

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
					h( Button, { variant: 'tertiary', onClick: onCancel, icon: 'arrow-left-alt2' }, __( 'Back to events', 'wordcamporg' ) ),
				),
			} )
		)
	);
}

// === Events Tab (list + form) ===

export default function EventsTab() {
	const [ events, setEvents ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ editingId, setEditingId ] = useState( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ cloning, setCloning ] = useState( false );

	useEffect( () => {
		if ( editingId !== null ) return;
		setLoading( true );
		/*
		 * One request per section rather than one list sliced client-side.
		 * Two reasons. The collection defaults to published only, so the
		 * Drafts section could never populate without asking for them. And
		 * the collection inherits GatherPress's upcoming-only clause, which
		 * left Past permanently empty and hid past-dated drafts (#1977);
		 * `wporg_groups_event_list` is this plugin's own parameter, read in
		 * inc/rest.php, and selects the slice.
		 *
		 * Splitting also keeps `per_page` honest. A single capped request
		 * ordered by post date spends its 100 rows on whatever was created
		 * most recently, so on a group with a long history the other two
		 * sections come back empty no matter what the clause does.
		 */
		const fields = '_fields=id,title,meta,status&per_page=100';
		Promise.all( [
			apiFetch( { path: `/wp/v2/gatherpress_events?${ fields }&status=draft&wporg_groups_event_list=all&orderby=date&order=desc` } ),
			apiFetch( { path: `/wp/v2/gatherpress_events?${ fields }&status=publish&wporg_groups_event_list=upcoming` } ),
			apiFetch( { path: `/wp/v2/gatherpress_events?${ fields }&status=publish&wporg_groups_event_list=past` } ),
		] )
			.then( ( [ draftRows, upcomingRows, pastRows ] ) => {
				const toEvent = ( e, section ) => ( {
					id: e.id,
					title: e.title.rendered,
					status: e.status,
					dateStart: e.meta?.gatherpress_datetime_start || '',
					section,
				} );
				setEvents( [
					...draftRows.map( ( e ) => toEvent( e, 'draft' ) ),
					...upcomingRows.map( ( e ) => toEvent( e, 'upcoming' ) ),
					...pastRows.map( ( e ) => toEvent( e, 'past' ) ),
				] );
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
			eventId: editingId,
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
				title: res.fields.title + ' ' + __( '(copy)', 'wordcamporg' ),
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

	const drafts = events.filter( ( e ) => e.section === 'draft' );
	const upcoming = events.filter( ( e ) => e.section === 'upcoming' );
	const past = events.filter( ( e ) => e.section === 'past' );

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
					event.status === 'draft' ? ( event.dateStart ? ' — ' : '' ) + __( 'Draft', 'wordcamporg' ) : ''
				)
			),
			showClone && h( 'div', { className: 'wporg-settings-tab__list-item-actions',
				onClick: ( ev ) => ev.stopPropagation() },
				h( Button, {
					variant: 'tertiary', isSmall: true,
					onClick: () => cloneEvent( event.id ),
					disabled: cloning,
				}, __( 'Clone', 'wordcamporg' ) )
			)
		);

	return h( 'div', { className: 'wporg-settings-tab' },
		h( 'div', { className: 'wporg-settings-tab__header' },
			h( 'p', {}, __( 'Manage your group events.', 'wordcamporg' ) ),
			h( Button, { variant: 'primary', onClick: () => setEditingId( 0 ) },
				__( '+ Create event', 'wordcamporg' ) )
		),

		drafts.length > 0 && h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Drafts', 'wordcamporg' ) ),
		drafts.length > 0 && h( 'div', { className: 'wporg-settings-tab__list' },
			drafts.map( ( e ) => renderEventItem( e, false ) ) ),

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Upcoming', 'wordcamporg' ) ),
		upcoming.length === 0
			? h( 'p', { className: 'wporg-settings-tab__empty' }, __( 'No upcoming events.', 'wordcamporg' ) )
			: h( 'div', { className: 'wporg-settings-tab__list' }, upcoming.map( ( e ) => renderEventItem( e, true ) ) ),

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Past', 'wordcamporg' ) ),
		past.length === 0
			? h( 'p', { className: 'wporg-settings-tab__empty' }, __( 'No past events.', 'wordcamporg' ) )
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

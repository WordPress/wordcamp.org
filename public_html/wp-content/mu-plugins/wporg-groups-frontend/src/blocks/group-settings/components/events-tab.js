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
	useState,
	useEffect,
	useRef,
} from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	TextControl,
	FormTokenField,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import VenueEditor from '../../event-manage/venue-editor';
import RecurrenceControls, { normalizeRecurrence } from '../../../components/recurrence-controls';
import RsvpQuestionsEditor from '../../event-manage/rsvp-questions-editor';
import DescriptionEditor, { ensureCoreBlocksRegistered } from '../../../components/event-form/description-editor';
import FeaturedImagePicker from '../../../components/event-form/featured-image-picker';
import DurationField from '../../../components/event-form/duration-field';
import VenueField from '../../../components/event-form/venue-field';

const NS =
	( window.wporgGroupsEventModal &&
		window.wporgGroupsEventModal.restNamespace ) ||
	'wporg-groups/v1';
const MINIMUM_EVENT_DATE = window.wporgGroupsEventModal?.minimumEventDate || '';


// === Event Form (inline) ===

function EventForm( { eventId, onDone, onCancel } ) {
	const isEdit = !! eventId;
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ form, setForm ] = useState( {
		title: '',
		date: '',
		time_start: '',
		time_end: '',
		venue_select: '',
		is_online: false,
		online_event_link: '',
		rsvp_questions: [],
	} );
	const [ initialDescription, setInitialDescription ] = useState( '' );
	const [ featuredImage, setFeaturedImage ] = useState( { id: 0, url: '' } );
	const [ recurrence, setRecurrence ] = useState( null );
	const [ venues, setVenues ] = useState( [] );
	const [ venueEditorId, setVenueEditorId ] = useState( null );
	const [ speakers, setSpeakers ] = useState( [] );
	const [ memberOptions, setMemberOptions ] = useState( [] );
	const descRef = useRef( { current: () => '' } );

	const updateField = ( field, value ) => setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );

	useEffect( () => {
		ensureCoreBlocksRegistered();
		const params = eventId ? `?event_id=${ eventId }` : '';
		apiFetch( { path: `/${ NS }/event-form-data${ params }` } )
			.then( ( res ) => {
				setForm( {
					title: res.fields.title || '',
					date: res.fields.date || '',
					time_start: res.fields.time_start || '',
					time_end: res.fields.time_end || '',
					venue_select: res.fields.venue_id ? String( res.fields.venue_id ) : '',
					is_online: !! res.fields.is_online,
					online_event_link: res.fields.online_event_link || '',
					rsvp_questions: res.fields.rsvp_questions || [],
				} );
				setInitialDescription( res.fields.description || '' );
				setFeaturedImage( { id: res.fields.featured_image_id || 0, url: res.fields.featured_image_url || '' } );
				setRecurrence( normalizeRecurrence( res.fields.recurrence ) );
				setVenues( res.venues || [] );

				// Load speakers for this event and member list for autocomplete.
				Promise.all( [
					eventId
						? apiFetch( { path: `/wp/v2/gatherpress_events/${ eventId }?_fields=meta` } )
							.then( ( ev ) => ev.meta?._event_speakers || [] )
							.catch( () => [] )
						: Promise.resolve( [] ),
					apiFetch( { path: '/wporg-groups/v1/members?per_page=200' } )
						.catch( () => [] ),
				] ).then( ( [ speakerIds, members ] ) => {
					setSpeakers( speakerIds );
					setMemberOptions( members );
					setLoading( false );
				} );
			} )
			.catch( () => { setError( __( 'Could not load form data.', 'wporg-groups-frontend' ) ); setLoading( false ); } );
	}, [ eventId ] );

	if ( loading ) return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );

	if ( venueEditorId !== null ) {
		return h( 'div', { className: 'wporg-settings-tab' },
			h( VenueEditor, {
				venueId: venueEditorId, inline: true,
				onSave: ( saved ) => {
					setVenueEditorId( null );
					updateField( 'venue_select', String( saved.id ) );
					setVenues( ( prev ) => {
						const exists = prev.find( ( v ) => v.id === saved.id );
						if ( exists ) return prev.map( ( v ) => v.id === saved.id ? { ...v, name: saved.name } : v );
						return [ ...prev, { id: saved.id, name: saved.name } ];
					} );
				},
				onCancel: () => setVenueEditorId( null ),
			} )
		);
	}

	const onSubmit = async ( ev ) => {
		ev.preventDefault();
		setError( '' );
		setSaving( true );
		const description = descRef.current ? descRef.current() : '';
		try {
			const result = await apiFetch( {
				path: isEdit ? `/${ NS }/event/${ eventId }` : `/${ NS }/event`,
				method: 'POST',
				data: {
					title: form.title, description, date: form.date,
					time_start: form.time_start, time_end: form.time_end,
					venue_id: parseInt( form.venue_select, 10 ) || 0,
					is_online: form.is_online,
					online_event_link: form.is_online ? form.online_event_link : '',
					featured_image_id: featuredImage.id,
					// Recurrence is locked (uneditable) once an event is published,
					// so editing an existing event never needs to resend it — and
					// must not send `null`, which fails the endpoint's object schema.
					...( isEdit ? {} : { recurrence } ),
					// Blank-labelled rows are an empty slot the organizer added
					// and never filled in; the server drops them too.
					rsvp_questions: ( form.rsvp_questions || [] ).filter( ( question ) => question.label.trim() !== '' ),
				},
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
		} catch ( err ) {
			setError( err.message || __( 'Could not save event.', 'wporg-groups-frontend' ) );
			setSaving( false );
		}
	};

	return h( 'form', { onSubmit, className: 'wporg-event-form' },
		h( 'div', { className: 'wporg-event-form__header' },
			h( Button, { variant: 'tertiary', onClick: onCancel, icon: 'arrow-left-alt2' }, __( 'Back to events', 'wporg-groups-frontend' ) ),
		),
		error && h( Notice, { status: 'error', isDismissible: false }, error ),
		h( TextControl, { label: __( 'Event title', 'wporg-groups-frontend' ), value: form.title, onChange: ( v ) => updateField( 'title', v ), required: true, __nextHasNoMarginBottom: true } ),
		h( 'div', { className: 'wporg-event-form__field' },
			h( 'label', { className: 'wporg-event-form__label' }, __( 'Description', 'wporg-groups-frontend' ) ),
			h( DescriptionEditor, { initialValue: initialDescription, getValueRef: descRef, classPrefix: 'wporg-event-form' } ) ),
		h( FeaturedImagePicker, { imageId: featuredImage.id, imageUrl: featuredImage.url, onChange: ( id, url ) => setFeaturedImage( { id, url } ), classPrefix: 'wporg-event-form' } ),
		h( 'div', { className: 'wporg-event-form__row' },
			h( TextControl, { label: __( 'Date', 'wporg-groups-frontend' ), type: 'date', value: form.date, min: isEdit ? undefined : MINIMUM_EVENT_DATE, onChange: ( v ) => updateField( 'date', v ), required: true, __nextHasNoMarginBottom: true } ),
			h( TextControl, { label: __( 'Start time', 'wporg-groups-frontend' ), type: 'time', value: form.time_start, onChange: ( v ) => updateField( 'time_start', v ), required: true, __nextHasNoMarginBottom: true } ),
			h( DurationField, { timeStart: form.time_start, timeEnd: form.time_end, onChange: ( v ) => updateField( 'time_end', v ), classPrefix: 'wporg-event-form' } ) ),
		h( RecurrenceControls, {
			value: recurrence,
			eventDate: form.date,
			onChange: setRecurrence,
		} ),
		h( VenueField, {
			venues, venueId: form.venue_select,
			onSelect: ( v ) => updateField( 'venue_select', v ),
			onOpenVenueEditor: ( id ) => setVenueEditorId( id ),
			classPrefix: 'wporg-event-form',
		} ),
		h( 'div', { className: 'wporg-event-form__online-event' },
			h( ToggleControl, {
				label: __( 'This is an online event', 'wporg-groups-frontend' ),
				checked: form.is_online,
				onChange: ( value ) => updateField( 'is_online', value ),
				__nextHasNoMarginBottom: true,
			} ),
			form.is_online && h( TextControl, {
				label: __( 'Online event link', 'wporg-groups-frontend' ),
				type: 'url',
				value: form.online_event_link,
				onChange: ( value ) => updateField( 'online_event_link', value ),
				placeholder: 'https://',
				required: true,
				__nextHasNoMarginBottom: true,
			} )
		),
		h( RsvpQuestionsEditor, {
			questions: form.rsvp_questions,
			onChange: ( value ) => updateField( 'rsvp_questions', value ),
		} ),
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
		),
		h( 'div', { className: 'wporg-event-form__actions' },
			h( Button, { variant: 'tertiary', onClick: onCancel, disabled: saving }, _x( 'Cancel', 'abort current action', 'wporg-groups-frontend' ) ),
			h( Button, { variant: 'primary', type: 'submit', isBusy: saving, disabled: saving },
				isEdit ? __( 'Save changes', 'wporg-groups-frontend' ) : __( 'Create event', 'wporg-groups-frontend' ) )
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
		apiFetch( { path: '/wp/v2/gatherpress_events?per_page=100&_fields=id,title,meta,status&orderby=date&order=desc' } )
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
		return h( EventForm, {
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

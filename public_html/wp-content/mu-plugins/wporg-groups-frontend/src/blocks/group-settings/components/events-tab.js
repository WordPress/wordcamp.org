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
import {
	BlockEditorProvider,
	BlockList,
	BlockToolbar,
	WritingFlow,
	ObserveTyping,
	BlockTools,
} from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import { parse, serialize } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import VenueEditor from '../../event-manage/venue-editor';

const NS =
	( window.wporgGroupsEventModal &&
		window.wporgGroupsEventModal.restNamespace ) ||
	'wporg-groups/v1';

let coreBlocksRegistered = false;

function ensureCoreBlocksRegistered() {
	if ( ! coreBlocksRegistered ) {
		registerCoreBlocks();
		coreBlocksRegistered = true;
	}
}

// === Duration helpers ===

const DURATION_OPTIONS = [
	{ label: __( '30 minutes', 'wporg-groups-frontend' ), value: '30' },
	{ label: __( '1 hour', 'wporg-groups-frontend' ), value: '60' },
	{ label: __( '1.5 hours', 'wporg-groups-frontend' ), value: '90' },
	{ label: __( '2 hours', 'wporg-groups-frontend' ), value: '120' },
	{ label: __( '2.5 hours', 'wporg-groups-frontend' ), value: '150' },
	{ label: __( '3 hours', 'wporg-groups-frontend' ), value: '180' },
	{ label: __( 'Custom', 'wporg-groups-frontend' ), value: 'custom' },
];

function addMinutesToTime( time, minutes ) {
	if ( ! time ) return '';
	const [ hr, min ] = time.split( ':' ).map( Number );
	const total = hr * 60 + min + minutes;
	return String( Math.floor( total / 60 ) % 24 ).padStart( 2, '0' ) + ':' + String( total % 60 ).padStart( 2, '0' );
}

function getMinutesBetween( start, end ) {
	if ( ! start || ! end ) return 0;
	const [ sh, sm ] = start.split( ':' ).map( Number );
	const [ eh, em ] = end.split( ':' ).map( Number );
	let diff = ( eh * 60 + em ) - ( sh * 60 + sm );
	if ( diff < 0 ) diff += 24 * 60;
	return diff;
}

// === Sub-components ===

function DescriptionEditor( { initialValue, getValueRef, onDirty } ) {
	const [ blocks, setBlocks ] = useState( () => parse( initialValue || '' ) );
	if ( getValueRef ) getValueRef.current = () => serialize( blocks );
	const handleChange = ( newBlocks ) => {
		setBlocks( newBlocks );
		if ( onDirty ) onDirty();
	};
	return h( 'div', { className: 'wporg-event-form__editor' },
		h( BlockEditorProvider, {
			value: blocks, onInput: handleChange, onChange: handleChange,
			settings: { hasFixedToolbar: true },
		},
			h( 'div', { className: 'wporg-event-form__editor-toolbar' },
				h( BlockToolbar, { hideDragHandle: true } ) ),
			h( BlockTools, {},
				h( WritingFlow, {},
					h( ObserveTyping, {}, h( BlockList ) ) ) )
		)
	);
}

function FeaturedImagePicker( { imageId, imageUrl, onChange } ) {
	const openPicker = () => {
		const frame = wp.media( {
			title: __( 'Choose featured image', 'wporg-groups-frontend' ),
			button: { text: __( 'Set featured image', 'wporg-groups-frontend' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onChange( att.id, att.sizes?.medium?.url || att.url );
		} );
		frame.open();
	};
	if ( imageUrl ) {
		return h( 'div', { className: 'wporg-event-form__featured' },
			h( 'div', { className: 'wporg-event-form__featured-preview' },
				h( 'img', { src: imageUrl, alt: '' } ),
				h( 'div', { className: 'wporg-event-form__featured-actions' },
					h( Button, { variant: 'secondary', isSmall: true, onClick: openPicker }, __( 'Replace', 'wporg-groups-frontend' ) ),
					h( Button, { variant: 'tertiary', isSmall: true, isDestructive: true, onClick: () => onChange( 0, '' ) }, __( 'Remove', 'wporg-groups-frontend' ) )
				)
			)
		);
	}
	return h( Button, { variant: 'secondary', onClick: openPicker }, __( 'Choose featured image', 'wporg-groups-frontend' ) );
}

function DurationField( { timeStart, timeEnd, onChange } ) {
	const minutes = getMinutesBetween( timeStart, timeEnd );
	const known = DURATION_OPTIONS.find( ( o ) => o.value !== 'custom' && Number( o.value ) === minutes );
	const [ isCustom, setIsCustom ] = useState( ! known && !! timeEnd );
	const selected = isCustom ? 'custom' : ( known ? String( minutes ) : '' );
	return h( 'div', { className: 'wporg-event-form__field' },
		h( SelectControl, {
			label: __( 'Duration', 'wporg-groups-frontend' ), value: selected,
			options: [ { label: '—', value: '' } ].concat( DURATION_OPTIONS ),
			onChange: ( v ) => {
				if ( v === 'custom' ) setIsCustom( true );
				else if ( v ) { setIsCustom( false ); onChange( addMinutesToTime( timeStart, Number( v ) ) ); }
			},
			__nextHasNoMarginBottom: true,
		} ),
		isCustom && h( TextControl, {
			label: __( 'End time', 'wporg-groups-frontend' ), type: 'time',
			value: timeEnd, onChange, required: true, __nextHasNoMarginBottom: true,
		} )
	);
}

function VenueField( { venues, venueId, onSelect, onOpenEditor } ) {
	const options = [
		{ label: __( '— No venue —', 'wporg-groups-frontend' ), value: '' },
	].concat(
		( venues || [] ).map( ( v ) => ( { label: v.name, value: String( v.id ) } ) )
	).concat( [
		{ label: __( '+ Add a new venue', 'wporg-groups-frontend' ), value: '__new__' },
	] );
	return h( 'div', { className: 'wporg-event-form__field' },
		h( SelectControl, {
			label: __( 'Venue', 'wporg-groups-frontend' ), value: venueId ? String( venueId ) : '',
			options, onChange: ( v ) => v === '__new__' ? onOpenEditor( 0 ) : onSelect( v ),
			__nextHasNoMarginBottom: true,
		} ),
		venueId && venueId !== '__new__' &&
			h( Button, { variant: 'link', onClick: () => onOpenEditor( parseInt( venueId, 10 ) ), className: 'wporg-event-form__edit-venue' },
				__( 'Edit venue', 'wporg-groups-frontend' ) )
	);
}

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
	} );
	const [ initialDescription, setInitialDescription ] = useState( '' );
	const [ featuredImage, setFeaturedImage ] = useState( { id: 0, url: '' } );
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
				} );
				setInitialDescription( res.fields.description || '' );
				setFeaturedImage( { id: res.fields.featured_image_id || 0, url: res.fields.featured_image_url || '' } );
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
			h( DescriptionEditor, { initialValue: initialDescription, getValueRef: descRef } ) ),
		h( 'div', { className: 'wporg-event-form__field' },
			h( 'label', { className: 'wporg-event-form__label' }, __( 'Featured image', 'wporg-groups-frontend' ) ),
			h( FeaturedImagePicker, { imageId: featuredImage.id, imageUrl: featuredImage.url, onChange: ( id, url ) => setFeaturedImage( { id, url } ) } ) ),
		h( 'div', { className: 'wporg-event-form__row' },
			h( TextControl, { label: __( 'Date', 'wporg-groups-frontend' ), type: 'date', value: form.date, onChange: ( v ) => updateField( 'date', v ), required: true, __nextHasNoMarginBottom: true } ),
			h( TextControl, { label: __( 'Start time', 'wporg-groups-frontend' ), type: 'time', value: form.time_start, onChange: ( v ) => updateField( 'time_start', v ), required: true, __nextHasNoMarginBottom: true } ),
			h( DurationField, { timeStart: form.time_start, timeEnd: form.time_end, onChange: ( v ) => updateField( 'time_end', v ) } ) ),
		h( VenueField, {
			venues, venueId: form.venue_select,
			onSelect: ( v ) => updateField( 'venue_select', v ),
			onOpenEditor: ( id ) => setVenueEditorId( id ),
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
			h( Button, { variant: 'tertiary', onClick: onCancel, disabled: saving }, __( 'Cancel', 'wporg-groups-frontend' ) ),
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

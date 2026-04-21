/**
 * Group Settings — Events tab.
 *
 * Wraps the existing EventModal form for creating and editing events.
 * The event form is rendered inline within the tab (not in a separate modal).
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
	useRef,
	Fragment,
} from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	TextControl,
	SelectControl,
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

const AUTOSAVE_INTERVAL_MS = 5000;

const DURATION_OPTIONS = [
	{ label: __( '30 minutes', 'wporg-groups-frontend' ), value: '30' },
	{ label: __( '1 hour', 'wporg-groups-frontend' ), value: '60' },
	{ label: __( '1.5 hours', 'wporg-groups-frontend' ), value: '90' },
	{ label: __( '2 hours', 'wporg-groups-frontend' ), value: '120' },
	{ label: __( '2.5 hours', 'wporg-groups-frontend' ), value: '150' },
	{ label: __( '3 hours', 'wporg-groups-frontend' ), value: '180' },
	{ label: __( 'Custom', 'wporg-groups-frontend' ), value: 'custom' },
];

let coreBlocksRegistered = false;

function ensureCoreBlocksRegistered() {
	if ( ! coreBlocksRegistered ) {
		registerCoreBlocks();
		coreBlocksRegistered = true;
	}
}

function addMinutesToTime( time, minutes ) {
	if ( ! time ) {
		return '';
	}
	const [ hr, min ] = time.split( ':' ).map( Number );
	const total = hr * 60 + min + minutes;
	const newH = Math.floor( total / 60 ) % 24;
	const newM = total % 60;
	return String( newH ).padStart( 2, '0' ) + ':' + String( newM ).padStart( 2, '0' );
}

function getMinutesBetween( start, end ) {
	if ( ! start || ! end ) {
		return 0;
	}
	const [ sh, sm ] = start.split( ':' ).map( Number );
	const [ eh, em ] = end.split( ':' ).map( Number );
	let diff = ( eh * 60 + em ) - ( sh * 60 + sm );
	if ( diff < 0 ) {
		diff += 24 * 60;
	}
	return diff;
}

/**
 * Inline block editor for event description.
 */
function DescriptionEditor( { initialValue, getValueRef, onDirty } ) {
	const [ blocks, setBlocks ] = useState( () => parse( initialValue || '' ) );

	if ( getValueRef ) {
		getValueRef.current = () => serialize( blocks );
	}

	const handleChange = ( newBlocks ) => {
		setBlocks( newBlocks );
		if ( onDirty ) {
			onDirty();
		}
	};

	return h(
		'div',
		{ className: 'wporg-event-form__editor' },
		h(
			BlockEditorProvider,
			{
				value: blocks,
				onInput: handleChange,
				onChange: handleChange,
				settings: { hasFixedToolbar: true },
			},
			h(
				'div',
				{ className: 'wporg-event-form__editor-toolbar' },
				h( BlockToolbar, { hideDragHandle: true } )
			),
			h(
				BlockTools,
				{},
				h( WritingFlow, {}, h( ObserveTyping, {}, h( BlockList ) ) )
			)
		)
	);
}

/**
 * Featured image picker.
 */
function FeaturedImagePicker( { imageId, imageUrl, onChange } ) {
	const openPicker = () => {
		const frame = wp.media( {
			title: __( 'Choose featured image', 'wporg-groups-frontend' ),
			button: { text: __( 'Set featured image', 'wporg-groups-frontend' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			onChange( attachment.id, attachment.sizes?.medium?.url || attachment.url );
		} );
		frame.open();
	};

	if ( imageUrl ) {
		return h(
			'div',
			{ className: 'wporg-event-form__featured' },
			h(
				'div',
				{ className: 'wporg-event-form__featured-preview' },
				h( 'img', { src: imageUrl, alt: '' } ),
				h(
					'div',
					{ className: 'wporg-event-form__featured-actions' },
					h( Button, { variant: 'secondary', isSmall: true, onClick: openPicker }, __( 'Replace', 'wporg-groups-frontend' ) ),
					h( Button, { variant: 'tertiary', isSmall: true, isDestructive: true, onClick: () => onChange( 0, '' ) }, __( 'Remove', 'wporg-groups-frontend' ) )
				)
			)
		);
	}

	return h( Button, { variant: 'secondary', onClick: openPicker }, __( 'Choose featured image', 'wporg-groups-frontend' ) );
}

/**
 * Duration field with preset options.
 */
function DurationField( { timeStart, timeEnd, onChange } ) {
	const minutes = getMinutesBetween( timeStart, timeEnd );
	const knownDuration = DURATION_OPTIONS.find( ( o ) => o.value !== 'custom' && Number( o.value ) === minutes );
	const [ isCustom, setIsCustom ] = useState( ! knownDuration && !! timeEnd );

	const selectedValue = isCustom ? 'custom' : ( knownDuration ? String( minutes ) : '' );

	return h(
		'div',
		{ className: 'wporg-event-form__field' },
		h( SelectControl, {
			label: __( 'Duration', 'wporg-groups-frontend' ),
			value: selectedValue,
			options: [ { label: __( '— Select —', 'wporg-groups-frontend' ), value: '' } ].concat( DURATION_OPTIONS ),
			onChange: ( v ) => {
				if ( v === 'custom' ) {
					setIsCustom( true );
				} else if ( v ) {
					setIsCustom( false );
					onChange( addMinutesToTime( timeStart, Number( v ) ) );
				}
			},
			__nextHasNoMarginBottom: true,
		} ),
		isCustom && h( TextControl, {
			label: __( 'End time', 'wporg-groups-frontend' ),
			type: 'time',
			value: timeEnd,
			onChange,
			required: true,
			__nextHasNoMarginBottom: true,
		} )
	);
}

/**
 * Venue selector with "Add new" and "Edit" options.
 */
function VenueField( { venues, venueId, onSelectExisting, onOpenVenueEditor } ) {
	const options = [
		{ label: __( '— Select a venue —', 'wporg-groups-frontend' ), value: '' },
	].concat(
		( venues || [] ).map( ( v ) => ( { label: v.name, value: String( v.id ) } ) )
	).concat( [
		{ label: __( '+ Add a new venue', 'wporg-groups-frontend' ), value: '__new__' },
	] );

	return h(
		'div',
		{ className: 'wporg-event-form__field' },
		h( SelectControl, {
			label: __( 'Venue', 'wporg-groups-frontend' ),
			value: venueId ? String( venueId ) : '',
			options,
			onChange: ( v ) => {
				if ( v === '__new__' ) {
					onOpenVenueEditor( 0 );
				} else {
					onSelectExisting( v );
				}
			},
			__nextHasNoMarginBottom: true,
		} ),
		venueId && venueId !== '__new__' &&
			h( Button, {
				variant: 'link',
				onClick: () => onOpenVenueEditor( parseInt( venueId, 10 ) ),
				className: 'wporg-event-form__edit-venue',
			}, __( 'Edit venue', 'wporg-groups-frontend' ) )
	);
}

const EMPTY_FORM = {
	title: '',
	date: '',
	time_start: '',
	time_end: '',
	venue_select: '',
};

/**
 * Events tab — create and edit events.
 */
export default function EventsTab( { eventId: initialEventId, onClose } ) {
	const isEdit = !! initialEventId;
	const [ eventId, setEventId ] = useState( initialEventId || 0 );

	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ initialDescription, setInitialDescription ] = useState( '' );
	const [ featuredImage, setFeaturedImage ] = useState( { id: 0, url: '' } );
	const [ venues, setVenues ] = useState( [] );
	const [ dirty, setDirty ] = useState( false );

	const [ venueEditorOpen, setVenueEditorOpen ] = useState( false );
	const [ venueEditorId, setVenueEditorId ] = useState( 0 );

	const descRef = useRef( { current: () => '' } );
	const dirtyRef = useRef( false );
	dirtyRef.current = dirty;

	const updateField = ( field, value ) => {
		setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );
	};

	const markDirty = () => {
		if ( ! dirtyRef.current ) {
			setDirty( true );
		}
	};

	// Load form data.
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
				} );
				setInitialDescription( res.fields.description || '' );
				setFeaturedImage( {
					id: res.fields.featured_image_id || 0,
					url: res.fields.featured_image_url || '',
				} );
				setVenues( res.venues || [] );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Could not load form data.', 'wporg-groups-frontend' ) );
				setLoading( false );
			} );
	}, [ eventId ] );

	const onSubmit = async ( ev ) => {
		ev.preventDefault();
		setError( '' );
		setSaving( true );

		const description = descRef.current ? descRef.current() : '';

		const payload = {
			title: form.title,
			description,
			date: form.date,
			time_start: form.time_start,
			time_end: form.time_end,
			venue_id: parseInt( form.venue_select, 10 ) || 0,
			featured_image_id: featuredImage.id,
		};

		try {
			const path = isEdit
				? `/${ NS }/event/${ eventId }`
				: `/${ NS }/event`;

			const result = await apiFetch( {
				path,
				method: 'POST',
				data: payload,
			} );

			if ( result.permalink ) {
				window.location.href = result.permalink;
			} else {
				window.location.reload();
			}
		} catch ( err ) {
			setError( err.message || __( 'Could not save event.', 'wporg-groups-frontend' ) );
			setSaving( false );
		}
	};

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	if ( venueEditorOpen ) {
		return h( VenueEditor, {
			venueId: venueEditorId,
			onSave: ( saved ) => {
				setVenueEditorOpen( false );
				updateField( 'venue_select', String( saved.id ) );
				setVenues( ( prev ) => {
					const exists = prev.find( ( v ) => v.id === saved.id );
					if ( exists ) {
						return prev.map( ( v ) =>
							v.id === saved.id ? { ...v, name: saved.name } : v
						);
					}
					return [ ...prev, { id: saved.id, name: saved.name } ];
				} );
				markDirty();
			},
			onCancel: () => setVenueEditorOpen( false ),
		} );
	}

	return h(
		'form',
		{ onSubmit, className: 'wporg-event-form' },
		error && h( Notice, { status: 'error', isDismissible: false }, error ),

		h( TextControl, {
			label: __( 'Event title', 'wporg-groups-frontend' ),
			value: form.title,
			onChange: ( v ) => { updateField( 'title', v ); markDirty(); },
			required: true,
			__nextHasNoMarginBottom: true,
		} ),

		h(
			'div',
			{ className: 'wporg-event-form__field' },
			h( 'label', { className: 'wporg-event-form__label' }, __( 'Description', 'wporg-groups-frontend' ) ),
			h( DescriptionEditor, {
				initialValue: initialDescription,
				getValueRef: descRef,
				onDirty: markDirty,
			} )
		),

		h(
			'div',
			{ className: 'wporg-event-form__field' },
			h( 'label', { className: 'wporg-event-form__label' }, __( 'Featured image', 'wporg-groups-frontend' ) ),
			h( FeaturedImagePicker, {
				imageId: featuredImage.id,
				imageUrl: featuredImage.url,
				onChange: ( id, url ) => {
					setFeaturedImage( { id, url } );
					markDirty();
				},
			} )
		),

		h(
			'div',
			{ className: 'wporg-event-form__row' },
			h( TextControl, {
				label: __( 'Date', 'wporg-groups-frontend' ),
				type: 'date',
				value: form.date,
				onChange: ( v ) => { updateField( 'date', v ); markDirty(); },
				required: true,
				__nextHasNoMarginBottom: true,
			} ),
			h( TextControl, {
				label: __( 'Start time', 'wporg-groups-frontend' ),
				type: 'time',
				value: form.time_start,
				onChange: ( v ) => { updateField( 'time_start', v ); markDirty(); },
				required: true,
				__nextHasNoMarginBottom: true,
			} ),
			h( DurationField, {
				timeStart: form.time_start,
				timeEnd: form.time_end,
				onChange: ( v ) => { updateField( 'time_end', v ); markDirty(); },
			} )
		),

		h( VenueField, {
			venues,
			venueId: form.venue_select,
			onSelectExisting: ( v ) => { updateField( 'venue_select', v ); markDirty(); },
			onOpenVenueEditor: ( id ) => {
				setVenueEditorId( id );
				setVenueEditorOpen( true );
			},
		} ),

		h(
			'div',
			{ className: 'wporg-event-form__actions' },
			h(
				Button,
				{ variant: 'primary', type: 'submit', isBusy: saving, disabled: saving },
				isEdit
					? __( 'Save changes', 'wporg-groups-frontend' )
					: __( 'Create event', 'wporg-groups-frontend' )
			)
		)
	);
}

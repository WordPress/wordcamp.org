/**
 * Event form shared by the event modal and the group-settings Events tab.
 *
 * Owns the field state, the `event-form-data` fetch and the request payload.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import {
	useState,
	useEffect,
	useRef,
	forwardRef,
	useImperativeHandle,
} from '@wordpress/element';
import {
	TextControl,
	Button,
	ToggleControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import RecurrenceControls, { normalizeRecurrence } from '../recurrence-controls';
import RsvpQuestionsEditor from '../../blocks/event-manage/rsvp-questions-editor';
import DescriptionEditor, { ensureCoreBlocksRegistered } from './description-editor';
import FeaturedImagePicker from './featured-image-picker';
import DurationField from './duration-field';
import VenueField from './venue-field';

export const NS =
	( window.wporgGroupsEventModal &&
		window.wporgGroupsEventModal.restNamespace ) ||
	'wporg-groups/v1';
const MINIMUM_EVENT_DATE = window.wporgGroupsEventModal?.minimumEventDate || '';

const EMPTY_FORM = {
	title: '',
	date: '',
	time_start: '',
	time_end: '',
	venue_select: '',
	is_online: false,
	online_event_link: '',
	rsvp_questions: [],
};

/**
 * @param {Object}   props
 * @param {string}   props.mode              `'create'` or `'edit'`.
 * @param {number}   props.eventId           Post id to load; 0 loads the create defaults.
 * @param {string}   props.classPrefix       BEM block prefix of the host surface.
 * @param {string}   props.className         Class of the `<form>` element.
 * @param {Function} props.onSubmitPayload   Receives the payload, returns a promise; a rejection's message is shown as the save error.
 * @param {Function} props.onCancel
 * @param {Function} props.onOpenVenueEditor Receives the venue id (0 = new venue).
 * @param {Function} props.onChange          Optional; called when a field value changes.
 * @param {Function} props.onDirty           Optional; called on any edit, including the description.
 * @param {Function} props.onLoad            Optional; called once event data has been loaded into the form.
 * @param {*}        props.header            Rendered above the fields.
 * @param {*}        props.footerStart       Rendered at the start of the actions row.
 * @param {*}        props.children          Rendered after the RSVP questions.
 * @param {Object}   ref                     Exposes `getPayload()`, `loadEvent( id )`, `selectVenue( venue )`, `isLoading()`, `isSaving()`.
 */
function EventForm(
	{
		mode,
		eventId,
		classPrefix,
		className,
		onSubmitPayload,
		onCancel,
		onOpenVenueEditor,
		onChange,
		onDirty,
		onLoad,
		header,
		footerStart,
		children,
	},
	ref
) {
	const isEdit = mode === 'edit';

	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	/**
	 * Description is intentionally NOT in form state - the inline block editor
	 * owns it. We grab the current value via `descriptionRef` only at
	 * submit/autosave time.
	 */
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ initialDescription, setInitialDescription ] = useState( '' );
	const [ featuredImage, setFeaturedImage ] = useState( { id: 0, url: '' } );
	const [ recurrence, setRecurrence ] = useState( null );
	const [ venues, setVenues ] = useState( [] );
	const descriptionRef = useRef( () => '' );

	// Bumped whenever we load fresh data into the form.
	const [ editorKey, setEditorKey ] = useState( 0 );

	const markDirty = () => {
		if ( onDirty ) {
			onDirty();
		}
	};

	const markChanged = () => {
		if ( onChange ) {
			onChange();
		}
		markDirty();
	};

	const updateField = ( field, value ) => {
		setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );
		markChanged();
	};

	const loadFormData = ( id ) => {
		let cancelled = false;
		setLoading( true );
		setError( '' );

		const path = id
			? `/${ NS }/event-form-data?event_id=${ id }`
			: `/${ NS }/event-form-data`;

		apiFetch( { path } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setVenues( res.venues || [] );
				setInitialDescription( res.fields.description || '' );
				setFeaturedImage( {
					id: res.fields.featured_image_id || 0,
					url: res.fields.featured_image_url || '',
				} );
				setRecurrence( normalizeRecurrence( res.fields.recurrence ) );
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
				setEditorKey( ( k ) => k + 1 );
				setLoading( false );
				if ( onLoad ) {
					onLoad();
				}
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( err && err.message ? err.message : __( 'Failed to load event data.', 'wporg-groups-frontend' ) );
				setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	};

	// Initial mount: register core blocks + fetch the form data.
	useEffect( () => {
		ensureCoreBlocksRegistered();
		return loadFormData( eventId );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ eventId ] );

	const buildPayload = () => ( {
		title: form.title,
		description: descriptionRef.current ? descriptionRef.current() : '',
		date: form.date,
		time_start: form.time_start,
		time_end: form.time_end,
		venue_id: parseInt( form.venue_select, 10 ) || 0,
		is_online: form.is_online,
		online_event_link: form.is_online ? form.online_event_link : '',
		featured_image_id: featuredImage.id,
		// Recurrence is locked once an event is published; drafts stay editable.
		// Send it only while unlocked, and never as `null`, which fails the
		// endpoint's object schema.
		...( recurrence && ! recurrence.locked ? { recurrence } : {} ),
		// Blank-labelled rows are just an empty slot the organizer
		// added and never filled in; the server drops them too.
		rsvp_questions: ( form.rsvp_questions || [] ).filter(
			( q ) => q.label.trim() !== ''
		),
	} );

	useImperativeHandle( ref, () => ( {
		getPayload: buildPayload,
		loadEvent: ( id ) => {
			loadFormData( id );
		},
		selectVenue: ( venue ) => {
			updateField( 'venue_select', String( venue.id ) );
			setVenues( ( prev ) => {
				const exists = prev.find( ( v ) => v.id === venue.id );
				if ( exists ) {
					return prev.map( ( v ) =>
						v.id === venue.id ? { ...v, name: venue.name } : v
					);
				}
				return [ ...prev, { id: venue.id, name: venue.name } ];
			} );
		},
		isLoading: () => loading,
		isSaving: () => saving,
	} ) );

	const onSubmit = ( e ) => {
		e.preventDefault();
		setSaving( true );
		setError( '' );

		onSubmitPayload( buildPayload() ).catch( ( err ) => {
			setSaving( false );
			setError( err && err.message ? err.message : __( 'Failed to save the event.', 'wporg-groups-frontend' ) );
		} );
	};

	if ( loading ) {
		return (
			<div className={ `${ classPrefix }__loading` }>
				<Spinner />
			</div>
		);
	}

	return (
		<form onSubmit={ onSubmit } className={ className }>
			{ header }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<TextControl
				label={ __( 'Event title', 'wporg-groups-frontend' ) }
				value={ form.title }
				onChange={ ( v ) => updateField( 'title', v ) }
				required
				__nextHasNoMarginBottom
			/>

			<div className={ `${ classPrefix }__field` }>
				<label className="components-base-control__label">
					{ __( 'Description', 'wporg-groups-frontend' ) }
				</label>
				<DescriptionEditor
					key={ editorKey }
					initialValue={ initialDescription }
					getValueRef={ descriptionRef }
					onDirty={ onDirty }
					classPrefix={ classPrefix }
				/>
			</div>

			<FeaturedImagePicker
				imageId={ featuredImage.id }
				imageUrl={ featuredImage.url }
				onChange={ ( id, url ) => {
					setFeaturedImage( { id, url } );
					markDirty();
				} }
				classPrefix={ classPrefix }
			/>

			<div className={ `${ classPrefix }__row` }>
				<TextControl
					label={ __( 'Date', 'wporg-groups-frontend' ) }
					type="date"
					value={ form.date }
					min={ isEdit ? undefined : MINIMUM_EVENT_DATE }
					onChange={ ( v ) => updateField( 'date', v ) }
					required
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Start time', 'wporg-groups-frontend' ) }
					type="time"
					value={ form.time_start }
					onChange={ ( v ) => updateField( 'time_start', v ) }
					required
					__nextHasNoMarginBottom
				/>
				<DurationField
					timeStart={ form.time_start }
					timeEnd={ form.time_end }
					onChange={ ( v ) => updateField( 'time_end', v ) }
					classPrefix={ classPrefix }
				/>
			</div>

			<RecurrenceControls
				value={ recurrence }
				eventDate={ form.date }
				onChange={ ( value ) => {
					setRecurrence( value );
					markChanged();
				} }
			/>

			<VenueField
				venues={ venues }
				venueId={ form.venue_select }
				onSelect={ ( v ) => updateField( 'venue_select', v ) }
				onOpenVenueEditor={ onOpenVenueEditor }
				classPrefix={ classPrefix }
			/>

			<div className={ `${ classPrefix }__online-event` }>
				<ToggleControl
					label={ __( 'This is an online event', 'wporg-groups-frontend' ) }
					checked={ form.is_online }
					onChange={ ( value ) => updateField( 'is_online', value ) }
					__nextHasNoMarginBottom
				/>
				{ form.is_online && (
					<TextControl
						label={ __( 'Online event link', 'wporg-groups-frontend' ) }
						type="url"
						value={ form.online_event_link }
						onChange={ ( value ) => updateField( 'online_event_link', value ) }
						placeholder="https://"
						required
						__nextHasNoMarginBottom
					/>
				) }
			</div>

			<RsvpQuestionsEditor
				questions={ form.rsvp_questions }
				onChange={ ( value ) => updateField( 'rsvp_questions', value ) }
			/>

			{ children }

			<div className={ `${ classPrefix }__actions` }>
				{ footerStart }
				<Button variant="tertiary" onClick={ onCancel } disabled={ saving }>
					{ _x( 'Cancel', 'abort current action', 'wporg-groups-frontend' ) }
				</Button>
				<Button variant="primary" type="submit" isBusy={ saving } disabled={ saving }>
					{ isEdit
						? __( 'Save changes', 'wporg-groups-frontend' )
						: __( 'Create event', 'wporg-groups-frontend' ) }
				</Button>
			</div>
		</form>
	);
}

export default forwardRef( EventForm );

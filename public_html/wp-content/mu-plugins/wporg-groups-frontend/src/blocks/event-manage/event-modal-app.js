/**
 * WPorg Groups Frontend — Event Modal.
 *
 * A small React app that mounts a modal dialog for creating or editing
 * GatherPress events. Listens for clicks on `[data-wporg-groups-modal]`
 * buttons anywhere in the document, fetches the relevant form data from
 * the `wporg-groups/v1` REST API, and renders an inline `@wordpress/block-editor`
 * instance for the description field alongside plain `<input>`s and
 * `<select>`s for the metadata.
 *
 * Written in vanilla JS using `wp.element.createElement` (aliased to `h`)
 * so the file can be loaded directly without a JSX build step. The trade
 * off is some extra verbosity at call sites; the app is small enough that
 * this is a fair price for not having to wire up `@wordpress/scripts` for
 * a single feature.
 *
 * @package WordCamp\Groups\Frontend
 */
import {
	createElement as h,
	useState,
	useEffect,
	useRef,
	render,
} from '@wordpress/element';
import {
	Modal,
	TextControl,
	Button,
	SelectControl,
	ToggleControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import VenueEditor from './venue-editor';
import MessageMembersModal from './message-members-modal';
import RecurrenceControls, { normalizeRecurrence } from '../../components/recurrence-controls';
import RsvpQuestionsEditor from './rsvp-questions-editor';
import DescriptionEditor, { ensureCoreBlocksRegistered } from '../../components/event-form/description-editor';
import FeaturedImagePicker from '../../components/event-form/featured-image-picker';
import DurationField from '../../components/event-form/duration-field';
import VenueField from '../../components/event-form/venue-field';

const NS =
	( window.wporgGroupsEventModal &&
		window.wporgGroupsEventModal.restNamespace ) ||
	'wporg-groups/v1';
const MINIMUM_EVENT_DATE = window.wporgGroupsEventModal?.minimumEventDate || '';

	/**
	 * Modal containing the create/edit form. Mode is `'create'` or `'edit'`,
	 * `eventId` is the integer post id when editing.
	 */
	const AUTOSAVE_INTERVAL_MS = 5000;
	const EMPTY_FORM = {
		title: '',
		date: '',
		time_start: '',
		time_end: '',
		venue_id: 0,
		venue_select: '',
		is_online: false,
		online_event_link: '',
		new_venue_name: '',
		new_venue_address: '',
		rsvp_questions: [],
	};

	function EventModal( { mode, eventId, onClose } ) {
		const isEdit = mode === 'edit' && eventId > 0;

		const [ loading, setLoading ] = useState( true );
		const [ saving, setSaving ] = useState( false );
		const [ error, setError ] = useState( '' );

		// Description is intentionally NOT in form state — the inline block
		// editor owns it. We grab the current value via `descriptionRef`
		// only at submit/autosave time.
		const [ form, setForm ] = useState( EMPTY_FORM );
		const [ initialDescription, setInitialDescription ] = useState( '' );
		const [ featuredImage, setFeaturedImage ] = useState( { id: 0, url: '' } );
		const [ recurrence, setRecurrence ] = useState( null );
		const [ venues, setVenues ] = useState( [] );
		const descriptionRef = useRef( () => '' );

		// Drafts: list of available drafts (create mode only) and the id
		// of the draft we're currently autosaving to (0 = none yet).
		const [ drafts, setDrafts ] = useState( [] );
		const [ draftId, setDraftId ] = useState( 0 );
		const [ autosaveStatus, setAutosaveStatus ] = useState( '' );
		const [ autosaveTime, setAutosaveTime ] = useState( null );
		const [ venueEditorOpen, setVenueEditorOpen ] = useState( false );
		const [ venueEditorId, setVenueEditorId ] = useState( 0 );

		// Dirty tracking — set on any user input. Drives autosave + the
		// close-confirmation prompt.
		const [ dirty, setDirty ] = useState( false );
		const dirtyRef = useRef( false );
		dirtyRef.current = dirty;

		// Bumped whenever we load fresh data into the form (initial mount,
		// draft picker selection). Used as a `key` on `DescriptionEditor`
		// to force a remount with the new initial value.
		const [ editorKey, setEditorKey ] = useState( 0 );

		const markDirty = () => {
			if ( ! dirtyRef.current ) {
				setDirty( true );
			}
		};

		const loadFormData = ( opts ) => {
			let cancelled = false;
			setLoading( true );
			setError( '' );

			const path = opts.eventId
				? `/${ NS }/event-form-data?event_id=${ opts.eventId }`
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
						venue_id: res.fields.venue_id || 0,
						venue_select: res.fields.venue_id ? String( res.fields.venue_id ) : '',
						is_online: !! res.fields.is_online,
						online_event_link: res.fields.online_event_link || '',
						new_venue_name: '',
						new_venue_address: '',
						rsvp_questions: res.fields.rsvp_questions || [],
					} );
					setEditorKey( ( k ) => k + 1 );
					setDirty( false );
					setLoading( false );
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

		// Initial mount: register core blocks + fetch the form data + (in
		// create mode) fetch the existing drafts list.
		useEffect( () => {
			ensureCoreBlocksRegistered();
			const cleanup = loadFormData( { eventId: isEdit ? eventId : 0 } );

			if ( ! isEdit ) {
				apiFetch( { path: `/${ NS }/drafts` } )
					.then( ( res ) => setDrafts( Array.isArray( res ) ? res : [] ) )
					.catch( () => {} );
			}

			return cleanup;
		// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ isEdit, eventId ] );

		const buildPayload = () => {
			const isAddingNewVenue = form.venue_select === '__new__';
			return {
				title: form.title,
				description: descriptionRef.current ? descriptionRef.current() : '',
				date: form.date,
				time_start: form.time_start,
				time_end: form.time_end,
				venue_id: isAddingNewVenue ? 0 : ( parseInt( form.venue_select, 10 ) || 0 ),
				is_online: form.is_online,
				online_event_link: form.is_online ? form.online_event_link : '',
				new_venue_name: isAddingNewVenue ? form.new_venue_name : '',
				new_venue_address: isAddingNewVenue ? form.new_venue_address : '',
				featured_image_id: featuredImage.id,
				// Recurrence is locked (uneditable) once an event is published,
				// so editing an existing event never needs to resend it — and
				// must not send `null`, which fails the endpoint's object schema.
				...( isEdit ? {} : { recurrence } ),
				// Blank-labelled rows are just an empty slot the organizer
				// added and never filled in; the server drops them too.
				rsvp_questions: ( form.rsvp_questions || [] ).filter(
					( q ) => q.label.trim() !== ''
				),
			};
		};

		// Autosave: every AUTOSAVE_INTERVAL_MS, if the form is dirty and we
		// aren't already saving, push a draft. Only runs in create mode —
		// for edit mode, autosaving over a published event would be too
		// surprising. The user's safety net there is the close-confirm
		// prompt instead.
		useEffect( () => {
			if ( isEdit ) {
				return undefined;
			}
			const interval = setInterval( () => {
				if ( ! dirtyRef.current || saving || loading ) {
					return;
				}
				setAutosaveStatus( 'saving' );
				const payload = buildPayload();
				const path = draftId
					? `/${ NS }/draft/${ draftId }`
					: `/${ NS }/draft`;
				apiFetch( { path, method: 'POST', data: payload } )
					.then( ( res ) => {
						if ( res && res.id && ! draftId ) {
							setDraftId( res.id );
						}
						setAutosaveStatus( 'saved' );
						setAutosaveTime( new Date() );
						setDirty( false );
					} )
					.catch( () => {
						setAutosaveStatus( 'error' );
					} );
			}, AUTOSAVE_INTERVAL_MS );
			return () => clearInterval( interval );
		// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ isEdit, draftId, saving, loading, form, recurrence ] );

		const updateField = ( field, value ) => {
			setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );
			markDirty();
		};

		const handleSelectDraft = ( id ) => {
			if ( ! id ) {
				return;
			}
			setDraftId( parseInt( id, 10 ) );
			loadFormData( { eventId: parseInt( id, 10 ) } );
		};

		const handleStartFresh = () => {
			setDraftId( 0 );
			setAutosaveStatus( '' );
			setAutosaveTime( null );
			loadFormData( { eventId: 0 } );
		};

		const onSubmit = ( e ) => {
			e.preventDefault();
			setSaving( true );
			setError( '' );

			const payload = buildPayload();

			let path;
			if ( isEdit ) {
				path = `/${ NS }/event/${ eventId }`;
			} else if ( draftId ) {
				// Promoting a draft to a published event.
				path = `/${ NS }/draft/${ draftId }/publish`;
			} else {
				path = `/${ NS }/event`;
			}

			apiFetch( { path, method: 'POST', data: payload } )
				.then( ( res ) => {
					setDirty( false );
					if ( res && res.permalink ) {
						window.location.href = res.permalink;
					} else {
						window.location.reload();
					}
				} )
				.catch( ( err ) => {
					setSaving( false );
					setError( err && err.message ? err.message : __( 'Failed to save the event.', 'wporg-groups-frontend' ) );
				} );
		};

		// `handleClose` prompts before closing only when the user has
		// actually interacted with the form. We *don't* poke at form values
		// here — pre-filled defaults (next-week date, last venue, last
		// time-of-day) and the editor's empty-paragraph initial state both
		// look like "content" but should not trigger the prompt. The
		// `dirty` flag is our source of truth: it's set by `markDirty()`
		// from any field's `onChange` and from the inline editor's
		// `onDirty` callback the first time the user touches it.
		//
		// We also prompt when there's an existing draft id, because
		// closing a draft mid-edit is worth confirming even if the
		// current keystrokes are already auto-saved.
		const handleClose = () => {
			if ( saving ) {
				return;
			}
			const shouldPrompt = dirty || draftId > 0;
			if ( shouldPrompt ) {
				const message = draftId
					? __( 'This event has been auto-saved as a draft. Close the form?', 'wporg-groups-frontend' )
					: __( 'You have unsaved changes. Close this form anyway?', 'wporg-groups-frontend' );
				if ( ! window.confirm( message ) ) {
					return;
				}
			}
			onClose();
		};

		useEffect( () => {
			const onEscape = ( ev ) => {
				if ( ev.key === 'Escape' ) {
					ev.stopPropagation();
					ev.preventDefault();
					handleClose();
				}
			};
			document.addEventListener( 'keydown', onEscape, true );
			return () => document.removeEventListener( 'keydown', onEscape, true );
		} );

		const showDraftPicker = ! isEdit && drafts.length > 0;

		const autosaveLabel = ( () => {
			if ( isEdit ) {
				return '';
			}
			if ( autosaveStatus === 'saving' ) {
				return __( 'Saving draft…', 'wporg-groups-frontend' );
			}
			if ( autosaveStatus === 'error' ) {
				return __( 'Couldn\u2019t autosave', 'wporg-groups-frontend' );
			}
			if ( autosaveStatus === 'saved' && autosaveTime ) {
				const t = autosaveTime;
				const hh = String( t.getHours() ).padStart( 2, '0' );
				const mm = String( t.getMinutes() ).padStart( 2, '0' );
				return __( 'Draft saved at', 'wporg-groups-frontend' ) + ` ${ hh }:${ mm }`;
			}
			return '';
		} )();

		return h(
			Modal,
			{
				title: isEdit
					? __( 'Edit event', 'wporg-groups-frontend' )
					: __( 'Create event', 'wporg-groups-frontend' ),
				onRequestClose: handleClose,
				className: 'wporg-groups-modal-accent wporg-groups-event-modal',
				size: 'large',
				shouldCloseOnClickOutside: false,
			},
			loading
				? h( 'div', { className: 'wporg-groups-event-modal__loading' }, h( Spinner, {} ) )
				: h(
					'form',
					{ onSubmit: onSubmit, className: 'wporg-groups-event-modal__form' },
					error &&
						h( Notice, { status: 'error', isDismissible: false }, error ),

					showDraftPicker &&
						h(
							'div',
							{ className: 'wporg-groups-event-modal__draft-picker' },
							h(
								SelectControl,
								{
									label: __( 'Continue from a draft', 'wporg-groups-frontend' ),
									value: draftId ? String( draftId ) : '',
									options: [
										{ label: __( '— Start fresh —', 'wporg-groups-frontend' ), value: '' },
									].concat(
										drafts.map( ( d ) => ( {
											label: ( d.title || __( '(Untitled)', 'wporg-groups-frontend' ) )
												+ ( d.event_date ? ` — ${ d.event_date.slice( 0, 10 ) }` : '' ),
											value: String( d.id ),
										} ) )
									),
									onChange: ( v ) => {
										if ( v === '' ) {
											handleStartFresh();
										} else {
											handleSelectDraft( v );
										}
									},
									__nextHasNoMarginBottom: true,
								}
							)
						),

					h( TextControl, {
						label: __( 'Event title', 'wporg-groups-frontend' ),
						value: form.title,
						onChange: ( v ) => updateField( 'title', v ),
						required: true,
						__nextHasNoMarginBottom: true,
					} ),

					h(
						'div',
						{ className: 'wporg-groups-event-modal__field' },
						h(
							'label',
							{ className: 'components-base-control__label' },
							__( 'Description', 'wporg-groups-frontend' )
						),
						h( DescriptionEditor, {
							key: editorKey,
							initialValue: initialDescription,
							getValueRef: descriptionRef,
							onDirty: markDirty,
							classPrefix: 'wporg-groups-event-modal',
						} )
					),

					h( FeaturedImagePicker, {
						imageId: featuredImage.id,
						imageUrl: featuredImage.url,
						onChange: ( id, url ) => {
							setFeaturedImage( { id, url } );
							markDirty();
						},
						classPrefix: 'wporg-groups-event-modal',
					} ),

					h(
						'div',
						{ className: 'wporg-groups-event-modal__row' },
						h( TextControl, {
							label: __( 'Date', 'wporg-groups-frontend' ),
							type: 'date',
							value: form.date,
							min: isEdit ? undefined : MINIMUM_EVENT_DATE,
							onChange: ( v ) => updateField( 'date', v ),
							required: true,
							__nextHasNoMarginBottom: true,
						} ),
						h( TextControl, {
							label: __( 'Start time', 'wporg-groups-frontend' ),
							type: 'time',
							value: form.time_start,
							onChange: ( v ) => updateField( 'time_start', v ),
							required: true,
							__nextHasNoMarginBottom: true,
						} ),
						h( DurationField, {
							timeStart: form.time_start,
							timeEnd: form.time_end,
							onChange: ( v ) => {
								updateField( 'time_end', v );
								markDirty();
							},
							classPrefix: 'wporg-groups-event-modal',
						} )
					),

					h( RecurrenceControls, {
						value: recurrence,
						eventDate: form.date,
						onChange: ( value ) => {
							setRecurrence( value );
							markDirty();
						},
					} ),

					h( VenueField, {
						venues: venues,
						venueId: form.venue_select,
						onSelect: ( v ) => {
							updateField( 'venue_select', v );
							markDirty();
						},
						onOpenVenueEditor: ( id ) => {
							setVenueEditorId( id );
							setVenueEditorOpen( true );
						},
						classPrefix: 'wporg-groups-event-modal',
					} ),

					h(
						'div',
						{ className: 'wporg-groups-event-modal__online-event' },
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

					h(
						'div',
						{ className: 'wporg-groups-event-modal__actions' },
						h(
							'span',
							{
								className: 'wporg-groups-event-modal__autosave wporg-groups-event-modal__autosave--' + ( autosaveStatus || 'idle' ),
							},
							autosaveLabel
						),
						h(
							Button,
							{ variant: 'tertiary', onClick: handleClose, disabled: saving },
							_x( 'Cancel', 'abort current action', 'wporg-groups-frontend' )
						),
						h(
							Button,
							{ variant: 'primary', type: 'submit', isBusy: saving, disabled: saving },
							isEdit
								? __( 'Save changes', 'wporg-groups-frontend' )
								: __( 'Create event', 'wporg-groups-frontend' )
						)
					)
				),
				venueEditorOpen && h( VenueEditor, {
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
				} )
		);
	}

	/**
	 * Top-level mounted component. Owns the open/closed state and listens
	 * for `[data-wporg-groups-modal]` button clicks anywhere on the page.
	 */
	function App() {
		const [ state, setState ] = useState( { open: false, mode: 'create', eventId: 0 } );

		useEffect( () => {
			const onClick = ( ev ) => {
				const trigger = ev.target.closest( '[data-wporg-groups-modal]' );
				if ( ! trigger ) {
					return;
				}
				ev.preventDefault();
				const mode = trigger.getAttribute( 'data-wporg-groups-modal' );
				const eventId = parseInt( trigger.getAttribute( 'data-wporg-groups-event-id' ) || '0', 10 );
				setState( { open: true, mode: mode, eventId: eventId } );
			};
			document.addEventListener( 'click', onClick );
			return () => {
				document.removeEventListener( 'click', onClick );
			};
		}, [] );

		if ( ! state.open ) {
			return null;
		}

		if ( [ 'message-all', 'message-attendees' ].includes( state.mode ) ) {
			return h( MessageMembersModal, {
				eventId: state.eventId,
				recipientMode: state.mode,
				onClose: () => setState( { open: false, mode: 'create', eventId: 0 } ),
			} );
		}

		return h( EventModal, {
			mode: state.mode,
			eventId: state.eventId,
			onClose: () => setState( { open: false, mode: 'create', eventId: 0 } ),
		} );
	}

	function mount() {
		const root = document.getElementById( 'wporg-groups-event-modal-root' );
		if ( ! root ) {
			return;
		}
		render( h( App, {} ), root );
	}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}

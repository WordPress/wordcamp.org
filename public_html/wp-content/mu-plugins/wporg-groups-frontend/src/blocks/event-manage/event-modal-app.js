/**
 * WPorg Groups Frontend — Event Modal.
 *
 * A small React app that mounts a modal dialog for creating or editing
 * GatherPress events. Listens for clicks on `[data-wporg-groups-modal]`
 * buttons anywhere in the document and wraps the shared `EventForm` with
 * the modal's own chrome: draft picker, autosave and the close-confirmation
 * prompt.
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
import { Modal, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import VenueEditor from './venue-editor';
import MessageMembersModal from './message-members-modal';
import EventForm, { NS } from '../../components/event-form/event-form';

	const AUTOSAVE_INTERVAL_MS = 5000;

	/**
	 * Modal containing the create/edit form. Mode is `'create'` or `'edit'`,
	 * `eventId` is the integer post id when editing.
	 */
	function EventModal( { mode, eventId, onClose } ) {
		const isEdit = mode === 'edit' && eventId > 0;
		const formRef = useRef( null );

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

		// Bumped on every field change and load so the autosave countdown
		// below restarts.
		const [ changeCount, setChangeCount ] = useState( 0 );
		const restartAutosave = () => setChangeCount( ( c ) => c + 1 );

		const markDirty = () => {
			if ( ! dirtyRef.current ) {
				setDirty( true );
			}
		};

		// Create mode: fetch the existing drafts list.
		useEffect( () => {
			if ( isEdit ) {
				return;
			}
			apiFetch( { path: `/${ NS }/drafts` } )
				.then( ( res ) => setDrafts( Array.isArray( res ) ? res : [] ) )
				.catch( () => {} );
		}, [ isEdit ] );

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
				const form = formRef.current;
				if ( ! dirtyRef.current || ! form || form.isSaving() || form.isLoading() ) {
					return;
				}
				setAutosaveStatus( 'saving' );
				const payload = form.getPayload();
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
		}, [ isEdit, draftId, changeCount ] );

		const handleSelectDraft = ( id ) => {
			if ( ! id ) {
				return;
			}
			setDraftId( parseInt( id, 10 ) );
			formRef.current.loadEvent( parseInt( id, 10 ) );
		};

		const handleStartFresh = () => {
			setDraftId( 0 );
			setAutosaveStatus( '' );
			setAutosaveTime( null );
			formRef.current.loadEvent( 0 );
		};

		const submitPayload = ( payload ) => {
			let path;
			if ( isEdit ) {
				path = `/${ NS }/event/${ eventId }`;
			} else if ( draftId ) {
				// Promoting a draft to a published event.
				path = `/${ NS }/draft/${ draftId }/publish`;
			} else {
				path = `/${ NS }/event`;
			}

			return apiFetch( { path, method: 'POST', data: payload } )
				.then( ( res ) => {
					setDirty( false );
					if ( res && res.permalink ) {
						window.location.href = res.permalink;
					} else {
						window.location.reload();
					}
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
			if ( formRef.current && formRef.current.isSaving() ) {
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
			h( EventForm, {
				ref: formRef,
				mode: isEdit ? 'edit' : 'create',
				eventId: isEdit ? eventId : 0,
				classPrefix: 'wporg-groups-event-modal',
				className: 'wporg-groups-event-modal__form',
				onSubmitPayload: submitPayload,
				onCancel: handleClose,
				onOpenVenueEditor: ( id ) => {
					setVenueEditorId( id );
					setVenueEditorOpen( true );
				},
				onChange: restartAutosave,
				onDirty: markDirty,
				onLoad: () => {
					setDirty( false );
					restartAutosave();
				},
				header: showDraftPicker &&
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
				footerStart: h(
					'span',
					{
						className: 'wporg-groups-event-modal__autosave wporg-groups-event-modal__autosave--' + ( autosaveStatus || 'idle' ),
					},
					autosaveLabel
				),
			} ),
			venueEditorOpen && h( VenueEditor, {
				venueId: venueEditorId,
				onSave: ( saved ) => {
					setVenueEditorOpen( false );
					formRef.current.selectVenue( saved );
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

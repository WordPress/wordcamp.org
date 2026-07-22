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
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.components || ! wp.blockEditor ) {
		// Required dependencies missing — bail rather than crash the page.
		return;
	}

	const { createElement: h, useState, useEffect, useRef, render, Fragment } = wp.element;
	const { Modal, TextControl, Button, SelectControl, Notice, Spinner } = wp.components;
	const {
		BlockEditorProvider,
		BlockList,
		WritingFlow,
		ObserveTyping,
		BlockTools,
	} = wp.blockEditor;
	const { registerCoreBlocks } = wp.blockLibrary;
	const { parse, serialize } = wp.blocks;
	const apiFetch = wp.apiFetch;
	const __ = wp.i18n.__;

	const NS = ( window.wporgGroupsEventModal && window.wporgGroupsEventModal.restNamespace ) || 'wporg-groups/v1';

	let coreBlocksRegistered = false;
	function ensureCoreBlocksRegistered() {
		if ( coreBlocksRegistered ) {
			return;
		}
		try {
			registerCoreBlocks();
		} catch ( e ) {
			// `registerCoreBlocks` complains if called twice, but in some
			// page contexts the editor isn't loaded yet — swallow.
		}
		coreBlocksRegistered = true;
	}

	/**
	 * Inline Gutenberg editor for the description field.
	 *
	 * Uses the canonical "self-contained" embedding pattern:
	 *
	 *   - Block state lives entirely inside this component.
	 *   - The parent passes `initialValue` once and **never** feeds new
	 *     values back into the editor (no `value` prop, no `useEffect` on
	 *     value, no setState ping-pong).
	 *   - When the parent needs the serialised markup at submit time it
	 *     calls `getValueRef.current()` — the editor exposes an imperative
	 *     getter via the supplied ref instead of pushing every keystroke
	 *     up the tree.
	 *
	 * This avoids the feedback loop that was causing per-keystroke lag and
	 * breaking the slash inserter (parent re-renders were tearing down the
	 * editor's internal state on every input event).
	 */
	function DescriptionEditor( { initialValue, getValueRef, onDirty } ) {
		const [ blocks, setBlocks ] = useState( () => parse( initialValue || '' ) );

		// Keep the imperative getter pointed at the latest block state.
		// `useEffect` on `[ blocks ]` would also work but assigning during
		// render is cheap and avoids one extra effect run per keystroke.
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
			{ className: 'wporg-groups-event-modal__editor' },
			h(
				BlockEditorProvider,
				{
					value: blocks,
					onInput: handleChange,
					onChange: handleChange,
					settings: {
						hasFixedToolbar: true,
					},
				},
				h(
					BlockTools,
					{},
					h(
						WritingFlow,
						{},
						h(
							ObserveTyping,
							{},
							h( BlockList, {} )
						)
					)
				)
			)
		);
	}

	/**
	 * Featured image picker — opens the standard `wp.media` library frame
	 * so the organizer can either upload a new image or pick an existing
	 * one from the site's media library. The selected attachment id is
	 * lifted up to the parent via `onChange` and the thumbnail URL is
	 * displayed inline as a preview.
	 */
	function FeaturedImagePicker( { imageId, imageUrl, onChange } ) {
		const openMediaFrame = () => {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			const frame = window.wp.media( {
				title: __( 'Select a featured image', 'wporg-groups-frontend' ),
				button: { text: __( 'Use this image', 'wporg-groups-frontend' ) },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				const url = attachment.sizes && attachment.sizes.medium
					? attachment.sizes.medium.url
					: attachment.url;
				onChange( attachment.id, url );
			} );
			frame.open();
		};

		const handleRemove = () => {
			onChange( 0, '' );
		};

		return h(
			'div',
			{ className: 'wporg-groups-event-modal__field' },
			h(
				'label',
				{ className: 'components-base-control__label' },
				__( 'Featured image', 'wporg-groups-frontend' )
			),
			h(
				'div',
				{ className: 'wporg-groups-event-modal__featured' },
				imageId
					? h(
						'div',
						{ className: 'wporg-groups-event-modal__featured-preview' },
						h( 'img', { src: imageUrl, alt: '' } ),
						h(
							'div',
							{ className: 'wporg-groups-event-modal__featured-actions' },
							h(
								Button,
								{ variant: 'secondary', onClick: openMediaFrame },
								__( 'Replace', 'wporg-groups-frontend' )
							),
							h(
								Button,
								{ variant: 'tertiary', isDestructive: true, onClick: handleRemove },
								__( 'Remove', 'wporg-groups-frontend' )
							)
						)
					)
					: h(
						Button,
						{ variant: 'secondary', onClick: openMediaFrame },
						__( 'Choose featured image', 'wporg-groups-frontend' )
					)
			)
		);
	}

	function VenueField( { venues, venueId, onSelectExisting, newVenueName, newVenueAddress, onChangeNewVenue } ) {
		const isAddingNew = venueId === '__new__';
		const options = [
			{ label: __( '— Select a venue —', 'wporg-groups-frontend' ), value: '' },
		].concat(
			( venues || [] ).map( ( v ) => ( {
				label: v.name,
				value: String( v.id ),
			} ) )
		).concat( [
			{ label: __( '+ Add a new venue', 'wporg-groups-frontend' ), value: '__new__' },
		] );

		return h(
			Fragment,
			{},
			h( SelectControl, {
				label: __( 'Venue', 'wporg-groups-frontend' ),
				value: isAddingNew ? '__new__' : ( venueId ? String( venueId ) : '' ),
				options: options,
				onChange: onSelectExisting,
				__nextHasNoMarginBottom: true,
			} ),
			isAddingNew &&
				h(
					'div',
					{ className: 'wporg-groups-event-modal__new-venue' },
					h( TextControl, {
						label: __( 'New venue name', 'wporg-groups-frontend' ),
						value: newVenueName,
						onChange: ( v ) => onChangeNewVenue( 'name', v ),
						__nextHasNoMarginBottom: true,
					} ),
					h( TextControl, {
						label: __( 'New venue address', 'wporg-groups-frontend' ),
						value: newVenueAddress,
						onChange: ( v ) => onChangeNewVenue( 'address', v ),
						__nextHasNoMarginBottom: true,
					} )
				)
		);
	}

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
		new_venue_name: '',
		new_venue_address: '',
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
		const [ venues, setVenues ] = useState( [] );
		const descriptionRef = useRef( () => '' );

		// Drafts: list of available drafts (create mode only) and the id
		// of the draft we're currently autosaving to (0 = none yet).
		const [ drafts, setDrafts ] = useState( [] );
		const [ draftId, setDraftId ] = useState( 0 );
		const [ autosaveStatus, setAutosaveStatus ] = useState( '' );
		const [ autosaveTime, setAutosaveTime ] = useState( null );

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
					setForm( {
						title: res.fields.title || '',
						date: res.fields.date || '',
						time_start: res.fields.time_start || '',
						time_end: res.fields.time_end || '',
						venue_id: res.fields.venue_id || 0,
						venue_select: res.fields.venue_id ? String( res.fields.venue_id ) : '',
						new_venue_name: '',
						new_venue_address: '',
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
				new_venue_name: isAddingNewVenue ? form.new_venue_name : '',
				new_venue_address: isAddingNewVenue ? form.new_venue_address : '',
				featured_image_id: featuredImage.id,
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
		}, [ isEdit, draftId, saving, loading, form ] );

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
				className: 'wporg-groups-event-modal',
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
						} )
					),

					h( FeaturedImagePicker, {
						imageId: featuredImage.id,
						imageUrl: featuredImage.url,
						onChange: ( id, url ) => {
							setFeaturedImage( { id, url } );
							markDirty();
						},
					} ),

					h(
						'div',
						{ className: 'wporg-groups-event-modal__row' },
						h( TextControl, {
							label: __( 'Date', 'wporg-groups-frontend' ),
							type: 'date',
							value: form.date,
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
						h( TextControl, {
							label: __( 'End time', 'wporg-groups-frontend' ),
							type: 'time',
							value: form.time_end,
							onChange: ( v ) => updateField( 'time_end', v ),
							required: true,
							__nextHasNoMarginBottom: true,
						} )
					),

					h( VenueField, {
						venues: venues,
						venueId: form.venue_select,
						onSelectExisting: ( v ) => updateField( 'venue_select', v ),
						newVenueName: form.new_venue_name,
						newVenueAddress: form.new_venue_address,
						onChangeNewVenue: ( field, v ) =>
							updateField( field === 'name' ? 'new_venue_name' : 'new_venue_address', v ),
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
							__( 'Cancel', 'wporg-groups-frontend' )
						),
						h(
							Button,
							{ variant: 'primary', type: 'submit', isBusy: saving, disabled: saving },
							isEdit
								? __( 'Save changes', 'wporg-groups-frontend' )
								: __( 'Create event', 'wporg-groups-frontend' )
						)
					)
				)
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
} )( window.wp );

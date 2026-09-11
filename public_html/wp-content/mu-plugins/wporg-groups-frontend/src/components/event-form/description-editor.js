/**
 * Inline Gutenberg editor for the event description field, shared by the
 * event modal and the group-settings Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import {
	createElement as h,
	useState,
	useEffect,
	useRef,
	useCallback,
} from '@wordpress/element';
import {
	BlockEditorProvider,
	BlockList,
	BlockToolbar,
	WritingFlow,
	ObserveTyping,
	BlockTools,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useDispatch } from '@wordpress/data';
import { registerCoreBlocks } from '@wordpress/block-library';
import { registerCoreFormatTypes } from '@wordpress/format-library';
import { createBlock, parse, serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { isAppleOS } from '@wordpress/keycodes';
import {
	ShortcutProvider,
	store as keyboardShortcutsStore,
} from '@wordpress/keyboard-shortcuts';
import { ALLOWED_BLOCK_TYPES } from './constants';
import {
	MAX_HISTORY_LENGTH,
	createHistoryManager,
	recordInput,
	recordChange,
	stepUndo,
	stepRedo,
	handleEditorKeyDown,
} from './description-editor-history';

export { ALLOWED_BLOCK_TYPES };

let coreBlocksRegistered = false;

export function ensureCoreBlocksRegistered() {
	if ( coreBlocksRegistered ) {
		return;
	}
	try {
		registerCoreBlocks();
	} catch ( e ) {
		// `registerCoreBlocks` complains if called twice, but in some
		// page contexts the editor isn't loaded yet - swallow.
	}
	try {
		registerCoreFormatTypes();
	} catch ( e ) {
		// `registerCoreFormatTypes` may throw if called twice - swallow.
	}
	coreBlocksRegistered = true;
}

// `BlockEditorProvider` gives its subtree an isolated `core/block-editor`
// registry, so this dispatch only reaches it from a component rendered
// *inside* the provider - a sibling effect would select a block in the
// wrong (default) store and `BlockToolbar` would never see it.
function SelectFirstBlockOnMount( { clientId } ) {
	const { selectBlock } = useDispatch( blockEditorStore );

	useEffect( () => {
		if ( clientId ) {
			// `null` (instead of the default `0`) selects the block
			// without also moving real DOM focus into it - see
			// `useFocusFirstElement` in `@wordpress/block-editor`. We
			// only need the toolbar to appear, not to steal focus from
			// the modal on open.
			selectBlock( clientId, null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return null;
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
 *     calls `getValueRef.current()` - the editor exposes an imperative
 *     getter via the supplied ref instead of pushing every keystroke
 *     up the tree.
 *   - Maintains an undo/redo stack for block edits and keyboard shortcuts.
 *
 * This avoids the feedback loop that was causing per-keystroke lag and
 * breaking the slash inserter (parent re-renders were tearing down the
 * editor's internal state on every input event).
 *
 * @param {Object}   props
 * @param {string}   props.initialValue Serialised block markup to seed with.
 * @param {Object}   props.getValueRef  Ref that receives the serialise getter.
 * @param {Function} props.onDirty      Optional; called on any edit.
 * @param {string}   props.classPrefix  BEM block prefix of the host surface.
 */
export default function DescriptionEditor( { initialValue, getValueRef, onDirty, classPrefix } ) {
	// A description with no supported blocks (empty string, or markup
	// that doesn't parse into anything) yields `[]`, leaving no block to
	// select and the toolbar permanently empty - fall back to an empty
	// paragraph so there's always a first block.
	const [ blocks, setBlocks ] = useState( () => {
		const parsed = parse( initialValue || '' ).filter( ( block ) =>
			ALLOWED_BLOCK_TYPES.includes( block.name )
		);
		return parsed.length ? parsed : [ createBlock( 'core/paragraph' ) ];
	} );

	const historyRef = useRef( null );
	if ( ! historyRef.current ) {
		historyRef.current = createHistoryManager( blocks );
	}

	const keyboardShortcutsDispatch = useDispatch( keyboardShortcutsStore );

	useEffect( () => {
		if ( keyboardShortcutsDispatch?.registerShortcut ) {
			keyboardShortcutsDispatch.registerShortcut( {
				name: 'wporg-groups/description-undo',
				category: 'global',
				description: __( 'Undo the last change.', 'wporg-groups-frontend' ),
				keyCombination: {
					modifier: 'primary',
					character: 'z',
				},
			} );
			keyboardShortcutsDispatch.registerShortcut( {
				name: 'wporg-groups/description-redo',
				category: 'global',
				description: __( 'Redo the last undone change.', 'wporg-groups-frontend' ),
				keyCombination: {
					modifier: 'primaryShift',
					character: 'z',
				},
				aliases: [
					{
						modifier: 'primary',
						character: 'y',
					},
				],
			} );
		}

		return () => {
			if ( keyboardShortcutsDispatch?.unregisterShortcut ) {
				keyboardShortcutsDispatch.unregisterShortcut( 'wporg-groups/description-undo' );
				keyboardShortcutsDispatch.unregisterShortcut( 'wporg-groups/description-redo' );
			}
		};
	}, [ keyboardShortcutsDispatch ] );

	if ( getValueRef ) {
		getValueRef.current = () => serialize( historyRef.current.present );
	}

	const handleInput = useCallback(
		( newBlocks ) => {
			recordInput( historyRef.current, newBlocks );
			setBlocks( newBlocks );
			if ( onDirty ) {
				onDirty();
			}
		},
		[ onDirty ]
	);

	const handleChange = useCallback(
		( newBlocks ) => {
			recordChange( historyRef.current, newBlocks, serialize, MAX_HISTORY_LENGTH );
			setBlocks( newBlocks );
			if ( onDirty ) {
				onDirty();
			}
		},
		[ onDirty ]
	);

	const undo = useCallback( () => {
		const target = stepUndo( historyRef.current, serialize );
		if ( target ) {
			setBlocks( target );
			if ( onDirty ) {
				onDirty();
			}
		}
	}, [ onDirty ] );

	const redo = useCallback( () => {
		const target = stepRedo( historyRef.current, MAX_HISTORY_LENGTH );
		if ( target ) {
			setBlocks( target );
			if ( onDirty ) {
				onDirty();
			}
		}
	}, [ onDirty ] );

	const handleKeyDown = useCallback(
		( event ) => {
			handleEditorKeyDown(
				event,
				{ onUndo: undo, onRedo: redo },
				isAppleOS()
			);
		},
		[ undo, redo ]
	);

	return h(
		ShortcutProvider,
		{
			className: `${ classPrefix }__editor`,
			onKeyDown: handleKeyDown,
		},
		h(
			BlockEditorProvider,
			{
				value: blocks,
				onInput: handleInput,
				onChange: handleChange,
				settings: {
					hasFixedToolbar: true,
					allowedBlockTypes: ALLOWED_BLOCK_TYPES,
				},
			},
			h( SelectFirstBlockOnMount, { clientId: blocks[ 0 ]?.clientId } ),
			h(
				'div',
				{ className: `${ classPrefix }__editor-toolbar` },
				h( BlockToolbar, { hideDragHandle: true } )
			),
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

/**
 * History manager for the event description block editor.
 *
 * Implements an undo/redo stack that distinguishes between transient
 * keystrokes (onInput) and committed block changes (onChange).
 *
 * @package WordCamp\Groups\Frontend
 */

export const MAX_HISTORY_LENGTH = 50;

/**
 * Deep clone an arbitrary value (objects, arrays, primitives).
 *
 * @param {*} value The value to deep clone.
 * @return {*} Cloned value.
 */
function deepClone( value ) {
	if ( null === value || typeof value !== 'object' ) {
		return value;
	}
	if ( Array.isArray( value ) ) {
		return value.map( deepClone );
	}
	const copy = {};
	for ( const key of Object.keys( value ) ) {
		copy[ key ] = deepClone( value[ key ] );
	}
	return copy;
}

/**
 * Deep clone an array of blocks while preserving clientIds and attributes.
 *
 * @param {Array} blockList Array of block objects to clone.
 * @return {Array} Cloned array of block objects.
 */
export function cloneBlocks( blockList ) {
	return blockList.map( ( block ) => ( {
		...block,
		attributes: deepClone( block.attributes ),
		innerBlocks: cloneBlocks( block.innerBlocks || [] ),
	} ) );
}

/**
 * Initialize a new history manager state.
 *
 * @param {Array} initialBlocks Initial blocks array.
 * @return {Object} Initialized history manager object.
 */
export function createHistoryManager( initialBlocks ) {
	return {
		past: [],
		future: [],
		present: initialBlocks,
		lastCommitted: initialBlocks,
		lastCommittedSerialized: null,
	};
}

/**
 * Record a transient input event (e.g. typing) without committing to past history.
 *
 * @param {Object} history   History manager object.
 * @param {Array}  newBlocks Updated blocks array.
 */
export function recordInput( history, newBlocks ) {
	history.present = newBlocks;
}

/**
 * Record a committed change event, saving the previous state into past history.
 *
 * @param {Object}   history     History manager object.
 * @param {Array}    newBlocks   Updated blocks array.
 * @param {Function} serializeFn Function that serializes a block array to a string.
 * @param {number}   maxHistory  Maximum number of history entries to retain.
 */
export function recordChange( history, newBlocks, serializeFn, maxHistory = MAX_HISTORY_LENGTH ) {
	const newSerialized = serializeFn( newBlocks );
	const lastCommittedSerialized =
		history.lastCommittedSerialized ?? serializeFn( history.lastCommitted );

	if ( newSerialized !== lastCommittedSerialized ) {
		history.past.push( cloneBlocks( history.lastCommitted ) );
		if ( history.past.length > maxHistory ) {
			history.past.shift();
		}
		history.future = [];
		history.lastCommitted = newBlocks;
		history.lastCommittedSerialized = newSerialized;
	}

	history.present = newBlocks;
}

/**
 * Step back to the previous state in history.
 *
 * @param {Object}   history     History manager object.
 * @param {Function} serializeFn Function that serializes a block array to a string.
 * @return {Array|null} Restored blocks array, or null if nothing to undo.
 */
export function stepUndo( history, serializeFn ) {
	const lastCommittedSerialized =
		history.lastCommittedSerialized ?? serializeFn( history.lastCommitted );

	// If there is an uncommitted transient typing burst, undo back to last committed state.
	if ( history.present !== history.lastCommitted ) {
		const presentSerialized = serializeFn( history.present );
		if ( presentSerialized !== lastCommittedSerialized ) {
			history.future.push( cloneBlocks( history.present ) );
			const target = cloneBlocks( history.lastCommitted );
			history.present = target;
			return target;
		}
	}

	if ( history.past.length === 0 ) {
		return null;
	}

	const previous = history.past.pop();
	history.future.push( cloneBlocks( history.lastCommitted ) );
	history.lastCommitted = previous;
	history.lastCommittedSerialized = null;
	history.present = previous;
	return previous;
}

/**
 * Step forward to the next state in redo history.
 *
 * @param {Object} history    History manager object.
 * @param {number} maxHistory Maximum number of history entries to retain.
 * @return {Array|null} Restored blocks array, or null if nothing to redo.
 */
export function stepRedo( history, maxHistory = MAX_HISTORY_LENGTH ) {
	if ( history.future.length === 0 ) {
		return null;
	}

	const next = history.future.pop();
	history.past.push( cloneBlocks( history.lastCommitted ) );
	if ( history.past.length > maxHistory ) {
		history.past.shift();
	}
	history.lastCommitted = next;
	history.lastCommittedSerialized = null;
	history.present = next;
	return next;
}

/**
 * Handle keyboard events for undo/redo shortcuts.
 *
 * @param {KeyboardEvent|Object} event          Keyboard event object.
 * @param {Object}                actions        Actions containing undo and redo callbacks.
 * @param {Function}              actions.onUndo Callback for undo.
 * @param {Function}              actions.onRedo Callback for redo.
 * @param {boolean}               isApple        Whether the current platform is Apple OS.
 * @return {boolean} True if the event was handled as an undo/redo shortcut.
 */
export function handleEditorKeyDown( event, { onUndo, onRedo }, isApple = false ) {
	if ( event.defaultPrevented ) {
		return false;
	}

	const hasPrimaryModifier = isApple ? event.metaKey : event.ctrlKey;
	const hasSecondaryModifier = isApple ? event.ctrlKey : event.metaKey;

	if ( ! hasPrimaryModifier || hasSecondaryModifier || event.altKey ) {
		return false;
	}

	const key = event.key ? event.key.toLowerCase() : '';
	const isZ = key === 'z' || event.code === 'KeyZ' || event.keyCode === 90;
	const isY = key === 'y' || event.code === 'KeyY' || event.keyCode === 89;

	if ( isZ && ! event.shiftKey ) {
		if ( typeof event.preventDefault === 'function' ) {
			event.preventDefault();
		}
		onUndo();
		return true;
	}

	if (
		( isZ && event.shiftKey ) ||
		( ! isApple && isY && ! event.shiftKey )
	) {
		if ( typeof event.preventDefault === 'function' ) {
			event.preventDefault();
		}
		onRedo();
		return true;
	}

	return false;
}

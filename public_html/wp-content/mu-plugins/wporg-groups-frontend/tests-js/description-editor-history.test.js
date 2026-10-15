import {
	cloneBlocks,
	createHistoryManager,
	recordInput,
	recordChange,
	stepUndo,
	stepRedo,
	handleEditorKeyDown,
} from '../src/components/event-form/description-editor-history';

describe( 'DescriptionEditor history manager', () => {
	function makeBlock( content, clientId = 'cid-1' ) {
		return {
			clientId,
			name: 'core/paragraph',
			isValid: true,
			attributes: { content },
			innerBlocks: [],
		};
	}

	const serialize = ( blocks ) => JSON.stringify( blocks );

	test( 'initializes with empty past/future and set present/lastCommitted', () => {
		const initial = [ makeBlock( 'Initial' ) ];
		const history = createHistoryManager( initial );

		expect( history.past ).toEqual( [] );
		expect( history.future ).toEqual( [] );
		expect( history.present ).toBe( initial );
		expect( history.lastCommitted ).toBe( initial );
	} );

	test( 'cloneBlocks deep clones blocks preserving attributes and clientIds', () => {
		const original = [
			{
				...makeBlock( 'Hello' ),
				attributes: {
					content: 'Hello',
					metadata: { nested: { value: 42 }, list: [ 1, 2, 3 ] },
				},
			},
		];
		const cloned = cloneBlocks( original );

		expect( cloned ).toEqual( original );
		expect( cloned ).not.toBe( original );
		expect( cloned[ 0 ] ).not.toBe( original[ 0 ] );
		expect( cloned[ 0 ].attributes ).not.toBe( original[ 0 ].attributes );
		expect( cloned[ 0 ].attributes.metadata ).not.toBe( original[ 0 ].attributes.metadata );
		expect( cloned[ 0 ].attributes.metadata.nested ).not.toBe( original[ 0 ].attributes.metadata.nested );
		expect( cloned[ 0 ].attributes.metadata.list ).not.toBe( original[ 0 ].attributes.metadata.list );
		expect( cloned[ 0 ].clientId ).toBe( original[ 0 ].clientId );
	} );

	/*
	 * The regression test for #2018. Since WordPress 6.5 the `content`
	 * attribute of every rich-text block is a `RichTextData`, which keeps
	 * its value in a private field - so `Object.keys()` on one is `[]` and
	 * a key-by-key clone produced `{}`. Undo rendered the block empty, and
	 * because `@wordpress/blocks` swallows the `toHTMLString` throw and
	 * falls back to `originalContent ?? ''`, that blank is what got saved.
	 *
	 * Stood in for here rather than imported: `@wordpress/rich-text` is a
	 * `wp.*` external at build time and is not installed for the test run.
	 * What the test needs is the shape that broke the clone - a class
	 * holding its value where `Object.keys()` cannot see it - and this is
	 * that shape.
	 */
	class FakeRichTextData {
		#value;

		constructor( value ) {
			this.#value = value;
		}

		toHTMLString() {
			return this.#value;
		}
	}

	test( 'cloneBlocks keeps rich-text attribute instances intact', () => {
		const content = new FakeRichTextData( 'typed <em>rich</em>' );
		const original = [ { ...makeBlock( content ), originalContent: '' } ];

		const cloned = cloneBlocks( original );

		// By reference, not a copy: a copy is what lost the private field.
		expect( cloned[ 0 ].attributes.content ).toBe( content );
		expect( cloned[ 0 ].attributes.content.toHTMLString() ).toBe( 'typed <em>rich</em>' );
	} );

	test( 'undo and redo round-trip rich-text content without blanking it', () => {
		// Serialising the way the editor really does, so a clone that lost
		// its private field serialises to the empty fallback and fails here.
		const serializeHtml = ( blocks ) =>
			blocks
				.map( ( block ) => {
					const { content } = block.attributes;

					try {
						return content.toHTMLString();
					} catch ( e ) {
						return block.originalContent ?? '';
					}
				} )
				.join( '' );

		const first = [ { ...makeBlock( new FakeRichTextData( 'Hello' ) ), originalContent: '' } ];
		const second = [ { ...makeBlock( new FakeRichTextData( 'Hello world' ) ), originalContent: '' } ];

		const history = createHistoryManager( first );
		recordChange( history, second, serializeHtml );

		const undone = stepUndo( history, serializeHtml );
		expect( serializeHtml( undone ) ).toBe( 'Hello' );

		const redone = stepRedo( history );
		expect( serializeHtml( redone ) ).toBe( 'Hello world' );

		// What `getValueRef` would hand the submit handler.
		expect( serializeHtml( history.present ) ).toBe( 'Hello world' );
	} );

	test( 'recordInput updates present without modifying past', () => {
		const initial = [ makeBlock( 'Hello' ) ];
		const history = createHistoryManager( initial );
		const updated = [ makeBlock( 'Hello World' ) ];

		recordInput( history, updated );

		expect( history.present ).toBe( updated );
		expect( history.lastCommitted ).toBe( initial );
		expect( history.past ).toEqual( [] );
	} );

	test( 'recordChange saves previous committed state to past and clears future', () => {
		const initial = [ makeBlock( 'Step 1' ) ];
		const history = createHistoryManager( initial );
		history.future = [ [ makeBlock( 'Future' ) ] ];

		const step2 = [ makeBlock( 'Step 2' ) ];
		recordChange( history, step2, serialize );

		expect( history.past.length ).toBe( 1 );
		expect( history.past[ 0 ][ 0 ].attributes.content ).toBe( 'Step 1' );
		expect( history.lastCommitted ).toBe( step2 );
		expect( history.present ).toBe( step2 );
		expect( history.future ).toEqual( [] );
	} );

	test( 'recordChange does not duplicate past entry when content is identical', () => {
		const initial = [ makeBlock( 'Same' ) ];
		const history = createHistoryManager( initial );

		const clone = [ makeBlock( 'Same' ) ];
		recordChange( history, clone, serialize );

		expect( history.past ).toEqual( [] );
	} );

	test( 'recordChange caps history at maxHistory', () => {
		const history = createHistoryManager( [ makeBlock( '0' ) ] );

		for ( let i = 1; i <= 5; i++ ) {
			recordChange( history, [ makeBlock( String( i ) ) ], serialize, 3 );
		}

		expect( history.past.length ).toBe( 3 );
		expect( history.past.map( ( b ) => b[ 0 ].attributes.content ) ).toEqual( [ '2', '3', '4' ] );
		expect( history.lastCommitted[ 0 ].attributes.content ).toBe( '5' );
	} );

	test( 'stepUndo reverts uncommitted typing burst to lastCommitted and pushes to future', () => {
		const committed = [ makeBlock( 'Committed' ) ];
		const history = createHistoryManager( committed );

		const typing = [ makeBlock( 'Committed typing...' ) ];
		recordInput( history, typing );

		const restored = stepUndo( history, serialize );

		expect( restored[ 0 ].attributes.content ).toBe( 'Committed' );
		expect( history.present[ 0 ].attributes.content ).toBe( 'Committed' );
		expect( history.future.length ).toBe( 1 );
		expect( history.future[ 0 ][ 0 ].attributes.content ).toBe( 'Committed typing...' );
	} );

	test( 'stepUndo steps through multiple committed states', () => {
		const history = createHistoryManager( [ makeBlock( 'A' ) ] );
		recordChange( history, [ makeBlock( 'B' ) ], serialize );
		recordChange( history, [ makeBlock( 'C' ) ], serialize );

		const undo1 = stepUndo( history, serialize );
		expect( undo1[ 0 ].attributes.content ).toBe( 'B' );
		expect( history.present[ 0 ].attributes.content ).toBe( 'B' );
		expect( history.lastCommitted[ 0 ].attributes.content ).toBe( 'B' );

		const undo2 = stepUndo( history, serialize );
		expect( undo2[ 0 ].attributes.content ).toBe( 'A' );
		expect( history.present[ 0 ].attributes.content ).toBe( 'A' );
		expect( history.lastCommitted[ 0 ].attributes.content ).toBe( 'A' );

		const undo3 = stepUndo( history, serialize );
		expect( undo3 ).toBeNull();
	} );

	test( 'stepRedo steps forward into future stack', () => {
		const history = createHistoryManager( [ makeBlock( 'A' ) ] );
		recordChange( history, [ makeBlock( 'B' ) ], serialize );

		stepUndo( history, serialize );
		expect( history.present[ 0 ].attributes.content ).toBe( 'A' );

		const redo1 = stepRedo( history );
		expect( redo1[ 0 ].attributes.content ).toBe( 'B' );
		expect( history.present[ 0 ].attributes.content ).toBe( 'B' );
		expect( history.lastCommitted[ 0 ].attributes.content ).toBe( 'B' );

		const redo2 = stepRedo( history );
		expect( redo2 ).toBeNull();
	} );

	test( 'new edit after undo clears redo future stack', () => {
		const history = createHistoryManager( [ makeBlock( 'A' ) ] );
		recordChange( history, [ makeBlock( 'B' ) ], serialize );
		stepUndo( history, serialize );

		expect( history.future.length ).toBe( 1 );

		recordChange( history, [ makeBlock( 'C' ) ], serialize );
		expect( history.future ).toEqual( [] );
		expect( stepRedo( history ) ).toBeNull();
	} );
} );

describe( 'handleEditorKeyDown keyboard shortcuts', () => {
	function createEvent( overrides = {} ) {
		return {
			key: '',
			metaKey: false,
			ctrlKey: false,
			shiftKey: false,
			altKey: false,
			defaultPrevented: false,
			preventDefault: jest.fn(),
			...overrides,
		};
	}

	test( 'triggers onUndo on Mac when Cmd+Z is pressed', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'z', metaKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( true );
		expect( onUndo ).toHaveBeenCalledTimes( 1 );
		expect( onRedo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'triggers onRedo on Mac when Shift+Cmd+Z is pressed (uppercase Z)', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'Z', metaKey: true, shiftKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( true );
		expect( onRedo ).toHaveBeenCalledTimes( 1 );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'triggers onRedo on Mac when Cmd+Shift+Z is pressed (lowercase z)', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'z', metaKey: true, shiftKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( true );
		expect( onRedo ).toHaveBeenCalledTimes( 1 );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does not trigger onRedo on Mac when Cmd+Y is pressed (reserved by browser)', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'y', metaKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( false );
		expect( onRedo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );

	test( 'triggers onUndo on Windows/Linux when Ctrl+Z is pressed', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'z', ctrlKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, false );

		expect( handled ).toBe( true );
		expect( onUndo ).toHaveBeenCalledTimes( 1 );
		expect( onRedo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'triggers onRedo on Windows/Linux when Shift+Ctrl+Z is pressed', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'Z', ctrlKey: true, shiftKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, false );

		expect( handled ).toBe( true );
		expect( onRedo ).toHaveBeenCalledTimes( 1 );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'triggers onRedo on Windows/Linux when Ctrl+Y is pressed', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'y', ctrlKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, false );

		expect( handled ).toBe( true );
		expect( onRedo ).toHaveBeenCalledTimes( 1 );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'ignores keystrokes with alt/option modifier pressed', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'z', metaKey: true, altKey: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( false );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( onRedo ).not.toHaveBeenCalled();
	} );

	test( 'ignores events where default is already prevented', () => {
		const onUndo = jest.fn();
		const onRedo = jest.fn();
		const event = createEvent( { key: 'z', metaKey: true, defaultPrevented: true } );

		const handled = handleEditorKeyDown( event, { onUndo, onRedo }, true );

		expect( handled ).toBe( false );
		expect( onUndo ).not.toHaveBeenCalled();
	} );

	test( 'full keystroke-driven undo and redo cycle restores editor blocks', () => {
		function makeBlock( content ) {
			return {
				clientId: 'cid-1',
				name: 'core/paragraph',
				isValid: true,
				attributes: { content },
				innerBlocks: [],
			};
		}
		const serialize = ( blocks ) => JSON.stringify( blocks );

		const initial = [ makeBlock( '' ) ];
		const history = createHistoryManager( initial );

		// Simulate typing 'GAMMA'
		const typing = [ makeBlock( 'GAMMA' ) ];
		recordInput( history, typing );
		expect( history.present[ 0 ].attributes.content ).toBe( 'GAMMA' );

		const actions = {
			onUndo: () => {
				const target = stepUndo( history, serialize );
				if ( target ) {
					history.present = target;
				}
			},
			onRedo: () => {
				const target = stepRedo( history );
				if ( target ) {
					history.present = target;
				}
			},
		};

		// 1. Press Cmd+Z (undo) - text clears to initial empty block
		const undoEvent = createEvent( { key: 'z', metaKey: true } );
		handleEditorKeyDown( undoEvent, actions, true );
		expect( history.present[ 0 ].attributes.content ).toBe( '' );
		expect( undoEvent.preventDefault ).toHaveBeenCalled();

		// 2. Press Shift+Cmd+Z (redo) - text restores back to 'GAMMA'
		const redoEvent = createEvent( { key: 'Z', metaKey: true, shiftKey: true } );
		handleEditorKeyDown( redoEvent, actions, true );
		expect( history.present[ 0 ].attributes.content ).toBe( 'GAMMA' );
		expect( redoEvent.preventDefault ).toHaveBeenCalled();

		// 3. Repeated undo and redo cycle
		handleEditorKeyDown( createEvent( { key: 'z', metaKey: true } ), actions, true );
		expect( history.present[ 0 ].attributes.content ).toBe( '' );

		handleEditorKeyDown( createEvent( { key: 'z', metaKey: true, shiftKey: true } ), actions, true );
		expect( history.present[ 0 ].attributes.content ).toBe( 'GAMMA' );
	} );
} );

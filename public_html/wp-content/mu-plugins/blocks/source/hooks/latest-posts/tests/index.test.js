/**
 * Internal dependencies
 */
import { readAttributes, unwrapContainer } from '../front-end';

describe( 'readAttributes', () => {
	const container = ( attributes ) => {
		const element = document.createElement( 'div' );

		if ( undefined !== attributes ) {
			element.dataset.attributes = attributes;
		}

		return element;
	};

	it( 'reads the attributes the container carries', () => {
		const encoded = encodeURIComponent(
			JSON.stringify( { postsToShow: 3, liveUpdateEnabled: true } )
		);

		expect( readAttributes( container( encoded ) ) ).toEqual( {
			postsToShow: 3,
			liveUpdateEnabled: true,
		} );
	} );

	it( 'returns null when there is nothing to read', () => {
		expect( readAttributes( container() ) ).toBeNull();
		expect( readAttributes( container( '' ) ) ).toBeNull();
	} );

	it( 'returns null for a payload it cannot parse', () => {
		expect( readAttributes( container( 'not json' ) ) ).toBeNull();
		expect( readAttributes( container( '%E0%A4%A' ) ) ).toBeNull();
	} );

	// Anything but a plain object would ask the route for the block's defaults,
	// which renders a different list over a correct one.
	it( 'returns null for a payload that is not a plain object', () => {
		[ '[]', '"nope"', '5', 'null', 'true' ].forEach( ( json ) => {
			expect(
				readAttributes( container( encodeURIComponent( json ) ) )
			).toBeNull();
		} );
	} );
} );

describe( 'unwrapContainer', () => {
	it( 'returns the contents of the response container, not the container', () => {
		const html =
			'<ul class="wp-block-latest-posts__list wp-block-latest-posts"><li>one</li><li>two</li></ul>';

		expect( unwrapContainer( html ) ).toBe( '<li>one</li><li>two</li>' );
	} );

	it( 'unwraps whichever element the block class lands on', () => {
		const html =
			'<div data-attributes="%7B%7D" class="wp-block-latest-posts has-live-update"><li>one</li></div>';

		expect( unwrapContainer( html ) ).toBe( '<li>one</li>' );
	} );

	it( 'keeps a nested list inside the items', () => {
		const html =
			'<ul class="wp-block-latest-posts"><li>one<ul><li>nested</li></ul></li></ul>';

		expect( unwrapContainer( html ) ).toBe(
			'<li>one<ul><li>nested</li></ul></li>'
		);
	} );

	it( 'returns the markup unchanged when there is no container to strip', () => {
		expect( unwrapContainer( '<li>one</li>' ) ).toBe( '<li>one</li>' );
		expect( unwrapContainer( '' ) ).toBe( '' );
	} );
} );

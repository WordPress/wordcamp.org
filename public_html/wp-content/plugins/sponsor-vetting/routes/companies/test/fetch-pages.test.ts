/**
 * Tests for loading every page of a list.
 */

import { describe, expect, it } from 'vitest';
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { fetchPagesAtOnce } from '../fetch-pages';

/**
 * Answers requests as the endpoint would, with 50 records short of a number of full pages, and notes which pages
 * were asked for and how many at most were loading at once.
 *
 * @param pages How many pages the list has.
 * @return The next middleware, the paths it was asked for, and the most requests it had at once.
 */
function getServer( pages: number ) {
	let loading = 0;
	const server = {
		asked: [] as string[],
		most: 0,

		/**
		 * Answers one request.
		 *
		 * @param options       The request.
		 * @param options.path  Its path.
		 * @param options.parse Whether to answer with the records, rather than the response.
		 * @return The records, or the response with them.
		 */
		next: async ( { path = '', parse }: APIFetchOptions ) => {
			server.asked.push( path );
			loading += 1;
			server.most = Math.max( server.most, loading );
			await new Promise( ( resolve ) => setTimeout( resolve, 1 ) );
			loading -= 1;

			const page = Number( new URLSearchParams( path.split( '?' )[ 1 ] ).get( 'page' ) );
			const records = [ ...Array( page < pages ? 100 : 50 ).keys() ].map(
				( index ) => ( page - 1 ) * 100 + index
			);

			return parse === false
				? new Response( JSON.stringify( records ), { headers: { 'X-WP-TotalPages': String( pages ) } } )
				: records;
		},
	};

	return server;
}

const LIST = '/wp/v2/sv-sponsorships?status=any&per_page=-1&_fields=id';

describe( 'loading a list', () => {
	it( 'asks for every page once it knows how many there are, a few at once, and keeps the records in order', async () => {
		const server = getServer( 10 );
		const records = await fetchPagesAtOnce( { path: LIST }, server.next );

		expect( server.asked ).toEqual(
			[ ...Array( 10 ).keys() ].map(
				( index ) => `/wp/v2/sv-sponsorships?status=any&per_page=100&page=${ index + 1 }&_fields=id`
			)
		);
		expect( server.most ).toBe( 4 );
		expect( records ).toEqual( [ ...Array( 950 ).keys() ] );
	} );

	it( 'loads a list asked for while it loads once more afterwards, for everyone who asked', async () => {
		const server = getServer( 1 );
		const [ first, ...later ] = await Promise.all(
			[ 1, 2, 3 ].map( () => fetchPagesAtOnce( { path: LIST }, server.next ) )
		);

		expect( server.asked ).toHaveLength( 2 );
		expect( later ).toEqual( [ first, first ] );
	} );

	it( 'leaves other requests alone', async () => {
		const server = getServer( 1 );

		await fetchPagesAtOnce( { path: '/wp/v2/posts?per_page=-1' }, server.next );
		await fetchPagesAtOnce( { path: '/wp/v2/sv-companies?per_page=20' }, server.next );

		expect( server.asked ).toEqual( [ '/wp/v2/posts?per_page=-1', '/wp/v2/sv-companies?per_page=20' ] );
	} );
} );

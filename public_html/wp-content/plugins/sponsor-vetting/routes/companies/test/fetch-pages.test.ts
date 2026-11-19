/**
 * Tests for loading every page of a list at once.
 */

import { describe, expect, it } from 'vitest';
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { fetchPagesAtOnce } from '../fetch-pages';

/**
 * Answers requests as the endpoint would, with 250 records, and notes which pages were asked for.
 *
 * @param asked Where to note the requests' paths.
 * @return The next middleware.
 */
function getServer( asked: string[] ) {
	return async ( { path = '', parse }: APIFetchOptions ) => {
		asked.push( path );

		const page = Number( new URLSearchParams( path.split( '?' )[ 1 ] ).get( 'page' ) );
		const records = [ ...Array( page < 3 ? 100 : 50 ).keys() ].map( ( index ) => ( page - 1 ) * 100 + index );

		return parse === false
			? new Response( JSON.stringify( records ), { headers: { 'X-WP-TotalPages': '3' } } )
			: records;
	};
}

describe( 'loading a list', () => {
	it( 'asks for every page once it knows how many there are, and keeps the records in order', async () => {
		const asked: string[] = [];
		const records = await fetchPagesAtOnce(
			{ path: '/wp/v2/sv-sponsorships?status=any&per_page=-1&_fields=id' },
			getServer( asked )
		);

		expect( asked ).toEqual( [
			'/wp/v2/sv-sponsorships?status=any&per_page=100&page=1&_fields=id',
			'/wp/v2/sv-sponsorships?status=any&per_page=100&page=2&_fields=id',
			'/wp/v2/sv-sponsorships?status=any&per_page=100&page=3&_fields=id',
		] );
		expect( records ).toEqual( [ ...Array( 250 ).keys() ] );
	} );

	it( 'leaves other requests alone', async () => {
		const asked: string[] = [];

		await fetchPagesAtOnce( { path: '/wp/v2/posts?per_page=-1' }, getServer( asked ) );
		await fetchPagesAtOnce( { path: '/wp/v2/sv-companies?per_page=20' }, getServer( asked ) );

		expect( asked ).toEqual( [ '/wp/v2/posts?per_page=-1', '/wp/v2/sv-companies?per_page=20' ] );
	} );
} );

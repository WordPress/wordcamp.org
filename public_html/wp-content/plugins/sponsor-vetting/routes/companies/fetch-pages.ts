/**
 * Loads every page of a list of companies or sponsorships at once.
 */

import type { APIFetchMiddleware } from '@wordpress/api-fetch';

/**
 * The most records the endpoints send at a time.
 */
const PAGE_SIZE = 100;

/**
 * A request for every company or every sponsorship, as core-data makes it.
 */
const ALL_RECORDS = /^\/wp\/v2\/sv-(companies|sponsorships)\?(.*&)?per_page=-1(&|$)/;

/**
 * Loads all the pages of a list together, where api-fetch would load one, read where the next is, then load that.
 *
 * Only the first is awaited before the others, for how many there are. Any other request passes through.
 *
 * @param options The request.
 * @param next    Sends it on to the other middlewares.
 * @return The records of every page, in order.
 */
export const fetchPagesAtOnce: APIFetchMiddleware = async ( options, next ) => {
	const { path } = options;

	if ( options.parse === false || ! path || ! ALL_RECORDS.test( path ) ) {
		return next( options );
	}

	const getPage = ( page: number ) => ( {
		...options,
		path: path.replace( 'per_page=-1', `per_page=${ PAGE_SIZE }&page=${ page }` ),
	} );
	const response = ( await next( { ...getPage( 1 ), parse: false } ) ) as Response;
	const first: unknown[] = await response.json();
	const total = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1;
	const rest = ( await Promise.all(
		[ ...Array( Math.max( total - 1, 0 ) ).keys() ].map( ( index ) => next( getPage( index + 2 ) ) )
	) ) as unknown[][];

	return first.concat( ...rest );
};

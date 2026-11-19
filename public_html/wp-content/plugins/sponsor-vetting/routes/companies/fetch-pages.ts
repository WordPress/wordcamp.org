/**
 * Loads every page of a list of companies or sponsorships, a few at a time, and one load of a list at a time.
 */

import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';

/**
 * The most records the endpoints send at a time.
 */
const PAGE_SIZE = 100;

/**
 * The most pages of a list that load at once, so a longer list doesn't take more of the server's workers.
 */
const PARALLEL_PAGES = 4;

/**
 * A request for every company or every sponsorship, as core-data makes it.
 */
const ALL_RECORDS = /^\/wp\/v2\/sv-(companies|sponsorships)\?(.*&)?per_page=-1(&|$)/;

type Next = ( options: APIFetchOptions ) => Promise< unknown >;

/**
 * The load of each list that's running, and the one waiting for it to finish, keyed by the list's request.
 */
const loads = new Map< string, { running: Promise< unknown[] >; waiting?: Promise< unknown[] > } >();

/**
 * Loads all the pages of a list: the first, for how many there are, then the rest, a few at once.
 *
 * @param options The list's request.
 * @param path    Its path.
 * @param next    Sends a request on to the other middlewares.
 * @return The records of every page, in order.
 */
async function loadPages( options: APIFetchOptions, path: string, next: Next ): Promise< unknown[] > {
	/**
	 * Builds the request for one page of the list.
	 *
	 * @param page The page, from 1.
	 * @return The request.
	 */
	const getPage = ( page: number ) => ( {
		...options,
		path: path.replace( 'per_page=-1', `per_page=${ PAGE_SIZE }&page=${ page }` ),
	} );
	const response = ( await next( { ...getPage( 1 ), parse: false } ) ) as Response;
	const pages: unknown[][] = [ await response.json() ];
	const total = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1;
	let last = 1;

	/**
	 * Loads the pages no other worker has taken yet, one after another.
	 */
	const work = async () => {
		while ( last < total ) {
			last += 1;

			const page = last;

			pages[ page - 1 ] = ( await next( getPage( page ) ) ) as unknown[];
		}
	};

	await Promise.all( Array.from( { length: Math.min( PARALLEL_PAGES, total - 1 ) }, work ) );

	return pages.flat();
}

/**
 * Starts loading a list, and forgets the load once it's done.
 *
 * @param options The list's request.
 * @param path    Its path.
 * @param next    Sends a request on to the other middlewares.
 * @return The records of every page, in order.
 */
function startLoad( options: APIFetchOptions, path: string, next: Next ): Promise< unknown[] > {
	const running: Promise< unknown[] > = loadPages( options, path, next ).finally( () => {
		if ( loads.get( path )?.running === running ) {
			loads.delete( path );
		}
	} );

	loads.set( path, { running } );

	return running;
}

/**
 * Loads all the pages of a list, where api-fetch would load one, read where the next is, then load that.
 *
 * A list asked for while it's loading, as it is after each change a vetter saves, loads once more when that's
 * done, for everyone who asked in the meantime. Any other request passes through.
 *
 * @param options The request.
 * @param next    Sends it on to the other middlewares.
 * @return The records of every page, in order.
 */
export const fetchPagesAtOnce: APIFetchMiddleware = ( options, next ) => {
	const { path } = options;

	if ( options.parse === false || ! path || ! ALL_RECORDS.test( path ) ) {
		return next( options );
	}

	const load = loads.get( path );

	if ( ! load ) {
		return startLoad( options, path, next );
	}

	load.waiting ??= load.running.catch( () => undefined ).then( () => startLoad( options, path, next ) );

	return load.waiting;
};

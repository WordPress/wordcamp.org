/**
 * Extenal dependencies
 */
import createSelector from 'rememo';

/**
 * WordPress dependencies
 */
const { registerStore, dispatch } = wp.data;
const { apiFetch } = wp;
const { addQueryArgs } = wp.url;

/**
 * Define store name.
 */
export const WC_BLOCKS_STORE = 'wc-blocks-store';

/**
 * Initial state.
 */
const DEFAULT_STATE = {
	loadingEntities : [],
};

const MAX_POSTS = 100;

/**
 * Supported entities with their args.
 */
const API_ARGS = {
	speakers: {
		path: 'wp/v2/speakers',
		query: {
			orderby  : 'id',
			order    : 'desc',
			per_page : MAX_POSTS,
			_embed   : true,
			context  : 'view',
		}
	},
	speakerTracks: {
		path: 'wp/v2/session_track',
		query: {
			per_page: MAX_POSTS,
			context: 'view',
		}
	},
	speakerGroups: {
		path: '/wp/v2/speaker_group',
		query: {
			orderby  : 'name',
			order    : 'asc',
			per_page : MAX_POSTS,
		}
	},
};

/**
 * Helper method for fetching WordCamp Custom Entities.
 *
 * @param state
 * @param postType
 * @param args
 * @param callback
 */
const apiFetchEntities = ( state, postType, args ) => {

	// Bail if we already have these entities fetched.
	if ( state.hasOwnProperty( postType ) && 0 !== state[ postType ].length ) {
		return;
	}

	// Bail if already loading this entity
	if ( -1 !== state.loadingEntities.indexOf( postType ) ) {
		return;
	}

	state.loadingEntities.push( postType );

	// TODO: Implement pagination.
	const apiFetchResult = apiFetch( {
		path: addQueryArgs( args.path, args.query )
	} );

	apiFetchResult.then(
		( customEntities ) => {
			dispatch( WC_BLOCKS_STORE ).setEntities( postType, customEntities);
		}
	).catch( //TODO: Implement retries on HTTP Transport errors.
		( reason ) => {
			console.log( "Unable to retrieve data from API.", reason );
		}
	);

};

/**
 * Define actions which can be dispatched by this store.
 *
 * @type {{apiFetch(*): *}}
 */
const actions = {

	/**
	 * Queues fetching from API for a post type.
	 *
	 * @param postType
	 * @returns {{postType: *, type: string}}
	 */
	fetchEntities( postType ) {
		return {
			type: 'FETCH_ENTITIES',
			postType,
		};
	},

	/**
	 * Set entities state
	 *
	 * @param postType
	 * @param entities
	 */
	setEntities( postType, entities ) {
		return {
			type: 'SET_ENTITIES',
			postType,
			entities,
		}
	}
};

/**
 * Defines selectors provided by this store.
 *
 * @type {{getPosts(*, *)}}
 */
const selectors = {

	/**
	 * Returns post type from current state.
	 *
	 * @param state
	 * @param postType
	 * @param args
	 */
	getEntities: createSelector(
		( state, postType, args={} ) => {
			let results = state[ postType ];

			if ( ! state.hasOwnProperty( postType ) ) {
				return;
			}

			if ( Array.isArray( args.entityIds ) ) {
				results = results.filter( item => -1 !== args.entityIds.indexOf( item.id ) );
			}

			if ( 'function' === typeof( args.filterEntities ) ) {
				results = results.filter( args.filterEntities );
			}

			if ( 'function' === typeof( args.orderBy ) ) {
				results = args.orderBy( results );
			}

			return results;
		},
		// Return state if postType is yet initialized to prevent unnecessary selector executions.
		( state, postType ) => state[ postType ] || state
	),
};

registerStore(
	WC_BLOCKS_STORE,
	{
		reducer( state=DEFAULT_STATE, action ) {

			switch ( action.type ) {

				case 'FETCH_ENTITIES':

					apiFetchEntities(
						state,
						action.postType,
						API_ARGS[ action.postType ],
					);

					break;

				case 'SET_ENTITIES':

					// Changing state reference so that withSelect works.
					state = { ...state };
					state[ action.postType ] = action.entities;

					// Not really needed, but lets do this for correctness.
					const loadingEntities = state.loadingEntities.filter( item => action.postType !== item );

					break;

			}
			return state;
		},
		actions,
		selectors,
	}
);

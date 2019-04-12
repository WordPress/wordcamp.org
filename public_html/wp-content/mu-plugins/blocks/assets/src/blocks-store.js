/**
 * External dependencies
 */
import createSelector from 'rememo';

/**
 * WordPress dependencies
 */
const { registerStore, dispatch } = wp.data;
const { apiFetch }                = wp;
const { addQueryArgs }            = wp.url;

/**
 * Define store name.
 */
export const WC_BLOCKS_STORE = 'wc-blocks-store';

/**
 * Initial state.
 */
const DEFAULT_STATE = {
	loadingEntities: [],
};

const MAX_POSTS = 100;

/**
 * Generic query for supported data type.
 */
const API_ARGS = {
	entity: {
		path  : ( entityType ) => 'wp/v2/' + entityType,
		query : {
			orderby  : 'id',
			order    : 'desc',
			per_page : MAX_POSTS,
			_embed   : true,
			context  : 'view',
		},
	},
};

/**
 * Helper method for fetching WordCamp Custom Entities.
 *
 * @param {Object} state      Current store state
 * @param {string} entityType Name of the entity. Egs post, speaker etc
 * @param {string} path       REST API path to fetch entity records
 * @param {Object} query      Query params for the REST API request
 */
const apiFetchEntities = ( state, entityType, path, query ) => {
	// Bail if we already have these entities fetched.
	if ( state.hasOwnProperty( entityType ) && 0 !== state[ entityType ].length ) {
		return;
	}

	// Bail if already loading this entity
	if ( -1 !== state.loadingEntities.indexOf( entityType ) ) {
		return;
	}

	state.loadingEntities.push( entityType );

	// TODO: Implement pagination.
	const apiFetchResult = apiFetch( {
		path: addQueryArgs( path, query ),
	} );

	apiFetchResult.then(
		( customEntities ) => {
			dispatch( WC_BLOCKS_STORE ).setEntities( entityType, customEntities );
		}
	).catch( //TODO: Implement retries on HTTP Transport errors.
		( reason ) => {
			console.error( entityType, 'Unable to retrieve data from API.', reason );
		}
	);
};

/**
 * Define actions which can be dispatched by this store.
 *
 * @type {Object}
 */
const actions = {
	/**
	 * Queues fetching from API for a post type.
	 *
	 * @param {string} entityType
	 * @returns {Object}
	 */
	fetchEntities( entityType ) {
		return {
			type       : 'FETCH_ENTITIES',
			entityType : entityType,
		};
	},

	/**
	 * Set entities state
	 *
	 * @param {string} entityType
	 * @param {Array}  entities
	 *
	 * @return {Object}
	 */
	setEntities( entityType, entities ) {
		return {
			type       : 'SET_ENTITIES',
			entityType : entityType,
			entities   : entities,
		};
	},
};

/**
 * Defines selectors provided by this store.
 *
 * @type {{getPosts(*, *)}}
 */
const selectors = {

	/**
	 * Returns post type from current state. Caches [state, entityType] for quick resolution.
	 *
	 * @param {Object} state      Current store state
	 * @param {string} entityType Name of the entity to get
	 * @param {Object} args       Additional filter arguments.
	 */
	getEntities: createSelector(
		( state, entityType, args = {} ) => {
			let results = state[ entityType ];

			if ( ! state.hasOwnProperty( entityType ) ) {
				// Queue apiFetch for this entity.
				dispatch( WC_BLOCKS_STORE ).fetchEntities( entityType );
				return;
			}

			if ( Array.isArray( args.entityIds ) ) {
				results = results.filter( ( item ) => -1 !== args.entityIds.indexOf( item.id ) );
			}

			if ( 'function' === typeof args.filterEntities ) {
				results = results.filter( args.filterEntities );
			}

			if ( 'function' === typeof args.orderBy ) {
				results = args.orderBy( results );
			}

			return results;
		},
		// Return state if entityType is yet initialized to prevent unnecessary selector executions.
		( state, entityType ) => state[ entityType ] || state
	),
};

registerStore(
	WC_BLOCKS_STORE,
	{
		/**
		 * Reducer for this store.
		 *
		 * @param {Object} state
		 * @param {Object} action
		 *
		 * @returns {Object}
		 */
		reducer( state = DEFAULT_STATE, action ) {
			switch ( action.type ) {
				case 'FETCH_ENTITIES':

					apiFetchEntities(
						state,
						action.entityType,
						API_ARGS.entity.path( action.entityType ),
						API_ARGS.entity.query
					);

					break;

				case 'SET_ENTITIES':

					// Changing state reference so that withSelect works.
					state = { ...state };
					state[ action.entityType ] = action.entities;

					// Not really needed, but lets do this for correctness.
					state.loadingEntities = state.loadingEntities.filter( ( item ) => action.entityType !== item );

					break;
			}
			return state;
		},
		actions,
		selectors,
	}
);

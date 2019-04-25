/**
 * External dependencies
 */
import { intersection, orderBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const { registerStore, select, dispatch } = wp.data;
const apiFetch                            = wp.apiFetch;

/**
 * Define store name.
 */
export const WC_BLOCKS_STORE = 'wordcamp';

const DEFAULT_STATE = {};

/**
 * Helper method to apply filters. Can be moved to a seperate file.
 * Don't use the output for this in a prop because it could cause perf issues,
 * since ref of returned object will always be different.
 *
 * @param { Array }  entities List of entities to filter against
 * @param { Object } args     Arguments for filter function
 */
export const filterEntities = ( entities, args ) => {
	if ( ! Array.isArray( entities ) ) {
		return entities;
	}

	let result = [ ...entities ];

	if ( args.hasOwnProperty( 'filter' ) && Array.isArray( args.filter ) ) {
		args.filter.forEach( ( filterParams ) => {
			let { fieldName, fieldValue } = filterParams;

			if ( ! Array.isArray( fieldValue ) ) {
				fieldValue = [ fieldValue ];
			}

			result = result.filter( ( entity ) => {
				if ( ! entity.hasOwnProperty( fieldName ) ) {
					return false;
				}

				let compareValue = Array.isArray( entity[ fieldName ] ) ? entity[ fieldName ] : [ entity[ fieldName ] ];

				return intersection( fieldValue, compareValue ).length > 0;
			} );
		} );
	}

	if ( args.hasOwnProperty( 'order' ) ) {
		let [ orderby, order ] = split( args.order, '_', 2 );
		// TODO: Figure out a way to move this out of data store.
		if ( 'title' === orderby && result[ 0 ].title.hasOwnProperty( 'rendered') ) {
			orderby = 'title.rendered';
		}
		result = orderBy( result, [ orderby ], [ order ] );
	}

	return result;
};

/**
 * Defines actions provided by this store.
 */
const actions = {

	/**
	 * Queues api fetch for settings.
	 *
	 * @return {{type: string}}
	 */
	fetchSiteSettings() {
		return {
			type : 'FETCH_SETTINGS',
		};
	},

	/**
	 * Set the site settings object in this store.
	 *
	 * @param { Object } settings

	 * @return { Object }
	 */
	setSiteSettings( settings ) {
		return {
			type     : 'SET_SETTINGS',
			settings : settings,
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
	 * Get query results from the Core data store.
	 *
	 * Returns data from current state. Caches [state, entityType] for
	 * quick resolution.
	 *
	 * @param {Object} state      Current store state
	 * @param {string} entityType Type of the entity to fetch
	 * @param {string} entityName Type of the entity to fetch
	 * @param {Object} queryArgs  Optional. Additional arguments for the fetch query.
	 *
	 * @return {Array} The results of the query.
	 */
	getEntities( state, entityType, entityName, queryArgs = {} ) {
		const defaultArgs = {
			per_page: -1, // The data store has middleware that converts this to paginated requests of 100 items each.
		};

		const mergedArgs = { ...defaultArgs, ...queryArgs };

		return select( 'core' ).getEntityRecords( entityType, entityName, mergedArgs );
	},

	getSiteSettings( state ) {
		if ( ! state.hasOwnProperty( 'siteSettings' ) ) {
			dispatch( WC_BLOCKS_STORE ).fetchSiteSettings();
			return null;
		}
		return state.siteSettings;
	}
};

/**
 * Decide how to change the store's state based on the given action.
 *
 * @param {Object} state
 * @param {Object} action
 *
 * @return {{state}}
 */
const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'FETCH_SETTINGS':
			if ( state.loadingSettings ) {
				break;
			}

			apiFetch( { path: '/wp/v2/settings' } ).then(
				( fetchedSettings ) => {
					dispatch( WC_BLOCKS_STORE ).setSiteSettings( fetchedSettings );
				}
			);

			state.loadingSettings = true;
			break;

		case 'SET_SETTINGS':
			state.siteSettings = action.settings;
			state.loadingSettings = false;
			break;
	}

	return state;
};

registerStore( WC_BLOCKS_STORE, { selectors, actions, reducer } );

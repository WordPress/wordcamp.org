/**
 * External dependencies
 */
import { intersection, orderBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const { registerStore, select } = wp.data;

/**
 * Define store name.
 */
export const WC_BLOCKS_STORE = 'wc-blocks-store';

const MAX_POSTS = -1 ; // Its supported now.

/**
 * Helper method to apply filters. Can be moved to a seperate file.
 * Don't use the output for this in a prop because it could cause perf issues, since ref of returned object will always be different.
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
		const [ orderby, order ] = split( args.order, '_', 2 );
		result = orderBy( result, [ 'title' === orderby ? 'title.rendered' : orderby ], [ order ] );
	}

	return result;
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
	 * @param {string} entityType Type of the entity to fetch
	 * @param {string} entityName Type of the entity to fetch
	 */
	getEntities( state, entityType, entityName ) {
		return select( 'core' ).getEntityRecords( entityType, entityName, { _embed: true, per_page: MAX_POSTS } );
	},

};

registerStore(
	WC_BLOCKS_STORE,
	{
		selectors,
		reducer( state, action ) {
			return state;
		},
	}
);

/**
 * External dependencies
 */
import { get } from 'lodash';
import createSelector from 'rememo';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { filterEntities } from '../../data';

const buildOptionGroup = ( entityType, type, label, items ) => {
	items = items.map( ( item ) => {
		let parsedItem;

		switch ( entityType ) {
			case 'post':
				parsedItem = {
					label: item.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' ),
					value: item.id,
					type: type,
					details: item.details,
				};

				parsedItem.avatar = get( item, 'avatar_urls[\'24\']', '' );
				parsedItem.image = get( item, '_embedded[\'wp:featuredmedia\'][0].media_details.sizes.thumbnail.source_url', '' );

				break;

			case 'term':
				parsedItem = {
					label: item.name || __( '(Untitled)', 'wordcamporg' ),
					value: item.id,
					type: type,
					count: item.count,
				};
				break;
		}

		return parsedItem;
	} );

	return {
		label: label,
		options: items,
	};
};

/**
 * A memoized function that parses structured data into a format used as an option set by ItemSelect.
 *
 * @return {Array}
 */
export const buildOptions = createSelector(
	( groups ) => {
		const options = [];

		groups.forEach( ( group ) => {
			const { entityType, type, label, items } = group;
			let orderby;

			switch ( entityType ) {
				case 'post':
					orderby = 'title.rendered';
					break;
				case 'term':
					orderby = 'name';
					break;
			}

			if ( Array.isArray( items ) && items.length ) {
				const sortedItems = filterEntities( items, { sort: orderby + '_asc' } );

				options.push( buildOptionGroup( entityType, type, label, sortedItems ) );
			}
		} );

		return options;
	},
	( groups ) => {
		const references = [];

		groups.forEach( ( group ) => {
			const { items } = group;

			references.push( items );
		} );

		return references;
	}
);

/**
 * Find the label for an option with a specific value.
 *
 * @param {string} value
 * @param {Array}  options
 * @return {string}
 */
export function getOptionLabel( value, options ) {
	let label = '';

	const selectedOption = options.find( ( option ) => {
		return value === option.value;
	} );

	if ( selectedOption.hasOwnProperty( 'label' ) ) {
		label = selectedOption.label;
	}

	return label;
}

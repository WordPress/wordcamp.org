/**
 * External dependencies
 */
import { get }        from 'lodash';
import createSelector from 'rememo';

/**
 * WordPress dependencies
 */
import { Dashicon } from '@wordpress/components';
import { __ }       from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { filterEntities } from '../../data';
import { AvatarImage }    from '../image';

const buildOptionGroup = ( entityType, type, label, items ) => {
	items = items.map( ( item ) => {
		let parsedItem;

		switch ( entityType ) {
			case 'post':
				parsedItem = {
					label : item.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' ),
					value : item.id,
					type  : type,
				};

				parsedItem.avatar = get( item, 'avatar_urls[\'24\']', '' );
				parsedItem.image  = get( item, '_embedded[\'wp:featuredmedia\'].media_details.sizes.thumbnail.source_url', '' );
				break;

			case 'term':
				parsedItem = {
					label : item.name || __( '(Untitled)', 'wordcamporg' ),
					value : item.id,
					type  : type,
					count : item.count,
				};
				break;
		}

		return parsedItem;
	} );

	return {
		label   : label,
		options : items,
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
 *
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

/**
 * Component for a single option in an ItemSelect dropdown.
 *
 * Not all of the props need or should have a value. An option representing a speaker will have
 * an avatar prop, but not an icon or a count (of terms).
 *
 * @param {Object} props {
 *     @type {string} avatar
 *     @type {string} icon
 *     @type {string} label
 *     @type {number} count
 * }
 *
 * @return {Element}
 */
export function Option( { avatar, icon, label, count } ) {
	let image;

	if ( avatar ) {
		image = (
			<AvatarImage
				className="wordcamp-item-select__option-avatar"
				name={ label }
				size={ 24 }
				url={ avatar }
			/>
		);
	} else if ( icon ) {
		image = (
			<div className="wordcamp-item-select__option-icon-container">
				<Dashicon
					className="wordcamp-item-select__option-icon"
					icon={ icon }
					size={ 16 }
				/>
			</div>
		);
	}

	const content = (
		<span className="wordcamp-item-select__option-label">
			{ label }
			{ 'undefined' !== typeof count &&
				<span className="wordcamp-item-select__option-label-count">
					{ count }
				</span>
			}
		</span>
	);

	return (
		<div className="wordcamp-item-select__option">
			{ image }
			{ content }
		</div>
	);
}

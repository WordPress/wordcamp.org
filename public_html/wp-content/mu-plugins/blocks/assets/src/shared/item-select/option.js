/**
 * External dependencies
 */
import createSelector from 'rememo';

/**
 * WordPress dependencies
 */
const { Dashicon } = wp.components;
const { __ }       = wp.i18n;

/**
 * Internal dependencies
 */
import { AvatarImage }    from '../avatar';
import { filterEntities } from '../../blocks-store';

const buildOptionGroup = ( entityType, type, label, items ) => {
	items = items.map( ( item ) => {
		switch ( entityType ) {
			case 'post':
				item = {
					label  : item.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' ),
					value  : item.id,
					type   : type,
					avatar : item.avatar_urls[ '24' ],
				};
				break;

			case 'term':
				item = {
					label : item.name || __( '(Untitled)', 'wordcamporg' ),
					value : item.id,
					type  : type,
					count : item.count,
				};
				break;
		}

		return item;
	} );

	return {
		label   : label,
		options : items,
	};
};

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

export function Option( { avatar, icon, label, count } ) {
	let image;

	if ( avatar ) {
		image = (
			<AvatarImage
				className="wordcamp-item-select-option-avatar"
				name={ label }
				size={ 24 }
				url={ avatar }
			/>
		);
	} else if ( icon ) {
		image = (
			<div className="wordcamp-item-select-option-icon-container">
				<Dashicon
					className="wordcamp-item-select-option-icon"
					icon={ icon }
					size={ 16 }
				/>
			</div>
		);
	}

	const content = (
		<span className="wordcamp-item-select-option-label">
			{ label }
			{ count &&
				<span className="wordcamp-item-select-option-label-count">
					{ count }
				</span>
			}
		</span>
	);

	return (
		<div className="wordcamp-item-select-option">
			{ image }
			{ content }
		</div>
	);
}

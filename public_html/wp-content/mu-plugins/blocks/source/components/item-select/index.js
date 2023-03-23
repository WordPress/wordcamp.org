/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './edit.scss';

/**
 * Determine if an option should be selectable based on what else is already selected.
 *
 * @param {Object} option
 * @param {Array}  selected
 * @return {boolean}
 */
function isOptionDisabled( option, selected ) {
	let chosen;

	if ( Array.isArray( selected ) && selected.length ) {
		chosen = selected[ 0 ].split( ':' )[ 0 ];
	}

	return chosen && chosen !== option.type;
}

/**
 * Convert a selection of options into values for a block's attributes.
 *
 * @param {Array} selectedOptions
 * @return {Object}
 */
function getNewAttributes( selectedOptions ) {
	let attributes = {};

	if ( null === selectedOptions ) {
		return attributes;
	}

	if ( selectedOptions.length ) {
		const chosen = selectedOptions[ 0 ].split( ':' )[ 0 ];

		attributes = {
			mode: chosen,
			item_ids: selectedOptions.map( ( item ) => item.split( ':' )[ 1 ] * 1 ),
		};
	} else {
		attributes = {
			mode: '',
			item_ids: [],
		};
	}

	return attributes;
}

export function ItemSelect( { className, label, help, submitLabel, onChange, options, isLoading, value } ) {
	const instanceId = useInstanceId( ItemSelect );
	const [ selectedOptions, setSelectedOptions ] = useState( null );
	const currentValue = selectedOptions || value.map( ( item ) => item.type + ':' + item.value );
	const id = `wordcamp-item-select-control-${ instanceId }`;

	if ( isLoading ) {
		return null;
	}

	return (
		<div className="wordcamp-item-select">
			<SelectControl
				className={ classnames( 'wordcamp-item-select__select', className ) }
				id={ id }
				label={ label }
				help={ help }
				multiple={ true }
				value={ currentValue }
				onChange={ ( newValue ) => {
					setSelectedOptions( newValue || [] );
				} }
			>
				{ options.map( ( group, i ) => (
					<optgroup key={ `group-${ i }` } label={ group.label }>
						{ group.options.map( ( item, key ) => (
							<option
								key={ `item-${ i }-${ key }` }
								value={ item.type + ':' + item.value }
								disabled={ isOptionDisabled( item, selectedOptions ) }
							>
								{ decodeEntities( item.label ) }
							</option>
						) ) }
					</optgroup>
				) ) }
			</SelectControl>
			<Button
				className="wordcamp-item-select__button"
				variant="secondary"
				onClick={ () => onChange( getNewAttributes( selectedOptions ) ) }
			>
				{ submitLabel || __( 'Select', 'wordcamporg' ) }
			</Button>
		</div>
	);
}

/**
 * Additional component exports
 */
export { buildOptions, getOptionLabel } from './utils';
export { Option } from './option';

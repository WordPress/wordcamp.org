/**
 * External dependencies
 */
import classnames from 'classnames';
import Select     from 'react-select';

/**
 * WordPress dependencies
 */
import { BaseControl, Button } from '@wordpress/components';
import { Component }           from '@wordpress/element';
import { __ }                  from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * Component for selecting one or more related entities to be used as content in a block.
 */
export class ItemSelect extends Component {
	/**
	 * Run additional operations during component initialization.
	 *
	 * @param {Object} props
	 */
	constructor( props ) {
		super( props );

		this.state = {
			selectedOptions: null,
		};

		this.getNewAttributes = this.getNewAttributes.bind( this );
	}

	/**
	 * Determine if an option should be selectable based on what else is already selected.
	 *
	 * @param {Object} option
	 * @param {Array}  selected
	 *
	 * @return {boolean}
	 */
	static isOptionDisabled( option, selected ) {
		let chosen;

		if ( Array.isArray( selected ) && selected.length ) {
			chosen = selected[ 0 ].type;
		}

		return chosen && chosen !== option.type;
	}

	/**
	 * Render the label of an option group.
	 *
	 * @param {Object} groupData
	 *
	 * @returns {Element}
	 */
	static formatGroupLabel( groupData ) {
		return (
			<span className="wordcamp-item-select__option-group-label">
				{ groupData.label }
			</span>
		);
	}

	/**
	 * Convert a selection of options into values for a block's attributes.
	 *
	 * @return {Object}
	 */
	getNewAttributes() {
		const { selectedOptions } = this.state;
		let attributes = {};

		if ( null === selectedOptions ) {
			return attributes;
		}

		const newValue = selectedOptions.map( ( option ) => option.value ) || [];

		if ( newValue.length ) {
			const chosen = selectedOptions[ 0 ].type;

			attributes = {
				mode     : chosen,
				item_ids : newValue,
			};
		} else {
			attributes = {
				mode     : '',
				item_ids : [],
			};
		}

		return attributes;
	}

	/**
	 * Render the select dropdown and related UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { instanceId, className, label, help, submitLabel, onChange, selectProps } = this.props;
		const value = this.state.selectedOptions || this.props.value;
		const id = `wordcamp-item-select-control-${ instanceId }`;

		const mergedSelectProps = {
			isMulti          : true,
			isOptionDisabled : this.constructor.isOptionDisabled,
			formatGroupLabel : this.constructor.formatGroupLabel,
			...selectProps,
		};

		return (
			<BaseControl
				id={ id }
				className={ classnames( 'wordcamp-item-select', className ) }
				label={ label }
				help={ help }
			>
				<div className="wordcamp-item-select__inner">
					<Select
						id={ id }
						className="wordcamp-item-select__select"
						value={ value }
						aria-label={ label }
						onChange={ ( selectedOptions ) => {
							this.setState( { selectedOptions: selectedOptions || [] } );
						} }
						{ ...mergedSelectProps }
					/>
					<Button
						className="wordcamp-item-select__button"
						isLarge
						isDefault
						onClick={ () => onChange( this.getNewAttributes() ) }
					>
						{ submitLabel || __( 'Select', 'wordcamporg' ) }
					</Button>
				</div>
			</BaseControl>
		);
	}
}

/**
 * Additional component exports
 */
export * from './option';

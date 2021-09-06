/**
 * External dependencies
 */
import classnames from 'classnames';
import Select from 'react-select';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, Button } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { withInstanceId } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import './edit.scss';

/**
 * Style object passed to ReactSelect component.
 * See https://react-select.com/styles
 */
const customStyles = {
	indicatorSeparator: ( provided ) => ( {
		...provided,
		display: 'none',
	} ),
	multiValue: ( provided ) => ( {
		...provided,
		backgroundColor: '#e2e4e7',
		borderRadius: '12px',
	} ),
	multiValueLabel: ( provided ) => ( {
		...provided,
		padding: '6px 4px',
		paddingLeft: '12px', // We need to specifically override `provided.paddingLeft`.
		fontSize: '0.9em',
		lineHeight: 1,
	} ),
	multiValueRemove: ( provided, { isFocused } ) => ( {
		...provided,
		backgroundColor: isFocused ? '#fff' : '#e2e4e7',
		boxShadow: isFocused ? 'inset 0 0 0 1px #6c7781, inset 0 0 0 2px #fff' : null,
		borderRadius: '0 12px 12px 0',

		svg: {
			color: isFocused ? '#fff' : '#e2e4e7',
			background: isFocused ? '#191e23' : '#555d66',
			borderRadius: '10px',
		},

		':hover': {
			backgroundColor: '#fff',
			boxShadow: 'inset 0 0 0 1px #6c7781, inset 0 0 0 2px #fff',

			svg: {
				color: '#fff',
				background: '#191e23',
			},
		},
	} ),
	option: ( provided, { isDisabled } ) => ( {
		...provided,
		color: 'inherit',
		opacity: isDisabled ? 0.7 : 1,
	} ),
};

/**
 * Component for selecting one or more related entities to be used as content in a block.
 */
class ItemSelectBase extends Component {
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
	 * @return {Element}
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
				mode: chosen,
				item_ids: newValue,
			};
		} else {
			attributes = {
				mode: '',
				item_ids: [],
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
			isMulti: true,
			isOptionDisabled: this.constructor.isOptionDisabled,
			formatGroupLabel: this.constructor.formatGroupLabel,
			...selectProps,
		};

		return (
			<BaseControl
				id={ id }
				className={ classnames( 'wordcamp-item-select', className ) }
				label={ label }
				help={ help }
			>
				<Select
					id={ id }
					className="wordcamp-item-select__select"
					value={ value }
					aria-label={ label }
					onChange={ ( selectedOptions ) => {
						this.setState( { selectedOptions: selectedOptions || [] } );
					} }
					isClearable={ false }
					styles={ customStyles }
					{ ...mergedSelectProps }
				/>
				<Button
					className="wordcamp-item-select__button"
					isSecondary
					onClick={ () => onChange( this.getNewAttributes() ) }
				>
					{ submitLabel || __( 'Select', 'wordcamporg' ) }
				</Button>
			</BaseControl>
		);
	}
}

export const ItemSelect = withInstanceId( ItemSelectBase );

/**
 * Additional component exports
 */
export { buildOptions, getOptionLabel } from './utils';
export { Option } from './option';

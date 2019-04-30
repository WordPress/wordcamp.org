/**
 * External dependencies
 */
import Select from 'react-select';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { BaseControl, Button } = wp.components;
const { withInstanceId } = wp.compose;
const { Component } = wp.element;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import './style.scss';

class ItemSelect extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			selectedOptions: null,
		};

		this.getNewAttributes = this.getNewAttributes.bind( this );
	}

	static isOptionDisabled( option, selected ) {
		let chosen;

		if ( Array.isArray( selected ) && selected.length ) {
			chosen = selected[ 0 ].type;
		}

		return chosen && chosen !== option.type;
	}

	static formatGroupLabel( groupData ) {
		return (
			<span className="wordcamp-item-select-option-group-label">
				{ groupData.label }
			</span>
		);
	}

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
				<div className="wordcamp-item-select-inner">
					<Select
						id={ id }
						className="wordcamp-item-select-select"
						value={ value }
						aria-label={ label }
						onChange={ ( selectedOptions ) => {
							this.setState( { selectedOptions } );
						} }
						{ ...mergedSelectProps }
					/>
					<Button
						className="wordcamp-item-select-button"
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

export default withInstanceId( ItemSelect );
export * from './option';

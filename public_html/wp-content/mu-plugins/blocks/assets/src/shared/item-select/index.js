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

		this.isOptionDisabled = this.isOptionDisabled.bind( this );
	}

	/**
	 * Checks if an option is disabled, based on whether selected option belongs to the same category as current option.
	 *
	 * @param {Object} option
	 * @param {Array} selected
	 *
	 * @returns {Boolean}
	 */
	isOptionDisabled( option, selected ) {
		const { mode } = this.props;
		let chosen;

		if ( 'loading' === option.type ) {
			return true;
		}

		if ( Array.isArray( selected ) && selected.length ) {
			chosen = selected[ 0 ].type;
		}

		if ( mode && mode !== option.type ) {
			return true;
		}

		return chosen && chosen !== option.type;
	}

	render() {
		const {
			instanceId, className, label, help, submitLabel,
			buildSelectOptions, onChange,
			selectProps,
		} = this.props;
		const value = this.state.selectedOptions || this.props.value;
		const id = `wordcamp-item-select-control-${ instanceId }`;

		const mergedSelectProps = {
			options          : buildSelectOptions(),
			isMulti          : true,
			isOptionDisabled : this.isOptionDisabled,
			...selectProps
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
						onClick={ () => onChange( this.state.selectedOptions || [] ) }
					>
						{ submitLabel || __( 'Select', 'wordcamporg' ) }
					</Button>
				</div>
			</BaseControl>
		);
	}
}

export default withInstanceId( ItemSelect );

/**
 * External dependencies
 */
import Select from 'react-select';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { BaseControl, Button } = wp.components;
const { Component } = wp.element;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import './style.scss';

class VersatileSelect extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			selectedOptions: [],
		};

		this.render = this.render.bind( this );
	}

	render() {
		const { className, label, help, instanceId, onChange, submitLabel } = this.props;
		const id = `wordcamp-block-versatile-select-control-${ instanceId }`;

		return (
			<BaseControl
				id={ id }
				className={ classnames( 'wordcamp-components-versatile-select', className ) }
				label={ label }
				help={ help }
			>
				<div className={ 'wordcamp-components-versatile-select-inner' }>
					<Select
						isMulti={ true }
						closeMenuOnSelect={ false }
						menuIsOpen={true}
						{ ...this.props }
						className={ 'wordcamp-components-versatile-select-select' }
						onChange={ ( selectedOptions ) => {
							this.setState( { selectedOptions: selectedOptions } );
						} }
					/>
					<Button
						className={ 'wordcamp-components-versatile-select-button' }
						isLarge
						isDefault
						onClick={ () => {
							const { selectedOptions } = this.state;
							onChange( selectedOptions );
						} }
					>
						{ submitLabel || __( 'Select', 'wordcamporg' ) }
					</Button>
				</div>
			</BaseControl>
		);
	}
}

export default VersatileSelect;

/**
 * External dependencies
 */
import Select from 'react-select';
import classnames from 'classnames';
import { map } from 'lodash';

/**
 * WordPress dependencies
 */
const { BaseControl, Button } = wp.components;
const { Component } = wp.element;
const { __ } = wp.i18n;

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
				className={ classnames( 'wordcamp-components-image-alignment', className ) }
				label={ label }
				help={ help }
			>
				<Select
					isMulti={ true }
					closeMenuOnSelect={ false }
					{ ...this.props }
					onChange={ ( selectedOptions ) => {
						this.setState( { selectedOptions: selectedOptions } );
					} }
				/>
				<Button
					isDefault
					onClick={ () => {
						const { selectedOptions } = this.state;
						onChange( selectedOptions );
					} }
				>
					{ submitLabel || __( 'Select', 'wordcamporg' ) }
				</Button>
			</BaseControl>
		);
	}
}

export default VersatileSelect;

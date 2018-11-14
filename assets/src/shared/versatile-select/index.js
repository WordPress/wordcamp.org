/**
 * External dependencies
 */
import AsyncSelect from 'react-select/lib/Async';
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
		const { className, label, help, value, instanceId, onChange, options, submitLabel } = this.props;
		const id = `wordcamp-block-versatile-select-control-${ instanceId }`;

		return (
			<BaseControl
				id={ id }
				className={ classnames( 'wordcamp-components-image-alignment', className ) }
				label={ label }
				help={ help }
			>
				<AsyncSelect
					isMulti={ true }
					closeMenuOnSelect={ false }
					options={ options }
					value={ value }
					onChange={ ( selectedOptions ) => {
						this.setState( { selectedOptions: selectedOptions } );
					} }

				/>
				<Button
					isPrimary
					isDefault
					onClick={ () => {
						const { selectedOptions } = this.state;
						const selected = map( selectedOptions, 'value' );

						onChange( selected );
					} }
				>
					{ submitLabel || __( 'Select', 'wordcamporg' ) }
				</Button>
			</BaseControl>
		);
	}
}

export default VersatileSelect;

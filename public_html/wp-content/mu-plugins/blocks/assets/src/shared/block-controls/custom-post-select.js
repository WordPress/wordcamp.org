/**
 * WordPress dependencies.
 */
const { __ } = wp.i18n;
const { Component } = wp.element;
const { Button, Placeholder, Spinner } = wp.components;

/**
 * Internal dependencies.
 */
import VersatileSelect from '../../shared/versatile-select';
import './custom-post-select.scss';

/**
 * Render select box for custom posts. At any point of time, all selected options can only belong to one group.
 */
class CustomPostTypeSelect extends Component {

	/**
	 * Constructor.
	 *
	 * @param props              Props for component.
	 * @param props.selectProps  Props to be directly passed to select in Versatile select.
	 * @param props.onChange Function to be called when "Apply" Button is clicked.
	 * @param props.buildSelectOptions Function that should return an array of objects to build select options. Objects should be in this format:
	 *  [
	 *   {
	 *       label: Group1
	 *       options: []
	 *   },
	 *   {
	 *       label: Group2
	 *       options: []
	 *   },
	 *   ...
	 *  ]
	 *   Here label is name of group and options is list of array of that group. Options is array of object, where each element represent a single select option.
	 *   Option should have following format:
	 *  [
	 *   {
	 *       label: Label,
	 *       value: value,
	 *       ...
	 *   },
	 *   {
	 *       label: Label,
	 *       value: value,
	 *       ...
	 *   }
	 *   ...
	 *  ]
	 *
	 */
	constructor( props ) {
		super( props );
	}

	/**
	 * Checks if an option is disabled, based on whether selected option belongs to the same category as current option.
	 *
	 * @param option
	 * @param selected
	 * @returns {*}
	 */
	isOptionDisabled( option, selected ) {
		const { mode } = this.props.attributes;
		let chosen;

		if ( 'loading' === option.type ) {
			return true;
		}

		if ( Array.isArray( selected ) && selected.length ) {
			chosen = selected[ 0 ].type;
		}
		return chosen && chosen !== option.type;
	}

	render() {
		const {
			selectProps, selectLabel, buildSelectOptions, selectClassname, onChange, iconName, label, placeholderClassname, onButtonClick, buttonLabel
		} = this.props;

		const options = buildSelectOptions();
		let value = [];
		return (
			<Placeholder
				icon={ iconName }
				label = { label }
				className = { placeholderClassname }
			>
				<div className='wordcamp-component-custom-post-select-button-container' >
					<Button
						isDefault
						isLarge
						onClick = { onButtonClick }
					>
						{ buttonLabel }
					</Button>
				</div>
				<div className='wordcamp-component-custom-post-select-container' >
					<VersatileSelect
						className={ selectClassname }
						label = { selectLabel }
						value = { value }
						selectProps = {
							{
								options: options,
								isMulti: true,
								isOptionDisabled: ( option, selected ) => {
									return this.isOptionDisabled( option, selected);
								},
								...selectProps
							}
						}
						onChange= { onChange }
					/>
				</div>
			</Placeholder>
		)
	}
}

export default CustomPostTypeSelect;
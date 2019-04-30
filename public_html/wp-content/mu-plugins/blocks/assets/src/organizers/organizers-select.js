/**
 * External dependencies
 */
import { every, flatMap, includes } from 'lodash';

/**
 * WordPress dependencies
 */
const { Dashicon }  = wp.components;
const { Component } = wp.element;
const { __ }        = wp.i18n;

/**
 * Internal dependencies
 */
import ItemSelect, { buildOptions, Option } from '../shared/item-select';

class OrganizersSelect extends Component {
	constructor( props ) {
		super( props );

		this.buildSelectOptions    = this.buildSelectOptions.bind( this );
		this.getCurrentSelectValue = this.getCurrentSelectValue.bind( this );
		this.isLoading             = this.isLoading.bind( this );
	}

	buildSelectOptions() {
		const { entities } = this.props;
		const { wcb_organizer, wcb_organizer_team } = entities;

		const optionGroups = [
			{
				entityType : 'post',
				type       : 'wcb_organizer',
				label      : __( 'Organizers', 'wordcamporg' ),
				items      : wcb_organizer,
			},
			{
				entityType : 'term',
				type       : 'wcb_organizer_team',
				label      : __( 'Teams', 'wordcamporg' ),
				items      : wcb_organizer_team,
			},
		];

		return buildOptions( optionGroups );
	}

	getCurrentSelectValue() {
		const { attributes } = this.props;
		const { mode, item_ids } = attributes;

		const options = flatMap( this.buildSelectOptions(), ( group ) => {
			return group.options;
		} );

		let value = [];

		if ( mode && item_ids.length ) {
			value = options.filter( ( option ) => {
				return mode === option.type && includes( item_ids, option.value );
			} );
		}

		return value;
	}

	isLoading() {
		const { entities } = this.props;

		return ! every( entities, ( value ) => {
			return Array.isArray( value );
		} );
	}

	render() {
		const { label, setAttributes } = this.props;

		return (
			<ItemSelect
				className="wordcamp-organizers-select"
				label={ label }
				value={ this.getCurrentSelectValue() }
				onChange={ ( changed ) => setAttributes( changed ) }
				selectProps={ {
					options           : this.buildSelectOptions(),
					isLoading         : this.isLoading(),
					formatOptionLabel : ( optionData ) => {
						return (
							<Option { ...optionData } />
						);
					},
				} }
			/>
		);
	}
}

export default OrganizersSelect;

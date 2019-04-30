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

class SpeakersSelect extends Component {
	constructor( props ) {
		super( props );

		this.buildSelectOptions    = this.buildSelectOptions.bind( this );
		this.getCurrentSelectValue = this.getCurrentSelectValue.bind( this );
		this.isLoading             = this.isLoading.bind( this );
	}

	buildSelectOptions() {
		const { entities } = this.props;
		const { wcb_speaker, wcb_speaker_group } = entities;

		const optionGroups = [
			{
				entityType : 'post',
				type       : 'wcb_speaker',
				label      : __( 'Speakers', 'wordcamporg' ),
				items      : wcb_speaker,
			},
			{
				entityType : 'term',
				type       : 'wcb_speaker_group',
				label      : __( 'Groups', 'wordcamporg' ),
				items      : wcb_speaker_group,
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
		const { label, icon, setAttributes } = this.props;

		return (
			<ItemSelect
				className="wordcamp-speakers-select"
				label={ label }
				value={ this.getCurrentSelectValue() }
				onChange={ ( changed ) => setAttributes( changed ) }
				selectProps={ {
					options           : this.buildSelectOptions(),
					isLoading         : this.isLoading(),
					formatOptionLabel : ( optionData ) => {
						return (
							<Option
								icon={ 'wcb_speaker_group' === optionData.type ? icon : null }
								{ ...optionData }
							/>
						);
					},
				} }
			/>
		);
	}
}

export default SpeakersSelect;

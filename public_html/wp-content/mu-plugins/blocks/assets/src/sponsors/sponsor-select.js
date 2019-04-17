/**
 * External dependencies.
 */
import { includes } from 'lodash';

/**
 * WordPress dependencies
 */
const { Component }       = wp.element;
const { __ } = wp.i18n;


import ItemSelect from '../shared/item-select';

function SponsorOption( option ) {
	let sponsorOption;

	if ( 'post' === option.type ) {
		sponsorOption = SponsorPostOption( option );
	} else {
		sponsorOption = SponsorLevelOption( option );
	}

	return sponsorOption;
}

function SponsorPostOption( sponsor ) {
	return (
		<span>
			{ sponsor.label }
		</span>
	);
}

function SponsorLevelOption( sponsorLevel ) {
	return (
		<span className="wordcamp-item-select-option-label">
			{ sponsorLevel.label }
			<span className="wordcamp-item-select-option-label-term-count">
				{ sponsorLevel.count }
			</span>
		</span>
	);
}


class SponsorsSelect extends Component {
	/**
	 * Sets `mode`, `term_ids` and `post_ids` attribute when `Apply` button is
	 * clicked. Pass `onChange` prop to override.
	 *
	 * @param {Array} selectedOptions Array of values, type of selected options
	 */
	onChange( selectedOptions = {} ) {
		const { setAttributes } = this.props;
		const newValue          = selectedOptions.item_ids;
		const chosen            = selectedOptions.mode;

		if ( newValue && chosen ) {
			switch ( chosen ) {
				case 'post' :
					setAttributes( {
						mode     : 'specific_posts',
						post_ids : newValue,
					} );
					break;

				case 'term' :
					setAttributes( {
						mode     : 'specific_terms',
						term_ids : newValue,
					} );
					break;
			}
		} else {
			setAttributes( {
				mode     : '',
				post_ids : [],
				term_ids : [],
			} );
		}
	}

	/**
	 * Generate options array to be passed to select2.
	 *
	 * @return {Array}
	 */
	buildSelectOptions() {
		const { posts, terms } = this.props;
		const options = [];

		options.push( {
			label   : __( 'Sponsor Levels', 'wordcamporg' ),
			options : terms,
		} );

		options.push( {
			label   : __( 'Sponsors', 'wordcamporg' ),
			options : posts,
		} );

		return options;
	}

	render() {
		const { posts, terms, attributes } = this.props;
		const { mode, term_ids, post_ids } = attributes;

		let selectedOptions = [];

		switch ( mode ) {
			case 'all' :
				break;
			case 'specific_posts' :
				selectedOptions = posts.filter( ( post ) => {
					return includes( post_ids, post.value );
				} );
				break;
			case 'specific_terms' :
				selectedOptions = terms.filter( ( term ) => {
					return includes( term_ids, term.value );
				} );
				break;
			default:
				break;
		}

		return (
			<ItemSelect
				buildSelectOptions={
					() => {
						return this.buildSelectOptions();
					}
				}
				onChange={
					( newOptions ) => {
						return this.onChange( newOptions );
					}
				}
				selectProps={
					{
						formatOptionLabel: ( optionData ) => {
							return (
								<SponsorOption { ...optionData } />
							);
						},
						isLoading : false,
					}
				}
				label={ __( 'Or, choose specific sponsors or levels', 'wordcamporg' ) }
				value={ selectedOptions }
			/>
		);
	}
}

export default SponsorsSelect;

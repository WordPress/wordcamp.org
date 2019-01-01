/**
 * External dependencies
 */
import { filter, includes, map } from 'lodash';

/**
 * WordPress dependencies
 */
const { Dashicon } = wp.components;
const { withSelect } = wp.data;
const { Component } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import AvatarImage from '../shared/avatar';
import VersatileSelect from '../shared/versatile-select';

// lots of stuff here, can/should some of it be black-boxed into VersitileSelect, so it's DRY when we start using this component in other blocks?

// this is pretty big, could it be broken reasonably down into smaller components?

class SpeakersSelect extends Component {
	constructor( props ) {
		super( props );

		this.optionDisabled = this.optionDisabled.bind( this );
		this.render = this.render.bind( this );
	}

	static optionImage( optionData ) {
		const { type } = optionData;

		let image;

		switch ( type ) {
			case 'post' :
				image = (
					<AvatarImage
						className={ 'wordcamp-speakers-select-option-avatar' }
						name={ optionData.label }
						size={ 24 }
						url={ optionData.avatar }

						// formatting is messed up when no gravatar exists for user
					/>
				);
				break;

			case 'term' :
				image = (
					<div className={ 'wordcamp-speakers-select-option-icon-container' }>
						<Dashicon
							className={ 'wordcamp-speakers-select-option-icon' }
							icon={ 'megaphone' }
							size={ 16 }
						/>
					</div>
				);
				break;
		}

		return image;
	}

	static optionLabel( optionData ) {
		const { type } = optionData;
		// would it be better to include label and count in ^, and then name the return value something like `labelMarkup` ?

		let label;

		switch ( type ) {
			case 'post' :
				label = (
					<span className={ 'wordcamp-speakers-select-option-label' }>
						{ optionData.label }
					</span>
				);
				break;

			case 'term' :
				label = (
					<span className={ 'wordcamp-speakers-select-option-label' }>
						{ optionData.label }
						<span className={ 'wordcamp-speakers-select-option-label-term-count' }>
							{ optionData.count }
						</span>
					</span>
				);
				break;
		}

		return label;
	}

	optionDisabled( option, selected ) {
		const { mode } = this.props.attributes;
		let chosen;

		if ( Array.isArray( selected ) && selected.length ) {
			chosen = selected[ 0 ].type;
		}

		if ( 'specific_terms' === mode && 'post' === option.type ) {
			return true;
		}

		if ( 'specific_posts' === mode && 'term' === option.type ) {
			return true;
		}

		return chosen && chosen !== option.type;
	}

	render() {
		const { label, attributes, setAttributes, selectOptions } = this.props;
		const { mode, post_ids, term_ids } = attributes;

		let currentValue, ids;

		switch ( mode ) {
			case 'specific_posts' :
				ids = post_ids;
				break;

			case 'specific_terms' :
				ids = term_ids;
				break;
		}

		if ( ids ) {
			currentValue = filter( selectOptions[ 0 ].options, ( o ) => {
				// what is `o`? an option? the name should be descriptive and obvious
				return includes( ids, o.value );
			} );
		}

		return (
			<VersatileSelect
				label={ label }
				value={ currentValue }
				options={ selectOptions }
				isOptionDisabled={ this.optionDisabled }
				formatGroupLabel={ ( groupData ) => {
					return (
						<span className={ 'wordcamp-speakers-select-option-group-label' }>
							{ groupData.label }
						</span>
					);
				} }
				formatOptionLabel={ ( optionData ) => {
					return (
						<div className={ 'wordcamp-speakers-select-option' }>
							{ this.constructor.optionImage( optionData ) }
							{ this.constructor.optionLabel( optionData ) }
						</div>
					);
				} }
				onChange={ ( selectedOptions ) => {
					// this seems like it's big enough that it'd be more readable if it were modularized into a named function
					// even the smaller ones above feel like they could be too. if it's not a 1-liner then it makes the element harder to scan quickly, and mixes logic with markup
					const value = map( selectedOptions, 'value' );

					if ( ! value.length ) {
						// i think this would flow better if it were `if ( value.length ) { stuff } else { other stuff }`. `if ( ! true } {} else {}` is a bit awkward to read
						setAttributes( {
							mode     : '',
							post_ids : [],
							term_ids : [],
						} );
					} else {
						const chosen = selectedOptions[ 0 ].type;

						switch ( chosen ) {
							case 'post' :
								setAttributes( {
									mode     : 'specific_posts',
									post_ids : value,
								} );
								break;

							case 'term' :
								setAttributes( {
									mode     : 'specific_terms',
									term_ids : value,
								} );
								break;
						}
					}
				} }
				{ ...this.props }
			/>
		);
	}
}

const optionsSelect = ( select, props ) => {
	const { mode } = props.attributes;
	const { getEntityRecords } = select( 'core' );

	let options = [];

	if ( ! mode || 'specific_terms' === mode ) {
		// should there be some kind of UI to let the user know they can only select groups at the start, and can't mix speakers/groups?

		const terms = getEntityRecords( 'taxonomy', 'wcb_speaker_group', {
			orderby  : 'name',
			order    : 'asc',
			per_page : 100,
		} );

		options.push( {
			label   : __( 'Groups', 'wordcamporg' ),
			options : map( terms || [], ( term ) => {
				return {
					label : decodeEntities( term.name ) || __( '(Untitled)', 'wordcamporg' ),
					value : term.id,
					type  : 'term',
					count : term.count,
				};
			} ),
		} );
	}

	if ( ! mode || 'specific_posts' === mode ) {
		const posts = getEntityRecords( 'postType', 'wcb_speaker', {
			orderby  : 'title',
			order    : 'asc',
			per_page : 100,
			_embed   : true,
		} );

		options.push( {
			label   : __( 'Speakers', 'wordcamporg' ),
			options : map( posts || [], ( post ) => {
				return {
					label  : decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
					value  : post.id,
					type   : 'post',
					avatar : post[ 'avatar_urls' ][ '24' ],
				};
			} ),
		} );
	}

	return {
		selectOptions: options,
	};
};

export default withSelect( optionsSelect )( SpeakersSelect );

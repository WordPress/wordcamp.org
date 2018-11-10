/**
 * WordPress dependencies
 */
const { Dashicon } = wp.components;
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import AvatarImage from '../shared/avatar';
import VersatileSelect from '../shared/versatile-select';

class SpeakersSelect extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			selectedOptions: [],
		};

		this.optionFilter = this.optionFilter.bind( this );
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
						size={ 30 }
						url={ optionData.avatar }
					/>
				);
				break;

			case 'term' :
				image = (
					<Dashicon
						className={ 'wordcamp-speakers-select-option-icon' }
						icon={ 'megaphone' }
						size={ 30 }
					/>
				);
				break;
		}

		return image;
	}

	static optionLabel( optionData ) {
		const { type } = optionData;

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

	optionFilter( option ) {
		const { selectedOptions } = this.state;
		let chosen;

		if ( ! _.isUndefined( selectedOptions ) ) {
			chosen = selectedOptions.pop().type;
		}

		if ( chosen !== option.type ) {
			return false;
		}

		if ( 'specific_terms' === mode && 'post' === option.type ) {
			return false;
		}

		if ( 'specific_posts' === mode && 'term' === option.type ) {
			return false;
		}

		return true;
	}

	render() {
		const { attributes, setAttributes, selectOptions } = this.props;
		const { mode, post_ids, term_ids } = attributes;

		let currentValue;

		switch ( mode ) {
			case 'specific_posts' :
				currentValue = post_ids;
				break;

			case 'specific_terms' :
				currentValue = term_ids;
				break;
		}

		return (
			<VersatileSelect
				label={ __( 'Choose specific speakers or groups', 'wordcamporg' ) }
				value={ currentValue }
				options={ selectOptions }
				filterOption={ this.optionFilter }
				formatGroupLabel={ ( groupData ) => {
					return (
						<span className={ 'wordcamp-speakers-select-option-group-label' }>
							{ groupData.label }
							<span className={ 'wordcamp-speakers-select-option-group-item-count' }>
								{ groupData.options.length }
							</span>
						</span>
					);
				} }
				formatOptionLabel={ ( optionData ) => {
					return (
						<Fragment>
							{ this.constructor.optionImage( optionData ) }
							{ this.constructor.optionLabel( optionData ) }
						</Fragment>
					);
				} }
				onChange={ ( value ) => {
					const { selectedOptions } = this.state;

					if ( ! value ) {
						setAttributes( {
							mode: '',
							post_ids: [],
							term_ids: [],
						} );
					} else {
						const chosen = selectedOptions.pop().type;

						switch ( chosen ) {
							case 'post' :
								setAttributes( {
									mode: 'specific_posts',
									post_ids: value,
								} );
								break;

							case 'term' :
								setAttributes( {
									mode: 'specific_terms',
									term_ids: value,
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
		const terms = getEntityRecords( 'taxonomy', 'wcb_speaker_group', {
			orderby: 'name',
			order: 'asc',
			per_page: 100,
		} );

		options.push( {
			label: __( 'Groups', 'wordcamporg' ),
			options: _.map( terms || [], ( term ) => {
				return {
					label: decodeEntities( term.name ) || __( '(Untitled)', 'wordcamporg' ),
					value: term.id,
					type: 'term',
					count: term.count,
				};
			} ),
		} );
	}

	if ( ! mode || 'specific_posts' === mode ) {
		const posts = getEntityRecords( 'postType', 'wcb_speaker', {
			orderby: 'title',
			order: 'asc',
			per_page: 100,
			_embed: true,
		} );

		options.push( {
			label: __( 'Speakers', 'wordcamporg' ),
			options: _.map( posts || [], ( post ) => {
				return {
					label: decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
					value: post.id,
					type: 'post',
					avatar: post[ 'avatar_urls' ][ '24' ],
				};
			} ),
		} );
	}

	return {
		selectOptions: options,
	};
};

export default withSelect( optionsSelect )( SpeakersSelect );

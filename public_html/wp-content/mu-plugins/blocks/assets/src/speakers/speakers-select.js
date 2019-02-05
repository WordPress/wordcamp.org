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

function SpeakersOption( {
	type,
	label  = '',
	avatar = '',
	count  = 0,
} ) {
	let image, content;

	switch ( type ) {
		case 'post' :
			image = (
				<AvatarImage
					className="wordcamp-speakers-select-option-avatar"
					name={ label }
					size={ 24 }
					url={ avatar }
				/>
			);
			content = (
				<span className="wordcamp-speakers-select-option-label">
					{ label }
				</span>
			);
			break;

		case 'term' :
			image = (
				<div className="wordcamp-speakers-select-option-icon-container">
					<Dashicon
						className="wordcamp-speakers-select-option-icon"
						icon={ 'megaphone' }
						size={ 16 }
					/>
				</div>
			);
			content = (
				<span className="wordcamp-speakers-select-option-label">
					{ label }
					<span className="wordcamp-speakers-select-option-label-term-count">
						{ count }
					</span>
				</span>
			);
			break;
	}

	return (
		<div className="wordcamp-speakers-select-option">
			{ image }
			{ content }
		</div>
	);
}

class SpeakersSelect extends Component {
	constructor( props ) {
		super( props );

		this.optionDisabled = this.optionDisabled.bind( this );
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
						<span className="wordcamp-speakers-select-option-group-label">
							{ groupData.label }
						</span>
					);
				} }
				formatOptionLabel={ ( optionData ) => {
					return (
						<SpeakersOption { ...optionData } />
					);
				} }
				onChange={ ( selectedOptions ) => {
					const value = map( selectedOptions, 'value' );

					if ( ! value.length ) {
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
					avatar : post.avatar_urls[ '24' ],
				};
			} ),
		} );
	}

	return {
		selectOptions: options,
	};
};

export default withSelect( optionsSelect )( SpeakersSelect );

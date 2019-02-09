/**
 * External dependencies
 */
import { filter, includes, map } from 'lodash';

/**
 * WordPress dependencies
 */
const apiFetch = wp.apiFetch;
const { Dashicon, Spinner } = wp.components;
const { Component } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;
const { addQueryArgs } = wp.url;

/**
 * Internal dependencies
 */
import AvatarImage from '../shared/avatar';
import VersatileSelect from '../shared/versatile-select';

const POSTS_QUERY = {
	orderby  : 'title',
	order    : 'asc',
	per_page : 100,
	_embed   : true,
};

const TERMS_QUERY = {
	orderby  : 'name',
	order    : 'asc',
	per_page : 100,
};

class SpeakersSelect extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			postsLoaded : false,
			posts       : [],
			termsLoaded : false,
			terms       : [],
		};

		this.buildSelectOptions = this.buildSelectOptions.bind( this );
		this.isOptionDisabled = this.isOptionDisabled.bind( this );
	}

	componentWillMount() {
		this.isStillMounted = true;

		this.termsFetchRequest = apiFetch( {
			path: addQueryArgs( `/wp/v2/speaker_group`, TERMS_QUERY ),
		} ).then(
			( fetchedTerms ) => {
				const terms = map( fetchedTerms || [], ( term ) => {
					return {
						label : decodeEntities( term.name ) || __( '(Untitled)', 'wordcamporg' ),
						value : term.id,
						type  : 'term',
						count : term.count,
					};
				} );

				if ( this.isStillMounted ) {
					this.setState( { terms, termsLoaded: true } );
				}
			}
		).catch(
			() => {
				if ( this.isStillMounted ) {
					this.setState( { terms: [], termsLoaded: true } );
				}
			}
		);

		this.postsFetchRequest = apiFetch( {
			path: addQueryArgs( `/wp/v2/speakers`, POSTS_QUERY ),
		} ).then(
			( fetchedPosts ) => {
				const posts = map( fetchedPosts || [], ( post ) => {
					return {
						label  : decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
						value  : post.id,
						type   : 'post',
						avatar : post.avatar_urls[ '24' ],
					};
				} );

				if ( this.isStillMounted ) {
					this.setState( { posts, postsLoaded: true } );
				}
			}
		).catch(
			() => {
				if ( this.isStillMounted ) {
					this.setState( { posts: [], postsLoaded: true } );
				}
			}
		);
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	buildSelectOptions( mode ) {
		const { termsLoaded, terms, postsLoaded, posts } = this.state;
		const options = [];

		if ( ! termsLoaded || ! postsLoaded ) {
			return [ {
				label : __( 'Loading', 'wordcamporg' ),
				value : '',
				type  : 'loading',
			} ];
		}

		if ( ! mode || 'specific_terms' === mode ) {
			options.push( {
				label   : __( 'Groups', 'wordcamporg' ),
				options : terms,
			} );
		}

		if ( ! mode || 'specific_posts' === mode ) {
			options.push( {
				label   : __( 'Speakers', 'wordcamporg' ),
				options : posts,
			} );
		}

		return options;
	}

	isOptionDisabled( option, selected ) {
		const { mode } = this.props.attributes;
		let chosen;

		if ( 'loading' === option.type ) {
			return true;
		}

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
		const { label, attributes, setAttributes } = this.props;
		const { mode, post_ids, term_ids } = attributes;

		const selectOptions = this.buildSelectOptions( mode );

		let currentValue;

		switch ( mode ) {
			case 'specific_posts' :
				currentValue = filter( selectOptions[ 0 ].options, ( option ) => {
					return includes( post_ids, option.value );
				} );
				break;

			case 'specific_terms' :
				currentValue = filter( selectOptions[ 0 ].options, ( option ) => {
					return includes( term_ids, option.value );
				} );
				break;
		}

		return (
			<VersatileSelect
				className="wordcamp-speakers-select"
				label={ label }
				value={ currentValue }
				options={ selectOptions }
				isOptionDisabled={ this.isOptionDisabled }
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
			/>
		);
	}
}

function SpeakersOption( { type, label = '', avatar = '', count = 0 } ) {
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

		case 'loading' :
			image = (
				<div className="wordcamp-speakers-select-loading-container">
					<Spinner />
				</div>
			);
			content = (
				<span className="wordcamp-speakers-select-option-label">
					{ label }
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

export default SpeakersSelect;

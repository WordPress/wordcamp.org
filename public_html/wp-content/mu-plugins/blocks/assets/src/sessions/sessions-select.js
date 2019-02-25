/**
 * External dependencies
 */
import { get, includes } from 'lodash';

/**
 * WordPress dependencies
 */
const { Dashicon } = wp.components;
const { Component } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import VersatileSelect from '../shared/versatile-select';

class SessionsSelect extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			wcb_session          : [],
			wcb_track            : [],
			wcb_session_category : [],
			loading              : true,
		};

		this.buildSelectOptions = this.buildSelectOptions.bind( this );
		this.isOptionDisabled = this.isOptionDisabled.bind( this );
	}

	componentWillMount() {
		this.isStillMounted = true;

		const { allSessionPosts, allSessionTracks, allSessionCategories } = this.props;
		const promises = [];

		promises.push( allSessionPosts.then(
			( fetchedPosts ) => {
				const posts = fetchedPosts.map( ( post ) => {
					const image = get( post, '_embedded[\'wp:featuredmedia\'].media_details.sizes.thumbnail.source_url', '' );

					return {
						label : decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
						value : post.id,
						type  : 'wcb_session',
						image : image,
					};
				} );

				if ( this.isStillMounted ) {
					this.setState( { wcb_session: posts } );
				}
			}
		).catch() );

		[ allSessionTracks, allSessionCategories ].forEach( ( promise ) => {
			promises.push( promise.then(
				( fetchedTerms ) => {
					const terms = fetchedTerms.map( ( term ) => {
						return {
							label : decodeEntities( term.name ) || __( '(Untitled)', 'wordcamporg' ),
							value : term.id,
							type  : term.taxonomy,
							count : term.count || 0,
						};
					} );

					if ( this.isStillMounted ) {
						const [ firstTerm ] = terms;
						this.setState( { [ firstTerm.type ]: terms } );
					}
				}
			).catch() );
		} );

		Promise.all( promises ).then( () => {
			this.setState( { loading: false } );
		} );
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	buildSelectOptions( mode ) {
		const options = [];

		const labels = {
			wcb_session          : __( 'Sessions', 'wordcamporg' ),
			wcb_track            : __( 'Tracks', 'wordcamporg' ),
			wcb_session_category : __( 'Session Categories', 'wordcamporg' ),
		};

		for ( const type in this.state ) {
			if ( this.state.hasOwnProperty( type ) && ( ! mode || type === mode ) && this.state[ type ].length ) {
				options.push( {
					label   : labels[ type ],
					options : this.state[ type ],
				} );
			}
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

		if ( mode && mode !== option.type ) {
			return true;
		}

		return chosen && chosen !== option.type;
	}

	render() {
		const { label, attributes, setAttributes } = this.props;
		const { mode, item_ids } = attributes;
		const options = this.buildSelectOptions( mode );

		let value = [];

		if ( mode && item_ids.length ) {
			value = options[ 0 ].options.filter( ( option ) => {
				return includes( item_ids, option.value );
			} );
		}

		return (
			<VersatileSelect
				className="wordcamp-sessions-select"
				label={ label }
				value={ value }
				onChange={ ( selectedOptions ) => {
					const newValue = selectedOptions.map( ( option ) => option.value );

					if ( newValue.length ) {
						const chosen = selectedOptions[ 0 ].type;

						setAttributes( {
							mode     : chosen,
							item_ids : newValue,
						} );
					} else {
						setAttributes( {
							mode     : '',
							item_ids : [],
						} );
					}
				} }
				selectProps={ {
					isLoading        : this.state.loading,
					options          : options,
					isMulti          : true,
					isOptionDisabled : this.isOptionDisabled,
					formatGroupLabel : ( groupData ) => {
						return (
							<span className="wordcamp-sessions-select-option-group-label">
								{ groupData.label }
							</span>
						);
					},
					formatOptionLabel: ( optionData ) => {
						return (
							<SessionsOption { ...optionData } />
						);
					},
				} }
			/>
		);
	}
}

function SessionsOption( { type, label = '', image = '', count = 0 } ) {
	let optImage, optContent;

	switch ( type ) {
		case 'wcb_session' :
			if ( image ) {
				optImage = (
					<img
						className="wordcamp-block-select-option-image"
						src={ image }
						alt={ label }
						width={ 24 }
						height={ 24 }
					/>
				);
			} else {
				optImage = (
					<div className="wordcamp-block-select-option-icon-container">
						<Dashicon
							className="wordcamp-block-select-option-icon"
							icon={ 'list-view' }
							size={ 16 }
						/>
					</div>
				);
			}
			optContent = (
				<span className="wordcamp-block-select-option-label">
					{ label }
				</span>
			);
			break;

		case 'wcb_track' :
		case 'wcb_session_category' :
			optImage = (
				<div className="wordcamp-block-select-option-icon-container">
					<Dashicon
						className="wordcamp-block-select-option-icon"
						icon={ 'list-view' }
						size={ 16 }
					/>
				</div>
			);
			optContent = (
				<span className="wordcamp-block-select-option-label">
					{ label }
					<span className="wordcamp-block-select-option-label-term-count">
						{ count }
					</span>
				</span>
			);
			break;
	}

	return (
		<div className="wordcamp-block-select-option">
			{ optImage }
			{ optContent }
		</div>
	);
}

export default SessionsSelect;

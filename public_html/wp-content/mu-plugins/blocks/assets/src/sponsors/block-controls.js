import { get } from 'lodash';

/**
 * WordPress dependencies.
 */
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies.
 */
import { BlockControls, PlaceholderNoContent } from "../shared/block-controls";
import SponsorBlockContent from './block-content';
import CustomPostTypeSelect from '../shared/block-controls/custom-post-select'
import FeaturedImage from '../shared/featured-image';

const LABEL = __( 'Sponsors', 'wordcamporg' );

function SponsorOption( option ) {
	if ( 'post' === option.type ) {
		return SponsorPostOption( option );
	} else {
		return SponsorLevelOption( option );
	}
}

function SponsorPostOption( sponsor ) {
	const imageUrl = get( sponsor.featuredImageData, 'sizes.thumbnail.source_url', false );
	return(
		<span>
			{
				imageUrl &&
				<img
					width={24}
					height={24}
					src={ imageUrl }
					alt={ sponsor.label }
				/>
			}
			{ sponsor.label }
		</span>
	);
}

function SponsorLevelOption( sponsorLevel ) {
	return(
		<span>{ sponsorLevel.label }</span>
	);
}

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {

	constructor( props ) {
		super(props);
		this.state = {
			posts   : [],
			terms   : [],
			loading : true,
		};
	}

	/**
	 * Initialize posts and terms arrays and sets loading state till promises
	 * are not resolved.
	 */
	componentWillMount() {
		this.isStillMounted = true;

		const { sponsorPosts, sponsorLevels } = this.props;

		const parsedPosts = sponsorPosts.then(
			( fetchedPosts ) => {
				const posts = fetchedPosts.map( ( post ) => {
						return {
							label: decodeEntities(post.title.rendered.trim()) ||
								__('(Untitled)', 'wordcamporg'),
							value: post.id,
							type: 'post',
							featuredImageData: get( post,
							'_embedded.wp:featuredmedia[0].media_details', '' ),
						}
					}
				);
				if ( this.isStillMounted ) {
					this.setState( { fetchedPosts } );
					this.setState( { posts } );
				}
			}
		).catch( (e) => {
			console.error("Error fetching data", e );
		});

		const parsedTerms = sponsorLevels.then(
			( fetchedTerms ) => {
				const terms = fetchedTerms.map( ( term ) => {
					return {
						label : decodeEntities( term.name ) || __( '(Untitled)', 'wordcamporg' ),
						value : term.id,
						type  : 'term',
						count : term.count,
					};
				} );

				if ( this.isStillMounted ) {
					this.setState( { fetchedTerms } );
					this.setState( { terms } );
				}
			}
		).catch( (e) => {
			console.error("Error fetching data", e );
		});

		Promise.all( [ parsedPosts, parsedTerms ] ).then( () => {
			this.setState( { loading: false } );
		} );
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	/**
	 * Sets `mode`, `term_ids` and `post_ids` attribute when `Apply` button is
	 * clicked. Pass `onChange` prop to override.
	 *
	 * @param selectedOptions Array of values, type of selected options
	 */
	onChange( selectedOptions ) {
		const { setAttributes } = this.props;
		const newValue = selectedOptions.map( ( option ) => option.value );

		if ( newValue.length ) {
			const chosen = selectedOptions[ 0 ].type;

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
	 */
	buildSelectOptions() {
		const { posts, terms } = this.state;
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

	/**
	 * Renders Sponsor Block Control view
	 */
	render() {
		const { sponsorPosts, attributes, setAttributes } = this.props;
		const { mode, post_ids, term_ids } = attributes;
		const { fetchedPosts } = this.state;
		const hasPosts = Array.isArray( fetchedPosts ) && fetchedPosts.length;
		console.log("This called.. Attr are: ", attributes );

		// Check if posts are still loading.
		if ( mode && ! hasPosts ) {
			return (
				<PlaceholderNoContent
					label = { LABEL }
					loading = { () => {
						return ! Array.isArray( sponsorPosts );
					} }
				/>
			)
		}

		let selectedPosts = [];

		switch ( mode ) {
			case 'all' :
				selectedPosts = fetchedPosts;
				break;
			case 'specific_posts' :
				selectedPosts = fetchedPosts.filter( ( post ) => {
						return post_ids.indexOf( post.id ) !== -1;
					}
				);
				break;
			case 'specific_terms' :
				selectedPosts = fetchedPosts.filter( ( post ) => {
						return false;
					}
				);
				break;
			default:
				break;
		}

		return (
			<div>
				<SponsorBlockContent
					selectedPosts = { selectedPosts }
					{ ...this.props }
				/>
				<CustomPostTypeSelect
					buildSelectOptions = {
						() => {
							return this.buildSelectOptions()
						}
					}
					isLoading = { this.state.loading }
					onChange = {
						( selectedOptions ) => {
							return this.onChange( selectedOptions );
						}
					}
					selectProps = {
						{
							formatOptionLabel : ( optionData ) => {
								return (
									<SponsorOption { ...optionData } />
								);
							}
						}
					}
					onButtonClick = { () => { setAttributes( { mode: 'all' } ); } }
					buttonLabel = { __( 'List all sponsors', 'wordcamporg' ) }
					iconName = "heart"
					label = { __( 'Sponsors', 'wordcamporg' ) }
					selectLabel = { __( 'Choose specific speakers or levels', 'wordcamporg' ) }
					{ ...this.props }
				/>
			</div>
		);
	}

}

export default SponsorBlockControls;
/**
 * External dependencies.
 */
import { includes } from 'lodash';


/**
 * WordPress dependencies.
 */
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;
const { Component } = wp.element;

/**
 * Internal dependencies.
 */
import VersatileSelect from '../../shared/versatile-select';

/**
 * Render select box for custom posts. Will call `setattribute` to set mode,
 * posts and terms ids when `Apply` button is clicked.
 */
class CustomPostTypeSelect extends Component {

	/**
	 * Constructor.
	 *
	 * @param props              Props for component.
	 * @param props.allPosts     Promise which resolves into array of post object
	 *     which will be available as selection.
	 * @param props.allTerms     Promise which resolves into array of terms object
	 *     which will be available as selection.
	 * @param props.getAvatarUrl Function to get avatar URL from a post object.
	 * @param props.termLabel    Label for terms
	 * @param props.postLabel    Label for posts
	 * @param props.selectProps  Props to be directly passed to select in
	 *     Versatile select.
	 */
	constructor( props ) {
		super( props );
		this.state = {
			posts   : [],
			terms   : [],
			loading : true,
		};
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
	 * Initialize posts and terms arrays and sets loading state till promises are not resolved.
	 */
	componentWillMount() {
		this.isStillMounted = true;

		const { allPosts, allTerms, getAvatarUrl } = this.props;

		const parsedPosts = allPosts.then(
			( fetchedPosts ) => {
				const posts = fetchedPosts.map( ( post ) => {
					const postObject = {
						label  : decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
						value  : post.id,
						type   : 'post',
					};
					if ( "function" === typeof getAvatarUrl ) {
						postObject.avatar = getAvatarUrl( post );
					}
					return postObject;
				} );

				if ( this.isStillMounted ) {
					this.setState( { posts } );
				}
			}
		).catch( (e) => {
			console.log("Error fetching data", e );
		});

		const parsedTerms = allTerms.then(
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
					this.setState( { terms } );
				}
			}
		).catch( (e) => {
			console.log("Error fetching data", e );
		});

		Promise.all( [ parsedPosts, parsedTerms ] ).then( () => {
			this.setState( { loading: false } );
		} );
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	/**
	 * Generate options array to be passed to select2.
	 */
	buildSelectOptions( mode, postLabel, termLabel ) {
		const { posts, terms } = this.state;
		const options = [];

		if ( ! mode || 'specific_terms' === mode ) {
			options.push( {
				label   : __( termLabel, 'wordcamporg' ),
				options : terms,
			} );
		}

		if ( ! mode || 'specific_posts' === mode ) {
			options.push( {
				label   : __( postLabel, 'wordcamporg' ),
				options : posts,
			} );
		}

		return options;
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

		if ( 'specific_terms' === mode && 'post' === option.type ) {
			return true;
		}

		if ( 'specific_posts' === mode && 'term' === option.type ) {
			return true;
		}

		return chosen && chosen !== option.type;
	}

	render() {
		const { selectProps, postLabel, termLabel, label, selectedValue, selectClassname } = this.props;
		const { mode, post_ids, term_ids } = this.props.attributes;

		const options = this.buildSelectOptions( mode, postLabel, termLabel );
		let value = [];

		if ( 'specific_posts' === mode && options.length ) {
			value = options[ 0 ].options.filter( ( option ) => {
				return includes( post_ids, option.value );
			} );
		} else if ( 'specific_terms' === mode && options.length ) {
			value = options[ 0 ].options.filter( ( option ) => {
				return includes( term_ids, option.value );
			} );
		}

		return (
			<VersatileSelect
				className={ selectClassname }
				label = { label }
				value = { value }
				selectProps = {
					{
						options: options,
						isMulti: true,
						isLoading: this.state.loading,
						isOptionDisabled: ( option, selected ) => {
							this.isOptionDisabled( option, selected);
						},
						...selectProps
					}
				}
				onChange={ ( selectedOptions ) => { this.onChange( selectedOptions ) } }
			/>
		)
	}
}

export default CustomPostTypeSelect;
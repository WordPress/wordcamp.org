/**
 * External dependencies
 */
import { find } from 'lodash';

/**
 * WordPress dependencies
 */
const { Button, Placeholder, Spinner } = wp.components;
const { Component, Fragment } = wp.element;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import SpeakersBlockContent from './block-content';
import SpeakersSelect from './speakers-select';

const data = window.WordCampBlocks.speakers || {};

class SpeakersBlockControls extends Component {
	render() {
		const { attributes, setAttributes, speakerPosts } = this.props;
		const { mode } = attributes;
		const { options } = data;

		const hasPosts = Array.isArray( speakerPosts ) && speakerPosts.length;

		/*
		 what do you think about modularizing all these different returns into named functions?
		 it seems like it'd be easier to scan the structure and also focus on individual parts that way
		 that could also simplify the structure into a single if..then..else block, rather than an if, a switch, and an implicit else

		```
		let content;

		if ( mode && ! hasPosts ) {
			content = noPostsPlaceholder();
		} elseif ( 'all' === mode ) {
			content = ( <SpeakersBlockContent { ...this.props } /> );
		} elseif ( 'specific_posts' === mode ) {
			content = specificPostsView();
		} elseif ( 'specific_terms' === mode ) {
			content = specificTermsView();
		} else {
			content = newBlockPlaceholder();
		}

		return content;
		```

		that'd also resolve the CS violation about returning from switch statements
		https://make.wordpress.org/core/handbook/best-practices/coding-standards/javascript/#switch-statements

		 */

		if ( mode && ! hasPosts ) {
			return (
				<Placeholder
					icon="megaphone"
					label={ __( 'Speakers', 'wordcamporg' ) }
				>
					{ ! Array.isArray( speakerPosts ) ?
						<Spinner /> :
						__( 'No posts found.', 'wordcamporg' )
					}
				</Placeholder>
			);
		}

		switch ( mode ) {
			case 'all' :
				return (
					<SpeakersBlockContent { ...this.props } />
				);

			case 'specific_posts' :
				const postsLabel = find( options.mode, ( modeOption ) => {
					return 'specific_posts' === modeOption.value;
				} ).label;

				return (
					<Fragment>
						<SpeakersBlockContent { ...this.props } />
						<Placeholder
							icon="megaphone"
							label={ postsLabel }
						>
							<SpeakersSelect { ...this.props } />
						</Placeholder>
					</Fragment>
				);

			case 'specific_terms' :
				const termsLabel = find( options.mode, ( modeOption ) => {
					return 'specific_terms' === modeOption.value;
				} ).label;

				return (
					<Fragment>
						<SpeakersBlockContent { ...this.props } />
						<Placeholder
							icon="megaphone"
							label={ termsLabel }
						>
							<SpeakersSelect { ...this.props } />
						</Placeholder>
					</Fragment>
				);
		}

		return (
			<Placeholder
				icon={ 'megaphone' }
				label={ __( 'Speakers', 'wordcamporg' ) }
			>
				<div className={ 'wordcamp-block-speakers-mode-option' }>
					<Button
						isDefault
						isLarge
						onClick={ () => {
							setAttributes( { mode: 'all' } );
						} }
					>
						{ find( options.mode, ( modeOption ) => {
							return 'all' === modeOption.value;
						} ).label }
					</Button>
				</div>

				<div className={ 'wordcamp-block-speakers-mode-option' }>
					<SpeakersSelect
						label={ __( 'Choose specific speakers or groups', 'wordcamporg' ) }
						{ ...this.props }
					/>
				</div>
			</Placeholder>
		);
	}
}

export default SpeakersBlockControls;

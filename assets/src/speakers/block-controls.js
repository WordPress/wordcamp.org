/**
 * External dependencies
 */
const { find } = window.lodash;

/**
 * WordPress dependencies
 */
const { Button, Placeholder, Spinner, TextControl } = wp.components;
const { Component, Fragment } = wp.element;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import SpeakersBlockContent from './block-content';

const data = window.WordCampBlocks.speakers || {};

class SpeakersBlockControls extends Component {
	render() {
		const { attributes, setAttributes, speakerPosts } = this.props;
		const { mode } = attributes;
		const { options } = data;

		const hasPosts = Array.isArray( speakerPosts ) && speakerPosts.length;

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
			case 'query' :
				return (
					<SpeakersBlockContent { ...this.props } />
				);

			case 'specific' :
				return (
					<Fragment>
						<SpeakersBlockContent { ...this.props } />
						<Placeholder
							label={ __( 'Add more speakers', 'wordcamporg' ) }
						>
							<TextControl
								label={ 'Temporary input' }
							/>
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
						onClick={ () => { setAttributes( { mode: 'query' } ) } }
					>
						{ find( options.mode, ( modeOption ) => {
							return 'query' === modeOption.value;
						} ).label }
					</Button>
				</div>

				<div className={ 'wordcamp-block-speakers-mode-option' }>
					<TextControl
						label={ find( options.mode, ( modeOption ) => {
							return 'specific' === modeOption.value;
						} ).label }
					/>
				</div>
			</Placeholder>
		);
	}
}

export default SpeakersBlockControls;

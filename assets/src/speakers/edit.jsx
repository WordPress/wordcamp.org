/**
 * External dependencies
 */
import classnames from 'classnames';
const { isUndefined, pickBy, split } = window.lodash;
const data = window.WordCampBlocks.speakers || {};

/**
 * WordPress dependencies
 */
const { CheckboxControl, Disabled, PanelBody, PanelRow, Placeholder, RangeControl, SelectControl, Spinner } = wp.components;
const { withSelect } = wp.data;
const { InspectorControls } = wp.editor;
const { Component, Fragment, RawHTML } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;
const { addQueryArgs } = wp.url;

const MAX_POSTS = 100;

class SpeakersEdit extends Component {
	render() {
		const { schema, options } = data;
		const { attributes, setAttributes, speakerPosts } = this.props;
		const { show_all_posts, posts_per_page, sort, speaker_link, show_avatars, avatar_size } = attributes;

		const inspectorControls = (
			<InspectorControls>
				<PanelBody title={ __( 'Which Speakers?', 'wordcamporg' ) } initialOpen={ true }>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Show all', 'wordcamporg' ) }
							checked={ show_all_posts }
							onChange={ ( value ) => setAttributes( { 'show_all_posts': value } ) }
						/>
					</PanelRow>
					{ ! show_all_posts &&
						/*<PanelRow>*/
							<RangeControl
								label={ __( 'Number to show', 'wordcamporg' ) }
								value={ Number( posts_per_page ) }
								min={ Number( schema['posts_per_page'].minimum ) }
								max={ MAX_POSTS }
								initialPosition={ Number( schema['posts_per_page'].default ) }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { 'posts_per_page': value } ) }
							/>
						/*</PanelRow>*/
					}
					{ /*<PanelRow>*/ }
						<SelectControl
							label={ __( 'Sort by', 'wordcamporg' ) }
							value={ sort }
							options={ options['sort'] }
							onChange={ ( value ) => setAttributes( { 'sort': value } ) }
						/>
					{ /*</PanelRow>*/ }
				</PanelBody>

				<PanelBody title={ __( 'Speaker Display', 'wordcamporg' ) } initialOpen={ false }>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Link titles to single posts', 'wordcamporg' ) }
							checked={ speaker_link }
							onChange={ ( value ) => setAttributes( { 'speaker_link': value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Show avatars', 'wordcamporg' ) }
							checked={ show_avatars }
							onChange={ ( value ) => setAttributes( { 'show_avatars': value } ) }
						/>
					</PanelRow>
					{ show_avatars &&
						/*<PanelRow>*/
							<RangeControl
								label={ __( 'Avatar size (px)', 'wordcamporg' ) }
								help={ __( 'Height and width in pixels.', 'wordcamporg' ) }
								value={ Number( avatar_size ) }
								min={ Number( schema['avatar_size'].minimum ) }
								max={ Number( schema['avatar_size'].maximum ) }
								initialPosition={ Number( schema['avatar_size'].default ) }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { 'avatar_size': value } ) }
							/>
						/*</PanelRow>*/
					}
				</PanelBody>
			</InspectorControls>
		);

		const hasPosts = Array.isArray( speakerPosts ) && speakerPosts.length;
		if ( ! hasPosts ) {
			return (
				<Fragment>
					{ inspectorControls }
					<Placeholder
						icon="megaphone"
						label={ __( 'Speakers' ) }
					>
						{ ! Array.isArray( speakerPosts ) ?
							<Spinner /> :
							__( 'No posts found.' )
						}
					</Placeholder>
				</Fragment>
			);
		}

		return (
			<Fragment>
				{ inspectorControls }
				<div className={ 'wcorg-speakers' }>
					{ speakerPosts.map( ( post, i ) =>
						<div
							key={ i }
							id={ 'wcorg-speaker-' + post.slug }
							className={ classnames( 'wcorg-speaker', 'wcorg-speaker-' + post.slug ) }
						>
							<h2>
								{ speaker_link ?
									<Disabled>
										<a href={ post.link }>
											{ decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ) }
										</a>
									</Disabled> :
									decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' )
								}
							</h2>
							<div className={ 'wcorg-speaker-description' }>
								{ show_avatars &&
									/* todo Turn this into a component */
									<img
										className={ classnames( 'avatar', 'avatar-' + avatar_size, 'photo' ) }
										src={ addQueryArgs( post['avatar_urls']['24'], { s: avatar_size } ) }
										srcSet={ addQueryArgs( post['avatar_urls']['24'], { s: avatar_size * 2 } ) + ' 2x' }
										width={ avatar_size }
										height={ avatar_size }
									/>
								}
								<Disabled>
									<RawHTML>
										{ post.content.rendered.trim() }
									</RawHTML>
								</Disabled>
							</div>
						</div>
					) }
				</div>
			</Fragment>
		);
	}
}

const speakersSelect = ( select, props ) => {
	const { show_all_posts, posts_per_page, sort } = props.attributes;
	const { getEntityRecords } = select( 'core' );
	const [ orderby, order ] = split( sort, '_', 2 );

	const speakersQuery = pickBy( {
		orderby: orderby,
		order: order,
		per_page: show_all_posts ? MAX_POSTS : posts_per_page, // -1 is not allowed for per_page.
		_embed: true
	}, ( value ) => ! isUndefined( value ) );

	return {
		speakerPosts: getEntityRecords( 'postType', 'wcb_speaker', speakersQuery ),
	};
};

export const edit = withSelect( speakersSelect )( SpeakersEdit );

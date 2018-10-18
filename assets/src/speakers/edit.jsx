/**
 * External dependencies
 */
import classnames from 'classnames';
const data = WordCampBlocks.speakers || {};

/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
const { Component, Fragment } = wp.element;
const { InspectorControls } = wp.editor;
const { ServerSideRender, PanelBody, PanelRow, CheckboxControl, RangeControl, SelectControl } = wp.components;

class SpeakersEdit extends Component {
	render() {
		const { options, schema } = data;
		const { attributes, setAttributes, speakerPosts } = this.props;
		const { show_all_posts, posts_per_page, track, groups, sort, speaker_link, show_avatars, avatar_size } = attributes;

		const inspectorControls = (
			<InspectorControls>
				<PanelBody title={ __( 'Which Speakers?', 'wordcamporg' ) } initialOpen={ true }>
					{ options['track'].length > 1 &&
						/*<PanelRow>*/
							<SelectControl
								label={ __( 'From which session tracks?', 'wordcamporg' ) }
								value={ track }
								options={ options['track'] }
								multiple={ true }
								onChange={ ( value ) => setAttributes( { 'track': value } ) }
							/>
						/*</PanelRow>*/
					}
					{ options['groups'].length > 1 &&
						/*<PanelRow>*/
							<SelectControl
								label={ __( 'From which speaker groups?', 'wordcamporg' ) }
								value={ groups }
								options={ options['groups'] }
								multiple={ true }
								onChange={ ( value ) => setAttributes( { 'groups': value } ) }
							/>
						/*</PanelRow>*/
					}
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
								value={ posts_per_page }
								min={ schema['posts_per_page'].minimum }
								max={ schema['posts_per_page'].maximum }
								initialPosition={ schema['posts_per_page'].default }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { 'posts_per_page': value } ) }
							/>
						/*</PanelRow>*/
					}
					/*<PanelRow>*/
						<SelectControl
							label={ __( 'Sort by', 'wordcamporg' ) }
							value={ sort }
							options={ options['sort'] }
							onChange={ ( value ) => setAttributes( { 'sort': value } ) }
						/>
					/*</PanelRow>*/
				</PanelBody>

				<PanelBody title={ __( 'Speaker Display', 'wordcamporg' ) } initialOpen={ false }>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Link titles to single posts', 'wordcamporg' ) }
							help={ __( 'These will not appear in the block preview.', 'wordcamporg' ) }
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
								value={ avatar_size }
								min={ schema['avatar_size'].minimum }
								max={ schema['avatar_size'].maximum }
								initialPosition={ schema['avatar_size'].default }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { 'avatar_size': value } ) }
							/>
						/*</PanelRow>*/
					}

				</PanelBody>
			</InspectorControls>
		);

		return ( /* This does not work yet */
			<Fragment>
				{ inspectorControls }
				<div
					className={ 'wcorg-speakers' }
				>
					{ speakerPosts.map( ( post, i ) =>
						<div
							id={ post.slug }
						>

						</div>
					) }
				</div>
			</Fragment>
		);
	}
}


// todo
export default withSelect(  )( SpeakersEdit );

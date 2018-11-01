const { Component } = wp.element;
const { CheckboxControl, PanelBody, PanelRow, RangeControl, SelectControl } = wp.components;
const { InspectorControls } = wp.editor;
const { __ } = wp.i18n;
const data = window.WordCampBlocks.speakers || {};
const MAX_POSTS = 100;

class SpeakerInspectorControls extends Component {
	render() {
		const { attributes, setAttributes } = this.props;
		const { show_all_posts, posts_per_page, sort, speaker_link, show_avatars, avatar_size } = attributes;
		const { schema, options } = data;

		return (
			<InspectorControls>
				<PanelBody title={ __( 'Which Speakers?', 'wordcamporg' ) } initialOpen={ true }>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Show all', 'wordcamporg' ) }
							checked={ show_all_posts }
							onChange={ ( value ) => setAttributes( { show_all_posts: value } ) }
						/>
					</PanelRow>
					{ ! show_all_posts &&
						<PanelRow>
							<RangeControl
								label={ __( 'Number to show', 'wordcamporg' ) }
								value={ Number( posts_per_page ) }
								min={ Number( schema[ 'posts_per_page' ].minimum ) }
								max={ MAX_POSTS }
								initialPosition={ Number( schema[ 'posts_per_page' ].default ) }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { posts_per_page: value } ) }
							/>
						</PanelRow>
					}
					<PanelRow>
						<SelectControl
							label={ __( 'Sort by', 'wordcamporg' ) }
							value={ sort }
							options={ options.sort }
							onChange={ ( value ) => setAttributes( { sort: value } ) }
						/>
					</PanelRow>
				</PanelBody>

				<PanelBody title={ __( 'Speaker Display', 'wordcamporg' ) } initialOpen={ false }>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Link titles to single posts', 'wordcamporg' ) }
							checked={ speaker_link }
							onChange={ ( value ) => setAttributes( { speaker_link: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<CheckboxControl
							label={ __( 'Show avatars', 'wordcamporg' ) }
							checked={ show_avatars }
							onChange={ ( value ) => setAttributes( { show_avatars: value } ) }
						/>
					</PanelRow>
					{ show_avatars &&
						<PanelRow>
							<RangeControl
								label={ __( 'Avatar size (px)', 'wordcamporg' ) }
								help={ __( 'Height and width in pixels.', 'wordcamporg' ) }
								value={ Number( avatar_size ) }
								min={ Number( schema[ 'avatar_size' ].minimum ) }
								max={ Number( schema[ 'avatar_size' ].maximum ) }
								initialPosition={ Number( schema[ 'avatar_size' ].default ) }
								allowReset={ true }
								onChange={ ( value ) => setAttributes( { avatar_size: value } ) }
							/>
						</PanelRow>
					}
				</PanelBody>
			</InspectorControls>
		);
	}
}

export default SpeakerInspectorControls;

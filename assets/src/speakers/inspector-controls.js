/**
 * WordPress dependencies
 */
const { PanelBody, PanelRow, SelectControl, ToggleControl } = wp.components;
const { InspectorControls } = wp.editor;
const { Component, Fragment } = wp.element;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import AvatarSizeControl from '../shared/avatar-size';
import ImageAlignmentControl from '../shared/image-alignment';

const data = window.WordCampBlocks.speakers || {};

class SpeakerInspectorControls extends Component {
	render() {
		const { attributes, setAttributes } = this.props;
		const { show_avatars, avatar_size, speaker_link, sort } = attributes;
		const { schema, options } = data;

		return (
			<InspectorControls>
				<PanelBody title={ __( 'Avatar Settings', 'wordcamporg' ) } initialOpen={ true }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show avatars', 'wordcamporg' ) }
							checked={ show_avatars }
							onChange={ ( value ) => setAttributes( { show_avatars: value } ) }
						/>
					</PanelRow>
					{ show_avatars &&
						<Fragment>
							<PanelRow>
								<AvatarSizeControl
									label={ __( 'Size', 'wordcamporg' ) }
									value={ Number( avatar_size ) }
									min={ Number( schema[ 'avatar_size' ].minimum ) }
									max={ Number( schema[ 'avatar_size' ].maximum ) }
									initialPosition={ Number( schema[ 'avatar_size' ].default ) }
									onChange={ ( value ) => setAttributes( { avatar_size: value } ) }
								/>
							</PanelRow>
						</Fragment>
					}
				</PanelBody>

				<PanelBody title={ __( 'Content Settings', 'wordcamporg' ) } initialOpen={ false }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Speaker Link', 'wordcamporg' ) }
							help={ __( "Link to a speaker's biography page", 'wordcamporg' ) }
							checked={ speaker_link }
							onChange={ ( value ) => setAttributes( { speaker_link: value } ) }
						/>
					</PanelRow>
				</PanelBody>

				<PanelBody title={ __( 'Sorting & Filtering', 'wordcamporg' ) } initialOpen={ false }>
					<PanelRow>
						<SelectControl
							label={ __( 'Sort by', 'wordcamporg' ) }
							value={ sort }
							options={ options.sort }
							onChange={ ( value ) => setAttributes( { sort: value } ) }
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
		);
	}
}

export default SpeakerInspectorControls;

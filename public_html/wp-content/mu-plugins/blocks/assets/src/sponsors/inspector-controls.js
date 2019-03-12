/**
 * WordPress dependencies.
 */
const { Component } = wp.element;
const { InspectorControls } = wp.editor;
const { PanelBody, PanelRow, ToggleControl, TextControl, Button, BaseControl } = wp.components;
const { __, _x } = wp.i18n;

/**
 * Internal dependencies
 */
import GridInspectorControl from '../shared/grid-layout/inspector-control';

const sizePresets = [
	{
		name      : __( 'Small', 'wordcamporg' ),
		size      : { height: 150, width: 150 },
		slug      : 'small',
	},
	{
		name      : __( 'Medium', 'wordcamporg' ),
		size      : { height: 320, width: 320 },
		slug      : 'regular',
	},
	{
		name      : __( 'Large', 'wordcamporg' ),
		size      : { height: 604, width: 604 },
		slug      : 'large',
	},
];

/**
 * Class for defining Inspector control in sponsor block.
 */
class SponsorInspectorControls extends Component {

	onPresetSizeClick( size ) {
		const { setAttributes } = this.props;
		setAttributes( { sponsor_logo_height: size[ 'height' ], sponsor_logo_width: size[ 'width' ] } );
	}

	/**
	 * Renders inspector controls.
	 */
	render() {

		const { attributes, setAttributes } = this.props;
		const {
			show_name, show_logo, show_desc, sponsor_logo_height, sponsor_logo_width
		} = attributes;

		const sortByOptions = [
			{ label: __( 'Name', 'wordcamporg' ), value: 'name' },
			{ label: __( 'Sponsor Level', 'wordcamporg' ), value: 'level' },
		];

		return (
			<InspectorControls>
				<GridInspectorControl
					{ ...this.props }
				/>
				<PanelBody
					title = { __( 'Content Settings', 'wordcamporg' ) }
					initialOpen = { true }
				>
					<PanelRow>
						<ToggleControl
							label = { __( 'Name', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor name', 'wordcamporg' ) }
							checked = { show_name === undefined ? true : show_name }
							onChange={ ( value ) => setAttributes( { show_name: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label = { __( 'Logo', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor logo', 'wordcamporg' ) }
							checked = { show_logo === undefined ? true : show_logo }
							onChange={ ( value ) => setAttributes( { show_logo: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label = { __( 'Description', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor description', 'wordcamporg' ) }
							checked = { show_desc === undefined ? true : show_desc }
							onChange={ ( value ) => setAttributes( { show_desc: value } ) }
						/>
					</PanelRow>
				</PanelBody>
				<PanelBody
					title = { __( 'Logo size', 'wordcamporg' ) }
					initialOpen = { true }
				>
					<PanelRow>
						<BaseControl
							help = { __( 'Specify logo height and width, or select a predefined size by pressing a size button.' ) }
						>
							<PanelRow>
									{ sizePresets.map( ( preset ) => {
										const { name, shortName, size, slug } = preset;

										return (
											<Button
												key={ slug }
												isLarge
												aria-label={ name }
												onClick={ () => this.onPresetSizeClick( size ) }
											>
												{ shortName || name }
											</Button>
										);
									} ) }
							</PanelRow>
							<PanelRow>
								<TextControl
									label = { __( 'Height (in px)', 'wordcamporg' ) }
									type = 'number'
									value = { sponsor_logo_height }
									onChange = { ( height ) => setAttributes( { sponsor_logo_height: Number( height ) } ) }
								/>
							</PanelRow>
							<PanelRow>
								<TextControl
									label = { __('Width (in px)', 'wordcamporg' ) }
									type = 'number'
									value = { sponsor_logo_width }
									onChange = { ( width ) => setAttributes( { sponsor_logo_width: Number( width ) } ) }
								/>
							</PanelRow>
						</BaseControl>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
		)
	}
}

export default SponsorInspectorControls;

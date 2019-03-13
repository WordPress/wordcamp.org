/**
 * WordPress dependencies.
 */
const { Component } = wp.element;
const { PanelBody, PanelRow, TextControl, BaseControl, SelectControl } = wp.components;
const { __ } = wp.i18n;

/**
 * Not sure if these sizes are actually useful.
 */
const sizePresets = [
	{
		label : __( 'Thumbnail', 'wordcamporg' ),
		value : '150x150',
	},
	{
		label : __( 'Small - 1:1', 'wordcamporg' ),
		value : '200x200',
	},
	{
		label : __( 'Small - 3:2', 'wordcamporg' ),
		value : '270x180',
	},
	{
		label : __( 'Small - 4:3', 'wordcamporg' ),
		value : '300x225',
	},
	{
		label : __( 'Medium - 1:1', 'wordcamporg' ),
		value : '400x400',
	},
	{
		label : __( 'Medium - 3:2', 'wordcamporg' ),
		value : '480x320',
	},
	{
		label : __( 'Medium - 4:3', 'wordcamporg' ),
		value : '480x360',
	},
	{
		label : __( 'Medium - 16:9', 'wordcamporg' ),
		value : '720x405',
	},
	{
		label : __( 'Large - 16:9', 'wordcamporg' ),
		value : '1280x720'
	},
	{
		label : __( '(Custom)', 'wordcamporg' ),
		value : '',
	}
];

/**
 * Implements inspector control for FeaturedImage component defined in ./index.js. Uses and sets attribute `featured_image_height` and `featured_image_width`.
 */
class FeaturedImageInspectorControls extends Component {

	componentWillMount() {
		this.availableSizes = sizePresets.map( (size) => size.value );
	}

	onPresetSizeSelect( size ) {
		if ( size === '' ) {
			return;
		}
		const { setAttributes } = this.props;
		const sizeFields = size.split('x');
		const width = sizeFields[0];
		const height = sizeFields[1];
		setAttributes( { featured_image_height: Number( height ), featured_image_width: Number( width ) } );
	}

	render() {
		const { attributes, setAttributes, title, help, selectLabel } = this.props;
		const {
			featured_image_height, featured_image_width
		} = attributes;
		const sizeString = featured_image_width + 'x' + featured_image_height;
		const selectedValue = this.availableSizes.indexOf( sizeString ) === -1 ? '' : sizeString;
		return (
			<PanelBody
				title = { title }
				initialopen = { false }
			>
				<PanelRow>
					<BaseControl
						help = { help }
					>
						<PanelRow>
							<SelectControl
								label={ selectLabel }
								value={ selectedValue }
								options={ sizePresets }
								onChange={ ( size ) => this.onPresetSizeSelect( size ) }
							/>
						</PanelRow>
						<PanelRow>
							<TextControl
								label = { __( 'Height (in px)', 'wordcamporg' ) }
								type = 'number'
								value = { featured_image_height }
								onChange = { ( height ) => setAttributes( { featured_image_height: Number( height ) } ) }
							/>
						</PanelRow>
						<PanelRow>
							<TextControl
								label = { __('Width (in px)', 'wordcamporg' ) }
								type = 'number'
								value = { featured_image_width }
								onChange = { ( width ) => setAttributes( { featured_image_width: Number( width ) } ) }
							/>
						</PanelRow>
					</BaseControl>
				</PanelRow>
			</PanelBody>
		);
	}
}

export default FeaturedImageInspectorControls;

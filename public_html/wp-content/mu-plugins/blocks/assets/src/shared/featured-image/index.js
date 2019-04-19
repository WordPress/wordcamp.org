/**
 * External dependencies
 */
import classnames from 'classnames';
const { isValidElement } = React;

/**
 * WordPress dependencies.
 */
const { Dashicon, Disabled } = wp.components;
const { Component } = wp.element;
const { __ } = wp.i18n;
const { isURL } = wp.url;

/**
 * Displays featured image, can be linked with block control for size.
 */
export default class FeaturedImage extends Component {
	/**
	 * @param {Object} props
	 * @param {Array}  props.wpMediaDetails Available sizes of images in the format as returned by WP API. This is the `sizes` object inside `media_details` inside `wp:featuredMedia` object.
	 * @param {number} props.width          Width in pixels for image.
	 * @param {string} props.className      Class name for image element
	 * @param {string} props.alt            Alt text for image
	 * @param {string} props.imageLink      URL for wrapping the image in an anchor tag
	 * @param {string} props.fallback       An element to use if no image is available
	 */
	constructor( props ) {
		super( props );

		this.state = {};

		this.renderFallback = this.renderFallback.bind( this );
	}

	/**
	 * Get 'full' size image to be displayed in editor. Or get the widest one.
	 *
	 * @return {Object}
	 */
	getFullImage() {
		const { getOwnPropertyDescriptors } = Object;
		const availableSizes = this.props.wpMediaDetails;

		const { selectedImage } = this.state;

		if ( selectedImage && selectedImage.hasOwnProperty( 'source_url' ) ) {
			return selectedImage;
		}

		if ( availableSizes.hasOwnProperty( 'full' ) && availableSizes.full.hasOwnProperty( 'source_url' ) ) {
			this.setState( { selectedImage: availableSizes.full } );
			return availableSizes.full;
		}

		let widestImage = { source_url: '' };

		for ( const size in getOwnPropertyDescriptors( availableSizes ) ) {
			if ( availableSizes[ size ].width > ( widestImage.width || 0 ) && availableSizes[ size ].hasOwnProperty( 'source_url' ) ) {
				widestImage = availableSizes[ size ];
			}
		}

		this.setState( { selectedImage: widestImage } );

		return widestImage;
	}

	/**
	 * Renders FeaturedImage component.
	 *
	 * @return {Element}
	 */
	render() {
		const { className, alt, width, imageLink } = this.props;
		const { source_url: src } = this.getFullImage();

		if ( ! src ) {
			return this.renderFallback();
		}

		let image = (
			<img
				className="wordcamp-featured-image"
				src={ src }
				alt={ alt }
				width={ width + 'px' }
			/>
		);

		if ( isURL( imageLink ) ) {
			image = (
				<Disabled>
					<a href={ imageLink } className={ classnames( 'wordcamp-image-link', 'wordcamp-featured-image-link' ) }>
						{ image }
					</a>
				</Disabled>
			);
		}

		image = (
			<div className={ classnames( 'wordcamp-image-container', 'wordcamp-featured-image-container', className ) }>
				{ image }
			</div>
		);

		return image;
	}

	/**
	 * Render a fallback element when no featured image is available.
	 *
	 * @return {Element}
	 */
	renderFallback() {
		const { className, width, imageLink, fallbackIcon, fallbackElement } = this.props;

		if ( isValidElement( fallbackElement ) ) {
			return fallbackElement;
		}

		let output = '';

		if ( fallbackIcon ) {
			output = (
				<FeaturedImageFallback
					className={ className }
					icon={ fallbackIcon }
					width={ width }
					link={ imageLink }
				/>
			);
		}

		return output;
	}
}

function FeaturedImageFallback( { className, icon, width, link } ) {
	const containerStyle = {
		maxWidth: width,
	};
	let fallback;

	fallback = (
		<Dashicon
			className="wordcamp-featured-image-fallback-icon"
			icon={ icon }
			size={ Number( width ) * 0.65 }
		/>
	);

	if ( link ) {
		fallback = (
			<Disabled>
				<a href={ link } className={ classnames( 'wordcamp-featured-image-link', 'wordcamp-featured-image-fallback-link' ) }>
					{ fallback }
				</a>
			</Disabled>
		);
	}

	return (
		<div
			className={ classnames( 'wordcamp-featured-image-fallback-container', className ) }
			style={ containerStyle }
		>
			<div className="wordcamp-featured-image-fallback-container-inner">
				{ fallback }
			</div>
		</div>
	);
}

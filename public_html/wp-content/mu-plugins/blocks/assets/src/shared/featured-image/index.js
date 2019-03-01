/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
const { Component, Fragment, RawHTML } = wp.element;

/**
 * Displays featured image, can be linked with block control for size. Fetches the smallest possible image size required to render the current size.
 */
export default class FeaturedImage extends Component {

	/**
	 * Constructor for component. Size can be provided, if height and width both are provided, then they take precedence over size.
	 *
	 * @param props Props for function.
	 * @param props.availableSizes Available sizes of image along with source URL. Should be in this format:
	 * [
	 * 		{
	 * 		 	height: 999,
	 * 		 	width: 999,
	 * 		 	source_url: https://link.to/image-size-1.png
	 * 		},
	 * 		{
	 * 			height: 500,
	 * 			width: 500,
	 * 			source_url: https://link.to/image-size-2.png
	 * 		}
	 * 		...
	 * ]
	 * @param props.size Size of image. Supported values are "icon, thumbnail, s, m, l, xl, max".
	 * @param props.height Height in pixels for image.
	 * @param props.width Width in pixels for image. If both height and width are provided then they take precedence over `size`.
	 * @param props.className Classname for image element
	 * @param props.url URL of image
	 * @param props.alt Alt text for image
	 */
	constructor( props ) {
		super( props );
		this.state = {};
		this.parseAvailableSizes();
	}

	getSizeChart() {
		return {
				'icon'      : { height: 20, width: 20 },
				'thumbnail' : { height: 150, width: 150 },
				's'         : { height: 44, width: 150 },
				'm'         : { height: 87, width: 300 },
				'l'         : { height: 296, width: 1024
			},
		}
	}

	/**
	 * Pre-calculate and store aspect ration of all images. This will help us in picking the best image available for the size that we have.
	 */
	parseAvailableSizes() {
		const { availableSizes } = this.props;

		let largestSizeValue = 0;
		let largestSize = {};
		// Lets pre-calculate aspect ratio of all images. Also cache the largest area image.
		const sizes = availableSizes.map( ( size ) => {
			return {
				aspectRatio: size.width / size.height,
				area: size.width * size.height,
				...size
			}
		} );

		// Lets sort the available images based on area and then aspect ratio.
		sizes.sort( (size1, size2) => {
				if ( size1.aspectRatio < size2.aspectRatio ) {
					return -1;
				} else if ( sizearea1.aspectRatio === size2.aspectRatio ) {
					return size1.area < size2.area ? -1 : 1;
				} else {
					return 1;
				}
			}
		);

		this.setState(
			{
				sizes: sizes,
				...this.state
			}
		);

		console.log("Sizes : ", sizes );
	}

	/**
	 * Returns URL of appropriate image. We calculate appropriate by looking for an image with similar aspect ratio, and similar or greater area.
	 * If there is no image with similar aspect ratio or area, we will return strictly bigger image then currently requested.
	 * If there is no bigger image then currently request one, we will return the max size image URL.
	 * Note: Core Image block allows user to select image size by name, but we cannot really do that because we will be controlling multiple images at once, and one or more images may not have all the available sizes.
	 *
	 * @param height
	 * @param width
	 */
	getSizedUrl( height, width ) {
		const { sizes } = this.state;
		// Reject all the images with less height or width then 20px.
		const possibleImages = sizes.filter( ( size ) => {
				if ( size.height < height - 20 || size.width < width - 20 ) {
					return false;
				}
			}
		);

		// All images are small, lets just return the largest image url.
		if ( ! possibleImages.length ) {
			return sizes[ sizes.length - 1 ].source_url;
		}

		// Lets loop through all available images, keep track of the aspect
	}

	render() {
		const { className, alt } = this.props;
		return(
			<img
				className={ classnames( 'featured-image', className ) }
				src = { this.getSizedUrl() }
				alt = { alt }
			/>
		)
	}
}

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
	 * @oaram props.wpMediaDetails Available sizes of images in the format as returned by WP API. This is the `sizes` object inside `media_details` inside `wp:featuredMedia` object. Not required if `props.availableSizes is present.
	 * @param props.availableSizes Available sizes of image along with source URL. Not required if `props.wpMediaDetails` is present. Should be in this format:
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
	 * @param props.height Height in pixels for image.
	 * @param props.width Width in pixels for image.
	 * @param props.className Classname for image element
	 * @param props.alt Alt text for image
	 * @param props.onChange Function callback for when Image URL is changed. Current URL will be passed as a parameter.
	 */
	constructor( props ) {
		super( props );
	}

	componentWillMount() {
		let availableSizes;
		const { wpMediaDetails } = this.props;
		if ( wpMediaDetails ) {
			availableSizes = FeaturedImage.parseWPMediaDetails( wpMediaDetails );
		} else {
			availableSizes = this.props.availableSizes;
		}
		this.parseAvailableSizes( availableSizes );
	}

	/**
	 * Converts from WP API featured_media object to array of different sizes.
	 *
	 * @param wpMediaDetails
	 */
	static parseWPMediaDetails( wpMediaDetails ) {
		const availableSizes = [];
		for ( const size of Object.keys( wpMediaDetails ) ) {
			if ( ! wpMediaDetails.hasOwnProperty( size  ) ) {
				continue;
			}

			availableSizes.push( wpMediaDetails[ size ] );
		}
		return availableSizes;
	}

	/**
	 * Pre-calculate and store aspect ratio of all images. This will help us in picking the best image available for the size that we have.
	 */
	parseAvailableSizes( availableSizes ) {
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
		sizes.sort( ( size1, size2 ) => {
				if ( size1.area < size2.area ) {
					return -1;
				} else if ( size1.area === size2.area ) {
					return size1.aspectRatio < size2.aspectRatio ? -1 : 1;
				} else {
					return 1;
				}
			}
		);

		this.setState(
			{
				sizes,
				...this.state
			}
		);
	}


	/**
	 * Returns URL of appropriate image. We find appropriate by looking for an image with similar aspect ratio, and similar or greater area.
	 * If there is no image with similar aspect ratio or area, we will return strictly bigger image then currently requested.
	 * If there is no bigger image then currently request one, we will return the max size image URL.
	 * Note: Core Image block allows user to select image size by name, but we cannot really do that because we will be controlling multiple images at once, and one or more images may not have all the available sizes. So an automated solution is preferred here.
	 *
	 * @param height
	 * @param width
	 */
	getSizedUrl( height, width ) {
		const { sizes } = this.state;
		if ( ! sizes ) {
			return "";
		}

		// Lets loop through all possible images, see if we have an image that matches requested aspect ration. if we have, we will return it. Also keep track of image which has the most closest aspect ration.
		let smallestImage;
		let selectedImage;
		const requiredAspectRatio = width / height;

		for ( const size of sizes ) {

			// Reject all the images with less height or width then 20px.
			if (size.height < height - 20 || size.width < width - 20) {
				continue;
			}

			// Keep track of smallest image that is similar or bigger than our requirements. In case we won't find any image with similar aspect ratio, we will use this.
			// Since images are sorted by size, this will be the first image that is not rejected by the size filter above.
			if ( ! smallestImage ) {
				smallestImage = size;
			}

			if (size.aspectRatio > requiredAspectRatio - 0.25 &&
				size.aspectRatio < requiredAspectRatio + 0.25) {
				// This image has similar aspect ratio to what we need. Also, since possibleImage array is sorted from lowest size, this is also the lowest size image that we can use. Lets go ahead and use this image.
				selectedImage = size;
				break;
			}
		}

		if ( selectedImage ) {
			return selectedImage.source_url;
		} else if ( smallestImage ) {
			return smallestImage.source_url;
		} else {
			// All images are small, lets just return the largest image url.
			return sizes[ sizes.length - 1 ].source_url;
		}

	}

	/**
	 * Renders FeaturedImage component.
	 *
	 * @returns {*}
	 */
	render() {
		const { className, alt, onChange } = this.props;

		const imageSize = this.props.size || { height: 150, width: 150 };

		const { height, width } = imageSize;

		const imageURL = this.getSizedUrl( height, width );
		if ( imageURL !== this.currentImageURL ) {
			this.currentImageURL = imageURL;
			if ( typeof( onChange ) === 'function' ) {
				onChange( imageURL );
			}
		}
		return(
			<img
				className={ classnames( 'featured-image', className ) }
				src = { imageURL }
				alt = { alt }
				style = { { height: height + "px"} }
				width={ width + 'px' }
			/>
		)
	}
}

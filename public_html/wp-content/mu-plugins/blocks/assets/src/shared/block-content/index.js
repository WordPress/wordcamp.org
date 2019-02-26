/**
 * External dependencies
 */
import { get } from 'lodash';
import classnames from 'classnames';


/**
 * WordPress dependencies
 */
const { __, _x, sprintf } = wp.i18n;
const { decodeEntities } = wp.htmlEntities;


export function arrayToHumanReadableList( array ) {
	if ( ! Array.isArray( array ) ) {
		return '';
	}

	const count = array.length;
	let list = '';

	switch ( count ) {
		case 0:
			break;
		case 1:
			[ list ] = array;
			break;
		case 2:
			const [ first, second ] = array;
			list = sprintf(
				/* translators: Each %s is a person's name. */
				_x( '%1$s and %2$s', 'list of two items', 'wordcamporg' ),
				first,
				second
			);
			break;
		default:
			/* translators: used between list items, there is a space after the comma */
			const item_separator = __( ', ', 'wordcamporg' );
			let [ last, ...initial ] = [ ...array ].reverse();

			initial = initial.join( item_separator ) + item_separator;

			list = sprintf(
				/* translators: 1: A list of items. 2: The last item in a list of items. */
				_x( '%1$s and %2$s', 'list of three or more items', 'wordcamporg' ),
				initial,
				last
			);
			break;
	}

	return list;
}

/**
 * Renderes a features image for a post.
 *
 * @param post
 * @param imageClass
 * @param defaultImageClass
 * @returns {*}
 * @constructor
 */
export function WCPTFeaturedImage( { post, imageClass, defaultImageClass } ) {
	let image;

	const url = get( post, '_embedded[\'wp:featuredmedia\'][0].media_details.sizes.thumbnail.source_url', '' );

	if ( url ) {
		image = (
			<img
				src={ url }
				alt={ decodeEntities( post.title.rendered.trim() ) }
				className={ classnames( imageClass ) }
			/>
		);
	} else {
		image = (
			<div className={defaultImageClass}/>
		);
	}

	return image;

}
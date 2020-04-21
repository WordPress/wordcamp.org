/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs, isURL } from '@wordpress/url';

// Avatar-specific presets for the ImageSizeControl component.
export const avatarSizePresets = [
	{
		name: __( 'Small', 'wordcamporg' ),
		shortName: _x( 'S', 'size small', 'wordcamporg' ),
		size: 90,
		slug: 'small',
	},
	{
		name: __( 'Regular', 'wordcamporg' ),
		shortName: _x( 'M', 'size medium', 'wordcamporg' ),
		size: 150,
		slug: 'regular',
	},
	{
		name: __( 'Large', 'wordcamporg' ),
		shortName: _x( 'L', 'size large', 'wordcamporg' ),
		size: 300,
		slug: 'large',
	},
	{
		name: __( 'Larger', 'wordcamporg' ),
		shortName: _x( 'XL', 'size extra large', 'wordcamporg' ),
		size: 500,
		slug: 'larger',
	},
];

/**
 * Component for an avatar image, optionally including a link.
 *
 * This tries to mirror the markup output by WP's get_avatar function, with the addition
 * of an optional wrapping link and a container div.
 *
 * @param {Object} props {
 *     @type {string} className
 *     @type {string} name
 *     @type {number} size
 *     @type {string} url
 *     @type {string} imageLink
 * }
 *
 * @return {Element}
 */
export function AvatarImage( { className, name, size, url, imageLink } ) {
	const getSizedURL = ( avatar_url, avatar_size ) => {
		// 's' is the name of the parameter used by Gravatar.
		// eslint-disable-next-line id-length
		return addQueryArgs( avatar_url, { s: avatar_size } );
	};

	/* translators: %s is the person's name. */
	const alt = name ? sprintf( __( 'Avatar of %s', 'wordcamporg' ), name ) : '';

	let image = (
		<img
			className={ classnames( 'avatar', 'avatar-' + size, 'photo' ) }
			src={ getSizedURL( url, size ) }
			srcSet={ getSizedURL( url, size * 2 ) + ' 2x' }
			alt={ alt }
			width={ size }
			height={ size }
		/>
	);

	if ( isURL( imageLink ) ) {
		image = (
			<a
				href={ imageLink }
				className="wordcamp-image__avatar-link"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ image }
			</a>
		);
	}

	image = (
		<div className={ classnames( 'wordcamp-image__avatar-container', className ) }>
			{ image }
		</div>
	);

	return image;
}

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { addQueryArgs } = wp.url;

function AvatarImage( {
	alt,
	className,
	size,
	url,
} ) {
	const getSizedURL = ( avatar_url, avatar_size ) => {
		return addQueryArgs( avatar_url, { s: avatar_size } );
	};

	return (
		<img
			className={ classnames( 'avatar', 'avatar-' + size, 'photo', className ) }
			src={ getSizedURL( url, size ) }
			srcSet={ getSizedURL( url, size * 2 ) + ' 2x' }
			alt={ alt }
			width={ size }
			height={ size }
		/>
	);
}

export default AvatarImage;

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { __, sprintf } = wp.i18n;
const { addQueryArgs } = wp.url;

function AvatarImage( {
	className,
	name,
	size,
	url,
	// this feels like a weird way to declare default arguments, mostly from a readability standpoint. i wonder if there's a better way, but i'm not really sure.
	// i don't think i've seen it done this way in gutenberg, but i could be wrong
	// maybe this should extend Component so it could simply accept a `props` object and then have a `defaultProps` property?
		// or is this some es6 way of picking specific things out of the props that are already being passed in?
	// or maybe have a statement where it merges the passed props with the defined defaults or something?
	// or maybe just put it all on 1 line?
	// if we change it here, we should change all the other instances as well
} ) {
	const getSizedURL = ( avatar_url, avatar_size ) => {
		return addQueryArgs( avatar_url, { s: avatar_size } );
	};

	const alt = name ? sprintf( __( 'Avatar of %s', 'wordcamporg' ), name ) : '';

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

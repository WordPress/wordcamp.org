/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import getCardFrameHeight from './get-card-frame-height';
import useInView from './in-view';
import ScreenShot from './screenshot';

/**
 *
 * @param {Object} props
 * @param {string} props.link           Url for anchor tag.
 * @param {string} props.previewLink    Url used for the screenshot preview.
 * @param {string} props.caption        Text for screen readers.
 * @param {string} props.height         Initial height for the preview, include unit.
 * @param {string} props.width          Desired with of the preview element, include unit.
 * @param {number} props.aspectRatio    Aspect ratio for the preview element.
 * @param {string} props.queryString    Arguments passed to screenshot service.
 * @param {Object} props.anchorTagProps HTMLAnchorElement attributes to be added to the block anchor tag.
 *
 * @return {Object} React element
 */
function Block( {
	link,
	previewLink,
	caption,
	height = '1px',
	width = '100%',
	aspectRatio = 2 / 3,
	queryString = '?vpw=1200&vph=800',
	anchorTagProps = {},
} ) {
	const wrapperRef = useRef();
	const [ frameHeight, setFrameHeight ] = useState( height );
	const isVisible = useInView( { element: wrapperRef } );
	const [ shouldLoad, setShouldLoad ] = useState( false );

	useEffect( () => {
		const handleOnResize = () => {
			try {
				setFrameHeight( getCardFrameHeight( wrapperRef.current.clientWidth, aspectRatio ) );
			} catch ( err ) {}
		};

		handleOnResize();

		window.addEventListener( 'resize', handleOnResize );

		return () => {
			window.removeEventListener( 'resize', handleOnResize );
		};
	}, [ shouldLoad ] );

	useEffect( () => {
		if ( isVisible ) {
			setShouldLoad( true );
		}
	}, [ isVisible ] );

	return (
		<a
			className="wporg-screenshot-card"
			ref={ wrapperRef }
			style={ {
				height: frameHeight,
				width: width,
			} }
			href={ link }
			{ ...anchorTagProps }
		>
			<ScreenShot queryString={ queryString } src={ previewLink } isReady={ shouldLoad } alt={ caption } />
		</a>
	);
}

export default Block;

/**
 * Embed the chat from a YouTube Live video.
 *
 * Visit `https://www.youtube.com/channel/UC4R8DWoMoI7CAwX8_LjQHig` for live videos to use for testing.
 * `https://www.youtube.com/watch?v=DWcJFNfaw9c` is nice both for the music and the fact that it's been running
 * for months. If that's no longer active, check `https://www.youtube.com/channel/UCSJ4gkVC6NrvII8umztf0Ow`
 * for others. All of them have regular chat activity, but some of the more popular ones have so much that it's
 * annoying/distracting. Scrolling up inside the frame is also a nice way to stop it from constantly moving.
 *
 * Maybe refactor this if https://github.com/WordPress/gutenberg/issues/13490 is resolved.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { Path, SVG } from '@wordpress/components';

/**
 * Internal dependencies
 */
import PreviewPlaceholder from './components/preview-placeholder';
import DeselectedIframeOverlay from './components/deselected-iframe-overlay';

export const name = 'wordcamp/youtube-live-chat-embed';

/*
 * This is copied from `gutenberg/packages/block-library/src/embed/icons.js`, because the icon isn't available in
 * Dashicons or @wordpress/icons.
 *
 * This can probably be removed when https://github.com/WordPress/gutenberg/issues/21538 is resolved.
 */
export const embedYouTubeIcon = {
	foreground: '#ff0000',
	src: (
		<SVG viewBox="0 0 24 24">
			<Path d="M21.8 8s-.195-1.377-.795-1.984c-.76-.797-1.613-.8-2.004-.847-2.798-.203-6.996-.203-6.996-.203h-.01s-4.197 0-6.996.202c-.39.046-1.242.05-2.003.846C2.395 6.623 2.2 8 2.2 8S2 9.62 2 11.24v1.517c0 1.618.2 3.237.2 3.237s.195 1.378.795 1.985c.76.797 1.76.77 2.205.855 1.6.153 6.8.2 6.8.2s4.203-.005 7-.208c.392-.047 1.244-.05 2.005-.847.6-.607.795-1.985.795-1.985s.2-1.618.2-3.237v-1.517C22 9.62 21.8 8 21.8 8zM9.935 14.595v-5.62l5.403 2.82-5.403 2.8z" />
		</SVG>
	),
};

export const settings = {
	title: __( 'YouTube Live Chat', 'wordcamporg' ),
	icon: embedYouTubeIcon,
	category: 'embed',

	attributes: {
		videoUrl: {
			type: 'string',
			default: '',
		},
	},

	supports: {
		align: [ 'wide', 'full' ],
	},

	edit: Edit,

	// Block is rendered dynamically to inject the iframe, no need to save anything.
	save: () => null,
};

/**
 * Render the block for the editor.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 * @param {string}   props.className
 * @param {boolean}  props.isSelected
 * @return {Element}
 */
function Edit( { attributes, setAttributes, className, isSelected } ) {
	const { videoUrl, align } = attributes;
	let classes = className;

	if ( align ) {
		classes += ' align' + align;
	}

	if ( ! videoUrl ) {
		return (
			<PreviewPlaceholder
				classes={ classes }
				icon={ embedYouTubeIcon }
				label={ __( 'YouTube Live Chat', 'wordcamporg' ) }
				instructions={ __( 'Enter a YouTube.com live video URL to embed its chat on your site.', 'wordcamporg' ) }
				placeholder="https://www.youtube.com/watch?v=pXPtCVMDzBA" // Thabo Tswana at WCEU 2017
				embedHandler={ ( newUrl ) => setAttributes( { videoUrl: newUrl } ) }
			/>
		);
	}

	try {
		const videoId = getVideoId( videoUrl );

		return <Preview classes={ classes } videoId={ videoId } isSelected={ isSelected } />;
	} catch ( error ) {
		return <InvalidUrl error={ error } />;
	}
}

/**
 * Render an error screen when the user enters an invalid YouTube video URL.
 *
 * @param {Object} props
 * @param {*}      props.error
 * @return {Element}
 */
function InvalidUrl( { error } ) {
	// eslint-disable-next-line no-console
	console.log( 'YouTube Live Chat error:', error ); // So that users can give us details necessary for debugging.

	return (
		<div className="notice notice-error">
			<p>
				{ __( "This block couldn't be displayed because the video URL is not valid.", 'wordcamporg' ) }
			</p>

			<p>
				{
					createInterpolateElement(
						__( 'Please delete this, and create new a block with valid a URL. The URL must be in a form similar to <code>https://www.youtube.com/watch?v=pXPtCVMDzBA</code>.', 'wordcamporg' ),
						{
							code: <code />,
						}
					)
				}
			</p>
		</div>
	);
}

/**
 * Extract a YouTube video ID from a URL.
 *
 * In the future, this could be enhanced by using the YouTube API to detect if the video ID is valid, and if the
 * chat is currently active. We can't currently detect the "Invalid parameters" and "Chat is disabled..." errors
 * because the same-origin-policy blocks access to the iframe's content. To prevent poor UX, that could be done in
 * the background, and saved, so that it only has to happen once.
 *
 * Another possibility could be using `postMessage()`, but it's not clear if that works for the chat `iframe`, or
 * only for the video player `iframe`. See https://developers.google.com/youtube/iframe_api_reference?csw=1.
 *
 * @param {string} url
 * @return {string}
 */
function getVideoId( url ) {
	const parsedUrl = new URL( url );
	const videoId = parsedUrl.searchParams.get( 'v' );

	if ( ! videoId ) {
		throw 'URL does not contain a video ID.';
	}

	return videoId;
}

/**
 * Show a preview of the block's front end output.
 *
 * @param {Object}  props
 * @param {string}  props.classes
 * @param {string}  props.videoId
 * @param {boolean} props.isSelected
 * @return {Element}
 */
function Preview( { classes, videoId, isSelected } ) {
	const embedDomain = window.location.hostname;
	const iframeSrc = `https://www.youtube.com/live_chat?v=${ videoId }&embed_domain=${ embedDomain }`;

	classes += ' is-preview';

	return (
		<>
			<DeselectedIframeOverlay isSelected={ isSelected } />

			<div className={ classes }>
				<iframe
					id={ 'wp-block-wordcamp-youtube-live-chat-embed__video-' + videoId }
					title="Embedded YouTube live chat"
					src={ iframeSrc }
					sandbox="allow-same-origin allow-scripts allow-popups"
				>
				</iframe>
			</div>
		</>
	);
}

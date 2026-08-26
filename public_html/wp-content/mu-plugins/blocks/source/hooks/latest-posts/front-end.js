/**
 * WordPress dependencies
 */
import { addQueryArgs } from '@wordpress/url';

const SELECTOR = '.wp-block-latest-posts.has-live-update';
const REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes in milliseconds.
const MAX_FAILURES = 3;

/**
 * The endpoint that renders this block, localised by the controller.
 *
 * @return {string} The endpoint, or an empty string when the data is missing.
 */
function rendererUrl() {
	const data = window.WordCampBlocks || {};

	return ( data[ 'latest-posts' ] || {} ).renderer || '';
}

/**
 * Read the block's saved attributes off the container.
 *
 * @param {Element} element Container element.
 * @return {Object|null} The attributes, or null when they cannot be read.
 */
export function readAttributes( element ) {
	const encoded = element.dataset.attributes;

	if ( ! encoded ) {
		return null;
	}

	try {
		const parsed = JSON.parse( decodeURIComponent( encoded ) );

		// Anything but a plain object would ask the route for the block's defaults.
		return parsed && 'object' === typeof parsed && ! Array.isArray( parsed )
			? parsed
			: null;
	} catch {
		return null;
	}
}

/**
 * Take the list items out of a rendered response.
 *
 * The response has been through the `render_block` filter too, so it arrives wearing a
 * container of its own. The container already on the page is the one carrying the
 * block's classes, so only its contents are replaced.
 *
 * @param {string} html Rendered block markup.
 * @return {string} The container's contents, or the markup unchanged if it has none.
 */
export function unwrapContainer( html ) {
	const template = document.createElement( 'template' );
	template.innerHTML = html.trim();

	const container = template.content.querySelector(
		'.wp-block-latest-posts'
	);

	return container ? container.innerHTML : html;
}

/**
 * Keep one container's list up to date.
 *
 * The markup is written straight into the container, so a refreshed list has the same
 * shape as the one the server rendered and any styling written against that markup
 * still applies. A failed request leaves the page alone.
 *
 * @param {Element} element Container element.
 */
function start( element ) {
	const attributes = readAttributes( element );

	element.classList.remove( 'is-loading' );

	/*
	 * Without the saved attributes a refresh would ask for the block's default
	 * settings, replacing a correct list with a wrong one. Leave the page's own.
	 */
	if ( ! attributes ) {
		return;
	}

	let warned = false;
	let failures = 0;
	let lastContent = null;

	/**
	 * Report the first failure, so a list that stops updating can be diagnosed.
	 *
	 * @param {string} reason What went wrong.
	 */
	function warn( reason ) {
		if ( ! warned ) {
			warned = true;
			// eslint-disable-next-line no-console
			console.warn( 'Latest Posts live update failed:', reason );
		}
	}

	function refresh() {
		// The container can be taken off the page by something else on the site. Stop
		// rather than keep polling into a node nobody can see.
		if ( ! element.isConnected ) {
			clearInterval( timer );
			return;
		}

		const endpoint = rendererUrl();

		if ( ! endpoint ) {
			// A page cached before the deploy carries the old inline data with no
			// endpoint in it. That will not change while this page is open.
			warn( 'the renderer endpoint is missing' );
			clearInterval( timer );
			return;
		}

		/*
		 * Deliberately not `apiFetch`: it attaches an `X-WP-Nonce`, which expires for a
		 * logged-out visitor, and an expired nonce is refused before the request is
		 * routed. Sending none leaves the request anonymous, which is what this needs.
		 */
		window
			.fetch( addQueryArgs( endpoint, { context: 'edit', attributes } ) )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( `HTTP ${ response.status }` );
				}

				return response.json();
			} )
			.then( ( body ) => {
				// The route answered, so whatever went wrong before has passed.
				failures = 0;

				if ( ! body || ! body.rendered ) {
					return;
				}

				const content = unwrapContainer( body.rendered );

				/*
				 * Rewriting the list rebuilds every node in it, which drops focus and
				 * any selection inside it and restarts the images. Most polls return
				 * what is already on screen, so only write when something changed.
				 * The page's own copy cannot seed this, since the markup it parsed
				 * from is not byte-identical to the response, so the first poll always
				 * writes and the saving starts from the second.
				 */
				if ( content === lastContent ) {
					return;
				}

				lastContent = content;
				element.innerHTML = content;
			} )
			.catch( ( error ) => {
				warn( error.message );

				/*
				 * A refusal usually repeats: a page cached before the deploy sends
				 * attributes the route no longer accepts, and will until it expires.
				 * Give the network a couple of chances, then stop asking.
				 */
				failures++;

				if ( failures >= MAX_FAILURES ) {
					clearInterval( timer );
				}
			} );
	}

	// `refresh` closes over this; it only ever runs once the interval exists.
	const timer = setInterval( refresh, REFRESH_INTERVAL );
}

document.querySelectorAll( SELECTOR ).forEach( start );

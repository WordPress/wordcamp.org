/**
 * Event flyer QR code: draws the code from the block's `data-url`.
 *
 * `uqr` only encodes; the SVG is built here with DOM calls rather than taken
 * from its `renderSVG()` string, so nothing is assigned to `innerHTML`. The
 * code is one `<path>` of unit squares on a viewBox the size of the module
 * grid, which keeps it sharp at any print size.
 */

import { encode } from 'uqr';

const SVG_NS = 'http://www.w3.org/2000/svg';

/*
 * The four-module quiet zone the QR specification asks for. `M` error
 * correction survives a crease or a smudge on a pinned-up sheet without
 * making the code much denser than `L` for a URL this short.
 */
const QUIET_ZONE = 4;
const ERROR_CORRECTION = 'M';

/**
 * Builds the SVG for one URL.
 *
 * @param {Document} doc Document to create the elements in.
 * @param {string}   url The URL to encode.
 * @return {SVGSVGElement} The QR code.
 */
export function buildSvg( doc, url ) {
	const { data, size } = encode( url, { ecc: ERROR_CORRECTION, border: QUIET_ZONE } );
	const commands = [];

	data.forEach( ( row, top ) => {
		row.forEach( ( isDark, left ) => {
			if ( isDark ) {
				commands.push( `M${ left } ${ top }h1v1h-1z` );
			}
		} );
	} );

	const svg = doc.createElementNS( SVG_NS, 'svg' );
	svg.setAttribute( 'viewBox', `0 0 ${ size } ${ size }` );
	svg.setAttribute( 'shape-rendering', 'crispEdges' );
	svg.setAttribute( 'focusable', 'false' );

	const background = doc.createElementNS( SVG_NS, 'rect' );
	background.setAttribute( 'width', String( size ) );
	background.setAttribute( 'height', String( size ) );
	background.setAttribute( 'fill', '#fff' );

	const modules = doc.createElementNS( SVG_NS, 'path' );
	modules.setAttribute( 'd', commands.join( '' ) );
	modules.setAttribute( 'fill', '#000' );

	svg.append( background, modules );

	return svg;
}

/**
 * Draws every flyer QR code under `root` that hasn't been drawn yet.
 *
 * @param {Document|Element} root Where to look.
 */
export function init( root = document ) {
	root.querySelectorAll( '.wporg-event-flyer-qr[data-url]' ).forEach( ( figure ) => {
		const target = figure.querySelector( '.wporg-event-flyer-qr__code' );

		if ( ! target || target.firstChild ) {
			return;
		}

		target.append( buildSvg( figure.ownerDocument, figure.dataset.url ) );
	} );
}

init();

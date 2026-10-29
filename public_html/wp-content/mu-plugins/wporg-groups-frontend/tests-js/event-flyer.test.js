import { encode } from 'uqr';
import { buildSvg, init as drawCodes } from '../src/blocks/event-flyer-qr/view';
import { init as wirePrintButtons } from '../src/blocks/event-flyer-link/view';
import { init as wireCopyButtons } from '../src/blocks/event-short-link/view';

const EVENT_URL = 'https://events.wordpress.test/example/event/meetup/20261010/';

function flyerMarkup( url = EVENT_URL ) {
	return `<figure class="wporg-event-flyer-qr" data-url="${ url }">
		<div class="wporg-event-flyer-qr__code" aria-hidden="true"></div>
		<figcaption>example/event/meetup/20261010</figcaption>
	</figure>`;
}

describe( 'event flyer QR code', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'draws one dark square per dark module of the encoded URL', () => {
		const svg = buildSvg( document, EVENT_URL );
		const { data, size } = encode( EVENT_URL, { ecc: 'M', border: 4 } );
		const darkModules = data.flat().filter( Boolean ).length;
		const drawn = svg.querySelector( 'path' ).getAttribute( 'd' ).match( /M/g ).length;

		expect( svg.getAttribute( 'viewBox' ) ).toBe( `0 0 ${ size } ${ size }` );
		expect( drawn ).toBe( darkModules );
	} );

	it( 'draws the code into each flyer figure, from its own data-url', () => {
		document.body.innerHTML = flyerMarkup();

		drawCodes();

		const target = document.querySelector( '.wporg-event-flyer-qr__code' );
		const expected = buildSvg( document, EVENT_URL ).querySelector( 'path' ).getAttribute( 'd' );

		expect( target.querySelectorAll( 'svg' ) ).toHaveLength( 1 );
		expect( target.querySelector( 'path' ).getAttribute( 'd' ) ).toBe( expected );
	} );

	it( 'does not draw a second code when run again', () => {
		document.body.innerHTML = flyerMarkup();

		drawCodes();
		drawCodes();

		expect( document.querySelectorAll( '.wporg-event-flyer-qr__code svg' ) ).toHaveLength( 1 );
	} );

	it( 'never puts the URL into the markup as HTML', () => {
		const hostile = 'https://example.test/?q="><img src=x onerror=alert(1)>';
		const svg = buildSvg( document, hostile );

		expect( svg.querySelector( 'img' ) ).toBeNull();
		expect( svg.outerHTML ).not.toContain( 'onerror' );
	} );
} );

describe( 'event flyer print button', () => {
	beforeEach( () => {
		document.body.innerHTML = `<nav class="wporg-event-flyer-link is-flyer-toolbar">
			<a href="${ EVENT_URL }">Back to event</a>
			<button type="button" class="wporg-event-flyer-link__print" hidden>Print flyer</button>
		</nav>`;
		window.print = jest.fn();
	} );

	it( 'is revealed once it can print', () => {
		wirePrintButtons();

		expect( document.querySelector( 'button' ).hidden ).toBe( false );
	} );

	it( 'opens the print dialog', () => {
		wirePrintButtons();
		document.querySelector( 'button' ).click();

		expect( window.print ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'event short link copy button', () => {
	const SHORT_URL = 'https://events.wordpress.test/example/e/12/';

	beforeEach( () => {
		document.body.innerHTML = `<div class="wporg-event-short-link" data-copied="Link copied." data-failed="Couldn't copy.">
			<a href="${ SHORT_URL }">events.wordpress.test/example/e/12/</a>
			<button type="button" class="wporg-event-short-link__copy" data-url="${ SHORT_URL }" hidden>Copy</button>
			<span class="wporg-event-short-link__status" role="status"></span>
		</div>`;
	} );

	afterEach( () => {
		delete window.navigator.clipboard;
	} );

	function mockClipboard( writeText ) {
		Object.defineProperty( window.navigator, 'clipboard', { value: { writeText }, configurable: true } );
	}

	it( 'stays hidden without a clipboard to copy to', () => {
		wireCopyButtons();

		expect( document.querySelector( 'button' ).hidden ).toBe( true );
	} );

	it( 'copies the link and says so', async () => {
		const writeText = jest.fn().mockResolvedValue();
		mockClipboard( writeText );

		wireCopyButtons();
		document.querySelector( 'button' ).click();
		await Promise.resolve();

		expect( document.querySelector( 'button' ).hidden ).toBe( false );
		expect( writeText ).toHaveBeenCalledWith( SHORT_URL );
		expect( document.querySelector( '[role="status"]' ).textContent ).toBe( 'Link copied.' );
	} );

	it( 'says so when the copy fails', async () => {
		mockClipboard( jest.fn().mockRejectedValue( new Error( 'denied' ) ) );

		wireCopyButtons();
		document.querySelector( 'button' ).click();
		await new Promise( ( resolve ) => setTimeout( resolve ) );

		expect( document.querySelector( '[role="status"]' ).textContent ).toBe( "Couldn't copy." );
	} );
} );

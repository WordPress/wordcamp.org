const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Every event card opens its event from anywhere on the card, including the
 * media region at the top.
 *
 * That region used to be dead on cards with no featured image (#2055). The
 * card is made clickable by stretching the post-title link over it with an
 * absolutely positioned `::after`, but core gives every constrained group
 * `position: relative`, so the stretch resolved against the card's inner
 * text group and stopped short of the media above it. Cards with a thumbnail
 * hid the gap behind core's own image link; cards without one had an
 * unclickable top third.
 *
 * Hit-testing is the point here: the markup can carry a link and still not
 * receive the click, which is exactly how the bug was missed.
 *
 * Requires the `organiser5` / `password` editor-tier test user. One account
 * per test: this environment only supports a single active session per user
 * and specs run concurrently under `fullyParallel` (see utils/login.js).
 */
test.describe( 'event card clickability', () => {
	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';

	test( "the media region of every card opens that card's event", async ( { page } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		await login( page, 'organiser5', 'password' );

		/*
		 * Publish an event of our own rather than reading whatever the archive
		 * happens to hold. CI seeds no events at all, and the specs that create
		 * them run concurrently with this one, so the archive was empty on the
		 * runs that reached it first.
		 */
		const title = `Event card clickability E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const modal = page.locator( '.wporg-groups-event-modal' );
		await modal.getByLabel( 'Event title' ).fill( title );
		await modal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await modal.getByLabel( 'Start time' ).fill( START );
		await modal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		await modal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );

		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( 'event/', { waitUntil: 'domcontentloaded' } );

		const cards = page.locator( '.wp-block-post-template > li' );
		expect( await cards.count() ).toBeGreaterThan( 0 );

		const results = await page.evaluate( () => {
			return [ ...document.querySelectorAll( '.wp-block-post-template > li' ) ].map( ( card ) => {
				const media = card.querySelector( '.wp-block-post-featured-image' );
				const titleLink = card.querySelector( '.wp-block-post-title a' );

				if ( ! media || ! titleLink ) {
					return { ok: false, reason: 'card is missing its media region or title link' };
				}

				// `elementFromPoint` is viewport-relative and returns null for
				// anything scrolled out of it, so bring each card into view
				// before asking what sits on top of its media region.
				media.scrollIntoView( { block: 'center' } );

				const box = media.getBoundingClientRect();
				const hit = document.elementFromPoint( box.left + box.width / 2, box.top + box.height / 2 );
				const link = hit && hit.closest( 'a' );

				return {
					ok: !! link && link.href === titleLink.href,
					title: titleLink.textContent.trim(),
					hasImage: !! media.querySelector( 'img' ),
					href: link ? link.href : null,
					expected: titleLink.href,
				};
			} );
		} );

		for ( const result of results ) {
			expect(
				result,
				`"${ result.title }" (${ result.hasImage ? 'with' : 'without' } a featured image) ` +
					'does not open its event from the media region'
			).toMatchObject( { ok: true } );
		}

		// The regression is specific to cards core renders no image for, so a
		// run where every event happens to have one proves nothing.
		expect(
			results.some( ( result ) => ! result.hasImage ),
			'No image-less event on the archive, so this run did not exercise the placeholder.'
		).toBe( true );
	} );
} );

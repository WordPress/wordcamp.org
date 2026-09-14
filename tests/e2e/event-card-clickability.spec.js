const { test, expect } = require( '@playwright/test' );

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
 */
test.describe( 'event card clickability', () => {
	test( "the media region of every card opens that card's event", async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( 'event/', { waitUntil: 'domcontentloaded' } );

		const cards = page.locator( '.wp-block-post-template > li' );
		expect( await cards.count() ).toBeGreaterThan( 0 );

		const results = await page.evaluate( () => {
			return [ ...document.querySelectorAll( '.wp-block-post-template > li' ) ].map( ( card ) => {
				const media = card.querySelector( '.wp-block-post-featured-image' );
				const title = card.querySelector( '.wp-block-post-title a' );

				if ( ! media || ! title ) {
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
					ok: !! link && link.href === title.href,
					title: title.textContent.trim(),
					hasImage: !! media.querySelector( 'img' ),
					href: link ? link.href : null,
					expected: title.href,
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

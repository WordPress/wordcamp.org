const { test, expect } = require( '@playwright/test' );

test.describe( 'event RSVP accessibility', () => {
	test( 'attendee dialog traps focus and restores it to its native trigger', async ( { page } ) => {
		await page.goto( 'event/block-themes-deep-dive/', {
			waitUntil: 'domcontentloaded',
		} );

		const trigger = page.getByRole( 'button', { name: 'View attendees' } );
		await expect( trigger ).toHaveCount( 1 );
		expect( await trigger.evaluate( ( element ) => element.tagName ) ).toBe( 'BUTTON' );

		const notice = page.getByRole( 'status' );
		await expect( notice ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( notice ).toHaveAttribute( 'aria-atomic', 'true' );

		await trigger.click();

		const dialog = page.getByRole( 'dialog', { name: 'Event attendees' } );
		await expect( dialog ).toBeVisible();

		const close = dialog.getByRole( 'button', { name: 'Close' } );
		await expect( close ).toBeFocused();

		const focusable = dialog.locator(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		const focusableCount = await focusable.count();
		expect( focusableCount ).toBeGreaterThan( 0 );

		await page.keyboard.press( 'Shift+Tab' );
		await expect( focusable.nth( focusableCount - 1 ) ).toBeFocused();

		await page.keyboard.press( 'Tab' );
		await expect( close ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( trigger ).toBeFocused();
	} );
} );

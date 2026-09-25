const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The Export tab in the organiser-facing group settings modal.
 *
 * Requires the `organiser1` / `password` test user from the local Groups
 * environment setup.
 */
test.describe( 'export tab', () => {
	/**
	 * Opens the settings modal on the Export tab, logged in as an organiser.
	 */
	async function openExportTab( page ) {
		await login( page, 'organiser1', 'password' );
		await page.goto( '' );

		await page.locator( '[data-wporg-settings-open]' ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'tab', { name: 'Export' } ).click();

		return dialog;
	}

	/**
	 * Clicks a download button and returns the file's contents.
	 */
	async function downloadFrom( page, dialog, buttonName ) {
		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			dialog.getByRole( 'button', { name: buttonName } ).click(),
		] );

		return {
			filename: download.suggestedFilename(),
			content: fs.readFileSync( await download.path(), 'utf8' ),
		};
	}

	test( 'an organiser can download the full CSV and JSON', async ( { page }, testInfo ) => {
		const dialog = await openExportTab( page );
		await page.screenshot( { path: testInfo.outputPath( 'export-tab.png' ) } );

		const csv = await downloadFrom( page, dialog, 'Download CSV' );
		expect( csv.filename ).toMatch( /-events-\d{4}-\d{2}-\d{2}\.csv$/ );
		// Every field is quoted, whatever it contains, so a reader that splits
		// on a delimiter other than the comma can't re-split a cell.
		expect( csv.content ).toContain(
			'"event_id","event_title","event_start_gmt","event_end_gmt","venue","organiser"'
		);

		const json = await downloadFrom( page, dialog, 'Download JSON' );
		expect( json.filename ).toMatch( /\.json$/ );
		const data = JSON.parse( json.content );
		expect( Array.isArray( data.events ) ).toBe( true );
		expect( data.events.length ).toBeGreaterThan( 0 );
		expect( data.group.name ).toBeTruthy();
	} );

	test( 'column and date-range selections narrow the CSV', async ( { page }, testInfo ) => {
		const dialog = await openExportTab( page );

		const columnsField = dialog.getByLabel( 'Which columns should be exported?' );
		for ( const label of [ 'Event title', 'RSVP status' ] ) {
			await columnsField.fill( label );
			await columnsField.press( 'Enter' );
		}

		await dialog
			.getByLabel( 'Which dates should be exported?' )
			.selectOption( 'past' );
		await page.screenshot( { path: testInfo.outputPath( 'export-tab-filters.png' ) } );

		const csv = await downloadFrom( page, dialog, 'Download CSV' );
		const [ header ] = csv.content.replace( /^﻿/, '' ).split( '\n' );

		expect( header.trim() ).toBe( '"event_title","rsvp_status"' );
	} );
} );

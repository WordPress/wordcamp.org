import { getFeaturedImageHelp, getUploadSpaceNotice } from '../src/components/event-form/featured-image-help';

describe( 'getFeaturedImageHelp', () => {
	test( 'gives the shape, the size limit and the JPG tip', () => {
		const help = getFeaturedImageHelp( '1 MB' );

		expect( help ).toContain( '16:9' );
		expect( help ).toContain( '1200 × 675' );
		expect( help ).toContain( 'Maximum file size: 1 MB.' );
		expect( help ).toContain( 'JPG' );
	} );

	test( 'leaves the limit out when it is not known', () => {
		const help = getFeaturedImageHelp( '' );

		expect( help ).toContain( '1200 × 675' );
		expect( help ).not.toContain( 'Maximum file size' );
	} );
} );

describe( 'getUploadSpaceNotice', () => {
	test( 'says nothing while there is room', () => {
		expect( getUploadSpaceNotice( { status: 'ok', used: '10 MB', allowed: '1 GB' } ) ).toBe( '' );
	} );

	test( 'says nothing without a config', () => {
		expect( getUploadSpaceNotice( undefined ) ).toBe( '' );
	} );

	test( 'warns when the space is nearly used, with where to ask', () => {
		const notice = getUploadSpaceNotice( { status: 'near', used: '950 MB', allowed: '1 GB' } );

		expect( notice ).toContain( '950 MB of its 1 GB' );
		expect( notice ).toContain( '<a>#meetups</a>' );
	} );

	test( 'says uploads stop when the space is used up, and that the library still works', () => {
		const notice = getUploadSpaceNotice( { status: 'full', used: '1.1 GB', allowed: '1 GB' } );

		expect( notice ).toContain( 'all 1 GB' );
		expect( notice ).toContain( 'can’t be uploaded' );
		expect( notice ).toContain( 'already in the media library' );
		expect( notice ).toContain( '<a>#meetups</a>' );
	} );
} );

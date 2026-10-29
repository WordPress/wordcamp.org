import { getFeaturedImageHelp } from '../src/components/event-form/featured-image-help';

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

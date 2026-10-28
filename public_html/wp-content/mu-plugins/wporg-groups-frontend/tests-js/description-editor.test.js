import { ALLOWED_BLOCK_TYPES } from '../src/components/event-form/constants';

describe( 'DescriptionEditor ALLOWED_BLOCK_TYPES', () => {
	test( 'includes only the allowed description blocks', () => {
		expect( ALLOWED_BLOCK_TYPES ).toEqual( [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/image',
			'core/quote',
			'core/separator',
			'core/code',
			'core/preformatted',
			'core/group',
			'core/columns',
			'core/column',
		] );
	} );

	test( 'disallows unsupported blocks that cannot round-trip', () => {
		const disallowed = [
			'core/pullquote',
			'core/gallery',
			'core/cover',
			'core/audio',
			'core/video',
			'core/table',
		];

		disallowed.forEach( ( blockName ) => {
			expect( ALLOWED_BLOCK_TYPES ).not.toContain( blockName );
		} );
	} );
} );

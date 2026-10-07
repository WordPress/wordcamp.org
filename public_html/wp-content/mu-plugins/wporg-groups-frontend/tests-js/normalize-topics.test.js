import normalizeTopics, { MAX_TOPICS } from '../src/components/event-form/normalize-topics';

describe( 'normalizeTopics', () => {
	test( 'trims, drops blanks and folds case-insensitive repeats', () => {
		expect( normalizeTopics( [ ' WordPress ', '', 'wordpress', 'PHP', '   ' ] ) ).toEqual( [
			'WordPress',
			'PHP',
		] );
	} );

	test( 'reads object tokens by their value', () => {
		expect( normalizeTopics( [ { value: 'Blocks' }, 'PHP' ] ) ).toEqual( [ 'Blocks', 'PHP' ] );
	} );

	test( 'caps the list the way the server does', () => {
		const many = [ 'One', 'Two', 'Three', 'Four', 'Five', 'Six' ];

		expect( normalizeTopics( many ) ).toEqual( many.slice( 0, MAX_TOPICS ) );
	} );

	test( 'treats a missing value as no topics', () => {
		expect( normalizeTopics( undefined ) ).toEqual( [] );
	} );
} );

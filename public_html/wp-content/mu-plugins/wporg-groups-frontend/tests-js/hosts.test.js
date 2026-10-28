import { labelPeople, memberToHost } from '../src/components/event-form/hosts';

describe( 'memberToHost', () => {
	test( 'reads the profile slug off the member', () => {
		expect(
			memberToHost( { id: 4, name: 'Ana', profile: 'https://profiles.wordpress.org/ana-dev/' } )
		).toEqual( { id: 4, name: 'Ana', slug: 'ana-dev' } );
	} );

	test( 'leaves the slug empty without a profile', () => {
		expect( memberToHost( { id: 4, name: 'Ana' } ).slug ).toBe( '' );
	} );
} );

describe( 'labelPeople', () => {
	test( 'labels people by name, once each', () => {
		const ana = { id: 1, name: 'Ana', slug: 'ana' };
		const labels = labelPeople( [ ana, { id: 2, name: 'Bo', slug: 'bo' }, ana ] );

		expect( [ ...labels.keys() ] ).toEqual( [ 'Ana', 'Bo' ] );
		expect( labels.get( 'Ana' ).id ).toBe( 1 );
	} );

	test( 'adds the slug where two people share a name', () => {
		const labels = labelPeople( [
			{ id: 1, name: 'Sam', slug: 'sam-a' },
			{ id: 2, name: 'Sam', slug: 'sam-b' },
		] );

		expect( labels.get( 'Sam (@sam-a)' ).id ).toBe( 1 );
		expect( labels.get( 'Sam (@sam-b)' ).id ).toBe( 2 );
		expect( labels.has( 'Sam' ) ).toBe( false );
	} );
} );

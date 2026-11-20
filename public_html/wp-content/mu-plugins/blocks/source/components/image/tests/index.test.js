/**
 * External dependencies
 */
import renderer from 'react-test-renderer';

/**
 * Internal dependencies
 */
import { AvatarImage } from '../avatar';

describe( 'AvatarImage', () => {
	test( 'should show HTML entities in the name as characters in the alt text.', () => {
		const component = renderer.create(
			<AvatarImage
				name={ 'Tom &amp; Jerry&#8217;s' }
				size={ 24 }
				url="https://example.com/avatar.png"
			/>
		);
		expect( component.root.findByType( 'img' ).props.alt ).toBe(
			'Avatar of Tom & Jerry’s'
		);
	} );
} );

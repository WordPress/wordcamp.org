/* global describe, test, expect */
/**
 * External dependencies
 */
import renderer from 'react-test-renderer';

/**
 * Internal dependencies
 */
import { ItemTitle } from '../';

describe( 'ItemTitle', () => {
	test( 'should render a heading tag with the default level.', () => {
		const component = renderer.create( <ItemTitle title="Example Title" /> );
		expect( component.toJSON() ).toMatchSnapshot();
	} );

	test( 'should render a heading tag of level 1.', () => {
		const component = renderer.create( <ItemTitle title="Example Title" headingLevel={ 1 } /> );
		expect( component.toJSON() ).toMatchSnapshot();
	} );

	test( 'should render a heading tag with a custom class.', () => {
		const component = renderer.create( <ItemTitle title="Example Title" className="my-test-heading" /> );
		expect( component.toJSON() ).toMatchSnapshot();
	} );

	test( 'should render a heading tag with a set alignment.', () => {
		const component = renderer.create( <ItemTitle title="Example Title" align="right" /> );
		expect( component.toJSON() ).toMatchSnapshot();
	} );

	test( 'should render a heading tag with a link.', () => {
		const component = renderer.create( <ItemTitle title="Example Title" link="https://wordpress.org" /> );
		expect( component.toJSON() ).toMatchSnapshot();
	} );

	test( 'should render a heading tag with a link, custom class name, heading level, and alignment.', () => {
		const component = renderer.create(
			<ItemTitle
				title="Example Title"
				headingLevel={ 1 }
				className="my-test-heading"
				align="right"
				link="https://wordpress.org"
			/>
		);
		expect( component.toJSON() ).toMatchSnapshot();
	} );
} );

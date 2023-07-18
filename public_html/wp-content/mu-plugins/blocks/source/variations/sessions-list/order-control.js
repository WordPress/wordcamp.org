/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const orderOptions = [
	{
		label: __( 'Newest to oldest', 'wordcamporg' ),
		value: 'date/desc',
	},
	{
		label: __( 'Oldest to newest', 'wordcamporg' ),
		value: 'date/asc',
	},
	{
		/* translators: label for ordering posts by title in ascending order */
		label: __( 'A → Z', 'wordcamporg' ),
		value: 'title/asc',
	},
	{
		/* translators: label for ordering posts by title in descending order */
		label: __( 'Z → A', 'wordcamporg' ),
		value: 'title/desc',
	},
	{
		label: __( 'Latest First', 'wordcamporg' ),
		value: 'session_date/desc',
	},
	{
		label: __( 'Earliest First', 'wordcamporg' ),
		value: 'session_date/asc',
	},
];

function OrderControl( { order, orderBy, onChange } ) {
	return (
		<SelectControl
			hideLabelFromVision
			label={ __( 'Order by', 'wordcamporg' ) }
			value={ `${ orderBy }/${ order }` }
			options={ orderOptions }
			onChange={ ( value ) => {
				const [ newOrderBy, newOrder ] = value.split( '/' );
				onChange( { order: newOrder, orderBy: newOrderBy } );
			} }
		/>
	);
}

export default OrderControl;

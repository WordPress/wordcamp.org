/**
 * Venue select with the add/edit hand-off to the venue editor, shared by
 * the event modal and the group-settings Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function VenueField( { venues, venueId, onSelect, onOpenVenueEditor, classPrefix } ) {
	const options = [
		{ label: __( '— No venue —', 'wporg-groups-frontend' ), value: '' },
	].concat(
		( venues || [] ).map( ( v ) => ( {
			label: v.name,
			value: String( v.id ),
		} ) )
	).concat( [
		{ label: __( '+ Add a new venue', 'wporg-groups-frontend' ), value: '__new__' },
	] );

	const handleChange = ( v ) => {
		if ( v === '__new__' ) {
			onOpenVenueEditor( 0 );
		} else {
			onSelect( v );
		}
	};

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h( SelectControl, {
			label: __( 'Venue', 'wporg-groups-frontend' ),
			value: venueId ? String( venueId ) : '',
			options: options,
			onChange: handleChange,
			__nextHasNoMarginBottom: true,
		} ),
		venueId && venueId !== '__new__' &&
			h( Button, {
				variant: 'link',
				onClick: () => onOpenVenueEditor( parseInt( venueId, 10 ) ),
				className: `${ classPrefix }__edit-venue`,
			}, __( 'Edit venue', 'wporg-groups-frontend' ) )
	);
}

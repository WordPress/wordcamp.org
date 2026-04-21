/**
 * Group Settings — Venues tab.
 *
 * Lists existing venues and allows creating/editing venues.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
} from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import VenueEditor from '../../event-manage/venue-editor';

export default function VenuesTab() {
	const [ venues, setVenues ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ editingId, setEditingId ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/gatherpress_venues?per_page=100&_fields=id,title,meta' } )
			.then( ( data ) => {
				setVenues( data.map( ( v ) => ( {
					id: v.id,
					name: v.title.rendered,
					address: v.meta?.gatherpress_venue_information
						? JSON.parse( v.meta.gatherpress_venue_information ).fullAddress || ''
						: '',
				} ) ) );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	if ( editingId !== null ) {
		return h( VenueEditor, {
			venueId: editingId,
			onSave: ( saved ) => {
				setEditingId( null );
				setVenues( ( prev ) => {
					const exists = prev.find( ( v ) => v.id === saved.id );
					if ( exists ) {
						return prev.map( ( v ) =>
							v.id === saved.id ? { ...v, name: saved.name } : v
						);
					}
					return [ ...prev, { id: saved.id, name: saved.name, address: '' } ];
				} );
			},
			onCancel: () => setEditingId( null ),
		} );
	}

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		h(
			'div',
			{ className: 'wporg-settings-tab__header' },
			h( 'p', {}, __( 'Manage the venues your group uses for events.', 'wporg-groups-frontend' ) ),
			h(
				Button,
				{
					variant: 'primary',
					onClick: () => setEditingId( 0 ),
				},
				__( '+ Add venue', 'wporg-groups-frontend' )
			)
		),
		venues.length === 0
			? h( 'p', { className: 'wporg-settings-tab__empty' }, __( 'No venues yet.', 'wporg-groups-frontend' ) )
			: h(
					'div',
					{ className: 'wporg-settings-tab__list' },
					venues.map( ( venue ) =>
						h(
							'div',
							{ key: venue.id, className: 'wporg-settings-tab__list-item' },
							h(
								'div',
								{ className: 'wporg-settings-tab__list-item-info' },
								h( 'strong', {}, venue.name ),
								venue.address && h( 'span', {}, venue.address )
							),
							h(
								Button,
								{
									variant: 'secondary',
									isSmall: true,
									onClick: () => setEditingId( venue.id ),
								},
								__( 'Edit', 'wporg-groups-frontend' )
							)
						)
					)
				)
	);
}

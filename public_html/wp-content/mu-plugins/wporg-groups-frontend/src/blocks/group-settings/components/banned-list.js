/**
 * The people banned from the group, with a way to lift each ban (#2395).
 *
 * Lifting a ban doesn't add the person back; they can join again themselves.
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { createElement as h, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export default function BannedList( { refreshKey, onNotice } ) {
	const [ banned, setBanned ] = useState( [] );
	const [ unbanningId, setUnbanningId ] = useState( 0 );

	useEffect( () => {
		let isMounted = true;

		apiFetch( { path: '/wporg-groups/v1/members/banned' } )
			.then( ( data ) => {
				if ( isMounted ) {
					setBanned( Array.isArray( data ) ? data : [] );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setBanned( [] );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ refreshKey ] );

	const unban = ( person ) => {
		setUnbanningId( person.id );

		apiFetch( { path: `/wporg-groups/v1/members/banned/${ person.id }`, method: 'DELETE' } )
			.then( () => {
				setBanned( ( prev ) => prev.filter( ( p ) => p.id !== person.id ) );
				onNotice(
					sprintf(
						/* translators: %s: person's name. */
						__( '%s can join this group again.', 'wordcamporg' ),
						person.name
					)
				);
			} )
			.catch( ( err ) => onNotice( err.message || __( 'Could not lift the ban.', 'wordcamporg' ) ) )
			.finally( () => setUnbanningId( 0 ) );
	};

	if ( ! banned.length ) {
		return null;
	}

	return h(
		'div',
		{ className: 'wporg-members-tab__banned' },
		h( 'h3', { className: 'wporg-members-tab__banned-heading' }, __( 'Banned', 'wordcamporg' ) ),
		h(
			'p',
			{ className: 'wporg-members-tab__banned-help' },
			__( "Banned people can't join this group or RSVP to its events. Lifting a ban doesn't add them back.", 'wordcamporg' )
		),
		h(
			'div',
			{ className: 'wporg-members-tab__list' },
			banned.map( ( person ) =>
				h(
					'div',
					{ key: person.id, className: 'wporg-members-tab__item' },
					h(
						'div',
						{ className: 'wporg-members-tab__info' },
						h( 'span', { className: 'wporg-members-tab__name' }, person.name ),
						h(
							'span',
							{ className: 'wporg-members-tab__banned-meta' },
							person.bannedBy
								? sprintf(
										/* translators: 1: organizer's name, 2: date. */
										__( 'Banned by %1$s on %2$s', 'wordcamporg' ),
										person.bannedBy,
										new Date( person.bannedAt ).toLocaleDateString()
								  )
								: sprintf(
										/* translators: %s: date. */
										__( 'Banned on %s', 'wordcamporg' ),
										new Date( person.bannedAt ).toLocaleDateString()
								  )
						)
					),
					h(
						'div',
						{ className: 'wporg-members-tab__actions' },
						h(
							Button,
							{
								variant: 'tertiary',
								isSmall: true,
								isBusy: unbanningId === person.id,
								disabled: unbanningId !== 0,
								onClick: () => unban( person ),
								label: sprintf(
									/* translators: %s: person's name. */
									__( 'Lift the ban on %s', 'wordcamporg' ),
									person.name
								),
							},
							__( 'Unban', 'wordcamporg' )
						)
					)
				)
			)
		)
	);
}

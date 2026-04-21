/**
 * Group Settings — Members tab.
 *
 * Lists group members with role management for organisers.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
} from '@wordpress/element';
import { SelectControl, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const ROLE_OPTIONS = [
	{ label: __( 'Member', 'wporg-groups-frontend' ), value: 'subscriber' },
	{ label: __( 'Event Organiser', 'wporg-groups-frontend' ), value: 'author' },
	{ label: __( 'Organiser', 'wporg-groups-frontend' ), value: 'editor' },
];

export default function MembersTab() {
	const [ members, setMembers ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/wporg-groups/v1/members?per_page=200' } )
			.then( ( data ) => {
				setMembers( data );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	const updateRole = async ( userId, newRole ) => {
		setNotice( '' );
		try {
			await apiFetch( {
				path: `/wp/v2/users/${ userId }`,
				method: 'POST',
				data: { roles: [ newRole ] },
			} );

			setMembers( ( prev ) =>
				prev.map( ( m ) =>
					m.id === userId
						? {
								...m,
								role: newRole,
								roleLabel:
									ROLE_OPTIONS.find( ( o ) => o.value === newRole )?.label || 'Member',
							}
						: m
				)
			);
			setNotice( __( 'Role updated.', 'wporg-groups-frontend' ) );
		} catch ( err ) {
			setNotice( err.message || __( 'Could not update role.', 'wporg-groups-frontend' ) );
		}
	};

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h(
				Notice,
				{
					status: 'info',
					isDismissible: true,
					onDismiss: () => setNotice( '' ),
				},
				notice
			),
		h(
			'p',
			{},
			__( 'Manage group members and their roles.', 'wporg-groups-frontend' )
		),
		h(
			'div',
			{ className: 'wporg-members-tab__list' },
			members.map( ( member ) =>
				h(
					'div',
					{ key: member.id, className: 'wporg-members-tab__item' },
					h( 'img', {
						className: 'wporg-members-tab__avatar',
						src: member.avatar,
						alt: '',
						width: 40,
						height: 40,
					} ),
					h(
						'div',
						{ className: 'wporg-members-tab__info' },
						h( 'span', { className: 'wporg-members-tab__name' }, member.name ),
					),
					h( SelectControl, {
						value: member.role,
						options: ROLE_OPTIONS,
						onChange: ( val ) => updateRole( member.id, val ),
						__nextHasNoMarginBottom: true,
						className: 'wporg-members-tab__role-select',
					} )
				)
			)
		)
	);
}

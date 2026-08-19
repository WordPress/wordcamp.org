/**
 * Group Settings — Members tab.
 *
 * Lists group members with role management, search, and pagination.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import {
	SearchControl,
	SelectControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import OwnershipTransferPanel from './ownership-transfer-panel';

const ROLE_OPTIONS = [
	{ label: __( 'Member', 'wporg-groups-frontend' ), value: 'subscriber' },
	{ label: __( 'Event Organizer', 'wporg-groups-frontend' ), value: 'author' },
	{ label: __( 'Organizer', 'wporg-groups-frontend' ), value: 'editor' },
];

const PER_PAGE = 20;
const ASSIGNABLE_ROLES = ROLE_OPTIONS.map( ( option ) => option.value );

export default function MembersTab( { canManageRoles = false } ) {
	const [ members, setMembers ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	useEffect( () => {
		let isMounted = true;

		const fetchMembers = async () => {
			const params = new URLSearchParams( {
				per_page: String( PER_PAGE ),
				page: String( page ),
			} );

			if ( search.trim() ) {
				params.set( 'search', search.trim() );
			}

			setLoading( true );
			setNotice( '' );

			const response = await apiFetch( {
				path: `/wporg-groups/v1/members?${ params.toString() }`,
				parse: false,
			} );
			const data = await response.json();

			if ( ! isMounted ) {
				return;
			}

			setMembers( data );
			setTotal( Number( response.headers.get( 'X-WP-Total' ) ) || data.length );
			setTotalPages( Math.max( 1, Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1 ) );
			setLoading( false );
		};

		fetchMembers().catch( ( err ) => {
			if ( isMounted ) {
				setMembers( [] );
				setTotal( 0 );
				setTotalPages( 1 );
				setNotice( err.message || __( 'Could not load members.', 'wporg-groups-frontend' ) );
				setLoading( false );
			}
		} );

		return () => {
			isMounted = false;
		};
	}, [ page, search ] );

	const updateRole = useCallback( async ( userId, newRole ) => {
		setNotice( '' );
		try {
			const updatedMember = await apiFetch( {
				path: `/wporg-groups/v1/members/${ userId }/role`,
				method: 'POST',
				data: { role: newRole },
			} );

			setMembers( ( prev ) =>
				prev.map( ( m ) =>
					m.id === userId
						? updatedMember
						: m
				)
			);
			setNotice( __( 'Role updated.', 'wporg-groups-frontend' ) );
		} catch ( err ) {
			setNotice( err.message || __( 'Could not update role.', 'wporg-groups-frontend' ) );
		}
	}, [] );

	const onSearchChange = useCallback( ( value ) => {
		setSearch( value );
		setPage( 1 );
	}, [] );

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h( Notice, { status: 'info', isDismissible: true, onDismiss: () => setNotice( '' ) }, notice ),
		h( OwnershipTransferPanel ),
		h(
			'div',
			{ className: 'wporg-members-tab__controls' },
			h( SearchControl, {
				value: search,
				onChange: onSearchChange,
				placeholder: __( 'Search members\u2026', 'wporg-groups-frontend' ),
				className: 'wporg-members-tab__search',
				__nextHasNoMarginBottom: true,
			} ),
			h( 'span', { className: 'wporg-members-tab__count' },
				total.toLocaleString() + ' ' + ( total === 1 ? __( 'member', 'wporg-groups-frontend' ) : __( 'members', 'wporg-groups-frontend' ) )
			),
		),
		h(
			'div',
			{ className: 'wporg-members-tab__list' },
			members.map( ( member ) => {
				const canEditRole = canManageRoles && ASSIGNABLE_ROLES.includes( member.role );

				return h(
					'div',
					{ key: member.id, className: 'wporg-members-tab__item' },
					h( 'img', {
						className: 'wporg-members-tab__avatar',
						src: member.avatar,
						alt: '',
						width: 40,
						height: 40,
					} ),
					h( 'div', { className: 'wporg-members-tab__info' },
						h( 'span', { className: 'wporg-members-tab__name' }, member.name )
					),
					canEditRole
						? h( SelectControl, {
							value: member.role,
							options: ROLE_OPTIONS,
							onChange: ( val ) => updateRole( member.id, val ),
							__nextHasNoMarginBottom: true,
							className: 'wporg-members-tab__role-select',
						} )
						: h( 'span', { className: 'wporg-members-tab__role-readonly' },
							member.roleLabel || __( 'Organizer', 'wporg-groups-frontend' )
						)
				);
			} )
		),
		totalPages > 1 &&
			h(
				'div',
				{ className: 'wporg-members-tab__pagination' },
				h(
					Button,
					{
						variant: 'secondary',
						isSmall: true,
						disabled: page <= 1,
						onClick: () => setPage( ( currentPage ) => Math.max( 1, currentPage - 1 ) ),
					},
					__( 'Previous', 'wporg-groups-frontend' )
				),
				h( 'span', {},
					page.toLocaleString() + ' / ' + totalPages.toLocaleString()
				),
				h(
					Button,
					{
						variant: 'secondary',
						isSmall: true,
						disabled: page >= totalPages,
						onClick: () => setPage( ( currentPage ) => Math.min( totalPages, currentPage + 1 ) ),
					},
					__( 'Next', 'wporg-groups-frontend' )
				)
			)
	);
}

/**
 * Group Settings — Members tab.
 *
 * Lists group members with role management, removal, search, and pagination,
 * lets organizers message every member (#2409), and ban people and lift bans
 * (#2395).
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
import { __, sprintf } from '@wordpress/i18n';

import OwnershipTransferPanel from './ownership-transfer-panel';
import MessageMembersModal from '../../event-manage/message-members-modal';
import RemoveMemberModal from './remove-member-modal';
import BannedList from './banned-list';

const ROLE_OPTIONS = [
	{ label: __( 'Member', 'wordcamporg' ), value: 'subscriber' },
	{ label: __( 'Event Organizer', 'wordcamporg' ), value: 'author' },
	{ label: __( 'Organizer', 'wordcamporg' ), value: 'editor' },
];

const PER_PAGE = 20;
const ASSIGNABLE_ROLES = ROLE_OPTIONS.map( ( option ) => option.value );

// Mirrors the PHP `ORGANIZER_ROLES`: these are changed to Member before they can be removed.
const ORGANIZER_ROLES = [ 'administrator', 'editor' ];

export default function MembersTab( { canManageRoles = false } ) {
	const [ members, setMembers ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ removing, setRemoving ] = useState( null );
	const [ messaging, setMessaging ] = useState( false );
	const [ bannedKey, setBannedKey ] = useState( 0 );

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
				setNotice( err.message || __( 'Could not load members.', 'wordcamporg' ) );
				setLoading( false );
			}
		} );

		return () => {
			isMounted = false;
		};
	}, [ page, search ] );

	// Removals can empty the page you're on, so go back one rather than show an empty list.
	useEffect( () => {
		if ( ! loading && page > 1 && members.length === 0 ) {
			setPage( ( currentPage ) => currentPage - 1 );
		}
	}, [ loading, page, members.length ] );

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
			setNotice( __( 'Role updated.', 'wordcamporg' ) );
		} catch ( err ) {
			setNotice( err.message || __( 'Could not update role.', 'wordcamporg' ) );
		}
	}, [] );

	const onRemoved = useCallback( ( member, banned ) => {
		setRemoving( null );
		setMembers( ( prev ) => prev.filter( ( m ) => m.id !== member.id ) );
		setTotal( ( prev ) => prev - 1 );
		setNotice(
			banned
				? sprintf(
						/* translators: %s: member name. */
						__( '%s was removed from the group and banned.', 'wordcamporg' ),
						member.name
				  )
				: sprintf(
						/* translators: %s: member name. */
						__( '%s was removed from the group.', 'wordcamporg' ),
						member.name
				  )
		);

		if ( banned ) {
			setBannedKey( ( key ) => key + 1 );
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
				placeholder: __( 'Search members\u2026', 'wordcamporg' ),
				className: 'wporg-members-tab__search',
				__nextHasNoMarginBottom: true,
			} ),
			h( 'span', { className: 'wporg-members-tab__count' },
				total.toLocaleString() + ' ' + ( total === 1 ? __( 'member', 'wordcamporg' ) : __( 'members', 'wordcamporg' ) )
			),
			canManageRoles &&
				h(
					Button,
					{
						variant: 'secondary',
						className: 'wporg-members-tab__message',
						onClick: () => setMessaging( true ),
					},
					__( 'Message all members', 'wordcamporg' )
				),
		),
		messaging &&
			h( MessageMembersModal, {
				recipientMode: 'message-group',
				onClose: () => setMessaging( false ),
			} ),
		removing &&
			h( RemoveMemberModal, {
				member: removing,
				onClose: () => setRemoving( null ),
				onRemoved,
			} ),
		h(
			'div',
			{ className: 'wporg-members-tab__list' },
			members.map( ( member ) => {
				const canEditRole = canManageRoles && ASSIGNABLE_ROLES.includes( member.role );
				const canRemove = canManageRoles && ! ORGANIZER_ROLES.includes( member.role );

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
							member.roleLabel || __( 'Organizer', 'wordcamporg' )
						),
					// Every row gets the slot, so the role controls line up.
					canManageRoles &&
						h( 'div', { className: 'wporg-members-tab__actions' },
							canRemove &&
								h(
									Button,
									{
										variant: 'tertiary',
										isDestructive: true,
										isSmall: true,
										onClick: () => setRemoving( member ),
										label: sprintf(
											/* translators: %s: member name. */
											__( 'Remove %s', 'wordcamporg' ),
											member.name
										),
									},
									__( 'Remove', 'wordcamporg' )
								)
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
					__( 'Previous', 'wordcamporg' )
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
					__( 'Next', 'wordcamporg' )
				)
			),
		canManageRoles && h( BannedList, { refreshKey: bannedKey, onNotice: setNotice } )
	);
}

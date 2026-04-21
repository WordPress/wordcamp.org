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

const ROLE_OPTIONS = [
	{ label: __( 'Member', 'wporg-groups-frontend' ), value: 'subscriber' },
	{ label: __( 'Event Organiser', 'wporg-groups-frontend' ), value: 'author' },
	{ label: __( 'Organiser', 'wporg-groups-frontend' ), value: 'editor' },
];

const PER_PAGE = 20;

export default function MembersTab() {
	const [ allMembers, setAllMembers ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	useEffect( () => {
		apiFetch( { path: '/wporg-groups/v1/members?per_page=500' } )
			.then( ( data ) => {
				setAllMembers( data );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	const updateRole = useCallback( async ( userId, newRole ) => {
		setNotice( '' );
		try {
			await apiFetch( {
				path: `/wp/v2/users/${ userId }`,
				method: 'POST',
				data: { roles: [ newRole ] },
			} );

			setAllMembers( ( prev ) =>
				prev.map( ( m ) =>
					m.id === userId
						? {
								...m,
								role: newRole,
								roleLabel: ROLE_OPTIONS.find( ( o ) => o.value === newRole )?.label || 'Member',
							}
						: m
				)
			);
			setNotice( __( 'Role updated.', 'wporg-groups-frontend' ) );
		} catch ( err ) {
			setNotice( err.message || __( 'Could not update role.', 'wporg-groups-frontend' ) );
		}
	}, [] );

	// Filter by search.
	const filtered = search
		? allMembers.filter( ( m ) =>
				m.name.toLowerCase().includes( search.toLowerCase() )
			)
		: allMembers;

	// Paginate.
	const totalPages = Math.ceil( filtered.length / PER_PAGE );
	const pageMembers = filtered.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE );

	// Reset page when search changes.
	useEffect( () => setPage( 1 ), [ search ] );

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h( Notice, { status: 'info', isDismissible: true, onDismiss: () => setNotice( '' ) }, notice ),
		h(
			'div',
			{ className: 'wporg-members-tab__controls' },
			h( SearchControl, {
				value: search,
				onChange: setSearch,
				placeholder: __( 'Search members\u2026', 'wporg-groups-frontend' ),
				className: 'wporg-members-tab__search',
				__nextHasNoMarginBottom: true,
			} ),
			h( 'span', { className: 'wporg-members-tab__count' },
				filtered.length + ' ' + ( filtered.length === 1 ? __( 'member', 'wporg-groups-frontend' ) : __( 'members', 'wporg-groups-frontend' ) )
			),
		),
		h(
			'div',
			{ className: 'wporg-members-tab__list' },
			pageMembers.map( ( member ) =>
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
					h( 'div', { className: 'wporg-members-tab__info' },
						h( 'span', { className: 'wporg-members-tab__name' }, member.name )
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
						onClick: () => setPage( page - 1 ),
					},
					__( 'Previous', 'wporg-groups-frontend' )
				),
				h( 'span', {},
					/* translators: 1: current page, 2: total pages */
					page + ' / ' + totalPages
				),
				h(
					Button,
					{
						variant: 'secondary',
						isSmall: true,
						disabled: page >= totalPages,
						onClick: () => setPage( page + 1 ),
					},
					__( 'Next', 'wporg-groups-frontend' )
				)
			)
	);
}

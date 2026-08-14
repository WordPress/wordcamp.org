/**
 * Group Settings — Members tab — ownership transfer panel.
 *
 * Lets the group's current owner (or a network admin, for an unresponsive
 * owner) nominate a candidate to take over the group. The candidate must
 * explicitly accept, and a network admin must approve, before anything
 * changes — see `group-ownership-transfer.php` for the full workflow.
 *
 * Self-contained: fetches its own state on mount rather than taking props
 * from `MembersTab`, matching `AboutTab`'s self-contained-fetch style.
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
	SelectControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const STATUS_LABELS = {
	pending_acceptance: __( 'Awaiting candidate acceptance', 'wporg-groups-frontend' ),
	pending_approval: __( 'Accepted — awaiting network admin approval', 'wporg-groups-frontend' ),
};

// Mirrors the `finalize_transfer()` `$final_status` values this codebase's
// PHP side ever writes — see `group-ownership-transfer.php`.
const FINAL_STATUS_LABELS = {
	declined: __( 'Declined', 'wporg-groups-frontend' ),
	cancelled: __( 'Cancelled', 'wporg-groups-frontend' ),
	completed: __( 'Completed', 'wporg-groups-frontend' ),
	rejected: __( 'Rejected', 'wporg-groups-frontend' ),
};

export default function OwnershipTransferPanel() {
	const [ loading, setLoading ] = useState( true );
	const [ state, setState ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const [ noticeType, setNoticeType ] = useState( 'success' );
	const [ busy, setBusy ] = useState( false );
	const [ candidateId, setCandidateId ] = useState( '' );
	const [ fromUserId, setFromUserId ] = useState( '' );

	const fetchState = useCallback( () => {
		return apiFetch( { path: '/wporg-groups/v1/ownership-transfer' } ).then( ( data ) => {
			setState( data );
			setLoading( false );
		} );
	}, [] );

	useEffect( () => {
		fetchState().catch( ( err ) => {
			setNoticeType( 'error' );
			setNotice( err.message || __( 'Could not load ownership transfer status.', 'wporg-groups-frontend' ) );
			setLoading( false );
		} );
	}, [ fetchState ] );

	const runAction = useCallback( ( path, data ) => {
		setBusy( true );
		setNotice( '' );
		apiFetch( { path: `/wporg-groups/v1/ownership-transfer/${ path }`, method: 'POST', data } )
			.then( ( result ) => {
				setState( result );
				setCandidateId( '' );
				setFromUserId( '' );
			} )
			.catch( ( err ) => {
				setNoticeType( 'error' );
				setNotice( err.message || __( 'That action could not be completed.', 'wporg-groups-frontend' ) );
			} )
			.finally( () => setBusy( false ) );
	}, [] );

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	if ( ! state ) {
		return notice
			? h( Notice, { status: noticeType, isDismissible: false }, notice )
			: null;
	}

	const { pending, history, currentOwners, eligibleCandidates, canInitiate, canAccept, viewerIsOwner } = state;

	// Nothing to show if there's no pending transfer and this viewer can't start one.
	if ( ! pending && ! canInitiate ) {
		return null;
	}

	// An owner initiating their own transfer never needs to pick a "from"
	// user (the server defaults it to them); only a super admin acting on
	// an inactive owner's behalf does.
	const needsFromUser = canInitiate && ! viewerIsOwner;

	return h(
		'div',
		{ className: 'wporg-ownership-transfer' },
		h( 'h3', { className: 'wporg-settings-tab__section-title' }, __( 'Group ownership', 'wporg-groups-frontend' ) ),
		notice &&
			h( Notice, { status: noticeType, isDismissible: true, onDismiss: () => setNotice( '' ) }, notice ),

		pending &&
			h(
				Notice,
				{ status: 'info', isDismissible: false, className: 'wporg-ownership-transfer__status' },
				sprintf(
					/* translators: 1: current owner, 2: nominated owner, 3: status label. */
					__( 'Transfer from %1$s to %2$s: %3$s', 'wporg-groups-frontend' ),
					pending.fromUserName,
					pending.toUserName,
					STATUS_LABELS[ pending.status ] || pending.status
				)
			),

		pending &&
			canAccept &&
			h(
				'div',
				{ className: 'wporg-ownership-transfer__actions' },
				h(
					Button,
					{ variant: 'primary', isBusy: busy, disabled: busy, onClick: () => runAction( 'accept' ) },
					__( 'Accept ownership', 'wporg-groups-frontend' )
				),
				h(
					Button,
					{ variant: 'tertiary', isDestructive: true, isBusy: busy, disabled: busy, onClick: () => runAction( 'decline' ) },
					__( 'Decline', 'wporg-groups-frontend' )
				)
			),

		pending &&
			canInitiate &&
			h(
				'div',
				{ className: 'wporg-ownership-transfer__actions' },
				h(
					Button,
					{ variant: 'tertiary', isDestructive: true, isBusy: busy, disabled: busy, onClick: () => runAction( 'cancel' ) },
					__( 'Cancel transfer', 'wporg-groups-frontend' )
				)
			),

		! pending &&
			canInitiate &&
			h(
				'div',
				{ className: 'wporg-ownership-transfer__form' },
				eligibleCandidates.length === 0
					? h(
						'p',
						{ className: 'wporg-settings-tab__empty' },
						__( 'No members are eligible to receive ownership yet — only existing Organisers (editor tier) can be nominated.', 'wporg-groups-frontend' )
					)
					: [
						needsFromUser &&
							h( SelectControl, {
								key: 'from',
								label: __( 'Current owner being replaced', 'wporg-groups-frontend' ),
								value: fromUserId,
								options: [ { label: __( 'Select an owner…', 'wporg-groups-frontend' ), value: '' } ]
									.concat( currentOwners.map( ( owner ) => ( { label: owner.name, value: String( owner.id ) } ) ) ),
								onChange: setFromUserId,
								__nextHasNoMarginBottom: true,
							} ),
						h( SelectControl, {
							key: 'candidate',
							label: __( 'Transfer ownership to', 'wporg-groups-frontend' ),
							value: candidateId,
							options: [ { label: __( 'Select a member…', 'wporg-groups-frontend' ), value: '' } ]
								.concat( eligibleCandidates.map( ( c ) => ( { label: c.name, value: String( c.id ) } ) ) ),
							onChange: setCandidateId,
							__nextHasNoMarginBottom: true,
						} ),
						h(
							Button,
							{
								key: 'submit',
								variant: 'secondary',
								isBusy: busy,
								disabled: busy || ! candidateId || ( needsFromUser && ! fromUserId ),
								onClick: () =>
									runAction( 'initiate', {
										candidateId: Number( candidateId ),
										fromUserId: fromUserId ? Number( fromUserId ) : 0,
									} ),
							},
							__( 'Request transfer', 'wporg-groups-frontend' )
						),
					]
			),

		history.length > 0 &&
			h(
				'details',
				{ className: 'wporg-ownership-transfer__history' },
				h( 'summary', {}, __( 'Recent transfers', 'wporg-groups-frontend' ) ),
				h(
					'ul',
					{},
					history.map( ( entry, index ) =>
						h(
							'li',
							{ key: index },
							sprintf(
								/* translators: 1: previous owner, 2: new/nominated owner, 3: outcome. */
								__( '%1$s → %2$s: %3$s', 'wporg-groups-frontend' ),
								entry.fromUserName,
								entry.toUserName,
								FINAL_STATUS_LABELS[ entry.status ] || entry.status
							)
						)
					)
				)
			)
	);
}

/**
 * Modal for checking attendees in at an event, including walk-ins (#2130).
 *
 * Each toggle saves straight away, updating the list first and rolling the
 * row back if the save fails, since this is used at the door with people
 * waiting. Walk-ins are added by their WordPress.org username, and those
 * without an account are only counted (#2138).
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, Spinner, TextControl } from '@wordpress/components';
import { createElement as h, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { checkInPath, filterAttendees, stepWalkInCount, withCheckIn } from './check-in-helpers';

export default function CheckInModal( { eventId, recurrenceId, onClose } ) {
	const [ list, setList ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ filter, setFilter ] = useState( '' );
	const [ pending, setPending ] = useState( [] );
	const [ walkIn, setWalkIn ] = useState( '' );
	const [ addingWalkIn, setAddingWalkIn ] = useState( false );
	const [ savingWalkIns, setSavingWalkIns ] = useState( false );

	// The latest list, for rolling a failed toggle back against whatever
	// other toggles have landed since it started.
	const listRef = useRef( null );
	listRef.current = list;

	// Toggles in flight. A response only reflects the toggles saved before it,
	// so while others are still pending the optimistic list is the newer one,
	// and the server's list is only taken once the last of them lands.
	const inFlight = useRef( 0 );

	useEffect( () => {
		apiFetch( { path: checkInPath( eventId, '', recurrenceId ) } )
			.then( setList )
			.catch( ( error ) => {
				setLoadError( error?.message || __( 'The attendee list could not be loaded.', 'wordcamporg' ) );
			} );
	}, [ eventId, recurrenceId ] );

	const toggle = ( attendee, checkedIn ) => {
		setNotice( null );
		setList( ( current ) => withCheckIn( current, attendee.commentId, checkedIn ) );
		setPending( ( current ) => [ ...current, attendee.commentId ] );
		inFlight.current++;

		apiFetch( {
			path: checkInPath( eventId, `/${ attendee.commentId }`, recurrenceId ),
			method: 'POST',
			data: { checked_in: checkedIn },
		} )
			.then( ( response ) => {
				if ( 1 === inFlight.current ) {
					setList( response );
				}
			} )
			.catch( ( error ) => {
				setList( withCheckIn( listRef.current, attendee.commentId, ! checkedIn ) );
				setNotice( {
					status: 'error',
					message: sprintf(
						/* translators: 1: attendee name, 2: error message. */
						__( 'Could not update %1$s: %2$s', 'wordcamporg' ),
						attendee.name,
						error?.message || __( 'Please try again.', 'wordcamporg' )
					),
				} );
			} )
			.finally( () => {
				inFlight.current--;
				setPending( ( current ) => current.filter( ( id ) => id !== attendee.commentId ) );
			} );
	};

	const addWalkIn = ( event ) => {
		event.preventDefault();
		setNotice( null );
		setAddingWalkIn( true );

		apiFetch( {
			path: checkInPath( eventId, '', recurrenceId, 'walk-in' ),
			method: 'POST',
			data: { login: walkIn.trim() },
		} )
			.then( ( response ) => {
				setList( response );
				setWalkIn( '' );
				setNotice( { status: 'success', message: response.message } );
			} )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message: error?.message || __( 'The walk-in could not be added.', 'wordcamporg' ),
				} );
			} )
			.finally( () => setAddingWalkIn( false ) );
	};

	// Sends the new total rather than the step, so a retried request can't
	// count the same people twice.
	const changeWalkIns = ( step ) => {
		const count = stepWalkInCount( list.walkInsWithoutAccount, step );

		setNotice( null );
		setSavingWalkIns( true );

		apiFetch( {
			path: checkInPath( eventId, '', recurrenceId, 'walk-in-count' ),
			method: 'POST',
			data: { count },
		} )
			.then( setList )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message: error?.message || __( 'The walk-in count could not be saved.', 'wordcamporg' ),
				} );
			} )
			.finally( () => setSavingWalkIns( false ) );
	};

	const renderWalkInCount = () => {
		const count = list.walkInsWithoutAccount || 0;

		return h(
			'div',
			{
				className: 'wporg-groups-check-in-modal__walk-in-count',
				role: 'group',
				'aria-labelledby': 'wporg-groups-check-in-walk-in-count-label',
			},
			h(
				'div',
				{ className: 'wporg-groups-check-in-modal__walk-in-count-text' },
				h(
					'span',
					{
						id: 'wporg-groups-check-in-walk-in-count-label',
						className: 'wporg-groups-check-in-modal__walk-in-count-label',
					},
					__( 'Walk-ins without an account', 'wordcamporg' )
				),
				h(
					'span',
					{ className: 'wporg-groups-check-in-modal__walk-in-count-help' },
					__( 'Only the number is kept, no names.', 'wordcamporg' )
				)
			),
			h(
				'div',
				{ className: 'wporg-groups-check-in-modal__stepper' },
				h(
					Button,
					{
						variant: 'secondary',
						label: __( 'One fewer walk-in', 'wordcamporg' ),
						onClick: () => changeWalkIns( -1 ),
						// Stay focusable while saving, so a keyboard user pressing
						// again isn't dropped back to the page.
						disabled: savingWalkIns || 0 === count,
						accessibleWhenDisabled: true,
						__next40pxDefaultSize: true,
					},
					'\u2212'
				),
				h( 'span', { className: 'wporg-groups-check-in-modal__stepper-value' }, count ),
				h(
					Button,
					{
						variant: 'secondary',
						label: __( 'One more walk-in', 'wordcamporg' ),
						onClick: () => changeWalkIns( 1 ),
						disabled: savingWalkIns,
						accessibleWhenDisabled: true,
						isBusy: savingWalkIns,
						__next40pxDefaultSize: true,
					},
					'+'
				)
			)
		);
	};

	const renderList = () => {
		if ( loadError ) {
			return h( Notice, { status: 'error', isDismissible: false }, loadError );
		}

		if ( ! list ) {
			return h(
				'p',
				{ className: 'wporg-groups-check-in-modal__loading' },
				h( Spinner ),
				__( 'Loading attendees…', 'wordcamporg' )
			);
		}

		if ( ! list.attendees.length ) {
			return h( 'p', {}, __( 'Nobody has RSVP’d yet. Add walk-ins above.', 'wordcamporg' ) );
		}

		const visible = filterAttendees( list.attendees, filter );

		return h(
			'div',
			{ className: 'wporg-groups-check-in-modal__list' },
			h( TextControl, {
				label: __( 'Find an attendee', 'wordcamporg' ),
				type: 'search',
				value: filter,
				onChange: setFilter,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			visible.length
				? h(
						'ul',
						{ className: 'wporg-groups-check-in-modal__attendees' },
						visible.map( ( attendee ) =>
							h(
								'li',
								{ key: attendee.commentId, className: 'wporg-groups-check-in-modal__attendee' },
								attendee.photo &&
									h( 'img', {
										src: attendee.photo,
										alt: '',
										width: 32,
										height: 32,
										className: 'wporg-groups-check-in-modal__avatar',
									} ),
								h( CheckboxControl, {
									label: attendee.login
										? `${ attendee.name } (@${ attendee.login })`
										: attendee.name,
									help:
										'waiting_list' === attendee.status
											? __( 'Waiting list', 'wordcamporg' )
											: undefined,
									checked: attendee.checkedIn,
									disabled: pending.includes( attendee.commentId ),
									onChange: ( checked ) => toggle( attendee, checked ),
									__nextHasNoMarginBottom: true,
								} )
							)
						)
				  )
				: h( 'p', {}, __( 'No attendees match.', 'wordcamporg' ) )
		);
	};

	return h(
		Modal,
		{
			title: __( 'Check in attendees', 'wordcamporg' ),
			onRequestClose: onClose,
			className: 'wporg-groups-modal-accent wporg-groups-check-in-modal',
		},
		h(
			'div',
			{ className: 'wporg-groups-check-in-modal__body' },
			list &&
				h(
					'p',
					{ className: 'wporg-groups-check-in-modal__count', role: 'status', 'aria-live': 'polite' },
					list.walkInsWithoutAccount
						? sprintf(
								/* translators: 1: checked in, 2: attendees, 3: walk-ins without an account. */
								_n(
									'%1$d of %2$d checked in, plus %3$d walk-in without an account',
									'%1$d of %2$d checked in, plus %3$d walk-ins without an account',
									list.walkInsWithoutAccount,
									'wordcamporg'
								),
								list.checkedInCount,
								list.attendees.length,
								list.walkInsWithoutAccount
						  )
						: sprintf(
								/* translators: 1: number checked in, 2: number of attendees. */
								__( '%1$d of %2$d checked in', 'wordcamporg' ),
								list.checkedInCount,
								list.attendees.length
						  )
				),
			notice && h( Notice, { status: notice.status, isDismissible: false }, notice.message ),
			h(
				'form',
				{ onSubmit: addWalkIn, className: 'wporg-groups-check-in-modal__walk-in' },
				h( TextControl, {
					label: __( 'Add a walk-in', 'wordcamporg' ),
					help: __( 'Their WordPress.org username.', 'wordcamporg' ),
					value: walkIn,
					onChange: setWalkIn,
					autoComplete: 'off',
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				h(
					Button,
					{
						variant: 'secondary',
						type: 'submit',
						isBusy: addingWalkIn,
						disabled: addingWalkIn || ! walkIn.trim(),
						__next40pxDefaultSize: true,
					},
					__( 'Check in', 'wordcamporg' )
				)
			),
			list && renderWalkInCount(),
			renderList(),
			h(
				'div',
				{ className: 'wporg-groups-check-in-modal__actions' },
				h( Button, { variant: 'primary', onClick: onClose }, __( 'Done', 'wordcamporg' ) )
			)
		)
	);
}

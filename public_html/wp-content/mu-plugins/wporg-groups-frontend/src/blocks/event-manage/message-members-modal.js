/**
 * Modal for sending an event-related message to all group members.
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, TextareaControl } from '@wordpress/components';
import { createElement as h, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function MessageMembersModal( { eventId, recipientMode, onClose } ) {
	const isAllMembers = 'message-all' === recipientMode;
	const [ message, setMessage ] = useState( '' );
	const [ recipients, setRecipients ] = useState( {
		attending: true,
		waiting_list: false,
		not_attending: false,
	} );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ sent, setSent ] = useState( false );
	const hasRecipients =
		isAllMembers || recipients.attending || recipients.waiting_list || recipients.not_attending;

	const updateRecipient = ( status, checked ) => {
		setRecipients( ( current ) => ( {
			...current,
			[ status ]: checked,
		} ) );
	};

	const onSubmit = ( event ) => {
		event.preventDefault();
		setSending( true );
		setError( '' );

		apiFetch( {
			path: '/gatherpress/v1/event/email',
			method: 'POST',
			data: {
				post_id: eventId,
				message,
				send: {
					all: isAllMembers,
					attending: ! isAllMembers && recipients.attending,
					waiting_list: ! isAllMembers && recipients.waiting_list,
					not_attending: ! isAllMembers && recipients.not_attending,
				},
			},
		} )
			.then( ( response ) => {
				if ( ! response?.success ) {
					throw new Error( __( 'The message could not be scheduled.', 'wporg-groups-frontend' ) );
				}

				setSent( true );
				setSending( false );
			} )
			.catch( ( requestError ) => {
				setSending( false );
				setError(
					requestError?.message || __( 'The message could not be scheduled.', 'wporg-groups-frontend' )
				);
			} );
	};

	return h(
		Modal,
		{
			title: isAllMembers
				? __( 'Message all members', 'wporg-groups-frontend' )
				: __( 'Message event attendees', 'wporg-groups-frontend' ),
			onRequestClose: onClose,
			className: 'wporg-groups-message-modal',
			shouldCloseOnClickOutside: false,
		},
		sent
			? h(
					'div',
					{ className: 'wporg-groups-message-modal__success' },
					h(
						Notice,
						{ status: 'success', isDismissible: false },
						__( 'Your message has been scheduled for delivery.', 'wporg-groups-frontend' )
					),
					h( Button, { variant: 'primary', onClick: onClose }, __( 'Close', 'wporg-groups-frontend' ) )
			  )
			: h(
					'form',
					{ onSubmit, className: 'wporg-groups-message-modal__form' },
					error && h( Notice, { status: 'error', isDismissible: false }, error ),
					h(
						'p',
						{},
						isAllMembers
							? __(
									'This message will be emailed to every opted-in member of this group.',
									'wporg-groups-frontend'
							  )
							: __(
									'Choose which RSVP groups should receive this message.',
									'wporg-groups-frontend'
							  )
					),
					! isAllMembers &&
						h(
							'fieldset',
							{ className: 'wporg-groups-message-modal__recipients' },
							h( 'legend', {}, __( 'Recipients', 'wporg-groups-frontend' ) ),
							h( CheckboxControl, {
								label: __( 'Attending', 'wporg-groups-frontend' ),
								checked: recipients.attending,
								onChange: ( checked ) => updateRecipient( 'attending', checked ),
								__nextHasNoMarginBottom: true,
							} ),
							h( CheckboxControl, {
								label: __( 'Waiting list', 'wporg-groups-frontend' ),
								checked: recipients.waiting_list,
								onChange: ( checked ) => updateRecipient( 'waiting_list', checked ),
								__nextHasNoMarginBottom: true,
							} ),
							h( CheckboxControl, {
								label: __( 'Not attending', 'wporg-groups-frontend' ),
								checked: recipients.not_attending,
								onChange: ( checked ) => updateRecipient( 'not_attending', checked ),
								__nextHasNoMarginBottom: true,
							} )
						),
					! hasRecipients &&
						h(
							Notice,
							{ status: 'warning', isDismissible: false },
							__( 'Select at least one RSVP group.', 'wporg-groups-frontend' )
						),
					h( TextareaControl, {
						label: __( 'Message', 'wporg-groups-frontend' ),
						value: message,
						onChange: setMessage,
						required: true,
						rows: 8,
						__nextHasNoMarginBottom: true,
					} ),
					h(
						'div',
						{ className: 'wporg-groups-message-modal__actions' },
						h(
							Button,
							{
								variant: 'tertiary',
								onClick: onClose,
								disabled: sending,
							},
							__( 'Cancel', 'wporg-groups-frontend' )
						),
						h(
							Button,
							{
								variant: 'primary',
								type: 'submit',
								isBusy: sending,
								disabled: sending || ! message.trim() || ! hasRecipients,
							},
							__( 'Send message', 'wporg-groups-frontend' )
						)
					)
			  )
	);
}

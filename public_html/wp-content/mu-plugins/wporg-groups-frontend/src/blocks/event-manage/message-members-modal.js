/**
 * Modal for messaging group members: about an event, or (`message-group`) a
 * plain group-wide message with no event attached (#2409).
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, TextareaControl, TextControl } from '@wordpress/components';
import { createElement as h, useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';

export default function MessageMembersModal( { eventId, recipientMode, onClose } ) {
	const isGroupMessage = 'message-group' === recipientMode;
	// Every mode but attendees goes to all opted-in members, with no RSVP picker.
	const isAllMembers = 'message-attendees' !== recipientMode;
	const descriptions = {
		'message-group': __(
			'This message will be emailed to every member of this group who has event emails turned on. It isn’t linked to any event.',
			'wordcamporg'
		),
		'message-all': __(
			'This message will be emailed to every opted-in member of this group.',
			'wordcamporg'
		),
		'message-attendees': __(
			'This message will be emailed to the opted-in attendees in the RSVP groups you choose.',
			'wordcamporg'
		),
	};
	const [ subject, setSubject ] = useState( '' );
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

		const request = isGroupMessage
			? {
					path: '/wporg-groups/v1/group-message',
					method: 'POST',
					data: { subject, message },
			  }
			: {
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
			  };

		apiFetch( request )
			.then( ( response ) => {
				if ( ! response?.success ) {
					throw new Error( __( 'The message could not be scheduled.', 'wordcamporg' ) );
				}

				setSent( true );
				setSending( false );
			} )
			.catch( ( requestError ) => {
				setSending( false );
				setError(
					requestError?.message || __( 'The message could not be scheduled.', 'wordcamporg' )
				);
			} );
	};

	return h(
		Modal,
		{
			title: isAllMembers
				? __( 'Message all members', 'wordcamporg' )
				: __( 'Message event attendees', 'wordcamporg' ),
			onRequestClose: onClose,
			className: 'wporg-groups-modal-accent wporg-groups-message-modal',
			shouldCloseOnClickOutside: false,
		},
		sent
			? h(
					'div',
					{ className: 'wporg-groups-message-modal__success' },
					h(
						Notice,
						{ status: 'success', isDismissible: false },
						__( 'Your message will be emailed shortly.', 'wordcamporg' )
					),
					h( Button, { variant: 'primary', onClick: onClose }, __( 'Close', 'wordcamporg' ) )
			  )
			: h(
					'form',
					{ onSubmit, className: 'wporg-groups-message-modal__form' },
					error && h( Notice, { status: 'error', isDismissible: false }, error ),
					h( 'p', {}, descriptions[ recipientMode ] ),
					! isAllMembers &&
						h(
							'fieldset',
							{ className: 'wporg-groups-message-modal__recipients' },
							h( 'legend', {}, __( 'Recipients', 'wordcamporg' ) ),
							h( CheckboxControl, {
								label: __( 'Attending', 'wordcamporg' ),
								checked: recipients.attending,
								onChange: ( checked ) => updateRecipient( 'attending', checked ),
								__nextHasNoMarginBottom: true,
							} ),
							h( CheckboxControl, {
								label: __( 'Waiting list', 'wordcamporg' ),
								checked: recipients.waiting_list,
								onChange: ( checked ) => updateRecipient( 'waiting_list', checked ),
								__nextHasNoMarginBottom: true,
							} ),
							h( CheckboxControl, {
								label: __( 'Not attending', 'wordcamporg' ),
								checked: recipients.not_attending,
								onChange: ( checked ) => updateRecipient( 'not_attending', checked ),
								__nextHasNoMarginBottom: true,
							} )
						),
					! hasRecipients &&
						h(
							Notice,
							{ status: 'warning', isDismissible: false },
							__( 'Select at least one RSVP group.', 'wordcamporg' )
						),
					isGroupMessage &&
						h( TextControl, {
							label: __( 'Subject', 'wordcamporg' ),
							value: subject,
							onChange: setSubject,
							required: true,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
					h( TextareaControl, {
						label: __( 'Message', 'wordcamporg' ),
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
							_x( 'Cancel', 'abort current action', 'wordcamporg' )
						),
						h(
							Button,
							{
								variant: 'primary',
								type: 'submit',
								isBusy: sending,
								disabled:
									sending ||
									! message.trim() ||
									! hasRecipients ||
									( isGroupMessage && ! subject.trim() ),
							},
							__( 'Send message', 'wordcamporg' )
						)
					)
			  )
	);
}

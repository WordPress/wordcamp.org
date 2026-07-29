/**
 * Modal for sending an event-related message to all group members.
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import { createElement as h, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function MessageMembersModal( { eventId, onClose } ) {
	const [ message, setMessage ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ sent, setSent ] = useState( false );

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
					all: true,
					attending: false,
					waiting_list: false,
					not_attending: false,
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
			title: __( 'Message all members', 'wporg-groups-frontend' ),
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
						__(
							'This message will be emailed to every opted-in member of this group.',
							'wporg-groups-frontend'
						)
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
								disabled: sending || ! message.trim(),
							},
							__( 'Send message', 'wporg-groups-frontend' )
						)
					)
			  )
	);
}

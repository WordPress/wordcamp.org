/**
 * Confirms removing a member, with the option to ban them too (#2395).
 *
 * @package WordCamp\Groups\Frontend
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice } from '@wordpress/components';
import { createElement as h, useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

export default function RemoveMemberModal( { member, onClose, onRemoved } ) {
	const [ ban, setBan ] = useState( false );
	const [ removing, setRemoving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const onSubmit = ( event ) => {
		event.preventDefault();
		setRemoving( true );
		setError( '' );

		apiFetch( {
			path: `/wporg-groups/v1/members/${ member.id }${ ban ? '?ban=1' : '' }`,
			method: 'DELETE',
		} )
			.then( ( response ) => onRemoved( member, !! response?.banned ) )
			.catch( ( requestError ) => {
				setRemoving( false );
				setError( requestError?.message || __( 'Could not remove member.', 'wordcamporg' ) );
			} );
	};

	return h(
		Modal,
		{
			title: sprintf(
				/* translators: %s: member name. */
				__( 'Remove %s', 'wordcamporg' ),
				member.name
			),
			onRequestClose: removing ? () => {} : onClose,
			className: 'wporg-groups-modal-accent wporg-groups-message-modal',
			shouldCloseOnClickOutside: false,
		},
		h(
			'form',
			{ onSubmit, className: 'wporg-groups-message-modal__form' },
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			h(
				'p',
				{},
				sprintf(
					/* translators: %s: member name. */
					__( 'Remove %s from this group? Their RSVPs to upcoming events will be cancelled.', 'wordcamporg' ),
					member.name
				)
			),
			h( CheckboxControl, {
				label: __( 'Also ban them from this group', 'wordcamporg' ),
				help: __(
					"They won't be able to join again or RSVP to this group's events until an organizer lifts the ban.",
					'wordcamporg'
				),
				checked: ban,
				onChange: setBan,
				disabled: removing,
				__nextHasNoMarginBottom: true,
			} ),
			h(
				'div',
				{ className: 'wporg-groups-message-modal__actions' },
				h(
					Button,
					{ variant: 'tertiary', onClick: onClose, disabled: removing },
					_x( 'Cancel', 'abort current action', 'wordcamporg' )
				),
				h(
					Button,
					{
						variant: 'primary',
						isDestructive: true,
						type: 'submit',
						isBusy: removing,
						disabled: removing,
					},
					ban ? __( 'Remove and ban', 'wordcamporg' ) : __( 'Remove', 'wordcamporg' )
				)
			)
		)
	);
}

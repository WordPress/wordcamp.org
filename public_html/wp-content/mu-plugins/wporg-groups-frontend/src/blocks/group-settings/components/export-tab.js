/**
 * Group Settings — Export tab.
 *
 * Downloads the group's event + RSVP history as CSV or JSON. Access is
 * enforced server-side; anyone who can open this modal is an Organiser.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement as h, useState, useCallback } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export default function ExportTab() {
	const [ downloading, setDownloading ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );

	const downloadExport = useCallback( async ( format ) => {
		setDownloading( format );
		setNotice( '' );

		try {
			const response = await apiFetch( {
				path: `/wporg-groups/v1/export?format=${ format }`,
				parse: false,
			} );
			const blob = await response.blob();

			const disposition = response.headers.get( 'Content-Disposition' ) || '';
			const match = disposition.match( /filename="?([^";]+)"?/ );
			const filename = match ? match[ 1 ] : `group-events-export.${ format }`;

			const url = window.URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			document.body.appendChild( link );
			link.click();
			link.remove();
			window.URL.revokeObjectURL( url );
		} catch ( err ) {
			setNotice( err.message || __( 'Export failed.', 'wporg-groups-frontend' ) );
		} finally {
			setDownloading( '' );
		}
	}, [] );

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h( Notice, { status: 'error', isDismissible: true, onDismiss: () => setNotice( '' ) }, notice ),
		h( 'p', {},
			__(
				'Download this group’s full event history, including the RSVP breakdown for every event. Anonymous RSVPs are included as a non-identifying token instead of the member’s name.',
				'wporg-groups-frontend'
			)
		),
		h( 'p', {},
			__(
				'CSV opens in any spreadsheet, with one row per RSVP. JSON groups the RSVPs under each event, for programmatic use.',
				'wporg-groups-frontend'
			)
		),
		h(
			'div',
			{ className: 'wporg-export-tab__buttons' },
			h(
				Button,
				{
					variant: 'primary',
					isBusy: downloading === 'csv',
					disabled: !! downloading,
					onClick: () => downloadExport( 'csv' ),
				},
				__( 'Download CSV', 'wporg-groups-frontend' )
			),
			h(
				Button,
				{
					variant: 'secondary',
					isBusy: downloading === 'json',
					disabled: !! downloading,
					onClick: () => downloadExport( 'json' ),
				},
				__( 'Download JSON', 'wporg-groups-frontend' )
			)
		)
	);
}

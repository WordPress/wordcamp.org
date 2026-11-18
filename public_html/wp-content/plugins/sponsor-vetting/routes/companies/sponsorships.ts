/**
 * What a vetter does to a sponsorship, wherever it's shown.
 */

import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { ANY, SPONSORSHIP } from './entities';
import type { Run } from './hooks';
import type { SponsorshipDetails } from './types';

/**
 * The sponsorship a change is made to, with what the change needs of it.
 */
type Target< Key extends keyof SponsorshipDetails = never > = Pick< SponsorshipDetails, 'id' | Key >;

/**
 * Names the change of a sponsorship to a status, for the control that makes it.
 *
 * @param status The status it changes to.
 * @param labels The statuses' labels, keyed by status.
 * @return What the change is called.
 */
export function getStatusChangeLabel( status: string, labels: Record< string, string > ): string {
	if ( status === 'svs-canceled' ) {
		return __( 'Cancel sponsorship', 'wordcamporg' );
	}

	if ( status === 'svs-pending' ) {
		return __( 'Reopen sponsorship', 'wordcamporg' );
	}

	/* translators: %s: Status, like "Not Approved". */
	return sprintf( __( 'Mark as %s', 'wordcamporg' ), labels[ status ] ?? status );
}

/**
 * Says what a change of a sponsorship to a status comes to, where its name doesn't.
 *
 * @param status The status it changes to.
 * @return What it does, if there's something to say.
 */
export function getStatusChangeInfo( status: string ): string | undefined {
	return status === 'svs-canceled'
		? __( 'The event sees it as Canceled, and its invoices can’t be approved.', 'wordcamporg' )
		: undefined;
}

/**
 * Counts how many of some sponsorships are approved now, and how many of those still wait for the approval email.
 *
 * Asked of the server, since what core-data holds is from before the change that approved them.
 *
 * @param ids The sponsorships.
 * @return The two counts.
 */
export async function countApprovals( ids: number[] ): Promise< { approved: number; unsent: number } > {
	const params = new URLSearchParams( {
		include: ids.join( ',' ),
		status: ANY,
		per_page: '100',
		_fields: 'id,status,needs_approval_email',
	} );
	const records = await apiFetch< Pick< SponsorshipDetails, 'status' | 'needs_approval_email' >[] >( {
		path: `/wp/v2/sv-sponsorships?${ params }`,
	} );
	const approved = records.filter( ( { status } ) => status === 'svs-approved' );

	return {
		approved: approved.length,
		unsent: approved.filter( ( record ) => record.needs_approval_email ).length,
	};
}

/**
 * Gives what a vetter does to a sponsorship, wherever it's shown.
 *
 * Each change runs as a task of the caller's, named after the change and the sponsorship, so the control that
 * started it can show that it's busy.
 *
 * @param run Runs a change.
 * @return The changes. Each resolves to whether it succeeded.
 */
export function useSponsorshipChanges( run: Run ) {
	const { deleteEntityRecord, saveEntityRecord } = useDispatch( coreStore );
	const write = ( id: number, values: Record< string, unknown > ) =>
		saveEntityRecord(
			'postType',
			SPONSORSHIP,
			{ id, ...values },
			{ throwOnError: true }
		) as Promise< SponsorshipDetails >;

	return {
		approve: ( { id, approvable, domain }: Target< 'approvable' | 'domain' > ) =>
			run( `approve-${ id }`, async () => {
				// The approval is for the website the vetter was shown, so the server refuses another.
				const approved = await write( id, {
					status: 'svs-approved',
					good_faith: approvable === 'good_faith',
					shown_domain: domain,
				} );

				if ( approved.needs_approval_email ) {
					throw new Error(
						__(
							'The sponsorship was approved, but the approval email wasn’t sent (see history). Send it again from here.',
							'wordcamporg'
						)
					);
				}

				return __( 'The sponsorship was approved.', 'wordcamporg' );
			} ),

		setStatus: ( { id, status: shown }: Target< 'status' >, status: string, label: string ) =>
			run( `status-${ id }`, async () => {
				// The change is from the status the vetter was shown, so the server refuses it from another.
				await write( id, { status, original_status: shown } );

				/* translators: %s: Status, like "Canceled". */
				return sprintf( __( 'The sponsorship is now %s.', 'wordcamporg' ), label );
			} ),

		confirmWebsite: ( { id, domain }: Target< 'domain' > ) =>
			run( `confirm-${ id }`, async () => {
				await write( id, { approved_domain: domain } );

				return __( 'The new website was confirmed.', 'wordcamporg' );
			} ),

		sendApprovalEmail: ( { id }: Target ) =>
			run( `send-${ id }`, async () => {
				const { message } = await apiFetch< { message: string } >( {
					path: `/sponsor-vetting/v1/sponsorships/${ id }/approval-email`,
					method: 'POST',
				} );

				return message;
			} ),

		link: ( { id, status }: Target< 'status' >, company: number ) =>
			run( `link-${ id }`, async () => {
				const linked = await write( id, { company } );

				// A pending sponsorship is approved as soon as it belongs to a vetted company.
				if ( status !== 'svs-pending' || linked.status !== 'svs-approved' ) {
					return __( 'The sponsorship was linked to the company.', 'wordcamporg' );
				}

				return linked.needs_approval_email
					? __(
							'The sponsorship was approved automatically: its company is vetted. The approval email wasn’t sent (see history); send it again from here.',
							'wordcamporg'
					  )
					: __( 'The sponsorship was approved automatically: its company is vetted.', 'wordcamporg' );
			} ),

		trash: ( { id }: Target ) =>
			run( `trash-${ id }`, async () => {
				await deleteEntityRecord( 'postType', SPONSORSHIP, id, {}, { throwOnError: true } );

				return __( 'Sponsorship moved to the Trash.', 'wordcamporg' );
			} ),
	};
}

export type SponsorshipChanges = ReturnType< typeof useSponsorshipChanges >;

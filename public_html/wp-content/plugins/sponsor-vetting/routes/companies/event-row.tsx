/**
 * One of a company's events, with what a vetter does about it.
 */

import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';
import { Button, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import { formatDay, formatDistance, formatTime, isEventUnderWay } from './dates';
import { StatusBadge, getApprovalLabel } from './fields';
import { Hint } from './canvas-parts';
import { getStatusChangeInfo, getStatusChangeLabel } from './sponsorships';
import type { SponsorshipChanges } from './sponsorships';
import type { CompanySponsorship, EventActions } from './types';

type EventRowProps = {
	sponsorship: CompanySponsorship;
	actions?: EventActions;
	isLoading: boolean;
	isMarked: boolean;
	labels: Record< string, string >;
	today: string;
	running: string | undefined;
	changes: SponsorshipChanges;
	onMove: () => void;
};

/**
 * Opens an address in a new tab, for the menu's items that lead elsewhere.
 *
 * @param url The address.
 */
function openInNewTab( url: string ): void {
	window.open( url, '_blank', 'noopener' );
}

/**
 * One of a company's events: when it is, how its approval stands, and what a vetter does about it, which is read
 * after the event itself.
 *
 * @param props             Props.
 * @param props.sponsorship The company's sponsorship of the event.
 * @param props.actions     What a vetter can do about it, once that's been read.
 * @param props.isLoading   Whether that's still being read.
 * @param props.isMarked    Whether it's the event the company was opened from.
 * @param props.labels      The statuses' labels, keyed by status.
 * @param props.today       Today, as `Y-m-d`.
 * @param props.running     The name of the change that's running, if any.
 * @param props.changes     What a vetter does to a sponsorship.
 * @param props.onMove      Asks which company to move it to.
 * @return The event's row, with a notice under it when its website changed after approval.
 */
export function EventRow( {
	sponsorship,
	actions,
	isLoading,
	isMarked,
	labels,
	today,
	running,
	changes,
	onMove,
}: EventRowProps ) {
	const { id, event, status } = sponsorship;
	const sponsor = actions?.sponsor;
	const emails = ( actions?.conversations ?? [] ).filter( ( { type } ) => type === 'approval' );
	// Approving has a button of its own.
	const others = actions?.allowed_statuses ?? [];

	return (
		<li className={ isMarked ? 'sponsor-vetting__event is-marked' : 'sponsor-vetting__event' }>
			<Text render={ <div /> } className="sponsor-vetting__event-name">
				{ event.name }
			</Text>
			<div className="sponsor-vetting__event-date">
				<Text render={ <div /> }>{ formatDay( event.date ) || __( 'No date yet', 'wordcamporg' ) }</Text>
				<Hint>
					{ isEventUnderWay( event, today )
						? __( 'Under way', 'wordcamporg' )
						: formatDistance( event.date, today ) }
				</Hint>
			</div>
			<span title={ ( status === 'svs-approved' && sponsorship.approved ) || undefined }>
				<StatusBadge status={ status } label={ getApprovalLabel( sponsorship, labels ) } />
			</span>
			<Stack
				direction="row"
				align="center"
				justify="end"
				gap="xs"
				className="sponsor-vetting__event-actions"
			>
				{ isLoading && ! actions && <Spinner /> }
				{ actions?.approvable && (
					<Button
						size="compact"
						variant={ actions.approvable === 'approve' ? 'solid' : 'outline' }
						loading={ running === `approve-${ id }` }
						onClick={ () => changes.approve( actions ) }
					>
						{ actions.approvable === 'approve'
							? __( 'Approve', 'wordcamporg' )
							: __( 'Approve in Good Faith', 'wordcamporg' ) }
					</Button>
				) }
				{ actions?.needs_approval_email && (
					<Button
						size="compact"
						variant="outline"
						loading={ running === `send-${ id }` }
						onClick={ () => changes.sendApprovalEmail( sponsorship ) }
					>
						{ __( 'Send approval email', 'wordcamporg' ) }
					</Button>
				) }
				{ actions && (
					<DropdownMenu
						icon={ moreVertical }
						/* translators: %s: Event name. */
						label={ sprintf( __( 'More actions for %s', 'wordcamporg' ), event.name ) }
						toggleProps={ { size: 'compact' } }
					>
						{ ( { onClose } ) => (
							<>
								<MenuGroup>
									{ others.map( ( next ) => (
										<MenuItem
											key={ next }
											info={ getStatusChangeInfo( next ) }
											onClick={ () => {
												void changes.setStatus(
													sponsorship,
													next,
													labels[ next ] ?? next
												);
												onClose();
											} }
										>
											{ getStatusChangeLabel( next, labels ) }
										</MenuItem>
									) ) }
									{ status !== 'svs-approved' && (
										<MenuItem
											onClick={ () => {
												onMove();
												onClose();
											} }
										>
											{ __( 'Move to another company', 'wordcamporg' ) }
										</MenuItem>
									) }
									<MenuItem
										onClick={ () => {
											void changes.trash( sponsorship );
											onClose();
										} }
									>
										{ __( 'Move to Trash', 'wordcamporg' ) }
									</MenuItem>
								</MenuGroup>
								{ ( sponsor || emails.length > 0 ) && (
									<MenuGroup>
										{ sponsor && (
											<MenuItem onClick={ () => openInNewTab( sponsor.edit_url ) }>
												{ __( 'Open the sponsor on the event site', 'wordcamporg' ) }
											</MenuItem>
										) }
										{ emails.map( ( { url, time } ) => (
											<MenuItem key={ url } onClick={ () => openInNewTab( url ) }>
												{ sprintf(
													/* translators: %s: Date and time. */
													__( 'Approval email of %s', 'wordcamporg' ),
													formatTime( time )
												) }
											</MenuItem>
										) ) }
									</MenuGroup>
								) }
							</>
						) }
					</DropdownMenu>
				) }
			</Stack>
			{ actions?.website_changed && (
				<Notice.Root intent="warning" className="sponsor-vetting__event-notice">
					<Notice.Title>{ __( 'The sponsor’s website changed', 'wordcamporg' ) }</Notice.Title>
					<Notice.Description>
						{ sprintf(
							/* translators: 1: Approved website domain, 2: New website domain. */
							__(
								'The camp changed it from %1$s to %2$s after approval. Invoices can’t be approved until you confirm the new website. If it’s a different company now, cancel the sponsorship instead.',
								'wordcamporg'
							),
							actions.approved_domain || __( 'none', 'wordcamporg' ),
							actions.domain || __( 'none', 'wordcamporg' )
						) }
					</Notice.Description>
					<Notice.Actions>
						<Notice.ActionButton
							loading={ running === `confirm-${ id }` }
							onClick={ () => changes.confirmWebsite( actions ) }
						>
							{ __( 'Confirm new website', 'wordcamporg' ) }
						</Notice.ActionButton>
					</Notice.Actions>
				</Notice.Root>
			) }
		</li>
	);
}

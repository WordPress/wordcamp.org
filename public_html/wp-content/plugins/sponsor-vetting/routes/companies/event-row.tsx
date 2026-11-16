import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';
import { Button, Notice, Stack, Text } from '@wordpress/ui';
import { formatDay, formatDistance, formatTime } from './dates';
import { StatusBadge, getApprovalLabel } from './fields';
import { Hint } from './canvas-parts';
import type { SponsorshipChanges } from './sponsorships';
import type { CompanySponsorship } from './types';

type EventRowProps = {
	sponsorship: CompanySponsorship;
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
 * One of a company's events: when it is, how its approval stands, and what a vetter does about it.
 *
 * @param props             Props.
 * @param props.sponsorship The company's sponsorship of the event.
 * @param props.isMarked    Whether it's the event the company was opened from.
 * @param props.labels      The statuses' labels, keyed by status.
 * @param props.today       Today, as `Y-m-d`.
 * @param props.running     The name of the change that's running, if any.
 * @param props.changes     What a vetter does to a sponsorship.
 * @param props.onMove      Asks which company to move it to.
 * @return The event's row, and one under it when its website changed after approval.
 */
export function EventRow( { sponsorship, isMarked, labels, today, running, changes, onMove }: EventRowProps ) {
	const { id, event, status, approvable, sponsor } = sponsorship;
	const emails = sponsorship.conversations.filter( ( { type } ) => type === 'approval' );
	const canCancel = sponsorship.allowed_statuses.includes( 'svs-canceled' );
	// Approving and canceling have buttons of their own.
	const others = sponsorship.allowed_statuses.filter(
		( next ) => next !== 'svs-approved' && next !== 'svs-canceled'
	);
	const className = isMarked ? 'sponsor-vetting__event is-marked' : 'sponsor-vetting__event';

	return (
		<>
			<tr className={ className }>
				<th scope="row">{ event.name }</th>
				<td>
					<Text render={ <div /> }>
						{ formatDay( event.date ) || __( 'No date yet', 'wordcamporg' ) }
					</Text>
					<Hint>{ formatDistance( event.date, today ) }</Hint>
				</td>
				<td>
					<span title={ sponsorship.approved || undefined }>
						<StatusBadge status={ status } label={ getApprovalLabel( sponsorship, labels ) } />
					</span>
				</td>
				<td>
					<Stack direction="row" align="center" justify="end" gap="xs" wrap="wrap">
						{ approvable && (
							<Button
								size="compact"
								variant={ approvable === 'approve' ? 'solid' : 'outline' }
								loading={ running === `approve-${ id }` }
								onClick={ () => changes.approve( sponsorship ) }
							>
								{ approvable === 'approve'
									? __( 'Approve', 'wordcamporg' )
									: __( 'Approve in Good Faith', 'wordcamporg' ) }
							</Button>
						) }
						{ sponsorship.needs_approval_email && (
							<Button
								size="compact"
								variant="outline"
								loading={ running === `send-${ id }` }
								onClick={ () => changes.sendApprovalEmail( sponsorship ) }
							>
								{ __( 'Send approval email', 'wordcamporg' ) }
							</Button>
						) }
						{ canCancel && (
							<Button
								size="compact"
								variant="minimal"
								tone="neutral"
								loading={ running === `status-${ id }` }
								onClick={ () =>
									changes.setStatus( sponsorship, 'svs-canceled', labels[ 'svs-canceled' ] )
								}
							>
								{ __( 'Cancel', 'wordcamporg' ) }
							</Button>
						) }
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
												onClick={ () => {
													void changes.setStatus(
														sponsorship,
														next,
														labels[ next ] ?? next
													);
													onClose();
												} }
											>
												{ sprintf(
													/* translators: %s: Status, like "Not approved". */
													__( 'Mark as %s', 'wordcamporg' ),
													labels[ next ] ?? next
												) }
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
					</Stack>
				</td>
			</tr>
			{ sponsorship.website_changed && (
				<tr className={ className }>
					<td colSpan={ 4 }>
						<Notice.Root intent="warning">
							<Notice.Title>{ __( 'The sponsor’s website changed', 'wordcamporg' ) }</Notice.Title>
							<Notice.Description>
								{ sprintf(
									/* translators: 1: Approved website domain, 2: New website domain. */
									__(
										'The camp changed it from %1$s to %2$s after approval. Invoices can’t be approved until you confirm the new website. If it’s a different company now, cancel the sponsorship instead.',
										'wordcamporg'
									),
									sponsorship.approved_domain || __( 'none', 'wordcamporg' ),
									sponsorship.domain || __( 'none', 'wordcamporg' )
								) }
							</Notice.Description>
							<Notice.Actions>
								<Notice.ActionButton
									loading={ running === `confirm-${ id }` }
									onClick={ () => changes.confirmWebsite( sponsorship ) }
								>
									{ __( 'Confirm new website', 'wordcamporg' ) }
								</Notice.ActionButton>
							</Notice.Actions>
						</Notice.Root>
					</td>
				</tr>
			) }
		</>
	);
}

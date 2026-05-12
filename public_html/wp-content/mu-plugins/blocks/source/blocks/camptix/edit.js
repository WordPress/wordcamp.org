/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	Disabled,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

const blockData = window.WordCampBlocks?.camptix || {};

export default function CamptixEdit( { attributes, setAttributes } ) {
	const {
		ticketIds, maxTicketsPerOrder, coupon, noTicketsMessage, eventClosedMessage,
		showRemainingTickets, showCouponField,
	} = attributes;
	const blockProps = useBlockProps();
	const allTickets = blockData.tickets || [];
	const hasCoupons = blockData.hasCoupons || false;

	const displayTickets = ticketIds.length > 0
		? allTickets.filter( ( ticket ) => ticketIds.includes( ticket.id ) )
		: allTickets;

	/**
	 * Toggle a ticket ID in the ticketIds array.
	 *
	 * @param {number}  id      Ticket ID.
	 * @param {boolean} checked Whether the ticket is selected.
	 */
	function toggleTicket( id, checked ) {
		if ( checked ) {
			setAttributes( { ticketIds: [ ...ticketIds, id ] } );
		} else {
			setAttributes( { ticketIds: ticketIds.filter( ( ticketId ) => ticketId !== id ) } );
		}
	}

	return (
		<>
			<InspectorControls>
				{ allTickets.length > 0 && (
					<PanelBody title={ __( 'Ticket Selection', 'wordcamporg' ) }>
						<p className="components-base-control__help">
							{ __( 'Select specific tickets to display. Leave all unchecked to show all tickets.', 'wordcamporg' ) }
						</p>
						{ allTickets.map( ( ticket ) => (
							<CheckboxControl
								key={ ticket.id }
								label={ `${ ticket.title } (${ ticket.formattedPrice })` }
								checked={ ticketIds.includes( ticket.id ) }
								onChange={ ( checked ) => toggleTicket( ticket.id, checked ) }
							/>
						) ) }
					</PanelBody>
				) }
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) }>
					<RangeControl
						label={ __( 'Max tickets per order', 'wordcamporg' ) }
						value={ maxTicketsPerOrder }
						onChange={ ( value ) => setAttributes( { maxTicketsPerOrder: value } ) }
						min={ 1 }
						max={ 10 }
					/>
					<ToggleControl
						label={ __( 'Show remaining tickets', 'wordcamporg' ) }
						checked={ showRemainingTickets }
						onChange={ () => setAttributes( { showRemainingTickets: ! showRemainingTickets } ) }
						help={ showRemainingTickets
							? __( 'A "Remaining" column is shown in the ticket table.', 'wordcamporg' )
							: __( 'The "Remaining" column is hidden.', 'wordcamporg' )
						}
					/>
					<SelectControl
						label={ __( 'Coupon field', 'wordcamporg' ) }
						value={ showCouponField }
						options={ [
							{ label: __( 'Auto (show when coupons exist)', 'wordcamporg' ), value: 'auto' },
							{ label: __( 'Always show', 'wordcamporg' ), value: 'show' },
							{ label: __( 'Always hide', 'wordcamporg' ), value: 'hide' },
						] }
						onChange={ ( value ) => setAttributes( { showCouponField: value } ) }
						help={
							'auto' === showCouponField && ! hasCoupons
								? __( 'No active coupons exist — the field will be hidden on the frontend.', 'wordcamporg' )
								: undefined
						}
					/>
					<TextControl
						label={ __( 'Auto-apply coupon code', 'wordcamporg' ) }
						value={ coupon }
						onChange={ ( value ) => setAttributes( { coupon: value } ) }
						help={ __( 'Automatically apply this coupon when the page loads.', 'wordcamporg' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Custom Messages', 'wordcamporg' ) } initialOpen={ false }>
					<TextareaControl
						label={ __( '"No tickets available" message', 'wordcamporg' ) }
						value={ noTicketsMessage }
						onChange={ ( value ) => setAttributes( { noTicketsMessage: value } ) }
						placeholder={ __( 'Sorry, but there are currently no tickets for sale. Please try again later.', 'wordcamporg' ) }
					/>
					<TextareaControl
						label={ __( '"Event closed" message', 'wordcamporg' ) }
						value={ eventClosedMessage }
						onChange={ ( value ) => setAttributes( { eventClosedMessage: value } ) }
						placeholder={ __( 'This event has completed.', 'wordcamporg' ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ displayTickets.length === 0 ? (
					<Placeholder
						label={ __( 'Tickets', 'wordcamporg' ) }
						instructions={ __( 'No tickets have been created yet. Add tickets to see a preview here.', 'wordcamporg' ) }
					/>
				) : (
					<Disabled>
						<table className="tix-tickets-preview">
							<thead>
								<tr>
									<th>{ __( 'Ticket', 'wordcamporg' ) }</th>
									<th>{ __( 'Price', 'wordcamporg' ) }</th>
									{ showRemainingTickets && (
										<th>{ __( 'Remaining', 'wordcamporg' ) }</th>
									) }
									<th>{ __( 'Quantity', 'wordcamporg' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ displayTickets.map( ( ticket ) => (
									<tr key={ ticket.id }>
										<td>{ ticket.title }</td>
										<td>{ ticket.formattedPrice }</td>
										{ showRemainingTickets && (
											<td>—</td>
										) }
										<td>
											<select disabled>
												{ [ ...Array( maxTicketsPerOrder + 1 ).keys() ].map( ( i ) => (
													<option key={ i } value={ i }>{ i }</option>
												) ) }
											</select>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
						{ coupon && (
							<p className="tix-coupon-preview">
								{ __( 'Coupon:', 'wordcamporg' ) } <strong>{ coupon }</strong>
							</p>
						) }
					</Disabled>
				) }
			</div>
		</>
	);
}

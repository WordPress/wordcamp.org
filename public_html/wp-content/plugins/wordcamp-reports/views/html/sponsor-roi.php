<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\HTML\Sponsor_ROI;
defined( 'WPINC' ) || die();

/** @var array $compiled The portfolio map from compile_report_data(). */
?>

<?php if ( empty( $compiled ) ) : ?>
	<p>No sponsors found for the given parameters.</p>
<?php else : ?>
	<?php foreach ( $compiled as $key => $portfolio ) : ?>
		<h3>
			<?php echo esc_html( $portfolio['sponsor_name'] ); ?>
			<?php if ( $portfolio['mes_id'] ) : ?>
				<small>(MES agreement #<?php echo absint( $portfolio['mes_id'] ); ?>)</small>
			<?php else : ?>
				<small>(local sponsorship)</small>
			<?php endif; ?>
		</h3>

		<table class="widefat striped" style="max-width: 700px;">
			<tbody>
			<tr>
				<td>Events sponsored</td>
				<td><?php echo esc_html( number_format_i18n( $portfolio['totals']['camp_count'] ) ); ?></td>
			</tr>
			<tr>
				<td>Total spend (USD, paid invoices)</td>
				<td><?php echo esc_html( number_format_i18n( $portfolio['totals']['spend_usd'], 2 ) ); ?></td>
			</tr>
			<tr>
				<td>Registered attendees reached</td>
				<td><?php echo esc_html( number_format_i18n( $portfolio['totals']['registered'] ) ); ?></td>
			</tr>
			<tr>
				<td>Checked-in attendees reached</td>
				<td>
					<?php echo esc_html( number_format_i18n( $portfolio['totals']['attended'] ) ); ?>
					<?php if ( $portfolio['totals']['unmeasured_camps'] > 0 ) : ?>
						<em>
							— attendance not measured at
							<?php echo absint( $portfolio['totals']['unmeasured_camps'] ); ?>
							of
							<?php echo absint( $portfolio['totals']['camp_count'] ); ?>
							events
						</em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td>Cost per registered attendee</td>
				<td>
					<?php echo null !== $portfolio['ratios']['cost_per_registered'] ? esc_html( number_format_i18n( $portfolio['ratios']['cost_per_registered'], 2 ) ) : '—'; ?>
				</td>
			</tr>
			<tr>
				<td>Cost per checked-in attendee</td>
				<td>
					<?php echo null !== $portfolio['ratios']['cost_per_attended'] ? esc_html( number_format_i18n( $portfolio['ratios']['cost_per_attended'], 2 ) ) : '—'; ?>
				</td>
			</tr>
			</tbody>
		</table>

		<table class="widefat striped" style="max-width: 900px; margin-top: 8px;">
			<thead>
			<tr>
				<th>WordCamp</th>
				<th>Event Date</th>
				<th>Tier</th>
				<th>Spend (USD)</th>
				<th>Registered</th>
				<th>Checked In</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $portfolio['camps'] as $camp ) : ?>
				<tr>
					<td><?php echo esc_html( $camp['wordcamp_name'] ); ?></td>
					<td><?php echo esc_html( $camp['event_date'] ); ?></td>
					<td><?php echo esc_html( $camp['tier'] ); ?></td>
					<td>
						<?php echo esc_html( number_format_i18n( $camp['spend_usd'] ?? 0, 2 ) ); ?>
						<?php if ( empty( $camp['has_invoice'] ) ) : ?>
							<em>(no invoice)</em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $camp['registered'] ) ); ?></td>
					<td>
						<?php if ( ! empty( $camp['attendance_measured'] ) ) : ?>
							<?php echo esc_html( number_format_i18n( $camp['attended'] ) ); ?>
						<?php else : ?>
							not measured
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<p class="description" style="margin-top: 16px;">
		Spend counts paid invoices only (optionally paid + sent), normalized to USD at the event-date exchange rate.
		Registered = published attendee records; Checked In = attendees marked as attended via CampTix check-in.
		"Not measured" means in-platform check-in wasn't used at that event — the attendance there is unknown, not zero.
		Reach numbers are whole-event audiences reached by the sponsor's placement, not per-sponsor interactions.
	</p>
<?php endif; ?>

<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\Sponsor_ROI;

use WordCamp\Reports;
use WordCamp\Reports\Report;

defined( 'WPINC' ) || die();

/** @var string $start_date */
/** @var string $end_date */
/** @var int $mes_id */
/** @var Report\Sponsor_ROI|null $report */
?>

<div class="wrap">
	<h1>
		<a href="<?php echo esc_url( Reports\get_page_url() ); ?>">WordCamp Reports</a>
		&raquo;
		<?php echo esc_html( Report\Sponsor_ROI::$name ); ?>
	</h1>

	<?php echo wp_kses_post( wpautop( Report\Sponsor_ROI::$description ) ); ?>

	<h4>Methodology</h4>

	<?php echo wp_kses_post( wpautop( Report\Sponsor_ROI::$methodology ) ); ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'run-report', Report\Sponsor_ROI::$slug . '-nonce' ); ?>

		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><label for="start-date">Start Date</label></th>
				<td><input type="date" id="start-date" name="start-date" value="<?php echo esc_attr( $start_date ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="end-date">End Date</label></th>
				<td><input type="date" id="end-date" name="end-date" value="<?php echo esc_attr( $end_date ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="mes-id">MES Agreement ID (optional)</label></th>
				<td>
					<input type="number" id="mes-id" name="mes-id" min="0" value="<?php echo esc_attr( $mes_id ?: '' ); ?>" />
					<p class="description">Filter to a single Multi-Event Sponsor agreement. Leave empty for all sponsors.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="refresh">Refresh results</label></th>
				<td><input type="checkbox" id="refresh" name="refresh" value="1" /></td>
			</tr>
			</tbody>
		</table>

		<?php submit_button( 'Show results', 'primary', 'action', false ); ?>
		<?php submit_button( 'Export CSV', 'secondary', 'action', false ); ?>
	</form>

	<?php if ( $report instanceof Report\Sponsor_ROI ) : ?>
		<div class="report-results">
			<?php $report->render_html(); ?>
		</div>
	<?php endif; ?>
</div>

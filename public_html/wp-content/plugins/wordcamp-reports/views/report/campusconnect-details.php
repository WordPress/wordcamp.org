<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\CampusConnect_Details;
defined( 'WPINC' ) || die();

use WordCamp\Reports;
use WordCamp\Reports\Report;

/** @var array $field_defaults */
/** @var string $start_date */
/** @var string $end_date */
?>

<div class="wrap">
	<h1>
		<a href="<?php echo esc_attr( Reports\get_page_url() ); ?>">CampusConnect Reports</a>
		&raquo;
		<?php echo esc_html( Report\CampusConnect_Details::$name ); ?>
	</h1>

	<?php echo wp_kses_post( wpautop( Report\CampusConnect_Details::$description ) ); ?>

	<h4>Methodology</h4>

	<?php echo wp_kses_post( wpautop( Report\CampusConnect_Details::$methodology ) ); ?>

	<form method="post" action="">
		<input type="hidden" name="action" value="run-report" />
		<?php wp_nonce_field( 'run-report', Report\CampusConnect_Details::$slug . '-nonce' ); ?>

		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><label for="start-date">Start Date (optional)</label></th>
				<td><input type="date" id="start-date" name="start-date" value="<?php echo esc_attr( $start_date ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="end-date">End Date (optional)</label></th>
				<td><input type="date" id="end-date" name="end-date" value="<?php echo esc_attr( $end_date ); ?>" /></td>
			</tr>
			</tbody>
		</table>

		<?php Report\CampusConnect_Details::render_available_fields( 'private', $field_defaults ); ?>

		<input type="submit" name="action" class="button button-primary" value="Show Results" formaction="#report-data-table">
		<input type="submit" name="action" class="button button-secondary" value="Export CSV">
	</form>

	<?php if ( $report instanceof Report\CampusConnect_Details ) : ?>
		<div class="report-results">
			<?php $report->render_html(); ?>
		</div>
	<?php endif; ?>
</div>

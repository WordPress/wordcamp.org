<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\WordCamp_Speaker_Feedback;
defined( 'WPINC' ) || die();

use WordCamp\Reports;
use WordCamp\Reports\Report;

/** @var string $start_date */
/** @var string $end_date */
/** @var int $wordcamp_id */
/** @var string $report */
?>

<div class="wrap">
	<h1>
		<a href="<?php echo esc_attr( Reports\get_page_url() ); ?>">WordCamp Reports</a>
		&raquo;
		<?php echo esc_html( Report\WordCamp_Speaker_Feedback::$name ); ?>
	</h1>

	<?php echo wp_kses_post( wpautop( Report\WordCamp_Speaker_Feedback::$description ) ); ?>

	<h4>Methodology</h4>

	<?php echo wp_kses_post( wpautop( Report\WordCamp_Speaker_Feedback::$methodology ) ); ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'run-report', Report\WordCamp_Speaker_Feedback::$slug . '-nonce' ); ?>

		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><label for="start-date">Start Date</label></th>
				<td><input type="date" id="start-date" name="start-date" value="<?php echo esc_attr( $start_date ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="end-date">End Date</label></th>
				<td><input type="date" id="end-date" name="end-date" value="<?php echo esc_attr( $end_date ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="wordcamp-id">WordCamp (optional)</label></th>
				<td><?php echo get_wordcamp_dropdown( 'wordcamp-id', array(), $wordcamp_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="refresh">Refresh results</label></th>
				<td><input type="checkbox" id="refresh" name="refresh" /></td>
			</tr>
			</tbody>
		</table>

		<?php submit_button( 'Export CSV', 'secondary', 'action', false ); ?>
	</form>
</div>

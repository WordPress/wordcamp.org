<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\Payment_Activity;
defined( 'WPINC' ) || die();

use WordCamp\Reports\Report;

/** @var string $year */
/** @var string $period */
/** @var int    $wordcamp_id */
/** @var array  $years */
/** @var array  $quarters */
/** @var array  $months */
/** @var Report\Payment_Activity|null $report */
?>

<div id="<?php echo esc_attr( Report\Payment_Activity::$slug ); ?>-report" class="report-container">
	<p class="report-description">
		<?php echo wp_kses_post( Report\Payment_Activity::$description ); ?>
	</p>

	<form method="get" action="" class="report-form">
		<div class="field_report-year">
			<label for="report-year">Year</label>
			<select id="report-year" name="report-year">
				<?php foreach ( $years as $year_value ) : ?>
					<option value="<?php echo esc_attr( $year_value ); ?>"<?php selected( $year_value, $year ); ?>><?php echo esc_html( $year_value ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="field_period">
			<label for="period">Time Period</label>
			<select id="period" name="period">
				<option value="all"<?php selected( 'all' === $period ); ?>>Entire year</option>
				<?php foreach ( $quarters as $quarter_value => $quarter_label ) : ?>
					<option value="<?php echo esc_attr( $quarter_value ); ?>"<?php selected( $quarter_value, $period ); ?>><?php echo esc_html( $quarter_label ); ?></option>
				<?php endforeach; ?>
				<?php foreach ( $months as $month_value => $month_label ) : ?>
					<option value="<?php echo esc_attr( $month_value ); ?>"<?php selected( $month_value, $period ); ?>><?php echo esc_html( $month_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="field_wordcamp-id">
			<label for="wordcamp-id">WordCamp <span>(optional)</span></label>
			<?php echo get_wordcamp_dropdown( 'wordcamp-id', array(), $wordcamp_id, true ); // phpcs:ignore -- escaped in calling function. ?>
		</div>

		<div class="submit_show-results">
			<?php submit_button( 'Show results', 'primary', 'action', false ); ?>
		</div>
	</form>

	<?php if ( $report instanceof Report\Payment_Activity ) : ?>
		<div class="report-results">
			<?php $report->render_html(); ?>
		</div>
	<?php endif; ?>
</div>

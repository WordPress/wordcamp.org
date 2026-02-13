<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\Meetup_Sponsors;
defined( 'WPINC' ) || die();

use WordCamp\Reports;
use WordCamp\Reports\Report;

?>

<div class="wrap">
	<h1>
		<a href="<?php echo esc_attr( Reports\get_page_url() ); ?>">WordCamp Reports</a>
		&raquo;
		<?php echo esc_html( Report\Meetup_Sponsors::$name ); ?>
	</h1>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wpautop( wp_kses_post( Report\Meetup_Sponsors::$description ) );
	?>

	<h4>Methodology</h4>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wpautop( wp_kses_post( Report\Meetup_Sponsors::$methodology ) );
	?>

	<form method="post" action="">
		<input type="hidden" name="action" value="run-report" />
		<?php wp_nonce_field( 'run-report', Report\Meetup_Sponsors::$slug . '-nonce' ); ?>

		<p>
			<label>
				<input type="checkbox" name="include-network" value="1" <?php checked( ! empty( $plus_network ) ); ?>>
				Include Network-level Sponsors
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="refresh" value="1">
				Refresh data
			</label>
		</p>

		<?php submit_button( 'Show results', 'primary', 'action', false ); ?>
		<?php submit_button( 'Export CSV', 'secondary', 'action', false ); ?>
	</form>

	<?php if ( $report instanceof Report\Meetup_Sponsors ) : ?>
		<div class="report-results">
			<?php $report->render_html(); ?>
		</div>
	<?php endif; ?>
</div>

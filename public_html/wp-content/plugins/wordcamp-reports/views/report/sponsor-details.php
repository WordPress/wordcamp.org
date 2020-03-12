<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\Report\Sponsor_Details;

use WordCamp\Reports;
use WordCamp\Reports\Report;

defined( 'WPINC' ) || die();

/** @var int $wordcamp_id */
/** @var Report\Payment_Activity|null $report */
?>

<div class="wrap">
	<h1>
		<a href="<?php echo esc_attr( Reports\get_page_url() ); ?>">WordCamp Reports</a>
		&raquo;
		<?php echo esc_html( Report\Payment_Activity::$name ); ?>
	</h1>

	<?php echo wp_kses_post( wpautop( Report\Sponsor_Details::$description ) ); ?>

	<h4>Methodology</h4>

	<?php echo wp_kses_post( wpautop( Report\Sponsor_Details::$methodology ) ); ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'run-report', Report\Sponsor_Details::$slug . '-nonce' ); ?>

		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><label for="wordcamp-id">WordCamp</label></th>
				<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The function does escaping.
					echo get_wordcamp_dropdown( 'wordcamp-id', array(), $wordcamp_id );
					?>
				</td>
			</tr>
			</tbody>
		</table>

		<?php submit_button( 'Export CSV', 'primary', 'action', false ); ?>
	</form>
</div>

<?php
	/**
	 * @package WordCamp\Reports
	 */

	namespace WordCamp\Reports\Views\HTML\Meetup_Status;
	defined( 'WPINC' ) || die();

	use DateTime;

	/** @var DateTime $start_date */
	/** @var DateTime $end_date */
	/** @var string $status */
	/** @var array $meetups */
	/** @var array $statuses */
?>

<?php if ( count( $meetups ) ) : ?>
	<h3 id="active-heading">
		<?php if ( $status && $status !== 'any' ) : ?>
			Meetups set to &ldquo;<?php echo esc_html( $statuses[ $status ] ); ?>&rdquo;
		<?php else : ?>
			Meetups activity
		<?php endif; ?>
		<?php if ( $start_date->format( 'Y-m-d' ) === $end_date->format( 'Y-m-d' ) ) : ?>
			on <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?>
		<?php else : ?>
			between <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?> and <?php echo esc_html( $end_date->format( 'M jS, Y' ) ); ?>
		<?php endif; ?>
	</h3>
	<table class="striped widefat but-not-too-wide">
		<tr>
			<td>Meetups</td>
			<td class="number"><?php echo number_format_i18n( count( $meetups ) ); ?></td>
		</tr>
	</table>

	<?php foreach ( $meetups as $meetup ) : ?>
	needs an if (meetup[latest_status])
		<p><strong class="active-camp"><?php echo esc_html( $meetup['name'] ); ?></strong> &ndash; <?php echo esc_html( $statuses[ $meetup['latest_status'] ] ); ?></p>

	1:53
E_NOTICE
Undefined index:
Domain
https://central.wordcamp.org
Page
/reports/meetup-application-status/?report-year=2020&period=all&status=any&action=Show+results
File
/home/wordcamp/public_html/wp-content/plugins/wordcamp-reports/views/html/meetup-status.php:39
Stack Trace
require('wp-blog-header.php'), require_once('wp-includes/template-loader.php'), include('/themes/wordcamp-central-2012/page.php'), the_content, apply_filters('the_content'), WP_Hook->apply_filters, do_shortcode, preg_replace_callback, do_shortcode_tag, WordCamp\Reports\Report\Meetup_Status::handle_shortcode, WordCamp\Reports\Report\Meetup_Status::render_public_page, include('/plugins/wordcamp-reports/views/public/meetup-status.php'), WordCamp\Reports\Report\Meetup_Status->render_html, include('/plugins/wordcamp-reports/views/html/meetup-status.php'), WordCamp\Error_Handling\handle_error, WordCamp\Error_Handling\send_error_to_slack


		<ul class="status-log ul-disc">
			<?php foreach ( $meetup['logs'] as $log ) : ?>
				<li><?php
						echo date( 'Y-m-d', $log['timestamp'] );
						echo ': ';
						echo esc_html( $log['message'] );
					?></li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
<?php endif; ?>

<?php if ( empty( $meetups ) ) : ?>
	<p>
		No data
		<?php if ( $status && $status !== 'any' ) : ?>
			involving &ldquo;<?php echo esc_html( $statuses[ $status ] ); ?>&rdquo;
		<?php endif; ?>
		<?php if ( $start_date->format( 'Y-m-d' ) === $end_date->format( 'Y-m-d' ) ) : ?>
			on <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?>
		<?php else : ?>
			between <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?> and <?php echo esc_html( $end_date->format( 'M jS, Y' ) ); ?>
		<?php endif; ?>
	</p>
<?php endif; ?>

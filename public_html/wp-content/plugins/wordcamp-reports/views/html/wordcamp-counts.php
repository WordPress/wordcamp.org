<?php

/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\HTML\WordCamp_Counts;
defined( 'WPINC' ) || die();

use DateTime;

/** @var array $data */
/** @var DateTime $start_date */
/** @var DateTime $end_date */
/** @var string $statuses */

$gender_legend     = '<span class="description small">Gender: F / M / ?</span>';
$first_time_legend = '<span class="description small">Y / N / ?</span>';

?>

<?php if ( count( $data['wordcamps'] ) ) : ?>
	<h3 id="active-heading">
		Counts for WordCamps occurring
		<?php if ( $start_date->format( 'Y-m-d' ) === $end_date->format( 'Y-m-d' ) ) : ?>
			on <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?>
		<?php else : ?>
			between <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?> and <?php echo esc_html( $end_date->format( 'M jS, Y' ) ); ?>
		<?php endif; ?>
	</h3>

	<table class="striped widefat but-not-too-wide">
		<tr>
			<td>WordCamps</td>
			<td class="number total"><?php echo number_format_i18n( count( $data['wordcamps'] ) ); ?></td>
		</tr>
	</table>

	<h4>Totals, Uniques and First times</h4>

	<table class="striped widefat but-not-too-wide">
		<tr>
			<td>Type</td>
			<td>Total</td>
			<td>Unique</td>
			<td>First time<br><?php echo $first_time_legend; ?></td>
		</tr>
		<tr>
			<td>Registered Attendees</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['attendee'] ); ?></td>
			<td class="number"><?php echo number_format_i18n( $data['uniques']['attendee'] ); ?></td>
			<td class="number">
				<?php echo number_format_i18n( $data['first_times']['attendee']['yes'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['attendee']['no'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['attendee']['unsure'] ); ?>
			</td>
		</tr>
		<tr>
			<td>Organizers</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['organizer'] ); ?></td>
			<td class="number"><?php echo number_format_i18n( $data['uniques']['organizer'] ); ?></td>
			<td class="number">
				<?php echo number_format_i18n( $data['first_times']['organizer']['yes'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['organizer']['no'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['organizer']['unsure'] ); ?>
			</td>
		</tr>
		<tr>
			<td>Sessions</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['session'] ); ?></td>
			<td class="number">n/a</td>
			<td class="number">n/a</td>
		</tr>
		<tr>
			<td>Speakers</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['speaker'] ); ?></td>
			<td class="number"><?php echo number_format_i18n( $data['uniques']['speaker'] ); ?></td>
			<td class="number">
				<?php echo number_format_i18n( $data['first_times']['speaker']['yes'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['speaker']['no'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['speaker']['unsure'] ); ?>
			</td>
		</tr>
		<tr>
			<td>Sponsors</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['sponsor'] ); ?></td>
			<td class="number"><?php echo number_format_i18n( $data['uniques']['sponsor'] ); ?></td>
			<td class="number">
				<?php echo number_format_i18n( $data['first_times']['sponsor']['yes'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['sponsor']['no'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['sponsor']['unsure'] ); ?>
			</td>
		</tr>

		<tr>
			<td>Volunteers</td>
			<td class="number"><?php echo number_format_i18n( $data['totals']['volunteer'] ); ?></td>
			<td class="number"><?php echo number_format_i18n( $data['uniques']['volunteer'] ); ?></td>
			<td class="number">
				<?php echo number_format_i18n( $data['first_times']['volunteer']['yes'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['volunteer']['no'] ); ?>
				/ <?php echo number_format_i18n( $data['first_times']['volunteer']['unsure'] ); ?>
			</td>
		</tr>
	</table>

	<?php if ( ! empty( $data['genders'] ) ) : ?>
		<h4>Estimated Gender Breakdown</h4>

		<table class="striped widefat but-not-too-wide">
			<tr>
				<td>Type</td>
				<td>Total</td>
				<td>Female</td>
				<td>Male</td>
				<td>Unknown</td>
			</tr>
			<tr>
				<td>Registered Attendees</td>
				<td class="number"><?php echo number_format_i18n( $data['totals']['attendee'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['attendee']['female'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['attendee']['male'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['attendee']['unknown'] ); ?></td>
			</tr>
			<tr>
				<td>Organizers</td>
				<td class="number"><?php echo number_format_i18n( $data['totals']['organizer'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['organizer']['female'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['organizer']['male'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['organizer']['unknown'] ); ?></td>
			</tr>
			<tr>
				<td>Speakers</td>
				<td class="number"><?php echo number_format_i18n( $data['totals']['speaker'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['speaker']['female'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['speaker']['male'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['speaker']['unknown'] ); ?></td>
			</tr>

			<tr>
				<td>Volunteers</td>
				<td class="number"><?php echo number_format_i18n( $data['totals']['volunteer'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['volunteer']['female'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['volunteer']['male'] ); ?></td>
				<td class="number"><?php echo number_format_i18n( $data['genders']['volunteer']['unknown'] ); ?></td>
			</tr>
		</table>
	<?php endif; ?>

	<h4>WordCamp Details</h4>

	<table class="striped widefat but-not-too-wide">
		<tr>
			<td>WordCamp</td>
			<td>Date</td>
			<td>Status</td>
			<td>Registered Attendees
				<br>
				<span class="description small">First time: </span>
				<?php echo $first_time_legend; ?>
				<?php if ( ! empty( $data['genders'] ) ) : ?>
					<br />
					<?php echo $gender_legend; ?>
				<?php endif; ?>	
			</td>
			<td>Organizers
				<br>
				<span class="description small">First time: </span>
				<?php echo $first_time_legend; ?>
				<?php if ( ! empty( $data['genders'] ) ) : ?>
					<br />
					<?php echo $gender_legend; ?>
				<?php endif; ?>	
			</td>
			<td>Sessions</td>
			<td>Speakers
				<br>
				<span class="description small">First time: </span>
				<?php echo $first_time_legend; ?>
				<?php if ( ! empty( $data['genders'] ) ) : ?>
					<br />
					<?php echo $gender_legend; ?>
				<?php endif; ?>
			</td>
			<td>Sponsors
				<br>
				<span class="description small">First time: </span>
				<?php echo $first_time_legend; ?>
			</td>
			<td>
				Volunteers
				<br>
				<span class="description small">First time: </span>
				<?php echo $first_time_legend; ?>
				<?php if ( ! empty( $data['genders'] ) ) : ?>
					<br>
					<?php echo $gender_legend; ?>
				<?php endif; ?>
			</td>
		</tr>

		<?php foreach ( $data['wordcamps'] as $event ) : ?>
			<tr>
				<td><a href="<?php echo esc_attr( $event['info']['URL'] ); ?>"><?php echo esc_html( $event['info']['Name'] ); ?></a></td>
				<td><?php echo esc_html( $event['info']['Start Date (YYYY-mm-dd)'] ); ?></td>
				<td><?php echo esc_html( $event['info']['Status'] ); ?></td>

				<td class="number">
					<span class="total">Total: <?php echo number_format_i18n( $event['totals']['attendee'] ); ?></span>
					<br>
					FT: <?php echo number_format_i18n( $event['first_times']['attendee']['yes'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['attendee']['no'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['attendee']['unsure'] ); ?>
					<?php if ( ! empty( $data['genders'] ) ) : ?>
						<br>
						G: <?php echo number_format_i18n( $event['genders']['attendee']['female'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['attendee']['male'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['attendee']['unknown'] ); ?>
					<?php endif; ?>
				</td>

				<td class="number">
					<span class="total">Total: <?php echo number_format_i18n( $event['totals']['organizer'] ); ?></span>
					<br>
					FT: <?php echo number_format_i18n( $event['first_times']['organizer']['yes'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['organizer']['no'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['organizer']['unsure'] ); ?>
					<?php if ( ! empty( $data['genders'] ) ) : ?>
						<br>
						G: <?php echo number_format_i18n( $event['genders']['organizer']['female'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['organizer']['male'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['organizer']['unknown'] ); ?>
					<?php endif; ?>
				</td>

				<td class="number total">
					<?php echo number_format_i18n( $event['totals']['session'] ); ?>
				</td>

				<td class="number">
					<span class="total">Total: <?php echo number_format_i18n( $event['totals']['speaker'] ); ?></span>
					<br>
					FT: <?php echo number_format_i18n( $event['first_times']['speaker']['yes'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['speaker']['no'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['speaker']['unsure'] ); ?>
					<?php if ( ! empty( $data['genders'] ) ) : ?>
						<br>
						G: <?php echo number_format_i18n( $event['genders']['speaker']['female'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['speaker']['male'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['speaker']['unknown'] ); ?>
					<?php endif; ?>
				</td>

				<td class="number">
					<span class="total">Total: <?php echo number_format_i18n( $event['totals']['sponsor'] ); ?></span>
					<br>
					FT: <?php echo number_format_i18n( $event['first_times']['sponsor']['yes'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['sponsor']['no'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['sponsor']['unsure'] ); ?>
				</td>

				<td class="number">
					<span class="total">Total: <?php echo number_format_i18n( $event['totals']['volunteer'] ); ?></span>
					<br>
					FT: <?php echo number_format_i18n( $event['first_times']['volunteer']['yes'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['volunteer']['no'] ); ?>
					/ <?php echo number_format_i18n( $event['first_times']['volunteer']['unsure'] ); ?>
					<?php if ( ! empty( $data['genders'] ) ) : ?>
						<br>
						G: <?php echo number_format_i18n( $event['genders']['volunteer']['female'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['volunteer']['male'] ); ?>
						/ <?php echo number_format_i18n( $event['genders']['volunteer']['unknown'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

<?php else : ?>

	<p>
		No data
		<?php if ( $start_date->format( 'Y-m-d' ) === $end_date->format( 'Y-m-d' ) ) : ?>
			on <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?>
		<?php else : ?>
			between <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?> and <?php echo esc_html( $end_date->format( 'M jS, Y' ) ); ?>
		<?php endif; ?>
	</p>

<?php endif; ?>

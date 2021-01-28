<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Views\HTML\Meetup_Attendees;
defined( 'WPINC' ) || die();

/** @var \DateTime $start_date */
/** @var \DateTime $end_date */
/** @var array $data */
?>

<?php if ( $data['total_rsvp'] ) : ?>
	<h3>Total of meetup event attendees between <?php echo esc_html( $start_date->format( 'M jS, Y' ) ); ?> and <?php echo esc_html( $end_date->format( 'M jS, Y' ) ); ?></h3>

	<table class="striped widefat but-not-too-wide">
		<tr>
			<td>Total count of attendees</td>
			<td class="number"><?php echo number_format_i18n( $data['total_rsvp'] ); ?></td>
		</tr>
	</table>

	<h4>By country</h4>

	<table class="striped widefat but-not-too-wide">
		<thead>
		<tr>
			<td>Country</td>
			<?php foreach ( array_keys( $data['monthly_rsvp'] ) as $month ) : ?>
				<td><?php echo esc_html( $month ); ?></td>
			<?php endforeach; ?>
			<td>Total</td>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['monthly_rsvp_by_country'] as $country => $month_counts ) : ?>
			<tr>
				<td><?php echo esc_html( $country ); ?></td>
				<?php foreach ( $month_counts as $count ) : ?>
					<td class="number"><?php echo number_format_i18n( $count ); ?></td>
				<?php endforeach; ?>
				<td class="number total"><?php echo number_format_i18n( $data['total_rsvp_by_country'][ $country ] ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<td class="total">Total</td>
			<?php foreach ( $data['monthly_rsvp'] as $count ) : ?>
				<td class="number total"><?php echo number_format_i18n( $count ); ?></td>
			<?php endforeach; ?>
			<td class="number total"><?php echo number_format_i18n( $data['total_rsvp'] ); ?></td>
		</tr>
		</tbody>
	</table>

	<h4>By group</h4>

	<table class="striped widefat but-not-too-wide">
		<thead>
		<tr>
			<td>Group</td>
			<?php foreach ( array_keys( $data['monthly_rsvp'] ) as $month ) : ?>
				<td><?php echo esc_html( $month ); ?></td>
			<?php endforeach; ?>
			<td>Total</td>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['monthly_rsvp_by_group'] as $group => $month_counts ) : ?>
			<tr>
				<td><?php echo esc_html( $group ); ?></td>
				<?php foreach ( $month_counts as $count ) : ?>
					<td class="number"><?php echo number_format_i18n( $count ); ?></td>
				<?php endforeach; ?>
				<td class="number total"><?php echo number_format_i18n( $data['total_rsvp_by_group'][ $group ] ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<td class="total">Total</td>
			<?php foreach ( $data['monthly_rsvp'] as $count ) : ?>
				<td class="number total"><?php echo number_format_i18n( $count ); ?></td>
			<?php endforeach; ?>
			<td class="number total"><?php echo number_format_i18n( $data['total_rsvp'] ); ?></td>
		</tr>
		</tbody>
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

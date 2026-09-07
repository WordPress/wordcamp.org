<?php

defined( 'WPINC' ) || die();

/** @var array $metas */
/** @var string $txn_id */

?>

<h3><?php echo esc_html__( 'Attendee Details', 'wordcamporg' ); ?></h3>

<table class="form-table">
	<tr>
		<th scope="row"><?php echo esc_html__( 'First name', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['first_name'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Last name', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['last_name'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Email', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['email'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Passport issuing country', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['passport_country'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Passport number', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['passport_number'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Date of birth', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['date_of_birth'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Nationality', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['nationality'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Mailing address', 'wordcamporg' ); ?></th>
		<td><?php echo wp_kses( nl2br( $metas['mailing_address'] ), array( 'br' => true ) ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Transaction ID', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $txn_id ); ?><td>
	</tr>
</table>

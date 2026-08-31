<?php

defined( 'WPINC' ) || die();

/** @var array $metas */

?>

<h3><?php echo esc_html__( 'Attendee Details', 'wordcamporg' ); ?></h3>

<table class="form-table">
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[first_name]"><?php echo esc_html__( 'First name', 'wordcamporg' ); ?> &bull;</label>
		</th>
		<td>
			<input
				required
				name="visa_letter_metas[first_name]"
				id="visa_letter_metas[first_name]"
				value="<?php echo esc_attr( empty( $metas['first_name'] ) ? '' : $metas['first_name'] ); ?>"
				type="text"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[last_name]"><?php echo esc_html__( 'Last name', 'wordcamporg' ); ?> &bull;</label>
		</th>
		<td>
			<input
				required
				name="visa_letter_metas[last_name]"
				id="visa_letter_metas[last_name]"
				value="<?php echo esc_attr( empty( $metas['last_name'] ) ? '' : $metas['last_name'] ); ?>"
				type="text"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[email]"><?php echo esc_html__( 'Email', 'wordcamporg' ); ?></label>
		</th>
		<td>
			<input
				name="visa_letter_metas[email]"
				id="visa_letter_metas[email]"
				value="<?php echo esc_attr( empty( $metas['email'] ) ? '' : $metas['email'] ); ?>"
				type="email"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[passport_country]"><?php echo esc_html__( 'Passport issuing country', 'wordcamporg' ); ?> &bull;</label>
		</th>
		<td>
			<input
				required
				name="visa_letter_metas[passport_country]"
				id="visa_letter_metas[passport_country]"
				value="<?php echo esc_attr( empty( $metas['passport_country'] ) ? '' : $metas['passport_country'] ); ?>"
				type="text"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[passport_number]"><?php echo esc_html__( 'Passport number', 'wordcamporg' ); ?> &bull;</label>
		</th>
		<td>
			<input
				required
				name="visa_letter_metas[passport_number]"
				id="visa_letter_metas[passport_number]"
				value="<?php echo esc_attr( empty( $metas['passport_number'] ) ? '' : $metas['passport_number'] ); ?>"
				type="text"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[date_of_birth]"><?php echo esc_html__( 'Date of birth', 'wordcamporg' ); ?></label>
		</th>
		<td>
			<input
				name="visa_letter_metas[date_of_birth]"
				id="visa_letter_metas[date_of_birth]"
				value="<?php echo esc_attr( empty( $metas['date_of_birth'] ) ? '' : $metas['date_of_birth'] ); ?>"
				type="date"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[nationality]"><?php echo esc_html__( 'Nationality', 'wordcamporg' ); ?> &bull;</label>
		</th>
		<td>
			<input
				required
				name="visa_letter_metas[nationality]"
				id="visa_letter_metas[nationality]"
				value="<?php echo esc_attr( empty( $metas['nationality'] ) ? '' : $metas['nationality'] ); ?>"
				type="text"
				class="widefat"
			/>
		<td>
	</tr>
	<tr>
		<th scope="row">
			<label for="visa_letter_metas[mailing_address]"><?php echo esc_html__( 'Mailing address', 'wordcamporg' ); ?></label>
		</th>
		<td>
			<textarea
				name="visa_letter_metas[mailing_address]"
				id="visa_letter_metas[mailing_address]"
				class="widefat"
			><?php
				echo esc_textarea( empty( $metas['mailing_address'] ) ? '' : $metas['mailing_address'] );
			?></textarea>
		<td>
	</tr>
</table>

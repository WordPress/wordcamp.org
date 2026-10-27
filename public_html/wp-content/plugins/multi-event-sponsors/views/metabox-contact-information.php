<?php
/** @var string $company_name */
/** @var string $website */
/** @var string $first_name */
/** @var string $last_name */
/** @var string $email_address */
/** @var string $phone_number */
/** @var string $street_address1 */
/** @var string $street_address2 */
/** @var string $city */
/** @var string $state */
/** @var string $zip_code */
/** @var string $country */
/** @var array $available_countries */
?>
<h3><?php esc_html_e( 'Contact Information', 'wordcamporg' ); ?></h3>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="mes_company_name"><?php esc_html_e( 'Company Name', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_company_name" name="mes_company_name" type="text" value="<?php echo esc_attr( $company_name ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_website"><?php esc_html_e( 'Website', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_website" name="mes_website" type="url" value="<?php echo esc_attr( $website ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_first_name"><?php esc_html_e( 'First Name', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_first_name" name="mes_first_name" type="text" value="<?php echo esc_attr( $first_name ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_last_name"><?php esc_html_e( 'Last Name', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_last_name" name="mes_last_name" type="text" value="<?php echo esc_attr( $last_name ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_email_address"><?php esc_html_e( 'Email Address', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_email_address" name="mes_email_address" type="email" value="<?php echo esc_attr( $email_address ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_phone_number"><?php esc_html_e( 'Phone Number', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_phone_number" name="mes_phone_number" type="text" value="<?php echo esc_attr( $phone_number ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_twitter_handle"><?php esc_html_e( 'Twitter Handle', 'wordcamporg' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wordcamporg' ); ?></span></label></th>

			<td>
				<input id="mes_twitter_handle" name="mes_twitter_handle" type="text" value="<?php echo esc_attr( $twitter_handle ); ?>" class="regular-text" />
			</td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Address', 'wordcamporg' ); ?></h3>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="mes_street_address1"><?php esc_html_e( 'Street Address 1', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_street_address1" name="mes_street_address1" type="text" value="<?php echo esc_attr( $street_address1 ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_street_address2"><?php esc_html_e( 'Street Address 2', 'wordcamporg' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wordcamporg' ); ?></span></label></th>

			<td>
				<input id="mes_street_address2" name="mes_street_address2" type="text" value="<?php echo esc_attr( $street_address2 ); ?>" class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_city"><?php esc_html_e( 'City', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_city" name="mes_city" type="text" value="<?php echo esc_attr( $city ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_state"><?php esc_html_e( 'State', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_state" name="mes_state" type="text" value="<?php echo esc_attr( $state ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_zip_code"><?php esc_html_e( 'Zip Code', 'wordcamporg' ); ?></label></th>

			<td>
				<input id="mes_zip_code" name="mes_zip_code" type="text" value="<?php echo esc_attr( $zip_code ); ?>" required class="regular-text" />
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mes_country"><?php esc_html_e( 'Country', 'wordcamporg' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wordcamporg' ); ?></span></label></th>

			<td>
				<select id="mes_country" name="mes_country">
					<option value="" <?php selected( $country, '' ); ?>>
						<?php esc_html_e( '-- Select a Country --', 'wordcamporg' ); ?>
					</option>

					<?php if ( wcorg_skip_feature( 'cldr-countries' ) ) : ?>
						<?php foreach ( $available_countries as $available_country ) : ?>
							<option value="<?php echo esc_attr( $available_country ); ?>" <?php selected( $available_country, $country ); ?>>
								<?php echo esc_html( $available_country ); ?>
							</option>
						<?php endforeach; ?>
					<?php else : ?>
						<?php foreach ( $available_countries as $country_code => $country_data ) : ?>
							<option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( $country_code, $country ); ?>>
								<?php echo esc_html( $country_data['name'] ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</td>
		</tr>
	</tbody>
</table>

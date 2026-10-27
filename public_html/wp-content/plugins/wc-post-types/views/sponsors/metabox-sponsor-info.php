<?php
/** Metabox view */

/** @var string $amount */
/** @var string $available_currencies */
/** @var string $currency */
/** @var string $company_name */
/** @var string $website */
/** @var string $first_name */
/** @var string $last_name */
/** @var string $email_address */
/** @var string $phone_number */
/** @var string $twitter_handle */
/** @var string $vat_number */
/** @var string $street_address1 */
/** @var string $street_address2 */
/** @var string $city */
/** @var string $state */
/** @var string $zip_code */
/** @var string $country */
/** @var string $first_time */
/** @var array $available_countries */
?>

<h3><?php esc_html_e( 'Sponsorship', 'wordcamporg' ); ?></h3>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row">
				<label for="_wcb_sponsor_amount">
					<?php esc_html_e( 'Amount:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="number"
					class="regular-text"
					id="_wcb_sponsor_amount"
					name="_wcb_sponsor_amount"
					value="<?php echo esc_attr( $amount ); ?>"
					step="any"
					min="0"
					required
				/>

				<p class="description"><?php esc_html_e( 'No commas, thousands separators or currency symbols. Ex. 1234.56', 'wordcamporg' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcb_sponsor_currency">
					<?php esc_html_e( 'Currency:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<select
					id="_wcb_sponsor_currency"
					name="_wcb_sponsor_currency"
					class="select-currency"
				>
					<?php foreach ( $available_currencies as $symbol => $name ) : ?>
						<option
							value="<?php echo esc_attr( $symbol ); ?>"
							<?php selected( $symbol, $currency ); ?>
						>
							<?php echo ( $symbol ) ? esc_html( $name . ' (' . $symbol . ')' ) : ''; ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Is this their first time being a sponsor at a WordPress event?', 'wordcamporg' ); ?>
			</th>
			<td>
				<fieldset>
					<legend class="screen-reader-text">
						<?php esc_html_e( 'Is this their first time being a sponsor at a WordPress event?', 'wordcamporg' ); ?>
					</legend>

					<label for="_wcb_sponsor_first_time_yes">
						<input
							type="radio"
							id="_wcb_sponsor_first_time_yes"
							name="_wcb_sponsor_first_time"
							value="yes"
							<?php checked( $first_time, 'yes' ); ?>
						/>
						<?php esc_html_e( 'Yes', 'wordcamporg' ); ?>
					</label>
					<br />

					<label for="_wcb_sponsor_first_time_no">
						<input
							type="radio"
							id="_wcb_sponsor_first_time_no"
							name="_wcb_sponsor_first_time"
							value="no"
							<?php checked( $first_time, 'no' ); ?>
						/>
						<?php esc_html_e( 'No', 'wordcamporg' ); ?>
					</label>
					<br />

					<label for="_wcb_sponsor_first_time_unsure">
						<input
							type="radio"
							id="_wcb_sponsor_first_time_unsure"
							name="_wcb_sponsor_first_time"
							value="unsure"
							<?php checked( $first_time, 'unsure' ); ?>
						/>
						<?php esc_html_e( 'I don\'t know', 'wordcamporg' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Contact Information', 'wordcamporg' ); ?></h3>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_company_name">
					<?php esc_html_e( 'Company Name:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_company_name"
					name="_wcpt_sponsor_company_name"
					value="<?php echo esc_attr( $company_name ); ?>"
					maxlength="50"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_website">
					<?php esc_html_e( 'Website:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="url"
					class="regular-text"
					id="_wcpt_sponsor_website"
					name="_wcpt_sponsor_website"
					value="<?php echo esc_url( $website ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_first_name">
					<?php esc_html_e( 'First Name:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_first_name"
					name="_wcpt_sponsor_first_name"
					value="<?php echo esc_attr( $first_name ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_last_name">
					<?php esc_html_e( 'Last Name:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_last_name"
					name="_wcpt_sponsor_last_name"
					value="<?php echo esc_attr( $last_name ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_email_address">
					<?php esc_html_e( 'Email Address:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="email"
					class="regular-text"
					id="_wcpt_sponsor_email_address"
					name="_wcpt_sponsor_email_address"
					value="<?php echo esc_attr( $email_address ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_phone_number">
					<?php esc_html_e( 'Phone Number:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_phone_number"
					name="_wcpt_sponsor_phone_number"
					value="<?php echo esc_attr( $phone_number ); ?>"
					maxlength="21"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_twitter_handle">
					<?php esc_html_e( 'Twitter Handle:', 'wordcamporg' ); ?>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_twitter_handle"
					name="_wcpt_sponsor_twitter_handle"
					value="<?php echo esc_attr( $twitter_handle ); ?>"
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_vat_number">
					<?php esc_html_e( 'VAT Number:', 'wordcamporg' ); ?>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_vat_number"
					name="_wcpt_sponsor_vat_number"
					value="<?php echo esc_attr( $vat_number ); ?>"

					<?php // QuickBooks will reject values longer than 31 characters. ?>
					maxlength="31"
				/>

				<p class="description"><?php esc_html_e( 'Only necessary for sponsors invoiced in Euros', 'wordcamporg' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Address', 'wordcamporg' ); ?></h3>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_country">
					<?php esc_html_e( 'Country:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<select id="_wcpt_sponsor_country" name="_wcpt_sponsor_country">
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
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_street_address1">
					<?php esc_html_e( 'Street Address 1:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_street_address1"
					name="_wcpt_sponsor_street_address1"
					value="<?php echo esc_attr( $street_address1 ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_street_address2">
					<?php esc_html_e( 'Street Address 2:', 'wordcamporg' ); ?>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_street_address2"
					name="_wcpt_sponsor_street_address2"
					value="<?php echo esc_attr( $street_address2 ); ?>"
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_city">
					<?php esc_html_e( 'City:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_city"
					name="_wcpt_sponsor_city"
					value="<?php echo esc_attr( $city ); ?>"
					required
				/>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_state">
					<?php esc_html_e( 'State / Province:', 'wordcamporg' ); ?>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_state"
					name="_wcpt_sponsor_state"
					value="<?php echo esc_attr( $state ); ?>"
				/>

				<p class="description"><?php esc_html_e( 'Only necessary if you want this to be shown on your invoice', 'wordcamporg' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="_wcpt_sponsor_zip_code">
					<?php esc_html_e( 'ZIP / Postal Code:', 'wordcamporg' ); ?>
					<span class="description"><?php esc_html_e( '(required)', 'wordcamporg' ); ?></span>
				</label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text"
					id="_wcpt_sponsor_zip_code"
					name="_wcpt_sponsor_zip_code"
					value="<?php echo esc_attr( $zip_code ); ?>"
					maxlength="30"
					required
				/>
			</td>
		</tr>
	</tbody>
</table>

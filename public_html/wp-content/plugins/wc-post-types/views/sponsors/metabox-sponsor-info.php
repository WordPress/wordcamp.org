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
<ul class="wcpt-form">
	<li class="wcpt-form-header">
		<?php esc_html_e( 'Sponsorship', 'wordcamporg' ); ?>
	</li>

	<li>
		<label for="_wcb_sponsor_amount">
			<?php esc_html_e( 'Amount:', 'wordcamporg' ); ?>
		</label>

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

		<?php wcorg_required_indicator(); ?>

		<span class="description"><?php esc_html_e( 'No commas, thousands separators or currency symbols. Ex. 1234.56', 'wordcamporg' ); ?></span>
	</li>

	<li>
		<label for="_wcb_sponsor_currency">
			<?php esc_html_e( 'Currency:', 'wordcamporg' ); ?>
		</label>

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

		<?php wcorg_required_indicator(); ?>
	</li>
</ul>

<ul class="wcpt-form">
	<li class="wcpt-form-header">
		<?php _e( 'Contact Information', 'wordcamporg' ); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_company_name">
			<?php _e( 'Company Name:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_company_name"
			name="_wcpt_sponsor_company_name"
			value="<?php echo esc_attr( $company_name ); ?>"
			maxlength="50"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_website">
			<?php _e( 'Website:', 'wordcamporg' ); ?>
		</label>

		<input
			type="url"
			class="regular-text"
			id="_wcpt_sponsor_website"
			name="_wcpt_sponsor_website"
			value="<?php echo esc_url( $website ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_first_name">
			<?php _e( 'First Name:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_first_name"
			name="_wcpt_sponsor_first_name"
			value="<?php echo esc_attr( $first_name ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_last_name">
			<?php _e( 'Last Name:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_last_name"
			name="_wcpt_sponsor_last_name"
			value="<?php echo esc_attr( $last_name ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_email_address">
			<?php _e( 'Email Address:', 'wordcamporg' ); ?>
		</label>

		<input
			type="email"
			class="regular-text"
			id="_wcpt_sponsor_email_address"
			name="_wcpt_sponsor_email_address"
			value="<?php echo esc_attr( $email_address ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_phone_number">
			<?php _e( 'Phone Number:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_phone_number"
			name="_wcpt_sponsor_phone_number"
			value="<?php echo esc_attr( $phone_number ); ?>"
			maxlength="21"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_twitter_handle">
			<?php esc_html_e( 'Twitter Handle:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_twitter_handle"
			name="_wcpt_sponsor_twitter_handle"
			value="<?php echo esc_attr( $twitter_handle ); ?>"
		/>
	</li>

	<li>
		<label for="_wcpt_sponsor_vat_number">
			<?php _e( 'VAT Number:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_vat_number"
			name="_wcpt_sponsor_vat_number"
			value="<?php echo esc_attr( $vat_number ); ?>"

			<?php // QuickBooks will reject values longer than 31 characters. ?>
			maxlength="31"
		/>

		<span class="description">
			<?php esc_html_e( 'Only necessary for sponsors invoiced in Euros', 'wordcamporg' ); ?>
		</span>
	</li>
</ul>

<ul class="wcpt-form">
	<li>
		<label for="_wcpt_sponsor_country">
			<?php _e( 'Country:', 'wordcamporg' ); ?>
		</label>

		<select id="_wcpt_sponsor_country" name="_wcpt_sponsor_country">
			<option value="" <?php selected( $country, '' ); ?>>
				<?php _e( '-- Select a Country --', 'wordcamporg' ); ?>
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

		<?php wcorg_required_indicator(); ?>
	</li>

	<li class="wcpt-form-header">
		<?php _e( 'Address', 'wordcamporg' ); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_street_address1">
			<?php _e( 'Street Address 1:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_street_address1"
			name="_wcpt_sponsor_street_address1"
			value="<?php echo esc_attr( $street_address1 ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_street_address2">
			<?php _e( 'Street Address 2:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_street_address2"
			name="_wcpt_sponsor_street_address2"
			value="<?php echo esc_attr( $street_address2 ); ?>"
		/>
	</li>

	<li>
		<label for="_wcpt_sponsor_city">
			<?php _e( 'City:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_city"
			name="_wcpt_sponsor_city"
			value="<?php echo esc_attr( $city ); ?>"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>

	<li>
		<label for="_wcpt_sponsor_state">
			<?php _e( 'State / Province:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_state"
			name="_wcpt_sponsor_state"
			value="<?php echo esc_attr( $state ); ?>"
		/>

		<span class="description">
			<?php esc_html_e( 'Only necessary if you want this to be shown on your invoice', 'wordcamporg' ); ?>
		</span>
	</li>

	<li>
		<label for="_wcpt_sponsor_zip_code">
			<?php _e( 'ZIP / Postal Code:', 'wordcamporg' ); ?>
		</label>

		<input
			type="text"
			class="regular-text"
			id="_wcpt_sponsor_zip_code"
			name="_wcpt_sponsor_zip_code"
			value="<?php echo esc_attr( $zip_code ); ?>"
			maxlength="30"
			required
		/>

		<?php wcorg_required_indicator(); ?>
	</li>
</ul>

<ul class="wcpt-form">
	<li class="wcpt-form-header">
		<?php esc_html_e( 'Is this their first time being a sponsor at a WordPress event?', 'wordcamporg' ); ?>
	</li>

	<li>
		<label for="_wcb_sponsor_first_time_yes">
			<?php esc_html_e( 'Yes', 'wordcamporg' ); ?>
		</label>
		<input
			type="radio"
			id="_wcb_sponsor_first_time_yes"
			name="_wcb_sponsor_first_time"
			value="yes"
			<?php checked( $first_time, 'yes' ); ?>
		/>
	</li>
	<li>
		<label for="_wcb_sponsor_first_time_no">
			<?php esc_html_e( 'No', 'wordcamporg' ); ?>
		</label>
		<input
			type="radio"
			id="_wcb_sponsor_first_time_no"
			name="_wcb_sponsor_first_time"
			value="no"
			<?php checked( $first_time, 'no' ); ?>
		/>
	</li>
	<li>
		<label for="_wcb_sponsor_first_time_unsure">
			<?php esc_html_e( 'I don\'t know', 'wordcamporg' ); ?>
		</label>
		<input
			type="radio"
			id="_wcb_sponsor_first_time_unsure"
			name="_wcb_sponsor_first_time"
			value="unsure"
			<?php checked( $first_time, 'unsure' ); ?>
		/>
	</li>
</ul>

<span class="wcpt-form-required">
	<?php _e( '* required', 'wordcamporg' ); ?>
</span>

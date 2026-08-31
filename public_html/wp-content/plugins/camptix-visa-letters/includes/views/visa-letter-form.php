<?php

defined( 'WPINC' ) || die();

/**
 * @var array $visa_prefill Optional stored values (post-purchase edit page); empty on checkout.
 * @var array $options      CampTix options (passed by both render contexts).
 */
$visa_prefill  = isset( $visa_prefill ) && is_array( $visa_prefill ) ? $visa_prefill : array();
$visa_checked  = ! empty( array_filter( $visa_prefill ) );
$visa_options  = isset( $options ) && is_array( $options ) ? $options : get_option( 'camptix_options' );
$visa_canadian = ! empty( $visa_options['visa-letter-canadian'] );

?>

<div class="camptix-visa-letter-toggle-wrapper">

	<input type="checkbox" value="1" name="camptix-need-visa-letter" id="camptix-need-visa-letter" <?php checked( $visa_checked ); ?>/>
	<label for="camptix-need-visa-letter">
		<?php echo esc_html__( 'I need a visa invitation letter', 'wordcamporg' ); ?>
	</label>

	<table class="camptix-visa-letter-details tix_tickets_table tix_visa_letter_table">
		<tbody>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-email">
						<?php echo esc_html__( 'Email address', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-email" id="visa-letter-email" pattern="^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+.[a-zA-Z0-9-.]+$" value="<?php echo esc_attr( $visa_prefill['email'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-first-name">
						<?php echo esc_html__( 'First name (as on passport)', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-first-name" id="visa-letter-first-name" value="<?php echo esc_attr( $visa_prefill['first_name'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-last-name">
						<?php echo esc_html__( 'Last name (as on passport)', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-last-name" id="visa-letter-last-name" value="<?php echo esc_attr( $visa_prefill['last_name'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-passport-country">
						<?php echo esc_html__( 'Passport issuing country', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-passport-country" id="visa-letter-passport-country" value="<?php echo esc_attr( $visa_prefill['passport_country'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-passport-number">
						<?php echo esc_html__( 'Passport number', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-passport-number" id="visa-letter-passport-number" value="<?php echo esc_attr( $visa_prefill['passport_number'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-date-of-birth">
						<?php echo esc_html__( 'Date of birth', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="date" name="visa-letter-date-of-birth" id="visa-letter-date-of-birth" value="<?php echo esc_attr( $visa_prefill['date_of_birth'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-nationality">
						<?php echo esc_html__( 'Nationality', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="visa-letter-nationality" id="visa-letter-nationality" value="<?php echo esc_attr( $visa_prefill['nationality'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-mailing-address">
						<?php echo esc_html__( 'Mailing address', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<textarea name="visa-letter-mailing-address" id="visa-letter-mailing-address" rows="3"><?php echo esc_textarea( $visa_prefill['mailing_address'] ?? '' ); ?></textarea>
				</td>
			</tr>

			<?php if ( $visa_canadian ) : ?>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-entry-date">
						<?php echo esc_html__( 'Date of entry into Canada', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="date" name="visa-letter-entry-date" id="visa-letter-entry-date" value="<?php echo esc_attr( $visa_prefill['entry_date'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-exit-date">
						<?php echo esc_html__( 'Date of exit from Canada', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="date" name="visa-letter-exit-date" id="visa-letter-exit-date" value="<?php echo esc_attr( $visa_prefill['exit_date'] ?? '' ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="visa-letter-accommodation">
						<?php echo esc_html__( 'Accommodation in Canada (optional)', 'wordcamporg' ); ?>
					</label>
				</td>
				<td class="tix-right">
					<textarea name="visa-letter-accommodation" id="visa-letter-accommodation" rows="2"><?php echo esc_textarea( $visa_prefill['accommodation'] ?? '' ); ?></textarea>
				</td>
			</tr>

			<?php endif; ?>

		</tbody>
	</table>
</div>

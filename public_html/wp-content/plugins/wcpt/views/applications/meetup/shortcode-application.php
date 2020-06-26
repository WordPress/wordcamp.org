<?php

namespace WordPress_Community\Applications\Meetup;
defined( 'WPINC' ) || die();

/**
 * Renders the application form for meetup. Renders shortcode meetup-organizer-application
 *
 * @param array $countries
 */
function render_meetup_application_form( $countries, $prefilled_fields ) {

	?>

	<form id="meetup-application" method="post">
		<div class="PDF_pageInner">
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Please enter your full Name.
					<span class="required-indicator">(required)</span>
					<input type="text" name="q_name" value="<?php echo esc_attr( $prefilled_fields['wporg_name'] ); ?>" required/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Please enter your email address.
					<span class="required-indicator">(required)</span>
					<input type="email" name="q_email" value="<?php echo esc_attr( $prefilled_fields['wporg_email'] ); ?>" required/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label> Please enter your mailing address (at least your city/state or city/country). </label>
				<label>
					Address Line 1
					<input type="text" name="q_address_line_1">
				</label>
				<label>
					Address Line 2
					<input type="text" name="q_address_line_2">
				</label>
				<label>
					City
					<span class="required-indicator">(required)</span>
					<input type="text" name="q_city" required/>
				</label>
				<label>
					State/Province
					<input type="text" name="q_state"/>
				</label>
				<label>
					Country
					<span class="required-indicator">(required)</span>

					<select name="q_country" required>
						<option value=""></option>

						<?php foreach ( $countries as $country ) : ?>
							<option value="<?php echo esc_attr( $country['alpha2'] ); ?>">
								<?php echo esc_html( $country['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					ZIP/Postal Code
					<input type="text" name="q_zip"/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Enter the city, state/province, and country where you would like to organize a Meetup
					<span class="required-indicator">(required)</span>
					<input type="text" name="q_mtp_loc" required />
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Is there already a WordPress meetup group in this city?
					<span class="required-indicator">(required)</span>
					<span class="label-description">If you don't know, please <a
								href="https://www.meetup.com/topics/wordpress/"
								target="_blank" rel="noopener noreferrer">check Meetup.com</a> first.</span>

					<select name="q_already_a_meetup" required>
						<option value=""></option>
						<option>Nope, no current meetup group</option>
						<option>Yes, it's the meetup I run now</option>
						<option>Yes, but I want to do a different kind of meetup</option>
					</select>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					<!-- todo: Show/hide this via JavaScript based on selected answer above -->
					If there's an existing Meetup.com group, please provide the URL
					<input type="url" name="q_existing_meetup_url"/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Could you introduce yourself and tell us more about your connection with WordPress?
					<span class="required-indicator">(required)</span>
					<textarea name="q_introduction" rows="5" required ></textarea>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Where we can find you online?
					<span class="required-indicator">(required)</span>
					<span class="label-description">Please add links to your websites, blogs and social media accounts.</span>
					<textarea name="q_socialmedia" rows="5" required ></textarea>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Why do you want to start a WordPress Meetup in your city? And what are your immediate plans?
					<span class="required-indicator">(required)</span>
					<textarea name="q_reasons_plans" rows="5" required ></textarea>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Have you talked with people in your community to check the level of interest?
					<span class="required-indicator">(required)</span>
					<select name="q_community_interest" required>
						<option value=""></option>
						<option>Nope, I have not</option>
						<option>Yes, people are interested</option>
						<option>I'm not sure about the level of interest</option>
					</select>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Your <a href="https://wordpress.org" target="_blank">WordPress.org</a> username
					<span class="required-indicator">(required)</span>
					<input type="text" name="q_wporg_username" value="<?php echo esc_attr( $prefilled_fields['wporg_username'] ); ?>" required/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Your <a href="https://chat.wordpress.org" target="_blank">WordPress Slack</a> username
					<input type="text" name="q_wp_slack_username"/>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<label>
					Anything else you want us to know while we're looking over your application?
					<textarea name="q_anything_else" rows="5"></textarea>
				</label>
			</div>
			<div class="PDF_questionDivide"></div>
			<div class="PDF_question">
				<div class="submit-button">
					<?php submit_button( 'Submit Application', 'primary', 'submit-application', false ); ?>
				</div>
			</div>
		</div>
	</form>

	<?php
}

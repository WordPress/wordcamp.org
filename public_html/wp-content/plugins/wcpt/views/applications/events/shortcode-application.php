<?php
namespace WordPress_Community\Applications\Events;
defined( 'WPINC' ) || die();

/**
 * Renders the application form for events. Renders shortcode meetup-organizer-application
 *
 * @param array $countries
 * @param array $prefilled_fields
 */
function render_events_application_form( $countries, $prefilled_fields ) {
	?>
				<form id="event-application" method="post">

                <div class="PDF_question" id="pd-question-10002">
                    <div class="qContent">
                        <div class="PDF_QT1900">
                            <h2>Organizer Information</h2>
                        </div>
                    </div>
                </div>
                <div class="PDF_question" id="pd-question-1">
					<div class="qContent">
						<div class="qText">
							Please enter your name.
							<span class="PDF_mand">*</span>
						</div>

						<div class="PDF_QT800">
							<div>
								<label for="q_first_name">First Name</label>
								<br/>
								<input type="text" class="firstName" maxlength="50" name="q_first_name"
									   id="q_first_name" value="" required/>
							</div>

							<div>
								<label for="q_last_name">Last Name</label>
								<br/>
								<input type="text" class="lastName" maxlength="50" name="q_last_name"
									   id="q_last_name" value="" required/>
							</div>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-2">

					<div class="qContent">
						<div class="qText">
							Please enter your email address.
							<span class="PDF_mand">*</span>
						</div>

						<div class="PDF_QT1400">
							<label for="q_email">(e.g. john@example.com)</label>
							<br/>
							<input type="email" maxlength="100" name="q_email" id="q_email" value="<?php echo esc_attr( $prefilled_fields['wporg_email'] ); ?>"
								   class="survey-email required" autocomplete="off" required/>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-3">

					<div class="qContent">
						<div class="qText">
							What is your wordpress.org username?
							<span class="PDF_mand">*</span>
						</div>

						<div class="qNote">
							<p>(This is the username you'd use to log in to https://wordpress.org/support/)</p>
						</div>

						<div class="PDF_QT100">
							<input maxlength="500" name="q_wporg_username" class="large required"
								   type="text" title="What is your wordpress.org username?" value="<?php echo esc_attr( $prefilled_fields['wporg_username'] ); ?>" required/>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-4">
					<div class="qContent">
						<div class="qText">
							What is your username on <a href="https://chat.wordpress.org" target="_blank">WordPress'
								Slack workspace</a>?
						</div>

						<div class="PDF_QT100">
							<input value="" maxlength="500" name="q_slack_username" class="large" type="text"
								   title="What is your Slack username?"/>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-5">

					<div class="qContent">
						<div class="qText">
							Please enter your City/State/Country of Residence.
						</div>

						<div class="PDF_QT900">
							<div>
								<label for="q_add1">Address Line 1</label>
								<br/>
								<input type="text" maxlength="100" class="a" name="q_add1" id="q_add1"
									   value=""/>
							</div>

							<div>
								<label for="q_add2">Address Line 2</label>
								<br/>
								<input type="text" maxlength="100" class="b" name="q_add2" id="q_add2"
									   value=""/>
							</div>

							<div>
								<label for="q_city">City</label>
								<br/>
								<input type="text" maxlength="50" class="c" name="q_city" id="q_city"
									   value="" required/>
							</div>

							<div>
								<label for="q_state">State</label>
								<br/>
								<input type="text" maxlength="50" class="d" name="q_state" id="q_state"
									   value=""/>
							</div>

							<div>
								<label for="q_zip">Zip Code</label>
								<br/>
								<input type="text" maxlength="20" class="e" name="q_zip" id="q_zip"
									   value=""/>
							</div>

							<div>
								<label for="q_country">Country</label>
								<br/>

								<select name="q_country" id="q_country" required>
									<option value=""></option>

									<?php foreach ( $countries as $country ) : ?>
										<option value="<?php echo esc_attr( $country['alpha2'] ); ?>">
											<?php echo esc_html( $country['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>
				</div>

                <div class="PDF_question" id="pd-question-6">

                    <div class="qContent">
                        <div class="qText">
                            Is there a WordPress meetup in your community?
                        </div>

                        <div class="PDF_QT400">
                            <ul>
                                <li>
                                    <input type="radio" name="q_active_meetup" value="Yes"
                                           id="q_1865375"/>

                                    <label for="q_1865375" value="1865375">
                                        Yes </label>
                                </li>
                                <li>
                                    <input type="radio" name="q_active_meetup" value="No"
                                           id="q_1865376"/>

                                    <label for="q_1865376" value="1865376">
                                        No </label>
                                </li>
                                <li>
                                    <input type="radio" name="q_active_meetup" value="I don't know"
                                           id="q_1865377"/>

                                    <label for="q_1865377" value="1865377">
                                        I don't know </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="PDF_question" id="pd-question-7">

                    <div class="qContent">
                        <div class="qText">
                            What is the URL for the meetup group&#039;s website?
                        </div>

                        <div class="PDF_QT100">
                            <input value="" maxlength="500" name="q_meetup_url" class="large" type="url"
                                   title="What is the URL for the meetup group&#039;s website?"/>
                        </div>
                    </div>
                </div>

				<div class="PDF_question" id="pd-question-8">

					<div class="qContent">
						<div class="qText">
                            Have you attended a WordPress Meetup, WordCamp, or other WordPress event? Please list one per line.
                            <span class="PDF_mand">*</span>
						</div>

						<div class="PDF_QT200">
							<textarea name="q_camps_been_to" class="small" rows="10" cols="40"
									  title="Have you attended a WordPress Meetup, WordCamp, or other WordPress event? Please list one per line." required></textarea>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-9">

					<div class="qContent">
						<div class="qText">
                            Have you organized a WordPress event (meetup, WordCamp, or other event)?
                            <span class="PDF_mand">*</span>
						</div>

                        <div class="PDF_QT200">
							<textarea name="q_role_in_meetup" class="small" rows="10" cols="40"
                                      title="Have you organized a WordPress event (meetup, WordCamp, or other event)" required></textarea>
                        </div>
					</div>
				</div>

                <div class="PDF_question">
                    <div class="qContent">
                        <div class="qText">
                            Please share links to your online/social media presence (Instagram, Facebook, X, LinkedIn, personal/business website, etc.)
                        </div>

                        <div class="PDF_QT100">
                            <input value="" maxlength="500" name="q_where_find_online" class="large" type="text"
                                   title="Please share links to your online/social media presence"/>
                        </div>
                    </div>
                </div>

				<div class="PDF_question" id="pd-question-10002">
					<div class="qContent">
						<div class="PDF_QT1900">
							<h2>Event Information</h2>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-10">

					<div class="qContent">
						<div class="qText">
                            Event city and country:
							<span class="PDF_mand">*</span>
						</div>

						<div class="PDF_QT100">
							<input value="" maxlength="500" name="q_event_location" class="large required"
								   type="text" title="Event city and country"
								   required/>
						</div>
					</div>
				</div>

				<div class="PDF_question" id="pd-question-11">

					<div class="qContent">
						<div class="qText">
                            Event date(s):
                            <span class="PDF_mand">*</span>
						</div>

						<div class="PDF_QT100">
							<input value="" maxlength="500" name="q_event_date" class="large required" type="text"
								   title="Event date(s)" required/>
						</div>
					</div>
				</div>


                <div class="PDF_question" id="pd-question-12">

                    <div class="qContent">
                        <div class="qText">
                            Is this event in-person or online?
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT400">
                            <ul>
                                <li>
                                    <input type="radio" name="q_in_person_online"
                                           value="It would be an in-person event" id="q_in_person_online_1" required/>

                                    <label for="q_in_person_online_1">
                                        In-person</label>
                                </li>
                                <li>
                                    <input type="radio" name="q_in_person_online"
                                           value="It would be an online event" id="q_in_person_online_2" />

                                    <label for="q_in_person_online_2">
                                        Online</label>
                                </li>
                                <li>
                                    <input type="radio" name="q_in_person_online"
                                           value="It would be both in-person and streamed online" id="q_in_person_online_3" />

                                    <label for="q_in_person_online_3">
                                        Hybrid: It would be both in-person and streamed online </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="PDF_question" id="pd-question-13">

                    <div class="qContent">
                        <div class="qText">
                            Please describe the event you would like to organize:
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT200">
							<textarea name="q_describe_events" class="small required" rows="10" cols="40"
                                      title="Please describe the event you would like to organize" required></textarea>
                        </div>
                    </div>
                </div>


                <div class="PDF_question" id="pd-question-14">

                    <div class="qContent">
                        <div class="qText">
                            What goals do you want to achieve with this event?
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT200">
							<textarea name="q_describe_goals" class="small required" rows="10" cols="40"
                                      title="What goals do you want to achieve with this event" required></textarea>
                        </div>
                    </div>
                </div>

                <div class="PDF_question" id="pd-question-15">

                    <div class="qContent">
                        <div class="qText">
                            Which of the following describe this event? (Select all that apply)
                        </div>

                        <div class="PDF_QT400">
                            <ul>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908777"
                                           value="Activity focused"/>

                                    <label for="q_1908777">Activity focused (training, recruiting, networking, contributing, conferencing, etc.)</label>
                                </li>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908778"
                                           value="Topic focused"/>

                                    <label for="q_1908778">Topic focused (design, development, security, accessibility, SEO, agencies, marketing, etc.)</label>
                                </li>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908779"
                                           value="Identity focused"/>

                                    <label for="q_1908779">Identity focused (WordPress for Students, WordPress for Women, BlackPress, etc.)</label>
                                </li>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908831"
                                           value="Job-status focused"/>

                                    <label for="q_1908831">Job-status focused (job seekers, freelancers, business owners, etc)</label>
                                </li>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908832"
                                           value="For a specific expertise level"/>

                                    <label for="q_1908832">For a specific expertise level (beginners, intermediate, advanced users)</label>
                                </li>
                                <li>
                                    <input type="checkbox" name="q_describe_event[]" id="q_1908833"
                                           value="Sustainable event"/>

                                    <label for="q_1908833">Sustainable event (no swag, small/easy to organize, low budget, etc.)</label>
                                </li>
                                <li>
                                    <label for="q_other">Other:</label>

                                    <input type="text" class="other" name="q_describe_event_other"
                                           id="q_other" value="" style="width: 75%"/>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>


                <div class="PDF_question" id="pd-question-16">

                    <div class="qContent">
                        <div class="qText">
                            Approximate number of attendees:
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT100">
                            <input value="" maxlength="500" name="q_how_many_attendees" class="large required"
                                   type="text" title="Approximate number of attendees" required/>
                        </div>
                    </div>
                </div>

				<div class="PDF_question" id="pd-question-17">

					<div class="qContent">
						<div class="qText">
                            If you have co-organizers, please share their name(s), email address(es) and WordPress.org username(s)
						</div>

						<div class="PDF_QT200">
							<textarea name="q_co_organizer_contact_info" class="small" rows="10" cols="40"
									  title="If you have co-organizers, please share their name(s), email address(es) and WordPress.org username(s)"></textarea>
						</div>
					</div>
				</div>

                <div class="PDF_question" id="pd-question-18">

                    <div class="qContent">
                        <div class="qText">
                            Your event URL will be: https://events.wordpress.org/[location]/[year]/[event-name]
                            e.g. https://events.wordpress.org/narnia/2024/communityday
                            What would you like to be the [event-name] at the end of your event URL?
                        </div>

                        <div class="PDF_QT100">
                            <input value="" maxlength="500" name="q_event_url" class="large required"
                                   type="text" title="Event URL endings"/>
                        </div>
                    </div>
                </div>

				<div class="PDF_question" id="pd-question-19">

					<div class="qContent">
						<div class="qText">
                            If you have a potential venue (ideally donated), please share the URL and/or name.
						</div>

						<div class="PDF_QT200">
							<textarea name="q_venues_considering" class="small" rows="10" cols="40"
									  title="If you have a potential venue (ideally donated), please share the URL and/or name."></textarea>
						</div>
					</div>
				</div>

                <div class="PDF_question" id="pd-question-20">

                    <div class="qContent">
                        <div class="qText">
                            What is the estimated cost to organize your event?
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT200">
							<textarea name="q_estimated_cost" class="small required" rows="10" cols="40"
                                      title="What is the estimated cost to organize your event?" required></textarea>
                        </div>
                    </div>
                </div>

                <div class="PDF_question" id="pd-question-21">

                    <div class="qContent">
                        <div class="qText">
                            Are you confident you can raise money from local sponsors? Please explain.
                            <span class="PDF_mand">*</span>
                        </div>

                        <div class="PDF_QT200">
							<textarea name="q_raise_money" class="small" rows="10" cols="40"
                                      title="Are you confident you can raise money from local sponsors? Please explain." required></textarea>
                        </div>
                    </div>
                </div>

				<div class="PDF_question" id="pd-question-22">

					<div class="qContent">
						<div class="qText">
                            Is there anything else you would like to share?
						</div>

						<div class="PDF_QT200">
							<textarea name="q_anything_else" class="large" rows="10" cols="40"
									  title="Is there anything else you would like to share?"></textarea>
						</div>
					</div>
				</div>

				<div class="PDF_question">
					<div class="button">
						<input type="submit" name="submit-application" value="Submit Application"/>
					</div>
				</div>
	</form>
<?php
}

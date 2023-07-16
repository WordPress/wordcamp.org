<?php

use function WordCamp\Logger\log;

/**
 * Sends e-mails at time-based intervals and on triggers
 * @package WordCampOrganizerReminders
 */
class WCOR_Mailer {
	public $triggers;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->triggers = array(
			'wcor_approved_for_pre_planning' => array(
				'name'     => 'WordCamp approved for pre-planning',
				'actions'  => array(
					array(
						'name'       => 'wcpt_approved_for_pre_planning',
						'callback'   => 'send_trigger_approved_for_pre_planning',
						'priority'   => 10,
						'parameters' => 1,
					),
				),
			),

			'wcor_added_to_schedule' => array(
				'name'     => 'WordCamp added to final schedule',
				'actions'  => array(
					array(
						'name'       => 'wcpt_added_to_final_schedule',
						'callback'   => 'send_trigger_added_to_schedule',
						'priority'   => 10,
						'parameters' => 1,
					),
				),
			),

			'wcor_organizer_added_to_central' => array(
				'name'     => 'Lead organizer account added to Central',
				'actions'  => array(
					array(
						'name'       => 'wcor_organizer_added_to_central',
						'callback'   => 'send_trigger_organizer_added_to_central',
						'priority'   => 10,
						'parameters' => 1,
					),
				),
			),

			'wcor_wordcamp_site_created' => array(
				'name'     => 'WordCamp website created',
				'actions'  => array(
					array(
						'name'       => 'wcor_wordcamp_site_created',
						'callback'   => 'send_trigger_wordcamp_site_created',
						'priority'   => 10,
						'parameters' => 1,
					),
				),
			),
		);

		add_action( 'wcor_send_timed_emails', array( $this, 'send_timed_emails' ) );

		foreach ( $this->triggers as $trigger_id => $trigger ) {
			foreach( $trigger['actions'] as $action ) {
				add_action( $action['name'], array( $this, $action['callback'] ), $action['priority'], $action['parameters'] );
			}
		}
	}

	/**
	 * Schedule cron job when plugin is activated
	 */
	public function activate() {
		if ( wp_next_scheduled( 'wcor_send_timed_emails' ) === false ) {
			wp_schedule_event(
				current_time( 'timestamp' ),
				'daily',
				'wcor_send_timed_emails'
			);
		}
	}

	/**
	 * Clear cron job when plugin is deactivated
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'wcor_send_timed_emails' );
	}

	/**
	 * Wrapper for wp_mail() that customizes the subject, body and headers
	 *
	 * We want to make sure that replies go to support@wordcamp.org, rather than the fake address that WordPress sends from, but
	 * we don't want to be flagged as spam for forging the From header, so we set the Sender header.
	 * @see http://stackoverflow.com/q/4728393/450127
	 *
	 * @todo Switch this and all other instances of hardcoded addresses to use EMAIL_CENTRAL_SUPPORT and
	 * EMAIL_DEVELOPER_NOTIFICATIONS constants
	 *
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @param array  $headers
	 * @param WP_Post $email
	 * @param WP_Post $wordcamp
	 *
	 * @return bool
	 */
	protected function mail( $to, $subject, $body, $headers, $email, $wordcamp ) {
		if ( ! $this->validate_email_addresses( $to ) ) {
			log( 'Message not sent because of invalid recipients.', compact( 'email', 'wordcamp' ) );
			return false;
		}

		$status  = true;
		$subject = $this->replace_placeholders( $wordcamp, $email, $subject );
		$body    = $this->replace_placeholders( $wordcamp, $email, $body );
		$subject = html_entity_decode( strip_tags( $subject ), ENT_QUOTES, 'UTF-8' );
		$body    = html_entity_decode( strip_tags( $body ), ENT_QUOTES, 'UTF-8' );

		$headers = array_merge( $headers, array(
			'From: WordCamp Central <support@wordcamp.org>',
			'Sender: wordpress@' . strtolower( $_SERVER['SERVER_NAME'] ),
			'CC: support@wordcamp.org',
		) );

		if ( is_array( $to ) && $this->send_individual_emails( $email->ID ) ) {
			foreach ( $to as $individual_recipient ) {
				if ( ! wp_mail( $individual_recipient, $subject, $body, $headers ) ) {
					log( 'Message failed to send', compact( 'individual_recipient', 'email', 'wordcamp' ) );
					$status = false;
				}
			}
		} else {
			$status = wp_mail( $to, $subject, $body, $headers );

			if ( ! $status ) {
				log( 'Message failed to send', compact( 'email', 'wordcamp' ) );
			}
		}

		return $status;
	}

	/**
	 * Replaces placeholders with a dynamic string.
	 *
	 * Some of these fields are guaranteed to have values by WordCamp_Admin::require_complete_meta_to_publish_wordcamp(),
	 * but those that aren't are filled with 'N/A/'.
	 *
	 * This is performant right now, but if we add more function calls in $replace then it could start to slow down,
	 * because everything in $replace is called every time the function is called, even if its corresponding $search
	 * value isn't present in $content. If it does, then refactor it so only replace placeholders that are actually
	 * found.
	 *
	 * @param  WP_Post $wordcamp
	 * @param  WP_Post $email
	 * @param  string  $content
	 *
	 * @return string
	 */
	protected function replace_placeholders( $wordcamp, $email, $content ) {
		/** @var $wordcamp_admin WordCamp_Admin */
		global $wordcamp_admin;

		// Make sure postmeta is synced with $_POST when this is called in the middle of updating a post
		$saving_post     = ( did_action( 'transition_post_status' ) || did_action( 'save_post' ) ) && isset( $_POST['post_type'] );
		$saving_wordcamp = $saving_post && defined( 'WCPT_POST_TYPE_ID' ) && $_POST['post_type'] === WCPT_POST_TYPE_ID;

		if ( $saving_wordcamp ) {
			$wordcamp_admin->metabox_save( $wordcamp->ID, $wordcamp, false );
		}

		$wordcamp_meta = get_post_custom( $wordcamp->ID );

		$search = array(
			// The WordCamp
			'[wordcamp_name]',
			'[wordcamp_start_date]',
			'[wordcamp_location]',
			'[wordcamp_url]',
			'[edit_wordcamp_url]',
			'[wordcamp_email]',
			'[wordcamp_twitter]',
			'[wordcamp_hashtag]',
			'[wordcamp_anticipated_attendees]',
			'[multi_event_sponsor_region]',

			// The organizing team
			'[organizer_name]',
			'[lead_organizer_username]',
			'[lead_organizer_email]',
			'[lead_organizer_telephone]',
			'[organizer_address]',
			'[sponsor_wrangler_name]',
			'[sponsor_wrangler_email]',
			'[budget_wrangler_name]',
			'[budget_wrangler_email]',
			'[venue_wrangler_name]',
			'[venue_wrangler_email]',
			'[speaker_wrangler_name]',
			'[speaker_wrangler_email]',
			'[food_wrangler_name]',
			'[food_wrangler_email]',
			'[swag_wrangler_name]',
			'[swag_wrangler_email]',
			'[volunteer_wrangler_name]',
			'[volunteer_wrangler_email]',
			'[printing_wrangler_name]',
			'[printing_wrangler_email]',
			'[design_wrangler_name]',
			'[design_wrangler_email]',
			'[website_wrangler_name]',
			'[website_wrangler_email]',
			'[social_wrangler_name]',
			'[social_wrangler_email]',
			'[a_v_wrangler_name]',
			'[a_v_wrangler_email]',
			'[party_wrangler_name]',
			'[party_wrangler_email]',
			'[travel_wrangler_name]',
			'[travel_wrangler_email]',
			'[safety_wrangler_name]',
			'[safety_wrangler_email]',

			// Venue
			'[venue_name]',
			'[venue_address]',
			'[venue_max_capacity]',
			'[venue_available_rooms]',
			'[venue_url]',
			'[venue_contact_info]',
			'[venue_exhibition_space_message]',

			// Miscellaneous
			'[multi_event_sponsor_info]',
			'[session_feedback_list_url]',
		);

		$replace = array(
			// The WordCamp
			$wordcamp->post_title,
			empty( $wordcamp_meta['Start Date (YYYY-mm-dd)'][0] ) ? '' : date( 'l, F jS, Y', $wordcamp_meta['Start Date (YYYY-mm-dd)'][0] ),
			$wordcamp_meta['Location'][0] ?? '',
			empty( $wordcamp_meta['URL'][0] ) ? '' : esc_url( $wordcamp_meta['URL'][0] ),
			esc_url( admin_url( 'post.php?post=' . $wordcamp->ID . '&action=edit' ) ),
			$wordcamp_meta['E-mail Address'][0] ?? '', // Group address for entire team.
			empty( $wordcamp_meta['Twitter'][0] ) ? 'N/A' : esc_url( 'https://twitter.com/' . $wordcamp_meta['Twitter'][0] ),
			empty( $wordcamp_meta['WordCamp Hashtag'][0] ) ? 'N/A' : esc_url( 'https://twitter.com/hashtag/' . $wordcamp_meta['WordCamp Hashtag'][0] ),
			empty( $wordcamp_meta['Number of Anticipated Attendees'][0] ) ? '' : absint( $wordcamp_meta['Number of Anticipated Attendees'][0] ),
			empty( $wordcamp_meta['Multi-Event Sponsor Region'][0] ) ? '' : get_term( $wordcamp_meta['Multi-Event Sponsor Region'][0], MES_Region::TAXONOMY_SLUG )->name,

			// The organizing team
			$wordcamp_meta['Organizer Name'][0] ?? '',
			$wordcamp_meta['WordPress.org Username'][0] ?? '',
			$wordcamp_meta['Email Address'][0] ?? '', // Lead organizer's personal address.
			$wordcamp_meta['Telephone'][0] ?? '',
			$wordcamp_meta['Mailing Address'][0] ?? '',
			$wordcamp_meta['Sponsor Wrangler Name'][0] ?? '',
			$wordcamp_meta['Sponsor Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Budget Wrangler Name'][0] ?? '',
			$wordcamp_meta['Budget Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Venue Wrangler Name'][0] ?? '',
			$wordcamp_meta['Venue Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Speaker Wrangler Name'][0] ?? '',
			$wordcamp_meta['Speaker Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Food/Beverage Wrangler Name'][0] ?? '',
			$wordcamp_meta['Food/Beverage Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Swag Wrangler Name'][0] ?? '',
			$wordcamp_meta['Swag Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Volunteer Wrangler Name'][0] ?? '',
			$wordcamp_meta['Volunteer Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Printing Wrangler Name'][0] ?? '',
			$wordcamp_meta['Printing Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Design Wrangler Name'][0] ?? '',
			$wordcamp_meta['Design Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Website Wrangler Name'][0] ?? '',
			$wordcamp_meta['Website Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Social Media/Publicity Wrangler Name'][0] ?? '',
			$wordcamp_meta['Social Media/Publicity Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['A/V Wrangler Name'][0] ?? '',
			$wordcamp_meta['A/V Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Party Wrangler Name'][0] ?? '',
			$wordcamp_meta['Party Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Travel Wrangler Name'][0] ?? '',
			$wordcamp_meta['Travel Wrangler E-mail Address'][0] ?? '',
			$wordcamp_meta['Safety Wrangler Name'][0] ?? '',
			$wordcamp_meta['Safety Wrangler E-mail Address'][0] ?? '',

			// Venue
			empty( $wordcamp_meta['Venue Name'][0] )          ? 'N/A' : $wordcamp_meta['Venue Name'][0],
			empty( $wordcamp_meta['Physical Address'][0] )    ? 'N/A' : $wordcamp_meta['Physical Address'][0],
			empty( $wordcamp_meta['Maximum Capacity'][0] )    ? 'N/A' : $wordcamp_meta['Maximum Capacity'][0],
			empty( $wordcamp_meta['Available Rooms'][0] )     ? 'N/A' : $wordcamp_meta['Available Rooms'][0],
			empty( $wordcamp_meta['Website URL'][0] )         ? 'N/A' : $wordcamp_meta['Website URL'][0],
			empty( $wordcamp_meta['Contact Information'][0] ) ? 'N/A' : $wordcamp_meta['Contact Information'][0],
			empty( $wordcamp_meta['Exhibition Space Available'][0] ) ? 'This event has no exhibition space.' : 'This event might have exhibition space available, please check with the organizers for more information.',

			// Miscellaneous
			$this->get_mes_info( $wordcamp->ID ),
			$this->get_feedback_list_table_url( $wordcamp ),
		);

		return str_replace( $search, $replace, $content );
	}

	/**
	 * Get formatted general info for all of the given WordCamp's ME sponsors
	 *
	 * @param int $wordcamp_id
	 *
	 * @return string
	 */
	protected function get_mes_info( $wordcamp_id ) {
		/** @var $multi_event_sponsors Multi_Event_Sponsors */
		global $multi_event_sponsors;

		$sponsors     = $multi_event_sponsors->get_wordcamp_me_sponsors( $wordcamp_id );
		$sponsor_info = $multi_event_sponsors->get_sponsor_info( $sponsors );
		$region_id    = get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true );

		if ( ! $sponsors || ! $sponsor_info ) {
			return '';
		}

		ob_start();

		foreach ( $sponsor_info as $sponsor ) {
			$sponsorship_level = get_post( $sponsor['sponsorship_levels'][ $region_id ] ); // we can assume this exists because otherwise the sponsor wouldn't be in $sponsors / $sponsor_info

			?>

			Company: <?php echo esc_html( $sponsor['company_name'] ); ?>

			Sponsorship Level: <?php echo esc_html( $sponsorship_level->post_title ); ?>

			Contact: <?php echo sprintf(
				'%s %s, %s',
				esc_html( $sponsor['contact_first_name'] ),
				esc_html( $sponsor['contact_last_name'] ),
				esc_html( $sponsor['contact_email'] )
			); ?>

			<?php
		}

		return trim( str_replace( "\t", '', ob_get_clean() ) );
	}

	/**
	 * Get the URL for the Feedback list table screen on a particular WordCamp site.
	 *
	 * @param WP_Post $wordcamp The WordCamp post.
	 *
	 * @return string
	 */
	protected function get_feedback_list_table_url( $wordcamp ) {
		$url     = '';
		$site_id = get_wordcamp_site_id( $wordcamp );

		switch_to_blog( $site_id );

		// This is just used to detect whether the Speaker Feedback Tool is active on the site.
		$page_id = get_option( 'sft_feedback_page', 0 );

		if ( $page_id ) {
			$url = add_query_arg(
				array(
					'post_type' => 'wcb_session',
					'page'      => 'wc-speaker-feedback',
				),
				esc_url( admin_url( 'edit.php' ) )
			);
		}

		restore_current_blog();

		return $url;
	}

	/**
	 * Retrieve the e-mail address(es) that a Reminder should be sent to.
	 *
	 * @param int $wordcamp_id
	 * @param int $email_id
	 *
	 * @return array
	 */
	protected function get_recipients( $wordcamp_id, $email_id ) {
		$recipients = array();
		$send_where = get_post_meta( $email_id, 'wcor_send_where' );

		if ( in_array( 'wcor_send_custom', $send_where ) ) {
			$recipients[] = get_post_meta( $email_id, 'wcor_send_custom_address', true );
		}

		if ( in_array( 'wcor_send_mes', $send_where ) ) {
			/** @var $multi_event_sponsors Multi_Event_Sponsors */
			global $multi_event_sponsors;

			$recipients = array_merge(
				$recipients,
				$multi_event_sponsors->get_sponsor_emails( $multi_event_sponsors->get_wordcamp_me_sponsors( $wordcamp_id ) )
			);
		}

		if ( in_array( 'wcor_send_sponsor_wrangler', $send_where ) ) {
			// If the Sponsor Wrangler email is invalid, use the lead organizer email address.
			if ( is_email( get_post_meta( $wordcamp_id, 'Sponsor Wrangler E-mail Address', true ) ) ) {
				$recipients[] = get_post_meta( $wordcamp_id, 'Sponsor Wrangler E-mail Address', true );
			} else {
				$recipients[] = get_post_meta( $wordcamp_id, 'Email Address', true );
			}
		}

		// A bunch of other wranglers could be recipients.
		$other_wranglers = array(
			'wcor_send_budget_wrangler' => 'Budget Wrangler E-mail Address',
			'wcor_send_venue_wrangler' => 'Venue Wrangler E-mail Address',
			'wcor_send_speaker_wrangler' => 'Speaker Wrangler E-mail Address',
			'wcor_send_food_wrangler' => 'Food/Beverage Wrangler E-mail Address',
			'wcor_send_swag_wrangler' => 'Swag Wrangler E-mail Address',
			'wcor_send_volunteer_wrangler' => 'Volunteer Wrangler E-mail Address',
			'wcor_send_printing_wrangler' => 'Printing Wrangler E-mail Address',
			'wcor_send_design_wrangler' => 'Design Wrangler E-mail Address',
			'wcor_send_website_wrangler' => 'Website Wrangler E-mail Address',
			'wcor_send_social_wrangler' => 'Social Media/Publicity Wrangler E-mail Address',
			'wcor_send_a_v_wrangler' => 'A/V Wrangler E-mail Address',
			'wcor_send_party_wrangler' => 'Party Wrangler E-mail Address',
			'wcor_send_travel_wrangler' => 'Travel Wrangler E-mail Address',
			'wcor_send_safety_wrangler' => 'Safety Wrangler E-mail Address',
		);

		foreach( array_intersect( array_keys( $other_wranglers ), $send_where ) as $key ) {
			$dest = get_post_meta( $wordcamp_id, $other_wranglers[ $key ], true );

			// Default to the lead organizer e-mail.
			if ( ! is_email( $dest ) ) {
				$dest = get_post_meta( $wordcamp_id, 'Email Address', true );;
			}

			$recipients[] = $dest;
		}

		if ( in_array( 'wcor_send_camera_wrangler', $send_where ) ) {
			$region_id = get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true );
			$recipients[] = MES_Region::get_camera_wranger_from_region( $region_id );
		}

		if ( in_array( 'wcor_send_organizers', $send_where ) ) {
			$email_address_key = wcpt_key_to_str( 'E-mail Address', 'wcpt_' ); // Team address.

			/*
			 * If a WordCamp post type is being updated, use the new address in the request, rather than the old
			 * one stored in the database
			 */
			if ( ! empty( $_POST[ $email_address_key ] ) ) {
				$recipients[] = sanitize_email( $_POST[ $email_address_key ] );
			} else {
				$recipients[] = sanitize_email( get_post_meta( $wordcamp_id, 'E-mail Address', true ) ); // Team address.
			}
		}

		$recipients = array_unique( $recipients );
		return $recipients;
	}

	/**
	 * Validate a given e-mail address or array of e-mail addresses
	 *
	 * @param string | array $emails
	 * @return bool
	 */
	protected function validate_email_addresses( $emails ) {
		$emails = (array) $emails;

		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Send an e-mail manually.
	 *
	 * This just sends the message immediately, regardless of whether or not it is assigned to be sent
	 * automatically and regardless of whether it's already been sent.
	 *
	 * @param WP_Post $email
	 * @param WP_Post $wordcamp
	 * @return bool
	 */
	public function send_manual_email( $email, $wordcamp ) {
		$recipient = $this->get_recipients( $wordcamp->ID, $email->ID );

		return $this->mail( $recipient, $email->post_title, $email->post_content, array(), $email, $wordcamp );
	}

	/**
	 * Send e-mails that are scheduled to go out at a specific time (e.g., 3 days before the camp)
	 */
	public function send_timed_emails() {
		$recent_or_upcoming_wordcamps = get_posts( array(
			'posts_per_page'  => -1,
			'post_type'       => 'wordcamp',
			'post_status'     => WordCamp_Loader::get_public_post_statuses(),
			'meta_query'      => array(
				array(
					'key'     => 'Start Date (YYYY-mm-dd)',
					'value'   => strtotime( 'now - 3 months' ),
					'compare' => '>=',
				),
			),
		) );

		$pending_wordcamps = get_posts( array(
			'posts_per_page'  => -1,
			'post_type'       => WCPT_POST_TYPE_ID,
			'post_status'     => WordCamp_Loader::get_pre_planning_post_statuses(),
		) );

		$wordcamps = array_merge( $recent_or_upcoming_wordcamps, $pending_wordcamps );

		$reminder_emails = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
			'meta_query'     => array(
				array(
					'key'     => 'wcor_send_when',
					'value'   => array( 'wcor_send_before', 'wcor_send_after', 'wcor_send_after_pending' ),
					'compare' => 'IN'
				),
			),
		) );

		foreach ( $wordcamps as $wordcamp ) {
			$sent_email_ids = get_post_meta( $wordcamp->ID, 'wcor_sent_email_ids', true );
			if ( ! is_array( $sent_email_ids ) ) {
				$sent_email_ids = array();
			}

			foreach ( $reminder_emails as $email ) {
				$recipient = $this->get_recipients( $wordcamp->ID, $email->ID );

				if ( $this->timed_email_is_ready_to_send( $wordcamp, $email, $sent_email_ids ) ) {
					if ( $this->mail( $recipient, $email->post_title, $email->post_content, array(), $email, $wordcamp ) ) {
						$sent_email_ids[] = $email->ID;
						update_post_meta( $wordcamp->ID, 'wcor_sent_email_ids', $sent_email_ids );
					}
				}
			}
		}
	}

	/**
	 * Determines if a time-based e-mail is ready to be sent to a WordCamp
	 *
	 * E-mails should be sent if the current date matches the date that the e-mail is scheduled to be sent (e.g., 3 days before the camp starts).
	 *
	 * One exception to that is if a camp is added later than expected (e.g., we start sending e-mails 4 months before the start date, but a camp
	 * isn't scheduled until 2 months before the start). When that happens, we want to send all the e-mails that they've missed.
	 *
	 * We don't want newly created messages to be retroactively sent to all WordCamps that occurred before the e-mail was created though, so we
	 * only send messages to camps that were created after the e-mail was created.
	 *
	 * @todo It'd be nice to have some unit tests for this function, since there are a lot of different cases, but it seems like that might be
	 * hard to do because of having to mock get_post_meta(), current_time(), etc. We could pass that info in, but that doesn't seem very elegant.
	 *
	 * @param WP_Post $wordcamp
	 * @param WP_Post $email
	 * @param array   $sent_email_ids The IDs of emails that have already been sent to the $wordcamp post
	 * @return bool
	 */
	protected function timed_email_is_ready_to_send( $wordcamp, $email, $sent_email_ids ) {
		$ready = false;

		// Don't retroactively send new emails to old camps, since they're already closed.
		if ( strtotime( $wordcamp->post_date ) < strtotime( $email->post_date ) ) {
			return $ready;
		}

		$send_when  = get_post_meta( $email->ID, 'wcor_send_when', true );
		$start_date = get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true );
		$end_date   = get_post_meta( $wordcamp->ID, 'End Date (YYYY-mm-dd)', true );

		if ( ! $end_date ) {
			$end_date = $start_date;
		}

		if ( ! in_array( $email->ID, $sent_email_ids ) ) {
			if ( 'wcor_send_before' == $send_when ) {
				$days_before = absint( get_post_meta( $email->ID, 'wcor_send_days_before', true ) );

				if ( $start_date && $days_before ) {
					$send_date = $start_date - ( $days_before * DAY_IN_SECONDS );

					if ( $send_date <= current_time( 'timestamp' ) ) {
						$ready = true;
					}
				}
			} elseif ( 'wcor_send_after' == $send_when ) {
				$days_after = absint( get_post_meta( $email->ID, 'wcor_send_days_after', true ) );

				if ( $end_date && $days_after ) {
					$send_date = $end_date + ( $days_after * DAY_IN_SECONDS );

					if ( $send_date <= current_time( 'timestamp' ) ) {
						$ready = true;
					}
				}
			} elseif ( 'wcor_send_after_pending' == $send_when ) {
				$days_after_pending                  = absint( get_post_meta( $email->ID, 'wcor_send_days_after_pending', true ) );
				$timestamp_added_to_pending_schedule = absint( get_post_meta( $wordcamp->ID, '_timestamp_added_to_planning_schedule', true ) );

				if ( $days_after_pending && $timestamp_added_to_pending_schedule ) {
					$execution_timestamp = $timestamp_added_to_pending_schedule + ( $days_after_pending * DAY_IN_SECONDS );

					if ( $execution_timestamp <= current_time( 'timestamp' ) ) {
						$ready = true;
					}
				}
			}
		}

		return $ready;
	}

	/**
	 * Get all of the reminder posts assigned to the given trigger.
	 *
	 * @param string $trigger
	 * @return array
	 */
	protected function get_triggered_posts( $trigger ) {
		$posts = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG,
			'meta_query'     => array(
				array(
					'key'    => 'wcor_send_when',
					'value'  => 'wcor_send_trigger',
				),
				array(
					'key'    => 'wcor_which_trigger',
					'value'  => $trigger,
				),
			),
		) );

		return $posts;
	}

	/**
	 * Determine if the given e-mail should be sent individually.
	 *
	 * Most e-mails with multiple recipients can be sent as a single message with all recipients in the To field,
	 * but some should be sent to each recipient individually in order to appear more personalized.
	 *
	 * @todo Maybe add a meta field so this can be easily controlled by the e-mail creator.
	 *
	 * @param int $email_id
	 * @return bool
	 */
	protected function send_individual_emails( $email_id ) {
		$send_where = get_post_meta( $email_id, 'wcor_send_where' );

		return in_array( 'wcor_send_mes', $send_where );
	}

	/**
	 * Sends e-mails hooked to the wcor_approved_for_pre_planning trigger.
	 *
	 * This fires when a WordCamp has been approved for pre-planning.
	 *
	 * @param WP_Post $wordcamp
	 */
	public function send_trigger_approved_for_pre_planning( $wordcamp ) {
		$this->send_triggered_emails( $wordcamp, 'wcor_approved_for_pre_planning' );
	}

	/**
	 * Sends e-mails hooked to the wcor_added_to_schedule trigger.
	 *
	 * This fires when a WordCamp is added to the final schedule.
	 *
	 * @param WP_Post $wordcamp
	 */
	public function send_trigger_added_to_schedule( $wordcamp ) {
		$this->send_triggered_emails( $wordcamp, 'wcor_added_to_schedule' );
	}

	/**
	 * Sends e-mails hooked to the wcor_organizer_added_to_central trigger.
	 *
	 * This fires when an application is approved and the lead organizer's WordPress.org account
	 * is added as a Contributor to central.wordcamp.org.
	 *
	 * wcor_organizer_added_to_central is only triggered when the organizer has successfully been
	 * added to Central, so we don't need to do any precondition checks like other trigger callbacks.
	 *
	 * @param WP_Post $wordcamp
	 */
	public function send_trigger_organizer_added_to_central( $wordcamp ) {
		$this->send_triggered_emails( $wordcamp, 'wcor_organizer_added_to_central' );
	}

	/**
	 * Sends e-mails hooked to the wcor_wordcamp_site_created trigger.
	 *
	 * This fires when a new WordCamp.org ste is created.
	 *
	 * wcor_wordcamp_site_created is only triggered when a new site has successfully been created,
	 * so we don't need to do any precondition checks like other trigger callbacks.
	 *
	 * @param int $wordcamp_id
	 */
	public function send_trigger_wordcamp_site_created( $wordcamp_id ) {
		$this->send_triggered_emails( get_post( $wordcamp_id ), 'wcor_wordcamp_site_created' );
	}

	/**
	 * Send emails associated with the given trigger.
	 *
	 * This will save a record of which e-mails have already been sent and avoid sending duplicates.
	 *
	 * @param WP_Post $wordcamp
	 * @param string $trigger
	 */
	protected function send_triggered_emails( $wordcamp, $trigger ) {
		$sent_email_ids = (array) get_post_meta( $wordcamp->ID, 'wcor_sent_email_ids', true );
		$emails         = $this->get_triggered_posts( $trigger );

		foreach( $emails as $email ) {
			$recipient = $this->get_recipients( $wordcamp->ID, $email->ID );

			if ( ! in_array( $email->ID, $sent_email_ids ) ) {
				if ( $this->mail( $recipient, $email->post_title, $email->post_content, array(), $email, $wordcamp ) ) {
					$sent_email_ids[] = $email->ID;
					update_post_meta( $wordcamp->ID, 'wcor_sent_email_ids', $sent_email_ids );
				}
			}
		}
	}
}

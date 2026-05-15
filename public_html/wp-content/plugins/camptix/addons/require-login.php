<?php

/**
 * Require attendees to login to the website before purchasing tickets.
 *
 * todo add a detailed explanation of the goals, workflow, etc
 */
class CampTix_Require_Login extends CampTix_Addon {
	public const UNCONFIRMED_USERNAME = '[[ unconfirmed ]]';
	public const UNKNOWN_ATTENDEE_EMAIL = 'unknown.attendee@example.org';

	/**
	 * Register hook callbacks
	 */
	public function __construct() {
		// Registration Information front-end screen.
		add_action( 'camptix_notices',                                array( $this, 'ticket_form_message' ), 8 );
		add_filter( 'camptix_ask_questions',                          array( $this, 'hide_additional_attendee_questions_during_checkout' ), 10, 5 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_username_to_attendee_object' ), 10, 3 );
		add_action( 'camptix_checkout_update_post_meta',              array( $this, 'save_checkout_username_meta' ), 10, 2 );
		add_action( 'transition_post_status',                         array( $this, 'buyer_completed_registration' ), 10, 3 );
		add_filter( 'camptix_email_tickets_template',                 array( $this, 'use_custom_email_templates' ), 5, 2 );
		add_filter( 'camptix_get_attendee_email',                     array( $this, 'redirect_unknown_attendee_emails_to_buyer' ), 10, 2 );
		add_action( 'camptix_attendee_form_before_input',             array( $this, 'inject_unknown_attendee_checkbox' ), 10, 3 );
		add_filter( 'camptix_checkout_attendee_info',                 array( $this, 'add_unknown_attendee_info_stubs' ) );
		add_filter( 'camptix_edit_info_cell_content',                 array( $this, 'show_buyer_attendee_status_instead_of_edit_link' ), 10, 2 );
		add_filter( 'camptix_attendee_info_default_value',            array( $this, 'prepopulate_known_fields' ), 10, 5 );

		// wp-admin
		add_filter( 'camptix_attendee_report_column_value_username',  array( $this, 'get_attendee_username_meta' ), 10, 2 );
		add_filter( 'camptix_save_attendee_post_add_search_meta',     array( $this, 'get_attendee_search_meta' ) );
		add_filter( 'camptix_attendee_report_extra_columns',          array( $this, 'get_attendee_report_extra_columns' ) );
		add_filter( 'camptix_metabox_attendee_info_additional_rows',  array( $this, 'get_attendee_metabox_rows' ), 10, 2 );
		add_filter( 'camptix_custom_email_templates',                 array( $this, 'register_custom_email_templates' ) );
		add_filter( 'camptix_options',                                array( $this, 'custom_email_template_option_values' ), 5 );

		// Attendee Information front-end screen
		add_action( 'camptix_form_edit_attendee_custom_error_flags',  array( $this, 'require_unique_usernames' ) );
		add_action( 'camptix_form_start_errors',                      array( $this, 'add_form_start_error_messages' ) );
		add_action( 'camptix_form_edit_attendee_update_post_meta',    array( $this, 'update_attendee_post_meta' ), 10, 2 );
		add_filter( 'camptix_save_attendee_information_label',        array( $this, 'rename_save_attendee_info_label' ), 10, 4 );
		add_filter( 'camptix_form_edit_attendee_ticket_info',         array( $this, 'replace_unknown_attendee_info_stubs' ) );

		// Misc
		add_action( 'template_redirect',                              array( $this, 'block_unauthenticated_actions' ), 7 );    // before CampTix_Plugin->template_redirect()
		add_filter( 'camptix_attendees_shortcode_query_args',         array( $this, 'hide_unconfirmed_attendees' ) );
		add_filter( 'camptix_private_attendees_parameters',           array( $this, 'prevent_unknown_attendees_viewing_private_content' ) );

		// Buyer-side claim-link recovery (issue #1721).
		add_action( 'template_redirect',                              array( $this, 'process_resend_claim_links' ), 8 );
		add_action( 'camptix_notices',                                array( $this, 'render_resend_claim_links_ui' ), 9 );
	}

	/**
	 * Block all normal CampTix checkout actions if the user is logged out
	 *
	 * If a logged-out user attempts to submit a request for any action other than 'login',
	 * it will be overriden with the 'login' action so that they first have to login.
	 */
	public function block_unauthenticated_actions() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;
		// Continue normal request, this is not a tickets page.
		if ( ! isset( $_REQUEST['tix_action'] ) ) {
			return;
		}

		// Bypass for payment webhook notifications.
		if ( 'payment_notify' === $_REQUEST['tix_action'] && isset( $_REQUEST['tix_payment_token'] ) ) {
			return;
		}

		// Bypass for coupon validation- the `tix_coupon_submit` value is set when "Apply Coupon" button is clicked.
		if ( 'attendee_info' === $_REQUEST['tix_action'] && isset( $_REQUEST['tix_coupon'], $_REQUEST['tix_coupon_submit'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {

			// Temporary: We don't want to block users from editing tickets unless they are unconfirmed.
			// See: https://github.com/WordPress/wordcamp.org/issues/1393.
			// See: https://github.com/WordPress/wordcamp.org/issues/1420.
			if ( $this->user_is_editing_ticket() && ! $this->user_must_confirm_ticket( $_REQUEST['tix_attendee_id'] ?? null ) ) {
				return;
			}

			$args = $this->get_sanitized_tix_parameters( $_REQUEST );
			$tickets_url = add_query_arg( urlencode_deep( $args ), $camptix->get_tickets_url() );

			wp_safe_redirect( add_query_arg( 'wcname', urlencode( get_bloginfo( 'name' ) ), wp_login_url( $tickets_url ) ) );
			exit();
		}
	}

	/**
	 * Get sanitized ticket parameters from request array.
	 *
	 * @param array $request_data Array of request data to sanitize.
	 * @return array Sanitized parameters.
	 */
	private function get_sanitized_tix_parameters( array $request_data ): array {
		$allowed_parameters = array(
			'tix_action'                 => 'text',
			'tix_tickets_selected'       => 'array_int',
			'tix_errors'                 => 'array_str',
			'tix_coupon'                 => 'text',
			'tix_attendee_id'            => 'int',
			'tix_edit_token'             => 'text',
			'tix_access_token'           => 'text',
			'tix_reservation_id'         => 'text',
			'tix_reservation_token'      => 'text',
			'tix_single_ticket_purchase' => 'text',
		);

		$args = array();
		foreach ( $allowed_parameters as $key => $type ) {
			if ( isset( $request_data[ $key ] ) ) {
				switch ( $type ) {
					case 'array_int':
						if ( is_array( $request_data[ $key ] ) ) {
							$args[ $key ] = array_map( 'absint', $request_data[ $key ] );
						} else {
							$args[ $key ] = array( absint( $request_data[ $key ] ) );
						}
						break;

					case 'array_str':
						if ( is_array( $request_data[ $key ] ) ) {
							$args[ $key ] = array_map( 'sanitize_text_field', $request_data[ $key ] );
						} else {
							$args[ $key ] = array( sanitize_text_field( $request_data[ $key ] ) );
						}
						break;

					case 'int':
						$args[ $key ] = absint( $request_data[ $key ] );
						break;

					case 'text':
					default:
						$args[ $key ] = sanitize_text_field( $request_data[ $key ] );
						break;
				}
			}
		}

		return $args;
	}

	/**
	 * Hide the interactive elements of the Tickets registration form if the user isn't logged in.
	 *
	 * @param $classes
	 * @return array
	 */
	public function hide_register_form_elements( $classes ) {
		if ( ! is_user_logged_in() ) {
			$classes[] = 'tix-hidden';
		}

		return $classes;
	}

	/**
	 * Add front-end notices.
	 */
	public function ticket_form_message() {
		/** @var $camptix CampTix_Plugin */
		global $camptix, $post;

		/*
		 * Don't display the message on [camptix_private] pages.
		 *
		 * The user doesn't need to log in to view them, and they're already being asked to "log in" with their
		 * name/email, so an additional message would be confusing.
		 */
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'camptix_private' ) ) {
			return;
		}

		// Warn users that they will need to login to purchase a ticket
		if ( ! is_user_logged_in() && $camptix->has_tickets_available() && ! $this->user_is_editing_ticket() ) {
			$camptix->notice( apply_filters(
				'camptix_require_login_please_login_message',
				sprintf(
					__( 'Please <a href="%1$s">log in</a> or <a href="%2$s">create an account</a> to purchase your tickets.', 'wordcamporg' ),
					wp_login_url( add_query_arg( urlencode_deep( $_REQUEST ), $this->get_redirect_return_url() ) ),
					wp_registration_url()
				)
			) );
		}

		// Inform a user registering multiple attendees that other attendees will enter their own info
		if ( isset( $_REQUEST['tix_action'], $_REQUEST['tix_tickets_selected'] ) ) {
			if ( 'attendee_info' == $_REQUEST['tix_action'] && $this->registering_multiple_attendees( $_REQUEST['tix_tickets_selected'] ) ) {
				$notice = __( '<p>Please enter your own information for the first ticket, and then enter the names and e-mail addresses of other attendees in the subsequent ticket fields.</p>', 'wordcamporg' );

				if ( $this->tickets_have_questions( $_REQUEST['tix_tickets_selected'] ) ) {
					$notice .= __( '<p>The other attendees will receive an e-mail asking them to confirm their registration and enter their additional information.</p>', 'wordcamporg' );
				}

				$camptix->notice( $notice );
			}
		}

		// Ask the attendee to confirm their registration
		if ( $this->user_is_editing_ticket() && $this->user_must_confirm_ticket( $_REQUEST['tix_attendee_id'] ?? null ) ) {
			$tickets_selected = array( get_post_meta( $_REQUEST['tix_attendee_id'], 'tix_ticket_id', true ) => 1 );  // mimic $_REQUEST['tix_tickets_selected']

			if ( $this->tickets_have_questions( $tickets_selected ) ) {
				$notice = __( 'To complete your registration, please fill out the fields below, and then click on the Confirm Registration button.', 'wordcamporg' );
			} else {
				$notice = __( 'To complete your registration, please verify that all of the information below is correct, and then click on the Confirm Registration button.', 'wordcamporg' );
			}

			$camptix->notice( $notice );
		}
	}

	/**
	 * Get the URL to return to after logging in or creating an account.
	 *
	 * @return string
	 */
	public function get_redirect_return_url() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$camptix_url = $camptix->get_tickets_url();
		$url_params  = array( 'tix_coupon', 'tix_reservation_id', 'tix_reservation_token' );

		foreach ( $url_params as $param ) {
			if ( isset( $_REQUEST[ $param ] ) ) {
				$camptix_url = add_query_arg( $param, $_REQUEST[ $param ], $camptix_url );
			}
		}

		return $camptix_url;
	}

	/**
	 * Determine if the user is registering multiple attendees
	 *
	 * @param array $tickets_selected
	 *
	 * @return bool
	 */
	protected function registering_multiple_attendees( $tickets_selected ) {
		$registering_multiple     = false;
		$number_distinct_tickets = 0;

		foreach ( $tickets_selected as $ticket_id => $number_attendees_current_ticket ) {
			$number_attendees_current_ticket = absint( $number_attendees_current_ticket );

			if ( $number_attendees_current_ticket > 0 ) {
				$number_distinct_tickets++;

				if ( $number_distinct_tickets > 1 ) {
					$registering_multiple = true;
					break;
				}

				if ( $number_attendees_current_ticket > 1 ) {
					$registering_multiple = true;
					break;
				}
			}
		}

		return $registering_multiple;
	}

	/**
	 * Determine if any of the given tickets have additional questions.
	 *
	 * @param array $tickets_selected
	 *
	 * @return bool
	 */
	protected function tickets_have_questions( $tickets_selected ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;
		$has_questions = false;

		foreach ( $tickets_selected as $ticket_id => $number_attendees_current_ticket ) {
			$number_attendees_current_ticket = absint( $number_attendees_current_ticket );

			if ( $number_attendees_current_ticket > 0 ) {
				$questions = $camptix->get_sorted_questions( $ticket_id );

				if ( count( $questions ) >= 1 ) {
					$has_questions = true;
					break;
				}
			}
		}

		return $has_questions;
	}

	/**
	 * When purchasing multiple tickets, only show questions for the buyer's ticket.
	 *
	 * We want the additional attendees to enter their own information when they confirm the ticket so that
	 * it's more accurate. This also speeds up the checkout process for the buyer and allows them to bypass
	 * questions when buying a ticket that they haven't decided who will use yet.
	 *
	 * @param bool  $ask_questions
	 * @param array $tickets_selected
	 * @param int   $ticket_id
	 * @param int   $current_attendee
	 * @param array $questions
	 *
	 * @return bool
	 */
	public function hide_additional_attendee_questions_during_checkout( $ask_questions, $tickets_selected, $ticket_id, $current_attendee, $questions ) {
		$additional_attendee_is_editing_info = isset( $_REQUEST['tix_action'] ) && 'edit_attendee' == $_REQUEST['tix_action'];
		$current_row_is_buyer                = $this->current_row_is_buyer( $tickets_selected, get_post( $ticket_id ), $current_attendee );

		if ( $current_row_is_buyer || $additional_attendee_is_editing_info ) {
			$ask_questions = true;
		} else {
			$ask_questions = false;
		}

		return $ask_questions;
	}

	/**
	 * Add the value of the username to the Attendee object used during checkout
	 *
	 * The current logged in user's username will be assigned to the first ticket and the other tickets will have
	 * an empty field because it will be filled in later when each individual confirms their registration.
	 *
	 * @param stdClass $attendee
	 * @param array $attendee_info
	 * @param int $attendee_order The order of the current attendee with respect to other attendees from the same transaction, starting at 1
	 *
	 * @return stdClass
	 */
	public function add_username_to_attendee_object( $attendee, $attendee_info, $attendee_order ) {
		if ( 1 === $attendee_order ) {
			$current_user       = wp_get_current_user();
			$attendee->username = $current_user->user_login;
		} else {
			$attendee->username = self::UNCONFIRMED_USERNAME;
		}

		return $attendee;
	}

	/**
	 * Save the attendee's username in the database.
	 *
	 * @param int $attendee_id
	 * @param stdClass $attendee
	 */
	public function save_checkout_username_meta( $attendee_id, $attendee ) {
		update_post_meta( $attendee_id, 'tix_username', $attendee->username );
	}

	/**
	 * Fire a hook to indicate that the buyer has successfully completed their transaction.
	 *
	 * It may seem odd to create a callback function just to fire another hook, but this allows other plugins to
	 * know when a buyer has completed registration without having to be aware of, and coupled to, the internal logic
	 * of this addon.
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param WP_Post $attendee
	 */
	public function buyer_completed_registration( $new_status, $old_status, $attendee ) {
		if ( 'tix_attendee' != $attendee->post_type || 'publish' == $old_status || 'publish' != $new_status ) {
			return;
		}

		$username = get_post_meta( $attendee->ID, 'tix_username', true );

		// Make sure the attendee is the buyer
		if ( CampTix_Require_Login::UNCONFIRMED_USERNAME == $username ) {
			return;
		}

		do_action( 'camptix_rl_buyer_completed_registration', $attendee, $username );
	}

	/**
	 * Retrieve the attendee's username from the database.
	 *
	 * @param array $data
	 * @param WP_Post $attendee
	 * @return string
	 */
	public function get_attendee_username_meta( $data, $attendee ) {
		$username = get_post_meta( $attendee->ID, 'tix_username', true );
		return $this->format_admin_username_display( $username, $attendee->ID );
	}

	/**
	 * Add the username to the search meta fields
	 *
	 * @param array $attendee_search_meta
	 * @return array
	 */
	public function get_attendee_search_meta( $attendee_search_meta ) {
		$attendee_search_meta[] = 'tix_username';

		return $attendee_search_meta;
	}

	/**
	 * Add the username column to the attendee report.
	 *
	 * @param array $extra_columns
	 * @return array
	 */
	public function get_attendee_report_extra_columns( $extra_columns ) {
		$extra_columns['username'] = __( 'Username', 'wordcamporg' );

		return $extra_columns;
	}

	/**
	 * Add the Username row to the Attendee Info metabox.
	 *
	 * @param array $rows
	 * @param WP_Post $post
	 * @return array
	 */
	public function get_attendee_metabox_rows( $rows, $post ) {
		$username = get_post_meta( $post->ID, 'tix_username', true );
		$rows[]   = array(
			__( 'Username', 'wordcamporg' ),
			$this->format_admin_username_display( $username, $post->ID ),
		);

		return $rows;
	}

	public function register_custom_email_templates( $templates ) {
		$templates['email_template_multiple_purchase_receipt_unconfirmed_attendees'] = array(
			'title'           => __( 'Multiple Purchase (receipt with unconfirmed attendees)', 'wordcamporg' ),
			'callback_method' => 'field_textarea',
		);

		$templates['email_template_multiple_purchase_unconfirmed_attendee'] = array(
			'title'           => __( 'Multiple Purchase (to unconfirmed attendees)', 'wordcamporg' ),
			'callback_method' => 'field_textarea',
		);

		$templates['email_template_multiple_purchase_unknown_attendee'] = array(
			'title'           => __( 'Multiple Purchase (for unknown attendees)', 'wordcamporg' ),
			'callback_method' => 'field_textarea',
		);

		return $templates;
	}

	/**
	 * Set the default custom e-mail template content.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function custom_email_template_option_values( $options ) {
		$options['email_template_multiple_purchase_receipt_unconfirmed_attendees'] ??= __( "Hi there!\n\nYou have purchased the following tickets:\n\n[receipt]\n\nYou can view and edit your order at any time before the event, by visiting the following link:\n\n[ticket_url]\n\nThe other attendees that you purchased tickets for will need to confirm their registration by visiting a link that was sent to them by e-mail.\n\nLet us know if you have any questions!", 'wordcamporg' );
		$options['email_template_multiple_purchase_unconfirmed_attendee']          ??= __( "Hi there!\n\nA ticket to [event_name] has been purchased for you by [buyer_full_name].\n\nPlease visit the following page and fill in your information to complete your registration:\n\n[ticket_url]\n\nLet us know if you have any questions!", 'wordcamporg' );
		$options['email_template_multiple_purchase_unknown_attendee']              ??= __( "Hi there!\n\nThis e-mail is for the unknown attendee that you purchased a ticket for. When you decide who will be using the ticket, please forward the link below to them so that they can complete their registration.\n\n[ticket_url]\n\nLet us know if you have any questions!", 'wordcamporg' );

		return $options;
	}

	/**
	 * Send custom e-mail templates to the purchaser and to unconfirmed attendees.
	 *
	 * @param string $template
	 * @param WP_Post $attendee
	 *
	 * @return string
	 */
	public function use_custom_email_templates( $template, $attendee ) {
		switch ( $template ) {
			case 'email_template_multiple_purchase_receipt':
				$template = 'email_template_multiple_purchase_receipt_unconfirmed_attendees';
				break;

			case 'email_template_multiple_purchase':
				$unknown_attendee_info = $this->get_unknown_attendee_info();

				if ( $unknown_attendee_info['email'] == get_post_meta( $attendee->ID, 'tix_email', true ) ) {
					$template = 'email_template_multiple_purchase_unknown_attendee';
				} elseif ( $this->user_must_confirm_ticket( $attendee->ID ) ) {
					$template = 'email_template_multiple_purchase_unconfirmed_attendee';
				}

				break;
		}

		return $template;
	}

	/**
	 * Redirect e-mails intended for unknown attendees to the ticket buyer instead.
	 *
	 * We don't know the attendee's real e-mail address, so we ask the buyer to forward the
	 * email to them once they decide who will be using the ticket.
	 *
	 * @param string $attendee_email
	 * @param int $attendee_id
	 *
	 * @return string
	 */
	public function redirect_unknown_attendee_emails_to_buyer( $attendee_email, $attendee_id ) {
		$unknown_attendee_info = $this->get_unknown_attendee_info();

		if ( $attendee_email == $unknown_attendee_info['email'] ) {
			$attendee_email = get_post_meta( $attendee_id, 'tix_receipt_email', true );
		}

		return $attendee_email;
	}

	/**
	 * Add a checkbox to indicate an unknown attendee.
	 *
	 * @param array $form_data
	 * @param WP_Post $ticket
	 * @param int $i
	 */
	public function inject_unknown_attendee_checkbox( $form_data, $ticket, $i ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		// This first attendee can't be unknown
		if ( $this->current_row_is_buyer( $form_data['tix_tickets_selected'] ?? [], $ticket, $i ) ) {
			return;
		}

		$name = 'tix_attendee_info['. $i .'][unknown_attendee]';

		?>

		<tr class="unknown-attendee">
			<td colspan="2">
				<?php $camptix->field_checkbox( array(
					'name'  => $name,
					'value' => isset( $_POST['tix_attendee_info'][ $i ]['unknown_attendee'] ),
					'class' => 'unknown-attendee',
				) ); ?>

				<label for="<?php echo esc_attr( $name ); ?>">
					&nbsp;<?php _e( "I don't know who will use this ticket yet", 'wordcamporg' ); ?>
				</label>
			</td>
		</tr>

		<?php
	}

	/**
	 * Determine if the attendee row being generated is the buyer or an additional attendee.
	 *
	 * Note: This will also return true if called in the context of an additional attendee editing their
	 * individual ticket.
	 *
	 * @param array $tickets_selected
	 * @param WP_Post $current_ticket
	 * @param int $current_attendee
	 *
	 * @return bool
	 */
	protected function current_row_is_buyer( $tickets_selected, $current_ticket, $current_attendee_row ) {
		$is_buyer = $first_ticket_id = false;

		foreach( $tickets_selected as $ticket_id => $number_tickets_selected ) {
			if ( $number_tickets_selected > 0 ) {
				$first_ticket_id = $ticket_id;
				break;
			}
		}

		if ( $first_ticket_id == $current_ticket->ID && 1 == $current_attendee_row ) {
			$is_buyer = true;
		}

		return $is_buyer;
	}

	/**
	 * Populate unknown attendee fields with stubbed values.
	 *
	 * Otherwise they would be empty and the checkout form would fail with errors.
	 *
	 * @param array $attendee_info
	 *
	 * @return array
	 */
	public function add_unknown_attendee_info_stubs( $attendee_info ) {
		$unknown_attendee_info = $this->get_unknown_attendee_info();

		if ( isset( $attendee_info['unknown_attendee'] ) ) {
			if ( empty( $attendee_info['first_name'] ) ) {
				$attendee_info['first_name'] = $unknown_attendee_info['first_name'];
			}

			if ( empty( $attendee_info['last_name'] ) ) {
				$attendee_info['last_name'] = $unknown_attendee_info['last_name'];
			}

			if ( ! is_email( $attendee_info['email'] ) ) {
				$attendee_info['email'] = $unknown_attendee_info['email'];
			}
		}

		return $attendee_info;
	}

	/**
	 * Show the buyer the status of the ticket, and a login link, instead of an 'Edit Information' link.
	 *
	 * The buyer is no longer responsible for editing attendee info, but they are responsible
	 * for ensuring that the unknown/unconfirmed attendees complete registration.
	 *
	 * @param string  $edit_link_html
	 * @param WP_Post $attendee
	 *
	 * @return string
	 */
	public function show_buyer_attendee_status_instead_of_edit_link( $edit_link_html, $attendee ) {
		global $camptix;

		$current_user          = wp_get_current_user();
		$attendee_username     = get_post_meta( $attendee->ID, 'tix_username', true );
		$unknown_attendee_info = $this->get_unknown_attendee_info();
		$is_unknown_attendee   = ( get_post_meta( $attendee->ID, 'tix_email', true ) == $unknown_attendee_info['email'] );

		// Display the ticket status using buyer-friendly labels (issue #1721).
		if ( $is_unknown_attendee ) {
			$status_text = _x( 'Status: Awaiting assignment', 'WordCamp ticket status.', 'wordcamporg' );
			$status_help = __( 'This ticket is fully paid for. It has not been assigned to a specific attendee yet — forward the claim link to whoever will use it.', 'wordcamporg' );
		} elseif ( self::UNCONFIRMED_USERNAME == $attendee_username ) {
			$status_text = _x( 'Status: Awaiting attendee', 'WordCamp ticket status.', 'wordcamporg' );
			$status_help = __( 'This ticket is fully paid for. The attendee has not yet logged in with their WordPress.org account to claim it.', 'wordcamporg' );
		} else {
			$status_text = _x( 'Status: Confirmed', 'WordCamp ticket status.', 'wordcamporg' );
			$status_help = '';
		}

		// Use a non-breaking space to prevent the status text from wrapping.
		$content = str_replace( ' ', '&nbsp;', $status_text );

		// Append a help tooltip for non-confirmed cases so buyers see at a glance that
		// the status is not a payment issue.
		if ( $status_help ) {
			$content .= ' <span class="tix-status-help" tabindex="0" aria-label="' . esc_attr( $status_help ) . '" title="' . esc_attr( $status_help ) . '">' . esc_html_x( '(?)', 'CampTix help icon', 'wordcamporg' ) . '</span>';
		}

		// Redirect back to this same overview, as they may not login with the correct username.
		$args        = $this->get_sanitized_tix_parameters( $_REQUEST );
		$tickets_url = add_query_arg( urlencode_deep( $args ), $camptix->get_tickets_url() );
		$login_link  = wp_login_url( $tickets_url );

		// If the ticket owner is known, add a hint to the url to prefill the login form.
		if ( self::UNCONFIRMED_USERNAME != $attendee_username ) {
			$login_link = add_query_arg( 'user', urlencode( $attendee_username ), $login_link );
		}

		// If the user is currently logged in, they'll need to logout first to login.
		if ( is_user_logged_in() ) {
			$login_link = wp_logout_url( $login_link );
		}

		// Several states:
		// 1. The ticket is assigned to someone, but they are not logged in.
		// 2. The ticket is assigned to someone other than the current user.
		// 3. The ticket is assigned to no-one, but the current user has a ticket already. DO NOTHING.
		// 4. The ticket is assigned to no-one, but the current user is not logged in.
		// 5. The ticket is assigned to no-one, and the current user does not have a ticket (They can claim it)
		// 6. The ticket is assigned to the current user.

		$current_user_ticket  = ( $current_user->user_login == $attendee_username );
		$assigned_to_someone  = ( self::UNCONFIRMED_USERNAME != $attendee_username );
		$assigned_to_no_one   = ( ( self::UNCONFIRMED_USERNAME == $attendee_username ) || $is_unknown_attendee );
		$this_user_has_ticket = is_user_logged_in() && ! $current_user_ticket && $this->get_ticket_of_user( $current_user );
		$login_to_claim       = $assigned_to_no_one && ! is_user_logged_in();
		$can_claim_ticket     = $assigned_to_no_one && is_user_logged_in() && ! $this_user_has_ticket;

		// 1 & 2 - Login to edit this ticeket.
		if ( $assigned_to_someone && ! $current_user_ticket ) {
			$content .= '<br><a href="' . esc_url( $login_link ) . '">' . sprintf( __( 'Login as %s to edit information', 'wordcamporg' ), esc_html( $attendee_username ) ) . '</a>';

			// 3 - NOOP, user already has a different ticket.
			// 4 - Login to claim
		} elseif ( $login_to_claim ) {
			$content .= '<br><a href="' . esc_url( $login_link ) . '">' . __( 'Login to edit information', 'wordcamporg' ) . '</a>';

			// 5 - Claim the ticket, since you don't have one.
			// 6 - Current user owns ticket, edit away.
		} elseif ( $can_claim_ticket || $current_user_ticket ) {
			$content .= '<br>' . $edit_link_html;
		}

		// Per-row "Copy claim link" control for unclaimed tickets (issue #1721).
		if ( $is_unknown_attendee || self::UNCONFIRMED_USERNAME == $attendee_username ) {
			$edit_token = get_post_meta( $attendee->ID, 'tix_edit_token', true );
			if ( $edit_token ) {
				$claim_url = $camptix->get_edit_attendee_link( $attendee->ID, $edit_token );
				$content  .= sprintf(
					'<br><button type="button" class="tix-copy-claim-link" data-claim-url="%1$s" data-copied-label="%2$s" data-prompt-label="%3$s" aria-label="%4$s">%5$s</button>',
					esc_url( $claim_url ),
					esc_attr__( 'Copied!', 'wordcamporg' ),
					esc_attr__( 'Copy this link:', 'wordcamporg' ),
					esc_attr__( "Copy this attendee's claim link to your clipboard", 'wordcamporg' ),
					esc_html__( 'Copy claim link', 'wordcamporg' )
				);
			}
		}

		return $content;
	}

	/**
	 * Fill in the buyer's info from their user profile.
	 *
	 * @param string $field_value
	 * @param string $field_name
	 * @param array $form_data
	 * @param WP_Post $ticket
	 * @param int $attendee_order
	 *
	 * @return string
	 */
	public function prepopulate_known_fields( $field_value, $field_name, $form_data, $ticket, $attendee_order ) {
		if ( 1 === $attendee_order ) {
			$current_user = wp_get_current_user();

			switch ( $field_name ) {
				case 'first_name':
					$field_value = $current_user->first_name;
					break;

				case 'last_name':
					$field_value = $current_user->last_name;
					break;

				case 'email':
					$field_value = $current_user->user_email;
					break;
			}
		}

		return $field_value;
	}

	/**
	 * Define the unknown attendee info stubs
	 *
	 * @return array
	 */
	protected function get_unknown_attendee_info() {
		$info = array(
			'first_name' => __( 'Unknown', 'wordcamporg' ),
			'last_name'  => __( 'Attendee', 'wordcamporg' ),
			'email'      => self::UNKNOWN_ATTENDEE_EMAIL,
		);

		return $info;
	}

	/**
	 * Ensure that each attendee is mapped to only one username.
	 *
	 * This prevents the buyer of a group of tickets from completing registration for the other attendees.
	 *
	 * @param WP_Post $attendee
	 */
	public function require_unique_usernames( $attendee ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		// Check to see if user is a WordCamp admin, and if so, allow them to edit any attendee.
		if ( current_user_can( 'edit_post', $attendee->ID ) ) {
			return;
		}

		$current_user = wp_get_current_user();
		$confirmed_usernames = $this->get_confirmed_usernames(
			get_post_meta( $attendee->ID, 'tix_ticket_id', true ),
			get_post_meta( $attendee->ID, 'tix_payment_token', true )
		);

		if (
			get_post_meta( $attendee->ID, 'tix_username', true ) != $current_user->user_login &&
			in_array( $current_user->user_login, $confirmed_usernames )
		) {
			$camptix->error_flag( 'require_login_edit_attendee_duplicate_username' );
			$camptix->redirect_with_error_flags();
		}
	}

	/**
	 * Get all of the usernames of confirmed attendees from group of tickets that was purchased together.
	 *
	 * @param int $ticket_id
	 * @param string $payment_token
	 *
	 * @return array
	 */
	protected function get_confirmed_usernames( $ticket_id, $payment_token ) {
		$usernames = array();

		$other_attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'tix_attendee',
			'post_status'    => array( 'pending', 'publish' ),

			'meta_query'   => array(
				'relation' => 'AND',

				array(
					'key'   => 'tix_ticket_id',
					'value' => $ticket_id,
				),

				array(
					'key'   => 'tix_payment_token',
					'value' => $payment_token,
				)
			)
		) );

		foreach ( $other_attendees as $attendee ) {
			$username = get_post_meta( $attendee->ID, 'tix_username', true );

			if ( ! empty( $username ) && self::UNCONFIRMED_USERNAME != $username ) {
				$usernames[] = $username;
			}
		}

		return $usernames;
	}

	/**
	 * Define the error messages that correspond to our custom error codes.
	 *
	 * @param array $errors
	 */
	public function add_form_start_error_messages( $errors ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( isset( $errors['require_login_edit_attendee_duplicate_username'] ) ) {
			$camptix->error( __( "You cannot edit the requested attendee's information because your user account has already been assigned to another ticket. Please ask the person using this ticket to sign in with their own account and fill out their information.", 'wordcamporg' ) );
		}
	}

	/**
	 * Update the username when saving an Attendee post.
	 *
	 * This fires when a user is editing their individual information, so the current user
	 * should be the person that the ticket was purchased for.
	 *
	 * If an admin is editing a confirmed attendee, then we assume that they're just adjusting some data
	 * on behalf of the attendee, rather than assuming ownership of the ticket, so we don't overwrite the
	 * attendee's username with the admin's username.
	 *
	 * @param array $new_ticket_info
	 * @param WP_Post $attendee
	 */
	public function update_attendee_post_meta( $new_ticket_info, $attendee ) {
		$current_user = wp_get_current_user();
		$old_username = get_post_meta( $attendee->ID, 'tix_username', true );

		// If no changes, or no username known, nothing to do.
		if (
			$old_username === $current_user->user_login ||
			! $current_user->user_login
		) {
			return;
		}

		// If the user is an admin, don't even attempt to sync the username UNLESS the email matches.
		if (
			current_user_can( 'edit_post', $attendee->ID ) &&
			$new_ticket_info['email'] != $current_user->user_email
		) {
			return;
		}

		update_post_meta( $attendee->ID, 'tix_username', $current_user->user_login );

		if ( self::UNCONFIRMED_USERNAME == $old_username ) {
			do_action( 'camptix_rl_registration_confirmed', $attendee->ID, $current_user->user_login );
		}
	}

	/**
	 * Change the 'Save Attendee Information' button to read 'Confirm Registration'.
	 *
	 * This helps encourage the user to verify their registration by suggestion that it's necessary.
	 *
	 * @param string $label
	 * @param WP_Post $attendee
	 * @param WP_Post $ticket
	 * @param array $questions
	 *
	 * @return string
	 */
	public function rename_save_attendee_info_label( $label, $attendee, $ticket, $questions ) {
		if ( $this->user_must_confirm_ticket( $attendee->ID ) ) {
			$label = __( 'Confirm Registration', 'wordcamporg' );
		}

		return $label;
	}

	/**
	 * Replace the stubbed unknown attendee info values with user's profile data.
	 *
	 * @param array $ticket_info
	 *
	 * @return array
	 */
	public function replace_unknown_attendee_info_stubs( $ticket_info ) {
		$current_user          = wp_get_current_user();
		$unknown_attendee_info = $this->get_unknown_attendee_info();
		$replacement_values    = array(
			'first_name' => $current_user->first_name,
			'last_name'  => $current_user->last_name,
			'email'      => $current_user->user_email,
		);

		foreach ( $ticket_info as $key => $value ) {
			if ( $value == $unknown_attendee_info[ $key ] ) {
				$ticket_info[ $key ] = $replacement_values[ $key ];
			}
		}

		return $ticket_info;
	}

	/**
	 * Remove unconfirmed attendees from the [attendees] shortcode output.
	 *
	 * @param array $query_args
	 *
	 * @return array
	 */
	public function hide_unconfirmed_attendees( $query_args ) {
		$meta_query = array(
			array(
				'key'     => 'tix_username',
				'value'   => self::UNCONFIRMED_USERNAME,
				'compare' => '!=',
			),
			'relation' => 'OR',
			array(
				'key' => 'tix_username',
				'compare' => 'NOT EXISTS',
			)
		);

		if ( isset( $query_args['meta_query'] ) ) {
			$query_args['meta_query'][] = $meta_query;
		} else {
			$query_args['meta_query'] = array( $meta_query );
		}

		return $query_args;
	}

	/*
	 * Prevent unknown attendees from viewing private content.
	 *
	 * The name/email used for unknown attendees is revealed not secret, so anyone could use them to login to a
	 * page with [camptix_private] content.
	 */
	public function prevent_unknown_attendees_viewing_private_content( $parameters ) {
		$parameters['meta_query'][] = array(
			'key'     => 'tix_email',
			'value'   => self::UNKNOWN_ATTENDEE_EMAIL,
			'compare' => '!='
		);

		return $parameters;
	}

	/**
	 * Checks if the user is performing actions that require ticket access.
	 *
	 * @return bool True if the user is editing or accessing a ticket, false otherwise.
	 */
	protected function user_is_editing_ticket() {
		return isset( $_REQUEST['tix_action'] ) && in_array( $_REQUEST['tix_action'], array( 'access_tickets', 'edit_attendee' ) );
	}

	/**
	 * Checks if the user associated with the given attendee ID must confirm their ticket.
	 * Unconfirmed tickets exist when one user purchases multiple tickets.
	 *
	 * @param int $attendee_id The ID of the attendee. If null or invalid, the function returns false.
	 *
	 * @return bool True if the attendee must confirm their ticket, false otherwise.
	 */
	protected function user_must_confirm_ticket( $attendee_id ) {
		return isset( $attendee_id ) && self::UNCONFIRMED_USERNAME == get_post_meta( $attendee_id, 'tix_username', true );
	}

	/**
	 * Retrieve the ticket associated with the given user.
	 *
	 * @param WP_User $user The user object for whom to retrieve the ticket.
	 * @return bool|WP_Post The ticket post object if found, false otherwise.
	 */
	protected function get_ticket_of_user( WP_User $user ) {
		if ( empty( $user->user_login ) ) {
			return false;
		}

		$ticket = get_posts( array(
			'posts_per_page' => 1,
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => 'tix_username',
					'value' => $user->user_login,
				),
			),
		) );

		return $ticket ? reset( $ticket ) : false;
	}

	/**
	 * Render the username field for admin views, with a friendly label for
	 * unclaimed/unknown tickets while keeping the raw value visible (issue #1721).
	 *
	 * @param string $username    Stored username (may be UNCONFIRMED_USERNAME).
	 * @param int    $attendee_id Attendee post ID, used to detect Unknown tickets.
	 * @return string Escaped HTML.
	 */
	protected function format_admin_username_display( $username, $attendee_id ) {
		$unknown_email = $this->get_unknown_attendee_info()['email'];
		$is_unknown    = ( get_post_meta( $attendee_id, 'tix_email', true ) == $unknown_email );

		if ( $is_unknown ) {
			$label = _x( 'Awaiting assignment', 'WordCamp ticket status.', 'wordcamporg' );
		} elseif ( self::UNCONFIRMED_USERNAME == $username ) {
			$label = _x( 'Awaiting attendee', 'WordCamp ticket status.', 'wordcamporg' );
		} else {
			return esc_html( $username );
		}

		return sprintf(
			'<span class="tix-status-pill">%1$s</span> <code class="tix-status-raw">%2$s</code>',
			esc_html( $label ),
			esc_html( $username )
		);
	}

	/**
	 * Mask an email address for display in buyer-facing confirmation notices.
	 *
	 * Example: jane.doe@example.com → j***@e****.com
	 *
	 * @param string $email
	 * @return string
	 */
	protected function mask_email_for_notice( $email ) {
		if ( ! is_email( $email ) ) {
			return '';
		}

		list( $local, $domain ) = explode( '@', $email, 2 );
		$tld_pos = strrpos( $domain, '.' );
		if ( false === $tld_pos ) {
			return '';
		}

		$domain_name = substr( $domain, 0, $tld_pos );
		$tld         = substr( $domain, $tld_pos );

		return mb_substr( $local, 0, 1 ) . '***@' . mb_substr( $domain_name, 0, 1 ) . '****' . $tld;
	}

	/**
	 * Process the "Email me my claim links" form submission (issue #1721).
	 *
	 * Hooked on template_redirect (priority 8, after block_unauthenticated_actions
	 * at 7 and before CampTix shortcode rendering). Walks every attendee on the
	 * access-token order, re-sends the appropriate multiple-purchase template to
	 * each Unconfirmed/Unknown ticket, and rate-limits to one resend per ticket
	 * per hour. Stores a transient with the result summary for the follow-up GET
	 * request to render via camptix_notices.
	 */
	public function process_resend_claim_links() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( empty( $_POST['tix_resend_claim_links'] ) ) {
			return;
		}

		$access_token = isset( $_POST['tix_access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['tix_access_token'] ) ) : '';
		if ( ! $access_token || ! ctype_alnum( $access_token ) ) {
			return;
		}

		$nonce = isset( $_POST['tix_resend_nonce'] ) ? wp_unslash( $_POST['tix_resend_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tix_resend_claim_links_' . $access_token ) ) {
			return;
		}

		$attendees = get_posts( array(
			'posts_per_page' => 200,
			'post_type'      => 'tix_attendee',
			'post_status'    => array( 'publish', 'pending' ),
			'meta_query'     => array(
				array(
					'key'     => 'tix_access_token',
					'value'   => $access_token,
					'compare' => '=',
					'type'    => 'CHAR',
				),
			),
			'cache_results'  => false,
		) );

		$sent          = array();
		$throttled     = 0;
		$failed        = 0;
		$unknown_email = $this->get_unknown_attendee_info()['email'];

		foreach ( $attendees as $attendee ) {
			$username       = get_post_meta( $attendee->ID, 'tix_username', true );
			$email          = get_post_meta( $attendee->ID, 'tix_email', true );
			$is_unknown     = ( $email == $unknown_email );
			$is_unconfirmed = ( self::UNCONFIRMED_USERNAME == $username );

			if ( ! $is_unknown && ! $is_unconfirmed ) {
				continue;
			}

			$throttle_key = 'camptix_rl_resend_' . $attendee->ID;
			if ( get_transient( $throttle_key ) ) {
				$throttled++;
				continue;
			}

			// Reuse CampTix's existing send pipeline — runs through use_custom_email_templates()
			// which already picks the unknown/unconfirmed variant and redirects unknown-attendee
			// mail to the buyer.
			$result = $camptix->email_attendee_ticket_multiple_template( $attendee );

			if ( $result ) {
				set_transient( $throttle_key, time(), HOUR_IN_SECONDS );
				$sent[] = $this->mask_email_for_notice( $camptix->get_attendee_email( $attendee->ID ) );
			} else {
				$failed++;
			}
		}

		set_transient(
			'camptix_rl_resend_summary_' . $access_token,
			array(
				'sent'      => $sent,
				'throttled' => $throttled,
				'failed'    => $failed,
			),
			MINUTE_IN_SECONDS * 5
		);

		$redirect = add_query_arg(
			array(
				'tix_action'       => 'access_tickets',
				'tix_access_token' => $access_token,
				'tix_resend_done'  => 1,
			),
			$camptix->get_tickets_url()
		) . '#tix';

		wp_safe_redirect( esc_url_raw( $redirect ) );
		die();
	}

	/**
	 * Render the "Email me my claim links" form and any post-resend notices on
	 * the ticket overview page (issue #1721).
	 *
	 * Hooked on camptix_notices. Active only when viewing the access_tickets
	 * screen with a valid access token AND the order has at least one
	 * Unconfirmed/Unknown ticket.
	 */
	public function render_resend_claim_links_ui() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$tix_action   = isset( $_GET['tix_action'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_action'] ) ) : '';
		$access_token = isset( $_GET['tix_access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['tix_access_token'] ) ) : '';

		if ( 'access_tickets' !== $tix_action || ! $access_token || ! ctype_alnum( $access_token ) ) {
			return;
		}

		if ( ! empty( $_GET['tix_resend_done'] ) ) {
			$this->render_resend_summary_notice( $access_token );
		}

		$unclaimed = get_posts( array(
			'posts_per_page' => 1,
			'post_type'      => 'tix_attendee',
			'post_status'    => array( 'publish', 'pending' ),
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'tix_access_token',
					'value'   => $access_token,
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'   => 'tix_username',
						'value' => self::UNCONFIRMED_USERNAME,
					),
					array(
						'key'   => 'tix_email',
						'value' => $this->get_unknown_attendee_info()['email'],
					),
				),
			),
			'cache_results'  => false,
		) );

		if ( empty( $unclaimed ) ) {
			return;
		}

		// Keep tix_action + token in the form URL so that if the handler returns early
		// (bad nonce, etc.) the buyer lands back on access_tickets, not the purchase form.
		$action_url = add_query_arg(
			array(
				'tix_action'       => 'access_tickets',
				'tix_access_token' => $access_token,
			),
			$camptix->get_tickets_url()
		);
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>#tix" class="tix-resend-claim-links">
			<input type="hidden" name="tix_access_token" value="<?php echo esc_attr( $access_token ); ?>">
			<?php wp_nonce_field( 'tix_resend_claim_links_' . $access_token, 'tix_resend_nonce' ); ?>
			<p class="tix-resend-claim-links__help">
				<?php esc_html_e( 'Lost the ticket emails? Re-send the claim link for every ticket in this order that is still awaiting an attendee.', 'wordcamporg' ); ?>
			</p>
			<p>
				<button type="submit" name="tix_resend_claim_links" value="1" class="tix-resend-claim-links__button">
					<?php esc_html_e( 'Email me my claim links', 'wordcamporg' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the result notice after a resend submission.
	 *
	 * @param string $access_token
	 */
	protected function render_resend_summary_notice( $access_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$summary = get_transient( 'camptix_rl_resend_summary_' . $access_token );
		if ( ! $summary ) {
			return;
		}
		delete_transient( 'camptix_rl_resend_summary_' . $access_token );

		$sent_count = count( $summary['sent'] );

		if ( $sent_count > 0 ) {
			$camptix->notice( sprintf(
				/* translators: 1) count of emails sent, 2) comma-separated masked addresses */
				_n(
					'Re-sent %1$d claim email to: %2$s. Please allow a few minutes for delivery and check your spam folder.',
					'Re-sent %1$d claim emails to: %2$s. Please allow a few minutes for delivery and check your spam folder.',
					$sent_count,
					'wordcamporg'
				),
				$sent_count,
				implode( ', ', array_map( 'esc_html', $summary['sent'] ) )
			) );
		}

		if ( $summary['throttled'] > 0 ) {
			$camptix->notice( sprintf(
				/* translators: %d: count of tickets skipped due to rate-limit */
				_n(
					'%d ticket was re-sent within the last hour and was skipped — check your inbox and spam folder before re-trying.',
					'%d tickets were re-sent within the last hour and were skipped — check your inbox and spam folder before re-trying.',
					$summary['throttled'],
					'wordcamporg'
				),
				$summary['throttled']
			) );
		}

		if ( $summary['failed'] > 0 ) {
			$camptix->error( sprintf(
				/* translators: %d: count of tickets that failed to send */
				_n(
					'%d claim email could not be sent. Please contact the event organisers.',
					'%d claim emails could not be sent. Please contact the event organisers.',
					$summary['failed'],
					'wordcamporg'
				),
				$summary['failed']
			) );
		}
	}

} // CampTix_Require_Login

camptix_register_addon( 'CampTix_Require_Login' );

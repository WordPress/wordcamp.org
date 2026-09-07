<?php
/**
 * Companion Tickets addon for CampTix.
 *
 * Designate one or more tickets as "companion" tickets — free, capacity-limited
 * (via each ticket's own `tix_quantity`) extras such as Contributor Day or a
 * Social Dinner. Registration for any companion ticket is self-service and
 * gated: only an attendee who is logged in AND already holds a published
 * qualifying main ticket may register themselves, once per companion ticket.
 * Each companion attendee record is linked to that attendee's main attendee
 * record. Refunding/cancelling the main ticket cascades a cancel to ALL of its
 * linked companion seats, returning them to their pools.
 *
 * Note on naming: internally the concept is "companion" (class, option keys,
 * meta) but the user-facing label is "Activity ticket" — the two are decoupled
 * on purpose, so the display name can change without touching stored config.
 *
 * Hard dependency: this addon relies on the `require-login` addon's
 * `tix_username` meta and confirmed-attendee identity (enabled by default on
 * WordCamp.org). When `require-login` is inactive an admin notice is shown and
 * the gate fails closed (companion registration is blocked), since the attendee
 * identity it needs is unavailable.
 *
 * Note: refunding/cancelling the main ticket is terminal for the linked
 * companion seats. Re-publishing the main ticket later does not restore them
 * (it would risk overselling the capacity-limited tickets); the attendee must
 * register again while a seat is available.
 */
class CampTix_Companion_Tickets_Addon extends CampTix_Addon {

	const REQUIRES_FLAG      = 'companion_ticket_requires_main';
	const DUPLICATE_FLAG     = 'companion_ticket_already_registered';
	const SELF_ONLY_FLAG     = 'companion_ticket_self_only';
	const IN_PROGRESS_FLAG   = 'companion_ticket_in_progress';
	const ONE_AT_A_TIME_FLAG = 'companion_ticket_one_at_a_time';

	const UNCONFIRMED_USERNAME = '[[ unconfirmed ]]';

	// The camptix-admin-flags addon's attendee meta key: one row per set flag,
	// meta_value = the flag slug (must be a key in its parsed config to show).
	const ADMIN_FLAG_META = 'camptix-admin-flag';

	// The flag slug written for a seat, recorded so removal cannot be defeated by a
	// later change to the ticket's post_name.
	const ACTIVITY_FLAG_META = 'tix_companion_activity_flag';

	/**
	 * Per-request cache of companion ticket IDs a username already holds.
	 *
	 * @var array<string,int[]>
	 */
	private $held_companion_cache = array();

	/**
	 * Attendee IDs queued for release (cancel) at shutdown.
	 *
	 * A transfer is detected mid-save, while CampTix still holds a stale copy of
	 * the post it will rewrite when the save finishes — cancelling inline would
	 * be clobbered. Deferring to shutdown runs after the whole save completes.
	 *
	 * @var array<int,bool>
	 */
	private $deferred_releases = array();

	/**
	 * Register self with CampTix.
	 */
	public static function register_addon() {
		camptix_register_addon( __CLASS__ );
	}

	/**
	 * Constructor: defer wiring until CampTix is initialised.
	 */
	public function __construct() {
		add_action( 'camptix_init', array( $this, 'camptix_init' ) );
	}

	/**
	 * Runs during camptix_init: wire config UI + checkout/runtime hooks.
	 */
	public function camptix_init() {
		global $camptix;

		// Hard dependency: the require-login addon supplies the attendee
		// identity (tix_username) this addon gates on. Warn if it's missing.
		// The gate still fails closed without it (no identity => not eligible).
		if ( ! $this->require_login_active() ) {
			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		}

		// Admin: Setup screen configuration.
		if ( current_user_can( $camptix->caps['manage_options'] ) ) {
			add_filter( 'camptix_setup_sections',      array( $this, 'setup_sections' ) );
			add_action( 'camptix_menu_setup_controls', array( $this, 'setup_controls' ), 10, 1 );
			add_filter( 'camptix_validate_options',    array( $this, 'validate_options' ), 10, 2 );
		}

		// Public ticket-selection screen: tell not-yet-eligible visitors what's
		// needed, and show already-held tickets as "already registered" instead
		// of letting them appear to silently vanish.
		add_action( 'camptix_notices',                  array( $this, 'maybe_show_eligibility_notice' ), 9 );
		add_filter( 'camptix_form_start_tix_remaining', array( $this, 'maybe_label_already_registered' ), 10, 2 );

		// Public checkout: gate ineligible registrations, link, and cascade.
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'gate_companion_attendee' ), 20, 3 );
		add_action( 'camptix_form_attendee_info_errors',              array( $this, 'render_gate_errors' ), 10, 1 );
		add_action( 'camptix_checkout_update_post_meta',              array( $this, 'link_companion_attendee' ), 20, 2 );
		add_action( 'transition_post_status',                         array( $this, 'maybe_cascade_cancel' ), 10, 3 );

		// Force-delete bypasses transition_post_status entirely, so a deleted
		// attendee needs its own hook: release the seats a deleted main was
		// justifying, and unflag a deleted seat's (surviving) main.
		add_action( 'before_delete_post', array( $this, 'maybe_release_on_delete' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_heal_activity_admin_flag_config' ) );

		// Email each attendee a confirmation when their companion seat is confirmed.
		add_action( 'transition_post_status', array( $this, 'maybe_send_activity_confirmation' ), 10, 3 );

		// Attendee export/report: expose the main<->companion link as columns.
		add_filter( 'camptix_attendee_report_extra_columns',                  array( $this, 'export_extra_columns' ) );
		add_filter( 'camptix_attendee_report_column_value_companion_of',      array( $this, 'export_column_companion_of' ), 10, 2 );
		add_filter( 'camptix_attendee_report_column_value_companion_tickets', array( $this, 'export_column_companion_tickets' ), 10, 2 );

		// Auto admin-flag: the flag is set on the main attendee when a companion
		// seat links to it (see link_companion_attendee); remove it again when
		// that seat reaches any terminal status. Display needs camptix-admin-flags.
		add_action( 'transition_post_status', array( $this, 'maybe_remove_activity_admin_flag' ), 12, 3 );

		// Public Attendees page: list each person once. A companion seat's holder
		// always also has a main-ticket listing, so hide the companion seats.
		add_filter( 'camptix_attendees_shortcode_query_args', array( $this, 'exclude_companion_attendees_from_listing' ), 10, 2 );

		// Ownership transfer: when a ticket's confirmed identity changes hands
		// (require-login reassigns tix_username via the edit-attendee flow),
		// release the companion seats that were personal to the old owner.
		add_action( 'update_post_meta', array( $this, 'detect_ownership_change' ), 10, 4 );

		// "Your tickets" links on the selection screen + a throttled
		// email-me-my-links action, for holders who lost their receipt email.
		add_action( 'camptix_notices',   array( $this, 'maybe_show_my_ticket_links' ), 8 );
		add_action( 'template_redirect', array( $this, 'process_links_email_request' ), 9 );

		// Admin: show the main<->companion links on the Edit Attendee screen.
		if ( current_user_can( $camptix->caps['manage_attendees'] ) ) {
			add_action( 'camptix_attendee_submitdiv_misc', array( $this, 'render_link_metabox' ), 10, 1 );
		}
	}

	/**
	 * Whether the require-login addon (this addon's hard dependency) is active.
	 *
	 * @return bool
	 */
	public function require_login_active() {
		return class_exists( 'CampTix_Require_Login' );
	}

	/**
	 * Admin notice shown when the require-login dependency is not active.
	 */
	public function render_dependency_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'CampTix Activity Tickets requires the CampTix "require login" addon, which is not active. Activity ticket registration is disabled until it is enabled.', 'wordcamporg' )
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Configuration
	 * -------------------------------------------------------------------------
	 */

	/**
	 * The configured companion ticket IDs.
	 *
	 * @return int[]
	 */
	public function get_companion_ticket_ids() {
		global $camptix;
		$options = $camptix->get_options();
		$ids     = isset( $options['camptix-companion-ticket-ids'] ) ? (array) $options['camptix-companion-ticket-ids'] : array();
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Whether a ticket ID is one of the configured companion tickets.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return bool
	 */
	public function is_companion_ticket( $ticket_id ) {
		return in_array( absint( $ticket_id ), $this->get_companion_ticket_ids(), true );
	}

	/**
	 * Whitelist of ticket IDs that count as a "qualifying main ticket".
	 * Empty config = any published ticket that is not a companion ticket.
	 *
	 * @return int[] Empty array means "any non-companion ticket".
	 */
	public function get_qualifying_ticket_ids() {
		global $camptix;
		$options = $camptix->get_options();
		$ids     = isset( $options['camptix-companion-qualifying-ticket-ids'] ) ? (array) $options['camptix-companion-qualifying-ticket-ids'] : array();
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Add an "Activity Tickets" tab to CampTix > Setup.
	 *
	 * @param array $sections Existing Setup tab sections.
	 * @return array Sections with the Activity Tickets tab added.
	 */
	public function setup_sections( $sections ) {
		$sections['companion-tickets'] = __( 'Activity Tickets', 'wordcamporg' );
		return $sections;
	}

	/**
	 * Render the controls for the Activity Tickets setup section.
	 *
	 * @param string $section The Setup section currently being rendered.
	 */
	public function setup_controls( $section ) {
		if ( 'companion-tickets' !== $section ) {
			return;
		}

		add_settings_section( 'companion-tickets', __( 'Activity Tickets', 'wordcamporg' ), '__return_false', 'camptix_options' );

		add_settings_field(
			'camptix-companion-ticket-ids',
			__( 'Activity tickets', 'wordcamporg' ),
			array( $this, 'render_companion_select' ),
			'camptix_options',
			'companion-tickets'
		);

		add_settings_field(
			'camptix-companion-qualifying-ticket-ids',
			__( 'Qualifying tickets', 'wordcamporg' ),
			array( $this, 'render_qualifying_select' ),
			'camptix_options',
			'companion-tickets'
		);
	}

	/**
	 * All ticket posts (publish + draft), title-sorted, for the admin checkboxes.
	 *
	 * @return WP_Post[]
	 */
	private function get_all_tickets() {
		return get_posts( array(
			'post_type'        => 'tix_ticket',
			'post_status'      => array( 'publish', 'draft' ),
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin-only list, bounded by the number of ticket types for the event.
			'posts_per_page'   => 200,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );
	}

	/**
	 * Render checkboxes choosing which tickets are activity (free add-on) tickets.
	 */
	public function render_companion_select() {
		$selected = $this->get_companion_ticket_ids();
		$tickets  = $this->get_all_tickets();

		// Marker so an all-unchecked save is recognised as "clear".
		echo '<input type="hidden" name="camptix_options[camptix-companion-tickets-rendered]" value="1" />';

		if ( empty( $tickets ) ) {
			echo '<p class="description">' . esc_html__( 'No ticket types found. Create a free activity ticket (e.g. Contributor Day) first.', 'wordcamporg' ) . '</p>';
			return;
		}

		foreach ( $tickets as $ticket ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="camptix_options[camptix-companion-ticket-ids][]" value="%d" %s /> %s</label>',
				absint( $ticket->ID ),
				checked( in_array( absint( $ticket->ID ), $selected, true ), true, false ),
				esc_html( $ticket->post_title )
			);
		}
		echo '<p class="description">' . esc_html__( 'Checked tickets are free, capacity-limited extras that only existing ticket-holders may register for (one each).', 'wordcamporg' ) . '</p>';
	}

	/**
	 * Render checkboxes choosing which main tickets qualify a holder.
	 *
	 * Leaving every box unchecked = any non-companion published ticket qualifies.
	 */
	public function render_qualifying_select() {
		$companion_ids = $this->get_companion_ticket_ids();
		$selected      = $this->get_qualifying_ticket_ids();
		$tickets       = array_filter(
			$this->get_all_tickets(),
			function ( $ticket ) use ( $companion_ids ) {
				return ! in_array( absint( $ticket->ID ), $companion_ids, true );
			}
		);

		// Marker so an all-unchecked save is recognised as "clear".
		echo '<input type="hidden" name="camptix_options[camptix-companion-qualifying-rendered]" value="1" />';

		if ( empty( $tickets ) ) {
			echo '<p class="description">' . esc_html__( 'No non-activity ticket types exist yet.', 'wordcamporg' ) . '</p>';
			return;
		}

		foreach ( $tickets as $ticket ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="camptix_options[camptix-companion-qualifying-ticket-ids][]" value="%d" %s /> %s</label>',
				absint( $ticket->ID ),
				checked( in_array( absint( $ticket->ID ), $selected, true ), true, false ),
				esc_html( $ticket->post_title )
			);
		}
		echo '<p class="description">' . esc_html__( 'Holders of the checked tickets may register for activity tickets. Leave all unchecked to allow holders of any other (non-activity) published ticket.', 'wordcamporg' ) . '</p>';
	}

	/**
	 * Sanitise our options on save.
	 *
	 * @param array $output Validated options so far.
	 * @param array $input  Raw submitted options.
	 * @return array
	 */
	public function validate_options( $output, $input ) {
		// Companion tickets. Accept the array when present, or treat an all-
		// unchecked UI save (marker present, no array) as "clear".
		if ( isset( $input['camptix-companion-ticket-ids'] ) || isset( $input['camptix-companion-tickets-rendered'] ) ) {
			$raw                                    = isset( $input['camptix-companion-ticket-ids'] ) ? (array) $input['camptix-companion-ticket-ids'] : array();
			$ids                                    = array_filter( array_map( 'absint', $raw ) );
			$output['camptix-companion-ticket-ids'] = array_values( array_unique( $ids ) );
		}

		// Qualifying main tickets. Same accept/clear behaviour, and a companion
		// ticket can never also be a qualifying ticket.
		if ( isset( $input['camptix-companion-qualifying-ticket-ids'] ) || isset( $input['camptix-companion-qualifying-rendered'] ) ) {
			$raw = isset( $input['camptix-companion-qualifying-ticket-ids'] ) ? (array) $input['camptix-companion-qualifying-ticket-ids'] : array();
			$ids = array_filter( array_map( 'absint', $raw ) );

			$companion_ids = isset( $output['camptix-companion-ticket-ids'] ) ? (array) $output['camptix-companion-ticket-ids'] : $this->get_companion_ticket_ids();
			$ids           = array_diff( $ids, $companion_ids );

			$output['camptix-companion-qualifying-ticket-ids'] = array_values( $ids );
		}

		// When the companion set changes: make sure each companion ticket has a
		// visible admin-flag definition, and flush the public Attendees listing
		// cache (its cache key ignores our query filter, so stale HTML would
		// keep showing companion seats until the transient expired).
		if ( isset( $input['camptix-companion-ticket-ids'] ) || isset( $input['camptix-companion-tickets-rendered'] ) ) {
			$output = $this->sync_activity_admin_flag_config( $output );
			$this->flush_attendees_shortcode_cache();
		}

		return $output;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Eligibility
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Find a published, confirmed main-ticket attendee for the given username.
	 *
	 * "Confirmed" means tix_username is a real login (not the require-login
	 * UNCONFIRMED sentinel). A companion ticket never qualifies as a main ticket.
	 *
	 * @param string $username WP.org login.
	 * @return int Attendee post ID, or 0 if none.
	 */
	public function get_user_main_attendee( $username ) {
		$username = (string) $username;
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			return 0;
		}

		$meta_query = array(
			array(
				'key'   => 'tix_username',
				'value' => $username,
			),
		);

		$qualifying = $this->get_qualifying_ticket_ids();
		if ( ! empty( $qualifying ) ) {
			$meta_query[] = array(
				'key'     => 'tix_ticket_id',
				'value'   => $qualifying,
				'compare' => 'IN',
			);
		}

		$attendees = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => 'publish',
			'posts_per_page'   => 50,
			'fields'           => 'ids',
			'meta_query'       => $meta_query,
			'suppress_filters' => false,
		) );

		$companion_ids = $this->get_companion_ticket_ids();
		foreach ( $attendees as $attendee_id ) {
			$ticket_id = absint( get_post_meta( $attendee_id, 'tix_ticket_id', true ) );
			if ( $ticket_id && ! in_array( $ticket_id, $companion_ids, true ) ) {
				return (int) $attendee_id;
			}
		}

		return 0;
	}

	/**
	 * Attendee statuses that hold a seat.
	 *
	 * Exactly the statuses CampTix counts against a ticket's quantity
	 * (`get_purchased_tickets_count()`, camptix.php): a `pending` seat is
	 * awaiting payment confirmation but has already drained the pool, so the
	 * duplicate gate has to see it too. Everything else has either not claimed
	 * the seat yet (`draft`, see `user_has_in_flight_registration()`) or has
	 * released it (`cancel`, `refund`, `failed`, `timeout`).
	 *
	 * @return string[]
	 */
	private function held_statuses() {
		return array( 'publish', 'pending' );
	}

	/**
	 * Whether this username already holds a seat for a ticket.
	 *
	 * @param string $username  WP.org login.
	 * @param int    $ticket_id Companion ticket ID.
	 * @return bool
	 */
	public function user_has_registration( $username, $ticket_id ) {
		$username = (string) $username;
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			return false;
		}

		$existing = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => $this->held_statuses(),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => 'tix_username',
					'value' => $username,
				),
				array(
					'key'   => 'tix_ticket_id',
					'value' => absint( $ticket_id ),
				),
			),
			'suppress_filters' => false,
		) );

		return ! empty( $existing );
	}

	/**
	 * Whether this username has an order for a ticket still in flight.
	 *
	 * A seat sits in `draft` between the attendee form being submitted and the
	 * gateway resolving the order — minutes, for a redirect gateway. It has not
	 * drained the pool yet, but it will the moment the payment completes, so a
	 * second (free, instantly published) registration in that window would
	 * oversell a capacity-limited ticket and leave the attendee with two seats.
	 *
	 * The window matches CampTix's own: `review_timeout_payments()` leaves a
	 * draft live for 24 hours before flipping it to `timeout`. Inside it the
	 * order can still publish, so block; outside it the draft is abandoned and
	 * must not keep the attendee out of a seat they never received.
	 *
	 * @param string $username  WP.org login.
	 * @param int    $ticket_id Companion ticket ID.
	 * @return bool
	 */
	public function user_has_in_flight_registration( $username, $ticket_id ) {
		$username = (string) $username;
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			return false;
		}

		/**
		 * Filters how long a draft seat counts as an order still in flight.
		 *
		 * Defaults to CampTix's own draft-timeout window. Shortening it trades
		 * a smaller double-registration window for a smaller retry delay after
		 * an abandoned checkout.
		 *
		 * @param int $window Seconds.
		 */
		$window = absint( apply_filters( 'camptix_companion_in_flight_window', DAY_IN_SECONDS ) );

		$in_flight = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => 'draft',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => 'tix_username',
					'value' => $username,
				),
				array(
					'key'   => 'tix_ticket_id',
					'value' => absint( $ticket_id ),
				),
				// Set on every checkout draft, immediately before the hook this
				// addon links seats on (camptix.php).
				array(
					'key'     => 'tix_timestamp',
					'value'   => time() - $window,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
			'suppress_filters' => false,
		) );

		return ! empty( $in_flight );
	}

	/**
	 * Companion ticket IDs the given username already holds (per-request cached).
	 *
	 * @param string $username WP.org login.
	 * @return int[]
	 */
	private function get_held_companion_ticket_ids( $username ) {
		$username = (string) $username;
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			return array();
		}
		if ( isset( $this->held_companion_cache[ $username ] ) ) {
			return $this->held_companion_cache[ $username ];
		}

		$held = array();
		foreach ( $this->get_companion_ticket_ids() as $ticket_id ) {
			if ( $this->user_has_registration( $username, $ticket_id ) ) {
				$held[] = absint( $ticket_id );
			}
		}

		$this->held_companion_cache[ $username ] = $held;
		return $held;
	}

	/**
	 * Pure decision: should this attendee's companion registration be blocked?
	 *
	 * Companion tickets are self-service and one-per-attendee per ticket: the
	 * registrant must be a confirmed (logged-in) attendee who already holds a
	 * published qualifying main ticket, and may register for each companion
	 * ticket only once.
	 *
	 * @param int    $ticket_id Ticket being purchased for this seat.
	 * @param string $username  Confirmed username for this seat (the
	 *                          require-login UNCONFIRMED sentinel or '' means
	 *                          the seat is not the buyer's own confirmed seat).
	 * @return string '' to allow, or an error-flag constant to block.
	 */
	public function should_block_companion_attendee( $ticket_id, $username ) {
		$ticket_id = absint( $ticket_id );
		if ( ! $this->is_companion_ticket( $ticket_id ) ) {
			return ''; // Not a companion ticket — never our concern.
		}

		$username = (string) $username;

		// Companion tickets must be registered by the attendee themselves, while
		// logged in. Reject the buyer's "additional attendee" seats (marked
		// unconfirmed by require-login) and any anonymous seat. This also stops a
		// single buyer draining several capacity-limited seats in one order.
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			/*
			 * Both cases are refused; the only question is which message is true.
			 * require-login gives the real username to seat #1 only and marks every
			 * later seat in the order unconfirmed, so an eligible attendee who selected
			 * two activities together lands here having done nothing wrong -- telling
			 * them to log in and register for themselves would be false.
			 *
			 * The current user is used to CLASSIFY the refusal, never to decide it: the
			 * outcome is identical on both branches, so the fail-closed identity rule
			 * this gate depends on is untouched.
			 */
			if ( self::UNCONFIRMED_USERNAME === $username ) {
				$current_user = (string) wp_get_current_user()->user_login;
				if ( '' !== $current_user && $this->get_user_main_attendee( $current_user ) ) {
					return self::ONE_AT_A_TIME_FLAG;
				}
			}

			return self::SELF_ONLY_FLAG;
		}

		// One registration per attendee per companion ticket. A held seat
		// (published, or pending payment) is a duplicate; an order still in
		// flight at the gateway is not yet a registration, so it gets its own
		// accurate message rather than "you are already registered".
		if ( $this->user_has_registration( $username, $ticket_id ) ) {
			return self::DUPLICATE_FLAG;
		}

		if ( $this->user_has_in_flight_registration( $username, $ticket_id ) ) {
			return self::IN_PROGRESS_FLAG;
		}

		// Eligible only if they already hold a published qualifying main ticket.
		if ( $this->get_user_main_attendee( $username ) ) {
			return '';
		}

		return self::REQUIRES_FLAG;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Checkout hooks
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Checkout hook: flag ineligible companion registrations so checkout aborts.
	 *
	 * Setting any error flag causes CampTix to abort checkout and redisplay the
	 * attendee-info form.
	 *
	 * @param object $attendee      Attendee object (has ->ticket_id, ->username).
	 * @param array  $attendee_info Submitted info for this seat.
	 * @param int    $i             Seat index.
	 * @return object
	 */
	public function gate_companion_attendee( $attendee, $attendee_info, $i ) {
		global $camptix;

		$reason = $this->should_block_companion_attendee(
			isset( $attendee->ticket_id ) ? $attendee->ticket_id : 0,
			$this->resolve_attendee_username( $attendee )
		);

		if ( '' !== $reason ) {
			$camptix->error_flag( $reason );
		}

		return $attendee;
	}

	/**
	 * Resolve the confirmed username for an attendee seat.
	 *
	 * Prefers the per-seat identity require-login sets on the attendee object
	 * (so the buyer's "additional attendee" seats correctly read as
	 * unconfirmed), falling back to post meta for a seat that already exists.
	 *
	 * There is deliberately no current-user fallback. `$attendee->username` is
	 * set only by require-login's `camptix_form_register_complete_attendee_object`
	 * filter, so an absent one means the identity this addon depends on is
	 * unavailable — require-login is inactive, or this is not the buyer's own
	 * seat. Attributing such a seat to whoever happens to be logged in would
	 * let one buyer pass the gate for every seat in a quantity-N order and
	 * would write seats the duplicate gate can never see again. Returning ''
	 * makes the gate fail closed, which is what this addon documents.
	 *
	 * @param object $attendee Attendee object.
	 * @param int    $post_id  Optional attendee post ID for the meta fallback.
	 * @return string Confirmed username, or '' when there is no per-seat identity.
	 */
	private function resolve_attendee_username( $attendee, $post_id = 0 ) {
		if ( isset( $attendee->username ) && '' !== $attendee->username ) {
			return (string) $attendee->username;
		}

		if ( $post_id ) {
			$meta = (string) get_post_meta( $post_id, 'tix_username', true );
			if ( '' !== $meta ) {
				return $meta;
			}
		}

		return '';
	}

	/**
	 * Print the user-facing message for our error flags.
	 *
	 * @param array $error_flags Active error flags.
	 */
	public function render_gate_errors( $error_flags ) {
		global $camptix;

		if ( isset( $error_flags[ self::REQUIRES_FLAG ] ) ) {
			$camptix->error( __( 'That ticket is only available to attendees who already have an event ticket. Please buy your ticket first.', 'wordcamporg' ) );
		}
		if ( isset( $error_flags[ self::DUPLICATE_FLAG ] ) ) {
			$camptix->error( __( 'You are already registered for that ticket.', 'wordcamporg' ) );
		}
		if ( isset( $error_flags[ self::SELF_ONLY_FLAG ] ) ) {
			$camptix->error( __( 'These tickets must be registered by each attendee from their own account. Please log in and register for yourself.', 'wordcamporg' ) );
		}
		if ( isset( $error_flags[ self::ONE_AT_A_TIME_FLAG ] ) ) {
			$camptix->error( __( 'Activity tickets are registered one at a time. Please complete this order, then come back to register for the next activity.', 'wordcamporg' ) );
		}
		if ( isset( $error_flags[ self::IN_PROGRESS_FLAG ] ) ) {
			$camptix->error( __( 'You already have an order for that ticket waiting on payment. Please finish that order — if the payment is never completed, the seat is released automatically and you can register again.', 'wordcamporg' ) );
		}
	}

	/**
	 * On the ticket-selection screen, surface two advisory notices:
	 *  - "already registered for X", so a ticket the visitor already holds is
	 *    shown as such rather than appearing to silently vanish; and
	 *  - "buy your ticket first" for visitors who do not yet hold a qualifying
	 *    main ticket, so they learn the requirement before the checkout gate.
	 */
	public function maybe_show_eligibility_notice() {
		global $camptix;

		// Only the initial selection screen — later steps surface the gate error.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display gate, no state change.
		$action = isset( $_REQUEST['tix_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['tix_action'] ) ) : '';
		if ( '' !== $action ) {
			return;
		}

		$username = (string) wp_get_current_user()->user_login;
		$held     = $this->get_held_companion_ticket_ids( $username );

		// Split the companion tickets into "already registered" vs. "still
		// available to this visitor".
		$registered_titles = array();
		$available_titles  = array();
		foreach ( $this->get_companion_ticket_ids() as $ticket_id ) {
			if ( in_array( absint( $ticket_id ), $held, true ) ) {
				$registered_titles[] = get_the_title( $ticket_id );
			} elseif ( (int) $camptix->get_remaining_tickets( $ticket_id ) > 0 ) {
				$available_titles[] = get_the_title( $ticket_id );
			}
		}

		// Advisory: name the tickets the visitor already holds, so an
		// already-registered ticket reads as such instead of seeming to vanish.
		if ( ! empty( $registered_titles ) ) {
			$camptix->notice( sprintf(
				/* translators: %s: comma-separated list of activity ticket names. */
				__( 'You are already registered for: %s.', 'wordcamporg' ),
				implode( ', ', $registered_titles )
			) );
		}

		// Prompt only visitors who cannot yet register (no qualifying main ticket).
		if ( ! empty( $available_titles ) && ! $this->get_user_main_attendee( $username ) ) {
			$camptix->notice( sprintf(
				/* translators: %s: comma-separated list of activity ticket names. */
				__( '%s can only be registered by attendees who already have an event ticket. Buy your ticket first, then come back to register.', 'wordcamporg' ),
				implode( ', ', $available_titles )
			) );
		}

		/*
		 * An eligible visitor looking at more than one available activity would
		 * otherwise only learn about the one-per-order limit by hitting it at
		 * checkout. Say it here instead.
		 */
		if ( count( $available_titles ) > 1 && $this->get_user_main_attendee( $username ) ) {
			$camptix->notice( __( 'Activity tickets are registered one at a time. Register for one, then come back for the next.', 'wordcamporg' ) );
		}
	}

	/**
	 * Replace a companion ticket's "remaining" cell with an "Already registered"
	 * label for a logged-in visitor who already holds it. Advisory only — the
	 * checkout DUPLICATE gate remains the authoritative enforcement.
	 *
	 * @param mixed  $remaining The remaining-count value CampTix would display.
	 * @param object $ticket    The ticket post being rendered.
	 * @return mixed
	 */
	public function maybe_label_already_registered( $remaining, $ticket ) {
		if ( ! isset( $ticket->ID ) || ! $this->is_companion_ticket( $ticket->ID ) ) {
			return $remaining;
		}

		$username = (string) wp_get_current_user()->user_login;
		if ( in_array( absint( $ticket->ID ), $this->get_held_companion_ticket_ids( $username ), true ) ) {
			return __( 'Already registered', 'wordcamporg' );
		}

		return $remaining;
	}

	/**
	 * When a companion attendee is created, link it to the buyer's main attendee.
	 *
	 * Because registration requires an already-published qualifying main ticket,
	 * the main attendee resolves here at checkout time.
	 *
	 * @param int    $post_id  New attendee post ID.
	 * @param object $attendee Attendee object (has ->ticket_id, ->username).
	 */
	public function link_companion_attendee( $post_id, $attendee ) {
		if ( ! isset( $attendee->ticket_id ) || ! $this->is_companion_ticket( $attendee->ticket_id ) ) {
			return;
		}

		$username = $this->resolve_attendee_username( $attendee, $post_id );

		$main_id = $this->get_user_main_attendee( $username );
		if ( ! $main_id ) {
			return; // Defensive: gate guarantees a published main ticket exists.
		}

		update_post_meta( $post_id, 'tix_companion_primary_attendee_id', $main_id );
		$this->add_activity_admin_flag( $main_id, absint( $attendee->ticket_id ), $post_id );
	}

	/**
	 * Attendee statuses that end a ticket's life.
	 *
	 * A companion seat is only justified while its main ticket is live, and the
	 * flag on the main is only justified while the seat is. Both the cascade and
	 * the flag removal key off the same list so they can't drift apart:
	 *
	 *  - `refund`, `cancel` — the original two.
	 *  - `trash`  — reachable from the organiser UI (`tix_attendee` has `show_ui`
	 *               and mapped delete caps), and `wp_trash_post()` does fire
	 *               `transition_post_status`. Duplicate/fraud cleanup lands here.
	 *  - `failed` — a declined or abandoned payment (`payment_result()`).
	 *  - `timeout`— where CampTix's daily `review_timeout_payments()` parks a
	 *               draft nobody ever paid for. Both matter because the flag is
	 *               added while the seat is still a draft, before payment.
	 *
	 * Force-delete fires none of these; see `maybe_release_on_delete()`.
	 *
	 * @return string[]
	 */
	private function terminal_statuses() {
		return array( 'refund', 'cancel', 'trash', 'failed', 'timeout' );
	}

	/**
	 * Whether a status transition ends the life of an attendee ticket.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post being transitioned.
	 * @return bool
	 */
	private function is_terminal_attendee_transition( $new_status, $old_status, $post ) {
		if ( ! $post || 'tix_attendee' !== $post->post_type ) {
			return false;
		}
		if ( $new_status === $old_status ) {
			return false;
		}

		return in_array( $new_status, $this->terminal_statuses(), true );
	}

	/**
	 * Cascade a terminal main-ticket status to ALL of its linked companion seats.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post being transitioned.
	 */
	public function maybe_cascade_cancel( $new_status, $old_status, $post ) {
		if ( ! $this->is_terminal_attendee_transition( $new_status, $old_status, $post ) ) {
			return;
		}

		$this->cancel_linked_companion_seats( $post->ID );
	}

	/**
	 * Cancel every live companion seat linked to a main attendee.
	 *
	 * Querying by the forward pointer means the relationship is inherently
	 * mutual. Cancelling (rather than trashing or deleting alongside the main)
	 * is what returns the seat to its capacity-limited pool.
	 *
	 * @param int $main_id Main attendee post ID.
	 */
	private function cancel_linked_companion_seats( $main_id ) {
		$companions = $this->get_linked_companion_ids( $main_id, array( 'publish' ) );
		foreach ( $companions as $companion_id ) {
			if ( ! $this->is_companion_ticket( get_post_meta( $companion_id, 'tix_ticket_id', true ) ) ) {
				continue;
			}
			wp_update_post( array(
				'ID'          => $companion_id,
				'post_status' => 'cancel',
			) );
		}
	}

	/**
	 * Release what a force-deleted attendee was holding.
	 *
	 * `wp_delete_post( $id, true )` fires only `before_delete_post`/`deleted_post`
	 * — never `transition_post_status` — so neither the cascade nor the flag
	 * removal would otherwise run. Both directions are handled, and both are
	 * no-ops for the direction that doesn't apply: a main has no flag of its own
	 * to drop, and a seat has no linked seats of its own.
	 *
	 * Runs `before` the delete so the post and its meta are still readable.
	 *
	 * @param int          $post_id Post being deleted.
	 * @param WP_Post|null $post    Post object (passed since WP 5.5).
	 */
	public function maybe_release_on_delete( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || 'tix_attendee' !== $post->post_type ) {
			return;
		}

		// A deleted seat would leave its flag stranded on a main that survives.
		$this->remove_activity_admin_flag_for_seat( $post->ID );

		// A deleted main takes its seats' justification with it.
		$this->cancel_linked_companion_seats( $post->ID );
	}

	/**
	 * Companion attendee IDs linked to a given main attendee.
	 *
	 * @param int      $main_id  Main attendee post ID.
	 * @param string[] $statuses Post statuses to include.
	 * @return int[]
	 */
	private function get_linked_companion_ids( $main_id, $statuses = array( 'publish' ) ) {
		return get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => $statuses,
			'posts_per_page'   => 50,
			'fields'           => 'ids',
			'meta_query'       => array(
				array(
					'key'   => 'tix_companion_primary_attendee_id',
					'value' => absint( $main_id ),
				),
			),
			'suppress_filters' => false,
		) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Auto admin-flag (integrates with the camptix-admin-flags addon)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Whether the camptix-admin-flags addon (the flags' display layer) is active.
	 *
	 * @return bool
	 */
	public function admin_flags_active() {
		return class_exists( 'CampTix_Admin_Flags_Addon' );
	}

	/**
	 * The admin-flag slug for a companion ticket.
	 *
	 * Sanitized exactly like the admin-flags settings parser, so the stored
	 * meta value matches its config key (a mismatch would make the flag
	 * invisible in the admin filter, column, and export).
	 *
	 * @param int $ticket_id Companion ticket post ID.
	 * @return string
	 */
	public function get_activity_flag_slug( $ticket_id ) {
		$ticket_id = absint( $ticket_id );
		$slug      = get_post_field( 'post_name', $ticket_id );
		if ( ! $slug ) {
			$slug = 'ticket-' . $ticket_id;
		}

		return sanitize_html_class( sanitize_title_with_dashes( 'activity-' . $slug ) );
	}

	/**
	 * Flag the main attendee as holding a given companion ticket (idempotent).
	 *
	 * The flag lives on the MAIN attendee — "this person also has X" is what
	 * organisers filter and export by; the companion seat itself already
	 * carries the ticket type.
	 *
	 * The slug is derived from the ticket's `post_name`, which is mutable: empty on a
	 * draft, written on publish, and editable afterwards. So the value actually used is
	 * recorded on the seat, and removal deletes exactly that rather than recomputing a
	 * slug that may since have changed and orphaning the real flag.
	 *
	 * @param int $main_id   Main attendee post ID.
	 * @param int $ticket_id Companion ticket post ID.
	 * @param int $seat_id   Optional. Companion seat post ID, to record the slug on.
	 */
	public function add_activity_admin_flag( $main_id, $ticket_id, $seat_id = 0 ) {
		if ( ! $this->admin_flags_active() ) {
			return;
		}

		$slug     = $this->get_activity_flag_slug( $ticket_id );
		$existing = (array) get_post_meta( $main_id, self::ADMIN_FLAG_META, false );
		if ( ! in_array( $slug, $existing, true ) ) {
			add_post_meta( $main_id, self::ADMIN_FLAG_META, $slug );
		}

		if ( $seat_id ) {
			update_post_meta( absint( $seat_id ), self::ACTIVITY_FLAG_META, $slug );
		}
	}

	/**
	 * Remove the flag from the linked main when a companion seat ends.
	 *
	 * Any terminal status, not just refund/cancel: the flag is added from
	 * `camptix_checkout_update_post_meta` while the seat is still a draft, so an
	 * order that never gets paid (`failed`, `timeout`) or an attendee an
	 * organiser trashes would otherwise leave the flag behind permanently —
	 * corrupting the very filter, column and export the flag exists to power.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post being transitioned.
	 */
	public function maybe_remove_activity_admin_flag( $new_status, $old_status, $post ) {
		if ( ! $this->is_terminal_attendee_transition( $new_status, $old_status, $post ) ) {
			return;
		}

		$this->remove_activity_admin_flag_for_seat( $post->ID );
	}

	/**
	 * Drop a companion seat's flag from the main attendee it linked to.
	 *
	 * One-per-ticket-per-attendee means no other live seat can re-justify the
	 * flag. Deleting is safe even if the admin-flags addon is inactive, and a
	 * no-op for an attendee that is not a companion seat.
	 *
	 * @param int $seat_id Companion attendee post ID.
	 */
	private function remove_activity_admin_flag_for_seat( $seat_id ) {
		$ticket_id = absint( get_post_meta( $seat_id, 'tix_ticket_id', true ) );
		if ( ! $this->is_companion_ticket( $ticket_id ) ) {
			return;
		}

		$main_id = absint( get_post_meta( $seat_id, 'tix_companion_primary_attendee_id', true ) );
		if ( ! $main_id ) {
			return;
		}

		/*
		 * Prefer the slug recorded when the flag was written: the ticket's post_name may
		 * have changed since (draft -> publish, or a manual edit), and recomputing would
		 * delete a value that was never stored, leaving the real flag behind forever.
		 * Seats linked before the slug was recorded fall back to recomputing.
		 */
		$slug = (string) get_post_meta( $seat_id, self::ACTIVITY_FLAG_META, true );
		if ( '' === $slug ) {
			$slug = $this->get_activity_flag_slug( $ticket_id );
		}

		delete_post_meta( $main_id, self::ADMIN_FLAG_META, $slug );
	}

	/**
	 * Ensure each companion ticket has an admin-flags config entry, so the auto
	 * flags actually render (admin-flags registers nothing for unknown slugs).
	 *
	 * Runs inside the Setup save ($output is the full options blob), and also
	 * rewrites the addon's raw textarea value so a later save of the Admin
	 * Flags section round-trips these entries instead of dropping them.
	 * Organiser-defined flags are preserved; stale entries for tickets that are
	 * no longer companions are left alone (they may still mark past attendees).
	 *
	 * @param array $output The full validated camptix options.
	 * @return array
	 */
	private function sync_activity_admin_flag_config( $output ) {
		if ( ! $this->admin_flags_active() ) {
			return $output;
		}

		$ids = isset( $output['camptix-companion-ticket-ids'] )
			? (array) $output['camptix-companion-ticket-ids']
			: $this->get_companion_ticket_ids();

		$parsed = isset( $output['camptix-admin-flags-data-parsed'] ) ? (array) $output['camptix-admin-flags-data-parsed'] : array();
		$added  = false;

		foreach ( $ids as $ticket_id ) {
			$slug = $this->get_activity_flag_slug( $ticket_id );
			if ( isset( $parsed[ $slug ] ) ) {
				continue;
			}

			/* translators: %s: activity ticket name. */
			$parsed[ $slug ] = sprintf( __( 'Activity: %s', 'wordcamporg' ), get_the_title( $ticket_id ) );
			$added           = true;
		}

		if ( $added ) {
			$output['camptix-admin-flags-data-parsed'] = $parsed;

			$lines = array();
			foreach ( $parsed as $slug => $label ) {
				$lines[] = sprintf( '%s: %s', $slug, $label );
			}
			$output['camptix-admin-flags-data'] = implode( "\n", $lines );
		}

		return $output;
	}

	/**
	 * Repair a missing admin-flags config entry for any configured activity ticket.
	 *
	 * `sync_activity_admin_flag_config()` only runs inside a Setup save, and early-returns
	 * when the admin-flags addon is inactive -- so a site that configured Activity Tickets
	 * first and enabled admin-flags later ends up writing flag meta whose slug the
	 * admin-flags config does not know about. Its own `save_post` (admin-flags.php:251)
	 * then deletes every flag row and re-adds only configured slugs, silently destroying
	 * the activity flags on the first manual save of that attendee.
	 *
	 * Re-checking in wp-admin closes that window without touching the checkout path, and
	 * writes only when something is actually missing.
	 */
	public function maybe_heal_activity_admin_flag_config() {
		if ( ! $this->admin_flags_active() ) {
			return;
		}

		$options = (array) get_option( 'camptix_options', array() );
		$healed  = $this->sync_activity_admin_flag_config( $options );

		if ( $healed !== $options ) {
			update_option( 'camptix_options', $healed );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Public Attendees listing
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Hide companion seats from the public [camptix_attendees] listing.
	 *
	 * Every companion holder also holds a main ticket, so this is a dedupe (the
	 * person still lists once), not a removal — see upstream issue #623. An
	 * explicit tickets="…" attribute is an organiser's deliberate choice (e.g.
	 * a Contributor Day attendees page), so it is left untouched.
	 *
	 * @param array $query_args WP_Query args for the listing.
	 * @param array $attr       Shortcode attributes.
	 * @return array
	 */
	public function exclude_companion_attendees_from_listing( $query_args, $attr ) {
		if ( ! empty( $attr['tickets'] ) ) {
			return $query_args;
		}

		$ids = $this->get_companion_ticket_ids();
		if ( ! $ids ) {
			return $query_args;
		}

		if ( ! isset( $query_args['meta_query'] ) ) {
			$query_args['meta_query'] = array();
		}

		$query_args['meta_query'][] = array(
			'key'     => 'tix_ticket_id',
			'value'   => $ids,
			'compare' => 'NOT IN',
		);

		return $query_args;
	}

	/**
	 * Delete all cached [camptix_attendees] renders.
	 *
	 * The shortcode's cache key hashes only the shortcode attributes — not the
	 * output of its query-args filter — so a config change here must flush the
	 * transients directly or stale listings persist for hours.
	 */
	private function flush_attendees_shortcode_cache() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API exists for finding these transients; runs only on Setup save.
		$transients = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_camptix-attendees-' ) . '%'
		) );

		foreach ( $transients as $option_name ) {
			delete_transient( str_replace( '_transient_', '', $option_name ) );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Ownership transfer
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Detect a ticket changing hands via require-login's identity reassignment.
	 *
	 * Fires on the update_post_meta PRE-action, which WordPress only fires on a
	 * genuine value change — at that moment get_post_meta() still returns the
	 * OLD username while $meta_value carries the NEW one. A first claim of an
	 * "[[ unconfirmed ]]" group-purchase seat is first ownership, not a
	 * transfer, and is ignored.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Attendee post ID.
	 * @param string $meta_key   Meta key being updated.
	 * @param mixed  $meta_value New meta value.
	 */
	public function detect_ownership_change( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( 'tix_username' !== $meta_key ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || 'tix_attendee' !== $post->post_type ) {
			return;
		}

		$old = (string) get_post_meta( $object_id, 'tix_username', true );
		$new = is_scalar( $meta_value ) ? (string) $meta_value : '';

		if ( '' === $old || self::UNCONFIRMED_USERNAME === $old || '' === $new || $old === $new ) {
			return;
		}

		$this->handle_ownership_transfer( $post, $old, $new );
	}

	/**
	 * React to a real ownership transfer: release the personal companion seats.
	 *
	 * Companion seats are free, self-service, and personal, so "release" (the
	 * seat returns to its pool; the new owner can register themselves) is the
	 * default policy — it also stops a capacity-limited seat being hijacked via
	 * a shared edit link. Re-linking to another main ticket the old owner may
	 * still hold is deliberately future work.
	 *
	 * @param WP_Post $post         The attendee post whose owner changed.
	 * @param string  $old_username Previous owner login.
	 * @param string  $new_username New owner login.
	 */
	public function handle_ownership_transfer( $post, $old_username, $new_username ) {
		/**
		 * Filter the companion-seat policy applied on an ownership transfer.
		 *
		 * @param string  $policy       'release' (default) cancels the affected
		 *                              companion seats; anything else leaves them.
		 * @param WP_Post $post         The attendee post whose owner changed.
		 * @param string  $old_username Previous owner login.
		 * @param string  $new_username New owner login.
		 */
		$policy = apply_filters( 'camptix_companion_transfer_policy', 'release', $post, $old_username, $new_username );
		if ( 'release' !== $policy ) {
			return;
		}

		$ticket_id = absint( get_post_meta( $post->ID, 'tix_ticket_id', true ) );

		if ( $this->is_companion_ticket( $ticket_id ) ) {
			// The companion seat itself changed hands.
			$this->queue_seat_release( $post->ID );
			return;
		}

		// A main ticket changed hands: the old owner no longer holds a
		// qualifying ticket, so their linked companion seats no longer qualify.
		foreach ( $this->get_linked_companion_ids( $post->ID, array( 'publish' ) ) as $companion_id ) {
			if ( $this->is_companion_ticket( get_post_meta( $companion_id, 'tix_ticket_id', true ) ) ) {
				$this->queue_seat_release( $companion_id );
			}
		}
	}

	/**
	 * Queue an attendee post to be cancelled once the current save finishes.
	 *
	 * @param int $attendee_id Attendee post ID.
	 */
	public function queue_seat_release( $attendee_id ) {
		if ( empty( $this->deferred_releases ) ) {
			add_action( 'shutdown', array( $this, 'process_deferred_releases' ) );
		}

		$this->deferred_releases[ absint( $attendee_id ) ] = true;
	}

	/**
	 * Cancel the queued seats. Runs on shutdown, after the triggering save has
	 * fully completed (cancelling mid-save would be overwritten by it).
	 */
	public function process_deferred_releases() {
		$ids                     = array_keys( $this->deferred_releases );
		$this->deferred_releases = array();

		foreach ( $ids as $attendee_id ) {
			if ( 'publish' === get_post_status( $attendee_id ) ) {
				wp_update_post( array(
					'ID'          => $attendee_id,
					'post_status' => 'cancel',
				) );
			}
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Confirmation email
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Email an attendee a confirmation when their companion seat is confirmed.
	 *
	 * Fires once per companion attendee, when it transitions to "publish"; a
	 * dedupe meta flag prevents repeat sends (e.g. on later admin edits). This is
	 * a dedicated note about the companion registration — its link to the main
	 * ticket and the limited capacity — in addition to CampTix's order receipt.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post being transitioned.
	 */
	public function maybe_send_activity_confirmation( $new_status, $old_status, $post ) {
		global $camptix;

		if ( ! $post || 'tix_attendee' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$ticket_id = absint( get_post_meta( $post->ID, 'tix_ticket_id', true ) );
		if ( ! $this->is_companion_ticket( $ticket_id ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, 'tix_companion_confirmation_sent', true ) ) {
			return; // Already emailed for this registration.
		}

		$to = get_post_meta( $post->ID, 'tix_email', true );
		if ( ! is_email( $to ) ) {
			return;
		}

		$options    = $camptix->get_options();
		$event_name = ! empty( $options['event_name'] ) ? $options['event_name'] : get_bloginfo( 'name' );
		$first_name = (string) get_post_meta( $post->ID, 'tix_first_name', true );
		$ticket     = get_the_title( $ticket_id );
		$edit_token = get_post_meta( $post->ID, 'tix_edit_token', true );
		$edit_url   = $camptix->get_edit_attendee_link( $post->ID, $edit_token );

		$subject = apply_filters(
			'camptix_companion_confirmation_subject',
			sprintf(
				/* translators: 1: activity ticket name, 2: event name. */
				__( 'You are registered for %1$s at %2$s', 'wordcamporg' ),
				$ticket,
				$event_name
			),
			$post->ID
		);

		$message = apply_filters(
			'camptix_companion_confirmation_message',
			sprintf(
				/* translators: 1: attendee first name, 2: activity ticket name, 3: event name, 4: manage-registration URL. */
				__( "Hi %1\$s,\n\nGreat news — your spot for %2\$s is confirmed. It is tied to your main %3\$s ticket, which is what makes you eligible for it.\n\nA couple of things worth knowing:\n- Spots are limited, so please only hold one if you plan to attend, and let us know early if your plans change.\n- This registration is connected to your event ticket. If your event ticket is cancelled or refunded, this registration is released automatically.\n\nYou can view or cancel just this registration here at any time:\n%4\$s\n\nThanks, and see you at %3\$s!", 'wordcamporg' ),
				$first_name,
				$ticket,
				$event_name,
				$edit_url
			),
			$post->ID
		);

		$camptix->wp_mail( $to, $subject, $message );
		update_post_meta( $post->ID, 'tix_companion_confirmation_sent', 1 );
	}

	/*
	 * -------------------------------------------------------------------------
	 * "Your tickets" links + email-me-my-links
	 * -------------------------------------------------------------------------
	 */

	/**
	 * All of a username's published attendee seats, main tickets before
	 * activity seats.
	 *
	 * @param string $username WP.org login.
	 * @return int[] Attendee post IDs.
	 */
	public function get_user_seat_ids( $username ) {
		$username = (string) $username;
		if ( '' === $username || self::UNCONFIRMED_USERNAME === $username ) {
			return array();
		}

		$attendees = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => 'publish',
			'posts_per_page'   => 100,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => array(
				array(
					'key'   => 'tix_username',
					'value' => $username,
				),
			),
			'suppress_filters' => false,
		) );

		$mains      = array();
		$companions = array();
		foreach ( $attendees as $attendee_id ) {
			$ticket_id = absint( get_post_meta( $attendee_id, 'tix_ticket_id', true ) );
			if ( $this->is_companion_ticket( $ticket_id ) ) {
				$companions[] = (int) $attendee_id;
			} else {
				$mains[] = (int) $attendee_id;
			}
		}

		return array_merge( $mains, $companions );
	}

	/**
	 * Email a user one message listing the manage link for each of their seats.
	 *
	 * Throttled per username so the request link can't pepper an inbox. The
	 * request path is login-gated and the address is always the requester's
	 * own account email — never visitor input, so it can't be used to probe
	 * whether an address holds a ticket.
	 *
	 * @param string $username WP.org login whose seats to list.
	 * @param string $to       Destination address.
	 * @return bool Whether an email was sent.
	 */
	public function send_ticket_links_email( $username, $to ) {
		global $camptix;

		if ( ! is_email( $to ) ) {
			return false;
		}

		$seat_ids = $this->get_user_seat_ids( $username );
		if ( empty( $seat_ids ) ) {
			return false;
		}

		$throttle_key = 'tix_companion_links_mail_' . md5( strtolower( $username ) );
		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		$lines = array();
		foreach ( $seat_ids as $seat_id ) {
			$ticket_id  = absint( get_post_meta( $seat_id, 'tix_ticket_id', true ) );
			$edit_token = get_post_meta( $seat_id, 'tix_edit_token', true );
			$lines[]    = sprintf( '- %1$s: %2$s', get_the_title( $ticket_id ), $camptix->get_edit_attendee_link( $seat_id, $edit_token ) );
		}

		$options    = $camptix->get_options();
		$event_name = ! empty( $options['event_name'] ) ? $options['event_name'] : get_bloginfo( 'name' );

		$subject = apply_filters(
			'camptix_companion_links_email_subject',
			sprintf(
				/* translators: %s: event name. */
				__( 'Your ticket links for %s', 'wordcamporg' ),
				$event_name
			),
			$username
		);

		$message = apply_filters(
			'camptix_companion_links_email_message',
			sprintf(
				/* translators: 1: event name, 2: list of ticket manage links. */
				__( "Here are the links to view or manage each of your tickets for %1\$s:\n\n%2\$s\n\nEach link opens that ticket's manage page directly, so keep this email handy.", 'wordcamporg' ),
				$event_name,
				implode( "\n", $lines )
			),
			$username
		);

		$sent = $camptix->wp_mail( $to, $subject, $message );
		if ( $sent ) {
			set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );
		}

		return (bool) $sent;
	}

	/**
	 * Pure handler for the email-me-my-links request; the template_redirect
	 * wrapper turns the outcome into a redirect.
	 *
	 * @return string '' when this isn't a links request, otherwise
	 *                'sent' | 'throttled' | 'invalid'.
	 */
	public function handle_links_email_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified just below.
		if ( ! isset( $_GET['tix_companion_email_links'] ) ) {
			return '';
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'tix-companion-email-links' ) ) {
			return 'invalid';
		}

		$user = wp_get_current_user();
		$sent = $this->send_ticket_links_email( (string) $user->user_login, (string) $user->user_email );

		return $sent ? 'sent' : 'throttled';
	}

	/**
	 * Process the email-me-my-links request, then redirect back to the tickets
	 * page (PRG) with an outcome flag so a refresh can't re-trigger the send.
	 */
	public function process_links_email_request() {
		global $camptix;

		$status = $this->handle_links_email_request();
		if ( '' === $status ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'tix_companion_links_sent', rawurlencode( $status ), $camptix->get_tickets_url() ) );
		exit;
	}

	/**
	 * On the ticket-selection screen, list the visitor's existing seats with
	 * their manage links, plus the email-me-these-links action.
	 */
	public function maybe_show_my_ticket_links() {
		global $camptix;

		// Only the initial selection screen — later steps have their own flow.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display gate, no state change.
		$action = isset( $_REQUEST['tix_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['tix_action'] ) ) : '';
		if ( '' !== $action ) {
			return;
		}

		// Outcome notice after the request's PRG redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only outcome flag.
		$outcome = isset( $_GET['tix_companion_links_sent'] ) ? sanitize_key( wp_unslash( $_GET['tix_companion_links_sent'] ) ) : '';
		if ( 'sent' === $outcome ) {
			$camptix->notice( __( 'Check your inbox — we emailed you the links to your tickets.', 'wordcamporg' ) );
		} elseif ( 'throttled' === $outcome ) {
			$camptix->notice( __( 'We emailed you your ticket links recently — please check your inbox (and spam folder) before requesting them again.', 'wordcamporg' ) );
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user     = wp_get_current_user();
		$seat_ids = $this->get_user_seat_ids( (string) $user->user_login );
		if ( empty( $seat_ids ) ) {
			return;
		}

		$links = array();
		foreach ( $seat_ids as $seat_id ) {
			$ticket_id  = absint( get_post_meta( $seat_id, 'tix_ticket_id', true ) );
			$edit_token = get_post_meta( $seat_id, 'tix_edit_token', true );
			$links[]    = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $camptix->get_edit_attendee_link( $seat_id, $edit_token ) ),
				esc_html( get_the_title( $ticket_id ) )
			);
		}

		$email_url = wp_nonce_url(
			add_query_arg( 'tix_companion_email_links', '1', $camptix->get_tickets_url() ),
			'tix-companion-email-links'
		);

		$camptix->notice( sprintf(
			/* translators: 1: list of ticket manage links, 2: request-email URL. */
			__( 'Your tickets: %1$s. <a href="%2$s">Email me these links</a>.', 'wordcamporg' ),
			implode( ', ', $links ),
			esc_url( $email_url )
		) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Attendee export
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Add the main<->companion link columns to the attendee CSV/XML export.
	 *
	 * @param array $columns Existing extra export columns (key => label).
	 * @return array
	 */
	public function export_extra_columns( $columns ) {
		$columns['companion_of']      = __( 'Activity of (main ticket #)', 'wordcamporg' );
		$columns['companion_tickets'] = __( 'Activity tickets', 'wordcamporg' );
		return $columns;
	}

	/**
	 * Export cell: for a companion row, the linked main attendee ID; else ''.
	 *
	 * @param string  $value    Default cell value.
	 * @param WP_Post $attendee Attendee post for this row.
	 * @return string
	 */
	public function export_column_companion_of( $value, $attendee ) {
		$main_id = absint( get_post_meta( $attendee->ID, 'tix_companion_primary_attendee_id', true ) );
		return $main_id ? (string) $main_id : '';
	}

	/**
	 * Export cell: for a main row, its live companion registrations; else ''.
	 *
	 * @param string  $value    Default cell value.
	 * @param WP_Post $attendee Attendee post for this row.
	 * @return string
	 */
	public function export_column_companion_tickets( $value, $attendee ) {
		$labels = array();
		foreach ( $this->get_linked_companion_ids( $attendee->ID, array( 'publish' ) ) as $companion_id ) {
			$ticket_id = absint( get_post_meta( $companion_id, 'tix_ticket_id', true ) );
			$labels[]  = sprintf( '%s (#%d)', get_the_title( $ticket_id ), $companion_id );
		}
		return implode( ' | ', $labels );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Admin display
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Human-readable description of the main<->companion links for an attendee.
	 *
	 * Only reports links to still-live (published) counterparts, so a cancelled
	 * companion seat is never shown as an active registration.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string '' if there is no live link.
	 */
	public function get_link_label( $attendee_id ) {
		// This attendee is itself a companion registration.
		$primary = absint( get_post_meta( $attendee_id, 'tix_companion_primary_attendee_id', true ) );
		if ( $primary && 'publish' === get_post_status( $primary ) ) {
			/* translators: %d: main attendee post ID. */
			return sprintf( __( 'Activity registration linked to ticket #%d.', 'wordcamporg' ), $primary );
		}

		// This attendee is a main ticket with companion registrations.
		$labels = array();
		foreach ( $this->get_linked_companion_ids( $attendee_id, array( 'publish' ) ) as $companion_id ) {
			$ticket_id = absint( get_post_meta( $companion_id, 'tix_ticket_id', true ) );
			$labels[]  = sprintf( '%s (#%d)', get_the_title( $ticket_id ), $companion_id );
		}
		if ( ! empty( $labels ) ) {
			/* translators: %s: comma-separated list of "Ticket name (#attendee id)". */
			return sprintf( __( 'Registered for activity tickets: %s.', 'wordcamporg' ), implode( ', ', $labels ) );
		}

		return '';
	}

	/**
	 * Show the link on the Edit Attendee screen's Publish metabox.
	 *
	 * @param WP_Post $post The attendee post being edited (passed by the hook).
	 */
	public function render_link_metabox( $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post();
		}
		if ( ! $post ) {
			return;
		}

		$label = $this->get_link_label( $post->ID );
		if ( '' !== $label ) {
			echo '<div class="misc-pub-section"><span class="dashicons dashicons-groups"></span> ' . esc_html( $label ) . '</div>';
		}
	}
}

CampTix_Companion_Tickets_Addon::register_addon();

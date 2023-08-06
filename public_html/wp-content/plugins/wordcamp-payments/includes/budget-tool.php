<?php

use WordPressdotorg\MU_Plugins\Utilities\Export_CSV;

class WordCamp_Budget_Tool {

	/**
	 * Attach hooks & filters on load.
	 */
	public static function load() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 10, 2 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Add the budget-related pages to the admin menu.
	 */
	public static function admin_menu() {
		add_submenu_page(
			'wordcamp-budget',
			esc_html__( 'WordCamp Budget', 'wordcamporg' ),
			esc_html__( 'Budget', 'wordcamporg' ),
			WordCamp_Budgets::VIEWER_CAP,
			'wordcamp-budget'
		);

		register_setting(
			'wcb_budget_noop',
			'wcb_budget_noop',
			array( __CLASS__, 'validate' )
		);

		add_action( 'wcb_render_budget_page', array( __CLASS__, 'render' ) );
	}

	/**
	 * If there is form data, validate and process/save it.
	 */
	public static function validate( $noop ) {
		if ( empty( $_POST['_wcb_budget_data'] ) ) {
			return;
		}

		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wcb_budget_noop-options' ) ) {
			return;
		}

		if ( ! current_user_can( WordCamp_Budgets::ADMIN_CAP ) ) {
			return;
		}

		$budget = self::_get_budget();
		$data   = json_decode( wp_unslash( $_POST['_wcb_budget_data'] ), true );
		$user   = get_user_by( 'id', get_current_user_id() );

		$valid_attributes = array( 'type', 'category', 'amount', 'note', 'link', 'name', 'value' );
		foreach ( $data as &$item ) {
			$_item = array();
			foreach ( $item as $key => $value ) {
				if ( ! in_array( $key, $valid_attributes ) ) {
					continue;
				}

				if ( 'amount' === $key ) {
					$value = round( floatval( $value ), 2 );
				}

				$_item[ $key ] = $value;
			}

			$item = $_item;
		}

		if ( ! empty( $_POST['wcb-budget-download-csv'] ) ) {
			$column_headers = Export_CSV::esc_csv( array( 'Type', 'Category', 'Detail', 'Amount', 'Link', 'Total' ) );
			$csv_data       = array();

			$meta = wp_list_filter( $data, array( 'type' => 'meta' ) );

			foreach ( $data as $raw ) {
				if ( 'meta' === $raw['type'] ) {
					continue;
				}
				$raw['amount'] = 'income' === $raw['type'] ? (float) $raw['amount'] : (float) $raw['amount'] * -1;

				$total = self::_get_real_value( $raw['amount'], $raw['link'], $meta );
				$row   = array(
					$raw['type'],
					$raw['category'],
					$raw['note'],
					$raw['amount'],
					$raw['link'],
					$total,
				);

				$csv_data[] = Export_CSV::esc_csv( $row );
			}

			$exporter = new Export_CSV( array(
				'filename' => array( 'budget', get_wordcamp_name(), wp_date( 'Y-m-d' ) ),
				'headers'  => $column_headers,
				'data'     => $csv_data,
			) );

			$exporter->emit_file();
			return;
		}

		if ( 'draft' === $budget['status'] && ! empty( $_POST['wcb-budget-save-draft'] ) ) {
			// Save draft.
			$budget['prelim'] = $data;
		} elseif ( 'draft' === $budget['status'] && ! empty( $_POST['wcb-budget-submit'] ) ) {
			// Submit for Approval.
			$budget['prelim'] = $data;
			$budget['status'] = 'pending';
			$domain           = parse_url( home_url(), PHP_URL_HOST );
			$link             = esc_url_raw( add_query_arg( 'page', 'wordcamp-budget', admin_url( 'admin.php' ) ) );

			$content = "A budget approval request has been submitted for {$domain} by {$user->user_login}:\n\n{$link}\n\nYours, Mr. Budget Tool";
			wp_mail( 'support@wordcamp.org', 'Budget Approval Requested: ' . $domain, $content );

		} elseif ( 'draft' === $budget['status'] && ! empty( $_POST['wcb-budget-request-review'] ) ) {
			// Save draft and request review.
			$budget['prelim'] = $data;
			$domain           = parse_url( home_url(), PHP_URL_HOST );
			$link             = esc_url_raw( add_query_arg( 'page', 'wordcamp-budget', admin_url( 'admin.php' ) ) );

			$content = "A budget review has been requested for {$domain} by {$user->user_login}:\n\n{$link}\n\nYours, Mr. Budget Tool";
			wp_mail( 'support@wordcamp.org', 'Budget Review Requested: ' . $domain, $content );

		} elseif ( 'pending' === $budget['status'] && current_user_can( 'wcb_approve_budget' ) ) {
			if ( ! empty( $_POST['wcb-budget-reject'] ) ) {
				$budget['status'] = 'draft';
			} elseif ( ! empty( $_POST['wcb-budget-approve'] ) ) {
				$budget['status']      = 'approved';
				$budget['approved_by'] = $user->ID;

				// Clone the approved prelim. budget.
				$budget['approved'] = $budget['prelim'];
				$budget['working']  = $budget['prelim'];
			}
		} elseif ( 'approved' === $budget['status'] && ! empty( $_POST['wcb-budget-update-working'] ) ) {
			$budget['working'] = $data;
		} elseif ( 'approved' === $budget['status'] && ! empty( $_POST['wcb-budget-reset'] ) ) {
			$budget['working'] = $budget['approved'];
		}

		$budget['updated']    = time();
		$budget['updated_by'] = $user->ID;

		self::rotate_backups();
		update_option( 'wcb_budget', $budget, 'no' );
		return;
	}

	/**
	 * Rotate the backup of budgets to make room for a new one.
	 *
	 * _This must be called before a new budget is written to the `wcb_budget` option._
	 *
	 * There've been a few reports of budgets being lost while saving, but so far we haven't been able to
	 * reproduce the bug. The budget is critical information, and time-consuming to re-enter, so having a backup
	 * gives us a safety net until we can find and fix the bug, and protects against future bugs and user error as
	 * well.
	 *
	 * 3 backups are kept, because the organizer might make several changes before they realized they lost
	 * something, or in an attempt to recover it.
	 */
	private static function rotate_backups() {
		update_option( 'wcb_budget_backup_3', get_option( 'wcb_budget_backup_2' ), false );
		update_option( 'wcb_budget_backup_2', get_option( 'wcb_budget_backup_1' ), false );
		update_option( 'wcb_budget_backup_1', get_option( 'wcb_budget'          ), false );
	}

	/**
	 * Enqueue the JS used to render the budget UI.
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();

		wp_enqueue_script( 'select2' );
		wp_enqueue_style( 'select2' );

		if ( 'toplevel_page_wordcamp-budget' === $screen->id ) {
			wp_enqueue_script(
				'wcb-budget-tool',
				plugins_url( 'javascript/budget-tool.js', __DIR__ ),
				array( 'backbone', 'jquery', 'jquery-ui-sortable', 'heartbeat', 'underscore', 'select2' ),
				3,
				true
			);

			wp_localize_script(
				'wcb-budget-tool',
				'networkStatus',
				array(
					'isNextGenWordCamp' => is_next_gen_wordcamp(),
				)
			);
		}
	}

	/**
	 * Helper function to get the real (total) value of an entry.
	 * For example, if a line item is 10 per attendee, with 100 attendees, the result will be 1000.
	 *
	 * See wcb.linkData in ../javascript/budget-tool.js.
	 *
	 * @param float       $value The value of the current entry.
	 * @param string|null $link  The quantity-type of the entry (per attendee, per day, etc), or null if plain value.
	 * @param array       $meta  The metadata for this budget, which contains the attendee, speaker, etc counts.
	 * @return int
	 */
	private static function _get_real_value( float $value, $link, $meta ) {
		// The metadata is an array of arrays, so we can filter out the relevant item, pluck just the value, then retrieve it.
		$count_speakers   = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'speakers' ) ), 'value' ) );
		$count_volunteers = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'volunteers' ) ), 'value' ) );
		$count_organizers = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'organizers' ) ), 'value' ) );
		$count_attendees  = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'attendees' ) ), 'value' ) );
		$count_days       = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'days' ) ), 'value' ) );
		$count_tracks     = (int) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'tracks' ) ), 'value' ) );
		$ticket_price     = (float) current( wp_list_pluck( wp_list_filter( $meta, array( 'name' => 'ticket-price' ) ), 'value' ) );

		switch ( $link ) {
			case 'per-speaker':
				return $value * $count_speakers;
			case 'per-volunteer':
				return $value * $count_volunteers;
			case 'per-organizer':
				return $value * $count_organizers;
			case 'per-speaker-volunteer':
				return $value * $count_speakers + $value * $count_volunteers;
			case 'per-speaker-volunteer-organizer':
				return $value * $count_speakers + $value * $count_volunteers + $value * $count_organizers;
			case 'per-attendee':
				return $value * $count_attendees;
			case 'per-day':
				return $value * $count_days;
			case 'per-track':
				return $value * $count_tracks;
			case 'ticket-price-x-attendees':
				return $ticket_price * $count_attendees;
		}

		return $value;
	}

	/**
	 * Helper function to get the current budget.
	 */
	private static function _get_budget() {
		$budget = get_option(
			'wcb_budget',
			array(
				'status' => 'draft',
				'prelim' => self::_get_default_budget(),
			)
		);

		return $budget;
	}

	/**
	 * Helper function to get the default budget.
	 */
	private static function _get_default_budget() {
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$default_budget = array(
			array( 'type' => 'meta', 'name' => 'attendees', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'days', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'tracks', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'speakers', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'volunteers', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'organizers', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'currency', 'value' => 'USD' ),
			array( 'type' => 'meta', 'name' => 'ticket-price', 'value' => 0 ),

			array( 'type' => 'income', 'category' => 'other', 'note' => 'Tickets Income', 'amount' => 0, 'link' => 'ticket-price-x-attendees' ),
			array( 'type' => 'income', 'category' => 'other', 'note' => 'Community Sponsorships', 'amount' => 0 ),
			array( 'type' => 'income', 'category' => 'other', 'note' => 'Local Sponsorships', 'amount' => 0 ),
			array( 'type' => 'income', 'category' => 'other', 'note' => 'Microsponsors', 'amount' => 0 ),

			array( 'type' => 'expense', 'category' => 'venue', 'note' => 'Venue', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'venue', 'note' => 'Wifi Costs', 'amount' => 0, 'link' => 'per-day' ),
			array( 'type' => 'expense', 'category' => 'other', 'note' => 'Comped Tickets', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'audio-visual', 'note' => 'Video recording', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'audio-visual', 'note' => 'Projector rental', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'audio-visual', 'note' => 'Livestream', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'signage-badges', 'note' => 'Printing', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'signage-badges', 'note' => 'Badges', 'amount' => 0, 'link' => 'per-attendee' ),
			array( 'type' => 'expense', 'category' => 'food-beverage', 'note' => 'Snacks', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'food-beverage', 'note' => 'Lunch', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'food-beverage', 'note' => 'Coffee', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'swag', 'note' => 'T-shirts', 'amount' => 0 ),
			array( 'type' => 'expense', 'category' => 'speaker-event', 'note' => 'Speakers Dinner', 'amount' => 0, 'link' => 'per-speaker' ),
		);

		$extra_budget_for_next_gen = array(
			array( 'type' => 'meta', 'name' => 'wp-expertise-level', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'focused-activity', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'job-status', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'identity-based', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'content-topic-focused', 'value' => 0 ),
			array( 'type' => 'meta', 'name' => 'other', 'value' => 0 ),
		);

		if ( is_next_gen_wordcamp() ) {
			// $extra_budget_for_next_gen should be positioned after the 'days' element for proper ordering.
			$insert_position = array_search( 'days', array_column( $default_budget, 'name' ), true ) + 1;
			array_splice( $default_budget, $insert_position, 0, $extra_budget_for_next_gen );

			$default_budget = array_filter(
				$default_budget,
				function ( $item ) {
					if ( ! isset( $item['category'] ) ) {
						return true;
					}

					return 'speaker-event' !== $item['category'];
				}
			);
		}

		return $default_budget;
		// phpcs:enable
	}

	/**
	 * Render the budget UI.
	 */
	public static function render() {
		$budget = self::_get_budget();

		$view = ! empty( $_GET['wcb-view'] ) ? $_GET['wcb-view'] : 'prelim';
		if ( ! in_array( $view, array( 'prelim', 'working', 'approved' ) ) ) {
			$view = 'prelim';
		}

		if ( 'prelim' === $view && 'approved' === $budget['status'] ) {
			$view = 'approved';
		}

		$editable = false;
		if ( 'prelim' === $view && 'draft' === $budget['status'] ) {
			$editable = true;
		} elseif ( 'working' === $view && 'approved' === $budget['status'] ) {
			$editable = true;
		}

		$inspire_urls = get_site_transient( 'wcb-inspire-urls' );
		if ( ! $inspire_urls ) {
			$urls = array( 'https://jawordpressorg.github.io/wapuu/wapuu-archive/original-wapuu.png' );
			$r    = wp_remote_get( 'https://jawordpressorg.github.io/wapuu-api/v1/wapuu.json' );
			if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) == 200 ) {
				$body       = json_decode( wp_remote_retrieve_body( $r ), true );
				$maybe_urls = wp_list_pluck( wp_list_pluck( $body, 'wapuu' ), 'src' );
				if ( count( $maybe_urls ) > 0 ) {
					$inspire_urls = $maybe_urls;
				}
			}

			set_site_transient( 'wcb-inspire-urls', $inspire_urls, 30 * DAY_IN_SECONDS );
		}

		$currencies = WordCamp_Budgets::get_currencies();
		foreach ( $currencies as $key => $value ) {
			if ( substr( $key, 0, 4 ) == 'null' ) {
				unset( $currencies[ $key ] );
			}
		}

		require dirname( __DIR__ ) . '/views/budget-tool/main.php';
	}

	/**
	 * Respond to heartbeat with a custom nonce.
	 */
	public static function heartbeat_received( $response, $data ) {
		if ( empty( $data['wcb_budgets_heartbeat'] ) ) {
			return $response;
		}

		$response['wcb_budgets'] = array(
			'nonce' => wp_create_nonce( 'wcb_budget_noop-options' ),
		);

		return $response;
	}

	/**
	 * Dynamically add the budget capability to site admins, superadmins, and trusted deputies.
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		global $trusted_deputies;

		if ( 'wcb_approve_budget' === $cap ) {
			if ( user_can( $user_id, is_multisite() ? 'manage_network' : 'manage_options' ) ) {
				$caps = array( 'exist' );
			} elseif ( in_array( $user_id, (array) $trusted_deputies ) ) {
				$caps = array( 'exist' );
			}
		}

		return $caps;
	}
}

WordCamp_Budget_Tool::load();

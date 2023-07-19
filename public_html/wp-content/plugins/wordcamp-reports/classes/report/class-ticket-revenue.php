<?php
/**
 * Ticket Revenue.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use Exception;
use WordCamp\Reports;
use WordCamp\Utilities\{ Currency_XRT_Client };
use WordPressdotorg\MU_Plugins\Utilities\{ Export_CSV };
use const WordCamp\Reports\CAPABILITY;
use function WordCamp\Reports\Validation\{validate_wordcamp_id};

/**
 * Class Ticket_Revenue
 *
 * @package WordCamp\Reports\Report
 */
class Ticket_Revenue extends Date_Range {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Ticket Revenue';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'ticket-revenue';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'A breakdown of WordCamp ticket revenue during a given time period.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		<ol>
			<li>Query the CampTix events log for attendee status changes to "publish" or "refund" during the specified date range.</li>
			<li>Query each WordCamp site with matched events and retrieve ticket data related to each event.</li>
			<li>Append the ticket data to the event data.</li>
			<li>Group the events by payment method.</li>
		</ol>
	';

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'finance';

	/**
	 * Shortcode tag for outputting the public report form.
	 *
	 * @var string
	 */
	public static $shortcode_tag = 'ticket_revenue_report';

	/**
	 * REST route for this report.
	 *
	 * @var string
	 */
	//public static $rest_base = 'ticket-revenue';

	/**
	 * WordCamp post ID.
	 *
	 * @var int The ID of the WordCamp post for this report.
	 */
	public $wordcamp_id = 0;

	/**
	 * WordCamp site ID.
	 *
	 * @var int The ID of the WordCamp site where the invoices are located.
	 */
	public $wordcamp_site_id = 0;

	/**
	 * Currency exchange rate client.
	 *
	 * @var Currency_XRT_Client Utility to handle currency conversion.
	 */
	protected $xrt = null;

	/**
	 * Data fields that can be visible in a public context.
	 *
	 * @var array An associative array of key/default value pairs.
	 */
	protected $public_data_fields = array(
		'timestamp'        => '',
		'blog_id'          => 0,
		'object_id'        => 0,
		'type'             => '',
		'method'           => '',
		'currency'         => '',
		'full_price'       => 0,
		'discounted_price' => 0,
	);

	/**
	 * Ticket_Revenue constructor.
	 *
	 * @param string $start_date  The start of the date range for the report.
	 * @param string $end_date    The end of the date range for the report.
	 * @param int    $wordcamp_id Optional. The ID of a WordCamp post to limit this report to.
	 * @param array  $options     {
	 *     Optional. Additional report parameters.
	 *     See Base::__construct and Date_Range::__construct for additional parameters.
	 * }
	 */
	public function __construct( $start_date, $end_date, $wordcamp_id = 0, array $options = array() ) {
		parent::__construct( $start_date, $end_date, $options );

		$this->xrt = new Currency_XRT_Client();

		if ( $wordcamp_id ) {
			try {
				$valid = validate_wordcamp_id( $wordcamp_id );

				$this->wordcamp_id      = $valid->post_id;
				$this->wordcamp_site_id = $valid->site_id;
			} catch ( Exception $e ) {
				$this->error->add(
					self::$slug . '-wordcamp-id-error',
					$e->getMessage()
				);
			}
		}
	}

	/**
	 * Generate a cache key.
	 *
	 * @return string
	 */
	protected function get_cache_key() {
		$cache_key = parent::get_cache_key();

		if ( $this->wordcamp_id ) {
			$cache_key .= '_' . $this->wordcamp_id;
		}

		return $cache_key;
	}

	/**
	 * Query and parse the data for the report.
	 *
	 * @return array
	 */
	public function get_data() {
		// Bail if there are errors.
		if ( ! empty( $this->error->get_error_messages() ) ) {
			return array();
		}

		// Maybe use cached data.
		$data = $this->maybe_get_cached_data();
		if ( is_array( $data ) ) {
			return $data;
		}

		// This script is a memory hog for date intervals larger than ~2 months.
		// @todo Maybe find a way to run this without having to hack the memory limit.
		ini_set( 'memory_limit', '512M' );

		$data = $this->get_indexed_camptix_events( array(
			'Attendee status has been changed to publish',
			'Attendee status has been changed to refund',
		) );

		$tickets_by_site = $this->sort_indexed_ticket_ids_by_site( $data );
		$ticket_details  = array();

		foreach ( $tickets_by_site as $blog_id => $ticket_ids ) {
			$ticket_details = array_merge( $ticket_details, $this->get_ticket_details( $blog_id, $ticket_ids ) );
		}

		array_walk( $data, function( &$event ) use ( $ticket_details ) {
			if ( false !== strpos( $event['message'], 'publish' ) ) {
				$event['type'] = 'Purchase';
				unset( $event['message'] );
			} elseif ( false !== strpos( $event['message'], 'refund' ) ) {
				$event['type'] = 'Refund';
				unset( $event['message'] );
			}

			$details_key = $event['blog_id'] . '_' . $event['object_id'];

			if ( isset( $ticket_details[ $details_key ] ) ) {
				$event['method']           = $ticket_details[ $details_key ]['method'];
				$event['currency']         = $ticket_details[ $details_key ]['currency'];
				$event['full_price']       = $ticket_details[ $details_key ]['full_price'];
				$event['discounted_price'] = $ticket_details[ $details_key ]['discounted_price'];
			}
		} );

		$data = $this->filter_data_fields( $data );
		$this->maybe_cache_data( $data );

		return $data;
	}

	/**
	 * Compile the report data into results.
	 *
	 * @param array $data The data to compile.
	 *
	 * @return array
	 */
	public function compile_report_data( array $data ) {
		$compiled_data = $this->derive_revenue_from_ticket_events( $data );

		return $compiled_data;
	}

	/**
	 * Retrieve events from the CampTix log database table.
	 *
	 * @param array $message_filter Array of strings to search for in the event message field, using the OR operator.
	 *
	 * @return array
	 */
	protected function get_indexed_camptix_events( array $message_filter = array() ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$table_name = $wpdb->base_prefix . 'camptix_log';

		$where_clause = array();
		$where_values = array();
		$where        = '';

		$where_clause[] = 'UNIX_TIMESTAMP( timestamp ) BETWEEN ' .
						  $this->start_date->getTimestamp() .
						  ' AND ' .
						  $this->end_date->getTimestamp();

		if ( ! empty( $message_filter ) ) {
			$like_clause = array();

			foreach ( $message_filter as $string ) {
				$like_clause[]  = 'message LIKE \'%%%s%%\'';
				$where_values[] = $string;
			}

			$where_clause[] = '( ' . implode( ' OR ', $like_clause ) . ' )';
		}

		if ( $this->wordcamp_site_id ) {
			$where_clause[] = 'blog_id = %d';
			$where_values[] = $this->wordcamp_site_id;
		} else {
			$excluded_ids   = implode( ',', array_map( 'absint', Reports\get_excluded_site_ids() ) );
			$where_clause[] = "blog_id NOT IN ( $excluded_ids )";
		}

		if ( ! empty( $where_clause ) ) {
			$where = 'WHERE ' . implode( ' AND ', $where_clause );
		}

		$sql = "
			SELECT timestamp, blog_id, object_id, message
			FROM $table_name
		" . $where;

		$query  = $wpdb->prepare( $sql, $where_values );
		$events = $wpdb->get_results( $query, ARRAY_A );

		return $events;
	}

	/**
	 * Group log event ticket IDs by their blog ID.
	 *
	 * @param array $events An array of CampTix log events/tickets.
	 *
	 * @return array
	 */
	protected function sort_indexed_ticket_ids_by_site( $events ) {
		$sorted = array();

		foreach ( $events as $event ) {
			if ( ! isset( $sorted[ $event['blog_id'] ] ) ) {
				$sorted[ $event['blog_id'] ] = array();
			}

			$sorted[ $event['blog_id'] ][] = $event['object_id'];
		}

		$sorted = array_map( 'array_unique', $sorted );

		return $sorted;
	}

	/**
	 * Get relevant details for a given list of tickets for a particular site.
	 *
	 * @param int   $blog_id    The ID of the site that the tickets are associated with.
	 * @param array $ticket_ids The IDs of specific tickets to get details for.
	 *
	 * @return array
	 */
	protected function get_ticket_details( $blog_id, array $ticket_ids ) {
		$ticket_details = array();
		$currency       = '';

		switch_to_blog( $blog_id );

		$options = get_option( 'camptix_options', array() );

		if ( isset( $options['currency'] ) ) {
			$currency = $options['currency'];
		}

		foreach ( $ticket_ids as $ticket_id ) {
			$method = get_post_meta( $ticket_id, 'tix_payment_method', true ) ?: 'none';

			$ticket_details[ $blog_id . '_' . $ticket_id ] = array(
				'method'           => $method,
				'currency'         => $currency,
				'full_price'       => floatval( get_post_meta( $ticket_id, 'tix_ticket_price', true ) ),
				'discounted_price' => floatval( get_post_meta( $ticket_id, 'tix_ticket_discounted_price', true ) ),
			);

			clean_post_cache( $ticket_id );
		}

		restore_current_blog();

		return $ticket_details;
	}

	/**
	 * Aggregate revenue totals from a list of ticket events.
	 *
	 * @param array $events The ticket events.
	 *
	 * @return array
	 */
	protected function derive_revenue_from_ticket_events( array $events ) {
		$initial_data = array(
			'tickets_sold'                => 0,
			'gross_revenue_by_currency'   => array(),
			'discounts_by_currency'       => array(),
			'tickets_refunded'            => 0,
			'amount_refunded_by_currency' => array(),
			'net_revenue_by_currency'     => array(),
			'converted_net_revenue'       => array(),
			'total_converted_revenue'     => 0,
		);

		$data_groups = array(
			'total'     => array_merge( $initial_data, array(
				'label'       => 'Total ticket revenue',
				'description' => 'Not including transaction fees.',
			) ),
			'stripe'    => array_merge( $initial_data, array(
				'label'       => 'Ticket transactions through Stripe',
				'description' => '',
			) ),
			'paypal'    => array_merge( $initial_data, array(
				'label'       => 'Ticket transactions through PayPal',
				'description' => '',
			) ),
			'instamojo' => array_merge( $initial_data, array(
				'label'       => 'Ticket transactions through Instamojo',
				'description' => '',
			) ),
			'razorpay'  => array_merge( $initial_data, array(
				'label'       => 'Ticket transactions through Razorpay',
				'description' => '',
			) ),
			'none'      => array_merge( $initial_data, array(
				'label'       => 'Ticket transactions with no payment',
				'description' => 'Transactions for which no payment method was recorded.',
			) ),
		);

		$currencies = array();

		foreach ( $events as $event ) {
			$currency = $event['currency'];
			$method   = $event['method'];
			$type     = $event['type'];

			if ( ! isset( $data_groups[ $method ] ) ) {
				$data_groups[ $method ] = array_merge( $initial_data, array(
					'label'       => sprintf(
						'Ticket transactions through %s',
						esc_html( $method )
					),
					'description' => '',
				) );
			}

			if ( ! in_array( $currency, $currencies, true ) ) {
				$data_groups[ $method ]['gross_revenue_by_currency'][ $currency ]   = 0;
				$data_groups[ $method ]['discounts_by_currency'][ $currency ]       = 0;
				$data_groups[ $method ]['amount_refunded_by_currency'][ $currency ] = 0;
				$data_groups[ $method ]['net_revenue_by_currency'][ $currency ]     = 0;
				$data_groups['total']['gross_revenue_by_currency'][ $currency ]     = 0;
				$data_groups['total']['discounts_by_currency'][ $currency ]         = 0;
				$data_groups['total']['amount_refunded_by_currency'][ $currency ]   = 0;
				$data_groups['total']['net_revenue_by_currency'][ $currency ]       = 0;
				$currencies[] = $currency;
			}

			switch ( $type ) {
				case 'Purchase':
					$data_groups[ $method ]['tickets_sold'] ++;
					$data_groups[ $method ]['gross_revenue_by_currency'][ $currency ] += $event['full_price'];
					$data_groups[ $method ]['discounts_by_currency'][ $currency ]     += $event['full_price'] - $event['discounted_price'];
					$data_groups[ $method ]['net_revenue_by_currency'][ $currency ]   += $event['discounted_price'];
					$data_groups['total']['tickets_sold'] ++;
					$data_groups['total']['gross_revenue_by_currency'][ $currency ] += $event['full_price'];
					$data_groups['total']['discounts_by_currency'][ $currency ]     += $event['full_price'] - $event['discounted_price'];
					$data_groups['total']['net_revenue_by_currency'][ $currency ]   += $event['discounted_price'];
					break;

				case 'Refund':
					$data_groups[ $method ]['tickets_refunded'] ++;
					$data_groups[ $method ]['amount_refunded_by_currency'][ $currency ] += $event['discounted_price'];
					$data_groups[ $method ]['net_revenue_by_currency'][ $currency ]     -= $event['discounted_price'];
					$data_groups['total']['tickets_refunded']  ++;
					$data_groups['total']['amount_refunded_by_currency'][ $currency ] += $event['discounted_price'];
					$data_groups['total']['net_revenue_by_currency'][ $currency ]     -= $event['discounted_price'];
					break;
			}
		} // End foreach().

		foreach ( $data_groups as &$group ) {
			ksort( $group['gross_revenue_by_currency'] );
			ksort( $group['discounts_by_currency'] );
			ksort( $group['amount_refunded_by_currency'] );
			ksort( $group['net_revenue_by_currency'] );

			foreach ( $group['net_revenue_by_currency'] as $currency => $amount ) {
				if ( 'USD' === $currency ) {
					$group['converted_net_revenue'][ $currency ] = $amount;
				} else {
					$group['converted_net_revenue'][ $currency ] = 0;

					$conversion = $this->xrt->convert( $amount, $currency, $this->end_date->format( 'Y-m-d' ) );

					if ( is_wp_error( $conversion ) ) {
						// Unsupported currencies are ok, but other errors should be surfaced.
						if ( 'unknown_currency' !== $conversion->get_error_code() ) {
							$this->merge_errors( $this->error, $conversion );
						}
					} else {
						$group['converted_net_revenue'][ $currency ] = $conversion->USD;
					}
				}
			}

			$group['total_converted_revenue'] = array_reduce( $group['converted_net_revenue'], function( $carry, $item ) {
				return $carry + floatval( $item );
			}, 0 );
		}

		return $data_groups;
	}

	/**
	 * Render an HTML version of the report output.
	 *
	 * @return void
	 */
	public function render_html() {
		if ( ! empty( $this->error->get_error_messages() ) ) {
			$this->render_error_html();
			return;
		}

		$now = new \DateTime();

		$start_date    = $this->start_date;
		$end_date      = $this->end_date;
		$xrt_date      = ( $end_date > $now ) ? $now : $end_date;
		$wordcamp_name = ( $this->wordcamp_site_id ) ? get_wordcamp_name( $this->wordcamp_site_id ) : '';
		$data          = $this->compile_report_data( $this->get_data() );
		$total         = $data['total'];

		include Reports\get_views_dir_path() . 'html/ticket-revenue.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$start_date  = filter_input( INPUT_POST, 'start-date' );
		$end_date    = filter_input( INPUT_POST, 'end-date' );
		$wordcamp_id = filter_input( INPUT_POST, 'wordcamp-id' );
		$refresh     = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action      = filter_input( INPUT_POST, 'action' );
		$nonce       = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Show results' === $action
			 && wp_verify_nonce( $nonce, 'run-report' )
			 && current_user_can( CAPABILITY )
		) {
			$options = array(
				'earliest_start' => new \DateTime( '2015-01-01' ), // No indexed CampTix events before 2015.
				'max_interval'   => new \DateInterval( 'P1Y' ), // 1 year. See http://php.net/manual/en/dateinterval.construct.php.
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $wordcamp_id, $options );

			// The report adjusts the end date in some circumstances.
			if ( empty( $report->error->get_error_messages() ) ) {
				$end_date = $report->end_date->format( 'Y-m-d' );
			}
		}

		include Reports\get_views_dir_path() . 'report/ticket-revenue.php';
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$start_date  = filter_input( INPUT_POST, 'start-date' );
		$end_date    = filter_input( INPUT_POST, 'end-date' );
		$wordcamp_id = filter_input( INPUT_POST, 'wordcamp-id' );
		$refresh     = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action      = filter_input( INPUT_POST, 'action' );
		$nonce       = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Export CSV' !== $action ) {
			return;
		}

		if ( wp_verify_nonce( $nonce, 'run-report' ) && current_user_can( CAPABILITY ) ) {
			$options = array(
				'earliest_start' => new \DateTime( '2015-01-01' ), // No indexed CampTix events before 2015.
				'max_interval'   => new \DateInterval( 'P1Y' ), // 1 year. See http://php.net/manual/en/dateinterval.construct.php.
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $wordcamp_id, $options );

			// The report adjusts the end date in some circumstances.
			if ( empty( $report->error->get_error_messages() ) ) {
				$end_date = $report->end_date->format( 'Y-m-d' );
			}

			$filename = array( $report::$name );
			if ( $report->wordcamp_site_id ) {
				$filename[] = get_wordcamp_name( $report->wordcamp_site_id );
			}
			$filename[] = $report->start_date->format( 'Y-m-d' );
			$filename[] = $report->end_date->format( 'Y-m-d' );

			$headers = array( 'Date', 'Blog ID', 'Attendee ID', 'Type', 'Payment Method', 'Currency', 'Full Price', 'Discounted Price' );

			$data = $report->get_data();

			$exporter = new Export_CSV( array(
				'filename' => $filename,
				'headers'  => $headers,
				'data'     => $data,
			) );

			if ( ! empty( $report->error->get_error_messages() ) ) {
				$exporter->error = $report->merge_errors( $report->error, $exporter->error );
			}

			$exporter->emit_file();
		} // End if().
	}

	/**
	 * Determine whether to render the public report form.
	 *
	 * This shortcode is limited to use on pages.
	 *
	 * @return string HTML content to display shortcode.
	 */
	public static function handle_shortcode() {
		$html = '';

		if ( 'page' === get_post_type() ) {
			ob_start();
			self::render_public_page();
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * Render the page for this report on the front end.
	 *
	 * @return void
	 */
	public static function render_public_page() {
		// Apparently 'year' is a reserved URL parameter on the front end, so we prepend 'report-'.
		$year        = filter_input( INPUT_GET, 'report-year', FILTER_VALIDATE_INT );
		$period      = filter_input( INPUT_GET, 'period' );
		$wordcamp_id = filter_input( INPUT_GET, 'wordcamp-id' );
		$action      = filter_input( INPUT_GET, 'action' );

		$years    = self::year_array( absint( date( 'Y' ) ), 2015 );
		$quarters = self::quarter_array();
		$months   = self::month_array();

		if ( ! $year ) {
			$year = absint( date( 'Y' ) );
		}

		if ( ! $period ) {
			$period = absint( date( 'm' ) );
		}

		$report = null;

		if ( 'Show results' === $action ) {
			$range = self::convert_time_period_to_date_range( $year, $period );

			$options = array(
				'earliest_start' => new \DateTime( '2015-01-01' ), // No indexed CampTix events before 2015.
				'max_interval'   => new \DateInterval( 'P1Y' ), // 1 year. See http://php.net/manual/en/dateinterval.construct.php.
			);

			$report = new self( $range['start_date'], $range['end_date'], $wordcamp_id, $options );
		}

		include Reports\get_views_dir_path() . 'public/ticket-revenue.php';
	}

	/**
	 * Prepare a REST response version of the report output.
	 *
	 * @todo Make the params here match the public page.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_callback( \WP_REST_Request $request ) {
		$params = wp_parse_args( $request->get_params(), array(
			'start_date'  => '',
			'end_date'    => '',
			'wordcamp_id' => 0,
		) );

		$options = array(
			'earliest_start' => new \DateTime( '2015-01-01' ), // No indexed CampTix events before 2015.
			'max_interval'   => new \DateInterval( 'P1Y' ), // 1 year. See http://php.net/manual/en/dateinterval.construct.php.
		);

		$report = new self( $params['start_date'], $params['end_date'], $params['wordcamp_id'], $options );

		if ( $report->error->get_error_messages() ) {
			$response = self::prepare_rest_response( $report->error->errors );
			$response->set_status( 400 );
		} else {
			$response = self::prepare_rest_response( $report->compile_report_data( $report->get_data() ) );
		}

		return $response;
	}
}

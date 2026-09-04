<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use DateTime;
use Exception;
use WordCamp\Reports\Utility\Date_Range;
use WordCamp\Utilities\Currency_XRT_Client;
use WordPressdotorg\MU_Plugins\Utilities\Export_CSV;
use const WordCamp\Reports\CAPABILITY;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\validate_date_range;

/**
 * Class Sponsor_ROI
 *
 * Joins sponsor spend (Sponsor Invoices) with event reach (registered + checked-in attendee
 * counts), rolled up across each sponsor's portfolio (their Multi-Event Sponsor agreement).
 * Aggregates only — no new tracking.
 *
 * @package WordCamp\Reports\Report
 */
class Sponsor_ROI extends Base {
	/**
	 * Invoice statuses that count toward spend, default mode: paid only.
	 *
	 * @var array
	 */
	const COUNTED_STATUSES_PAID = array( 'wcbsi_paid' );

	/**
	 * Invoice statuses that count toward spend when including sent invoices.
	 *
	 * @var array
	 */
	const COUNTED_STATUSES_PAID_SENT = array( 'wcbsi_paid', 'wcbsi_approved' );

	/**
	 * The currency that all spend amounts are normalized to.
	 *
	 * @var string
	 */
	const BASE_CURRENCY = 'USD';

	/**
	 * Sponsor country meta key.
	 *
	 * @var string
	 */
	const COUNTRY_META = '_wcpt_sponsor_country';

	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Sponsor ROI';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'sponsor-roi';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Sponsor spend joined with event reach (registered + checked-in), rolled up across each sponsor\'s portfolio.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = "
		<ol>
			<li>Retrieve the WordCamps whose start date falls within the specified date range (WordCamp Details report), skipping camps without a website.</li>
			<li>On each camp site, list published Sponsor posts. For each sponsor, sum their Sponsor Invoice amounts — paid invoices only by default — grouped by currency, and normalize to USD at the event-date exchange rate.</li>
			<li>Count the camp's reach: published attendee records (registered) and attendees marked as attended via CampTix check-in (checked in). When no check-in data exists at all, the camp is flagged 'attendance not measured' rather than counted as zero.</li>
			<li>Roll the per-camp rows up into one portfolio per Multi-Event Sponsor agreement (sponsors without an agreement stay as single-camp rows) and compute cost per registered / checked-in attendee.</li>
		</ol>
	";

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'wordcamp';

	/**
	 * The date range that defines the scope of the report data.
	 *
	 * @var null|Date_Range
	 */
	public $range = null;

	/**
	 * Optional Multi-Event Sponsor agreement filter (0 = all).
	 *
	 * @var int
	 */
	public $mes_id = 0;

	/**
	 * Exchange-rate client. Settable for tests.
	 *
	 * @var Currency_XRT_Client|object|null
	 */
	public $xrt = null;

	/**
	 * Memoized camp list from the WordCamp Details report.
	 *
	 * @var array|null
	 */
	protected $wordcamps = null;

	/**
	 * Data fields that can be visible in a public context.
	 *
	 * @var array An associative array of key/default value pairs.
	 */
	protected $public_data_fields = array(
		'rollup_key'          => '',
		'mes_id'              => 0,
		'wordcamp_id'         => 0,
		'wordcamp_name'       => '',
		'event_date'          => '',
		'sponsor_name'        => '',
		'tier'                => '',
		'website'             => '',
		'country'             => '',
		'first_time'          => '',
		'registered'          => 0,
		'attended'            => 0,
		'attendance_measured' => false,
	);

	/**
	 * Data fields that should only be visible in a private context.
	 *
	 * @var array An associative array of key/default value pairs.
	 */
	protected $private_data_fields = array(
		'site_id'     => 0,
		'sponsor_id'  => 0,
		'spend_usd'   => 0.0,
		'has_invoice' => false,
	);

	/**
	 * Sponsor_ROI constructor.
	 *
	 * @param string $start_date The start of the date range for the report.
	 * @param string $end_date   The end of the date range for the report.
	 * @param int    $mes_id     Optional. Filter the report to one MES agreement.
	 * @param array  $options    Optional. Additional report parameters. See Base::__construct
	 *                          and the functions in WordCamp\Reports\Validation for the full set.
	 */
	public function __construct( $start_date, $end_date, $mes_id = 0, array $options = array() ) {
		parent::__construct( $options );

		$this->mes_id = absint( $mes_id );

		try {
			$this->range = validate_date_range( $start_date, $end_date, $options );
		} catch ( Exception $e ) {
			$this->error->add(
				self::$slug . '-date-error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Generate a cache key.
	 *
	 * The base key only varies by report slug and public/private context; without
	 * the range and filters in the key, a 2024 run would serve cached 2025 data.
	 *
	 * @return string
	 */
	protected function get_cache_key() {
		$cache_key_segments = array(
			parent::get_cache_key(),
			$this->range->generate_cache_key_segment(),
		);

		if ( $this->mes_id ) {
			$cache_key_segments[] = 'mes-' . $this->mes_id;
		}

		if ( ! empty( $this->options['include_sent_invoices'] ) ) {
			$cache_key_segments[] = '+sent';
		}

		return implode( '_', $cache_key_segments );
	}

	/**
	 * Generate a cache expiration interval.
	 *
	 * @return int A time interval in seconds.
	 */
	protected function get_cache_expiration() {
		return $this->range->generate_cache_duration( parent::get_cache_expiration() );
	}

	/**
	 * Query and parse the data for the report.
	 *
	 * Emits one raw row per sponsor per camp. Reach counts are whole-event
	 * numbers repeated on every sponsor row for that camp — the audience the
	 * sponsor's placement reached, not a per-sponsor figure.
	 *
	 * @return array
	 */
	public function get_data() {
		// Bail if there are errors.
		if ( ! empty( $this->error->get_error_messages() ) ) {
			return array();
		}

		// Maybe use cached data.
		$cached = $this->maybe_get_cached_data();
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$camp_rows = array();
		$wordcamps = $this->get_wordcamps();

		foreach ( $wordcamps as $wordcamp_id => $info ) {
			$valid = $this->resolve_wordcamp( $wordcamp_id );

			if ( ! $valid ) {
				continue; // Skip camps without a usable subsite.
			}

			$event_date = $info['Start Date (YYYY-mm-dd)']
				? gmdate( 'Y-m-d', (int) $info['Start Date (YYYY-mm-dd)'] )
				: '';

			$camp_rows[] = $this->get_camp_sponsors( $valid->site_id, $valid->post_id, $info['Name'], $event_date );
		}

		$data = $camp_rows ? array_merge( ...$camp_rows ) : array();

		$data = $this->filter_data_fields( $data );
		$this->maybe_cache_data( $data );

		return $data;
	}

	/**
	 * Get the list of WordCamps in the date range from the WordCamp Details report.
	 *
	 * @return array Camp post ID => array with ID, Name, URL, Start Date, Status.
	 */
	protected function get_wordcamps() {
		if ( is_array( $this->wordcamps ) ) {
			return $this->wordcamps;
		}

		$details_report = new WordCamp_Details( $this->range, array(), false, array( 'public' => false ) );

		if ( ! empty( $details_report->error->get_error_messages() ) ) {
			$this->error = $this->merge_errors( $this->error, $details_report->error );

			return array();
		}

		$wordcamps = array_filter(
			$details_report->get_data(),
			function ( $wordcamp ) {
				return ! empty( $wordcamp['URL'] );
			}
		);

		$this->wordcamps = array_reduce(
			$wordcamps,
			function ( $carry, $item ) {
				$keep = array(
					'ID'                      => '',
					'Name'                    => '',
					'URL'                     => '',
					'Start Date (YYYY-mm-dd)' => '',
					'Status'                  => '',
				);

				$carry[ $item['ID'] ] = array_intersect_key( $item, $keep );

				return $carry;
			},
			array()
		);

		return $this->wordcamps;
	}

	/**
	 * Resolve a WordCamp post ID to its site/post ID pair, or null when unusable.
	 *
	 * @param int $wordcamp_id WordCamp post ID on the central site.
	 *
	 * @return object|null Object with site_id and post_id properties, or null.
	 */
	protected function resolve_wordcamp( $wordcamp_id ) {
		try {
			return \WordCamp\Reports\Validation\validate_wordcamp_id( $wordcamp_id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Gather one raw row per sponsor on a single camp subsite.
	 *
	 * @param int    $site_id       The camp's subsite blog ID.
	 * @param int    $wordcamp_id   The WordCamp post ID on the central site.
	 * @param string $wordcamp_name The camp's name.
	 * @param string $event_date    The camp's start date, 'Y-m-d'.
	 *
	 * @return array
	 */
	protected function get_camp_sponsors( $site_id, $wordcamp_id, $wordcamp_name, $event_date ) {
		$rows           = array();
		$count_statuses = $this->get_counted_statuses();

		switch_to_blog( $site_id );

		$sponsor_query = array(
			'post_type'      => 'wcb_sponsor',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		if ( $this->mes_id ) {
			$sponsor_query['meta_key']   = '_mes_id';
			$sponsor_query['meta_value'] = $this->mes_id;
		}

		$sponsors = get_posts( $sponsor_query );

		if ( empty( $sponsors ) ) {
			restore_current_blog();

			return $rows;
		}

		$reach = $this->get_camp_reach();

		foreach ( $sponsors as $sponsor ) {
			$mes_id = absint( get_post_meta( $sponsor->ID, '_mes_id', true ) );
			$totals = $this->get_sponsor_invoice_totals( $sponsor->ID, $count_statuses );

			$spend_usd = 0.0;
			foreach ( $totals as $currency => $amount ) {
				$spend_usd += $this->to_base_currency( $amount, $currency, $event_date );
			}

			$levels       = wp_get_post_terms( $sponsor->ID, 'wcb_sponsor_level', array( 'fields' => 'names' ) );
			$company_name = (string) get_post_meta( $sponsor->ID, '_wcpt_sponsor_company_name', true );

			$rows[] = array(
				'rollup_key'          => $mes_id ? (string) $mes_id : "local-{$site_id}-{$sponsor->ID}",
				'mes_id'              => $mes_id,
				'wordcamp_id'         => (int) $wordcamp_id,
				'site_id'             => (int) $site_id,
				'wordcamp_name'       => (string) $wordcamp_name,
				'event_date'          => (string) $event_date,
				'sponsor_id'          => (int) $sponsor->ID,
				'sponsor_name'        => '' !== $company_name ? $company_name : $sponsor->post_title,
				'tier'                => ! empty( $levels ) && ! is_wp_error( $levels ) ? (string) $levels[0] : '',
				'website'             => (string) get_post_meta( $sponsor->ID, '_wcpt_sponsor_website', true ),
				'country'             => (string) get_post_meta( $sponsor->ID, self::COUNTRY_META, true ),
				'first_time'          => (string) get_post_meta( $sponsor->ID, '_wcb_sponsor_first_time', true ),
				'spend_usd'           => $spend_usd,
				'has_invoice'         => ! empty( $totals ),
				'registered'          => $reach['registered'],
				'attended'            => $reach['attended'],
				'attendance_measured' => $reach['measured'],
			);
		}

		restore_current_blog();

		return $rows;
	}

	/**
	 * Counted invoice statuses, honoring the include-sent option.
	 *
	 * @return array
	 */
	protected function get_counted_statuses() {
		return ! empty( $this->options['include_sent_invoices'] )
			? self::COUNTED_STATUSES_PAID_SENT
			: self::COUNTED_STATUSES_PAID;
	}

	/**
	 * Compile the raw rows into per-portfolio totals and ratios.
	 *
	 * Camps where attendance wasn't measured contribute to `registered` but not
	 * to `attended` — unknown is not zero — and are counted in `unmeasured_camps`
	 * so the display layer can caveat the cost-per-attended ratio.
	 *
	 * @param array $data The data to compile.
	 *
	 * @return array
	 */
	public function compile_report_data( array $data ) {
		$portfolios = array();

		foreach ( $data as $row ) {
			$key = $row['rollup_key'];

			if ( ! isset( $portfolios[ $key ] ) ) {
				$portfolios[ $key ] = array(
					'sponsor_name' => $row['sponsor_name'],
					'mes_id'       => $row['mes_id'],
					'camps'        => array(),
					'tiers'        => array(),
					'totals'       => array(
						'spend_usd'        => 0.0,
						'registered'       => 0,
						'attended'         => 0,
						'camp_count'       => 0,
						'unmeasured_camps' => 0,
					),
					'ratios'       => array(
						'cost_per_registered' => null,
						'cost_per_attended'   => null,
					),
				);
			}

			$portfolios[ $key ]['camps'][]                      = $row;
			$portfolios[ $key ]['tiers'][ $row['wordcamp_id'] ] = $row['tier'];
			$portfolios[ $key ]['totals']['spend_usd']         += $row['spend_usd'] ?? 0.0; // Private field, absent in a public context.
			$portfolios[ $key ]['totals']['registered']        += $row['registered'];
			++$portfolios[ $key ]['totals']['camp_count'];

			if ( ! empty( $row['attendance_measured'] ) ) {
				$portfolios[ $key ]['totals']['attended'] += $row['attended'];
			} else {
				++$portfolios[ $key ]['totals']['unmeasured_camps'];
			}
		}

		foreach ( $portfolios as &$portfolio ) {
			$spend      = $portfolio['totals']['spend_usd'];
			$registered = $portfolio['totals']['registered'];
			$attended   = $portfolio['totals']['attended'];

			$portfolio['ratios']['cost_per_registered'] = $registered > 0 ? $spend / $registered : null;
			$portfolio['ratios']['cost_per_attended']   = $attended > 0 ? $spend / $attended : null;
		}
		unset( $portfolio );

		return $portfolios;
	}

	/**
	 * Sum a sponsor's invoice amounts, grouped by currency, filtered by status.
	 *
	 * Must be called within switch_to_blog() of the sponsor's subsite.
	 *
	 * @param int   $sponsor_id     Local wcb_sponsor post ID.
	 * @param array $count_statuses Invoice post statuses that count toward spend.
	 *
	 * @return array Map of currency code => summed amount (float).
	 */
	protected function get_sponsor_invoice_totals( $sponsor_id, array $count_statuses ) {
		$totals = array();

		$invoices = get_posts( array(
			'post_type'      => 'wcb_sponsor_invoice',
			'post_status'    => $count_statuses,
			'posts_per_page' => -1,
			'meta_key'       => '_wcbsi_sponsor_id',
			'meta_value'     => absint( $sponsor_id ),
		) );

		foreach ( $invoices as $invoice ) {
			// WP_Query silently drops statuses that aren't registered in the current
			// context, which makes the query fail OPEN (returns every status). Re-check
			// in PHP so an unregistered status can never inflate spend.
			if ( ! in_array( $invoice->post_status, $count_statuses, true ) ) {
				continue;
			}

			$currency = (string) get_post_meta( $invoice->ID, '_wcbsi_currency', true );
			$amount   = floatval( get_post_meta( $invoice->ID, '_wcbsi_amount', true ) );

			if ( '' === $currency ) {
				continue;
			}

			$totals[ $currency ] = ( $totals[ $currency ] ?? 0.0 ) + $amount;
		}

		return $totals;
	}

	/**
	 * Convert an amount to the base currency (USD).
	 *
	 * @param float  $amount   Amount in $currency.
	 * @param string $currency ISO currency code.
	 * @param string $date     'Y-m-d' rate date (the event date).
	 *
	 * @return float Amount in base currency; 0.0 if the currency is unknown.
	 */
	protected function to_base_currency( $amount, $currency, $date ) {
		if ( self::BASE_CURRENCY === $currency ) {
			return (float) $amount; // convert() has no special case for the base currency.
		}

		if ( null === $this->xrt ) {
			$this->xrt = new Currency_XRT_Client( self::BASE_CURRENCY );
		}

		$conversion = $this->xrt->convert( $amount, $currency, $date );

		if ( is_wp_error( $conversion ) ) {
			if ( 'unknown_currency' !== $conversion->get_error_code() ) {
				$this->merge_errors( $this->error, $conversion );
			}

			return 0.0;
		}

		$base = self::BASE_CURRENCY;

		return (float) $conversion->$base;
	}

	/**
	 * Count registered and checked-in attendees for the current (switched) site.
	 *
	 * `measured` distinguishes "check-in wasn't used at this event" from a real zero,
	 * so events checked in with external tools aren't reported as 100% no-show.
	 *
	 * @return array { 'registered' => int, 'attended' => int, 'measured' => bool }
	 */
	protected function get_camp_reach() {
		$registered = new \WP_Query( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );

		$attended = new \WP_Query( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'tix_attended',
			'meta_value'     => '1', // A boolean true lands in the database as the string '1'.
		) );

		$measured = new \WP_Query( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => 'tix_attended',
					'compare' => 'EXISTS',
				),
			),
		) );

		return array(
			'registered' => (int) $registered->found_posts,
			'attended'   => (int) $attended->found_posts,
			'measured'   => $measured->found_posts > 0,
		);
	}

	/**
	 * Flat CSV headers (one row per sponsor-per-camp; internal export).
	 *
	 * @return array Map of row key => column label.
	 */
	public function get_data_headers() {
		return array(
			'rollup_key'          => 'Portfolio Key',
			'mes_id'              => 'MES ID',
			'sponsor_name'        => 'Sponsor',
			'wordcamp_name'       => 'WordCamp',
			'event_date'          => 'Event Date',
			'tier'                => 'Tier',
			'spend_usd'           => 'Spend (USD)',
			'has_invoice'         => 'Has Invoice',
			'registered'          => 'Registered',
			'attended'            => 'Checked In',
			'attendance_measured' => 'Attendance Measured',
			'website'             => 'Sponsor Website',
			'country'             => 'Country',
		);
	}

	/**
	 * Render an HTML version of the report output.
	 *
	 * @return void
	 */
	public function render_html() {
		$data = $this->get_data();

		if ( ! empty( $this->error->get_error_messages() ) ) {
			$this->render_error_html();

			return;
		}

		$compiled = $this->compile_report_data( $data );

		include get_views_dir_path() . 'html/sponsor-roi.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( CAPABILITY ) ) {
			return;
		}

		$start_date = wp_unslash( $_POST['start-date'] ?? '' );
		$end_date   = wp_unslash( $_POST['end-date'] ?? '' );
		$mes_id     = absint( $_POST['mes-id'] ?? 0 );
		$refresh    = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action     = wp_unslash( $_POST['action'] ?? '' );
		$nonce      = wp_unslash( $_POST[ self::$slug . '-nonce' ] ?? '' );

		$report = null;

		if ( 'Show results' === $action
			&& wp_verify_nonce( $nonce, 'run-report' )
			&& current_user_can( CAPABILITY )
		) {
			$options = array(
				'earliest_start' => new DateTime( '2015-01-01' ), // No indexed payment data before 2015.
				'public'         => false,
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $mes_id, $options );
		}

		include get_views_dir_path() . 'report/sponsor-roi.php';
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$start_date = wp_unslash( $_POST['start-date'] ?? '' );
		$end_date   = wp_unslash( $_POST['end-date'] ?? '' );
		$mes_id     = absint( $_POST['mes-id'] ?? 0 );
		$action     = wp_unslash( $_POST['action'] ?? '' );
		$report     = wp_unslash( $_GET['report'] ?? '' );
		$nonce      = wp_unslash( $_POST[ self::$slug . '-nonce' ] ?? '' );

		if ( $report !== self::$slug ) {
			return;
		}
		if ( 'Export CSV' !== $action ) {
			return;
		}
		if ( ! wp_verify_nonce( $nonce, 'run-report' ) || ! current_user_can( CAPABILITY ) ) {
			return;
		}

		$report = new self( $start_date,
			$end_date,
			$mes_id,
			array(
				'earliest_start' => new DateTime( '2015-01-01' ),
				'public'         => false,
			)
		);

		$filename = array( $report::$name, wp_date( 'Y-m-d' ) );

		$exporter = new Export_CSV( array(
			'filename' => $filename,
			'headers'  => array_values( $report->get_data_headers() ),
			'data'     => self::flatten_for_csv( $report->get_data(), array_keys( $report->get_data_headers() ) ),
		) );

		if ( ! empty( $report->error->get_error_messages() ) ) {
			$exporter->error = $report->merge_errors( $report->error, $exporter->error );
		}

		$exporter->emit_file();
	}

	/**
	 * Reduce raw rows to ordered scalar columns for CSV.
	 *
	 * @param array $rows    Raw data rows.
	 * @param array $columns Ordered list of row keys to keep.
	 *
	 * @return array
	 */
	protected static function flatten_for_csv( array $rows, array $columns ) {
		return array_map(
			function ( $row ) use ( $columns ) {
				$out = array();

				foreach ( $columns as $col ) {
					$value = $row[ $col ] ?? '';

					if ( is_bool( $value ) ) {
						$value = $value ? 'Yes' : 'No';
					}

					$out[ $col ] = $value;
				}

				return $out;
			},
			$rows
		);
	}
}

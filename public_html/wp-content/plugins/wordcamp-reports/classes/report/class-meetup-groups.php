<?php
/**
 * Meetup Groups.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use Exception;
use DateTimeImmutable, DateTime;
use WP_Error;
use WordCamp\Reports;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\validate_date_range;
use function WordCamp\Reports\Time\{year_array, quarter_array, month_array, convert_time_period_to_date_range};
use WordCamp\Utilities\{Meetup_Client, Export_CSV};

/**
 * Class Meetup_Groups
 *
 * @package WordCamp\Reports\Report
 */
class Meetup_Groups extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Meetup Groups';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'meetup-groups';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'The number of meetup groups in the Chapter program on a given date and the number of groups that joined during a given time period.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = "
		Retrieve data about groups in the Chapter program from the Meetup.com API. Only groups who joined the Chapter program before the specified end date will be included.
	";

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'meetup';

	/**
	 * Shortcode tag for outputting the public report form.
	 *
	 * @var string
	 */
	public static $shortcode_tag = 'meetup_groups_report';

	/**
	 * The date range that defines the scope of the report data.
	 *
	 * @var null|Date_Range
	 */
	public $range = null;

	/**
	 * Data fields that can be visible in a public context.
	 *
	 * @var array An associative array of key/default value pairs.
	 */
	protected $public_data_fields = array(
		'name'          => '',
		'urlname'       => '',
		'city'          => '',
		'state'         => '',
		'country'       => '',
		'lat'           => 0,
		'lon'           => 0,
		'member_count'  => 0,
		'founded_date'  => 0,
		'pro_join_date' => 0,
	);

	/**
	 * Meetup_Groups constructor.
	 *
	 * @param string $start_date       The start of the date range for the report.
	 * @param string $end_date         The end of the date range for the report.
	 * @param array  $options          {
	 *     Optional. Additional report parameters.
	 *     See Base::__construct and the functions in WordCamp\Reports\Validation for additional parameters.
	 * }
	 */
	public function __construct( $start_date, $end_date, array $options = [] ) {
		parent::__construct( $options );

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
	 * @return string
	 */
	protected function get_cache_key() {
		$cache_key_segments = [
			parent::get_cache_key(),
			$this->range->generate_cache_key_segment(),
		];

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

		$meetup = new Meetup_Client();

		$data = $meetup->get_groups( array(
			'pro_join_date_max' => $this->range->end,
		) );

		if ( is_wp_error( $data ) ) {
			$this->error = $this->merge_errors( $this->error, $data );

			return array();
		}

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
		$joined_groups = array_filter( $data, function( $group ) {
			$join_date = new DateTimeImmutable();
			$join_date = $join_date->setTimestamp( $group['pro_join_date'] );

			if ( $join_date >= $this->range->start && $join_date <= $this->range->end ) {
				return true;
			}

			return false;
		} );

		$compiled_data = array(
			'total_groups'              => count( $data ),
			'total_groups_by_country'   => $this->count_groups_by_country( $data ),
			'total_members'             => $this->count_members( $data ),
			'total_members_by_country'  => $this->count_group_members_by_country( $data ),
			'joined_groups'             => count( $joined_groups ),
			'joined_groups_by_country'  => $this->count_groups_by_country( $joined_groups ),
			'joined_members'            => $this->count_members( $joined_groups ),
			'joined_members_by_country' => $this->count_group_members_by_country( $joined_groups ),
		);

		return $compiled_data;
	}

	/**
	 * From a list of groups, count how many total members there are.
	 *
	 * @param array $groups Meetup groups.
	 *
	 * @return int The number of total members.
	 */
	protected function count_members( $groups ) {
		return array_reduce( $groups, function( $carry, $item ) {
			$carry += absint( $item['member_count'] );

			return $carry;
		}, 0 );
	}

	/**
	 * From a list of groups, count how many there are in each country.
	 *
	 * @param array $groups Meetup groups.
	 *
	 * @return array An associative array of country keys and group count values, sorted high to low.
	 */
	protected function count_groups_by_country( $groups ) {
		$counts = array_reduce( $groups, function( $carry, $item ) {
			$country = $item['country'];

			if ( ! isset( $carry[ $country ] ) ) {
				$carry[ $country ] = 0;
			}

			$carry[ $country ] ++;

			return $carry;
		}, array() );

		arsort( $counts );

		return $counts;
	}

	/**
	 * From a list of groups, count how many total group members there are in each country.
	 *
	 * @param array $groups Meetup groups.
	 *
	 * @return array An associative array of country keys and group member count values, sorted high to low.
	 */
	protected function count_group_members_by_country( $groups ) {
		$counts = array_reduce( $groups, function( $carry, $item ) {
			$country = $item['country'];

			if ( ! isset( $carry[ $country ] ) ) {
				$carry[ $country ] = 0;
			}

			$carry[ $country ] += absint( $item['member_count'] );

			return $carry;
		}, array() );

		arsort( $counts );

		return $counts;
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

		$data       = $this->compile_report_data( $this->get_data() );
		$start_date = $this->range->start;
		$end_date   = $this->range->end;

		include get_views_dir_path() . 'html/meetup-groups.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$start_date = filter_input( INPUT_POST, 'start-date' );
		$end_date   = filter_input( INPUT_POST, 'end-date' );
		$refresh    = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action     = filter_input( INPUT_POST, 'action' );
		$nonce      = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Show results' === $action
		     && wp_verify_nonce( $nonce, 'run-report' )
		     && current_user_can( 'manage_network' )
		) {
			$options = array(
				'earliest_start' => new DateTime( '2015-01-01' ), // Chapter program started in 2015.
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $options );
		}

		include get_views_dir_path() . 'report/meetup-groups.php';
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$start_date = filter_input( INPUT_POST, 'start-date' );
		$end_date   = filter_input( INPUT_POST, 'end-date' );
		$refresh    = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action     = filter_input( INPUT_POST, 'action' );
		$nonce      = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Export CSV' !== $action ) {
			return;
		}

		if ( wp_verify_nonce( $nonce, 'run-report' ) && current_user_can( 'manage_network' ) ) {
			$options = array(
				'earliest_start' => new DateTime( '2015-01-01' ), // Chapter program started in 2015.
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $options );

			$filename   = array( $report::$name );
			$filename[] = $report->range->start->format( 'Y-m-d' );
			$filename[] = $report->range->end->format( 'Y-m-d' );

			$headers = array( 'Name', 'URL', 'City', 'State', 'Country', 'Latitude', 'Longitude', 'Member Count', 'Date Founded', 'Date Joined' );

			$data = $report->get_data();

			array_walk( $data, function( &$group ) {
				$group['urlname']       = ( $group['urlname'] ) ? esc_url( 'https://www.meetup.com/' . $group['urlname'] . '/' ) : '';
				$group['founded_date']  = ( $group['founded_date'] ) ? gmdate( 'Y-m-d', $group['founded_date'] ) : '';
				$group['pro_join_date'] = ( $group['pro_join_date'] ) ? gmdate( 'Y-m-d', $group['pro_join_date'] ) : '';
			} );

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
		$year   = filter_input( INPUT_GET, 'report-year', FILTER_VALIDATE_INT );
		$period = filter_input( INPUT_GET, 'period' );
		$action = filter_input( INPUT_GET, 'action' );

		$years    = year_array( absint( date( 'Y' ) ), 2015 );
		$quarters = quarter_array();
		$months   = month_array();

		if ( ! $year ) {
			$year = absint( date( 'Y' ) );
		}

		if ( ! $period ) {
			$period = absint( date( 'm' ) );
		}

		$report = null;

		if ( 'Show results' === $action ) {
			$error = null;

			try {
				$range = convert_time_period_to_date_range( $year, $period );
			} catch ( Exception $e ) {
				$error = new WP_Error(
					self::$slug . '-time-period-error',
					$e->getMessage()
				);
			}

			$options = array(
				'earliest_start' => new DateTime( '2015-01-01' ), // Chapter program started in 2015.
			);

			$report = new self( $range->start, $range->end, $options );

			if ( ! is_null( $error ) ) {
				$report->merge_errors( $error, $report->error );
			}
		}

		include get_views_dir_path() . 'public/meetup-groups.php';
	}
}

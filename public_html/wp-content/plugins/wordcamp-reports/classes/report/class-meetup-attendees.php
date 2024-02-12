<?php
/**
 * Meetup Groups.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use Exception;
use DateTime, DateTimeInterface, DateInterval;
use WP_Error;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\validate_date_range;
use function WordCamp\Reports\Time\{year_array, quarter_array, month_array, convert_time_period_to_date_range};
use WordCamp\Utilities\{Meetup_Client, Export_CSV};

/**
 * Class Meetup_Attendees
 *
 * @package WordCamp\Reports\Report
 */
class Meetup_Attendees extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Meetup Attendees';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'meetup-attendees';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Total counts on meetup attendees during a given time period.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		Retrieve attendee counts for events in the Chapter program from the Meetup.com API.

		<strong>Attendee counts are counted by the event\'s RSVP answers and might not be fully truthful, because we can\'t know how many really attended.</strong>

		<strong>Note that this requires one or more requests to the API for every group in the Chapter program, so running this report may literally take 5-10 minutes.</strong>

		Known issues:

		<ul>
			<li>This will not take into account events for groups that were in the chapter program within the given date range, but no longer are.</li>
			<li>This will not take into account a group\'s events within the date range that occurred before the group joined the chapter program.</li>
		</ul>
	';

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'meetup';

	/**
	 * Shortcode tag for outputting the public report form.
	 *
	 * @todo
	 *
	 * @var string
	 */
	//public static $shortcode_tag = 'meetup_events_report';

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
	protected $public_data_fields = [
		'id'           		=> '',
		'link'         		=> '',
		'name'         		=> '',
		'description'  		=> '',
		'time'         		=> 0,
		'status'       		=> '',
		'group'        		=> '',
		'city'         		=> '',
		'l10n_country' 		=> '',
		'latitude'     		=> 0,
		'longitude'    		=> 0,
		'yes_rsvp_count'	=> 0,
	];

	/**
	 * Meetup_Attendees constructor.
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
			$this->options['search_query'],
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

		// @todo Maybe find a way to run this without having to hack the ini.
		ini_set( 'memory_limit', '900M' );
		ini_set( 'max_execution_time', 500 );
		ini_set( 'max_allowed_packet', 33554432 );

		$meetup = new Meetup_Client();

		$groups = $meetup->get_groups( array(
			// Don't include groups that joined the chapter program later than the date range.
			'pro_join_date_max' => $this->range->end->getTimestamp() * 1000,
			// Don't include groups whose last event was before the start of the date range.
			'last_event_min'    => $this->range->start->getTimestamp() * 1000,
		) );

		if ( is_wp_error( $groups ) ) {
			$this->error->add( $groups->get_error_code(), $groups->get_error_message() );
			return array();
		}

		$group_slugs = wp_list_pluck( $groups, 'urlname' );
		$groups      = array_combine( $group_slugs, $groups );

		/**
		 * @todo This should probably be converted into a foreach loop that runs the `get_group_events` method
		 *       separately for each group. That way we can modify the start/end date parameters individually for
		 *       the case where the group had events before it joined the chapter program and some number of those
		 *       are included within the report date range. (See Known Issues in the report methodology).
		 */
		$events = $meetup->get_events( $group_slugs, array(
			'status' => 'upcoming,past',
			'no_earlier_than' => $this->get_timezoneless_iso8601_format( $this->range->start ),
			'no_later_than'   => $this->get_timezoneless_iso8601_format( $this->range->end ),
		) );

		$data = [];

		$relevant_keys = $this->public_data_fields;

		foreach ( $events as $event ) {
			$group_slug = $event['group']['urlname'];
			$event      = wp_parse_args( $event, $relevant_keys );

			$event['description']  		= isset( $event['description'] ) ? trim( $event['description'] ) : '';
			$event['time']         		= absint( $event['time'] ) / 1000; // Convert to seconds.
			$event['group']        		= isset( $event['group']['name'] ) ? $event['group']['name'] : $groups[ $group_slug ]['name'];
			$event['city']         		= isset( $event['venue']['city'] ) ? $event['venue']['city'] : $groups[ $group_slug ]['city'];
			$event['l10n_country'] 		= isset( $event['venue']['localized_country_name'] ) ? $event['venue']['localized_country_name'] : $groups[ $group_slug ]['country'];
			$event['latitude']     		= ! empty( $event['venue']['lat'] ) ? $event['venue']['lat'] : $groups[ $group_slug ]['lat'];
			$event['longitude']    		= ! empty( $event['venue']['lon'] ) ? $event['venue']['lon'] : $groups[ $group_slug ]['lon'];
			$event['yes_rsvp_count']	= ! empty( $event['yes_rsvp_count'] ) ? $event['yes_rsvp_count'] : 0;

			$data[] = array_intersect_key( $event, $relevant_keys );
		}

		$data = $this->filter_data_fields( $data );
		$data = $this->filter_data_rows( $data );
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
		$compiled_data = [
			'total_rsvp'							=> 0,
			'total_rsvp_by_country'   => [],
			'total_rsvp_by_group'     => [],
			'monthly_rsvp'            => [],
			'monthly_rsvp_by_country' => [],
			'monthly_rsvp_by_group'   => [],
		];

		foreach( $data as $item ) {
			$compiled_data['total_rsvp'] += intval( $item['yes_rsvp_count'] );
		}

		try {
			$compiled_data['monthly_rsvp'] = $this->count_rsvp_by_month( $data );
		} catch ( Exception $e ) {
			$this->error->add(
				self::$slug . '-compilation-error',
				$e->getMessage()
			);

			return $compiled_data;
		}

		try {
			$events_by_country = $this->sort_events_by_field( 'l10n_country', $data );
		} catch ( Exception $e ) {
			$this->error->add(
				self::$slug . '-compilation-error',
				$e->getMessage()
			);

			return $compiled_data;
		}

		uasort( $events_by_country, function( $a, $b ) {
			$count_a = count( $a );
			$count_b = count( $b );

			if ( $count_a === $count_b ) {
				return 0;
			}

			return ( $count_a < $count_b ) ? 1 : -1;
		} );

		foreach ( $events_by_country as $country => $events ) {
			$event_rsvp = 0;
			foreach ( $events as $event ) {
				$event_rsvp += intval( $event['yes_rsvp_count'] );
			}

			if ( isset( $compiled_data['total_rsvp_by_country'][ $country ] ) ) {
				$compiled_data['total_rsvp_by_country'][ $country ] += $event_rsvp;
			} else {
				$compiled_data['total_rsvp_by_country'][ $country ] = $event_rsvp;
			}

			try {
				$compiled_data['monthly_rsvp_by_country'][ $country ] = $this->count_rsvp_by_month( $events );
			} catch ( Exception $e ) {
				$this->error->add(
					self::$slug . '-compilation-error',
					$e->getMessage()
				);

				return $compiled_data;
			}
		}

		try {
			$events_by_group = $this->sort_events_by_field( 'group', $data );
		} catch ( Exception $e ) {
			$this->error->add(
				self::$slug . '-compilation-error',
				$e->getMessage()
			);

			return $compiled_data;
		}

		uasort( $events_by_group, function( $a, $b ) {
			$count_a = count( $a );
			$count_b = count( $b );

			if ( $count_a === $count_b ) {
				return 0;
			}

			return ( $count_a < $count_b ) ? 1 : -1;
		} );

		foreach ( $events_by_group as $group => $events ) {
			$event_rsvp = 0;
			foreach ( $events as $event ) {
				$event_rsvp += intval( $event['yes_rsvp_count'] );
			}

			if ( isset( $compiled_data['total_rsvp_by_group'][ $group ] ) ) {
				$compiled_data['total_rsvp_by_group'][ $group ] += $event_rsvp;
			} else {
				$compiled_data['total_rsvp_by_group'][ $group ] = $event_rsvp;
			}

			try {
				$compiled_data['monthly_rsvp_by_group'][ $group ] = $this->count_rsvp_by_month( $events );
			} catch ( Exception $e ) {
				$this->error->add(
					self::$slug . '-compilation-error',
					$e->getMessage()
				);

				return $compiled_data;
			}
		}

		return $compiled_data;
	}

	/**
	 * Format a date into a valid ISO 8601 string, and then strip off the timezone.
	 *
	 * This is the required format for Meetup's v3 events endpoint.
	 *
	 * @param DateTimeInterface $date
	 *
	 * @return bool|string
	 */
	protected function get_timezoneless_iso8601_format( DateTimeInterface $date ) {
		$real_iso8601 = $date->format( 'c' );

		return substr( $real_iso8601, 0, strpos( $real_iso8601, '+' ) );
	}

	/**
	 * Sort the events by the given field.
	 *
	 * @param string $field
	 * @param array  $data
	 *
	 * @return array
	 * @throws Exception
	 */
	protected function sort_events_by_field( $field, array $data ) {
		if ( empty( $data ) ) {
			return $data;
		}

		if ( ! array_key_exists( $field, $data[0] ) ) {
			throw new Exception( sprintf(
				'Cannot sort events by %s.',
				esc_html( $field )
			) );
		}

		return array_reduce( $data, function( $carry, $item ) use ( $field ) {
			$group = $item[ $field ];

			if ( ! isset( $carry[ $group ] ) ) {
				$carry[ $group ] = [];
			}

			$carry[ $group ][] = $item;

			return $carry;
		}, [] );
	}

	/**
	 * Count how many events were in each month in the date range.
	 *
	 * @param array $events
	 *
	 * @return array
	 * @throws Exception
	 */
	protected function count_rsvp_by_month( array $events ) {
		$month_iterator = new DateTime( $this->range->start->format( 'Y-m' ) . '-01' );
		$end_month      = new DateTime( $this->range->end->format( 'Y-m' ) . '-01' );
		$interval       = new DateInterval( 'P1M' );
		$months         = [];

		while ( $month_iterator <= $end_month ) {
			$months[ $month_iterator->format( 'M Y' ) ] = 0;
			$month_iterator->add( $interval );
		}

		if ( count( $months ) < 2 ) {
			return [];
		}

		foreach ( $events as $event ) {
			$event_date = new DateTime();
			$event_date->setTimestamp( $event['time'] );

			$event_month = $event_date->format( 'M Y' );

			$months[ $event_month ] += intval( $event['yes_rsvp_count'] );
		}

		return $months;
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

		include get_views_dir_path() . 'html/meetup-attendees.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$start_date = filter_input( INPUT_POST, 'start-date' );
		$end_date   = filter_input( INPUT_POST, 'end-date' );
		$search_query = sanitize_text_field( filter_input( INPUT_POST, 'search-query' ) );
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
				'max_interval'   => new DateInterval( 'P1Y' ),
				'search_query'   => $search_query,
				'search_fields'  => self::get_search_fields(),
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $options );
		}

		include get_views_dir_path() . 'report/meetup-attendees.php';
	}

	/**
	 * Get a list of fields that will be checked during search queries.
	 *
	 * @return array
	 */
	protected static function get_search_fields() {
		return array( 'name', 'description' );
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$start_date = filter_input( INPUT_POST, 'start-date' );
		$end_date   = filter_input( INPUT_POST, 'end-date' );
		$search_query = sanitize_text_field( filter_input( INPUT_POST, 'search-query' ) );
		$refresh    = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action     = filter_input( INPUT_POST, 'action' );
		$nonce      = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Export CSV' !== $action ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'run-report' ) || ! current_user_can( 'manage_network' ) ) {
			return;
		}

		$options = array(
			'earliest_start' => new DateTime( '2015-01-01' ), // Chapter program started in 2015.
			'max_interval'   => new DateInterval( 'P1Y' ),
			'search_query'   => $search_query,
			'search_fields'  => self::get_search_fields(),
		);

		if ( $refresh ) {
			$options['flush_cache'] = true;
		}

		$report = new self( $start_date, $end_date, $options );

		$filename   = array( $report::$name );
		$filename[] = $report->range->start->format( 'Y-m-d' );
		$filename[] = $report->range->end->format( 'Y-m-d' );

		$headers = [ 'Event ID', 'Event URL', 'Event Name', 'Description', 'Date', 'Event Status', 'Group Name', 'City', 'Country (localized)', 'Latitude', 'Longitude', 'RSVP Count' ];

		$data = $report->get_data();

		array_walk( $data, function( &$event ) {
			$date = new DateTime();
			$date->setTimestamp( $event['time'] );
			$event['time'] = $date->format( 'Y-m-d' );
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
	}
}

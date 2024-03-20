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
use const WordCamp\Reports\CAPABILITY;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\validate_date_range;
use function WordCamp\Reports\Time\{year_array, quarter_array, month_array, convert_time_period_to_date_range};
use WordPressdotorg\MU_Plugins\Utilities\{ Meetup_Client, Export_CSV };

/**
 * Class Meetup_Events
 *
 * @package WordCamp\Reports\Report
 */
class Meetup_Events extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Meetup Events';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'meetup-events';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Details on meetup events during a given time period.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		Retrieve data about events in the Chapter program from the Meetup.com API.
		
		Known issues:
		
		<ul>
			<li>This will not include events for groups that were in the chapter program within the given date range, but no longer are.</li>
			<li><s>This will include a group\'s events within the date range that occurred before the group joined the chapter program.</s></li>
			<li><s>Note that this requires one or more requests to the API for every group in the Chapter program, so running this report may literally take 5-10 minutes.</s></li>
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
	protected $public_data_fields = array(
		'id'           => '',
		'link'         => '',
		'name'         => '',
		'description'  => '',
		'time'         => 0,
		'status'       => '',
		'group'        => '',
		'city'         => '',
		'l10n_country' => '',
		'latitude'     => 0,
		'longitude'    => 0,
	);

	/**
	 * Meetup_Events constructor.
	 *
	 * @param string $start_date       The start of the date range for the report.
	 * @param string $end_date         The end of the date range for the report.
	 * @param array  $options          {
	 *     Optional. Additional report parameters.
	 *     See Base::__construct and the functions in WordCamp\Reports\Validation for additional parameters.
	 * }
	 */
	public function __construct( $start_date, $end_date, array $options = array() ) {
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
		$cache_key_segments = array(
			parent::get_cache_key(),
			$this->range->generate_cache_key_segment(),
			$this->options['search_query'],
		);

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

		/*
		 * How we're querying.
		 * Look for Network Events between the reporting dates
		 * Include the Group Details & Projoin date within
		 * Exclude events where the date is before they joined the network
		 * Exclude cancelled events, as ProNetworkEventsFilter only lets us specifically include a singular status.
		 */

		// Filter options: https://www.meetup.com/api/schema/#ProNetworkEventsFilter.
		$query = '
			query ( $cursor: String ) {
	            proNetworkByUrlname( urlname: "WordPress" ) {
					eventsSearch(
						input: { first: 200, after: $cursor },
						filter: {
							eventDateMin: "' . esc_attr( $this->range->start->format('c') ) . '",
							eventDateMax: "' . esc_attr( $this->range->end->format('c') ) . '"
						}
					) {
						count
						' . $meetup->pagination . '
						edges {
							node {
								id
								eventUrl
								title
								description
								dateTime
								status
								timeStatus
								isOnline
								group { proJoinDate name city country latitude longitude }
								venue { city country lat lng }
							}
						}
					}
				}
			}
		';

		// Fetch results.
		$results = $meetup->send_paginated_request( $query, array( 'cursor' => null ) );
		if ( is_wp_error( $results ) ) {
			$this->error->merge_from( $results );
			return array();
		}

		$events = array_column( $results['proNetworkByUrlname']['eventsSearch']['edges'], 'node' );

		$data = array();
		foreach ( $events as $event ) {
			$pro_join_date = $meetup->datetime_to_time( $event['group']['proJoinDate'] );
			$event_time    = $meetup->datetime_to_time( $event['dateTime'] );

			// Exclude events that happened before a meetup joined the chapter.
			if ( $event_time < $pro_join_date ) {
				continue;
			}

			// Exclude cancelled events.
			if ( 'cancelled' === strtolower( $event['status'] ) ) {
				continue;
			}

			$data[] = array(
				'id'           => $event['id'],
				'link'         => $event['eventUrl'],
				'name'         => $event['title'],
				'description'  => $event['description'],
				'time'         => $event_time,
				'status'       => $event['timeStatus'],
				'group'        => $event['group']['name'],
				'city'         => ! empty( $event['venue']['city'] ) ? $event['venue']['city'] : $event['group']['city'],
				'l10n_country' => $meetup->localised_country_name( ! empty( $event['venue']['country'] ) ? $event['venue']['country'] : $event['group']['country'] ),
				'latitude'     => ( ! $event['isOnline'] && ! empty( $event['venue']['lat'] ) ) ? $event['venue']['lat'] : $event['group']['latitude'],
				'longitude'    => ( ! $event['isOnline'] && ! empty( $event['venue']['lng'] ) ) ? $event['venue']['lng'] : $event['group']['longitude'],
			);
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
		$compiled_data = array(
			'total_events'              => count( $data ),
			'total_events_by_country'   => array(),
			'total_events_by_group'     => array(),
			'monthly_events'            => array(),
			'monthly_events_by_country' => array(),
			'monthly_events_by_group'   => array(),
		);

		try {
			$compiled_data['monthly_events'] = $this->count_events_by_month( $data );
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
			$compiled_data['total_events_by_country'][ $country ] = count( $events );

			try {
				$compiled_data['monthly_events_by_country'][ $country ] = $this->count_events_by_month( $events );
			} catch ( Exception $e ) {
				$this->error->add(
					self::$slug . '-compilation-error',
					$e->getMessage()
				);

				return $compiled_data;
			}
		}

		$compiled_data['countries_with_events'] = count( $compiled_data['total_events_by_country'] );

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
			$compiled_data['total_events_by_group'][ $group ] = count( $events );

			try {
				$compiled_data['monthly_events_by_group'][ $group ] = $this->count_events_by_month( $events );
			} catch ( Exception $e ) {
				$this->error->add(
					self::$slug . '-compilation-error',
					$e->getMessage()
				);

				return $compiled_data;
			}
		}

		$compiled_data['groups_with_events'] = count( $compiled_data['total_events_by_group'] );

		$meetup                        = new Meetup_Client();
		$compiled_data['total_groups'] = absint( $meetup->get_result_count( 'pro/wordpress/groups', array(
			// Don't include groups that joined the chapter program later than the date range.
			'pro_join_date_max' => $this->range->end,
		) ) );

		$compiled_data['groups_with_no_events'] = $compiled_data['total_groups'] - $compiled_data['groups_with_events'];

		return $compiled_data;
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

		return array_reduce(
			$data,
			function( $carry, $item ) use ( $field ) {
				$group = $item[ $field ];

				if ( ! isset( $carry[ $group ] ) ) {
					$carry[ $group ] = array();
				}

				$carry[ $group ][] = $item;

				return $carry;
			},
			array()
		);
	}

	/**
	 * Count how many events were in each month in the date range.
	 *
	 * @param array $events
	 *
	 * @return array
	 * @throws Exception
	 */
	protected function count_events_by_month( array $events ) {
		$month_iterator = new DateTime( $this->range->start->format( 'Y-m' ) . '-01' );
		$end_month      = new DateTime( $this->range->end->format( 'Y-m' ) . '-01' );
		$interval       = new DateInterval( 'P1M' );
		$months         = array();

		while ( $month_iterator <= $end_month ) {
			$months[ $month_iterator->format( 'M Y' ) ] = 0;
			$month_iterator->add( $interval );
		}

		if ( count( $months ) < 2 ) {
			return array();
		}

		foreach ( $events as $event ) {
			$event_date = new DateTime();
			$event_date->setTimestamp( $event['time'] );

			$event_month = $event_date->format( 'M Y' );

			$months[ $event_month ] ++;
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

		include get_views_dir_path() . 'html/meetup-events.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$start_date   = filter_input( INPUT_POST, 'start-date' );
		$end_date     = filter_input( INPUT_POST, 'end-date' );
		$search_query = sanitize_text_field( filter_input( INPUT_POST, 'search-query' ) );
		$refresh      = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action       = filter_input( INPUT_POST, 'action' );
		$nonce        = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Show results' === $action
			&& wp_verify_nonce( $nonce, 'run-report' )
			&& current_user_can( CAPABILITY )
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

		include get_views_dir_path() . 'report/meetup-events.php';
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
		$start_date   = filter_input( INPUT_POST, 'start-date' );
		$end_date     = filter_input( INPUT_POST, 'end-date' );
		$search_query = sanitize_text_field( filter_input( INPUT_POST, 'search-query' ) );
		$refresh      = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$action       = filter_input( INPUT_POST, 'action' );
		$nonce        = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Export CSV' !== $action ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'run-report' ) || ! current_user_can( CAPABILITY ) ) {
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

		$headers = array( 'Event ID', 'Event URL', 'Event Name', 'Description', 'Date', 'Event Status', 'Group Name', 'City', 'Country (localized)', 'Latitude', 'Longitude' );

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
				'max_interval'   => new DateInterval( 'P1Y' ),
			);

			$report = new self( $range->start ?? false, $range->end ?? false, $options );

			if ( ! is_null( $error ) ) {
				$report->merge_errors( $report->error, $error );
			}
		}

		include get_views_dir_path() . 'public/meetup-events.php';
	}
}

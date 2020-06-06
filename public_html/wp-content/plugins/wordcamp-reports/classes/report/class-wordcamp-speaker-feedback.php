<?php

namespace WordCamp\Reports\Report;

use DateInterval, DateTime, Exception;
use WordCamp_Loader;
use WordCamp\Utilities\Export_CSV;
use WordCamp\Reports\Utility\Date_Range;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\{ validate_date_range, validate_wordcamp_id };
use function WordCamp\SpeakerFeedback\Stats\{ should_generate_stats, gather_data, generate_stats, stat_keys };

defined( 'WPINC' ) || die();


class WordCamp_Speaker_Feedback extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'WordCamp Speaker Feedback';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'wordcamp-speaker-feedback';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Statistics from the Speaker Feedback Tool.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = "
		<ol>
			<li>TBD</li>
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
	 * WordCamp_Speaker_Feedback constructor.
	 *
	 * @param string $start_date  The start of the date range for the report.
	 * @param string $end_date    The end of the date range for the report.
	 * @param int    $wordcamp_id Optional. The ID of a WordCamp post to limit this report to.
	 * @param array  $options
	 *     Optional. Additional report parameters.
	 *     See Base::__construct and Date_Range::__construct for additional parameters.
	 */
	public function __construct( $start_date, $end_date, $wordcamp_id = 0, array $options = array() ) {
		$this->load_sft_dependencies();

		parent::__construct( $options );

		try {
			$this->range = validate_date_range( $start_date, $end_date, $options );
		} catch ( Exception $e ) {
			$this->error->add(
				self::$slug . '-date-error',
				$e->getMessage()
			);
		}

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
		$cache_key_segments = array(
			parent::get_cache_key(),
			$this->range->generate_cache_key_segment(),
		);

		if ( $this->wordcamp_id ) {
			$cache_key_segments[] = $this->wordcamp_id;
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

		// This script is a bit of a memory hog.
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted

		$data      = array();
		$wordcamps = $this->get_wordcamps();

		foreach ( $wordcamps as $wordcamp ) {
			$blog_id = get_wordcamp_site_id( $wordcamp );
			if ( ! $blog_id ) {
				continue;
			}

			$event_data = array(
				'id'         => $wordcamp->ID,
				'name'       => get_wordcamp_name( $blog_id ),
				'url'        => get_post_meta( $wordcamp->ID, 'URL', true ),
				'start_date' => get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true ),
			);

			switch_to_blog( $blog_id );

			if ( should_generate_stats() ) {
				$blog_data = gather_data();
				$stats     = generate_stats( $blog_data );

				$data[] = array_merge( $event_data, $stats );
			}

			restore_current_blog();
		}

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
		return $data;
	}

	/**
	 * Load the necessary files from the SFT plugin, since it's not activated on Central.
	 *
	 * @return void
	 */
	protected function load_sft_dependencies() {
		$path = WP_PLUGIN_DIR . '/wordcamp-speaker-feedback/includes/';
		require_once $path . 'class-feedback.php';
		require_once $path . 'comment.php';
		require_once $path . 'post.php';
		require_once $path . 'view.php';
		require_once $path . 'spam.php';
		require_once $path . 'stats.php';
	}

	/**
	 * Get data from a WordCamp Details report and filter it.
	 *
	 * @return array|null
	 */
	protected function get_wordcamps() {
		$post_args = array(
			'post_type'           => WCPT_POST_TYPE_ID,
			'post_status'         => WordCamp_Loader::get_public_post_statuses(),
			'posts_per_page'      => 9999,
			'nopaging'            => true,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
			'orderby'             => 'id',
			'order'               => 'ASC',
		);

		if ( $this->range instanceof Date_Range ) {
			// This replaces the default meta query.
			$post_args['meta_query'] = array(
				array(
					'key'     => 'Start Date (YYYY-mm-dd)',
					'value'   => array( $this->range->start->getTimestamp(), $this->range->end->getTimestamp() ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				),
			);
			$post_args['orderby'] = 'meta_value_num title';
		}

		if ( $this->wordcamp_id ) {
			$post_args['post__in'] = $this->wordcamp_id;
		}

		return get_posts( $post_args );
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

		include get_views_dir_path() . 'report/wordcamp-speaker-feedback.php';
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

		if ( wp_verify_nonce( $nonce, 'run-report' ) && current_user_can( 'manage_network' ) ) {
			$options = array(
				'public'         => false,
				'earliest_start' => new DateTime( '2020-01-01' ), // Speaker Feedback Tool was introduced in 2020.
				'max_interval'   => new DateInterval( 'P1Y1M' ),
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $start_date, $end_date, $wordcamp_id, $options );

			$filename = array( $report::$name );
			if ( $report->wordcamp_site_id ) {
				$filename[] = get_wordcamp_name( $report->wordcamp_site_id );
			}
			$filename[] = $report->range->start->format( 'Y-m-d' );
			$filename[] = $report->range->end->format( 'Y-m-d' );

			$data = $report->get_data();

			array_walk(
				$data,
				function( &$row ) {
					foreach ( $row as $key => $value ) {
						if ( 'start_date' === $key ) {
							$row[ $key ] = wp_date( 'Y-m-d', $value );
						}

						if ( is_array( $value ) ) {
							$row[ $key ] = print_r( $value, true );
						}
					}
				}
			);

			$headers = array_merge(
				array(
					'id',
					'name',
					'url',
					'start_date',
				),
				stat_keys(),
				array(
					'error',
				)
			);

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
}

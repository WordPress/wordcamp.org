<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use Exception;
use DateTime;
use WP_Post, WP_Query, WP_Error;
use const WordCamp\Reports\CAPABILITY;
use function WordCamp\Reports\{get_views_dir_path};
use WordCamp\Reports\Utility\Date_Range;
use function WordCamp\Reports\Validation\{validate_date_range, validate_wordcamp_id};
use WordCamp_Admin, WordCamp_Loader;

/**
 * Class CampusConnect_Details
 *
 * A report class for exporting a spreadsheet of CampusConnect events.
 *
 * Note that this report does not use caching because it is only used in WP Admin and has a large number of
 * optional parameters.
 *
 * Note that this extends the WordCamp Details report for re-use, since it's very similar.
 *
 * @package WordCamp\Reports\Report
 */
class CampusConnect_Details extends WordCamp_Details {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Campus Connect Details';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'campus-connect-details';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Create a spreadsheet of details about Campus Connect events that match optional criteria.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		<ol>
			<li>Retrieve WordCamp posts that fit within the criteria.</li>
			<li>Extract the data for each post that match the fields requested.</li>
			<li>Walk through all of the extracted data and format it for display.</li>
		</ol>
	';

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'campus-connect';

	/**
	 * REST API base for this report.
	 *
	 * Registered by `register_rest_endpoints()` in the plugin bootstrap, which
	 * only exposes a route for classes that declare both `$rest_base` and
	 * `rest_callback()`.
	 *
	 * @var string
	 */
	public static $rest_base = 'campus-connect-details';

	/**
	 * Get the full list of fields in the order they should appear in.
	 *
	 * @return array
	 */
	public static function get_field_order() {
		return array_merge(
			array(
				'Start Date (YYYY-mm-dd)',
				'End Date (YYYY-mm-dd)',
				'Status',
				'Name',
				'Organizer Name',
				'Venue Name',
				'_venue_city',
				'_venue_country_name',
				'Number of Anticipated Attendees',
				'Actual Attendees',
				'Series Event',
				'Created',
				'Tracker URL',
				'URL',
				'ID',
			),
			parent::get_field_order()
		);
	}

	/**
	 * Format the data for human-readable display.
	 *
	 * @param array $data The data to prepare.
	 *
	 * @return array
	 */
	public function prepare_data_for_display( array $data ) {
		$data = parent::prepare_data_for_display( $data );

		$rename = array(
			'Venue Name'         => 'Institution Name',
			'_venue_city'         => 'City',
			'_venue_country_name' => 'Country',
		);

		array_walk( $data, function( &$row ) use ( $rename ) {
			$new_row = [];
			foreach ( $row as $key => $value ) {
				switch ( $key ) {
					case 'Status':
						$value = trim( str_replace( 'WordCamp', '', $value ) );
						break;
				}

				// Rename some columns.
				$key = $rename[ $key ] ?? $key;

				$new_row[ $key ] = $value;
			}

			$row = $new_row;
		} );

		return $data;
	}

	/**
	 * Fill in missing City/Country data from the Location field.
	 *
	 * @param WP_Post $event The event post object.
	 *
	 * @return array The data row.
	 */
	public function fill_data_row( $row ) {
		$row = parent::fill_data_row( $row );

		// If the venue address isn't set, Extract the details from the Location.
		if ( empty( $row['_venue_city'] ) || empty( $row['_venue_country_name'] ) ) {
			list( $city, $country ) = explode( ',', $row['Location'], 2 ) + array( '', '' );

			$row['_venue_city']         = $row['_venue_city'] ?: trim( $city );
			$row['_venue_country_name'] = $row['_venue_country_name'] ?: trim( $country );
		}

		return $row;
	}

	/**
	 * Get WordCamp posts that fit the report criteria.
	 *
	 * @return array An array of WP_Post objects.
	 */
	public function get_event_posts() {
		$post_args = array(
			'post_type'           => WCPT_POST_TYPE_ID,
			'post_status'         => 'any',
			'posts_per_page'      => 9999,
			'nopaging'            => true,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
			'orderby'             => 'id',
			'order'               => 'ASC',
			'meta_query'          => array(
				array(
					'key'     => 'event_subtype',
					'value'   => 'campusconnect',
					'compare' => '=',
				),
			),
		);

		if ( $this->range instanceof Date_Range ) {
			$post_args['meta_query'][] = array(
				'key'      => 'Start Date (YYYY-mm-dd)',
				'value'    => array( $this->range->start->getTimestamp(), $this->range->end->getTimestamp() ),
				'compare'  => 'BETWEEN',
				'type'     => 'NUMERIC',
			);
			$post_args['orderby']      = 'meta_value_num title';
		}

		if ( ! empty( $this->event_ids ) ) {
			$post_args['post__in'] = $this->event_ids;
		}

		if ( $this->options['public'] ) {
			$post_args['post_status'] = WordCamp_Loader::get_public_post_statuses();
		}

		return get_posts( $post_args );
	}

	/**
	 * Restrict the REST endpoint to users who may view reports.
	 *
	 * This report intentionally queries every post status, not just the public
	 * ones, and exposes private post meta such as `Actual Attendees`. It must
	 * therefore never be readable anonymously.
	 *
	 * @return bool
	 */
	public static function rest_permission_callback() {
		return current_user_can( CAPABILITY );
	}

	/**
	 * The fields the REST endpoint asks for.
	 *
	 * An upper bound, not a promise. The safelist a caller actually receives is
	 * capability-filtered at runtime -- `WordCamp_Admin::meta_keys()` drops
	 * `Series Event` unless the caller has `manage_options` -- so this list is
	 * intersected with what is available before the report is built. It is kept
	 * deliberately narrow rather than defaulting to the whole safelist, which
	 * would put organiser e-mail addresses and telephone numbers on the wire.
	 *
	 * @return array
	 */
	public static function get_rest_fields() {
		return array(
			'Start Date (YYYY-mm-dd)',
			'End Date (YYYY-mm-dd)',
			'Status',
			'Name',
			'Organizer Name',
			'Venue Name',
			'_venue_city',
			'_venue_country_name',
			'Number of Anticipated Attendees',
			'Actual Attendees',
			'Series Event',
			'Created',
			'Tracker URL',
			'URL',
			'ID',
		);
	}

	/**
	 * Handle a REST request for this report.
	 *
	 * Returns the same rows the admin screen renders, so a consumer can read
	 * the report programmatically instead of exporting the CSV by hand.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_callback( $request ) {
		/*
		 * Ask only for fields this caller can actually be given. The safelist is
		 * capability-filtered, and `validate_fields_input()` rejects the whole
		 * request if it names one field the caller cannot have -- so without this
		 * intersection a user holding exactly `view_wordcamp_reports` passes the
		 * permission check and then receives a 500 instead of their report.
		 */
		$probe  = new static( null, null, false, array( 'public' => false ) );
		$fields = array_values(
			array_intersect(
				self::get_rest_fields(),
				array_keys( $probe->get_data_fields_safelist() )
			)
		);

		$report = new static(
			null,
			null,
			false,
			array(
				'fields' => $fields,
				'public' => false,
			)
		);

		$messages = $report->error->get_error_messages();

		if ( ! empty( $messages ) ) {
			return new WP_Error(
				'wcr_campus_connect_details_failed',
				implode( ' ', $messages ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $report->prepare_data_for_display( $report->get_data() ) );
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$field_defaults = array(
			'Start Date (YYYY-mm-dd)'         => 'checked',
			'End Date (YYYY-mm-dd)'           => 'checked',
			'Status'                          => 'checked',
			'Name'                            => 'checked',
			'Organizer Name'                  => 'checked',
			'Venue Name'                      => 'checked',
			'_venue_city'                     => 'checked',
			'_venue_country_name'             => 'checked',
			'Number of Anticipated Attendees' => 'checked',
			'Tracker URL'                     => 'checked',
			'URL'                             => 'checked',
			'Actual Attendees'                => 'checked',
			'Series Event'                    => 'checked',
			'Created'                         => 'checked',
			'ID'                              => 'checked',
		);
		foreach ( $_REQUEST['fields'] ?? array() as $field ) {
			$field_defaults[ $field ] = 'checked';
		}

		$report = false;
		$input  = self::get_report_inputs();
		if (
			! empty( $input ) &&
			'Show Results' === $input['action'] &&
			wp_verify_nonce( $input['nonce'], 'run-report' ) &&
			current_user_can( CAPABILITY )
		) {
			$options = array(
				'fields' => $input['fields'] ?? [],
				'public' => false,
			);

			$report = new static( $input['range'], null, $input['include_counts'], $options );
		}

		$start_date = $input['start_date'] ?? '';
		$end_date   = $input['end_date']   ?? '';

		include get_views_dir_path() . 'report/campusconnect-details.php';
	}
}

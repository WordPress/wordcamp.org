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

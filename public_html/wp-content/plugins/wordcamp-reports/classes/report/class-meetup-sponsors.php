<?php
/**
 * Meetup Sponsors.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;
defined( 'WPINC' ) || die();

use Exception;
use WP_Error;
use const WordCamp\Reports\CAPABILITY;
use function WordCamp\Reports\get_views_dir_path;
use WordPressdotorg\MU_Plugins\Utilities\{ Meetup_Client, Export_CSV };

/**
 * Class Meetup_Sponsors
 *
 * @package WordCamp\Reports\Report
 */
class Meetup_Sponsors extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Meetup Sponsors';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'meetup-sponsors';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Details on meetup sponsors';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		Retrieve data about groups in the Chapter program from the Meetup.com API, list their sponsors.
	';

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'meetup';

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

		$sponsor_filter = ( $this->options['include-network'] ?? false ) ? '' : 'type: GROUP';

		$query = '
			query ( $cursor: String ) {
				proNetwork( urlname: "WordPress" ) {
					groupsSearch (
						input: {
							first: 200,
							after: $cursor,
						}
					) {
						' . $meetup->pagination . '
						edges {
							node {
								name
								link
								city
								country
								sponsors ( first: 999, filter: { ' . $sponsor_filter . '} ) {
									edges {
										node {
											id
											name
											url
											description
											logoPhoto {
												standardUrl
											}
										}
									}
								}
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

		$groups = array_column( $results['proNetwork']['groupsSearch']['edges'], 'node' );

		$data = array();
		foreach ( $groups as $group ) {
			if ( empty( $group['sponsors']['edges'] ) ) {
				continue;
			}

			$data[] = [
				'group_name' => $group['name'],
				'group_url'  => $group['link'],
				'city'       => $group['city'],
				'country'    => $meetup->localised_country_name( $group['country'] ),
				'sponsors'  => array_map(
					function ( $sponsor_edge ) {
						$sponsor = $sponsor_edge['node'];
						return [
							'id'          => $sponsor['id'],
							'name'        => $sponsor['name'],
							'url'         => $sponsor['url'],
							'description' => $sponsor['description'],
							'logo_url'    => $sponsor['logoPhoto']['standardUrl'] ?? '',
						];
					},
					$group['sponsors']['edges']
				),
			];
		}

		$this->maybe_cache_data( $data );

		return $data;
	}

	/**
	 * Compile the report data into results.
	 *
	 * @param array $data The data to compile.
	 * @param bool  $with_html Whether to include HTML in the output.
	 *
	 * @return array
	 */
	public function compile_report_data( array $data, $with_html = false ) {
		$compiled_data = array();

		foreach ( $data as $row ) {
			$compiled_data[] = [
				'title'    => $row['group_name'],
				'URL'      => $row['group_url'],
				'Location' => trim( $row['city'] . ', ' . $row['country'], ', ' ),
				'data'     => array_map(
					function( $sponsor ) use ( $with_html ) {
						if ( ! $with_html ) {
							return [
								'Name'        => $sponsor['name'],
								'Description' => $sponsor['description'],
								'URL'         => $sponsor['url'],
								'Logo'        => $sponsor['logo_url'],
							];
						}

						return [
							'Name'        => sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $sponsor['url'] ), esc_html( $sponsor['name'] ) ),
							'Description' => esc_html( $sponsor['description'] ),
							'URL'         => sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $sponsor['url'] ), esc_html( $sponsor['url'] ) ),
							'Logo'        => $sponsor['logo_url'] ? sprintf( '<img src="%s" alt="%s" style="max-height:50px;max-width:150px;" />', esc_url( $sponsor['logo_url'] ), esc_attr( $sponsor['name'] ) ) : '',
						];
					},
					$row['sponsors']
				),
			];
		}

		return $compiled_data;
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

		$groups = $this->compile_report_data( $this->get_data(), $html = true );

		$safe_html = [
			'p' => [],
			'img' => [
				'src'    => true,
				'alt'    => true,
				'style'  => true,
			],
			'a' => [
				'href'   => true,
				'rel'    => true,
				'target' => true,
			],
		];

		include get_views_dir_path() . 'html/grouped-data-table.php';
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		$refresh      = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$plus_network = filter_input( INPUT_POST, 'include-network', FILTER_VALIDATE_BOOLEAN );
		$action       = filter_input( INPUT_POST, 'action' );
		$nonce        = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Show results' === $action
			&& wp_verify_nonce( $nonce, 'run-report' )
			&& current_user_can( CAPABILITY )
		) {
			$options = array(
				'include-network' => (bool) $plus_network,
			);

			if ( $refresh ) {
				$options['flush_cache'] = true;
			}

			$report = new self( $options );
		}

		$field_defaults = [];

		include get_views_dir_path() . 'report/meetup-sponsors.php';
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$refresh      = filter_input( INPUT_POST, 'refresh', FILTER_VALIDATE_BOOLEAN );
		$plus_network = filter_input( INPUT_POST, 'include-network', FILTER_VALIDATE_BOOLEAN );
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
			'include-network' => (bool) $plus_network,
		);

		if ( $refresh ) {
			$options['flush_cache'] = true;
		}

		$report = new self( $options );

		$filename = array( $report::$name );

		$compiled_data = $report->compile_report_data( $report->get_data(), $html = false );

		// Flatten the data for CSV export.
		$data = array();
		foreach ( $compiled_data as $group ) {
			foreach ( $group['data'] as $sponsor ) {
				$data[] = array(
					'Group Name'          => $group['title'],
					'Group URL'           => $group['URL'],
					'Location'            => $group['Location'],
					'Sponsor Name'        => $sponsor['Name'],
					'Sponsor URL'         => $sponsor['URL'],
					'Sponsor Description' => $sponsor['Description'],
					'Sponsor Logo URL'    => $sponsor['Logo'],
				);
			}
		}
		$headers = array_keys( reset( $data ) );

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
	 * Get the cache key for the report.
	 *
	 * @return string
	 */
	protected function get_cache_key() {
		return parent::get_cache_key() . '_' . md5( serialize( $this->options ) );
	}
}

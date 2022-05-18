<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Report;

use Exception;
use DateTime;
use WordPressdotorg\MU_Plugins\Utilities\Export_CSV;
use function WordCamp\Reports\get_views_dir_path;
use function WordCamp\Reports\Validation\validate_wordcamp_id;

defined( 'WPINC' ) || die();


class Sponsor_Details extends Base {
	/**
	 * Report name.
	 *
	 * @var string
	 */
	public static $name = 'Sponsor Details';

	/**
	 * Report slug.
	 *
	 * @var string
	 */
	public static $slug = 'sponsor-details';

	/**
	 * Report description.
	 *
	 * @var string
	 */
	public static $description = 'Create a spreadsheet of details about sponsors for a particular WordCamp.';

	/**
	 * Report methodology.
	 *
	 * @var string
	 */
	public static $methodology = '
		<ol>
			<li>Retrieve all of the sponsor posts for the specified WordCamp site.</li>
			<li>Retrieve all of the invoice posts for the specified WordCamp site.</li>
			<li>Combine sponsor and invoice data and export as a CSV file.</li>
		</ol>
	';

	/**
	 * Report group.
	 *
	 * @var string
	 */
	public static $group = 'wordcamp';

	/**
	 * The ID of the WordCamp post.
	 *
	 * @var int
	 */
	public $wordcamp_id = 0;

	/**
	 * Sponsor_Details constructor.
	 *
	 * @param int   $wordcamp_id The ID of the WordCamp post to retrieve sponsor details for.
	 * @param array $options     {
	 *     Optional. Additional report parameters.
	 *     See Base::__construct and the functions in WordCamp\Reports\Validation for additional parameters.
	 *
	 *     @type array $status_subset A list of valid status IDs.
	 * }
	 */
	public function __construct( $wordcamp_id, array $options = array() ) {
		parent::__construct( $options );

		try {
			$this->wordcamp_id = validate_wordcamp_id( $wordcamp_id );
		} catch ( Exception $exception ) {
			$this->error->add(
				self::$slug . '-wordcamp-id-error',
				$exception->getMessage()
			);
		}
	}

	/**
	 * The display headers, and keys, for each row of data.
	 *
	 * @return array
	 */
	protected function get_data_headers() {
		return array(
			'WordCamp',
			'Start Date',
			'Sponsor Name',
			'Sponsorship Level',
			'Sponsor Status',
			'Currency',
			'Amount',
			'Invoice Status',
			'Invoice Link',
			'QBO Link',
			'Sponsor Email',
			'Sponsor Phone',
			'Sponsor Link',
			'WordCamp Link',
		);
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

		$wordcamp = array(
			'name' => get_wordcamp_name( $this->wordcamp_id->site_id ),
			'date' => get_post_meta( $this->wordcamp_id->post_id, 'Start Date (YYYY-mm-dd)', true ),
			'link' => get_edit_post_link( $this->wordcamp_id->post_id, 'csv' ),
		);

		// Register a dummy taxonomy so it will exist when we try to get its terms in the event site.
		register_taxonomy( 'wcb_sponsor_level', 'wcb_sponsor' );

		switch_to_blog( $this->wordcamp_id->site_id );

		$data = $this->get_sponsor_and_invoice_ids();

		array_walk(
			$data,
			function( &$item, $index, $wordcamp ) {
				$new_row = array_fill_keys( $this->get_data_headers(), '' );

				$new_row['WordCamp']      = esc_html( $wordcamp['name'] );
				$new_row['Start Date']    = wp_date( 'Y-m-d', $wordcamp['date'] );
				$new_row['WordCamp Link'] = $wordcamp['link'];

				$sponsor_post = get_post( $item['sponsor_id'] );
				if ( $sponsor_post ) {
					$levels = wp_get_post_terms(
						$sponsor_post->ID,
						'wcb_sponsor_level',
						array( 'fields' => 'names' )
					);

					$new_row['Sponsor Name']   = esc_html( $sponsor_post->_wcpt_sponsor_company_name );
					$new_row['Sponsor Status'] = esc_html( get_post_status( $sponsor_post ) );
					$new_row['Sponsor Email']  = sanitize_email( $sponsor_post->_wcpt_sponsor_email_address );
					$new_row['Sponsor Phone']  = esc_html( $sponsor_post->_wcpt_sponsor_phone_number );

					if ( ! empty( $levels ) ) {
						$new_row['Sponsorship Level'] = esc_html( $levels[0] );
					}

					$new_row['Sponsor Link'] = add_query_arg(
						array(
							'post'   => $sponsor_post->ID,
							'action' => 'edit',
						),
						admin_url( 'post.php' )
					);
				}

				$invoice_post = get_post( $item['invoice_id'] );
				if ( $invoice_post ) {
					$new_row['Currency']       = esc_html( $invoice_post->_wcbsi_currency );
					$new_row['Amount']         = number_format_i18n( floatval( $invoice_post->_wcbsi_amount ), 2 );
					$new_row['Invoice Status'] = esc_html( get_post_status( $invoice_post ) );
					$new_row['Invoice Link']   = add_query_arg(
						array(
							'post'   => $invoice_post->ID,
							'action' => 'edit',
						),
						admin_url( 'post.php' )
					);

					if ( $invoice_post->_wcbsi_qbo_invoice_id ) {
						$new_row['QBO Link'] = sprintf(
							'https://qbo.intuit.com/app/invoice?txnId=%d',
							absint( $invoice_post->_wcbsi_qbo_invoice_id )
						);
					}
				}

				$item = $new_row;
			},
			$wordcamp
		);

		restore_current_blog();

		// ASCII transliteration doesn't work if the LC_CTYPE is 'C' or 'POSIX'.
		// See https://www.php.net/manual/en/function.iconv.php#74101.
		$orig_locale = setlocale( LC_CTYPE, 0 );
		setlocale( LC_CTYPE, 'en_US.UTF-8' );

		// Sort the sponsor names based on ASCII transliteration without actually changing any strings.
		uasort(
			$data,
			function( $a, $b ) {
				return strcasecmp(
					iconv( mb_detect_encoding( $a['Sponsor Name'] ), 'ascii//TRANSLIT', $a['Sponsor Name'] ),
					iconv( mb_detect_encoding( $b['Sponsor Name'] ), 'ascii//TRANSLIT', $b['Sponsor Name'] )
				);
			}
		);

		setlocale( LC_CTYPE, $orig_locale );

		return $data;
	}

	/**
	 * Compile the report data into results.
	 *
	 * Not used in this report, but the method is required by the parent class.
	 *
	 * @param array $data The data to compile.
	 *
	 * @return array
	 */
	public function compile_report_data( array $data ) {
		return $data;
	}

	/**
	 * Compile and associate sponsor and invoice post IDs.
	 *
	 * @return array
	 */
	protected function get_sponsor_and_invoice_ids() {
		$data = array();

		$data_default = array(
			'wordcamp_site_id' => $this->wordcamp_id->site_id,
			'wordcamp_post_id' => $this->wordcamp_id->post_id,
			'sponsor_id'       => 0,
			'invoice_id'       => 0,
		);

		$sponsor_args  = array(
			'post_type'      => 'wcb_sponsor',
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => -1,
		);
		$sponsor_posts = get_posts( $sponsor_args );

		$invoice_args  = array(
			'post_type'      => 'wcb_sponsor_invoice',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		);
		$invoice_posts = get_posts( $invoice_args );
		$invoice_posts = array_combine( wp_list_pluck( $invoice_posts, 'ID' ), $invoice_posts );

		foreach ( $sponsor_posts as $sponsor_post ) {
			$relevant_invoices = array_filter(
				$invoice_posts,
				function( $invoice_post ) use ( $sponsor_post ) {
					return absint( $invoice_post->_wcbsi_sponsor_id ) === absint( $sponsor_post->ID );
				}
			);

			if ( $relevant_invoices ) {
				foreach ( $relevant_invoices as $relevant_invoice ) {
					$new_data = wp_parse_args(
						array(
							'sponsor_id' => $sponsor_post->ID,
							'invoice_id' => $relevant_invoice->ID,
						),
						$data_default
					);

					$data[] = $new_data;

					// Remove from invoice array so we can isolate orphan invoices.
					unset( $invoice_posts[ $relevant_invoice->ID ] );
				}
			} else {
				$data[] = wp_parse_args(
					array(
						'sponsor_id' => $sponsor_post->ID,
					),
					$data_default
				);
			}
		}

		// Check for orphan invoices.
		if ( ! empty( $invoice_posts ) ) {
			foreach ( $invoice_posts as $orphan_invoice ) {
				$new_data = wp_parse_args(
					array(
						'invoice_id' => $relevant_invoice->ID,
					),
					$data_default
				);

				$data[] = $new_data;
			}
		}

		return $data;
	}

	/**
	 * Render the page for this report in the WP Admin.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		include get_views_dir_path() . 'report/sponsor-details.php';
	}

	/**
	 * Export the report data to a file.
	 *
	 * @return void
	 */
	public static function export_to_file() {
		$wordcamp_id = filter_input( INPUT_POST, 'wordcamp-id' );
		$action      = filter_input( INPUT_POST, 'action' );
		$nonce       = filter_input( INPUT_POST, self::$slug . '-nonce' );

		$report = null;

		if ( 'Export CSV' !== $action ) {
			return;
		}

		if ( wp_verify_nonce( $nonce, 'run-report' ) && current_user_can( 'manage_network' ) ) {
			$options = array(
				'earliest_start' => new DateTime( '2015-01-01' ), // No indexed payment data before 2015.
				'public'         => false,
			);

			$report = new self( $wordcamp_id, $options );

			$filename = array( $report::$name );
			if ( $report->wordcamp_id ) {
				$filename[] = get_wordcamp_name( $report->wordcamp_id->site_id );
			}
			$filename[] = wp_date( 'Y-m-d' );

			$headers = $report->get_data_headers();

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
		}
	}
}

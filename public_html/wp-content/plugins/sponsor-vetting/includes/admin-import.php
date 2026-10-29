<?php
/**
 * The screen on Central for importing sponsors from a spreadsheet: upload, preview, import.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Adds the import screen under Sponsor Vetting.
 */
function add_import_page(): void {
	if ( ! is_central() ) {
		return;
	}

	$hook = add_submenu_page(
		'edit.php?post_type=' . COMPANY_POST_TYPE,
		__( 'Import Sponsors', 'wordcamporg' ),
		__( 'Import Sponsors', 'wordcamporg' ),
		CAPABILITY,
		'sv-import',
		__NAMESPACE__ . '\render_import_page'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\handle_import' );
	}
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_import_page' );

/**
 * The transient holding the current user's uploaded rows or import results between steps.
 *
 * @param string $step `rows` or `results`.
 * @return string
 */
function get_import_transient( string $step ): string {
	return 'sv_import_' . $step . '_' . get_current_user_id();
}

/**
 * Handles the upload and the import, then redirects to the next step.
 */
function handle_import(): void {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( CAPABILITY ) ) {
		return;
	}

	check_admin_referer( 'sv_import' );

	$page = admin_url( 'edit.php?post_type=' . COMPANY_POST_TYPE . '&page=sv-import' );

	if ( isset( $_POST['sv_upload'] ) ) {
		$file = $_FILES['sv_csv']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A temporary path PHP chose, only read.
		$rows = is_uploaded_file( $file ) ? parse_csv( $file, array( 'event_url', 'company_name', 'website' ) ) : new \WP_Error( 'sv_no_file', __( 'Choose a CSV file.', 'wordcamporg' ) );

		if ( is_wp_error( $rows ) ) {
			add_notice( 'error', $rows->get_error_message() );
			wp_safe_redirect( $page );
			exit;
		}

		set_transient( get_import_transient( 'rows' ), $rows, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'step', 'preview', $page ) );
		exit;
	}

	if ( isset( $_POST['sv_import'] ) ) {
		$rows = (array) get_transient( get_import_transient( 'rows' ) );
		delete_transient( get_import_transient( 'rows' ) );

		set_transient( get_import_transient( 'results' ), array_map( static fn( array $row ): array => import_row( $row, false ) + array( 'row' => $row ), array_filter( $rows, 'is_array' ) ), HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'step', 'done', $page ) );
		exit;
	}
}

/**
 * Renders the step the reviewer is on.
 */
function render_import_page(): void {
	$step = sanitize_key( wp_unslash( (string) ( $_GET['step'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which step to show.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import Sponsors', 'wordcamporg' ); ?></h1>

		<?php
		if ( 'preview' === $step && is_array( get_transient( get_import_transient( 'rows' ) ) ) ) {
			$rows = get_transient( get_import_transient( 'rows' ) );
			render_import_results( array_map( static fn( array $row ): array => import_row( $row, true ) + array( 'row' => $row ), $rows ) );
			?>
			<form method="post">
				<?php wp_nonce_field( 'sv_import' ); ?>
				<?php submit_button( __( 'Import', 'wordcamporg' ), 'primary', 'sv_import' ); ?>
			</form>
			<?php
		} elseif ( 'done' === $step && is_array( get_transient( get_import_transient( 'results' ) ) ) ) {
			echo '<p>' . esc_html__( 'Imported. Every row that wasn’t skipped links to its sponsorship.', 'wordcamporg' ) . '</p>';
			render_import_results( get_transient( get_import_transient( 'results' ) ) );
			delete_transient( get_import_transient( 'results' ) );
		} else {
			?>
			<p><?php esc_html_e( 'Upload a CSV of sponsors from an organizer’s spreadsheet. Sponsors the event site doesn’t have yet are added there as drafts, and each sponsor’s vetting is requested. You’ll see what will happen before anything is imported.', 'wordcamporg' ); ?></p>
			<p>
				<?php
				/* translators: %s: Column names. */
				printf( esc_html__( 'Columns: %s. The event URL is its site, like https://seattle.wordcamp.org/2026/.', 'wordcamporg' ), '<code>' . esc_html( implode( ', ', get_import_columns() ) ) . '</code>' );
				?>
			</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'sv_import' ); ?>
				<input type="file" name="sv_csv" accept=".csv,text/csv">
				<?php submit_button( __( 'Preview', 'wordcamporg' ), 'primary', 'sv_upload' ); ?>
			</form>
			<?php
		}
		?>
	</div>
	<?php
}

/**
 * Renders what happened, or would happen, to each row.
 *
 * @param array[] $results Results, as import_row() returns them, with the row under `row`.
 */
function render_import_results( array $results ): void {
	$labels = array(
		'existing' => __( 'Existing sponsor', 'wordcamporg' ),
		'new'      => __( 'New sponsor', 'wordcamporg' ),
		'error'    => __( 'Skipped', 'wordcamporg' ),
	);
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Event', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Company', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Website', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'wordcamporg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $results as $result ) : ?>
				<tr>
					<td><?php echo esc_html( $result['blog_id'] ? get_event_name( (int) $result['blog_id'] ) : (string) ( $result['row']['event_url'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $result['row']['company_name'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $result['row']['website'] ?? '' ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $labels[ $result['result'] ] ?? '' ); ?></strong>:
						<?php echo esc_html( $result['message'] ); ?>
						<?php if ( $result['sponsorship_id'] ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $result['sponsorship_id'] ) ); ?>"><?php esc_html_e( 'Sponsorship', 'wordcamporg' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

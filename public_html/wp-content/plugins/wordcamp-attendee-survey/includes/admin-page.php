<?php
/**
 * Adds a page to the central admin.
 */

namespace WordCamp\AttendeeSurvey\AdminPage;

defined( 'WPINC' ) || die();

use CampTix_Plugin;

use function WordCamp\AttendeeSurvey\{get_feature_id};
use function WordCamp\AttendeeSurvey\Email\{get_email_id};
use function WordCamp\AttendeeSurvey\Page\{get_page_id};
use function WordCamp\AttendeeSurvey\Cron\{get_wordcamp_attendees_id};

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registered the appropriate filters and actions.
 */
function init() {
	add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu' );
}

/**
 * Add a menu item.
 */
function admin_menu() {
	add_menu_page(
		__( 'WordCamp Attendee Survey', 'wordcamporg' ),
		__( 'Attendee Survey', 'wordcamporg' ),
		'manage_options',
		get_feature_id(),
		__NAMESPACE__ . '\render_menu_page',
		'dashicons-feedback',
		58
	);
}

/**
 * Get stats for each feedback for all sites.
 *
 * @return array[] An array of custom elements.
 *                Each element has the following structure:
 *                [
 *                  'title' => string,
 *                  'sent' => string,
 *                  'responses' => string,
 *                  'rate' => string,
 *                ]
 */
function get_feedback_details() {
	/* @var CampTix_Plugin $camptix */
	global $camptix;

	$sites = get_sites( array( 'network_id' => EVENTS_NETWORK_ID ));

	$feedback_details = array();

	foreach ( $sites as $site ) {
		switch_to_blog($site->blog_id);

		$blog_details = get_blog_details($site->blog_id);

		// TODO: This should be tested elsewhere.
		if ( 'events.wordpress.test' !== $blog_details->domain ) {
			continue;
		}

		if ( EVENTS_ROOT_BLOG_ID === (int) $blog_details->blog_id ) {
			continue;
		}

		$query = new \WP_Query( array(
			'post_type'      => 'feedback',
			'post_parent'    => get_page_id(),
			'posts_per_page' => -1,
		) );

		$sent      = (int) $camptix->get_sent_email_count( get_email_id() );
		$responses = (int) $query->found_posts;

		$feedback_details[] = array(
			'title' => $blog_details->blogname,
			'sent' => $sent,
			'responses' => $responses,
			'rate' => 0 !== $sent ? ( $responses / $sent ) * 100 . '%' : '',
		);

		// Restore the original site context.
		restore_current_blog();
	}

	// Reset the global post object.
	wp_reset_postdata();

	return $feedback_details;
}

/**
 * Render the menu page.
 */
function render_menu_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Attendee Survey', 'wordcamporg' ); ?></h1>

		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<td><?php esc_html_e( 'Event', 'wordcamporg' ); ?></td>
					<td><?php esc_html_e( 'Total Sent', 'wordcamporg' ); ?></td>
					<td><?php esc_html_e( 'Total Responses', 'wordcamporg' ); ?></td>
					<td><?php esc_html_e( 'Response rate', 'wordcamporg' ); ?></td>
				</tr>
			</thead>
			<tbody>
			<?php
			$feedback_details = get_feedback_details();

			foreach ( $feedback_details as $feedback_detail ) {
				echo '<tr>';
				echo '<td>' . esc_html( $feedback_detail['title'] ) . '</td>';
				echo '<td>' . esc_html( $feedback_detail['sent'] ) . '</td>';
				echo '<td>' . esc_html( $feedback_detail['responses'] ) . '</td>';
				echo '<td>' . esc_html( $feedback_detail['rate'] ) . '</td>';
				echo '</tr>';
			}
			?>
			</tbody>
		</table>
	</div>
	<?php
}

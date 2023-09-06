<?php
/**
 * Adds a page to the central admin.
 */

namespace WordCamp\OrganizerSurvey\AdminPage;

defined( 'WPINC' ) || die();

use function WordCamp\OrganizerSurvey\{get_feature_id};
// use function WordCamp\OrganizerSurvey\Email\{get_email_id};
use function WordCamp\OrganizerSurvey\DebriefSurvey\{get_page_id};
// use function WordCamp\OrganizerSurvey\Cron\{get_wordcamp_organizer_id};

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
		__( 'WordCamp Organizer Survey', 'wordcamporg' ),
		__( 'Organizer Survey', 'wordcamporg' ),
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

	$wordcamps = get_posts( array(
		'post_type' => WCPT_POST_TYPE_ID,
		'post_status' => 'wcpt-closed',
		'posts_per_page' => -1,
	) );

	$feedback_details = array();

	foreach ( $wordcamps as $camp ) {
		// It's a bit counter intuitive, but the site ID is the blog ID.
		$blog_id = get_post_meta( $camp->ID, '_site_id', true );

		switch_to_blog( $blog_id );

		$blog_details = get_blog_details( $blog_id );

		if ( (int) get_site()->site_id !== EVENTS_NETWORK_ID ) {
			continue;
		}

		$query = new \WP_Query( array(
			'post_type'      => 'feedback',
			'post_parent'    => get_page_id(),
			'posts_per_page' => -1,
		) );

		$sent      = '(int) $camptix->get_sent_email_count( get_email_id() )';
		$responses = (int) $query->found_posts;

		$feedback_details[] = array(
			'title' => $blog_details->blogname,
			'admin_url' => admin_url(),
			'email_url' => 'get_edit_post_link( get_email_id() )',
			'responses_url' => admin_url( sprintf( 'edit.php?post_type=feedback&jetpack_form_parent_id=%s', get_page_id() ) ),
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
		<h1><?php echo esc_html__( 'Organizer Survey', 'wordcamporg' ); ?></h1>

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
				echo '<td><a href="'. esc_url( $feedback_detail['admin_url'] ) . '">' . esc_html( $feedback_detail['title'] ) . '</a></td>';
				echo '<td><a href="'. esc_url( $feedback_detail['email_url'] ) . '">' . esc_html( $feedback_detail['sent'] ) . '</a></td>';
				echo '<td><a href="'. esc_url( $feedback_detail['responses_url'] ) . '">' . esc_html( $feedback_detail['responses'] ) . '</a></td>';
				echo '<td>' . esc_html( $feedback_detail['rate'] ) . '</td>';
				echo '</tr>';
			}

			if ( empty( $feedback_details ) ) {
				echo '<tr><td colspan="4">' . esc_html__( 'Nothing to report', 'wordcamporg' ) . '</td></tr>';
			}

			?>
			</tbody>
		</table>
	</div>
	<?php
}

<?php
/**
 * The review screens: the list of applications, reviewing one, and picking which events take applications.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

/**
 * Sets the list's columns.
 *
 * @param string[] $columns Default columns.
 * @return string[]
 */
function get_columns( array $columns ): array {
	return array(
		'cb'        => $columns['cb'],
		'title'     => __( 'Applicant', 'wordcamporg' ),
		'oh_event'  => __( 'Event', 'wordcamporg' ),
		'oh_role'   => __( 'Role', 'wordcamporg' ),
		'oh_status'    => __( 'Status', 'wordcamporg' ),
		// Not core's `date` column: for unpublished posts that shows the last edit.
		'oh_submitted' => __( 'Submitted', 'wordcamporg' ),
	);
}
add_filter( 'manage_' . POST_TYPE . '_posts_columns', __NAMESPACE__ . '\get_columns' );

/**
 * Sorts the Submitted column by submission date.
 *
 * @param string[] $columns Sortable columns.
 * @return string[]
 */
function get_sortable_columns( array $columns ): array {
	$columns['oh_submitted'] = 'date';

	return $columns;
}
add_filter( 'manage_edit-' . POST_TYPE . '_sortable_columns', __NAMESPACE__ . '\get_sortable_columns' );

/**
 * Words the notices after saving for applications, not posts.
 *
 * @param array[] $messages Messages, keyed by post type.
 * @return array[]
 */
function get_updated_messages( array $messages ): array {
	$messages[ POST_TYPE ] = array_fill( 1, 10, __( 'Application updated.', 'wordcamporg' ) );

	return $messages;
}
add_filter( 'post_updated_messages', __NAMESPACE__ . '\get_updated_messages' );

/**
 * Renders a custom column.
 *
 * @param string $column  The column.
 * @param int    $post_id The application.
 */
function render_column( string $column, int $post_id ): void {
	$answers = (array) get_post_meta( $post_id, '_oh_application', true );

	switch ( $column ) {
		case 'oh_event':
			echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_oh_event_id', true ) ) );
			break;

		case 'oh_role':
			echo esc_html( format_answer( get_fields()['role'], $answers['role'] ?? '' ) );
			break;

		case 'oh_status':
			echo esc_html( get_statuses()[ get_post_status( $post_id ) ] ?? '' );
			break;

		case 'oh_submitted':
			echo esc_html( get_the_date( 'Y-m-d H:i', $post_id ) );
			break;
	}
}
add_action( 'manage_' . POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\render_column', 10, 2 );

/**
 * Renders the event filter above the list.
 *
 * @param string $post_type The listed post type.
 */
function render_event_filter( string $post_type ): void {
	if ( POST_TYPE !== $post_type ) {
		return;
	}

	$current = absint( $_GET['oh_event'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
	?>
	<label for="oh-event-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by event', 'wordcamporg' ); ?></label>
	<select id="oh-event-filter" name="oh_event">
		<option value=""><?php esc_html_e( 'All events', 'wordcamporg' ); ?></option>
		<?php foreach ( get_flagship_event_ids() as $wordcamp_id ) : ?>
			<option value="<?php echo esc_attr( (string) $wordcamp_id ); ?>" <?php selected( $current, $wordcamp_id ); ?>><?php echo esc_html( get_the_title( $wordcamp_id ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', __NAMESPACE__ . '\render_event_filter' );

/**
 * Limits the list to the chosen event.
 *
 * @param \WP_Query $query The list's query.
 */
function filter_by_event( \WP_Query $query ): void {
	$event_id = absint( $_GET['oh_event'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.

	if ( ! $event_id || ! $query->is_main_query() || POST_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	$query->set(
		'meta_query',
		array(
			array(
				'key'   => '_oh_event_id',
				'value' => $event_id,
			),
		)
	);
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\filter_by_event' );

/**
 * Replaces core's Publish box with the review boxes.
 *
 * @param \WP_Post $post The application.
 */
function add_meta_boxes( \WP_Post $post ): void {
	remove_meta_box( 'submitdiv', POST_TYPE, 'side' );

	add_meta_box( 'oh-review', __( 'Review', 'wordcamporg' ), __NAMESPACE__ . '\render_review_box', POST_TYPE, 'side', 'high' );
	add_meta_box( 'oh-application', __( 'Application', 'wordcamporg' ), __NAMESPACE__ . '\render_application_box', POST_TYPE, 'normal', 'high' );

	if ( get_post_meta( $post->ID, '_oh_identity_consent', true ) ) {
		add_meta_box( 'oh-identity', __( 'Identity & Background', 'wordcamporg' ), __NAMESPACE__ . '\render_identity_box', POST_TYPE, 'normal' );
	}

	add_meta_box( 'oh-log', __( 'Notes and History', 'wordcamporg' ), __NAMESPACE__ . '\render_log_box', POST_TYPE, 'normal', 'low' );
}
add_action( 'add_meta_boxes_' . POST_TYPE, __NAMESPACE__ . '\add_meta_boxes' );

/**
 * Renders the status, the review fields, and the Save button.
 *
 * @param \WP_Post $post The application.
 */
function render_review_box( \WP_Post $post ): void {
	$statuses = get_statuses();
	$current  = isset( $statuses[ $post->post_status ] ) ? $post->post_status : 'oh-received';
	$next     = array_merge( array( $current ), get_allowed_transitions()[ $current ] ?? array() );
	$links    = (array) get_post_meta( $post->ID, '_oh_freescout_links', true );

	wp_nonce_field( 'oh_review_' . $post->ID, 'oh_review_nonce' );
	?>
	<p>
		<label for="oh-status"><strong><?php esc_html_e( 'Status', 'wordcamporg' ); ?></strong></label><br>
		<select id="oh-status" name="post_status">
			<?php foreach ( $next as $status ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $statuses[ $status ] ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php
	render_review_input( $post->ID, '_oh_reviewer', __( 'Reviewer', 'wordcamporg' ) );
	render_review_input( $post->ID, '_oh_second_reviewer', __( 'Second reviewer', 'wordcamporg' ) );
	?>

	<p>
		<label for="oh-rationale"><strong><?php esc_html_e( 'Rationale', 'wordcamporg' ); ?></strong></label><br>
		<textarea id="oh-rationale" name="oh_review[_oh_rationale]" rows="4" class="widefat"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_oh_rationale', true ) ); ?></textarea>
	</p>

	<p>
		<label for="oh-freescout"><strong><?php esc_html_e( 'FreeScout conversations', 'wordcamporg' ); ?></strong></label><br>
		<textarea id="oh-freescout" name="oh_review[_oh_freescout_links]" rows="2" class="widefat" placeholder="https://freescout.wordpress.net/conversation/…"><?php echo esc_textarea( implode( "\n", $links ) ); ?></textarea>
		<span class="description"><?php esc_html_e( 'One link per line.', 'wordcamporg' ); ?></span>
	</p>

	<?php render_review_input( $post->ID, '_oh_awarded_amount', __( 'Awarded amount (USD)', 'wordcamporg' ), 'number' ); ?>

	<p>
		<label for="oh-funding-method"><strong><?php esc_html_e( 'Funding method', 'wordcamporg' ); ?></strong></label><br>
		<select id="oh-funding-method" name="oh_review[_oh_funding_method]">
			<option value=""></option>
			<?php foreach ( get_funding_methods() as $method => $label ) : ?>
				<option value="<?php echo esc_attr( $method ); ?>" <?php selected( get_post_meta( $post->ID, '_oh_funding_method', true ), $method ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php render_review_input( $post->ID, '_oh_transfer_date', __( 'Transfer date', 'wordcamporg' ), 'date' ); ?>

	<p>
		<?php
		// Named `save`, not `publish`: core would turn a Publish click into the `publish` status.
		submit_button( __( 'Save', 'wordcamporg' ), 'primary', 'save', false );
		?>
	</p>
	<?php
}

/**
 * Renders a one-line review field.
 *
 * @param int    $post_id  The application.
 * @param string $meta_key The field's meta key.
 * @param string $label    The field's label.
 * @param string $type     The input type.
 */
function render_review_input( int $post_id, string $meta_key, string $label, string $type = 'text' ): void {
	$id = 'oh' . str_replace( '_', '-', $meta_key );
	?>
	<p>
		<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
		<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="oh_review[<?php echo esc_attr( $meta_key ); ?>]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, $meta_key, true ) ); ?>" class="widefat" <?php echo 'number' === $type ? 'min="0" step="0.01"' : ''; ?>>
	</p>
	<?php
}

/**
 * The ways a scholarship is paid out.
 *
 * @return string[] Labels, keyed by method.
 */
function get_funding_methods(): array {
	return array(
		'grant'         => __( 'Upfront grant', 'wordcamporg' ),
		'reimbursement' => __( 'Reimbursement', 'wordcamporg' ),
	);
}

/**
 * Renders the applicant's answers.
 *
 * @param \WP_Post $post The application.
 */
function render_application_box( \WP_Post $post ): void {
	$user_id = (int) get_post_meta( $post->ID, '_oh_applicant_user_id', true );
	$user    = get_userdata( $user_id );
	$answers = (array) get_post_meta( $post->ID, '_oh_application', true );
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Event', 'wordcamporg' ); ?></th>
			<td><?php echo esc_html( get_the_title( (int) get_post_meta( $post->ID, '_oh_event_id', true ) ) ); ?></td>
		</tr>
		<?php if ( $user ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress.org profile', 'wordcamporg' ); ?></th>
				<td><a href="<?php echo esc_url( 'https://profiles.wordpress.org/' . $user->user_nicename . '/' ); ?>"><?php echo esc_html( $user->user_login ); ?></a></td>
			</tr>
		<?php endif; ?>
		<?php render_answer_rows( get_fields(), $answers ); ?>
	</table>
	<?php
}

/**
 * Renders the Identity & Background answers, kept apart from the rest of the application.
 *
 * @param \WP_Post $post The application.
 */
function render_identity_box( \WP_Post $post ): void {
	$consent = (int) get_post_meta( $post->ID, '_oh_identity_consent', true );
	?>
	<p class="description">
		<?php
		/* translators: %s: Date. */
		printf( esc_html__( 'Special-category data, shared with consent on %s. Don’t copy it anywhere else.', 'wordcamporg' ), esc_html( wp_date( get_option( 'date_format' ), $consent ) ) );
		?>
	</p>
	<table class="form-table" role="presentation">
		<?php render_answer_rows( get_identity_fields(), (array) get_post_meta( $post->ID, '_oh_identity', true ) ); ?>
	</table>
	<?php
}

/**
 * Renders answers as table rows.
 *
 * @param array[] $fields  Fields, as get_fields() describes them.
 * @param array   $answers Answers, keyed by field name.
 */
function render_answer_rows( array $fields, array $answers ): void {
	foreach ( $fields as $key => $field ) {
		$answer = format_answer( $field, $answers[ $key ] ?? '' );

		if ( '' === $answer ) {
			continue;
		}
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
			<td><?php echo 'textarea' === $field['type'] ? wp_kses_post( wpautop( esc_html( $answer ) ) ) : esc_html( $answer ); ?></td>
		</tr>
		<?php
	}
}

/**
 * Renders the note field and the log of notes and status changes.
 *
 * @param \WP_Post $post The application.
 */
function render_log_box( \WP_Post $post ): void {
	$statuses = get_statuses();
	?>
	<p>
		<label for="oh-note"><strong><?php esc_html_e( 'Add a private note', 'wordcamporg' ); ?></strong></label>
		<textarea id="oh-note" name="oh_note" rows="3" class="widefat"></textarea>
	</p>

	<ul>
		<?php foreach ( get_log( $post->ID ) as $entry ) : ?>
			<?php $author = get_userdata( (int) $entry['user_id'] ); ?>
			<li>
				<strong><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $entry['time'] ) ); ?></strong>
				<?php echo esc_html( $author ? $author->user_login : __( 'System', 'wordcamporg' ) ); ?>:
				<?php
				if ( isset( $entry['text'] ) ) {
					echo esc_html( $entry['text'] );
				} elseif ( 'new' === $entry['from'] ) {
					esc_html_e( 'Submitted the application.', 'wordcamporg' );
				} else {
					/* translators: 1: Previous status. 2: New status. */
					printf( esc_html__( 'Status changed from %1$s to %2$s.', 'wordcamporg' ), esc_html( $statuses[ $entry['from'] ] ?? $entry['from'] ), esc_html( $statuses[ $entry['to'] ] ?? $entry['to'] ) );
				}
				?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Saves the review fields and a new note.
 *
 * The status is saved by core, from the `post_status` field, and checked by enforce_status_transition().
 *
 * @param int $post_id The application.
 */
function save_review( int $post_id ): void {
	if (
		! isset( $_POST['oh_review_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['oh_review_nonce'] ), 'oh_review_' . $post_id )
		|| ! current_user_can( 'edit_post', $post_id )
	) {
		return;
	}

	$review = wp_unslash( (array) ( $_POST['oh_review'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.

	$values = array(
		'_oh_reviewer'        => sanitize_text_field( (string) ( $review['_oh_reviewer'] ?? '' ) ),
		'_oh_second_reviewer' => sanitize_text_field( (string) ( $review['_oh_second_reviewer'] ?? '' ) ),
		'_oh_rationale'       => sanitize_textarea_field( (string) ( $review['_oh_rationale'] ?? '' ) ),
		'_oh_freescout_links' => array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\R/', (string) ( $review['_oh_freescout_links'] ?? '' ) ) ) ) ),
		'_oh_awarded_amount'  => '' === (string) ( $review['_oh_awarded_amount'] ?? '' ) ? '' : (string) round( (float) $review['_oh_awarded_amount'], 2 ),
		'_oh_funding_method'  => isset( get_funding_methods()[ $review['_oh_funding_method'] ?? '' ] ) ? $review['_oh_funding_method'] : '',
		'_oh_transfer_date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $review['_oh_transfer_date'] ?? '' ) ) ? $review['_oh_transfer_date'] : '',
	);

	foreach ( $values as $meta_key => $value ) {
		update_post_meta( $post_id, $meta_key, $value );
	}

	$note = sanitize_textarea_field( wp_unslash( (string) ( $_POST['oh_note'] ?? '' ) ) );
	if ( '' !== $note ) {
		add_note( $post_id, $note );
	}
}
add_action( 'save_post_' . POST_TYPE, __NAMESPACE__ . '\save_review' );

/**
 * Adds the screen for picking which flagship events take applications.
 */
function add_settings_page(): void {
	add_submenu_page(
		'edit.php?post_type=' . POST_TYPE,
		__( 'Flagship Events', 'wordcamporg' ),
		__( 'Flagship Events', 'wordcamporg' ),
		CAPABILITY,
		'oh-events',
		__NAMESPACE__ . '\render_settings_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_settings_page' );

/**
 * Registers the flagship events setting.
 */
function register_settings(): void {
	register_setting(
		'oh_settings',
		EVENTS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => static fn( mixed $ids ): array => array_values(
				array_filter(
					array_map( 'absint', (array) $ids ),
					static fn( int $id ): bool => defined( 'WCPT_POST_TYPE_ID' ) && WCPT_POST_TYPE_ID === get_post_type( $id )
				)
			),
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
add_filter( 'option_page_capability_oh_settings', static fn(): string => CAPABILITY );

/**
 * Renders the flagship events screen: upcoming WordCamps, plus past ones still picked, with the picked ones checked.
 */
function render_settings_page(): void {
	$post_type = defined( 'WCPT_POST_TYPE_ID' ) ? WCPT_POST_TYPE_ID : 'wordcamp';
	$selected  = get_flagship_event_ids();
	$wordcamps = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'meta_key'       => 'Start Date (YYYY-mm-dd)', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen, few rows.
			'meta_value'     => time(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin screen, few rows.
			'meta_compare'   => '>',
			'meta_type'      => 'NUMERIC',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
		)
	);

	// Past flagships stay listed, or saving the form would unpick them while their applications are still processed.
	$past = array_diff( $selected, wp_list_pluck( $wordcamps, 'ID' ) );

	if ( $past ) {
		$wordcamps = array_merge(
			get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'post__in'       => $past,
					'posts_per_page' => count( $past ),
					'meta_key'       => 'Start Date (YYYY-mm-dd)', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen, few rows.
					'orderby'        => 'meta_value_num',
					'order'          => 'ASC',
				)
			),
			$wordcamps
		);
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Flagship Events', 'wordcamporg' ); ?></h1>
		<?php settings_errors(); ?>
		<p><?php esc_html_e( 'Pick the flagship WordCamps that take Open Horizons applications. Each takes them from four months before it starts until two months before.', 'wordcamporg' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'oh_settings' ); ?>
			<fieldset>
				<?php foreach ( $wordcamps as $wordcamp ) : ?>
					<?php $window = get_application_window( $wordcamp->ID ); ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( EVENTS_OPTION ); ?>[]" value="<?php echo esc_attr( (string) $wordcamp->ID ); ?>" <?php checked( in_array( $wordcamp->ID, $selected, true ) ); ?>>
						<?php echo esc_html( get_the_title( $wordcamp ) ); ?>
						<?php if ( $window ) : ?>
							<span class="description">
								<?php
								/* translators: 1: Date applications open. 2: Date they close. */
								printf( esc_html__( '(applications %1$s to %2$s)', 'wordcamporg' ), esc_html( gmdate( 'Y-m-d', $window['opens'] ) ), esc_html( gmdate( 'Y-m-d', $window['closes'] ) ) );
								?>
							</span>
						<?php endif; ?>
					</label><br>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

<?php
/**
 * What the company and sponsorship screens share: notices, the notes and history box, and save handling.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

add_action( 'admin_notices', __NAMESPACE__ . '\render_notices' );
add_filter( 'post_updated_messages', __NAMESPACE__ . '\get_updated_messages' );

/**
 * Queues a notice for the current user's next screen, since saving redirects before it can be shown.
 *
 * @param string $type    `success`, `warning` or `error`.
 * @param string $message The message.
 */
function add_notice( string $type, string $message ): void {
	$key     = 'sv_notices_' . get_current_user_id();
	$notices = (array) get_transient( $key );

	$notices[] = array(
		'type'    => $type,
		'message' => $message,
	);

	set_transient( $key, array_filter( $notices ), MINUTE_IN_SECONDS );
}

/**
 * Shows the current user's queued notices.
 */
function render_notices(): void {
	$key     = 'sv_notices_' . get_current_user_id();
	$notices = array_filter( (array) get_transient( $key ) );

	if ( ! $notices ) {
		return;
	}

	delete_transient( $key );

	foreach ( $notices as $notice ) {
		wp_admin_notice(
			esc_html( $notice['message'] ),
			array(
				'type'        => $notice['type'],
				'dismissible' => true,
			)
		);
	}
}

/**
 * Words the notices after saving for companies and sponsorships, not posts.
 *
 * @param array[] $messages Messages, keyed by post type.
 * @return array[]
 */
function get_updated_messages( array $messages ): array {
	$messages[ COMPANY_POST_TYPE ]     = array_fill( 1, 10, __( 'Company updated.', 'wordcamporg' ) );
	$messages[ SPONSORSHIP_POST_TYPE ] = array_fill( 1, 10, __( 'Sponsorship updated.', 'wordcamporg' ) );

	return $messages;
}

/**
 * Tracks whether a screen's save handler is running, since the actions it takes save the post again.
 *
 * @param bool|null $saving Whether a save is starting or done, or null to read.
 * @return bool
 */
function is_saving( ?bool $saving = null ): bool {
	static $current = false;

	if ( null !== $saving ) {
		$current = $saving;
	}

	return $current;
}

/**
 * Whether a screen's save request is genuine: its nonce checks out, and the user may edit the record.
 *
 * @param int $post_id The record.
 * @return bool
 */
function is_valid_save( int $post_id ): bool {
	return ! is_saving()
		&& isset( $_POST['sv_nonce'] )
		&& wp_verify_nonce( sanitize_key( $_POST['sv_nonce'] ), 'sv_save_' . $post_id )
		&& current_user_can( 'edit_post', $post_id );
}

/**
 * Renders the status picker: the current status and the ones it may change to.
 *
 * @param \WP_Post $post    The record.
 * @param string[] $exclude Statuses only an action sets, which the picker doesn't offer.
 */
function render_status_select( \WP_Post $post, array $exclude = array() ): void {
	$statuses = get_statuses( $post->post_type );
	$current  = isset( $statuses[ $post->post_status ] ) ? $post->post_status : get_initial_status( $post->post_type );
	$next     = array_diff( array_merge( array( $current ), get_allowed_transitions( $post->post_type )[ $current ] ?? array() ), array_diff( $exclude, array( $current ) ) );

	wp_nonce_field( 'sv_save_' . $post->ID, 'sv_nonce' );
	?>
	<p>
		<label for="sv-status"><strong><?php esc_html_e( 'Status', 'wordcamporg' ); ?></strong></label><br>
		<select id="sv-status" name="post_status">
			<?php foreach ( $next as $status ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $statuses[ $status ] ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php
}

/**
 * Renders the Save button, named so core doesn't turn the save into the `publish` status.
 */
function render_save_button(): void {
	echo '<p>';
	submit_button( __( 'Save', 'wordcamporg' ), 'primary', 'save', false );
	echo '</p>';
}

/**
 * Renders a website address as a link reviewers can open, or as text when it isn't an http(s) address.
 *
 * The address comes from organizers, so only an http(s) URL with a real domain becomes a link. Not
 * wp_http_validate_url(): it resolves the host, which a list screen shouldn't do per row.
 *
 * @param string $url  The address.
 * @param string $text Optional. The link text, if not the address.
 */
function render_website( string $url, string $text = '' ): void {
	$href = esc_url( $url, array( 'http', 'https' ) );
	$text = '' === $text ? $url : $text;

	if ( '' === $href || '' === normalize_domain( $href ) ) {
		echo esc_html( $text );
		return;
	}

	printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $href ), esc_html( $text ) );
}

/**
 * Renders a list of conversations.
 *
 * @param array[] $conversations Conversations, as get_conversations() returns them.
 */
function render_conversations( array $conversations ): void {
	if ( ! $conversations ) {
		echo '<p>' . esc_html__( 'No emails sent yet.', 'wordcamporg' ) . '</p>';
		return;
	}
	?>
	<ul>
		<?php foreach ( $conversations as $conversation ) : ?>
			<li>
				<a href="<?php echo esc_url( $conversation['url'] ); ?>"><?php echo esc_html( 'compliance' === $conversation['type'] ? __( 'Compliance request', 'wordcamporg' ) : __( 'Approval', 'wordcamporg' ) ); ?></a>,
				<?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $conversation['time'] ) ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Renders the note field and the log of notes and status changes.
 *
 * @param \WP_Post $post The record.
 */
function render_log_box( \WP_Post $post ): void {
	$statuses = get_statuses( $post->post_type );
	?>
	<p>
		<label for="sv-note"><strong><?php esc_html_e( 'Add a private note', 'wordcamporg' ); ?></strong></label>
		<textarea id="sv-note" name="sv_note" rows="3" class="widefat"></textarea>
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
					/* translators: %s: Status. */
					printf( esc_html__( 'Created as %s.', 'wordcamporg' ), esc_html( $statuses[ $entry['to'] ] ?? $entry['to'] ) );
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
 * Saves a new note from the notes and history box.
 *
 * @param int $post_id The record.
 */
function save_note( int $post_id ): void {
	$note = sanitize_textarea_field( wp_unslash( (string) ( $_POST['sv_note'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().

	if ( '' !== $note ) {
		add_note( $post_id, $note );
	}
}

/**
 * Reads a date field, as `Y-m-d`.
 *
 * @param string $name The field's name.
 * @return string The date, or an empty string.
 */
function get_posted_date( string $name ): string {
	$value = sanitize_text_field( wp_unslash( (string) ( $_POST[ $name ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().

	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
}

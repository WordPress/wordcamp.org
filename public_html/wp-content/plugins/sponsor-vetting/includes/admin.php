<?php
/**
 * What the company and sponsorship screens share: notices, the notes and history box, and save handling.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

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
add_action( 'admin_notices', __NAMESPACE__ . '\render_notices' );

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
add_filter( 'post_updated_messages', __NAMESPACE__ . '\get_updated_messages' );

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
 * Loads the styles for the company and sponsorship screens, and the script that filters their log.
 *
 * @param string $hook_suffix The admin page.
 */
function enqueue_admin_assets( string $hook_suffix ): void {
	$screen = get_current_screen();

	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! $screen || ! in_array( $screen->post_type, array( COMPANY_POST_TYPE, SPONSORSHIP_POST_TYPE ), true ) ) {
		return;
	}

	$dir = dirname( __DIR__ );

	wp_enqueue_style( 'sv-admin', plugins_url( 'css/admin.css', __DIR__ ), array(), (string) filemtime( $dir . '/css/admin.css' ) );
	wp_enqueue_script( 'sv-log', plugins_url( 'js/log.js', __DIR__ ), array(), (string) filemtime( $dir . '/js/log.js' ), true );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_assets' );

/**
 * Renders the note field and the log of notes and status changes, grouped by day.
 *
 * @param \WP_Post $post The record.
 */
function render_log_box( \WP_Post $post ): void {
	$days = array();

	foreach ( get_log( $post->ID ) as $entry ) {
		$days[ wp_date( 'Y-m-d', (int) $entry['time'] ) ][] = $entry;
	}
	?>
	<p class="sv-log-compose">
		<label for="sv-note" class="screen-reader-text"><?php esc_html_e( 'Add a private note', 'wordcamporg' ); ?></label>
		<textarea id="sv-note" name="sv_note" rows="2" class="widefat" placeholder="<?php esc_attr_e( 'Add a private note…', 'wordcamporg' ); ?>"></textarea>
		<span class="description"><?php esc_html_e( 'Only the vetting team sees notes. Links become clickable.', 'wordcamporg' ); ?></span>
	</p>
	<?php
	if ( ! $days ) {
		return;
	}
	?>

	<div class="sv-log-filter hide-if-no-js" role="group" aria-label="<?php esc_attr_e( 'Show', 'wordcamporg' ); ?>">
		<button type="button" class="button button-small" aria-pressed="true" data-sv-log-filter="all"><?php esc_html_e( 'All activity', 'wordcamporg' ); ?></button>
		<button type="button" class="button button-small" aria-pressed="false" data-sv-log-filter="notes"><?php esc_html_e( 'Notes only', 'wordcamporg' ); ?></button>
	</div>

	<?php foreach ( $days as $entries ) : ?>
		<?php $has_notes = (bool) array_filter( $entries, __NAMESPACE__ . '\is_log_note' ); ?>
		<section class="sv-log-day<?php echo $has_notes ? ' sv-log-day-has-notes' : ''; ?>">
			<h3><?php echo esc_html( wp_date( 'l, F j, Y', (int) $entries[0]['time'] ) ); ?></h3>
			<ol>
				<?php foreach ( $entries as $entry ) : ?>
					<li class="<?php echo is_log_note( $entry ) ? 'sv-log-note' : 'sv-log-event'; ?>">
						<time datetime="<?php echo esc_attr( wp_date( 'c', (int) $entry['time'] ) ); ?>"><?php echo esc_html( wp_date( 'H:i', (int) $entry['time'] ) ); ?></time>
						<div><?php echo wp_kses_post( get_log_entry_html( $entry, $post->post_type ) ); ?></div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endforeach; ?>
	<?php
}

/**
 * Whether a log entry is something someone wrote, which the notes-only view keeps, rather than something that happened.
 *
 * @param array $entry The entry, as get_log() returns it.
 * @return bool
 */
function is_log_note( array $entry ): bool {
	return isset( $entry['text'] ) && ! str_starts_with( (string) $entry['text'], 'Imported from Airtable.' );
}

/**
 * Builds what a log entry says.
 *
 * Notes the Airtable import added are shown by what they hold: a comment under its commenter's name, and the
 * imported fields as a table.
 *
 * @param array  $entry     The entry, as get_log() returns it.
 * @param string $post_type The record's post type.
 * @return string HTML.
 */
function get_log_entry_html( array $entry, string $post_type ): string {
	$author     = get_log_author_html( (int) $entry['user_id'] );
	$is_company = COMPANY_POST_TYPE === $post_type;

	if ( ! isset( $entry['text'] ) ) {
		if ( 'new' === $entry['from'] ) {
			return sprintf(
				/* translators: 1: Who created it. 2: Status. */
				esc_html( $is_company ? __( '%1$s created the company as %2$s', 'wordcamporg' ) : __( '%1$s created the sponsorship as %2$s', 'wordcamporg' ) ),
				$author,
				get_log_status_html( $post_type, (string) $entry['to'] )
			);
		}

		return sprintf(
			/* translators: 1: Who changed it. 2: Previous status. 3: New status. */
			esc_html__( '%1$s changed the status from %2$s to %3$s', 'wordcamporg' ),
			$author,
			get_log_status_html( $post_type, (string) $entry['from'] ),
			get_log_status_html( $post_type, (string) $entry['to'] )
		);
	}

	$text = (string) $entry['text'];

	if ( preg_match( '/^Airtable comment by (.+?):\R(.*)$/s', $text, $comment ) ) {
		return '<p class="sv-log-head"><strong>' . esc_html( $comment[1] ) . '</strong></p>' . get_log_text_html( $comment[2] );
	}

	if ( preg_match( '/^Imported from Airtable\.(?:\R(.*))?$/s', $text, $import ) ) {
		$html = '<p class="sv-log-head">' . sprintf(
			/* translators: %s: Who imported it. */
			esc_html( $is_company ? __( '%s imported the company from Airtable', 'wordcamporg' ) : __( '%s imported the sponsorship from Airtable', 'wordcamporg' ) ),
			$author
		) . '</p>';

		$fields = get_imported_fields( $import[1] ?? '' );

		if ( $fields ) {
			$html .= '<details><summary>' . esc_html__( 'Imported details', 'wordcamporg' ) . '</summary><table class="sv-log-fields">';

			foreach ( $fields as $label => $value ) {
				$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . make_clickable( esc_html( $value ) ) . '</td></tr>';
			}

			$html .= '</table></details>';
		}

		return $html;
	}

	return '<p class="sv-log-head">' . sprintf(
		/* translators: %s: Who added the note. */
		esc_html__( '%s added a note', 'wordcamporg' ),
		$author
	) . '</p>' . get_log_text_html( $text );
}

/**
 * Splits the fields the Airtable import listed, one `Label: value` per line, into a table's rows.
 *
 * A line without a label continues the value before it, since some values span lines.
 *
 * @param string $text The lines after the import's first line.
 * @return string[] Values, keyed by label.
 */
function get_imported_fields( string $text ): array {
	$fields = array();
	$label  = '';

	foreach ( preg_split( '/\R/', trim( $text ) ) as $line ) {
		if ( preg_match( '/^([^:]{1,60}): (.*)$/', $line, $match ) ) {
			$label            = $match[1];
			$fields[ $label ] = $match[2];
		} elseif ( '' !== $label ) {
			$fields[ $label ] .= "\n" . $line;
		}
	}

	return $fields;
}

/**
 * Builds who made a log entry: their name, linked to their WordPress.org profile, or System.
 *
 * @param int $user_id The user, or 0 for the system.
 * @return string HTML.
 */
function get_log_author_html( int $user_id ): string {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return '<strong class="sv-log-system">' . esc_html__( 'System', 'wordcamporg' ) . '</strong>';
	}

	return sprintf(
		'<a class="sv-log-author" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( 'https://profiles.wordpress.org/' . $user->user_nicename . '/' ),
		esc_html( $user->display_name )
	);
}

/**
 * Builds a status label, colored by status.
 *
 * @param string $post_type The record's post type.
 * @param string $status    The status.
 * @return string HTML.
 */
function get_log_status_html( string $post_type, string $status ): string {
	return sprintf(
		'<span class="sv-log-status sv-log-status-%s">%s</span>',
		esc_attr( $status ),
		esc_html( get_statuses( $post_type )[ $status ] ?? $status )
	);
}

/**
 * Builds a note's text, with its line breaks kept and its links made clickable.
 *
 * @param string $text The note.
 * @return string HTML.
 */
function get_log_text_html( string $text ): string {
	return '<div class="sv-log-text">' . make_clickable( esc_html( trim( $text ) ) ) . '</div>';
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

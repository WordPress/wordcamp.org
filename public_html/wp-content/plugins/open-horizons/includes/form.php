<?php
/**
 * The application form, `[open-horizons-application]`, and what happens when it's submitted.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

add_shortcode( 'open-horizons-application', __NAMESPACE__ . '\render_shortcode' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\register_styles' );

/**
 * How many applications one person may submit in an hour.
 */
const HOURLY_LIMIT = 3;

/**
 * Registers the form's styles, enqueued when the form renders.
 */
function register_styles(): void {
	wp_register_style( 'open-horizons-form', plugins_url( 'css/form.css', __DIR__ ), array(), (string) filemtime( dirname( __DIR__ ) . '/css/form.css' ) );
}

/**
 * Renders the application form, or why it isn't shown.
 *
 * @return string
 */
function render_shortcode(): string {
	wp_enqueue_style( 'open-horizons-form' );

	if ( ! is_user_logged_in() ) {
		return wcorg_login_message( __( 'Please log in with your <strong>WordPress.org</strong> account to apply.', 'wordcamporg' ), get_permalink() );
	}

	$events = get_open_events();

	if ( ! $events ) {
		return '<p>' . esc_html__( 'Applications are closed. They open four months before each flagship WordCamp; please check back then.', 'wordcamporg' ) . '</p>';
	}

	$input  = array();
	$errors = null;

	if ( isset( $_POST['oh_apply_nonce'] ) ) {
		$input = wp_unslash( (array) ( $_POST['oh'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in submit_application().

		$result = wp_verify_nonce( sanitize_key( $_POST['oh_apply_nonce'] ), 'oh_apply' )
			? submit_application( wp_get_current_user(), $input )
			: new \WP_Error( 'oh_nonce', __( 'Your session expired. Please submit the form again.', 'wordcamporg' ) );

		if ( ! is_wp_error( $result ) ) {
			return '<div class="oh-application-notice"><p>' . esc_html__( 'Thank you for applying! We’ve sent a confirmation to your email address.', 'wordcamporg' ) . '</p></div>';
		}

		$errors = $result;
	}

	return render_form( $events, $input, $errors );
}

/**
 * Submits an application for the user.
 *
 * @param \WP_User $user  The applicant.
 * @param array    $input Raw form input, unslashed: the answers, `event`, `identity` and `identity_consent`.
 * @return int|\WP_Error The application's ID, or why it wasn't accepted.
 */
function submit_application( \WP_User $user, array $input ): int|\WP_Error {
	if ( ! $user->exists() ) {
		return new \WP_Error( 'oh_logged_out', __( 'Please log in to apply.', 'wordcamporg' ) );
	}

	if ( count( find_applications( array(
		'user_id' => $user->ID, 'since' => HOUR_IN_SECONDS,
	) ) ) >= HOURLY_LIMIT ) {
		return new \WP_Error( 'oh_rate_limited', __( 'You’ve submitted several applications in the last hour. Please try again later.', 'wordcamporg' ) );
	}

	$event_id = absint( $input['event'] ?? 0 );
	$events   = get_open_events();

	if ( ! isset( $events[ $event_id ] ) ) {
		return new \WP_Error( 'oh_event_closed', __( 'Please choose an event that is taking applications.', 'wordcamporg' ) );
	}

	$existing = find_applications(
		array(
			'user_id'  => $user->ID,
			'event_id' => $event_id,
		)
	);

	if ( $existing ) {
		/* translators: %s: Event name. */
		return new \WP_Error( 'oh_duplicate', sprintf( __( 'You’ve already applied for %s.', 'wordcamporg' ), $events[ $event_id ]['name'] ) );
	}

	$answers = validate_answers( get_fields(), $input );
	if ( is_wp_error( $answers ) ) {
		return $answers;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => POST_TYPE,
			'post_status' => 'oh-received',
			'post_title'  => $answers['name'],
			// Core would derive the slug from the applicant's name, which erasure then couldn't reach.
			'post_name'   => wp_generate_uuid4(),
			'post_author' => 0,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	update_post_meta( $post_id, '_oh_applicant_user_id', $user->ID );
	update_post_meta( $post_id, '_oh_applicant_email', $answers['email'] );
	update_post_meta( $post_id, '_oh_event_id', $event_id );
	update_post_meta( $post_id, '_oh_application', $answers );

	// Special-category data is only kept with the applicant's explicit consent.
	if ( ! empty( $input['identity_consent'] ) ) {
		$identity = validate_answers( get_identity_fields(), (array) ( $input['identity'] ?? array() ) );

		if ( ! is_wp_error( $identity ) && array_filter( $identity ) ) {
			update_post_meta( $post_id, '_oh_identity', $identity );
			update_post_meta( $post_id, '_oh_identity_consent', time() );
		}
	}

	send_confirmation( $user, $answers, $events[ $event_id ] );

	return $post_id;
}

/**
 * Finds applications.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type int    $user_id  The applicant's user ID.
 *     @type string $email    The applicant's email address, or their account's.
 *     @type int    $event_id The flagship event.
 *     @type int    $since    Only applications submitted in the last this many seconds.
 * }
 * @return int[] Application IDs.
 */
function find_applications( array $args ): array {
	$meta_query = array();

	if ( ! empty( $args['user_id'] ) ) {
		$meta_query[] = array(
			'key'   => '_oh_applicant_user_id',
			'value' => (int) $args['user_id'],
		);
	}

	if ( ! empty( $args['email'] ) ) {
		$by_email = array(
			'relation' => 'OR',
			array(
				'key'   => '_oh_applicant_email',
				'value' => $args['email'],
			),
		);

		$user = get_user_by( 'email', $args['email'] );
		if ( $user ) {
			$by_email[] = array(
				'key'   => '_oh_applicant_user_id',
				'value' => $user->ID,
			);
		}

		$meta_query[] = $by_email;
	}

	if ( ! empty( $args['event_id'] ) ) {
		$meta_query[] = array(
			'key'   => '_oh_event_id',
			'value' => (int) $args['event_id'],
		);
	}

	$query = array(
		'post_type'      => POST_TYPE,
		// Listed, because none of the statuses are included in `any`.
		'post_status'    => array_merge( array_keys( get_statuses() ), array( 'trash' ) ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few applications per person.
		'no_found_rows'  => true,
	);

	if ( ! empty( $args['since'] ) ) {
		$query['date_query'] = array(
			array(
				'after'  => gmdate( 'Y-m-d H:i:s', time() - (int) $args['since'] ),
				'column' => 'post_date_gmt',
			),
		);
	}

	return array_map( 'intval', get_posts( $query ) );
}

/**
 * Emails the applicant that their application arrived.
 *
 * Sent with wp_mail(), not through FreeScout: it needs no reply, and a conversation per applicant would flood
 * the mailbox. Replies still reach the scholarships mailbox through Reply-To.
 *
 * Goes to the account's address, not the one typed into the form, so the form can't send mail to strangers.
 *
 * @param \WP_User $user    The applicant.
 * @param array    $answers The application's answers.
 * @param array    $event   The event: `name` and `closes`.
 */
function send_confirmation( \WP_User $user, array $answers, array $event ): void {
	$headers = defined( 'OPEN_HORIZONS_EMAIL' ) ? array( 'Reply-To: ' . OPEN_HORIZONS_EMAIL ) : array();

	$message = sprintf(
		/* translators: 1: Applicant's name. 2: Event name. 3: Date applications close. */
		__(
			"Hi %1\$s,\n\nThank you for applying for an Open Horizons Scholarship to attend %2\$s. We've received your application.\n\nWe review all applications together once applications close on %3\$s, and will email you with our decision within two to three weeks of that date.\n\nIf you have any questions, just reply to this email.\n\nThe WordPress Community Team",
			'wordcamporg'
		),
		$answers['name'],
		$event['name'],
		wp_date( get_option( 'date_format' ), $event['closes'] )
	);

	wp_mail(
		$user->user_email,
		/* translators: %s: Event name. */
		sprintf( __( 'We received your Open Horizons Scholarship application for %s', 'wordcamporg' ), $event['name'] ),
		$message,
		$headers
	);
}

/**
 * Renders the form.
 *
 * @param array[]        $events Open events, as get_open_events() returns them.
 * @param array          $input  What was submitted, to fill the form in again.
 * @param \WP_Error|null $errors Why the last submission wasn't accepted.
 * @return string
 */
function render_form( array $events, array $input, ?\WP_Error $errors ): string {
	$user = wp_get_current_user();

	// Start from the account's name and email, so applicants don't retype them.
	$input += array(
		'name'  => trim( $user->first_name . ' ' . $user->last_name ) ? trim( $user->first_name . ' ' . $user->last_name ) : $user->display_name,
		'email' => $user->user_email,
	);

	ob_start();
	?>

	<form class="oh-application" method="post">
		<?php if ( $errors ) : ?>
			<div class="oh-application-notice is-error">
				<?php foreach ( $errors->get_error_messages() as $message ) : ?>
					<p><?php echo esc_html( $message ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: %s: WordPress.org username. */
				esc_html__( 'Applying as %s on WordPress.org.', 'wordcamporg' ),
				'<strong>' . esc_html( $user->user_login ) . '</strong>'
			);
			?>
		</p>

		<p>
			<label for="oh-event"><?php esc_html_e( 'Event you’re requesting the scholarship for', 'wordcamporg' ); ?> <span aria-hidden="true">*</span></label><br>
			<select id="oh-event" name="oh[event]" required>
				<option value=""></option>
				<?php foreach ( $events as $event_id => $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event_id ); ?>" <?php selected( (string) ( $input['event'] ?? '' ), (string) $event_id ); ?>>
						<?php echo esc_html( $event['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php
		foreach ( get_fields() as $key => $field ) {
			render_field( 'oh[' . $key . ']', 'oh-' . $key, $field, $input[ $key ] ?? null );
		}
		?>

		<fieldset class="oh-identity">
			<legend><?php esc_html_e( 'Identity & Background (optional)', 'wordcamporg' ); ?></legend>
			<p><?php esc_html_e( 'The Open Horizons Scholarship prioritizes contributors from underrepresented, underserved, and economically disadvantaged backgrounds. These questions are optional, and help us understand and improve the scholarship’s reach.', 'wordcamporg' ); ?></p>

			<?php
			foreach ( get_identity_fields() as $key => $field ) {
				render_field( 'oh[identity][' . $key . ']', 'oh-identity-' . $key, $field, $input['identity'][ $key ] ?? null );
			}
			?>

			<p>
				<label>
					<input type="checkbox" name="oh[identity_consent]" value="1" <?php checked( ! empty( $input['identity_consent'] ) ); ?>>
					<?php esc_html_e( 'I agree that the Community Team may use my answers in this section to review my application. They are only seen by the scholarship reviewers. Without this consent, the answers in this section are not saved.', 'wordcamporg' ); ?>
				</label>
			</p>
		</fieldset>

		<?php wp_nonce_field( 'oh_apply', 'oh_apply_nonce' ); ?>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit application', 'wordcamporg' ); ?></button></p>
	</form>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders one question.
 *
 * @param string $name  The input's name.
 * @param string $id    The input's ID.
 * @param array  $field The field, as get_fields() describes it.
 * @param mixed  $value The current answer.
 */
function render_field( string $name, string $id, array $field, mixed $value ): void {
	$required = $field['required'] ? '<span aria-hidden="true">*</span>' : '';

	if ( 'multi' === $field['type'] ) {
		?>
		<fieldset>
			<legend><?php echo esc_html( $field['label'] ); ?> <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?></legend>
			<?php foreach ( $field['options'] as $option => $label ) : ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $option ); ?>" <?php checked( in_array( $option, (array) $value, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label><br>
			<?php endforeach; ?>
		</fieldset>
		<?php
		return;
	}

	if ( 'checkbox' === $field['type'] ) {
		?>
		<p>
			<label>
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> <?php echo $field['required'] ? 'required' : ''; ?>>
				<?php echo esc_html( $field['label'] ); ?> <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>
			</label>
		</p>
		<?php
		return;
	}

	$options = 'country' === $field['type']
		? wp_list_pluck( wcorg_get_countries(), 'name' )
		: ( $field['options'] ?? array() );
	?>
	<p>
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?> <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?></label><br>

		<?php if ( 'textarea' === $field['type'] ) : ?>
			<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5" cols="60" <?php echo $field['required'] ? 'required' : ''; ?>><?php echo esc_textarea( (string) $value ); ?></textarea>
		<?php elseif ( $options ) : ?>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $field['required'] ? 'required' : ''; ?>>
				<option value=""></option>
				<?php foreach ( $options as $option => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( (string) $value, (string) $option ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="<?php echo 'email' === $field['type'] ? 'email' : 'text'; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" size="40" <?php echo $field['required'] ? 'required' : ''; ?>>
		<?php endif; ?>
	</p>
	<?php
}

<?php
/**
 * Plugin Name:        WordCamp.org Login Message
 * Plugin Description: Displays a "Login with your WordPress.org account." in wp-login.php
 */

add_action( 'login_head',         'wcorg_login_css' );
add_action( 'admin_print_styles', 'wcorg_login_css' );
/**
 * Render CSS on the login screen.
 */
function wcorg_login_css() {
	?>

	<style type="text/css">
		#wp-auth-check-wrap #wp-auth-check {
			width: 500px !important;
		}

		body.login #login {
			width: 420px;
		}

			div.message p {
				margin-bottom: 1em;
			}

			#not-your-personal-site {
				font-style: italic;
			}

			.login form,
			.interim-login.login form {
				width: 272px;   /* 320px minus 24px padding on each side */
				margin-left: auto;
				margin-right: auto;
			}

			/* Present account creation as an action rather than another inline nav link. */
			.login #nav .wcorg-login-register {
				display: block;
				max-width: 322px;   /* Match the width of the form above it. */
				margin: 0 auto 1em;
				text-align: center;
			}
	</style>

	<?php
}

/**
 * Override the default login message.
 *
 * We share user tables with WordPress.org, so users need to know to use that account.
 *
 * @param string        $message
 * @param string | bool $redirect_to
 *
 * @return string
 */
function wcorg_login_message( $message, $redirect_to = false ) {
	$locale    = get_locale();
	$login_url = wcorg_get_wporg_login_url( $locale );

	if ( ! $redirect_to && ! empty( $_REQUEST['redirect_to'] ) ) {
		$redirect_to = $_REQUEST['redirect_to'];
	}

	if ( $redirect_to && wp_validate_redirect( $redirect_to ) != $redirect_to ) {
		$redirect_to = false;
	}

	$wporg_login_url = add_query_arg( 'redirect_to', $redirect_to, $login_url );

	if ( empty( $message ) ) {
		$message = __( 'Please use your <strong>WordPress.org</strong>* account to log in.', 'wordcamporg' );
	}

	ob_start();
	?>

	<div id="wcorg-login-message" class="message">
		<p><?php echo wp_kses_post( $message ); ?></p>

		<?php if ( ! is_login() ) : ?>
			<?php // On wp-login.php this is a button under the form instead, see wcorg_login_register_link(). ?>
			<p>
				<?php echo wp_kses_post( sprintf(
					__( 'If you don\'t have an account, <a href="%s">please create one</a>.', 'wordcamporg' ),
					esc_url( wcorg_get_registration_url( $redirect_to ) )
				) ); ?>
			</p>
		<?php endif; ?>

		<p id="not-your-personal-site">
			<?php echo wp_kses_post( sprintf(
				__( '* This is your account for <a href="%s">the official WordPress.org website</a>, rather than your personal WordPress site.', 'wordcamporg' ),
				$wporg_login_url
			) ); ?>
		</p>
	</div>

	<?php
	$message = ob_get_clean();
	return $message;
}
add_filter( 'login_message', 'wcorg_login_message' );

/**
 * Build the WordPress.org registration URL, returning to the current login request afterwards.
 *
 * @param string | bool $redirect_to Where to send the user once they've registered and logged in.
 *
 * @return string
 */
function wcorg_get_registration_url( $redirect_to = false ) {
	$registration_url = wcorg_get_wporg_login_url( get_locale(), 'register' );

	/*
	 * $redirect_to gets urlencode()'d once by wp_login_url() and then all of $login_url gets encoded directly,
	 * because $registration_url will end up with two redirect_to parameters nested inside it.
	 * e.g., https://wordpress.org/support/register.php?redirect_to=https%3A%2F%2Fwordcamp.org%2Fwp-login.php%3Fredirect_to%3Dhttp%253A%252F%252Ftesting.wordcamp.org%252Ftickets%252F
	 *
	 * The second redirect_to parameter is intentionally double-encoded so that it ends up single-encoded after the
	 * redirection back to wp-login.php.
	 */
	$login_url = urlencode( wp_login_url( $redirect_to ) );

	return add_query_arg( 'redirect_to', $login_url, $registration_url );
}

/**
 * Add a "Create an account" action underneath the login form.
 *
 * Accounts live on WordPress.org, so `users_can_register` is off here and core never prints its own
 * "Register" link. `lost_password_html_link` is the only filter inside the `#nav` row below the form,
 * so we prepend to it to get the link where core would have put its own.
 *
 * @param string $html_link The "Lost your password?" link.
 *
 * @return string
 */
function wcorg_login_register_link( $html_link ) {
	// The same `#nav` row is used by the register and lost password forms, where this would make no sense.
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';

	if ( 'login' !== $action ) {
		return $html_link;
	}

	$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : false;

	if ( $redirect_to && wp_validate_redirect( $redirect_to ) !== $redirect_to ) {
		$redirect_to = false;
	}

	$register_link = sprintf(
		'<a class="wcorg-login-register button button-secondary button-large" href="%s">%s</a>',
		esc_url( wcorg_get_registration_url( $redirect_to ) ),
		esc_html__( 'Create a WordPress.org account', 'wordcamporg' )
	);

	return $register_link . $html_link;
}
add_filter( 'lost_password_html_link', 'wcorg_login_register_link' );

/**
 * Build correct WordPress.org "SSO" login page URL.
 *
 * @param  string $locale
 * @param  string $path   Path to display, can be "register" or "root" (default).
 * @return string
 */
function wcorg_get_wporg_login_url( $locale, $path = 'root' ) {
	$url = 'https://login.wordpress.org';

	if ( 'register' === $path ) {
		$url .= '/register';
	}

	$url = add_query_arg( 'locale', $locale, $url );

	return $url;
}

// Prevent password resets, since they need to be done on w.org.
add_filter( 'allow_password_reset', '__return_false' );
add_filter( 'show_password_fields', '__return_false' );

/**
 * Redirect users to WordPress.org to reset their passwords.
 *
 * Otherwise, there's nothing to indicate where they can reset it.
 */
function wcorg_reset_passwords_at_wporg() {
	wp_redirect( 'https://login.wordpress.org/lostpassword/' );
	die();
}
add_action( 'login_form_lostpassword', 'wcorg_reset_passwords_at_wporg' );

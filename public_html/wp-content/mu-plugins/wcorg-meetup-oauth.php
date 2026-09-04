<?php
/**
 * Connect the network's Meetup integration to a Meetup account.
 *
 * Meetup hands the authorization code back through a redirect rather than a request we make ourselves, so the
 * callback that receives it can't carry a nonce of its own. Both ends of that trip live here: a network admin
 * starts the process from Settings, and the `state` minted for them at that point is what the callback matches
 * against when Meetup sends them back.
 */

namespace WordCamp\Meetup_OAuth;

use WordPressdotorg\MU_Plugins\Utilities\Meetup_OAuth2_Client;

defined( 'WPINC' ) || die();

const OAUTH_CAP      = 'manage_network';
const OAUTH_ACTION   = 'wcorg-meetup-oauth';
const PAGE_SLUG      = 'wcorg-meetup';
const STATE_META_KEY = 'wcorg_meetup_oauth_state';
const STATE_LIFETIME = 15 * MINUTE_IN_SECONDS;
const AUTHORIZE_URL  = 'https://secure.meetup.com/oauth2/authorize';

/**
 * The site option the Meetup client keeps its token in.
 *
 * Mirrors `Meetup_OAuth2_Client::SITE_OPTION_KEY_OAUTH`. It's repeated rather than referenced so that reading
 * the connection status doesn't have to load a class whose constants require the Meetup credentials to be
 * defined, and whose constructor pre-caches a token as a side effect.
 */
const TOKEN_SITE_OPTION = 'meetup_access_token';

add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page' );
add_action( 'admin_post_' . OAUTH_ACTION, __NAMESPACE__ . '\handle_authorize_request' );
add_action( 'admin_init', __NAMESPACE__ . '\maybe_exchange_code_for_token' );
add_filter( 'meetup_oauth2_authorize_url', __NAMESPACE__ . '\filter_authorize_url' );

/**
 * Add a settings page.
 *
 * @return void
 */
function add_page() {
	add_submenu_page(
		'settings.php',
		'Meetup',
		'Meetup',
		OAUTH_CAP,
		PAGE_SLUG,
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * The screen that starts the connection process.
 *
 * @return string
 */
function get_page_url() {
	return add_query_arg( 'page', PAGE_SLUG, network_admin_url( 'settings.php' ) );
}

/**
 * Point the client's reconnection notice at the screen that starts the process.
 *
 * The client can't build a usable link itself, because the `state` on it has to be minted and remembered by
 * whoever handles the callback.
 *
 * @return string
 */
function filter_authorize_url() {
	return get_page_url();
}

/**
 * Whether this environment has the credentials needed to talk to Meetup.
 *
 * @return bool
 */
function has_credentials() {
	return defined( 'MEETUP_OAUTH_CONSUMER_KEY' ) && MEETUP_OAUTH_CONSUMER_KEY
		&& defined( 'MEETUP_OAUTH_CONSUMER_REDIRECT_URI' ) && MEETUP_OAUTH_CONSUMER_REDIRECT_URI;
}

/**
 * Render the settings page.
 *
 * @return void
 */
function render_page() {
	$token     = get_site_option( TOKEN_SITE_OPTION, array() );
	$connected = is_array( $token ) && ! empty( $token['access_token'] );
	$expires   = ( $connected && isset( $token['timestamp'], $token['expires_in'] ) )
		? (int) $token['timestamp'] + (int) $token['expires_in']
		: 0;

	?>
	<div class="wrap">
		<h1>Meetup</h1>

		<h2>Connection</h2>

		<p>
			This connection lets the network read group and event data from Meetup, which feeds chapter and
			meetup vetting as well as the events importer.
		</p>

		<?php if ( $connected ) : ?>
			<p>
				<span class="dashicons dashicons-yes" aria-hidden="true"></span>
				<?php
				if ( $expires ) {
					printf(
						'Connected. The access token %1$s on <strong>%2$s</strong>.',
						esc_html( $expires > time() ? 'expires' : 'expired' ),
						esc_html( gmdate( 'd M Y H:i', $expires ) . ' UTC' )
					);
				} else {
					echo 'Connected.';
				}
				?>
			</p>
			<p>Reconnecting replaces the stored token for every site on the network.</p>
		<?php else : ?>
			<p>
				<span class="dashicons dashicons-no" aria-hidden="true"></span>
				Not connected.
			</p>
		<?php endif; ?>

		<?php if ( has_credentials() ) : ?>
			<p>
				You will be asked to log in to meetup.com to complete the connection. Use the
				<strong>WordCamp Central</strong> Meetup account for this login &mdash; the network's Meetup
				requests all run as whichever account authorizes here.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( OAUTH_ACTION ); ?>

				<input type="hidden" name="action" value="<?php echo esc_attr( OAUTH_ACTION ); ?>" />

				<?php submit_button( $connected ? 'Reconnect' : 'Connect', 'primary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p><em>The Meetup credentials are not configured in this environment.</em></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Send the user to Meetup to authorize the connection.
 *
 * @return void
 */
function handle_authorize_request() {
	if ( ! current_user_can( OAUTH_CAP ) ) {
		wp_die( 'You do not have permission to perform this action.', 403 );
	}

	check_admin_referer( OAUTH_ACTION );

	if ( ! has_credentials() ) {
		wp_die( 'The Meetup credentials are not configured in this environment.' );
	}

	$url = AUTHORIZE_URL . '?' . http_build_query(
		array(
			'client_id'     => MEETUP_OAUTH_CONSUMER_KEY,
			'response_type' => 'code',
			'redirect_uri'  => MEETUP_OAUTH_CONSUMER_REDIRECT_URI,
			'state'         => create_oauth_state(),
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);

	// Added right before the redirect, so that meetup.com isn't considered "safe" for the rest of the request.
	add_filter( 'allowed_redirect_hosts', __NAMESPACE__ . '\allow_meetup_redirect', 10, 2 );

	wp_safe_redirect( $url );
	exit;
}

/**
 * Add meetup.com to the safe redirect list so we can go start the authorization.
 *
 * @param array  $allowed_domains
 * @param string $domain
 *
 * @return array
 */
function allow_meetup_redirect( $allowed_domains, $domain ) {
	if ( 'secure.meetup.com' === $domain ) {
		$allowed_domains[] = $domain;
	}

	return array_unique( $allowed_domains );
}

/**
 * Turn an authorization code from Meetup into a stored token.
 *
 * Meetup redirects to the network's admin root, so this runs on every wp-admin request and has to decide for
 * itself whether the one it's looking at is a callback that this user started.
 *
 * @return void
 */
function maybe_exchange_code_for_token() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Meetup redirects the browser here, so
	// it can't send a nonce. The `state` checked below is what stands in for one.
	$code  = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
	$state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! $code || ! $state ) {
		return;
	}

	// The token is shared by every site on the network, so binding it is a network administrator's call.
	if ( ! current_user_can( OAUTH_CAP ) ) {
		return;
	}

	if ( ! verify_oauth_state( $state ) ) {
		return;
	}

	if ( ! has_credentials() || ! class_exists( Meetup_OAuth2_Client::class ) ) {
		return;
	}

	if ( ( new Meetup_OAuth2_Client() )->get_oauth_token( $code ) ) {
		// The code has been spent, so the value that vouched for it is done too.
		delete_oauth_state();
	}
}

/**
 * Generate a `state` for an authorization request, and remember it for the callback.
 *
 * User meta is used rather than an option or a transient because it's shared across every site and network in
 * the install, while the redirect URI always points at one of them.
 *
 * @return string
 */
function create_oauth_state() {
	$state = wp_generate_password( 32, false );

	update_user_meta(
		get_current_user_id(),
		STATE_META_KEY,
		array(
			'state'   => $state,
			'expires' => time() + STATE_LIFETIME,
		)
	);

	return $state;
}

/**
 * Check that a callback's `state` matches the authorization request that this user started.
 *
 * This only reads the stored value; discarding it is left to `maybe_exchange_code_for_token()`. That way a
 * stray request to the callback URL -- a reloaded tab, an old one out of history -- can't throw away an
 * authorization that's still in flight.
 *
 * @param string $state
 *
 * @return bool
 */
function verify_oauth_state( $state ) {
	$stored = get_user_meta( get_current_user_id(), STATE_META_KEY, true );

	if ( ! is_string( $state ) || ! $state || ! is_array( $stored ) ) {
		return false;
	}

	// `hash_equals()` throws a TypeError on anything but strings, so check the stored side too.
	if ( empty( $stored['state'] ) || ! is_string( $stored['state'] ) ) {
		return false;
	}

	if ( empty( $stored['expires'] ) || time() > $stored['expires'] ) {
		return false;
	}

	return hash_equals( $stored['state'], $state );
}

/**
 * Discard the `state` stored for the current user, once it can't be of any more use.
 *
 * @return bool
 */
function delete_oauth_state() {
	return delete_user_meta( get_current_user_id(), STATE_META_KEY );
}

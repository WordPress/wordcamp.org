<?php
namespace WordCamp\Error_Handling;
defined( 'WPINC' ) || die();

use DirectoryIterator;
use Dotorg\Slack\Send;
use function WordCamp\Logger\{ redact_keys };

/*
 * Catch errors on production and pipe them into Slack, because that's the only way we have to see them.
 *
 * See https://make.wordpress.org/systems/2018/02/11/access-to-wordcamp-error-logs/.
 *
 * Note that this won't catch errors in drop-in plugins or Sunrise, because they load much earlier than this.
 * Creating a `fatal-error-handler.php` file would let us override Core's fatal error handler, but we'd need
 * to update all the code here to not use any Core constants/functions that load after drop-in plugins. We also
 * want to handle non-fatals here.
 *
 * phpcs:disable WordPress.Security.NonceVerification -- This doesn't handle nonce'd actions, but does need to
 * work with the raw $_POST at a generic level.
 */

/*
 * Intentionally not using `get_temp_dir()`, because that could potentially return `WP_CONTENT_DIR`. Storing
 * error records there could result in leaking sensitive information that failed to be redacted before being
 * logged.
 */
const ERROR_RATE_LIMITING_DIR = '/tmp/error_limiting';

// Setting an error handler would interfere with PHPUnit. Tests only need to test individual functions.
if ( ! defined( 'WP_RUN_CORE_TESTS' ) || ! WP_RUN_CORE_TESTS ) {
	set_error_handler( __NAMESPACE__ . '\handle_error' );
	register_shutdown_function( __NAMESPACE__ . '\catch_fatal' );

	if ( ! wp_next_scheduled( 'clear_error_rate_limiting_files' ) ) {
		wp_schedule_event( time(), 'daily', 'clear_error_rate_limiting_files' );
	}

	add_action( 'clear_error_rate_limiting_files', __NAMESPACE__ . '\handle_clear_error_rate_limiting_files' );
}

/**
 * Error handler to track error frequency and conditionally send error messages to Slack.
 *
 * Note: This should always return false so that default error handling still occurs as well.
 *
 * @param int    $err_no
 * @param string $err_msg
 * @param string $file
 * @param int    $line
 *
 * @return bool
 */
function handle_error( $err_no, $err_msg, $file, $line ) {
	require_once __DIR__ . '/1-logger.php';

	if ( ! check_error_handling_dependencies() ) {
		return false;
	}

	// Checks to see if the error-throwing expression is prepended with the @ control operator.
	// See https://secure.php.net/manual/en/function.set-error-handler.php.
	if ( 0 === error_reporting() ) {
		return false;
	}

	$accepted_error_types = array(
		E_ERROR,
		E_CORE_ERROR,
		E_COMPILE_ERROR,
		E_USER_ERROR,
		E_WARNING,
		E_PARSE,
		E_CORE_WARNING,
		E_COMPILE_WARNING,
		E_USER_WARNING,
		E_NOTICE,
		E_USER_NOTICE,
		E_STRICT,
		E_DEPRECATED,
		E_USER_DEPRECATED,
	);

	if ( ! in_array( $err_no, $accepted_error_types ) ) {
		return false;
	}

	$is_third_party = is_third_party_file( $file );
	$is_fatal_error = is_fatal_error( $err_no );

	// Non-fatals from third party code usually aren't actionable or important.
	if ( $is_third_party && ! $is_fatal_error ) {
		return false;
	}

	$err_key      = substr( base64_encode("$file-$line-$err_no" ), -254 ); // Max file length for ubuntu is 255.
	$send_message = false;
	$occurrences  = 0;

	$data = array(
		'last_reported_at' => time(),
		'error_count'      => 0, // Since last reported.
	);

	if ( error_record_exists( $err_key ) ) {
		$data                 = get_error_record( $err_key );
		$data['error_count'] += 1;
		$occurrences          = $data['error_count'];
		$time_elapsed         = time() - $data['last_reported_at'];
		$pause_interval       = $is_fatal_error ? 30 : 600;

		if ( $time_elapsed > $pause_interval ) {
			$data['last_reported_at'] = time();
			$data['error_count']      = 0;
			$send_message             = true;
		}
	} else {
		$send_message = true;
	}

	update_error_record( $err_key, $data );

	if ( $send_message ) {
		send_error_to_slack( $err_no, $err_msg, $file, $line, $occurrences );
	}

	return false;
}

/**
 * Check if a file is custom code or from a third-party.
 *
 * @param string $file
 *
 * @return bool `true` if the file is from a third party.
 */
function is_third_party_file( $file ) {
	/*
	 * Use constants in the keys here to avoid path disclosure.
	 * `ABSPATH` already has a trailing slash, `WP_PLUGIN_DIR` and `WP_CONTENT_DIR` don't.
	 */
	$third_party_folders = array(
		/*
		 * For Core, this can only have subfolders. `ABSPATH` alone would match things like `themes/campsite-2017`.
		 * Core's `wp-content` folder isn't included here, because we use a separate one instead on production, but
		 * a standard `wordpress-develop` test environment doesn't.
		 */
		ABSPATH . 'wp-admin/',
		WPINC,

		WP_PLUGIN_DIR . '/akismet/',
		WP_PLUGIN_DIR . '/bbpress/',
		WP_PLUGIN_DIR . '/campt-indian-payment-gateway/',
		WP_PLUGIN_DIR . '/camptix-bd-payments/',
		WP_PLUGIN_DIR . '/camptix-mercadopago/',
		WP_PLUGIN_DIR . '/camptix-pagseguro/',
		WP_PLUGIN_DIR . '/camptix-payfast-gateway/',
		WP_PLUGIN_DIR . '/camptix-paynow/',
		WP_PLUGIN_DIR . '/camptix-paystack/',
		WP_PLUGIN_DIR . '/camptix-trustcard/',
		WP_PLUGIN_DIR . '/camptix-trustpay/',
		WP_PLUGIN_DIR . '/classic-editor/',
		WP_PLUGIN_DIR . '/custom-content-width/',
		WP_PLUGIN_DIR . '/edit-flow/',
		WP_PLUGIN_DIR . '/email-post-changes/',
		// Gutenberg isn't included here, because `send_error_to_slack()` will pipe it to a separate channel.
		WP_PLUGIN_DIR . '/hyperdb/',
		// Jetpack isn't included here, because `send_error_to_slack()` will pipe it to a separate channel.
		WP_PLUGIN_DIR . '/json-rest-api/',
		WP_PLUGIN_DIR . '/liveblog/',
		WP_PLUGIN_DIR . '/public-post-preview/',
		WP_PLUGIN_DIR . '/pwa/',
		WP_PLUGIN_DIR . '/wordpress-importer/',
		WP_PLUGIN_DIR . '/wp-cldr/',
		WP_PLUGIN_DIR . '/wp-super-cache/',

		WP_CONTENT_DIR . '/themes/p2/',
		WP_CONTENT_DIR . '/themes/twenty', // Partial so that it matches all Core themes.
	);

	$matches = array_filter(
		$third_party_folders,
		function( $folder ) use ( $file ) {
			return false !== stripos( $file, $folder );
		}
	);

	/*
	 * Match known Core root files, because `$third_party_folders` can't include them.
	 *
	 * On production, Core is installed in a subfolder, and root-level files _are_ custom, so we don't want to
	 * ignore errors in them. In a standard `wordpress-develop` test environment, though, Core is installed at
	 * the root of the `src/` folder.
	 */
	$filename           = basename( $file );
	$is_at_install_root = ABSPATH === trailingslashit( dirname( $file ) );

	if ( $is_at_install_root ) {
		$is_known_core_root_file = $filename === 'xmlrpc.php' || 'wp-' === substr( $filename, 0, 3 );

		// `index.php` could be Core's version, or our wrapper, so just accept either.
		$is_custom_root_file = in_array( $filename, array( 'index.php', 'wp-config.php' ), true );

		if ( $is_known_core_root_file && ! $is_custom_root_file ) {
			$matches[] = $file;
		}
	}

	return ! empty( $matches );
}

/**
 * Shutdown handler for catching fatal errors and sending them to Slack.
 *
 * Some error types cannot be handled directly by a custom error handler. However, we can catch them during shutdown
 * and redirect them to the custom handler callback.
 *
 * @return void
 */
function catch_fatal() {
	$error = error_get_last();

	// See https://secure.php.net/manual/en/function.set-error-handler.php.
	if ( ! empty( $error ) && is_fatal_error( $error['type'] ) ) {
		handle_error( $error['type'], $error['message'], $error['file'], $error['line'] );
	}
}

/**
 * Determine if we want to treat the given error as a fatal.
 *
 * @param int $error_type
 *
 * @return bool
 */
function is_fatal_error( $error_type ) {
	$unhandled_error_types = array(
		E_ERROR,
		E_PARSE,
		E_CORE_ERROR,
		E_CORE_WARNING,
		E_COMPILE_ERROR,
		E_COMPILE_WARNING,
	);

	return in_array( $error_type, $unhandled_error_types, true );
}

/**
 * Check if an error has previously been recorded.
 *
 * @param string $err_key
 *
 * @return bool
 */
function error_record_exists( $err_key ) {
	$error_file = ERROR_RATE_LIMITING_DIR . "/$err_key";

	return is_readable( $error_file );
}

/**
 * Get the data recorded for an error.
 *
 * Includes the timestamp of the error's last occurrence and the number of times it has occurred since it was
 * last reported/sent to Slack.
 *
 * @param string $err_key
 *
 * @return array|mixed|object
 */
function get_error_record( $err_key ) {
	$error_file = ERROR_RATE_LIMITING_DIR . "/$err_key";

	return json_decode( file_get_contents( $error_file ), true );
}

/**
 * Update the recorded data for an error.
 *
 * @param string $err_key
 * @param array  $data
 *
 * @return bool|int
 */
function update_error_record( $err_key, $data ) {
	$error_file = ERROR_RATE_LIMITING_DIR . "/$err_key";

	return file_put_contents( $error_file, wp_json_encode( $data ) );
}

/**
 * Build and dispatch an error message to a channel or user on Slack.
 *
 * @param int    $err_no
 * @param string $err_msg
 * @param string $file
 * @param int    $line
 * @param int    $occurrences
 *
 * @return void
 */
function send_error_to_slack( $err_no, $err_msg, $file, $line, $occurrences = 0 ) {
	// Local environments can just use `display_errors` etc, and shouldn't expose production's API token.
	if ( ! defined( 'WORDCAMP_ENVIRONMENT' )
		|| 'local' === WORDCAMP_ENVIRONMENT
		|| ! is_readable( __DIR__ . '/includes/slack/send.php' )
	) {
		return;
	}

	require_once __DIR__ . '/includes/slack/send.php';

	$error_name  = array_search( $err_no, get_defined_constants( true )['Core'] ) ?: '';
	$messages    = explode( 'Stack trace:', $err_msg, 2 );
	$text        = ( ! empty( $messages[0] ) ) ? trim( sanitize_text_field( $messages[0] ) ) : '';
	$domain      = esc_url( get_site_url() );
	$page_slug   = sanitize_text_field( untrailingslashit( $_SERVER['REQUEST_URI'] ) ) ?: '/';
	$footer      = '';

	if ( $occurrences > 0 ) {
		$footer .= "Occurred *$occurrences time(s)* since last reported";
	}

	switch ( $err_no ) {
		case E_ERROR:
		case E_PARSE:
		case E_CORE_ERROR:
		case E_COMPILE_ERROR:
		case E_USER_ERROR:
		default:
			$color = '#ff0000'; // Red.
			break;
		case E_WARNING:
		case E_CORE_WARNING:
		case E_COMPILE_WARNING:
		case E_USER_WARNING:
			$color = '#ffa500'; // Orange.
			break;
		case E_NOTICE:
		case E_USER_NOTICE:
		case E_STRICT:
		case E_DEPRECATED:
		case E_USER_DEPRECATED:
			$color = '#ffff00'; // Yellow.
			break;
	}

	$fields = array(
		array(
			'title' => 'Domain',
			'value' => $domain,
			'short' => false,
		),
		array(
			'title' => 'Page',
			'value' => $page_slug,
			'short' => false,
		),
		array(
			'title' => 'File',
			'value' => "$file:$line",
			'short' => false,
		)
	);

	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$fields[] = array(
			'title' => 'Referer',
			'value' => esc_url_raw( $_SERVER['HTTP_REFERER'] ),
			'short' => false,
		);
	}

	if ( $_POST ) {
		$redacted_post = $_POST; // redact_keys() would redact $_POST if passed directly.
		redact_keys( $redacted_post );

		$fields[] = array(
			'title' => 'POST',
			'value' => print_r( $redacted_post, true ),
			'short' => false,
		);
	}

	// Fatals can only be caught with `register_shutdown_function()`, but that doesn't have access to the call
	// stack of the previous script. It would only show the stack of the current script, which isn't useful.
	if ( ! is_fatal_error( $err_no ) ) {
		$fields[] = array(
			'title' => 'Stack Trace',
			'value' => wp_debug_backtrace_summary(),
			'short' => false,
		);
	}

	$attachment = array(
		'fallback'    => $text,
		'text'        => $text,
		'author_name' => $error_name,
		'color'       => $color,
		'fields'      => $fields,
		'footer'      => $footer,
	);

	$slack = new Send( SLACK_ERROR_REPORT_URL );
	$slack->add_attachment( $attachment );

	$channels = get_destination_channels( $file, WORDCAMP_ENVIRONMENT, is_fatal_error( $err_no ) );

	foreach ( $channels as $channel ) {
		$slack->send( $channel );
	}
}

/**
 * Determine which channels the error should be sent to.
 *
 * @param string $file
 * @param string $environment
 * @param bool   $is_fatal_error
 *
 * @return array
 */
function get_destination_channels( $file, $environment, $is_fatal_error ) {
	$channels           = array();
	$is_jetpack_error   = false !== stripos( $file, WP_PLUGIN_DIR . '/jetpack/' );
	$is_gutenberg_error = false !== stripos( $file, WP_PLUGIN_DIR . '/gutenberg/' );

	switch ( $environment ) {
		case 'production':
			// Send all Jetpack & Gutenberg errors to those teams. Only send fatals to us.
			if ( $is_jetpack_error ) {
				$channels[] = WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL;

				if ( $is_fatal_error ) {
					$channels[] = WORDCAMP_LOGS_SLACK_CHANNEL;
				}

			} elseif ( $is_gutenberg_error ) {
				$channels[] = WORDCAMP_LOGS_GUTENBERG_SLACK_CHANNEL;

				if ( $is_fatal_error ) {
					$channels[] = WORDCAMP_LOGS_SLACK_CHANNEL;
				}

			} else {
				$channels[] = WORDCAMP_LOGS_SLACK_CHANNEL;
			}

			break;

		case 'development':
			if ( ! $is_jetpack_error && ! $is_gutenberg_error ) {
				if ( defined( 'SANDBOX_SLACK_USERNAME' ) ) {
					$channels[] = SANDBOX_SLACK_USERNAME;
				}
			}

			break;

		case 'local':
		default:
			// Intentionally empty.
			break;
	}

	return $channels;
}

/**
 * Check and create the filesystem directory used to manage error rate limiting.
 *
 * For legacy bugs we are doing rate limiting via filesystem. We would be investigating to see if we can instead use
 * memcache to rate limit sometime in the future.
 *
 * @return bool Return true if file permissions etc are present.
 */
function check_error_handling_dependencies() {
	if ( ! file_exists( ERROR_RATE_LIMITING_DIR ) ) {
		mkdir( ERROR_RATE_LIMITING_DIR );
	}

	return is_dir( ERROR_RATE_LIMITING_DIR ) && is_writeable( ERROR_RATE_LIMITING_DIR );
}

/**
 * Remove temporary error rate limiting files.
 *
 * Function `record_error` above also creates a bunch of files in /tmp/error_limiting folder in order to rate limit
 * the notification. This function will be used as a cron to clear these error_limiting files periodically.
 *
 * @return void
 */
function handle_clear_error_rate_limiting_files() {
	// This only needs to run on one site.
	if ( BLOG_ID_CURRENT_SITE !== get_current_blog_id() ) {
		return;
	}

	if ( ! check_error_handling_dependencies() ) {
		return;
	}

	foreach ( new DirectoryIterator( ERROR_RATE_LIMITING_DIR ) as $file_info ) {
		if ( ! $file_info->isDot() ) {
			unlink( $file_info->getPathname() );
		}
	}
}

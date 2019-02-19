<?php
defined( 'WPINC' ) or die();

define( 'ERROR_RATE_LIMITING_DIR', '/tmp/error_limiting' );

if ( is_readable( __DIR__ . '/includes/slack/send.php' ) ) {
	require_once( __DIR__ . '/includes/slack/send.php' );
}

set_error_handler( 'send_error_to_slack' );
register_shutdown_function( 'send_fatal_to_slack' );

/**
 * Error handler to send errors to Slack.
 *
 * Note: This should always return false so that default error handling still occurs as well.
 *
 * @todo We should consider splitting the functionality here into two or more separate functions.
 *       This way logging errors to files could be separate from sending messages to Slack. This would
 *       also potentially allow us to load the error logging part earlier, before WP functions
 *       are available.
 *
 * @param int    $err_no
 * @param string $err_msg
 * @param string $file
 * @param int    $line
 *
 * @return bool
 */
function send_error_to_slack( $err_no, $err_msg, $file, $line ) {
	if ( ! defined( 'WORDCAMP_ENVIRONMENT' )
	     || ( 'production' !==  WORDCAMP_ENVIRONMENT && ! defined( 'SANDBOX_SLACK_USERNAME' ) )
	) {
		return false;
	}

	if ( ! init_error_handling() ) {
		return false;
	}

	// Checks to see if the error-throwing expression is prepended with the @ control operator.
	// See https://secure.php.net/manual/en/function.set-error-handler.php
	if ( 0 === error_reporting() ) {
		return false;
	}

	$error_safelist = [
		E_ERROR,
		E_USER_ERROR,
		E_CORE_ERROR,
		E_COMPILE_ERROR,
		E_PARSE,
		E_NOTICE,
		E_DEPRECATED,
		E_WARNING,
	];

	if ( ! in_array( $err_no, $error_safelist ) ) {
		return false;
	}

	// Always use constants in the keys here to avoid path disclosure.
	$error_ignorelist = [
		// See https://core.trac.wordpress.org/ticket/29204
		ABSPATH . 'wp-includes/SimplePie/Registry.php:215' => 'Non-static method WP_Feed_Cache::create() should not be called statically',
	];

	if ( isset( $error_ignorelist[ "$file:$line" ] ) && false !== strpos( $err_msg, $error_ignorelist[ "$file:$line" ] ) ) {
		return false;
	}

	$err_key    = substr( base64_encode("$file-$line-$err_no" ), -254 ); // Max file length for ubuntu is 255.
	$error_file = ERROR_RATE_LIMITING_DIR . "/$err_key";
	$text       = '';
	$data       = array(
		'last_reported_at' => time(),
		'error_count'      => 0, // since last reported.
	);

	if ( ! file_exists( $error_file ) ) {
		$text .= '[Error]';
		file_put_contents( $error_file, wp_json_encode( $data ) );
	} else {
		$data                 = json_decode( file_get_contents( $error_file ), true );
		$data['error_count'] += 1;
		$time_elasped         = time() - $data['last_reported_at'];

		if ( $time_elasped > 600 ) {
			$text                     .= "[Repeating Error] ${data['error_count']} time(s) since last reported.";
			$data['last_reported_at']  = time();
			$data['error_count']       = 0;

			file_put_contents( $error_file, wp_json_encode( $data ) );
		} else {
			file_put_contents( $error_file, wp_json_encode( $data ) );
			return false;
		}
	}

	$domain    = get_site_url();
	$page_slug = esc_html( trim( $_SERVER['REQUEST_URI'], '/' ) );

	$text .= " Message: \"$err_msg\" occurred on \"$file:$line\" \n Domain: $domain \n Page: $page_slug \n Error type: $err_no ";

	$message = array(
		'fallback'    => $text,
		'color'       => '#ff0000',
		'pretext'     => "Error on \"$file:$line\" ",
		'author_name' => $domain,
		'text'        => $text,
	);

	$send = new \Dotorg\Slack\Send( SLACK_ERROR_REPORT_URL );
	$send->add_attachment( $message );

	if ( 'production' === WORDCAMP_ENVIRONMENT ) {
		$send->send( WORDCAMP_LOGS_SLACK_CHANNEL );
	} else {
		$send->send( SANDBOX_SLACK_USERNAME );
	}

	return false;
}

/**
 * Shutdown handler for catching fatal errors and sending them to Slack.
 *
 * Some error types cannot be handled directly by a custom error handler. However, we can catch them during shutdown
 * and redirect them to the custom handler callback.
 */
function send_fatal_to_slack() {
	$error = error_get_last();

	// See https://secure.php.net/manual/en/function.set-error-handler.php
	$unhandled_error_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING ];

	if ( ! empty( $error ) && in_array( $error['type'], $unhandled_error_types, true ) ) {
		send_error_to_slack( $error['type'], $error['message'], $error['file'], $error['line'] );
	}
}

/**
 * Check and create filesystem dirs to manage rate limiting in error handling.
 *
 * For legacy bugs we are doing rate limiting via filesystem. We would be investigating to see if we can instead use memcache to rate limit sometime in the future.
 *
 * @return bool Return true if file permissions etc are present
 */
function init_error_handling() {
	if ( ! file_exists( ERROR_RATE_LIMITING_DIR ) ) {
		mkdir( ERROR_RATE_LIMITING_DIR );
	}

	return is_dir( ERROR_RATE_LIMITING_DIR ) && is_writeable( ERROR_RATE_LIMITING_DIR );
}

/**
 * Remove temporary error rate limiting files.
 *
 * Function `send_error_to_slack` above also creates a bunch of files in /tmp/error_limiting folder in order to rate limit the notification.
 * This function will be used as a cron to clear these error_limiting files periodically.
 */
function handle_clear_error_rate_limiting_files() {
	if ( ! init_error_handling() ) {
		return;
	}

	foreach ( new DirectoryIterator( ERROR_RATE_LIMITING_DIR ) as $file_info ) {
		if ( ! $file_info->isDot() ) {
			unlink( $file_info->getPathname() );
		}
	}
}

if ( ! wp_next_scheduled( 'clear_error_rate_limiting_files' ) ) {
	wp_schedule_event( time(), 'daily', 'clear_error_rate_limiting_files' );
}

add_action( 'clear_error_rate_limiting_files', 'handle_clear_error_rate_limiting_files' );

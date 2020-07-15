<?php
namespace WordCamp\Error_Handling;
defined( 'WPINC' ) || die();

use DirectoryIterator;
use Dotorg\Slack\Send;

const ERROR_RATE_LIMITING_DIR = '/tmp/error_limiting';

set_error_handler( __NAMESPACE__ . '\handle_error' );
register_shutdown_function( __NAMESPACE__ . '\catch_fatal' );

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

	$error_ignorelist = get_ignorelist();

	if ( isset( $error_ignorelist[ $file ] ) ) {
		$matches = array_filter(
			$error_ignorelist[ $file ],
			function( $pattern ) use ( $err_msg ) {
				return false !== strpos( $err_msg, $pattern );
			}
		);

		if ( ! empty( $matches ) ) {
			return false;
		}
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

		if ( $time_elapsed > 600 ) {
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
 * List of warnings/notices to ignore because they aren't actionable.
 *
 * Always use constants in the keys here to avoid path disclosure.
 *
 * Some constants here will require a trailing slash, and some won't. Avoid adding an extra slash if one
 * already exists in the constant itself, because double-slashes will prevent the string from matching.
 *
 * The line number is omitted from the filename key because it can change when edits are made to other parts of
 * the file, which would cause the warning/notice to start appearing again.
 *
 * @return array Associative array. Key is filename, value is array of error message patterns to match.
 */
function get_ignorelist() {
	return array(
		// See https://core.trac.wordpress.org/ticket/29204.
		ABSPATH . 'wp-includes/SimplePie/Registry.php' => array(
			'Non-static method WP_Feed_Cache::create() should not be called statically',
		),

		// This is normal.
		WP_PLUGIN_DIR . '/hyperdb/db.php' => array(
			'mysqli_query(): MySQL server has gone away',
		),

		// These are trivial mistakes in 3rd party code. They indicate poor quality, but don't warrant action.
		ABSPATH . 'wp-cron.php'                            => array(
			'Invalid argument supplied for foreach()',
		),
		ABSPATH . 'wp-includes/class-wp-query.php'         => array(
			'trim() expects parameter 1 to be string, array given',
			"Trying to get property 'ID' of non-object",
			"Trying to get property 'post_title' of non-object",
			"Trying to get property 'post_name' of non-object",
		),
		ABSPATH . 'wp-includes/class-wp-post.php'          => array(
			'Undefined property: WP_Post::$filter',
		),
		ABSPATH . 'wp-includes/class-wp-xmlrpc-server.php' => array(
			'Undefined variable: url',
		),
		ABSPATH . 'wp-includes/comment-template.php'       => array(
			"Trying to get property 'comment_ID' of non-object",
			"Trying to get property 'comment_status' of non-object",
			"Trying to get property 'user_id' of non-object",
		),
		ABSPATH . 'wp-includes/link-template.php'          => array(
			"Trying to get property 'post_type' of non-object",
		),
		ABSPATH . 'wp-includes/post-template.php'          => array(
			"Trying to get property 'post_content' of non-object",
		),
		ABSPATH . 'wp-includes/rest-api.php' => array(
			'Undefined index: items',
		),
		ABSPATH . 'wp-includes/rss.php'                    => array(
			'Undefined index: description',
			'Undefined property: stdClass::$error',
		),

		WP_PLUGIN_DIR . '/camptix-paystack/includes/class-paystack.php'                       => array(
			'Undefined variable: txn',
		),
		WP_PLUGIN_DIR . '/gutenberg/build/block-library/blocks/latest-posts.php' => array(
			'array_column() expects parameter 1 to be array, string given',
		),
	);
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
	$unhandled_error_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING ];

	if ( ! empty( $error ) && in_array( $error['type'], $unhandled_error_types, true ) ) {
		handle_error( $error['type'], $error['message'], $error['file'], $error['line'] );
	}
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
	if ( ! defined( 'WORDCAMP_ENVIRONMENT' )
		|| 'local' === WORDCAMP_ENVIRONMENT
		|| ! is_readable( __DIR__ . '/includes/slack/send.php' )
	) {
		return;
	}

	require_once( __DIR__ . '/includes/slack/send.php' );

	$error_name  = array_search( $err_no, get_defined_constants( true )['Core'] ) ?: '';
	$messages    = explode( 'Stack trace:', $err_msg, 2 );
	$text        = ( ! empty( $messages[0] ) ) ? trim( sanitize_text_field( $messages[0] ) ) : '';
	$stack_trace = wp_debug_backtrace_summary();
	$domain      = esc_url( get_site_url() );
	$page_slug   = sanitize_text_field( untrailingslashit( $_SERVER['REQUEST_URI'] ) ) ?: '/';
	$footer      = '';
	$is_fatal_error = false;

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
			$is_fatal_error = true;
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

	$fields = [
		[
			'title' => 'Domain',
			'value' => $domain,
			'short' => false,
		],
		[
			'title' => 'Page',
			'value' => $page_slug,
			'short' => false,
		],
		[
			'title' => 'File',
			'value' => "$file:$line",
			'short' => false,
		],
		[
			'title' => 'Stack Trace',
			'value' => $stack_trace,
			'short' => false,
		],
	];

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

	if ( 'production' === WORDCAMP_ENVIRONMENT ) {
		$is_jetpack_error     = false !== stripos( $file, WP_PLUGIN_DIR . '/jetpack/' );
		$is_gutenberg_error   = false !== stripos( $file, WP_PLUGIN_DIR . '/gutenberg/' );

		// Send all Jetpack errors to the Jetpack team. Only send fatals to us.
		if ( $is_jetpack_error ) {
			$slack->send( WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL );

			if ( $is_fatal_error ) {
				$slack->send( WORDCAMP_LOGS_SLACK_CHANNEL );
			}

		} elseif ( $is_gutenberg_error ) {
			$slack->send( WORDCAMP_LOGS_GUTENBERG_SLACK_CHANNEL);

			if ( $is_fatal_error ) {
				$slack->send( WORDCAMP_LOGS_SLACK_CHANNEL );
			}

		} else {
			$slack->send( WORDCAMP_LOGS_SLACK_CHANNEL );
		}

	} else {
		if ( 'development' === WORDCAMP_ENVIRONMENT && ! defined( 'SANDBOX_SLACK_USERNAME' ) ) {
			define( 'SANDBOX_SLACK_USERNAME', '@' . strstr( gethostname(), '.', true ) );
		}

		$slack->send( SANDBOX_SLACK_USERNAME );
	}
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

if ( ! wp_next_scheduled( 'clear_error_rate_limiting_files' ) ) {
	wp_schedule_event( time(), 'daily', 'clear_error_rate_limiting_files' );
}

add_action( 'clear_error_rate_limiting_files', __NAMESPACE__ . '\handle_clear_error_rate_limiting_files' );

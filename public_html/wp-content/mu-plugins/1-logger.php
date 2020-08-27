<?php

namespace WordCamp\Logger;
use function WordCamp\Error_Handling\{ update_error_record, send_error_to_slack };

defined( 'WPINC' ) or die();

/**
 * Log an event, with optional additional data
 *
 * SECURITY WARNING: You must redact any sensitive info before it's written to the log file. The best way to do
 * that is to add entries to redact_keys(), so that the caller remain clean. You can also do it before
 * passing the data to this function, though.
 *
 * @todo add a $type param with 'error' and 'info' values. errors continue to go to std error log. info is logged
 *       to separate file, so they don't clutter error log. need to rotate that file. then update callers to use
 *       new param.
 *
 * @param string $error_code
 * @param array  $data
 */
function log( $error_code, $data = array() ) {
	$backtrace = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 2 );

	$attachment_fields = array(
		array(
			'title' => 'Error Code',
			'value' => $error_code,
			'short' => false,
		),

//		array(
//			'title' => 'Request ID',
//			'value' => get_unique_request_id(),
//			'short' => false,
//		),
		// not really needed?

		array(
			'title' => 'Location',
			'value' => sprintf(
				'%s() -- %s:%s',
				$backtrace[1]['function'],
				$backtrace[0]['file'],
				$backtrace[0]['line']
			),
			'short' => false,
		),

		array(
			'title' => 'Backtrace',
			'value' => print_r( $backtrace, true ),
			'short' => false,
		),
	);

	if ( 'cli' === php_sapi_name() ) {
		$attachment_fields[] = array(
			'title' => 'CLI Command',
			'value' => sprintf( '%s %s', $_SERVER['_'] ?? '', implode( ' ', $_SERVER['argv'] ) ),
			'short' => false,
		);

	} else {
		$attachment_fields[] = array(
			'title' => 'Request URL',
			'value' => sprintf(
				'%s://%s%s',
				( empty( $_SERVER['HTTPS'] ) ) ? 'http' : 'https',
				$_SERVER['SERVER_NAME'],
				$_SERVER['REQUEST_URI']
			),
			'short' => false,
		);

		// Fall back to IP address if it's too early to detect the user
		if ( did_action( 'after_setup_theme' ) > 0 ) {
			$attachment_fields[] = array(
				'title' => 'Current User ID',
				'value' => get_current_user_id(),
				'short' => false,
			);

		} else {
			$attachment_fields[] = array(
				'title' => 'Requester IP Address',
				'value' => $_SERVER['REMOTE_ADDR'],
				'short' => false,
			);
		}
	}

	redact_keys( $data );

	if ( $data ) {
		$attachment_fields[] = array(
			'title' => 'Error Details',
//			'value' => str_replace( "\n", '[newline]', wp_json_encode( $data ) ),
			'value' => print_r( $data, true ),
			'short' => false,
				// should be short to "show more" ?
		);
	}


	/// ugh is sending this to slack really necessary or helpful?
	/// maybe it'd be better to insert into custom database table
		/// do via API call so non-blocking
	/// then have private page that shows it
	/// can rotate at 10k rows or something, or 1 month
	/// well, frak, that'll probably be more work than

	/// or maybe still write to disk, and just append a file, and have page show contents of that file
	/// i think fwrite-whatever() has rotatting built in?
	/// maybe limit it to 1mb or something like that
	/// could add additional files later on if needed
	///
	/// remove the wc format-log command b/c no longer needed?
	///
	///
	///
	/// all of this feels like reinventing the wheel.
	/// just `composer require monolog`, and write a lean wrapper function around the extraneous OOP stuff, so you have a streamlined function to call
	/// how does it handle slack's api rate limit though?
	///
	///
	///


	/*
	 * monolog
	 *

	log() stays the same maybe, but adds a new param $level that defaults to 'debug'
		can pass in others though
		debug goes to debug channel, everything else goes to errors channel
		can remove some functionality that monolog provides, like adding remote_addr

	HANDLE
	----
	prod + dev
		SlackWebhookHandler
		SlackHandler
		FingersCrossedHandler - for throttling to avoid rate limit? but need to always send, even if only happens onces. just throttle/debounce, but never ignore anything.
		DeduplicationHandler - for duplicate notices etc, but want to know the occurances count like we currently do
		FilterHandler - maybe useful if other handlers aren't enough for rate limiting
		SamplingHandler - maybe useful if other handlers aren't enough for rate limiting

		in dev goes to separate channel though, but only if SLACK_USERNAME_WHATEVER is defined

	local
		BrowserConsoleHandler


	FORMAT
	-----
	JsonFormatter

	PROCES
	-----
	IntrospectionProcessor
	WebProcessor
	ProcessIdProcessor
	UidProcessor


	UTILS
	------
	ErrorHandler: The Monolog\ErrorHandler class allows you to easily register a Logger instance as an exception handler, error handler or fatal error handler.
	ErrorLevelActivationStrategy: Activates a FingersCrossedHandler when a certain log level is reached.
	ChannelLevelActivationStrategy: Activates a FingersCrossedHandler when a certain log level is reached, depending on which channel received the log record.
	 */

	$attachment = array(
		'fallback'    => $error_code . '-fall',
//		'text'        => $error_code . '-text',
		'author_name' => $error_code .'-auth',
		'color'       => '#00A0D2',
		'fields'      => $attachment_fields,
		'footer'      => '',
	);


	if ( 'local' === WORDCAMP_ENVIRONMENT ) {
		error_log( $error_code . wp_json_encode( $attachment ) );
		// ugh frak this messes up the foramt-log command
			// don't need it anymore though? just remove?
		// need to check if longer than `log_errors_max_len` ini ? or just increase that for this?

	} else {
		if ( 'development' === WORDCAMP_ENVIRONMENT && defined( 'SANDBOX_SLACK_USERNAME' ) ) {
			$channels = array( SANDBOX_SLACK_USERNAME );

		} else if ( 'production' === WORDCAMP_ENVIRONMENT ) {
			$channels = array( '#dotorg-wordcamp-info' );
			// setup constant for info channel, commit to capes before commit this
			// rename other to -errors, even if keep this one as -info. ask corey
		}

		/*
		 * Using `$error_code` as a key could create situations where multiple instances of an error happen, with
		 * different `$data`, but those are squashed down into a single entry in Slack. That'd be bad, because it'd
		 * hide potentially useful debugging data. Keeping the `$pause_interval` low mitigates that to some extent,
		 * though.
		 *
		 * If we don't do that, though, then there's the risk that too many errors will happen, especially in bursts
		 * -- e.g.,`gravatar_open_failed`, `insert_post_failed`, etc -- which would result in exceeding Slack's rate
		 * limits, and nothing being recorded at all.
		 */
//		var_dump( WORDCAMP_ENVIRONMENT, SANDBOX_SLACK_USERNAME );
		update_error_record( $error_code, 15 );
//		send_error_to_slack( $attachment, $channels );
		// todo throttling isn't working :(
	}
}

/**
 * Redact sensitive values from log entries
 *
 * Right now this only redacts array keys, but it can be made more sophisticated in the future to handle
 * additional cases.
 *
 * In the future, it may be helpful to decode JSON values and search them for redactable values, and to allow
 * the caller to pass an array of strings that are sensitive and should be redacted. The latter would allow for
 * redacting values that aren't known until runtime, and could be helpful in other cases too.
 *
 * @param array $data
 *
 * @return array
 */
function redact_keys( & $data ) {
	$redacted_keys = array( 'Authorization', 'password', 'user_pass', 'key', 'apikey', 'api_key', 'client_secret' );
	$redacted_keys = array_map( 'strtolower', $redacted_keys ); // to avoid human error

	foreach ( $data as $key => $value ) {
		/*
		 * If an object is cast to an array, the array key for the object's private/protected members will contain
		 * whitespace, which causes json_decode() to ignore it. So instead, just make a simple array.
		 *
		 * The normal backtrace will often be very large, and contain recursive elements, which could lead to a
		 * script timeout. WP's summary is enough for this purpose.
		 */
		if ( is_a( $value, 'Exception' ) ) {
			$value = array(
				'message' => $value->getMessage(),
				'code'    => $value->getCode(),
				'file'    => $value->getFile(),
				'line'    => $value->getLine(),
				'trace'   => wp_debug_backtrace_summary()
			);
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( false !== filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$url_parts = parse_url( $value );

			if ( ! empty( $url_parts['query'] ) ) {
				parse_str( $url_parts['query'], $query );
				$url_parts['query'] = redact_keys( $query );
				$value              = $url_parts;
			}
		}

		if ( in_array( strtolower( $key ), $redacted_keys, true ) ) {
			 $data[ $key ] = '[redacted]';
		}

		if ( is_array( $value ) ) {
			$data[ $key ] = redact_keys( $value );
		}
	}

	return $data;
}

/**
 * Generate a unique ID for the current request
 *
 * This is useful when debugging race conditions, etc, so that you can identify which log entries belong to each thread.
 *
 * Based on https://stackoverflow.com/a/22508709/450127
 *
 * @return string
 */
function get_unique_request_id() {
	if ( 'cli' === php_sapi_name() ) {
		$caller = $_SERVER['USER'] . ( $_SERVER['SSH_CONNECTION'] ?? '' );
	} else {
		$caller = $_SERVER['REMOTE_ADDR'] . $_SERVER['REMOTE_PORT'];
	}

	return hash( 'crc32b', $caller . $_SERVER['REQUEST_TIME_FLOAT'] );
}

/**
 * Format entries created with log()
 *
 * See WordCamp_CLI_Miscellaneous::format_log() for usage instructions.
 *
 * @param string $raw_log
 * @param string $foreign_entries `ignore` or `include` entries that weren't created with log()
 *
 * @return string
 */
function format_log( $raw_log, $foreign_entries = 'include' ) {
	$formatted_log        = '';
	$raw_entries          = explode( "\n", $raw_log );
	$native_entry_pattern = '/(\[.*?\]) (\[\w+\]) (.*?:.*?) - (.*?) -- (\{.*\})/';

	foreach ( $raw_entries as $entry ) {
		$is_native_entry = 1 === preg_match( $native_entry_pattern, $entry, $entry_parts );
		// todo bug: this should be recognized as native -- [18-Jan-2017 00:33:04 UTC] [b1955769] source-site-id-setting.php:29 - preview:post_val -- [null]

		if ( $is_native_entry ) {
			$formatted_log .= sprintf(
				"\n%s %s %s - %s\n%s",
				$entry_parts[1],
				$entry_parts[2],
				$entry_parts[3],
				$entry_parts[4],
				print_r( json_decode( $entry_parts[5], true ), true )
			);
		} else {
			if ( 'ignore' !== $foreign_entries ) {
				$formatted_log .= $entry . "\n";
			}
		}
	}

	return $formatted_log;
}

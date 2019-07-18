<?php
namespace WordCamp\Utilities;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Class Meetup_Client
 */
class Meetup_Client extends API_Client {
	/**
	 * @var string The base URL for the API endpoints.
	 */
	protected $api_base = 'https://api.meetup.com/';

	/**
	 * @var string The API key.
	 */
	protected $api_key = '';

	/**
	 * @var bool If true, the client will fetch fewer results, for faster debugging.
	 */
	protected $debug_mode;

	/**
	 * Meetup_Client constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			/*
			 * Response codes that should break the request loop.
			 *
			 * See https://www.meetup.com/meetup_api/docs/#errors.
			 *
			 * `200` (ok) is not in the list, because it needs to be handled conditionally.
			 *  See API_Client::tenacious_remote_request.
			 *
			 * `400` (bad request) is not in the list, even though it seems like it _should_ indicate an unrecoverable
			 * error. In practice we've observed that it's common for a seemingly valid request to be rejected with
			 * a `400` response, but then get a `200` response if that exact same request is retried.
			 */
			'breaking_response_codes' => array(
				401, // Unauthorized (invalid key).
				429, // Too many requests (rate-limited).
				404, // Unable to find group
			),
			'throttle_callback'       => array( $this, 'maybe_throttle' ),
		) );

		if ( defined( 'MEETUP_API_KEY' ) ) {
			$this->api_key = MEETUP_API_KEY;
		} else {
			$this->error->add(
				'api_key_undefined',
				'The Meetup.com API Key is undefined.'
			);
		}

		$this->debug_mode = apply_filters( 'wcmc_debug_mode', false );
	}

	/**
	 * Send a paginated request to the Meetup API and return the aggregated response.
	 *
	 * This automatically paginates requests and will repeat requests to ensure all results are retrieved.
	 * It also tries to account for API request limits and throttles to avoid getting a limit error.
	 *
	 * @param string $request_url The API endpoint URL to send the request to.
	 *
	 * @return array|WP_Error The results of the request.
	 */
	protected function send_paginated_request( $request_url ) {
		$data = array();

		$request_url = add_query_arg( array(
			'page' => 200,
		), $request_url );

		while ( $request_url ) {
			$request_url = $this->sign_request_url( $request_url );

			$response = $this->tenacious_remote_get( $request_url );

			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( isset( $body['results'] ) ) {
					$new_data = $body['results'];
				} else {
					$new_data = $body;
				}

				if ( is_array( $new_data ) ) {
					$data = array_merge( $data, $new_data );
				} else {
					$this->error->add(
						'unexpected_response_data',
						'The API response did not provide the expected data format.',
						$response
					);
					break;
				}

				$request_url = $this->get_next_url( $response );
			} else {
				$this->handle_error_response( $response );
				break;
			}

			if ( $this->debug_mode ) {
				break;
			}
		}

		if ( ! empty( $this->error->get_error_messages() ) ) {
			return $this->error;
		}

		return $data;
	}

	/**
	 * Send a single request to the Meetup API and return the total number of results available.
	 *
	 * @param string $request_url The API endpoint URL to send the request to.
	 *
	 * @return int|WP_Error
	 */
	protected function send_total_count_request( $request_url ) {
		$count = 0;

		$request_url = add_query_arg( array(
			// We're only interested in the headers, so we don't need to receive more than one result.
			'page' => 1,
		), $request_url );

		$request_url = $this->sign_request_url( $request_url );

		$response = $this->tenacious_remote_get( $request_url );

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$count_header = wp_remote_retrieve_header( $response, 'X-Total-Count' );

			if ( $count_header ) {
				$count = absint( $count_header );
			} else {
				$this->error->add(
					'unexpected_response_data',
					'The API response did not provide a total count value.'
				);
			}
		} else {
			$this->handle_error_response( $response );
		}

		if ( ! empty( $this->error->get_error_messages() ) ) {
			return $this->error;
		}

		return $count;
	}

	/**
	 * Sign a request URL with our API key.
	 *
	 * @param string $request_url
	 *
	 * @return string
	 */
	protected function sign_request_url( $request_url ) {
		return add_query_arg( array(
			'sign' => true,
			'key'  => $this->api_key,
		), $request_url );
	}

	/**
	 * Get the URL for the next page of results from a paginated API response.
	 *
	 * @param array $response
	 *
	 * @return string
	 */
	protected function get_next_url( $response ) {
		$url = '';

		// First try v3.
		$links = wp_remote_retrieve_header( $response, 'link' );
		if ( $links ) {
			// Meetup.com is now returning combined link headers
			if ( is_string( $links ) ) {
				$links = preg_split( '!,\s+!', $links );
			}
			foreach ( (array) $links as $link ) {
				if ( false !== strpos( $link, 'rel="next"' ) && preg_match( '/^<([^>]+)>/', $link, $matches ) ) {
					$url = $matches[1];
					break;
				}
			}
		}

		// Then try v2.
		if ( ! $url ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['meta']['next'] ) ) {
				$url = $body['meta']['next'];
			}
		}

		return esc_url_raw( $url );
	}

	/**
	 * Check the rate limit status in an API response and delay further execution if necessary.
	 *
	 * @param array $headers
	 */
	protected function maybe_throttle( $response ) {
		$headers = wp_remote_retrieve_headers( $response );

		if ( ! isset( $headers['x-ratelimit-remaining'], $headers['x-ratelimit-reset'] ) ) {
			return;
		}

		$remaining = absint( $headers['x-ratelimit-remaining'] );
		$period    = absint( $headers['x-ratelimit-reset'] );

		// Pause more frequently than we need to, and for longer, just to be safe.
		if ( $remaining > 2 ) {
			return;
		}

		if ( $period < 2 ) {
			$period = 2;
		}

		$this->cli_message( "\nPausing for $period seconds to avoid rate-limiting." );

		sleep( $period );
	}

	/**
	 * Extract error information from an API response and add it to our error handler.
	 *
	 * @param array|WP_Error $response
	 *
	 * @return void
	 */
	public function handle_error_response( $response ) {
		if ( parent::handle_error_response( $response ) ) {
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$data          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['errors'] ) ) {
			foreach ( $data['errors'] as $error ) {
				$this->error->add( $error['code'], $error['message'] );
			}
		} elseif ( isset( $data['code'] ) && isset( $data['details'] ) ) {
			$this->error->add( $data['code'], $data['details'] );
		} elseif ( $response_code ) {
			$this->error->add(
				'http_response_code',
				sprintf( 'HTTP Status: %d', absint( $response_code ) )
			);
		} else {
			$this->error->add( 'unknown_error', 'There was an unknown error.' );
		}
	}

	/**
	 * Retrieve data about groups in the Chapter program.
	 *
	 * @param array $args Optional. Additional request parameters.
	 *                    See https://www.meetup.com/meetup_api/docs/pro/:urlname/groups/.
	 *
	 * @return array|WP_Error
	 */
	public function get_groups( array $args = array() ) {
		$request_url = $this->api_base . 'pro/wordpress/groups';

		if ( ! empty( $args ) ) {
			$request_url = add_query_arg( $args, $request_url );
		}

		return $this->send_paginated_request( $request_url );
	}

	/**
	 * Retrieve data about events associated with a set of groups.
	 *
	 * This automatically breaks up requests into chunks of 50 groups to avoid overloading the API.
	 *
	 * @param array $group_ids The IDs of the groups to get events for.
	 * @param array $args      Optional. Additional request parameters.
	 *                         See https://www.meetup.com/meetup_api/docs/2/events/.
	 *
	 * @return array|WP_Error
	 */
	public function get_events( array $group_ids, array $args = array() ) {
		$url_base     = $this->api_base . '2/events';
		$group_chunks = array_chunk( $group_ids, 50, true ); // Meetup API sometimes throws an error with chunk size larger than 50.
		$events       = array();

		foreach ( $group_chunks as $chunk ) {
			$query_args = array_merge( array(
				'group_id' => implode( ',', $chunk ),
			), $args );

			$request_url = add_query_arg( $query_args, $url_base );

			$data = $this->send_paginated_request( $request_url );

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$events = array_merge( $events, $data );
		}

		return $events;
	}

	/**
	 * Retrieve data about the group. Calls https://www.meetup.com/meetup_api/docs/:urlname/#get
	 *
	 * @param string $group_slug The slug/urlname of a group.
	 * @param array  $args       Optional. Additional request parameters.
	 *
	 * @return array|WP_Error
	 */
	public function get_group_details( $group_slug, $args = array() ) {
		$request_url = $this->api_base . "$group_slug";

		if ( ! empty( $args ) ) {
			$request_url = add_query_arg( $args, $request_url );
		}

		return $this->send_paginated_request( $request_url );
	}

	/**
	 * Retrieve group members. Calls https://www.meetup.com/meetup_api/docs/:urlname/members/#list
	 *
	 * @param string $group_slug The slug/urlname of a group.
	 * @param array  $args       Optional. Additional request parameters.
	 *
	 * @return array|WP_Error
	 */
	public function get_group_members( $group_slug, $args = array() ) {
		$request_url = $this->api_base . "$group_slug/members";

		if ( ! empty( $args ) ) {
			$request_url = add_query_arg( $args, $request_url );
		}

		return $this->send_paginated_request( $request_url );
	}

	/**
	 * Retrieve data about events associated with one particular group.
	 *
	 * @param string $group_slug The slug/urlname of a group.
	 * @param array  $args       Optional. Additional request parameters.
	 *                           See https://www.meetup.com/meetup_api/docs/:urlname/events/.
	 *
	 * @return array|WP_Error
	 */
	public function get_group_events( $group_slug, array $args = array() ) {
		$request_url = $this->api_base . "$group_slug/events";

		if ( ! empty( $args ) ) {
			$request_url = add_query_arg( $args, $request_url );
		}

		return $this->send_paginated_request( $request_url );
	}

	/**
	 * Find out how many results are available for a particular request.
	 *
	 * @param string $route The Meetup.com API route to send a request to.
	 * @param array  $args  Optional. Additional request parameters.
	 *                      See https://www.meetup.com/meetup_api/docs/.
	 *
	 * @return int|WP_Error
	 */
	public function get_result_count( $route, array $args = array() ) {
		$request_url = $this->api_base . $route;

		if ( ! empty( $args ) ) {
			$request_url = add_query_arg( $args, $request_url );
		}

		return $this->send_total_count_request( $request_url );
	}
}

<?php

use function WordCamp\Sunrise\get_domain_redirects;

/**
 * A helper plugin for our integration with Let's Encrypt.
 */
class WordCamp_Lets_Encrypt_Helper {
	/**
	 * Initialize
	 */
	public static function load() {
		if ( ! is_main_site() ) {
			return;
		}

		add_filter( 'rest_api_init', array( __CLASS__, 'rest_api_init' ) );
	}

	/**
	 * Register REST API endpoints
	 */
	public static function rest_api_init() {
		register_rest_route(
			'wordcamp-letsencrypt/v1',
			'/domains-dehydrated',
			array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'rest_callback_domains_dehydrated' ),
			)
		);
	}

	/**
	 * Return an array of all domains that need SSL certs.
	 *
	 * @return array
	 */
	public static function get_domains() {
		global $wpdb;

		$domains = array();
		$blogs   = $wpdb->get_results( "
			SELECT `domain`, `path`
			FROM `$wpdb->blogs`
			WHERE
				`public`  = 1 AND
				`deleted` = 0
			ORDER BY `blog_id` ASC",
			ARRAY_A
		);

		foreach ( $blogs as $blog ) {
			if ( preg_match( '#^[0-9]{4}(?:-[^\.])?\.([^\.]+)\.wordcamp\.org$#i', $blog['domain'], $matches ) ) {
				$domains[] = sprintf( '%s.wordcamp.org', $matches[1] );
			}

			$domains[] = $blog['domain'];

			// While transitioning from city.wordcamp.org/year-extra.
			if ( preg_match( '#^([^\.]+)\.wordcamp.org/([0-9]{4}(?:-[^\.])?)/?$#i', $blog['domain'] . $blog['path'], $matches ) ) {
				$domains[] = sprintf( '%s.%s.wordcamp.org', $matches[2], $matches[1] );
			}
		}

		// Back-compat domains. Note: is_callable() requires the full path, it doesn't follow namespace imports.
		if ( is_callable( 'WordCamp\Sunrise\get_domain_redirects' ) ) {
			$back_compat_domains = get_domain_redirects();

			$domains = array_merge( $domains, array_keys( $back_compat_domains ) );
		}

		$domains = array_unique( $domains );
		$domains = apply_filters( 'wordcamp_letsencrypt_domains', $domains );

		return array_values( $domains );
	}

	/**
	 * REST: domains-dehydrated
	 *
	 * Return a dehydrated domains.txt file of all domains that need SSL certs, in a format suitable for dehydrated.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|void
	 */
	public static function rest_callback_domains_dehydrated( $request ) {
		if ( WORDCAMP_LE_HELPER_API_KEY !== $request->get_param( 'api_key' ) ) {
			return new WP_Error( 'error', 'Invalid or empty key.', array( 'status' => 403 ) );
		}

		$domains = self::get_domains();

		// Sort domains by shortest first, sort all same-length domains by natcase.
		usort( $domains, function( $a, $b ) {
			$a_len = strlen( $a );
			$b_len = strlen( $b );

			if ( $a_len === $b_len ) {
				return strnatcasecmp( $a, $b );
			}

			return $a_len - $b_len;
		} );

		// Group all the subdomains together with their "parent" (xyz.campevent.tld)
		$result = array();
		foreach ( $domains as $domain ) {
			$dots = substr_count( $domain, '.' );

			if ( $dots <= 2 ) {
				// Special cases
				if ( 'central.wordcamp.org' === $domain ) {
					$result['wordcamp.org'][] = $domain;

				} elseif ( in_array( $domain, [ '2006.wordcamp.org', '2007.wordcamporg', 'wordcampsf.org', 'wordcampsf.com' ] ) ) {
					$result['sf.wordcamp.org'][] = $domain;
					$result['sf.wordcamp.org'][] = "www.{$domain}";

				} elseif ( ! isset( $result[ $domain ] ) ) {
					// Main domain
					$result[ $domain ] = array();
				}

			} else {
				// Strip anything before xyz.campevent.tld
				$main_domain              = implode( '.', array_slice( explode( '.', $domain ), - 3 ) );
				$result[ $main_domain ][] = $domain;
			}
		}

		// flatten and output in a dehydrated format.
		header( 'Content-type: text/plain' );

		// Primary Domain \s certAltNames
		// narnia.wordcamp.org www.narnia.wordcamp.org 2020.narnia.wordcamp.org
		foreach ( $result as $domain => $subdomains ) {
			$altnames = implode( ' ', $subdomains );

			echo rtrim( "$domain www.{$domain} $altnames" ) . "\n";
		}

		exit;
	}
}

WordCamp_Lets_Encrypt_Helper::load();

<?php

use function WordCamp\Sunrise\{ get_domain_redirects, get_top_level_domain };
use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH };


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
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_callback_domains_dehydrated' ),
				'permission_callback' => '__return_true',
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

		$tld     = get_top_level_domain();
		$domains = array();
		$blogs   = $wpdb->get_results(
			$wpdb->prepare( "
				SELECT `domain`, `path`
				FROM `$wpdb->blogs`
				WHERE
					`site_id` = %d AND
					`public`  = 1 AND
					`deleted` = 0
				ORDER BY `blog_id` ASC",
				WORDCAMP_NETWORK_ID
			),
			ARRAY_A
		);

		foreach ( $blogs as $blog ) {
			$domains[] = $blog['domain'];

			/*
			 * `year.city.wordcamp.org` sites should have a corresponding `city.wordcamp.test` domain.
			 * `group_domains()` expects this to exist, so that it can group "child" domains under it.
			 *
			 * This also provides a backup for the `*.wordcamp.org` wildcard certificate.
			 */
			if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $blog['domain'] . $blog['path'], $matches ) ) {
				$domains[] = sprintf( "%s.%s.$tld", $matches[2], $matches[3] );
			}

			/*
			 * Sites that were originally created with year.city.wordcamp.org URLs still need certificates for
			 * those domains, so that redirects will work.
			 *
			 * Sites that were created after that also need the certificates for the corresponding `year.city`
			 * domain, because attendees may expect it to exist out of habit. They should be gracefully
			 * redirected rather than getting an SSL error.
			 */
			if ( preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $blog['domain'] . $blog['path'] ) ) {
				$domains[] = sprintf(
					'%s.%s',
					trim( $blog['path'], '/' ),
					$blog['domain']
				);
			}
		}

		// Back-compat domains. Note: is_callable() requires the full path, it doesn't follow namespace imports.
		if ( is_callable( 'WordCamp\Sunrise\get_domain_redirects' ) ) {
			$back_compat_domains = array_keys( get_domain_redirects() );
			$back_compat_domains = self::parse_domain( $back_compat_domains );

			$domains = array_merge( $domains, $back_compat_domains );
		}

		$domains = array_unique( $domains );
		$domains = apply_filters( 'wordcamp_letsencrypt_domains', $domains );

		return array_values( $domains );
	}

	/**
	 * Parse the domain out of a domain + request URI combination.
	 *
	 * e.g., `india.wordcamp.org/2020` becomes `india.wordcamp.org`.
	 *
	 * @param $domains
	 *
	 * @return mixed
	 */
	public static function parse_domain( $domains ) {
		array_walk( $domains, function( & $domain ) {
			$domain = wp_parse_url( "https://$domain", PHP_URL_HOST );
		} );

		return $domains;
	}

	/**
	 * Group domains with their parent domain.
	 *
	 * @param array $domains
	 *
	 * @return array
	 */
	public static function group_domains( $domains ) {
		$tld    = get_top_level_domain();
		$result = array();

		// Sort domains by shortest first, sort all same-length domains by natural case.
		// Later on, this will allow us to create the parent array before adding the children to it.
		usort( $domains, function( $a, $b ) {
			$a_len = strlen( $a );
			$b_len = strlen( $b );

			if ( $a_len === $b_len ) {
				return strnatcasecmp( $a, $b );
			}

			return $a_len - $b_len;
		} );

		// Group all the subdomains together with their "parent" (xyz.campevent.tld)
		foreach ( $domains as $domain ) {
			$dots = substr_count( $domain, '.' );

			if ( $dots <= 2 ) {
				// Special cases
				if ( "central.wordcamp.$tld" === $domain ) {
					$result["wordcamp.$tld"][] = $domain;

				} elseif ( in_array( $domain, [ "2006.wordcamp.$tld", "2007.wordcamp.$tld", 'wordcampsf.org', 'wordcampsf.com'] ) ) {
					$result["sf.wordcamp.$tld"][] = $domain;
					$result["sf.wordcamp.$tld"][] = "www.{$domain}";

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

		return $result;
	}

	/**
	 * REST: domains-dehydrated
	 *
	 * Return a dehydrated domains.txt file of all domains that need SSL certs, in a format suitable for the
	 * dehydrated client.
	 *
	 * @see https://github.com/dehydrated-io/dehydrated/blob/master/docs/domains_txt.md
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|void
	 */
	public static function rest_callback_domains_dehydrated( $request ) {
		if ( WORDCAMP_LE_HELPER_API_KEY !== $request->get_param( 'api_key' ) ) {
			return new WP_Error( 'error', 'Invalid or empty key.', array( 'status' => 403 ) );
		}

		$domains = self::group_domains( self::get_domains() );

		// flatten and output in a dehydrated format.
		header( 'Content-type: text/plain' );

		// Primary Domain \s certAltNames
		// narnia.wordcamp.org www.narnia.wordcamp.org 2020.narnia.wordcamp.org
		foreach ( $domains as $domain => $subdomains ) {
			$altnames = implode( ' ', $subdomains );

			echo rtrim( "$domain www.{$domain} $altnames" ) . "\n";
		}

		exit;
	}
}

WordCamp_Lets_Encrypt_Helper::load();

<?php
/**
 * Website domains: how they're normalized, stored on companies, and matched.
 *
 * A company keeps one normalized domain per meta row, so a match is an exact comparison. The trademark tracker's
 * "is this sponsor domain an open case?" check follows the same normalization rules.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Reduces a website address to its domain: lowercase, without scheme, `www.`, port, path, or trailing dot.
 *
 * @param string $url A website address, with or without its scheme.
 * @return string The domain, or an empty string if there is none.
 */
function normalize_domain( string $url ): string {
	$url = strtolower( trim( $url ) );

	if ( '' === $url ) {
		return '';
	}

	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#', $url ) ) {
		$url = 'http://' . $url;
	}

	$host = rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' );

	if ( str_starts_with( $host, 'www.' ) ) {
		$host = substr( $host, 4 );
	}

	return preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/', $host ) ? $host : '';
}

/**
 * Gets a company's domains.
 *
 * @param int $company_id The company.
 * @return string[]
 */
function get_company_domains( int $company_id ): array {
	return array_map( 'strval', get_post_meta( $company_id, '_sv_domain', false ) );
}

/**
 * Replaces a company's domains.
 *
 * @param int      $company_id The company.
 * @param string[] $urls       Website addresses or domains; ones that don't normalize are dropped.
 */
function set_company_domains( int $company_id, array $urls ): void {
	$domains = array_values( array_unique( array_filter( array_map( __NAMESPACE__ . '\normalize_domain', array_map( 'strval', $urls ) ) ) ) );

	if ( get_company_domains( $company_id ) === $domains ) {
		return;
	}

	delete_post_meta( $company_id, '_sv_domain' );

	foreach ( $domains as $domain ) {
		add_post_meta( $company_id, '_sv_domain', $domain );
	}
}

/**
 * Finds the companies that use a domain.
 *
 * Runs on Central.
 *
 * @param string $domain A normalized domain.
 * @return int[] Company IDs, oldest first.
 */
function find_companies_by_domain( string $domain ): array {
	if ( '' === $domain ) {
		return array();
	}

	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => COMPANY_POST_TYPE,
				// Not `any`: it leaves out statuses that are excluded from search, which all of these are.
				'post_status'    => array_keys( get_company_statuses() ),
				'posts_per_page' => 10,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_sv_domain', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An exact match on one key.
				'meta_value'     => $domain, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An exact match on one key.
			)
		)
	);
}

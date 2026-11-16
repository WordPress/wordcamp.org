<?php
/**
 * Stands in for `sunrise.php`, in the Playground preview of Sponsor Vetting.
 *
 * @package WordCamp\Sponsor_Vetting\Preview
 */

declare( strict_types = 1 );

namespace WordCamp\Sunrise;

defined( 'WPINC' ) || die();

/**
 * Answers with the only network there is.
 *
 * @param string $domain The domain.
 * @param string $path   Optional. The path.
 * @return int
 */
function get_domain_network_id( string $domain, string $path = '' ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The signature is `sunrise.php`'s.
	return get_current_network_id();
}

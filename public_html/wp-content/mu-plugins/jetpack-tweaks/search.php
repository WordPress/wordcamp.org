<?php

namespace WordCamp\Jetpack_Tweaks\Search;

defined( 'WPINC' ) || die();

/**
 * REST route WordPress.com calls to activate the Jetpack Search plan on a site.
 */
const PLAN_ACTIVATE_ROUTE = '/jetpack/v4/search/plan/activate';

add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\disable_instant_search_on_provision', 10, 3 );

/**
 * Disable the Jetpack Search instant-search overlay when it's auto-enabled on a brand-new site.
 *
 * WordCamp sites are auto-provisioned with a Jetpack Complete plan. As part of that, WordPress.com
 * calls the `POST /jetpack/v4/search/plan/activate` REST endpoint, which enables Jetpack Search *and*
 * its instant-search "live results" overlay by default (it writes `instant_search_enabled = true`).
 * That overlay renders broken on WordCamp themes -- it overflows the viewport and overlaps site
 * content (#1742).
 *
 * We hook `rest_request_after_callbacks` so we run *after* the activation has enabled the option, and
 * flip it straight back off. The Search module stays active, so Jetpack falls back to its classic
 * search experience and search keeps working without the broken overlay.
 *
 * The override is scoped to the provisioning window (sites created within the last hour) so that it
 * only undoes the automatic enable that comes with provisioning. A later plan re-activation on an
 * established site is left alone, and the admin "Search settings" UI saves through a different
 * endpoint, so organizers can still opt back in and have it stick.
 *
 * @param mixed            $response The REST response (WP_REST_Response or WP_Error). Returned untouched.
 * @param array            $handler  The matched route handler. Unused.
 * @param \WP_REST_Request $request  The current REST request.
 *
 * @return mixed The unmodified $response.
 */
function disable_instant_search_on_provision( $response, $handler, $request ) {
	if ( ! $request instanceof \WP_REST_Request || PLAN_ACTIVATE_ROUTE !== $request->get_route() ) {
		return $response;
	}

	// Only act on a successful activation (a WP_Error means nothing was enabled), and only during
	// the initial provisioning window.
	if ( is_wp_error( $response ) || ! site_is_newly_created() ) {
		return $response;
	}

	update_option( 'instant_search_enabled', false );

	return $response;
}

/**
 * Whether the current site was created within the last hour.
 *
 * `WP_Site::registered` is stored in GMT and WordPress runs PHP in UTC, so comparing it against
 * `time()` (also UTC) gives a correct age. We treat a missing/zero timestamp as "not new".
 *
 * @return bool
 */
function site_is_newly_created() {
	$site = get_site();

	if ( ! $site || empty( $site->registered ) || '0000-00-00 00:00:00' === $site->registered ) {
		return false;
	}

	$registered = strtotime( $site->registered . ' GMT' );

	if ( ! $registered ) {
		return false;
	}

	return ( time() - $registered ) < HOUR_IN_SECONDS;
}

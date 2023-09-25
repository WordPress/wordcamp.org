<?php

namespace WordPressdotorg\MU_Plugins\Plugin_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_filter( 'wporg_internal_notes_rest_prepare_response', __NAMESPACE__ . '\wporg_internal_notes_replace_rest_author_link' );

/**
 * Replace the Internal Notes default embeddable author link with one from the wporg endpoint.
 *
 * Without this, any note author that isn't a member of the Pattern Directory site will appear as "unknown"
 * on internal notes and logs.
 *
 * @param \WP_REST_Response $response
 *
 * @return \WP_REST_Response
 */
function wporg_internal_notes_replace_rest_author_link( $response ) {
	$response_data = $response->get_data();
	$author = get_user_by( 'id', $response_data['author'] ?? 0 );

	if ( ! $author ) {
		return $response;
	}

	$response->remove_link( 'author' );
	$response->add_link(
		'author',
		rest_url( sprintf( 'wporg/v1/users/%s', $author->user_nicename ) ),
		array(
			'embeddable' => true,
		)
	);

	return $response;
}

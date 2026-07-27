<?php

namespace WordCamp\SpeakerFeedback\Query;

use WP_Comment_Query;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

// Run early (before other plugins' `pre_get_comments` callbacks) so this
// reads the caller's actual requested `type`/`type__in`, not a value another
// plugin has already normalized/expanded. GatherPress, for example, rewrites
// an empty `type` into an explicit list of every comment_type present in the
// table (minus its own) — read at priority 99, that expansion makes it look
// like the caller explicitly asked for feedback comments, defeating this
// exclusion entirely.
add_action( 'pre_get_comments', __NAMESPACE__ . '\pre_get_comments', 1 );

/**
 * Only return feedback comments from the query when that type is specifically called for.
 *
 * @param WP_Comment_Query $query_ref Current instance of WP_Comment_Query (passed by reference).
 *
 * @return void
 */
function pre_get_comments( &$query_ref ) {
	$type_vars = array_intersect_key(
		$query_ref->query_vars,
		array_fill_keys( array( 'type', 'type__in', 'type__not_in' ), '' )
	);

	// Make sure all the type vars are arrays instead of strings.
	$type_vars = array_map(
		function( $var ) {
			return (array) $var;
		},
		$type_vars
	);

	$wants_feedback = in_array( COMMENT_TYPE, array_merge( $type_vars['type'], $type_vars['type__in'] ), true );

	if ( ! $wants_feedback ) {
		$type_vars['type__not_in'][] = COMMENT_TYPE;
	}

	$query_ref->query_vars['type__not_in'] = $type_vars['type__not_in'];
}

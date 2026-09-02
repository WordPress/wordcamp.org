<?php

namespace WordCamp\Sponsor_Agreements;

use WP_Post;

defined( 'WPINC' ) || die();

add_action( 'added_post_meta',              __NAMESPACE__ . '\note_sponsor_agreement',      10, 4 );
add_action( 'updated_post_meta',            __NAMESPACE__ . '\note_sponsor_agreement',      10, 4 );
add_filter( 'xmlrpc_prepare_media_item',    __NAMESPACE__ . '\redact_agreement',            10, 2 );
add_filter( 'wp_prepare_attachment_for_js', __NAMESPACE__ . '\redact_agreement_for_js',     10, 2 );
add_filter( 'wp_unique_filename',           __NAMESPACE__ . '\obscure_sponsor_file_names',  10, 2 );


/**
 * The meta keys that name a sponsor's agreement.
 *
 * `_wcpt_sponsor_agreement` sits on a `wcb_sponsor` on an event site, and `mes_sponsor_agreement` on an
 * `mes_sponsor` on central. Both hold nothing but an attachment ID -- see `save_post_sponsor()` in
 * `wc-post-types` and `MES_Sponsor::save_post_meta()`.
 *
 * Duplicated here rather than referenced, because this file loads on every site in the network and neither
 * plugin does: `wc-post-types` returns early on central, and `multi-event-sponsors` only runs there.
 *
 * @return string[]
 */
function get_agreement_meta_keys() {
	return array( '_wcpt_sponsor_agreement', 'mes_sponsor_agreement' );
}

/**
 * The post types a sponsor is stored as, on an event site and on central respectively.
 *
 * @return string[]
 */
function get_sponsor_post_types() {
	return array( 'wcb_sponsor', 'mes_sponsor' );
}

/**
 * The meta key that marks an attachment as a sponsorship agreement.
 *
 * Recorded on the attachment rather than read back off the sponsor each time, so that the answer doesn't
 * change when the file is detached, reparented, or left behind by a deleted sponsor.
 */
const AGREEMENT_MARKER_META_KEY = '_wcorg_sponsor_agreement';


/**
 * Mark the file a sponsor names as its agreement.
 *
 * Hooked to the meta write rather than to `save_post`, so that both plugins that store an agreement are
 * covered from one place, whichever route wrote it.
 *
 * @param int    $meta_id
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $meta_value
 */
function note_sponsor_agreement( $meta_id, $object_id, $meta_key, $meta_value ) {
	if ( ! in_array( $meta_key, get_agreement_meta_keys(), true ) ) {
		return;
	}

	/*
	 * `mes_sponsor_agreement` carries no underscore, so it isn't protected meta and the Custom Fields box
	 * will write it to anything. Only a sponsor names its own agreement.
	 */
	if ( ! in_array( get_post_type( $object_id ), get_sponsor_post_types(), true ) ) {
		return;
	}

	// An array or an object would come out of `absint()` as `1`, which is another post entirely.
	if ( ! is_scalar( $meta_value ) ) {
		return;
	}

	make_agreement_private( absint( $meta_value ) );
}

/**
 * Mark one attachment as an agreement and give it the `private` status.
 *
 * An agreement is a business document, so it doesn't take the public status its sponsor has. `private` is
 * the status Core reserves for an attachment that shouldn't follow its parent, and it's what the REST
 * routes, the front-end queries, the attachment permalink and search all read.
 *
 * @param int $attachment_id
 *
 * @return bool Whether the attachment needed the status and got it.
 */
function make_agreement_private( $attachment_id ) {
	if ( ! $attachment_id ) {
		return false;
	}

	$attachment = get_post( $attachment_id );

	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return false;
	}

	update_post_meta( $attachment->ID, AGREEMENT_MARKER_META_KEY, 1 );

	/*
	 * Only from `inherit`. `private` is already where this is going; moving a file out of `trash` would
	 * resurrect it; `auto-draft` belongs to an upload that never finished.
	 */
	if ( 'inherit' !== $attachment->post_status ) {
		return false;
	}

	$updated = wp_update_post( array(
		'ID'          => $attachment->ID,
		'post_status' => 'private',
	) );

	return ! is_wp_error( $updated ) && $updated > 0;
}

/**
 * Whether an attachment is a sponsorship agreement.
 *
 * @param int $attachment_id
 *
 * @return bool
 */
function is_agreement( $attachment_id ) {
	return (bool) get_post_meta( $attachment_id, AGREEMENT_MARKER_META_KEY, true );
}

/**
 * Whether an attachment is an agreement the current user isn't one of the readers for.
 *
 * Asks Core the same question the queries and the REST routes ask, rather than keeping a second rule in
 * step with the status. On a WordCamp site it resolves to the organizers, who are Editors.
 *
 * @param int $attachment_id
 *
 * @return bool
 */
function is_hidden_agreement( $attachment_id ) {
	return is_agreement( $attachment_id ) && ! current_user_can( 'read_post', $attachment_id );
}

/**
 * Leave an agreement out of XML-RPC media responses.
 *
 * `wp.getMediaItem` resolves an attachment by ID and runs no query, so the status doesn't reach it.
 * Returning an empty struct keeps the method able to answer without describing the file.
 *
 * @param array   $media_item_struct
 * @param WP_Post $media_item
 *
 * @return array
 */
function redact_agreement( $media_item_struct, $media_item ) {
	return is_hidden_agreement( $media_item->ID ) ? array() : $media_item_struct;
}

/**
 * Leave an agreement out of the media details the admin hands to JavaScript.
 *
 * `wp_ajax_get_attachment()` takes an ID and runs no query either, so this is `redact_agreement()` on the
 * other route that resolves that way.
 *
 * @param array   $response
 * @param WP_Post $attachment
 *
 * @return array
 */
function redact_agreement_for_js( $response, $attachment ) {
	return is_hidden_agreement( $attachment->ID ) ? array() : $response;
}

/**
 * Add a CSPRN to the names of files uploaded to a sponsor, as `wordcamp-payments` does for its own files.
 *
 * Applies to every upload made against a sponsor, not just the agreement: the agreement is chosen from the
 * Media modal after the upload has already landed, so nothing at this point tells one file from the other.
 *
 * @param string $filename
 * @param string $extension
 *
 * @return string
 */
function obscure_sponsor_file_names( $filename, $extension ) {
	$attached_post = get_post( get_upload_parent_id() );

	if ( ! $attached_post instanceof WP_Post ) {
		return $filename;
	}

	if ( ! in_array( $attached_post->post_type, get_sponsor_post_types(), true ) ) {
		return $filename;
	}

	return add_csprn_to_filename( $filename, $extension );
}

/**
 * The post an upload in progress is being attached to.
 *
 * `post_id` is what the Media modal and `async-upload.php` send; `post` is what the REST media route takes,
 * which is the block editor's path to the same place.
 *
 * @return int
 */
function get_upload_parent_id() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a name, deciding nothing.
	return absint( $_REQUEST['post_id'] ?? $_REQUEST['post'] ?? 0 );
}

/**
 * Put a CSPRN between a file's name and its extension.
 *
 * A length of `16` was chosen because it keeps the name manageable. See
 * https://core.trac.wordpress.org/ticket/43546#comment:34 for where the technique comes from.
 *
 * @param string $filename
 * @param string $extension Including the leading dot, as `wp_unique_filename()` passes it.
 *
 * @return string
 */
function add_csprn_to_filename( $filename, $extension ) {
	$name = $filename;

	// Trim the extension from the end only, so a name that repeats it keeps the copies it meant to have.
	if ( $extension && str_ends_with( $name, $extension ) ) {
		$name = substr( $name, 0, - strlen( $extension ) );
	}

	return sprintf( '%s-%s%s', $name, wp_generate_password( 16, false, false ), $extension );
}

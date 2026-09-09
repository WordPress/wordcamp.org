<?php

namespace WordCamp\Sponsor_Agreements;

use WP_Post;

use function WordCamp\Logger\log;

defined( 'WPINC' ) || die();

/**
 * The meta key that marks an attachment as a sponsorship agreement.
 *
 * Recorded on the attachment rather than read back off the sponsor each time, so that the answer doesn't
 * change when the file is detached, reparented, or left behind by a deleted sponsor.
 */
const AGREEMENT_MARKER_META_KEY = '_wcorg_sponsor_agreement';

/**
 * The upload field the Sponsor Agreement modal marks its own uploads with.
 *
 * A file is recorded as the agreement when the sponsor is saved, which can be a while after it lands and
 * may never happen at all. The modal sends this alongside the file instead, so the answer arrives in the
 * request that creates the attachment.
 *
 * Only images need it. A PDF uploaded against a sponsor is taken as an agreement whether or not it
 * arrives -- see `is_agreement_upload()`.
 */
const UPLOAD_PARAM = 'wcorg_sponsor_agreement_upload';

add_action( 'added_post_meta',              __NAMESPACE__ . '\note_sponsor_agreement',      10, 4 );
add_action( 'updated_post_meta',            __NAMESPACE__ . '\note_sponsor_agreement',      10, 4 );
add_filter( 'xmlrpc_prepare_media_item',    __NAMESPACE__ . '\redact_agreement',            10, 2 );
add_filter( 'wp_prepare_attachment_for_js', __NAMESPACE__ . '\redact_agreement_for_js',     10, 2 );
add_filter( 'wp_unique_filename',           __NAMESPACE__ . '\name_agreement_upload',       10, 2 );
add_filter( 'wp_insert_attachment_data',    __NAMESPACE__ . '\hide_agreement_upload',       10, 1 );
add_action( 'add_attachment',               __NAMESPACE__ . '\mark_agreement_upload',       10, 1 );
add_action( 'admin_enqueue_scripts',        __NAMESPACE__ . '\add_agreement_upload_settings', 20 );


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

	$attachment_id = absint( $meta_value );

	report_unmarked_upload( $attachment_id, $object_id );
	secure_agreement( $attachment_id );
}

/**
 * Note an agreement that reached this point without its upload having been marked.
 *
 * The Sponsor Agreement modal marks what it uploads, so a file uploaded against this sponsor should
 * already be an agreement by the time the sponsor is saved. One that isn't means the marking didn't
 * happen -- a JavaScript error, or Core's uploader having moved on -- and that failure is otherwise
 * silent, because saving the sponsor covers it up by securing the file anyway.
 *
 * Reads the attachment's parent, which is the only thing separating an upload from this modal from one
 * made anywhere else. A file already sitting on this sponsor for another reason -- its logo, say -- and
 * later chosen as the agreement will look the same and be logged too. That's a false alarm in a log line
 * rather than a wrong decision about the file, so it's left rather than guessed at.
 *
 * @param int $attachment_id
 * @param int $sponsor_id
 */
function report_unmarked_upload( $attachment_id, $sponsor_id ) {
	if ( is_agreement( $attachment_id ) ) {
		return;
	}

	if ( (int) get_post_field( 'post_parent', $attachment_id ) !== (int) $sponsor_id ) {
		return;
	}

	log( 'sponsor_agreement_upload_not_marked', compact( 'attachment_id', 'sponsor_id' ) );
}

/**
 * Mark an attachment as an agreement, take it out of public reach, and name its file.
 *
 * @param int $attachment_id
 */
function secure_agreement( $attachment_id ) {
	// Asked before the mark is written, so the file is renamed once however many sponsors go on to name it.
	$already_an_agreement = is_agreement( $attachment_id );

	make_agreement_private( $attachment_id );

	/*
	 * After the status, and never fatal: the status is what closes the REST routes and the queries, so a
	 * rename that doesn't happen leaves a marked, findable file rather than an unrecorded one.
	 */
	if ( ! $already_an_agreement && is_agreement( $attachment_id ) ) {
		rename_agreement_file( $attachment_id );
	}
}

/**
 * Tell the Sponsor Agreement modal what to mark its uploads with.
 */
function add_agreement_upload_settings() {
	// Registered by `wc-post-types`, which doesn't run on every site in the network.
	if ( ! wp_script_is( 'wcb-spon', 'registered' ) ) {
		return;
	}

	wp_localize_script( 'wcb-spon', 'wcbSponsorAgreement', array( 'param' => UPLOAD_PARAM ) );
}

/**
 * Whether the current request is an upload the Sponsor Agreement modal made.
 *
 * Everything this decides happens inside `wp_ajax_upload_attachment()`, which has already checked the
 * nonce, `upload_files`, and `edit_post` against the parent before it creates anything -- so the checks
 * repeated here confirm what the request is rather than standing in for authorization. The action is read
 * from `$_REQUEST` because that is what `admin-ajax.php` and `async-upload.php` both dispatch on.
 *
 * @return bool
 */
function is_sponsor_upload() {
	// phpcs:disable WordPress.Security.NonceVerification -- `wp_ajax_upload_attachment()` checked it.
	if ( 'upload-attachment' !== ( $_REQUEST['action'] ?? '' ) ) {
		return false;
	}

	$sponsor_id = absint( $_REQUEST['post_id'] ?? 0 );
	// phpcs:enable WordPress.Security.NonceVerification

	if ( ! in_array( get_post_type( $sponsor_id ), get_sponsor_post_types(), true ) ) {
		return false;
	}

	return current_user_can( 'edit_post', $sponsor_id );
}

/**
 * Whether the file the current request is uploading is a sponsor's agreement.
 *
 * The modal marks what it uploads, which is what tells an agreement from the logo and the body images
 * that arrive from the same screen. A PDF is taken as one either way: nothing else a sponsor carries is
 * a PDF, and the mark is the half of this that depends on Core's uploader still working the way the
 * modal expects.
 *
 * @param string $type The file's mime type, or its extension with the leading dot.
 *
 * @return bool
 */
function is_agreement_upload( $type = '' ) {
	if ( ! is_sponsor_upload() ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	if ( ! empty( $_POST[ UPLOAD_PARAM ] ) ) {
		return true;
	}

	return in_array( strtolower( $type ), array( '.pdf', 'application/pdf' ), true );
}

/**
 * Name an agreement as it is uploaded, before the file is written.
 *
 * Naming here rather than renaming afterwards means the file never exists under the name it arrived
 * with, and that the generated sizes are built from the name it keeps.
 *
 * @param string $filename
 * @param string $extension
 *
 * @return string
 */
function name_agreement_upload( $filename, $extension ) {
	if ( ! is_agreement_upload( $extension ) ) {
		return $filename;
	}

	return add_csprn_to_filename( $filename, $extension );
}

/**
 * Give an agreement the `private` status as its attachment is written.
 *
 * `wp_insert_attachment_data` is the last thing to touch the row before it is inserted, so an agreement
 * is never stored with the status it would have inherited from its sponsor, even briefly.
 *
 * @param array $data The attachment's post fields.
 *
 * @return array
 */
function hide_agreement_upload( $data ) {
	if ( 'attachment' !== ( $data['post_type'] ?? '' ) ) {
		return $data;
	}

	if ( ! is_agreement_upload( $data['post_mime_type'] ?? '' ) ) {
		return $data;
	}

	$data['post_status'] = 'private';

	return $data;
}

/**
 * Mark an uploaded agreement, once it has an ID to mark.
 *
 * `make_agreement_private()` rather than the marker alone, so that the status is still applied if
 * `hide_agreement_upload()` didn't reach this file -- it reads the mime type from the row being written,
 * and this reads it back from the saved attachment.
 *
 * @param int $attachment_id
 */
function mark_agreement_upload( $attachment_id ) {
	if ( ! is_agreement_upload( get_post_mime_type( $attachment_id ) ) ) {
		return;
	}

	make_agreement_private( $attachment_id );
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

/**
 * Give an agreement's file a name that isn't the one it was uploaded under.
 *
 * Runs when a file is named as an agreement rather than when it's uploaded, so that it covers one chosen
 * from the Media Library as well as one uploaded from the Sponsor screen, and leaves a sponsor's other
 * uploads alone.
 *
 * Nothing links to an agreement by URL -- both plugins hold an attachment ID and resolve it through
 * `wp_get_attachment_url()` -- so the new name reaches every reader.
 *
 * Every file Core derives from the upload moves with it, under one new base name:
 *
 * - the generated sizes, which for a PDF are renderings of the first page and for an image are copies of
 *   it at up to 2048px;
 * - the full-size original, when Core scaled the upload down. `_wp_attached_file` then points at the
 *   `-scaled` copy while `original_image` holds the name the sizes are derived from, so that -- not the
 *   attached file -- is what the new base is built from.
 *
 * Acts on the current site. `ms_upload_constants()` defines `UPLOADS` for whichever site was current at
 * bootstrap, so this can't be called inside a `switch_to_blog()`.
 *
 * The `guid` is deliberately left alone. It's an identifier rather than the URL anything is served from,
 * and Core's rule is that it doesn't change once a post has one.
 *
 * @param int $attachment_id
 *
 * @return array {
 *     What happened on disk. The metadata always names whatever each file ended up being called.
 *
 *     @type int $renamed     Files moved.
 *     @type int $left_behind Files still under the name they had.
 * }
 */
function rename_agreement_file( $attachment_id ) {
	$outcome       = array(
		'renamed'     => 0,
		'left_behind' => 0,
	);
	$attached_path = get_attached_file( $attachment_id );

	if ( ! $attached_path || ! is_file( $attached_path ) ) {
		return $outcome;
	}

	$directory = dirname( $attached_path );
	$metadata  = wp_get_attachment_metadata( $attachment_id );
	$metadata  = is_array( $metadata ) ? $metadata : array();

	$old_base     = empty( $metadata['original_image'] ) ? wp_basename( $attached_path ) : $metadata['original_image'];
	$extension    = pathinfo( $old_base, PATHINFO_EXTENSION );
	$extension    = $extension ? '.' . $extension : '';
	$new_base     = wp_unique_filename( $directory, add_csprn_to_filename( $old_base, $extension ) );
	$old_base     = substr( $old_base, 0, strlen( $old_base ) - strlen( $extension ) );
	$new_base     = substr( $new_base, 0, strlen( $new_base ) - strlen( $extension ) );
	$already_done = array();

	/**
	 * Move one of the attachment's files, and answer to what it's now called.
	 *
	 * Two sizes can name the same file -- a theme registering a size Core already generates -- so the
	 * second time one comes round it has already been dealt with. The cache is what keeps its metadata in
	 * step with the first one's, and what keeps one file from being counted twice.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	$move = function ( $name ) use ( $directory, $old_base, $new_base, $extension, &$already_done, &$outcome ) {
		if ( isset( $already_done[ $name ] ) ) {
			return $already_done[ $name ];
		}

		$source = $directory . '/' . $name;

		/*
		 * Core names a derivative `<base>-<width>x<height>.<ext>` or `<base>-scaled.<ext>`, so the
		 * separator is what tells `photo-150x150.jpg` from `photobooth-150x150.jpg`. Without it the
		 * second would be rebased into `photo-<random>booth-150x150.jpg`, splicing the name rather than
		 * replacing what it starts with.
		 */
		$derived = $name === $old_base . $extension || str_starts_with( $name, $old_base . '-' );

		if ( ! $derived ) {
			// Not this attachment's to rename. Count it only if there's a file there to be concerned with.
			$outcome['left_behind'] += is_file( $source ) ? 1 : 0;
			$already_done[ $name ]   = $name;

			return $name;
		}

		$new_name = $new_base . substr( $name, strlen( $old_base ) );

		// A name in the metadata with no file behind it was already stale, and moves nothing either way.
		if ( ! is_file( $source ) ) {
			$already_done[ $name ] = $name;

			return $name;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem needs credentials this can't ask for, and a failure is reported rather than fatal.
		if ( ! @rename( $source, $directory . '/' . $new_name ) ) {
			++$outcome['left_behind'];
			$already_done[ $name ] = $name;

			return $name;
		}

		++$outcome['renamed'];
		$already_done[ $name ] = $new_name;

		return $new_name;
	};

	$attached_name = wp_basename( $attached_path );
	$new_attached  = $move( $attached_name );

	/*
	 * Carry on when the attached file itself wouldn't move. Everything else is named from `$old_base`
	 * rather than from it, so those can still go -- and whether they went or not is the whole of what the
	 * count is for.
	 */
	if ( ! empty( $metadata['original_image'] ) ) {
		$metadata['original_image'] = $move( $metadata['original_image'] );
	}

	foreach ( $metadata['sizes'] ?? array() as $size => $details ) {
		if ( empty( $details['file'] ) ) {
			continue;
		}

		$metadata['sizes'][ $size ]['file'] = $move( $details['file'] );
	}

	if ( ! empty( $metadata['file'] ) ) {
		$path_prefix      = str_contains( $metadata['file'], '/' ) ? dirname( $metadata['file'] ) . '/' : '';
		$metadata['file'] = $path_prefix . $new_attached;
	}

	if ( $metadata ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	if ( $new_attached !== $attached_name ) {
		update_attached_file( $attachment_id, $directory . '/' . $new_attached );
	}

	return $outcome;
}

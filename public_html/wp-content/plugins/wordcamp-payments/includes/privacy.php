<?php

namespace WordCamp\Budgets\Privacy;

use WP_Query, WP_Post;

defined( 'WPINC' ) || die();


if ( ! is_cli_request() ) {
	// `PHP_INT_MAX` so that a `pre_get_posts` callback which sets `post_type` has already run -- see below.
	add_action( 'pre_get_posts',                  __NAMESPACE__ . '\unsuppress_attachment_query_filters', PHP_INT_MAX );
	add_filter( 'posts_clauses',                  __NAMESPACE__ . '\exclude_others_payment_files', 10, 2 );
	add_filter( 'the_posts',                      __NAMESPACE__ . '\hide_others_payment_files' );
	add_filter( 'map_meta_cap',                   __NAMESPACE__ . '\restrict_others_payment_file_caps', 10, 4 );
}

add_filter( 'xmlrpc_prepare_media_item',          __NAMESPACE__ . '\redact_others_payment_files', 10, 2 );
add_filter( 'wp_unique_filename',                 __NAMESPACE__ . '\obscure_payment_file_names', 10, 2 );
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\register_personal_data_exporters' );
add_filter( 'wp_privacy_personal_data_erasers',   __NAMESPACE__ . '\register_personal_data_erasers'   );


/**
 * Whether the request is WP-CLI acting on the site itself, rather than on behalf of a visitor.
 *
 * The guards below scope attachments to the current user, and WP-CLI runs with no user set. Applying them there
 * would leave maintenance commands -- `wp media regenerate`, `wp post list --post_type=attachment`, an export
 * script -- silently operating on an incomplete set, with nothing to say rows had been skipped. Before this file
 * started loading on every request it never reached WP-CLI at all, so this keeps that as it was.
 *
 * Deliberately not extended to cron: `privacy.php` already loaded there, so cron was covered before, and
 * loosening it isn't needed to fix anything.
 *
 * @return bool
 */
function is_cli_request() {
	return defined( 'WP_CLI' ) && WP_CLI;
}


/**
 * The post types this file acts on.
 *
 * Duplicates the `POST_TYPE` constants in `reimbursement-request.php` and `payment-request.php` rather than
 * referencing them: those files only load in the admin, and this one loads everywhere -- referencing them from
 * here fatals on front-end, REST, and XML-RPC requests. `Test_Privacy` asserts the two stay in step.
 */
const REIMBURSEMENT_POST_TYPE   = 'wcb_reimbursement';
const PAYMENT_REQUEST_POST_TYPE = 'wcp_payment_request';


/**
 * The post types whose attachments carry financial details.
 *
 * @return string[]
 */
function get_budget_request_post_types() {
	return array( REIMBURSEMENT_POST_TYPE, PAYMENT_REQUEST_POST_TYPE );
}


/**
 * Apply the guards below even to queries that asked for filters to be suppressed.
 *
 * `get_posts()` -- and so `get_children()` -- defaults to `suppress_filters => true`, which skips `posts_clauses`
 * and `the_posts` alike. `pre_get_posts` is the only hook `WP_Query` fires before it reads that var, so it's the
 * one place this can be corrected from.
 *
 * Runs at `PHP_INT_MAX` rather than early, because `post_type` is what decides whether a suppressed query needs
 * correcting, and another `pre_get_posts` callback is free to set it. Every priority on this hook still runs
 * before `WP_Query` reads `suppress_filters`, so going last costs nothing and catches that shape.
 *
 * @param WP_Query $wp_query
 */
function unsuppress_attachment_query_filters( $wp_query ) {
	if ( ! $wp_query->get( 'suppress_filters' ) ) {
		return;
	}

	if ( ! query_may_return_attachments( $wp_query ) ) {
		return;
	}

	$wp_query->set( 'suppress_filters', false );
}

/**
 * Whether a query could return attachments, and so needs the guards applied.
 *
 * `any` counts, because `attachment` is public and so isn't excluded from search -- that's what `get_children()`
 * asks for by default. Naming no post type at all does not: `WP_Query` then narrows to `attachment` only for an
 * attachment permalink, and to `page` or `post` otherwise, search included. Keeping that case out matters,
 * because it's the shape of nearly every front-end query on the site.
 *
 * @param WP_Query $wp_query
 *
 * @return bool
 */
function query_may_return_attachments( $wp_query ) {
	$post_types = array_filter( (array) $wp_query->get( 'post_type' ) );

	if ( ! $post_types ) {
		return (bool) $wp_query->is_attachment;
	}

	return (bool) array_intersect( array( 'attachment', 'any' ), $post_types );
}

/**
 * Exclude other users' payment files from a query, in SQL.
 *
 * `WP_Query` returns early for `fields => ids` and for the count behind `found_posts`, both before `the_posts`,
 * so a filter on the results reaches neither -- and a `found_posts` counting rows the caller never receives is
 * what breaks "Load more" in the Media Library grid for non-admins.
 *
 * Mirrors `get_hidden_payment_file_ids()`: the uploader and the requester keep access, nobody else does. A
 * logged-out visitor is neither.
 *
 * @param string[] $clauses
 * @param WP_Query $wp_query
 *
 * @return string[]
 */
function exclude_others_payment_files( $clauses, $wp_query ) {
	global $wpdb;

	// Test the query before the capability, so a query that can't match doesn't force the current user to resolve.
	if ( ! query_may_return_attachments( $wp_query ) || current_user_can( 'manage_options' ) ) {
		return $clauses;
	}

	$user_id      = get_current_user_id();
	$post_types   = get_budget_request_post_types();
	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// `$placeholders` is a generated run of `%s`, so the `IN` list stays in step with the post types above.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$clauses['where'] .= $wpdb->prepare(
		" AND (
			{$wpdb->posts}.post_type != 'attachment'
			OR {$wpdb->posts}.post_author = %d
			OR {$wpdb->posts}.post_parent NOT IN (
				SELECT budget_request.ID
				FROM {$wpdb->posts} AS budget_request
				WHERE budget_request.post_type IN ( $placeholders )
				AND budget_request.post_author != %d
			)
		)",
		array_merge( array( $user_id ), $post_types, array( $user_id ) )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	return $clauses;
}

/**
 * Drop other users' payment files from a set of query results.
 *
 * A second pass behind `exclude_others_payment_files()`, for results the SQL never produced: a `posts_pre_query`
 * short-circuit (jetpack-search does this) supplies its own, and the clauses are built but discarded.
 *
 * Reads the post type off each result rather than off the query, so results that include attachments
 * incidentally -- `post_type => 'any'`, a mixed array, a front-end search -- are covered too.
 *
 * @param WP_Post[]|int[] $posts
 *
 * @return array
 */
function hide_others_payment_files( $posts ) {
	if ( ! $posts ) {
		return $posts;
	}

	$attachments = get_attachments_from_results( $posts );

	// As in `exclude_others_payment_files()`, don't resolve the current user unless there's a reason to.
	if ( ! $attachments || current_user_can( 'manage_options' ) ) {
		return $posts;
	}

	$hidden = get_hidden_payment_file_ids( $attachments );

	if ( ! $hidden ) {
		return $posts;
	}

	foreach ( $attachments as $index => $attachment ) {
		if ( in_array( $attachment->ID, $hidden, true ) ) {
			unset( $posts[ $index ] );
		}
	}

	// Re-index the array, because WP_Query functions will assume there are no gaps.
	return array_values( $posts );
}

/**
 * Pick the attachments out of a set of query results, keyed by their position in that set.
 *
 * `the_posts` receives whatever `fields` asked for: `WP_Post` objects normally, bare IDs for `fields => ids`,
 * lightweight objects for `fields => id=>parent`. Only the first two map back to a post; the rest are ignored.
 *
 * @param WP_Post[]|int[] $posts
 *
 * @return WP_Post[] Indexed by the corresponding key in `$posts`.
 */
function get_attachments_from_results( $posts ) {
	$ids_to_prime = array();

	foreach ( $posts as $post ) {
		if ( is_numeric( $post ) ) {
			$ids_to_prime[] = (int) $post;
		}
	}

	if ( $ids_to_prime ) {
		_prime_post_caches( $ids_to_prime, false, false );
	}

	$attachments = array();

	foreach ( $posts as $index => $post ) {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}

		if ( $post instanceof WP_Post && 'attachment' === $post->post_type ) {
			$attachments[ $index ] = $post;
		}
	}

	return $attachments;
}

/**
 * Which of the given attachments are payment files the given user may not see.
 *
 * @param WP_Post[] $attachments
 * @param int|null  $user_id     Defaults to the current user. Named explicitly by callers that are asked about
 *                               somebody else, like a `map_meta_cap` callback.
 *
 * @return int[]
 */
function get_hidden_payment_file_ids( $attachments, $user_id = null ) {
	$user_id           = is_null( $user_id ) ? get_current_user_id() : (int) $user_id;
	$payment_posts_ids = get_payment_file_parent_ids( $attachments );
	$hidden            = array();

	foreach ( $attachments as $attachment ) {
		if ( ! in_array( $attachment->post_parent, $payment_posts_ids, true ) ) {
			continue;
		}

		if ( (int) $attachment->post_author === $user_id ) {
			continue;
		}

		/*
		 * The post is already cached from the request in `get_payment_file_parent_ids()`, so this doesn't create
		 * a new database query, it's just a way to access the individual post directly instead of iterating through
		 * `$payment_posts_with_attachments`.
		 */
		$parent_author = (int) get_post( $attachment->post_parent )->post_author;

		if ( $parent_author === $user_id ) {
			continue;
		}

		$hidden[] = $attachment->ID;
	}

	return $hidden;
}

/**
 * Strip the details of other users' payment files out of XML-RPC responses.
 *
 * `wp.getMediaItem` looks an attachment up by ID with `get_post()`, so it never runs a `WP_Query` for the query
 * guards to apply to. Returning an empty struct keeps the method able to answer without describing the file.
 *
 * @param array   $media_item_struct
 * @param WP_Post $media_item
 *
 * @return array
 */
function redact_others_payment_files( $media_item_struct, $media_item ) {
	if ( current_user_can( 'manage_options' ) ) {
		return $media_item_struct;
	}

	if ( get_hidden_payment_file_ids( array( $media_item ) ) ) {
		return array();
	}

	return $media_item_struct;
}

/**
 * Withhold editing rights over a payment file from everyone the rules above hide it from.
 *
 * Those rules read the attachment's parent, so whoever may edit an attachment decides who sees it. Core has no
 * reason to treat that as sensitive -- `post_parent` is an ordinary field to it, and an organizer is an Editor
 * here, so `edit_others_posts` already covers another organizer's uploads. Several routes reach the field: the
 * media REST endpoints, the Media Library's Attach and Detach actions, XML-RPC. Declining the capability closes
 * all of them at once, instead of each one separately.
 *
 * Note that `wp_update_post()` checks no capabilities at all, so anything that reparents through it needs its
 * own check as well -- the Files metabox does that where it handles the field it posts.
 *
 * @param string[] $required_capabilities The primitive capabilities the requested one maps to.
 * @param string   $requested_capability  The requested meta capability.
 * @param int      $user_id               The user being asked about, who isn't always the current one.
 * @param array    $args                  The context the capability was requested with, typically the post ID.
 *
 * @return string[]
 */
function restrict_others_payment_file_caps( $required_capabilities, $requested_capability, $user_id, $args ) {
	// `map_meta_cap` runs for every capability check, so skip the post lookup for the ones this ignores.
	if ( ! in_array( $requested_capability, array( 'edit_post', 'delete_post' ), true ) ) {
		return $required_capabilities;
	}

	$attachment = get_capability_subject( $args );

	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return $required_capabilities;
	}

	/*
	 * A file that isn't attached to anything can't be one of these, so say so before spending a query on it --
	 * `get_payment_file_parent_ids()` drops unattached files for the same reason. This is the common case on the
	 * Media Library screens, where `map_meta_cap` runs once per attachment in the list.
	 */
	if ( ! $attachment->post_parent ) {
		return $required_capabilities;
	}

	// As in the rules above, don't run the lookup for someone it can't apply to.
	if ( user_can( $user_id, 'manage_options' ) ) {
		return $required_capabilities;
	}

	if ( get_hidden_payment_file_ids( array( $attachment ), $user_id ) ) {
		return array( 'do_not_allow' );
	}

	return $required_capabilities;
}

/**
 * Resolve the post a `map_meta_cap` callback is being asked about.
 *
 * `$args[0]` is whatever was handed to `current_user_can()`, so an ID can arrive as an int or as a string. The
 * numeric check and the cast mirror `get_post()`'s own contract.
 *
 * Naming no post at all resolves to nothing, and needs no fallback to the global `$post`: Core's own
 * `map_meta_cap()` denies `edit_post` and `delete_post` outright in that case, so there's nothing left to guard.
 * The budget post types have their own copy of this, one that does carry that fallback, because it runs for
 * screens that leave the post implicit. This file can't share it -- see `get_budget_request_post_types()`.
 *
 * @param array $args The $args that was passed to the `map_meta_cap` callback.
 *
 * @return WP_Post|null
 */
function get_capability_subject( $args ) {
	if ( ! isset( $args[0] ) ) {
		return null;
	}

	if ( $args[0] instanceof WP_Post ) {
		return $args[0];
	}

	return is_numeric( $args[0] ) ? get_post( (int) $args[0] ) : null;
}

/**
 * Get the Reimbursement/Vendor Payment posts that are attached to the given media items.
 *
 * @param WP_Post[] $attachments
 *
 * @return int[]
 */
function get_payment_file_parent_ids( $attachments ) {
	$parent_ids     = array_unique( wp_list_pluck( $attachments, 'post_parent' ) );
	$orphaned_index = array_search( 0, $parent_ids, true );

	// All payment files should be attached to a post, so unattached files can be removed.
	if ( false !== $orphaned_index ) {
		unset( $parent_ids[ $orphaned_index ] );
	}

	/*
	 * `post__in` treats an empty array as "no restriction", so bail rather than pulling in every request on the
	 * site to compare against a set of attachments that can't match any of them.
	 */
	if ( ! $parent_ids ) {
		return array();
	}

	$payment_posts_with_attachments = get_posts( array(
		'post__in'    => $parent_ids,
		'post_status' => 'any',
		'numberposts' => 1000,
		'post_type'   => get_budget_request_post_types(),
	) );

	return wp_list_pluck( $payment_posts_with_attachments, 'ID' );
}

/**
 * Add a CSPRN to payment file names to protect privacy.
 *
 * Without this, a 3rd party could scrape the site looking for predictable filenames. With this added, that is no
 * longer practical. See https://core.trac.wordpress.org/ticket/43546#comment:34 for details on how a similar
 * technique was used in Core. A length of `16` was chosen because that makes the filename less cumbersome, but
 * still makes brute force practically impossible (2.267522912 * 10^26 years).
 *
 * @param string $filename
 * @param string $extension
 *
 * @return string
 */
function obscure_payment_file_names( $filename, $extension ) {
	$attached_post       = get_post( absint( $_REQUEST['post_id'] ?? 0 ) );
	$relevant_post_types = get_budget_request_post_types();

	if ( $attached_post instanceof WP_Post && in_array( $attached_post->post_type, $relevant_post_types, true ) ) {
		$filename = sprintf(
			'%s-%s%s',
			str_replace( $extension, '', $filename ),
			wp_generate_password( 16, false, false ),
			$extension
		);
	}

	return $filename;
}

/**
 * Registers the personal data eraser for each WordCamp post type
 *
 * @param array $erasers
 *
 * @return array
 */
function register_personal_data_erasers( $erasers ) {
	/**
	 * This is an empty stub, we are not adding an eraser for now, because it contains data which can be used for
	 * accounting or reference purpose.
	 */
	return $erasers;
}

/**
 * Registers the personal data exporter for each WordCamp post type.
 *
 * @param array $exporters
 *
 * @return array
 */
function register_personal_data_exporters( $exporters ) {
	$exporters['wcb-reimbursements'] = array(
		'exporter_friendly_name' => __( 'WordCamp Reimbursement Requests', 'wordcamporg' ),
		'callback'               => __NAMESPACE__ . '\reimbursements_exporter',
	);

	$exporters['wcb-vendor-payments'] = array(
		'exporter_friendly_name' => __( 'WordCamp Vendor Payment Requests', 'wordcamporg' ),
		'callback'               => __NAMESPACE__ . '\vendor_payment_exporter',
	);

	return $exporters;
}

/**
 * Finds and exports personal data associated with an email address in a vendor payment request
 *
 * @param string $email_address
 * @param int    $page
 *
 * @return array
 */
function vendor_payment_exporter( $email_address, $page ) {
	$results = array(
		'data' => array(),
		'done' => true,
	);

	$vendor_payment_requests = get_post_wp_query( PAYMENT_REQUEST_POST_TYPE, $page, $email_address );

	if ( empty( $vendor_payment_requests ) ) {
		return $results;
	}

	$data_to_export = array();

	foreach ( $vendor_payment_requests->posts as $post ) {
		$vendor_payment_exp_data = array();
		$meta                    = get_post_meta( $post->ID );

		$vendor_payment_exp_data[] = array(
			'name'  => __( 'Title', 'wordcamporg' ),
			'value' => $post->post_title,
		);
		$vendor_payment_exp_data[] = array(
			'name'  => __( 'Date', 'wordcamporg' ),
			'value' => $post->post_date,
		);

		$vendor_payment_exp_data = array_merge(
			$vendor_payment_exp_data,
			get_meta_details( $meta, PAYMENT_REQUEST_POST_TYPE )
		);

		if ( ! empty( $vendor_payment_exp_data ) ) {
			$data_to_export[] = array(
				'group_id'    => PAYMENT_REQUEST_POST_TYPE,
				'group_label' => __( 'WordCamp Vendor Payments', 'wordcamporg' ),
				'item_id'     => PAYMENT_REQUEST_POST_TYPE . "-{$post->ID}",
				'data'        => $vendor_payment_exp_data,
			);
		}
	}

	$results['done'] = $vendor_payment_requests->max_num_pages <= $page;
	$results['data'] = $data_to_export;

	return $results;
}

/**
 * Finds and exports personal data associated with an email address in a Reimbursement Request.
 *
 * @param string $email_address
 * @param int    $page
 *
 * @return array
 */
function reimbursements_exporter( $email_address, $page ) {
	$results = array(
		'data' => array(),
		'done' => true,
	);

	$reimbursements = get_post_wp_query( REIMBURSEMENT_POST_TYPE, $page, $email_address );

	if ( empty( $reimbursements ) ) {
		return $results;
	}

	$data_to_export = array();

	foreach ( $reimbursements->posts as $post ) {
		$reimbursement_data_to_export = array();
		$meta                         = get_post_meta( $post->ID );

		$reimbursement_data_to_export[] = array(
			'name'  => __( 'Title', 'wordcamporg' ),
			'value' => $post->post_title,
		);
		$reimbursement_data_to_export[] = array(
			'name'  => __( 'Date', 'wordcamporg' ),
			'value' => $post->post_date,
		);

		// Meta fields.
		$reimbursement_data_to_export = array_merge(
			$reimbursement_data_to_export,
			get_meta_details( $meta, REIMBURSEMENT_POST_TYPE )
		);

		if ( ! empty( $reimbursement_data_to_export ) ) {
			$data_to_export[] = array(
				'group_id'    => REIMBURSEMENT_POST_TYPE,
				'group_label' => __( 'WordCamp Reimbursement Request', 'wordcamporg' ),
				'item_id'     => REIMBURSEMENT_POST_TYPE . "-{$post->ID}",
				'data'        => $reimbursement_data_to_export,
			);
		}
	}

	$results['done'] = $reimbursements->max_num_pages <= $page;
	$results['data'] = $data_to_export;

	return $results;
}

/**
 * Helper function, to build and return WP_Query object for fetching posts that should be considered for exporting data
 *
 * We use `_camppayments_vendor_email_address` as the key for `payment_request`, instead of author email,
 * because the vendor contact details could be of an individual (instead of a business), and thus is a potential PII
 *
 * @param string  $query_type
 * @param integer $page
 * @param string  $email_address Email address of the entity making the request.
 *
 * @return null|WP_Query
 */
function get_post_wp_query( $query_type, $page, $email_address ) {
	$query_args = array(
		'post_type'      => $query_type,
		'post_status'    => 'any',
		'number_posts'   => - 1,
		'posts_per_page' => 20,
		'paged'          => $page,
	);

	switch ( $query_type ) {
		case REIMBURSEMENT_POST_TYPE:
			$user = get_user_by( 'email', $email_address );

			if ( empty( $user ) ) {
				return null;
			}

			$query_args = array_merge( $query_args, array( 'post_author' => $user->ID ) );
			break;

		case PAYMENT_REQUEST_POST_TYPE:
			$query_args['meta_query'] = array(
				'relation' => 'AND',
			);

			$query_args['meta_query'][] = array(
				'key'   => '_camppayments_vendor_email_address',
				'value' => $email_address,
			);
			break;

		default:
			return null;
	}

	return new WP_Query( $query_args );
}

/**
 *
 * @param array  $meta      Meta object of post, as retrieved by `get_post_meta( $post->ID )`.
 * @param string $post_type Post type. Could be one of `wcb_reimbursement` or `wcp_payment_request`.
 *
 * @return array Details of the reimbursement request
 */
function get_meta_details( $meta, $post_type ) {
	$meta_details = array();

	foreach ( get_meta_fields_mapping( $post_type ) as $meta_field => $meta_field_name ) {
		$data = isset( $meta[ $meta_field ] ) ? $meta[ $meta_field ] : null;

		if ( ! empty( $data ) && is_array( $data ) && ! empty( $data[0] ) ) {
			$meta_details[] = array(
				'name'  => $meta_field_name,
				'value' => $meta [ $meta_field ][0],
			);
		}
	}

	return $meta_details;
}

/**
 * Returns array of meta fields and their titles that we want to allow export for.
 *
 * @param string $post_type
 *
 * @return array
 */
function get_meta_fields_mapping( $post_type ) {
	$mapping_fields = array();

	if ( REIMBURSEMENT_POST_TYPE === $post_type ) {
		$prefix         = '_wcbrr_';
		$mapping_fields = array_merge(
			$mapping_fields,
			array(
				$prefix . 'name_of_payer'                   => __( 'Payer Name', 'wordcamporg' ),
				$prefix . 'currency'                        => __( 'Currency', 'wordcamporg' ),
				$prefix . 'payment_method'                  => __( 'Payment Method', 'wordcamporg' ),
				$prefix . 'payment_receipt_country_iso3166' => __( 'Country for payment receipt', 'wordcamporg' ),

				// Payment Method - Direct Deposit.
				$prefix . 'ach_bank_name'               => __( 'Bank Name', 'wordcamporg' ),
				$prefix . 'ach_account_type'            => __( 'Account Type', 'wordcamporg' ),
				$prefix . 'ach_routing_number'          => __( 'Routing Number', 'wordcamporg' ),
				$prefix . 'ach_account_number'          => __( 'Account Number', 'wordcamporg' ),
				$prefix . 'ach_account_holder_name'     => __( 'Account Holder Name', 'wordcamporg' ),

				// Payment Method - Check.
				$prefix . 'payable_to'                  => __( 'Payable To', 'wordcamporg' ),
				$prefix . 'check_street_address'        => __( 'Street Address', 'wordcamporg' ),
				$prefix . 'check_city'                  => __( 'City', 'wordcamporg' ),
				$prefix . 'check_state'                 => __( 'State / Province', 'wordcamporg' ),
				$prefix . 'check_zip_code'              => __( 'ZIP / Postal Code', 'wordcamporg' ),
				$prefix . 'check_country'               => __( 'Country', 'wordcamporg' ),

				// Payment Method - Wire.
				$prefix . 'bank_name'                   => __( 'Beneficiary’s Bank Name', 'wordcamporg' ),
				$prefix . 'bank_street_address'         => __( 'Beneficiary’s Bank Street Address', 'wordcamporg' ),
				$prefix . 'bank_city'                   => __( 'Beneficiary’s Bank City', 'wordcamporg' ),
				$prefix . 'bank_state'                  => __( 'Beneficiary’s Bank State / Province', 'wordcamporg' ),
				$prefix . 'bank_zip_code'               => __( 'Beneficiary’s Bank ZIP / Postal Code', 'wordcamporg' ),
				$prefix . 'bank_country_iso3166'        => __( 'Beneficiary’s Bank Country', 'wordcamporg' ),
				$prefix . 'bank_bic'                    => __( 'Beneficiary’s Bank SWIFT BIC', 'wordcamporg' ),
				$prefix . 'beneficiary_account_number'  => __( 'Beneficiary’s Account Number or IBAN', 'wordcamporg' ),

				// Intermediary bank details.
				$prefix . 'interm_bank_name'            => __( 'Intermediary Bank Name', 'wordcamporg' ),
				$prefix . 'interm_bank_street_address'  => __( 'Intermediary Bank Street Address', 'wordcamporg' ),
				$prefix . 'interm_bank_city'            => __( 'Intermediary Bank City', 'wordcamporg' ),
				$prefix . 'interm_bank_state'           => __( 'Intermediary Bank State / Province', 'wordcamporg' ),
				$prefix . 'interm_bank_zip_code'        => __( 'Intermediary Bank ZIP / Postal Code', 'wordcamporg' ),
				$prefix . 'interm_bank_country_iso3166' => __( 'Intermediary Bank Country', 'wordcamporg' ),
				$prefix . 'interm_bank_swift'           => __( 'Intermediary Bank SWIFT BIC', 'wordcamporg' ),
				$prefix . 'interm_bank_account'         => __( 'Intermediary Bank Account', 'wordcamporg' ),

				$prefix . 'beneficiary_name'            => __( 'Beneficiary’s Name', 'wordcamporg' ),
				$prefix . 'beneficiary_street_address'  => __( 'Beneficiary’s Street Address', 'wordcamporg' ),
				$prefix . 'beneficiary_city'            => __( 'Beneficiary’s City', 'wordcamporg' ),
				$prefix . 'beneficiary_state'           => __( 'Beneficiary’s State / Province', 'wordcamporg' ),
				$prefix . 'beneficiary_zip_code'        => __( 'Beneficiary’s ZIP / Postal Code', 'wordcamporg' ),
				$prefix . 'beneficiary_country_iso3166' => __( 'Beneficiary’s Country', 'wordcamporg' ),
			)
		);
	} elseif ( PAYMENT_REQUEST_POST_TYPE === $post_type ) {
		$prefix         = '_camppayments_';
		$mapping_fields = array_merge(
			$mapping_fields,
			array(
				// Vendor payment fields.
				$prefix . 'description'            => __( 'Description', 'wordcamporg' ),
				$prefix . 'general_notes'          => __( 'Notes', 'wordcamporg' ),
				$prefix . 'vendor_name'            => __( 'Name', 'wordcamporg' ),
				$prefix . 'vendor_email_address'   => __( 'Email Address', 'wordcamporg' ),
				$prefix . 'vendor_contact_person'  => __( 'Contact Person', 'wordcamporg' ),
				$prefix . 'vendor_street_address'  => __( 'Street Address', 'wordcamporg' ),
				$prefix . 'vendor_city'            => __( 'City', 'wordcamporg' ),
				$prefix . 'vendor_state'           => __( 'State / Province', 'wordcamporg' ),
				$prefix . 'vendor_zip_code'        => __( 'ZIP / Postal Code', 'wordcamporg' ),
				$prefix . 'vendor_country_iso3166' => __( 'Country', 'wordcamporg' ),
			)
		);
	}

	return $mapping_fields;
}

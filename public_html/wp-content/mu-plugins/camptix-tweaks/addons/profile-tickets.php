<?php

namespace WordCamp\CampTix_Tweaks\Profile_Tickets;

use CampTix_Require_Login;
use WP_Post;

defined( 'WPINC' ) || die();

/*
 * Keep a copy of each ticket in its holder's user meta, so that their WordPress.org profile can list the
 * tickets they hold across every WordCamp (#1411). User meta is shared with WordPress.org, so the profile
 * reads the rows directly rather than querying each WordCamp site.
 *
 * Each ticket is its own `wordcamp_ticket` row, keyed by blog ID and attendee ID.
 */

const USER_META_KEY = 'wordcamp_ticket';

/*
 * Attendee meta recording which user currently holds the ticket's row, so that it can be removed from them
 * when the ticket moves to someone else.
 */
const HOLDER_META_KEY = 'tix_profile_user_id';

/*
 * Attendee meta recording who bought the ticket, taken the first time it's synced. The order only says who the
 * buyer is through its first attendee, which stops being true once the buyer gives that ticket to someone else.
 */
const BUYER_META_KEY = 'tix_profile_buyer';

add_action( 'transition_post_status', __NAMESPACE__ . '\sync_on_status_change', 10, 3 );
add_action( 'added_post_meta',        __NAMESPACE__ . '\sync_on_username_change', 10, 3 );
add_action( 'updated_post_meta',      __NAMESPACE__ . '\sync_on_username_change', 10, 3 );
add_action( 'before_delete_post',     __NAMESPACE__ . '\remove_on_delete', 10, 2 );

/**
 * Sync whenever an attendee's status changes.
 *
 * This covers every path that changes a ticket's status, including the ones that run without the ticket's
 * holder being logged in: Stripe webhooks, organizers refunding an order, and the timeout sweep.
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function sync_on_status_change( $new_status, $old_status, $post ) {
	if ( 'tix_attendee' !== $post->post_type || $new_status === $old_status ) {
		return;
	}

	sync_ticket( $post );
}

/**
 * Sync whenever a ticket changes hands.
 *
 * `CampTix_Require_Login` updates `tix_username` when an additional attendee claims their ticket, and when a
 * ticket is transferred by someone else saving it through its edit link.
 *
 * @param int    $meta_id   Unused.
 * @param int    $object_id
 * @param string $meta_key
 */
function sync_on_username_change( $meta_id, $object_id, $meta_key ) {
	if ( 'tix_username' !== $meta_key ) {
		return;
	}

	$post = get_post( $object_id );

	if ( $post && 'tix_attendee' === $post->post_type ) {
		sync_ticket( $post );
	}
}

/**
 * Remove the ticket from its holder's profile when the attendee is deleted.
 *
 * @param int     $post_id Unused.
 * @param WP_Post $post
 */
function remove_on_delete( $post_id, $post ) {
	if ( 'tix_attendee' !== $post->post_type ) {
		return;
	}

	$holder_id = (int) get_post_meta( $post->ID, HOLDER_META_KEY, true );

	if ( $holder_id ) {
		remove_row( $holder_id, $post->ID );
	}
}

/**
 * Bring the profile row for a ticket in line with the attendee post.
 *
 * @param WP_Post $attendee
 */
function sync_ticket( WP_Post $attendee ) {
	// Without Require Login, tickets aren't tied to accounts. Sites can opt out with `camptix_skip_require_login`.
	if ( ! class_exists( 'CampTix_Require_Login' ) ) {
		return;
	}

	$holder_id = (int) get_post_meta( $attendee->ID, HOLDER_META_KEY, true );

	/*
	 * Only tickets that were bought, or are awaiting payment, belong on a profile; not abandoned checkouts. This
	 * has to come first, because checkout saves the order's attendees one at a time, so the buyer isn't known yet.
	 */
	if ( ! $holder_id && ! in_array( $attendee->post_status, array( 'publish', 'pending' ), true ) ) {
		return;
	}

	// A trashed ticket leaves the profile. Core restores it as a draft, so it comes back once it's published again.
	$new_user_id = 'trash' === $attendee->post_status ? 0 : get_ticket_holder_id( $attendee );

	if ( $holder_id && $holder_id !== $new_user_id ) {
		remove_row( $holder_id, $attendee->ID );
		delete_post_meta( $attendee->ID, HOLDER_META_KEY );
	}

	if ( ! $new_user_id ) {
		return;
	}

	$row     = get_row( $attendee, $new_user_id );
	$old_row = find_row( $new_user_id, $attendee->ID );

	if ( $old_row ) {
		update_user_meta( $new_user_id, USER_META_KEY, $row, $old_row );
	} else {
		add_user_meta( $new_user_id, USER_META_KEY, $row );
	}

	update_post_meta( $attendee->ID, HOLDER_META_KEY, $new_user_id );
}

/**
 * Get the ID of the user who holds a ticket.
 *
 * That's the attendee's own account once they've claimed the ticket, and the buyer's until then.
 *
 * @param WP_Post $attendee
 *
 * @return int The user ID, or 0 when there's no account to attach the ticket to.
 */
function get_ticket_holder_id( WP_Post $attendee ) {
	$username = get_post_meta( $attendee->ID, 'tix_username', true );

	if ( ! $username || CampTix_Require_Login::UNCONFIRMED_USERNAME === $username ) {
		$username = get_buyer_username( $attendee );
	}

	$user = $username ? get_user_by( 'login', $username ) : false;

	return $user ? $user->ID : 0;
}

/**
 * Get the username of the person who bought a ticket.
 *
 * `CampTix_Require_Login` stamps the buyer on the first attendee of the order: in `tix_buyer_username` when
 * the buyer didn't know who would use that ticket, and in `tix_username` otherwise. That's read once and kept
 * on the attendee, because the first attendee's `tix_username` changes if the buyer transfers that ticket.
 *
 * @param WP_Post $attendee
 *
 * @return string An empty string when the buyer isn't known.
 */
function get_buyer_username( WP_Post $attendee ) {
	/** @var \CampTix_Plugin $camptix */
	global $camptix;

	$username = get_post_meta( $attendee->ID, BUYER_META_KEY, true );

	if ( $username ) {
		return $username;
	}

	$payment_token = get_post_meta( $attendee->ID, 'tix_payment_token', true );
	$order_ids     = $payment_token ? wp_list_pluck( $camptix->get_attendees_from_payment_token( $payment_token ), 'ID' ) : array();
	$first_id      = $order_ids ? min( $order_ids ) : $attendee->ID;
	$username      = get_post_meta( $first_id, 'tix_buyer_username', true );

	if ( ! $username ) {
		$username = get_post_meta( $first_id, 'tix_username', true );
	}

	if ( ! $username || CampTix_Require_Login::UNCONFIRMED_USERNAME === $username ) {
		return '';
	}

	update_post_meta( $attendee->ID, BUYER_META_KEY, $username );

	return $username;
}

/**
 * Build the profile row for a ticket.
 *
 * @param WP_Post $attendee
 * @param int     $user_id  The ticket's holder.
 *
 * @return array
 */
function get_row( WP_Post $attendee, $user_id ) {
	/** @var \CampTix_Plugin $camptix */
	global $camptix;

	$ticket = get_post( (int) get_post_meta( $attendee->ID, 'tix_ticket_id', true ) );
	$user   = get_userdata( $user_id );

	$row = array(
		'blog_id'       => get_current_blog_id(),
		'attendee_id'   => $attendee->ID,
		'wordcamp_name' => get_wordcamp_name(),
		'url'           => site_url(),
		'ticket'        => $ticket ? $ticket->post_title : '',
		'price'         => $camptix->append_currency( (float) get_post_meta( $attendee->ID, 'tix_ticket_price', true ), false ),
		'purchased'     => get_gmt_from_date( $attendee->post_date ),
		'status'        => $attendee->post_status,
		'unclaimed'     => CampTix_Require_Login::UNCONFIRMED_USERNAME === get_post_meta( $attendee->ID, 'tix_username', true ),
		'edit_link'     => $camptix->get_edit_attendee_link( $attendee->ID, get_post_meta( $attendee->ID, 'tix_edit_token', true ) ),
	);

	// The access link manages the whole order, refunds included, so only the buyer gets it.
	if ( $user && get_buyer_username( $attendee ) === $user->user_login ) {
		$row['access_link'] = $camptix->get_access_tickets_link( get_post_meta( $attendee->ID, 'tix_access_token', true ) );
	}

	return $row;
}

/**
 * Find a user's stored row for a ticket on the current site.
 *
 * @param int $user_id
 * @param int $attendee_id
 *
 * @return array|false
 */
function find_row( $user_id, $attendee_id ) {
	foreach ( get_user_meta( $user_id, USER_META_KEY, false ) as $row ) {
		if ( get_current_blog_id() === (int) $row['blog_id'] && (int) $attendee_id === (int) $row['attendee_id'] ) {
			return $row;
		}
	}

	return false;
}

/**
 * Remove a ticket's row from a user's profile.
 *
 * @param int $user_id
 * @param int $attendee_id
 */
function remove_row( $user_id, $attendee_id ) {
	$row = find_row( $user_id, $attendee_id );

	if ( $row ) {
		delete_user_meta( $user_id, USER_META_KEY, $row );
	}
}

<?php
/**
 * Upload space on group sites.
 *
 * Core gives every site the network's `blog_upload_space`, 100 MB unless
 * changed. A Meetup import brings the group's event photos over, and San
 * Jose's 322 events came to 123 MB, so an imported group could be out of
 * space before its organizers had uploaded anything (#2361).
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Upload_Space;

defined( 'WPINC' ) || die();

/**
 * A group site's upload space, in MB.
 */
const SPACE_ALLOWED = 1024;

/**
 * How full the space is, as a share, when organizers are told it's running out.
 */
const NEAR_FULL = 0.9;

/**
 * Where organizers ask for more space: #meetups on Make WordPress Slack.
 */
const MORE_SPACE_URL = 'https://wordpress.slack.com/messages/meetups/';

/**
 * Register hooks.
 *
 * Runs on every site of the groups network, front end and wp-admin, so it's
 * registered before the GatherPress guard.
 */
function bootstrap(): void {
	add_filter( 'get_space_allowed', __NAMESPACE__ . '\filter_space_allowed' );

	// Core hooks `multisite_over_quota_message()` here on every request, but
	// only defines it in wp-admin. The event modal loads the media library on
	// the front end, so an organizer's group page fataled once the site was
	// over its space. See https://core.trac.wordpress.org/ticket/60925.
	remove_action( 'upload_ui_over_quota', 'multisite_over_quota_message' );
	add_action( 'upload_ui_over_quota', __NAMESPACE__ . '\render_full_message' );
}

/**
 * Give group sites `SPACE_ALLOWED`.
 *
 * Only raises the network's default. A site's own space, which a super admin
 * sets on its Edit Site screen, is left alone, as core leaves it.
 *
 * @param int|string $space_allowed Upload space, in MB.
 * @return int|string
 */
function filter_space_allowed( $space_allowed ) {
	if ( is_numeric( get_option( 'blog_upload_space' ) ) ) {
		return $space_allowed;
	}

	return max( (float) $space_allowed, SPACE_ALLOWED );
}

/**
 * How much of the current site's upload space is used.
 *
 * @return array{used: int, allowed: int, status: string} Bytes, and `ok`,
 *                                                         `near` (`NEAR_FULL`), or `full`.
 */
function get_upload_space(): array {
	$allowed = get_space_allowed_bytes();
	$used    = (int) round( get_space_used() * MB_IN_BYTES );
	$status  = 'ok';

	if ( ! get_site_option( 'upload_space_check_disabled' ) ) {
		if ( ! is_upload_space_available() ) {
			$status = 'full';
		} elseif ( $used >= $allowed * NEAR_FULL ) {
			$status = 'near';
		}
	}

	return compact( 'used', 'allowed', 'status' );
}

/**
 * The current site's upload space, in bytes.
 */
function get_space_allowed_bytes(): int {
	return (int) round( max( 0, (float) get_space_allowed() ) * MB_IN_BYTES );
}

/**
 * Upload space as organizers read it: "950 MB", "1 GB", "1.5 GB".
 *
 * Whole megabytes, so nothing says "950.0 MB", and gigabytes to one decimal
 * unless they're whole, so 1.5 GB isn't rounded up to "2 GB".
 *
 * @param int $bytes Upload space.
 */
function format_space( int $bytes ): string {
	$tenths   = (int) round( $bytes / GB_IN_BYTES * 10 );
	$decimals = $bytes >= GB_IN_BYTES && 0 !== $tenths % 10 ? 1 : 0;

	return (string) size_format( $bytes, $decimals );
}

/**
 * Say the site is out of upload space, and where to ask for more.
 *
 * Shown in place of the media library's upload area, on `upload_ui_over_quota`.
 */
function render_full_message(): void {
	printf(
		'<p>%s</p>',
		wp_kses(
			sprintf(
				/* translators: 1: Upload space, e.g. "1 GB". 2: URL of the #meetups channel on Make WordPress Slack. */
				__( 'This group has used all %1$s of its upload space. You can still choose an image that is already in the media library. For more space, ask in <a href="%2$s">#meetups</a> on Make WordPress Slack.', 'wordcamporg' ),
				esc_html( format_space( get_space_allowed_bytes() ) ),
				esc_url( MORE_SPACE_URL )
			),
			array( 'a' => array( 'href' => true ) )
		)
	);
}

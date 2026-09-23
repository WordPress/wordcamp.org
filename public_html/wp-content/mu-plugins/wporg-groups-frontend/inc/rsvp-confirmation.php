<?php
/**
 * Confirmation email sent to a member when they RSVP.
 *
 * Plain-text `wp_mail()` only, matching
 * `WordCamp\Groups\Ownership_Transfer\Notifications\send_notification()` --
 * no templating system. Deliberately not GatherPress's HTML event email,
 * which renders through a hardcoded template path with no override hook.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\RSVP_Confirmation;

use GatherPress\Core\Calendar\Calendar;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Response\Status;
use GatherPress\Core\Rsvp\Rsvp;
use WP_Comment;

defined( 'WPINC' ) || die();

/**
 * Register the confirmation hook.
 *
 * `set_object_terms` is the one point every RSVP path passes through:
 * `Rsvp\Storage::save()` writes the status as a term regardless of whether
 * the RSVP arrived from the event block, GatherPress's REST route, or this
 * network's own occurrence-scoped route. GatherPress fires no action of its
 * own after saving an RSVP, so there is nothing more specific to hook.
 */
function bootstrap(): void {
	add_action( 'set_object_terms', __NAMESPACE__ . '\send_confirmation', 10, 6 );
}

/**
 * Email a member confirming their RSVP.
 *
 * Sent unconditionally to the person who RSVP'd: this confirms an action
 * they just took themselves, so it is not covered by the event-updates
 * opt-in that `Notifications\scope_opt_in_read_to_current_group()` handles
 * for the broadcast emails an organizer sends.
 *
 * @param int    $object_id  Comment ID the terms were set on.
 * @param array  $terms      Terms passed to `wp_set_object_terms()`.
 * @param array  $tt_ids     Term taxonomy IDs now assigned.
 * @param string $taxonomy   Taxonomy the terms belong to.
 * @param bool   $append     Whether the terms were appended rather than replacing.
 * @param array  $old_tt_ids Term taxonomy IDs assigned before this write.
 */
function send_confirmation(
	$object_id,
	$terms,
	$tt_ids,
	$taxonomy,
	$append,
	$old_tt_ids
): void {
	if ( Status::TAXONOMY !== $taxonomy ) {
		return;
	}

	$new = array_map( 'intval', (array) $tt_ids );
	$old = array_map( 'intval', (array) $old_tt_ids );
	sort( $new );
	sort( $old );

	/*
	 * `Storage::save()` rewrites the status term on every save, including
	 * ones that only change guest count or anonymity. Re-confirming an RSVP
	 * the member already holds would mail them again for a change they did
	 * not make to their attendance, so only a status that actually moved
	 * earns an email.
	 */
	if ( $new === $old ) {
		return;
	}

	$comment = get_comment( (int) $object_id );

	if ( ! $comment instanceof WP_Comment || Rsvp::COMMENT_TYPE !== $comment->comment_type ) {
		return;
	}

	// Read the status back rather than trusting `$terms`, whose shape depends
	// on how the caller addressed the term (slug, name or ID).
	$statuses = wp_get_object_terms( (int) $object_id, Status::TAXONOMY, array( 'fields' => 'slugs' ) );

	if ( is_wp_error( $statuses ) || ! in_array( Status::ATTENDING->value, $statuses, true ) ) {
		return;
	}

	$event_id = (int) $comment->comment_post_ID;

	if ( 'publish' !== get_post_status( $event_id ) ) {
		return;
	}

	if ( ! actor_owns_rsvp( $comment ) ) {
		return;
	}

	$recipient = get_recipient( $comment );

	if ( ! $recipient ) {
		return;
	}

	send_email( $recipient, $event_id );
}

/**
 * Whether the person whose RSVP this is is the one who just changed it.
 *
 * This mail confirms an action the member took themselves -- that is the whole
 * reason it is sent unconditionally, outside the event-updates opt-in. Someone
 * else moving their RSVP is not that, and GatherPress's own RSVP route takes a
 * `user_id`, which anyone holding `edit_post` on the event may pass. Without
 * this an organizer could walk another member's RSVP between attending and not
 * attending in a loop and mail them once per step, indefinitely (#2062).
 *
 * A guest RSVP carries no `user_id`, and the visitor who left it is likewise
 * not logged in, so the two compare equal and the confirmation still goes out.
 *
 * @param WP_Comment $comment RSVP comment.
 */
function actor_owns_rsvp( WP_Comment $comment ): bool {
	return get_current_user_id() === (int) $comment->user_id;
}

/**
 * Resolve the address to confirm to.
 *
 * Mirrors how GatherPress resolves recipients in
 * `Event\Rest_Api::build_comment_recipient()`: a comment tied to a
 * WordPress user is addressed at that account, and everything else falls
 * back to the address left on the RSVP itself.
 *
 * @param WP_Comment $comment RSVP comment.
 * @return array{email: string, name: string}|null Recipient, or null when no address is on file.
 */
function get_recipient( WP_Comment $comment ): ?array {
	$user_id = (int) $comment->user_id;
	$email   = (string) $comment->comment_author_email;
	$name    = (string) $comment->comment_author;

	if ( $user_id ) {
		$user = get_userdata( $user_id );

		if ( $user ) {
			$email = (string) $user->user_email;
			$name  = (string) $user->display_name;
		}
	}

	if ( ! is_email( $email ) ) {
		return null;
	}

	return array(
		'email' => $email,
		'name'  => $name,
	);
}

/**
 * Decode the entities WordPress hands text over with, for a plain-text body.
 *
 * `the_title` runs `wptexturize()`, so an apostrophe arrives as `&#8217;`, and
 * option and term names are stored HTML-escaped. Nothing decodes those in a
 * `text/plain` message, so they would reach the member literally. `inc/rest.php`
 * decodes titles for the same reason.
 *
 * @param string $text Text as WordPress hands it over.
 *
 * @return string
 */
function plain_text( string $text ): string {
	return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * The event's calendar links, in the order the event page offers them.
 *
 * Same on-site endpoint URLs the add-to-calendar control links to, rather
 * than URLs built here: GatherPress resolves them off the event's permalink,
 * so on a recurring event they inherit the occurrence the RSVP was for, the
 * same way the permalink in the body above does.
 *
 * A getter returns `false` when it can't resolve the post, so anything that
 * isn't a usable URL is dropped rather than printed as an empty line.
 *
 * @param int $event_id Event post ID.
 *
 * @return array<string, string> Calendar name => URL.
 */
function get_calendar_links( int $event_id ): array {
	$calendar = new Calendar( $event_id );

	$links = array(
		__( 'Google Calendar', 'wporg-groups-frontend' ) => $calendar->get_google_url(),
		__( 'iCal', 'wporg-groups-frontend' )            => $calendar->get_ical_url(),
		__( 'Outlook', 'wporg-groups-frontend' )         => $calendar->get_outlook_url(),
		__( 'Yahoo Calendar', 'wporg-groups-frontend' )  => $calendar->get_yahoo_url(),
	);

	return array_filter(
		$links,
		static function ( $url ): bool {
			return is_string( $url ) && '' !== $url;
		}
	);
}

/**
 * Build and send the confirmation.
 *
 * @param array{email: string, name: string} $recipient Recipient.
 * @param int                                $event_id  Event post ID.
 */
function send_email( array $recipient, int $event_id ): void {
	$event      = new Event( $event_id );
	$title      = plain_text( get_the_title( $event_id ) );
	$group_name = plain_text( get_bloginfo( 'name' ) );

	/*
	 * On a recurring event this resolves to the occurrence the member is
	 * RSVPing to, not the series: the recurring-events plugin filters the
	 * event's datetime meta and its permalink for whichever occurrence is in
	 * context, which the RSVP routes set before saving.
	 */
	$when      = $event->get_display_datetime();
	$permalink = get_permalink( $event_id );
	$venue     = plain_text( $event->get_venue_information()['name'] ?? '' );

	$lines = array(
		sprintf(
			/* translators: %s: event title. */
			__( 'You\'re on the list for "%s".', 'wporg-groups-frontend' ),
			$title
		),
		'',
	);

	// An event with no datetime yet renders as GatherPress's placeholder dash,
	// which says less than leaving the line out altogether.
	$has_datetime = $when && Event::DATETIME_PLACEHOLDER !== $when;

	if ( $has_datetime ) {
		$lines[] = sprintf(
			/* translators: %s: event date and time. */
			__( 'When: %s', 'wporg-groups-frontend' ),
			$when
		);
	}

	if ( $venue ) {
		$lines[] = sprintf(
			/* translators: %s: venue name. */
			__( 'Where: %s', 'wporg-groups-frontend' ),
			$venue
		);
	}

	$lines[] = sprintf(
		/* translators: %s: group name. */
		__( 'Group: %s', 'wporg-groups-frontend' ),
		$group_name
	);

	if ( $permalink ) {
		$lines[] = '';
		$lines[] = __( 'Event page:', 'wporg-groups-frontend' );
		$lines[] = $permalink;
	}

	/*
	 * Offered here because this is the message someone keeps, and the point
	 * at which they want the event in their own calendar (#2110). Withheld
	 * from an event with no date yet: every one of these would hand the
	 * calendar GatherPress's placeholder rather than a time.
	 */
	$calendar_links = $has_datetime ? get_calendar_links( $event_id ) : array();

	if ( $calendar_links ) {
		$lines[] = '';
		$lines[] = __( 'Add to calendar:', 'wporg-groups-frontend' );

		foreach ( $calendar_links as $calendar_name => $calendar_url ) {
			// Not a translatable format: the names are already translated
			// above, and a "name: url" line has nothing left to reorder.
			$lines[] = $calendar_name . ': ' . $calendar_url;
		}
	}

	$lines[] = '';
	$lines[] = __( 'If your plans change, you can cancel your RSVP on the event page.', 'wporg-groups-frontend' );

	$subject = sprintf(
		/* translators: %s: event title. */
		__( 'You\'re going to "%s"', 'wporg-groups-frontend' ),
		$title
	);

	$to = $recipient['name']
		? sprintf( '%s <%s>', $recipient['name'], $recipient['email'] )
		: $recipient['email'];

	// Passed as an array, as in `Groups\Messaging\send_message()`, so a
	// display name containing a comma can't smuggle in an extra recipient.
	$sent = wp_mail(
		array( $to ),
		$subject,
		implode( "\n", $lines ),
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);

	if ( ! $sent ) {
		trigger_error(
			sprintf(
				'Failed to send the RSVP confirmation for event %1$d to %2$s.',
				$event_id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; relayed to Slack by this repo's error handler.
				$recipient['email'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			),
			E_USER_WARNING
		);
	}
}

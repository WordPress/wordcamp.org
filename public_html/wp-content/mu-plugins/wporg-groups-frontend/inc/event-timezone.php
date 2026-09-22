<?php
/**
 * The timezone an event is scheduled in.
 *
 * GatherPress already stores a timezone per event, and its wp-admin sidebar
 * already lets you set one. The front-end form did not, so every event created
 * through it took `wp_timezone_string()` and the display was suppressed
 * network-wide to hide the result (#2021). This module is the other half: the
 * choices the form offers, and the validation both write paths share.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Timezone;

use GatherPress\Core\Utility;

defined( 'WPINC' ) || die();

/**
 * The timezone choices the form offers, grouped the way core groups them.
 *
 * Reuses GatherPress's own parse of `wp_timezone_choice()` rather than
 * rebuilding the list: it is already localized through `get_user_locale()`, and
 * sharing the source means the values the form offers are exactly the ones
 * GatherPress's own editor control writes.
 *
 * @return array<string, array<string, string>> Group label => [ value => label ].
 */
function get_choices(): array {
	if ( ! class_exists( Utility::class ) ) {
		return array();
	}

	return Utility::timezone_choices();
}

/**
 * Every timezone value the form can offer, flattened out of the groups.
 *
 * This is the allowlist, rather than `Utility::list_timezone_and_utc_offsets()`,
 * because a value the control cannot offer is one no organizer can have chosen.
 * `Event::save_datetimes()` runs whatever it is given through
 * `Utility::normalize_timezone_string()`, so a choice value is written happily.
 *
 * @return string[] Timezone identifiers, 'UTC', and 'UTC±N' manual offsets.
 */
function get_allowed(): array {
	$allowed = array();

	foreach ( get_choices() as $zones ) {
		$allowed = array_merge( $allowed, array_keys( $zones ) );
	}

	return $allowed;
}

/**
 * Reduce a timezone to a form both GatherPress spellings agree on.
 *
 * The two GatherPress lists spell a UTC offset differently: the choices core
 * builds say `UTC+10`, and what lands in the events table (and in
 * `list_timezone_and_utc_offsets()`) says `+10:00`. Its own
 * `normalize_timezone_string()` maps the first onto the second, except at zero,
 * which it collapses to `UTC` on the way in while `wp_timezone_string()` and
 * the events table both keep saying `+00:00`. Folding that one case here makes
 * the mapping total.
 *
 * @param string $timezone Any spelling of a timezone.
 */
function canonicalize( string $timezone ): string {
	if ( ! class_exists( Utility::class ) ) {
		return $timezone;
	}

	$normalized = Utility::normalize_timezone_string( $timezone );

	return preg_match( '/^[+-]00:00$/', $normalized ) ? 'UTC' : $normalized;
}

/**
 * Every offerable value, indexed by the form both spellings agree on.
 *
 * Without this, reading a stored `+00:00` back into the form matched no option,
 * and a `<select>` with no matching value falls back to its first one — so
 * opening an existing event and saving it would have quietly rescheduled it
 * into Africa/Abidjan.
 *
 * First spelling wins, so the plain `UTC` choice is preferred over the
 * `UTC+0` manual offset that resolves to the same instant: core emits the UTC
 * group before the manual offsets.
 *
 * @return array<string, string> Canonical form => choice spelling.
 */
function get_stored_spellings(): array {
	$map = array();

	foreach ( get_allowed() as $choice ) {
		$canonical = canonicalize( $choice );

		if ( ! isset( $map[ $canonical ] ) ) {
			$map[ $canonical ] = $choice;
		}
	}

	return $map;
}

/**
 * Normalize a timezone to the spelling the form's control offers.
 *
 * Accepts both a choice value and the spelling GatherPress stores, so the same
 * function serves the write path (validating a submission) and the read path
 * (preselecting the control for an existing event).
 *
 * Anything unrecognized becomes the empty string, which every caller reads as
 * "fall back to the site's own zone". Deliberately lossy rather than fatal: an
 * unknown zone would otherwise be written to the events table and make every
 * later read of that event's date throw.
 *
 * @param mixed $timezone Submitted or stored timezone.
 * @return string An offerable timezone, or '' when the value is not one.
 */
function sanitize( $timezone ): string {
	$timezone = trim( (string) $timezone );

	if ( '' === $timezone ) {
		return '';
	}

	if ( in_array( $timezone, get_allowed(), true ) ) {
		return $timezone;
	}

	return get_stored_spellings()[ canonicalize( $timezone ) ] ?? '';
}

/**
 * The timezone an event is scheduled in, or '' when it has no usable one.
 *
 * @param int $event_id Event post ID.
 */
function get_event_timezone( int $event_id ): string {
	if ( ! class_exists( '\GatherPress\Core\Event\Event' ) ) {
		return '';
	}

	$datetime = ( new \GatherPress\Core\Event\Event( $event_id ) )->get_datetime();

	return sanitize( $datetime['timezone'] ?? '' );
}

/**
 * The timezone to schedule an event in when nothing else has said.
 *
 * The site's own zone, which for a group site is whatever the network admin
 * chose at provisioning. That can still be a bare UTC offset when provisioning
 * left it unset, in which case this returns it as-is: it is what the event
 * would have been scheduled in anyway, and an organizer who knows better now
 * has a control to say so.
 */
function get_default(): string {
	$timezone = sanitize( wp_timezone_string() );

	return '' === $timezone ? 'UTC' : $timezone;
}

/**
 * What a company's list of events says about them: its hint, the urgent ones, and which are past.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { isEventOver, isEventUnderWay } from './dates';
import type { Company, Event, Flag, Sponsorship } from './types';

/**
 * How many days before its event a sponsor that isn't approved yet turns urgent.
 */
const SOON_DAYS = 7;

/**
 * Says how a company's events come to be approved, above the list of them.
 *
 * @param company        The company.
 * @param company.status Its status.
 * @param company.flags  Its flags.
 * @param events         Its sponsorships.
 * @return The hint, or an empty string where there's nothing to say.
 */
export function getEventsHint(
	{ status, flags }: Pick< Company, 'status' | 'flags' >,
	events: Pick< Sponsorship, 'status' >[]
): string {
	// A lapsed company is still Compliant, but approves nothing until it's vetted again.
	if ( flags.some( ( { type } ) => type === 'lapsed' ) ) {
		return __( 'The last vetting is too old to approve events. Vet the company again.', 'wordcamporg' );
	}

	if ( status === 'sv-compliant' ) {
		return __( 'New events are approved automatically while the vetting is current.', 'wordcamporg' );
	}

	if ( events.some( ( event ) => event.status === 'svs-pending' ) ) {
		return __(
			'Pending events are approved automatically when the company becomes Compliant.',
			'wordcamporg'
		);
	}

	return '';
}

/**
 * Flags a sponsorship that still isn't approved with its event a week away or less, which is when it turns urgent,
 * and stays so until the event's last day.
 *
 * @param sponsorship        The sponsorship.
 * @param sponsorship.status Its status.
 * @param sponsorship.event  Its event.
 * @param today              Today, as `Y-m-d`.
 * @return The flag, or nothing for one that's decided, or whose event is further off, undated or over.
 */
export function getEventFlag(
	{ status, event }: Pick< Sponsorship, 'status' | 'event' >,
	today: string
): Flag | undefined {
	if ( status !== 'svs-pending' || ! event.date ) {
		return undefined;
	}

	if ( isEventUnderWay( event, today ) ) {
		return { type: 'event_soon', text: __( 'Event under way', 'wordcamporg' ) };
	}

	const days = Math.round(
		( Date.parse( `${ event.date }T00:00:00Z` ) - Date.parse( `${ today }T00:00:00Z` ) ) / 86400000
	);

	if ( days < 0 || days > SOON_DAYS ) {
		return undefined;
	}

	return {
		type: 'event_soon',
		text:
			days === 0
				? __( 'Event today', 'wordcamporg' )
				: /* translators: %d: Number of days. */
				  sprintf( _n( 'Event in %d day', 'Event in %d days', days, 'wordcamporg' ), days ),
	};
}

/**
 * Tells the events a company's list shows from the ones it folds away: those that are over, apart from the one
 * the company was opened from.
 *
 * @param events The company's sponsorships, in the order they're listed.
 * @param today  Today, as `Y-m-d`.
 * @param openId The sponsorship the company was opened from, if any.
 * @return The sponsorships that are `shown` and the `past` ones, each in the order given.
 */
export function splitEvents< Item extends { id: number; event: Event } >(
	events: Item[],
	today: string,
	openId?: number
): { shown: Item[]; past: Item[] } {
	const isPast = ( { id, event }: Item ) => isEventOver( event, today ) && id !== openId;

	return {
		shown: events.filter( ( item ) => ! isPast( item ) ),
		past: events.filter( isPast ),
	};
}

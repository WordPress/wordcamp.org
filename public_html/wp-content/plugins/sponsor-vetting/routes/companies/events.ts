import { __, _n, sprintf } from '@wordpress/i18n';
import type { Company, Flag, Sponsorship } from './types';

/* How many days before its event a sponsor that isn't approved yet turns urgent. */
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
 * Flags a sponsorship that still isn't approved with its event a week away or less, which is when it turns urgent.
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

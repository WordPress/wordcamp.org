import { __ } from '@wordpress/i18n';
import type { Company, Sponsorship } from './types';

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

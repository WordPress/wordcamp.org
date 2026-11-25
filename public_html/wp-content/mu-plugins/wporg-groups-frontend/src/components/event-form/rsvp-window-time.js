/**
 * Converts the event form's RSVP window between seconds and an amount of
 * hours, days or weeks (#2397).
 *
 * @package WordCamp\Groups\Frontend
 */

export const UNIT_SECONDS = {
	weeks: 7 * 24 * 60 * 60,
	days: 24 * 60 * 60,
	hours: 60 * 60,
};

/**
 * @param {?number} seconds Seconds before the start, or null when unset.
 * @return {{amount: string, unit: string}} In the largest unit that divides it evenly.
 */
export function fromSeconds( seconds ) {
	if ( seconds === null || seconds === undefined || seconds === '' ) {
		return { amount: '', unit: 'days' };
	}
	const unit = Object.keys( UNIT_SECONDS ).find( ( u ) => seconds % UNIT_SECONDS[ u ] === 0 ) || 'hours';
	return { amount: String( Math.round( seconds / UNIT_SECONDS[ unit ] ) ), unit };
}

/**
 * @param {{amount: string, unit: string}} value
 * @return {?number} Seconds before the start, or null when left empty.
 */
export function toSeconds( value ) {
	const amount = parseInt( value?.amount, 10 );
	if ( ! Number.isFinite( amount ) || amount < 0 ) {
		return null;
	}
	return amount * ( UNIT_SECONDS[ value.unit ] || UNIT_SECONDS.days );
}

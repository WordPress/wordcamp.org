import { __ } from '@wordpress/i18n';
import type { Badge } from '@wordpress/ui';
import type { ComponentProps } from 'react';
import type { CompanyStatus, SponsorshipStatus } from './types';

type Intent = NonNullable< ComponentProps< typeof Badge >[ 'intent' ] >;

type Status = {
	label: string;
	intent: Intent;
};

/* The vetting pipeline, in the order companies move through it. Mirrors get_company_statuses(). */
export const COMPANY_STATUSES: Record< CompanyStatus, Status > = {
	'sv-needs-vetting': { label: __( 'Needs Vetting', 'wordcamporg' ), intent: 'informational' },
	'sv-in-progress': { label: __( 'In Progress', 'wordcamporg' ), intent: 'draft' },
	'sv-compliance-req': { label: __( 'Compliance Requested', 'wordcamporg' ), intent: 'low' },
	'sv-compliant': { label: __( 'Compliant', 'wordcamporg' ), intent: 'stable' },
	'sv-not-eligible': { label: __( 'Not Eligible', 'wordcamporg' ), intent: 'high' },
	'sv-inactive': { label: __( 'Inactive', 'wordcamporg' ), intent: 'none' },
};

/* Mirrors get_sponsorship_statuses(). */
export const SPONSORSHIP_STATUSES: Record< SponsorshipStatus, Status > = {
	'svs-pending': { label: __( 'Pending', 'wordcamporg' ), intent: 'none' },
	'svs-approved': { label: __( 'Approved', 'wordcamporg' ), intent: 'stable' },
	'svs-not-approved': { label: __( 'Not Approved', 'wordcamporg' ), intent: 'high' },
	'svs-canceled': { label: __( 'Canceled', 'wordcamporg' ), intent: 'draft' },
};

export const COMPANY_STATUS_ORDER = Object.keys( COMPANY_STATUSES ) as CompanyStatus[];

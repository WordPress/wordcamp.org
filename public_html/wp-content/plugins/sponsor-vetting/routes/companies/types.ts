import type { CompanyStatus, SponsorshipStatus } from './entities';

/**
 * The records as REST returns them in the `edit` context.
 */

export type FlagType = 'lapsed' | 'not_eligible' | 'not_eligible_ended' | 'good_faith' | 'replied';

export type Flag = {
	type: FlagType;
	text: string;
	url?: string;
	date?: string;
	overdue?: boolean;
};

export type LogEntry = {
	time: string;
	html: string;
};

type Title = {
	raw: string;
	rendered: string;
};

export type Company = {
	id: number;
	title: Title;
	status: CompanyStatus;
	domains: string[];
	flags: Flag[];
	next_event: string;
	sponsorships: number;
	last_vetted: string;
};

export type CompanyDetails = Company & {
	meta: {
		_sv_contact_first_name: string;
		_sv_contact_last_name: string;
		_sv_contact_email: string;
		_sv_gpl_findings: string;
		_sv_trademark_findings: string;
		_sv_not_eligible_until: string;
		_sv_good_faith_due: string;
	};
	mes: string;
	conversations: { type: string; url: string; time: string }[];
	log: LogEntry[];
};

export type Sponsorship = {
	id: number;
	title: Title;
	status: SponsorshipStatus;
	event: { name: string; date: string };
	approved: string;
	log: LogEntry[];
};

/* A post status, from core's `statuses` endpoint. */
export type Status = {
	slug: string;
	name: string;
};

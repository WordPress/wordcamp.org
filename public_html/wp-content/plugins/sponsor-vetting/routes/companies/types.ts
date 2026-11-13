import type { CompanyStatus, SponsorshipStatus, TRASH } from './entities';

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

export type Conversation = {
	type: string;
	url: string;
	time: string;
};

type Title = {
	raw: string;
	rendered: string;
};

/* What a company and a sponsorship have in common beyond what their lists show. */
type Details = {
	allowed_statuses: string[];
	conversations: Conversation[];
	log: LogEntry[];
	note: string;
};

export type Company = {
	id: number;
	title: Title;
	status: CompanyStatus | typeof TRASH;
	domains: string[];
	flags: Flag[];
	next_event: string;
	sponsorships: number;
	last_vetted: string;
	trashed_status: string;
};

export type CompanyDetails = Company &
	Details & {
		meta: {
			_sv_contact_first_name: string;
			_sv_contact_last_name: string;
			_sv_contact_email: string;
			_sv_gpl_findings: string;
			_sv_trademark_findings: string;
			_sv_not_eligible_until: string;
		};
		good_faith_due: string;
		mes: number;
		mes_options: { id: number; name: string }[];
	};

export type Event = {
	name: string;
	date: string;
};

export type Sponsorship = {
	id: number;
	title: Title;
	status: SponsorshipStatus | typeof TRASH;
	company: number;
	event: Event;
	approved: string;
	trashed_status: string;
};

/* A sponsorship as its company's panel lists it. */
export type CompanySponsorship = Pick< Sponsorship, 'id' | 'title' | 'status' | 'event' | 'approved' > & {
	log: LogEntry[];
};

export type SponsorshipDetails = Sponsorship &
	Details & {
		suggestion: { id: number; reason: string } | null;
		sponsor: {
			name: string;
			website: string;
			/* The website as an address that's safe to link to, or an empty string. */
			website_url: string;
			first_name: string;
			last_name: string;
			email: string;
			edit_url: string;
		} | null;
		approvable: 'approve' | 'good_faith' | '';
		approval_hint: string;
		good_faith: boolean;
		domain: string;
		approved_domain: string;
		website_changed: boolean;
		needs_approval_email: boolean;
	};

/* A record while it's edited: core-data hands its title over as the text itself. */
export type Edited< Record extends { title: Title } > = Omit< Record, 'title' > & { title: string };

/* A post status, from core's `statuses` endpoint. */
export type Status = {
	slug: string;
	name: string;
};

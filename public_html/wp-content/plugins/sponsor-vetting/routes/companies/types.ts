/**
 * The records as the REST API returns them, and what the screen makes of them.
 */

/**
 * `event_soon` is the screen's own: the rest come with a company.
 */
export type FlagType = 'lapsed' | 'not_eligible' | 'not_eligible_ended' | 'good_faith' | 'replied' | 'event_soon';

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

/**
 * What a company and a sponsorship have in common beyond what their lists show.
 */
type Details = {
	allowed_statuses: string[];
	conversations: Conversation[];
	log: LogEntry[];
	note: string;
};

export type Company = {
	id: number;
	title: Title;
	status: string;
	domains: string[];
	flags: Flag[];
	next_event: string;
	sponsorships: number;
	last_vetted: string;
	trashed_status: string;
};

export type CompanyDetails = Company &
	Details & {
		contact_first_name: string;
		contact_last_name: string;
		contact_email: string;
		gpl_findings: string;
		trademark_findings: string;
		not_eligible_until: string;
		good_faith_due: string;
		mes: number;
		mes_options: { id: number; name: string }[];
	};

export type Event = {
	name: string;
	/* The day it starts, as `Y-m-d`, or an empty string for an event without a date. */
	date: string;
	/* Its last day, which is the day it starts for a one-day event. */
	end: string;
};

/**
 * What the list of events shows of a sponsorship's company: its name as it's stored, its status and its flags.
 */
export type CompanySummary = {
	name: string;
	status: string;
	flags: Flag[];
};

export type Sponsorship = {
	id: number;
	title: Title;
	status: string;
	company: number;
	/** Null for a sponsorship without a company. */
	company_summary: CompanySummary | null;
	event: Event;
	approved: string;
	good_faith: boolean;
	trashed_status: string;
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
		domain: string;
		approved_domain: string;
		website_changed: boolean;
		needs_approval_email: boolean;
	};

/**
 * A sponsorship as its company lists it: an event, and how its approval stands.
 */
export type CompanySponsorship = Pick<
	SponsorshipDetails,
	'id' | 'title' | 'status' | 'event' | 'approved' | 'good_faith' | 'log'
>;

/**
 * What a vetter does about one of a company's events. Read only for the events its list shows: most of it is
 * looked up on the event's site.
 */
export type EventActions = Pick<
	SponsorshipDetails,
	| 'id'
	| 'allowed_statuses'
	| 'approvable'
	| 'domain'
	| 'approved_domain'
	| 'website_changed'
	| 'needs_approval_email'
	| 'conversations'
	| 'sponsor'
>;

/**
 * A record while it's edited: core-data hands its title over as the text itself.
 */
export type Edited< Record extends { title: Title } > = Omit< Record, 'title' > & { title: string };

/**
 * A status of a company or of a sponsorship.
 */
export type Status = {
	slug: string;
	name: string;
};

/**
 * One of the two post types, as REST describes it: add_rest_statuses() adds its statuses, in their own order.
 */
export type PostType = {
	slug: string;
	statuses: Status[];
};

/**
 * The statuses of the two post types, and every status's label, the Trash included, keyed by status.
 */
export type Statuses = {
	company: Status[];
	sponsorship: Status[];
	labels: Record< string, string >;
};

/**
 * What a record has that puts it in the Trash and takes it back out.
 */
export type Trashable = {
	id: number;
	title: { raw: string };
	status: string;
	trashed_status: string;
};

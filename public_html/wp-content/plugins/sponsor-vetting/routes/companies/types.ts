/**
 * What the REST routes in `includes/rest.php` return.
 */

export type CompanyStatus =
	| 'sv-needs-vetting'
	| 'sv-in-progress'
	| 'sv-compliance-req'
	| 'sv-compliant'
	| 'sv-not-eligible'
	| 'sv-inactive';

export type SponsorshipStatus = 'svs-pending' | 'svs-approved' | 'svs-not-approved' | 'svs-canceled';

export type FlagType = 'lapsed' | 'not_eligible' | 'not_eligible_ended' | 'good_faith' | 'replied';

export type Flag = {
	type: FlagType;
	text: string;
	url?: string;
	date?: string;
	overdue?: boolean;
};

export type Company = {
	id: number;
	name: string;
	domains: string[];
	status: CompanyStatus;
	flags: Flag[];
	next_event: string;
	last_vetted: string;
	sponsorships: number;
};

export type CompanyEvent = {
	id: number;
	name: string;
	date: string;
	status: SponsorshipStatus;
	approved: string;
	edit_url: string;
};

export type LogEntry = {
	time: string;
	event: string;
	html: string;
};

export type CompanyDetails = Company & {
	contact: {
		first_name: string;
		last_name: string;
		email: string;
	};
	gpl_findings: string;
	trademark_findings: string;
	not_eligible_until: string;
	good_faith_due: string;
	mes: string;
	events: CompanyEvent[];
	conversations: { url: string; time: string }[];
	log: LogEntry[];
	edit_url: string;
};

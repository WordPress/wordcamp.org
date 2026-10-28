<?php
/**
 * Plugin Name: Sponsor Vetting
 * Description: Vets each sponsor company once, on Central, and tracks every event's sponsorship of it.
 * Version:     0.1
 * Author:      WordCamp Central
 * Author URI:  https://wordcamp.org
 *
 * Network-activated. Companies and sponsorships live on central.wordcamp.org, where reviewers with
 * `wordcamp_vet_sponsors` vet them; camp sites only keep a pointer on their `wcb_sponsor` posts, request vetting,
 * and show organizers where it stands. Nothing here is public, in REST, or in search.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The company post type: one per sponsor company, vetted once.
 */
const COMPANY_POST_TYPE = 'sv_company';

/**
 * The sponsorship post type: one per camp sponsor, a child of its company.
 */
const SPONSORSHIP_POST_TYPE = 'sv_sponsorship';

/**
 * The capability that grants access to the registry, through the `sponsor_vetter` subrole.
 */
const CAPABILITY = 'wordcamp_vet_sponsors';

/**
 * How many months a Compliant vetting stays good for new sponsorships.
 */
const REVETTING_MONTHS = 12;

/**
 * How many days a sponsor approved in good faith has to finish their changes.
 */
const GOOD_FAITH_DAYS = 15;

/**
 * The FreeScout mailbox emails are sent from, named as in its `FREESCOUT_COMMUNITY_MAILBOX_ID` constant.
 */
const MAILBOX = 'community';

require_once __DIR__ . '/includes/post-types.php';
require_once __DIR__ . '/includes/workflow.php';
require_once __DIR__ . '/includes/domains.php';
require_once __DIR__ . '/includes/intake.php';
require_once __DIR__ . '/includes/emails.php';
require_once __DIR__ . '/includes/replies.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/airtable.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/organizer.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
	require_once __DIR__ . '/includes/admin-companies.php';
	require_once __DIR__ . '/includes/admin-sponsorships.php';
	require_once __DIR__ . '/includes/admin-import.php';
}

/**
 * Whether the current site is central.wordcamp.org, where the registry lives.
 *
 * @return bool
 */
function is_central(): bool {
	return WORDCAMP_ROOT_BLOG_ID === get_current_blog_id();
}

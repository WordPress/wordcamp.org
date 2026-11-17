<?php
/**
 * The abilities an agent vets sponsors with, and the MCP server on Central that offers them.
 *
 * Each ability asks the REST endpoints the vetting screen uses, as the user the agent signed in as, usually with an
 * Application Password. So an agent can do what that vetter can do on the screen, and nothing more: the workflow's
 * rules, the approval checks, and the emails happen where they happen for the screen.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'WPINC' ) || die();

/**
 * The abilities' category, and the prefix of their names.
 */
const ABILITY_CATEGORY = 'sponsor-vetting';

/**
 * The MCP server's ID and route: `/wp-json/mcp/sponsor-vetting` on Central.
 */
const MCP_SERVER_ID = 'sponsor-vetting';

/**
 * The company statuses an agent works through, which list-companies lists unless asked for others.
 */
const QUEUE_STATUSES = array( 'sv-needs-vetting', 'sv-in-progress', 'sv-compliance-req' );

/**
 * Starts the MCP adapter on Central, which registers its servers when the REST API starts.
 *
 * The adapter comes from the root Composer autoloader; without it there's no server, and the abilities stay
 * available to code.
 */
function load_mcp_adapter(): void {
	if ( is_central() && class_exists( McpAdapter::class ) ) {
		McpAdapter::instance();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_mcp_adapter' );

/**
 * Whether the current user may vet sponsors, which every ability and the server ask first.
 *
 * The capability is only granted on Central, where the records are.
 *
 * @return bool
 */
function can_vet(): bool {
	return current_user_can( CAPABILITY );
}

/**
 * Registers the abilities' category.
 *
 * Everywhere, like the abilities: the registry fills once per request, on whichever site asks first, which may not
 * be Central even when the request later switches there. Only vetters can run them, and only on Central.
 */
function register_ability_category(): void {
	wp_register_ability_category(
		ABILITY_CATEGORY,
		array(
			'label'       => __( 'Sponsor Vetting', 'wordcamporg' ),
			'description' => __( 'Vetting sponsor companies and approving their sponsorships of events.', 'wordcamporg' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_ability_category' );

/**
 * Registers the abilities.
 */
function register_abilities(): void {
	foreach ( get_abilities() + get_guidance_abilities() as $name => $ability ) {
		wp_register_ability(
			$name,
			$ability + array(
				'category'            => ABILITY_CATEGORY,
				'permission_callback' => __NAMESPACE__ . '\can_vet',
			)
		);
	}
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_abilities' );

/**
 * Registers the MCP server, which offers the abilities to vetters alone: the workflow as a prompt, the Community
 * team's handbook pages as resources, and everything else as tools.
 *
 * @param McpAdapter $adapter The adapter.
 */
function register_mcp_server( McpAdapter $adapter ): void {
	if ( ! is_central() ) {
		return;
	}

	$guidance = array(
		'prompt'   => array(),
		'resource' => array(),
	);

	foreach ( get_guidance_abilities() as $name => $ability ) {
		$guidance[ $ability['meta']['mcp']['type'] ][] = $name;
	}

	$adapter->create_server(
		MCP_SERVER_ID,
		'mcp',
		MCP_SERVER_ID,
		__( 'WordCamp Sponsor Vetting', 'wordcamporg' ),
		__( 'Vets sponsor companies for WordPress events and approves their sponsorships, on WordCamp Central. Start with the sponsor-vetting-vet-sponsors prompt, which walks through the workflow, and read the wordcamp://sponsor-vetting/ resources for what a review checks.', 'wordcamporg' ),
		'1.0.0',
		array( HttpTransport::class ),
		ErrorLogMcpErrorHandler::class,
		NullMcpObservabilityHandler::class,
		array_keys( get_abilities() ),
		$guidance['resource'],
		$guidance['prompt'],
		__NAMESPACE__ . '\can_vet'
	);
}
add_action( 'mcp_adapter_init', __NAMESPACE__ . '\register_mcp_server' );

/**
 * The Community handbook pages an agent vets by, which the server offers as resources.
 *
 * @return array[] Each page's `label`, `description`, `url`, and the `uri` it's read at, keyed by ability name.
 */
function get_handbook_pages(): array {
	return array(
		'sponsor-vetting/vetting-process' => array(
			'label'       => __( 'Sponsor Vetting Process', 'wordcamporg' ),
			'description' => __( 'The Community team’s sponsor vetting process, from the Community handbook: what the trademark and GPL reviews check, how findings are recorded, when to ask a sponsor for changes, and when to approve in good faith. It describes the work in Airtable, which these tools replace.', 'wordcamporg' ),
			'url'         => 'https://make.wordpress.org/community/handbook/community-deputy/super-deputies/sponsor-vetting-process/',
			'uri'         => 'wordcamp://sponsor-vetting/vetting-process',
		),
		'sponsor-vetting/gpl-checklist'   => array(
			'label'       => __( '100% GPL Vetting Checklist', 'wordcamporg' ),
			'description' => __( 'The Community handbook’s checklist for whether a sponsor that sells or promotes WordPress themes or plugins meets the GPL requirements.', 'wordcamporg' ),
			'url'         => 'https://make.wordpress.org/community/handbook/wordcamp-organizer/first-steps/helpful-documents-and-templates/100-gpl-vetting-checklist/',
			'uri'         => 'wordcamp://sponsor-vetting/gpl-checklist',
		),
	);
}

/**
 * The abilities that guide an agent rather than act: the workflow as a prompt, and the handbook pages as resources.
 *
 * @return array[] Each ability's arguments for wp_register_ability(), without its category and permission callback.
 */
function get_guidance_abilities(): array {
	$abilities = array(
		'sponsor-vetting/vet-sponsors' => array(
			'label'            => __( 'Vet sponsors', 'wordcamporg' ),
			'description'      => __( 'Walks through vetting sponsors, from linking new sponsorships to a company to approving them: the whole queue, or one company.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(
					'company' => array(
						'type'        => 'string',
						'description' => __( 'The ID of one company to vet. Leave it out to work through the queue.', 'wordcamporg' ),
					),
				),
			),
			'execute_callback' => __NAMESPACE__ . '\get_vetting_prompt',
			'meta'             => array(
				'mcp'         => array( 'type' => 'prompt' ),
				'annotations' => array( 'readonly' => true ),
			),
		),
	);

	foreach ( get_handbook_pages() as $name => $page ) {
		$abilities[ $name ] = array(
			'label'            => $page['label'],
			'description'      => $page['description'],
			'execute_callback' => static fn(): array => get_handbook_page( $page['uri'], $page['url'] ),
			'meta'             => array(
				'mcp' => array(
					'type'     => 'resource',
					'uri'      => $page['uri'],
					'mimeType' => 'text/markdown',
				),
			),
		);
	}

	return $abilities;
}

/**
 * Builds the vetting workflow prompt.
 *
 * @param array|null $input The prompt's arguments.
 * @return array The prompt's messages.
 */
function get_vetting_prompt( ?array $input = null ): array {
	$company = absint( $input['company'] ?? 0 );
	$scope   = $company
		/* translators: %d: Company ID. */
		? sprintf( __( 'Vet company %d: open it with sponsor-vetting-get-company, then follow the steps from step 3.', 'wordcamporg' ), $company )
		: __( 'Work through the sponsor vetting queue.', 'wordcamporg' );

	$steps = sprintf(
		/* translators: %d: Number of months. */
		__(
			'A company is vetted once. Each event that takes its sponsorship has a sponsorship under it, which can be approved once the company is Compliant and was vetted within the last %d months. Read the wordcamp://sponsor-vetting/vetting-process and wordcamp://sponsor-vetting/gpl-checklist resources first: they say what a review checks. They describe the work in Airtable; here each step is a tool.

1. New sponsorships wait for a company: sponsor-vetting-list-sponsorships with company 0. Link each to the company suggested, or another one, with sponsor-vetting-link-sponsorship, or create one with sponsor-vetting-create-company. Look a website up with sponsor-vetting-list-companies and its domain before creating a company.
2. Work through the queue from sponsor-vetting-list-companies, starting with the soonest next_event.
3. Open the company with sponsor-vetting-get-company, and set it to In Progress with sponsor-vetting-set-company-status while you vet it, so no one else does.
4. Review its websites for trademark and GPL compliance, as the handbook describes, and write what you find with sponsor-vetting-update-company: the GPL and trademark findings, in Markdown, and the contact if it’s missing. With no issues, write “All good” in both. The findings are what a compliance request emails to the sponsor, so write them to the sponsor.
5. Decide, and confirm with the person you’re working with before any step that emails or approves:
   - No issues: set the company to Compliant. That approves its pending sponsorships, for the websites it was vetted for, and emails their events.
   - Issues to fix: sponsor-vetting-send-compliance-request emails the findings to the contact, copying an event, and moves the company to Compliance Requested. Send it once. When the sponsor replies and issues remain, add a note saying what’s left, for a person to reply to.
   - The sponsor can’t take part: set it to Not Eligible, with a not_eligible_until date set with sponsor-vetting-update-company, or none for good.
   - An event that can’t wait for a sponsor who has committed to the changes: approve its sponsorship in good faith with sponsor-vetting-set-sponsorship-status.
6. Record your reasoning with sponsor-vetting-add-note. Status changes, notes and emails are logged under your name.

When unsure, add a note and leave the decision to a person.',
			'wordcamporg'
		),
		REVETTING_MONTHS
	);

	return array(
		'messages' => array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => $scope . "\n\n" . $steps,
				),
			),
		),
	);
}

/**
 * Reads a Community handbook page, as Markdown, for a resource.
 *
 * The handbook lives on make.wordpress.org, which serves a page's Markdown to a request that accepts it. Kept for
 * a while, so reading a resource doesn't ask every time.
 *
 * @param string $uri The resource's URI.
 * @param string $url The page's address.
 * @return array[] The resource's contents.
 */
function get_handbook_page( string $uri, string $url ): array {
	$key      = 'sv_handbook_' . md5( $url );
	$markdown = get_transient( $key );

	if ( ! is_string( $markdown ) ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'headers' => array( 'Accept' => 'text/markdown' ),
				'timeout' => 10,
			)
		);

		if (
			200 !== wp_remote_retrieve_response_code( $response )
			|| ! str_starts_with( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'text/markdown' )
		) {
			return array(
				array(
					'uri'      => $uri,
					'mimeType' => 'text/plain',
					/* translators: %s: Handbook page address. */
					'text'     => sprintf( __( 'The handbook page couldn’t be loaded. Read it at %s.', 'wordcamporg' ), $url ),
				),
			);
		}

		$markdown = wp_remote_retrieve_body( $response );

		set_transient( $key, $markdown, 12 * HOUR_IN_SECONDS );
	}

	return array(
		array(
			'uri'      => $uri,
			'mimeType' => 'text/markdown',
			'text'     => $markdown,
		),
	);
}

/**
 * The abilities, keyed by name.
 *
 * @return array[] Each ability's arguments for wp_register_ability(), without its category and permission callback.
 */
function get_abilities(): array {
	$id       = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$string   = array( 'type' => 'string' );
	$strings  = array(
		'type'  => 'array',
		'items' => $string,
	);
	$date     = array(
		'type'        => 'string',
		'pattern'     => '^(\d{4}-\d{2}-\d{2})?$',
		'description' => __( 'A date as YYYY-MM-DD, or an empty string for none.', 'wordcamporg' ),
	);
	$page     = array(
		'type'    => 'integer',
		'minimum' => 1,
		'default' => 1,
	);
	$per_page = array(
		'type'    => 'integer',
		'minimum' => 1,
		'maximum' => 100,
		'default' => 20,
	);
	$company  = get_company_schema();
	$sponsor  = get_sponsorship_schema();
	$message  = array(
		'type'       => 'object',
		'properties' => array( 'message' => $string ),
	);
	$reads    = array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
	$edits    = array(
		'annotations' => array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
	// Each call adds another record or note.
	$additions = array(
		'annotations' => array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		),
	);
	// Emails and approvals reach people outside the site, and can't be taken back.
	$effects = array(
		'annotations' => array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		),
	);

	return array(
		'sponsor-vetting/list-companies'          => array(
			'label'            => __( 'List companies', 'wordcamporg' ),
			'description'      => __( 'Lists sponsor companies, oldest first: by default the ones still being vetted (Needs Vetting, In Progress, Compliance Requested), or, looking a website up, any. Each comes with its status, websites, flags, the date of its next event and how many sponsorships it has.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(
					'status'   => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array_keys( get_company_statuses() ),
						),
						'description' => __( 'The statuses to list.', 'wordcamporg' ),
					),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Words in the company’s name.', 'wordcamporg' ),
					),
					'domain'   => array(
						'type'        => 'string',
						'description' => __( 'A website, to find the company it belongs to.', 'wordcamporg' ),
					),
					'page'     => $page,
					'per_page' => $per_page,
				),
			),
			'output_schema'    => array(
				'type'       => 'object',
				'properties' => array(
					'companies'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback' => __NAMESPACE__ . '\list_companies',
			'meta'             => $reads,
		),
		'sponsor-vetting/get-company'             => array(
			'label'            => __( 'Get a company', 'wordcamporg' ),
			'description'      => __( 'Gets everything about a company: its status and the statuses it can change to, websites, contact, GPL and trademark findings, flags, linked Multi-Event Sponsor, emails, notes and history, and its sponsorships.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => $id ),
			),
			'output_schema'    => $company,
			'execute_callback' => __NAMESPACE__ . '\get_company',
			'meta'             => $reads,
		),
		'sponsor-vetting/list-sponsorships'       => array(
			'label'            => __( 'List sponsorships', 'wordcamporg' ),
			'description'      => __( 'Lists event sponsorships, oldest first: by default the pending ones. Company 0 lists the ones still waiting to be linked to a company.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(
					'status'   => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array_keys( get_sponsorship_statuses() ),
						),
						'description' => __( 'The statuses to list.', 'wordcamporg' ),
					),
					'company'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Only this company’s sponsorships, or 0 for those without a company.', 'wordcamporg' ),
					),
					'page'     => $page,
					'per_page' => $per_page,
				),
			),
			'output_schema'    => array(
				'type'       => 'object',
				'properties' => array(
					'sponsorships' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'total'        => array( 'type' => 'integer' ),
					'total_pages'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback' => __NAMESPACE__ . '\list_sponsorships',
			'meta'             => $reads,
		),
		'sponsor-vetting/get-sponsorship'         => array(
			'label'            => __( 'Get a sponsorship', 'wordcamporg' ),
			'description'      => __( 'Gets everything about an event’s sponsorship: the event, the sponsor as the event entered it, its company or the one suggested, whether and how it can be approved, emails, notes and history.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => $id ),
			),
			'output_schema'    => $sponsor,
			'execute_callback' => __NAMESPACE__ . '\get_sponsorship',
			'meta'             => $reads,
		),
		'sponsor-vetting/create-company'          => array(
			'label'            => __( 'Create a company', 'wordcamporg' ),
			'description'      => __( 'Creates a company in Needs Vetting, for a sponsor no company covers yet, and links a sponsorship to it if given one.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'name' ),
				'properties' => array(
					'name'        => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'domains'     => $strings + array( 'description' => __( 'The company’s websites.', 'wordcamporg' ) ),
					'sponsorship' => $id + array( 'description' => __( 'A sponsorship to link to the new company.', 'wordcamporg' ) ),
				),
			),
			'output_schema'    => $company,
			'execute_callback' => __NAMESPACE__ . '\create_company_for_agent',
			'meta'             => $additions,
		),
		'sponsor-vetting/update-company'          => array(
			'label'            => __( 'Update a company', 'wordcamporg' ),
			'description'      => __( 'Changes a company’s details: only the ones given. Findings are Markdown, and a compliance request emails them to the sponsor as written. Use set-company-status for its status.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'                 => $id,
					'name'               => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'domains'            => $strings + array( 'description' => __( 'All of the company’s websites, replacing the ones it has.', 'wordcamporg' ) ),
					'contact_first_name' => $string,
					'contact_last_name'  => $string,
					'contact_email'      => $string + array( 'description' => __( 'Where compliance requests go.', 'wordcamporg' ) ),
					'gpl_findings'       => $string,
					'trademark_findings' => $string,
					'not_eligible_until' => $date,
					'mes'                => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The Multi-Event Sponsor to link, from the company’s mes_options, or 0 to unlink.', 'wordcamporg' ),
					),
				),
			),
			'output_schema'    => $company,
			'execute_callback' => __NAMESPACE__ . '\update_company',
			'meta'             => $edits,
		),
		'sponsor-vetting/set-company-status'      => array(
			'label'            => __( 'Set a company’s status', 'wordcamporg' ),
			'description'      => __( 'Moves a company along the vetting workflow, to one of its allowed_statuses. Compliant approves its pending sponsorships and emails their events. Not Eligible lasts until the company’s not_eligible_until date, or for good without one. To ask the sponsor for changes, use send-compliance-request instead.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'status' ),
				'properties' => array(
					'id'     => $id,
					'status' => array(
						'type' => 'string',
						'enum' => array_keys( get_company_statuses() ),
					),
				),
			),
			'output_schema'    => $company,
			'execute_callback' => __NAMESPACE__ . '\set_company_status',
			'meta'             => $effects,
		),
		'sponsor-vetting/send-compliance-request' => array(
			'label'            => __( 'Send a compliance request', 'wordcamporg' ),
			'description'      => __( 'Emails the company’s findings to its contact from the Community mailbox, copying the event of one of its pending sponsorships, and moves the company to Compliance Requested. The company needs a contact email and findings first.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'sponsorship' ),
				'properties' => array(
					'id'          => $id,
					'sponsorship' => $id + array( 'description' => __( 'The pending sponsorship whose event the request is for.', 'wordcamporg' ) ),
				),
			),
			'output_schema'    => $message,
			'execute_callback' => __NAMESPACE__ . '\send_compliance_request_for_agent',
			'meta'             => $effects,
		),
		'sponsor-vetting/link-sponsorship'        => array(
			'label'            => __( 'Link a sponsorship to a company', 'wordcamporg' ),
			'description'      => __( 'Links a sponsorship to its company, or moves it to another. A company that needs vetting goes back in the queue. If the company is already vetted for the sponsorship’s website, the sponsorship is approved and its event emailed.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'company' ),
				'properties' => array(
					'id'      => $id,
					'company' => $id,
				),
			),
			'output_schema'    => $sponsor,
			'execute_callback' => __NAMESPACE__ . '\link_sponsorship_for_agent',
			'meta'             => $effects,
		),
		'sponsor-vetting/set-sponsorship-status'  => array(
			'label'            => __( 'Set a sponsorship’s status', 'wordcamporg' ),
			'description'      => __( 'Approves a sponsorship, or moves it to one of its allowed_statuses. Approving needs the website you checked, and emails the event. Approve in good faith only when the sponsor has committed to the changes asked for and the event can’t wait.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'status' ),
				'properties' => array(
					'id'         => $id,
					'status'     => array(
						'type' => 'string',
						'enum' => array_keys( get_sponsorship_statuses() ),
					),
					'domain'     => $string + array( 'description' => __( 'For an approval: the sponsorship’s domain you checked. If the event has changed it since, the approval is refused.', 'wordcamporg' ) ),
					'good_faith' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'For an approval: approve while the company is Compliance Requested.', 'wordcamporg' ),
					),
				),
			),
			'output_schema'    => $sponsor,
			'execute_callback' => __NAMESPACE__ . '\set_sponsorship_status',
			'meta'             => $effects,
		),
		'sponsor-vetting/confirm-website'         => array(
			'label'            => __( 'Confirm a sponsorship’s new website', 'wordcamporg' ),
			'description'      => __( 'Confirms the website an event changed an approved sponsorship to, which its invoices wait for. If it’s a different company now, cancel the sponsorship instead.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'domain' ),
				'properties' => array(
					'id'     => $id,
					'domain' => $string + array( 'description' => __( 'The sponsorship’s current domain, which you checked.', 'wordcamporg' ) ),
				),
			),
			'output_schema'    => $sponsor,
			'execute_callback' => __NAMESPACE__ . '\confirm_website_for_agent',
			'meta'             => $edits,
		),
		'sponsor-vetting/send-approval-email'     => array(
			'label'            => __( 'Send an approval email', 'wordcamporg' ),
			'description'      => __( 'Sends an approved sponsorship’s approval email to its event again, when sending it failed.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => $id ),
			),
			'output_schema'    => $message,
			'execute_callback' => __NAMESPACE__ . '\send_approval_email_for_agent',
			'meta'             => $effects,
		),
		'sponsor-vetting/add-note'                => array(
			'label'            => __( 'Add a note', 'wordcamporg' ),
			'description'      => __( 'Adds a private note to a company’s or sponsorship’s history, for the other vetters.', 'wordcamporg' ),
			'input_schema'     => array(
				'type'       => 'object',
				'required'   => array( 'id', 'note' ),
				'properties' => array(
					'id'   => $id + array( 'description' => __( 'The company or sponsorship.', 'wordcamporg' ) ),
					'note' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
			),
			'output_schema'    => array(
				'type'       => 'object',
				'properties' => array( 'log' => get_log_schema() ),
			),
			'execute_callback' => __NAMESPACE__ . '\add_note_for_agent',
			'meta'             => $additions,
		),
	);
}

/**
 * Describes a company, as the abilities return it.
 *
 * @return array
 */
function get_company_schema(): array {
	$string  = array( 'type' => 'string' );
	$objects = array(
		'type'  => 'array',
		'items' => array( 'type' => 'object' ),
	);

	return array(
		'type'       => 'object',
		'properties' => array(
			'id'                 => array( 'type' => 'integer' ),
			'name'               => $string,
			'status'             => $string,
			'allowed_statuses'   => array(
				'type'  => 'array',
				'items' => $string,
			),
			'domains'            => array(
				'type'  => 'array',
				'items' => $string,
			),
			'contact'            => array(
				'type'       => 'object',
				'properties' => array(
					'first_name' => $string,
					'last_name'  => $string,
					'email'      => $string,
				),
			),
			'gpl_findings'       => $string,
			'trademark_findings' => $string,
			'not_eligible_until' => $string,
			'good_faith_due'     => $string,
			'last_vetted'        => $string,
			'flags'              => $objects,
			'mes'                => array( 'type' => 'integer' ),
			'mes_options'        => $objects,
			'conversations'      => $objects,
			'log'                => get_log_schema(),
			'sponsorships'       => $objects,
			'url'                => $string,
		),
	);
}

/**
 * Describes a sponsorship, as the abilities return it.
 *
 * @return array
 */
function get_sponsorship_schema(): array {
	$string = array( 'type' => 'string' );

	return array(
		'type'       => 'object',
		'properties' => array(
			'id'                   => array( 'type' => 'integer' ),
			'name'                 => $string,
			'status'               => $string,
			'allowed_statuses'     => array(
				'type'  => 'array',
				'items' => $string,
			),
			'company'              => array( 'type' => 'integer' ),
			'suggestion'           => array( 'type' => array( 'object', 'null' ) ),
			'event'                => array( 'type' => 'object' ),
			'sponsor'              => array( 'type' => array( 'object', 'null' ) ),
			'domain'               => $string,
			'approved'             => $string,
			'approved_domain'      => $string,
			'website_changed'      => array( 'type' => 'boolean' ),
			'approvable'           => $string,
			'approval_hint'        => $string,
			'needs_approval_email' => array( 'type' => 'boolean' ),
			'good_faith'           => array( 'type' => 'boolean' ),
			'conversations'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'log'                  => get_log_schema(),
			'url'                  => $string,
		),
	);
}

/**
 * Describes a record's notes and history, as the abilities return them.
 *
 * @return array
 */
function get_log_schema(): array {
	return array(
		'type'  => 'array',
		'items' => array(
			'type'       => 'object',
			'properties' => array(
				'time' => array( 'type' => 'string' ),
				'by'   => array( 'type' => 'string' ),
				'note' => array( 'type' => 'string' ),
				'from' => array( 'type' => 'string' ),
				'to'   => array( 'type' => 'string' ),
			),
		),
	);
}

/**
 * Asks one of the endpoints the vetting screen uses, as the current user, in the context the screen reads in.
 *
 * @param string $method The request's method.
 * @param string $route  The route.
 * @param array  $params Optional. The request's parameters.
 * @return \WP_REST_Response|\WP_Error The response, or the error it answered with.
 */
function ask_rest( string $method, string $route, array $params = array() ): \WP_REST_Response|\WP_Error {
	$request = new \WP_REST_Request( $method, $route );
	$request->set_param( 'context', 'edit' );

	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}

	$response = rest_do_request( $request );

	return $response->is_error() ? $response->as_error() : $response;
}

/**
 * Lists companies.
 *
 * @param array|null $input The ability's input.
 * @return array|\WP_Error
 */
function list_companies( ?array $input = null ): array|\WP_Error {
	$input = (array) $input;
	// A website's company is wanted whatever its status.
	$default_statuses = isset( $input['domain'] ) ? array_keys( get_company_statuses() ) : QUEUE_STATUSES;
	$params           = array(
		'status'   => $input['status'] ?? $default_statuses,
		'orderby'  => 'date',
		'order'    => 'asc',
		'page'     => $input['page'] ?? 1,
		'per_page' => $input['per_page'] ?? 20,
		'_fields'  => 'id,title,status,domains,flags,next_event,sponsorships,last_vetted',
	);

	if ( ! empty( $input['search'] ) ) {
		$params['search'] = (string) $input['search'];
	}

	if ( isset( $input['domain'] ) ) {
		// No company has an empty domain, so a website that isn't one matches none.
		$params['include'] = find_companies_by_domain( normalize_domain( (string) $input['domain'] ) ) ?: array( 0 );
	}

	$response = ask_rest( 'GET', '/wp/v2/sv-companies', $params );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return array(
		'companies'   => array_map(
			static fn( array $company ): array => array(
				'id'           => (int) $company['id'],
				'name'         => (string) $company['title']['raw'],
				'status'       => (string) $company['status'],
				'domains'      => $company['domains'],
				'flags'        => $company['flags'],
				'next_event'   => $company['next_event'],
				'sponsorships' => $company['sponsorships'],
				'last_vetted'  => $company['last_vetted'],
			),
			$response->get_data()
		),
		'total'       => (int) $response->get_headers()['X-WP-Total'],
		'total_pages' => (int) $response->get_headers()['X-WP-TotalPages'],
	);
}

/**
 * Gets a company, with its sponsorships.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error
 */
function get_company( array $input ): array|\WP_Error {
	$id      = (int) $input['id'];
	$company = ask_rest(
		'GET',
		'/wp/v2/sv-companies/' . $id,
		array( '_fields' => 'id,title,status,meta,allowed_statuses,domains,flags,last_vetted,good_faith_due,mes,mes_options,conversations' )
	);

	if ( is_wp_error( $company ) ) {
		return $company;
	}

	$sponsorships = ask_rest(
		'GET',
		'/wp/v2/sv-sponsorships',
		array(
			'parent'   => array( $id ),
			'status'   => array_keys( get_sponsorship_statuses() ),
			'per_page' => 100,
			'_fields'  => 'id,title,status,event,domain,approved,approvable',
		)
	);

	if ( is_wp_error( $sponsorships ) ) {
		return $sponsorships;
	}

	$company = $company->get_data();
	$meta    = $company['meta'];

	return array(
		'id'                 => $id,
		'name'               => (string) $company['title']['raw'],
		'status'             => (string) $company['status'],
		'allowed_statuses'   => $company['allowed_statuses'],
		'domains'            => $company['domains'],
		'contact'            => array(
			'first_name' => (string) $meta['_sv_contact_first_name'],
			'last_name'  => (string) $meta['_sv_contact_last_name'],
			'email'      => (string) $meta['_sv_contact_email'],
		),
		'gpl_findings'       => (string) $meta['_sv_gpl_findings'],
		'trademark_findings' => (string) $meta['_sv_trademark_findings'],
		'not_eligible_until' => (string) $meta['_sv_not_eligible_until'],
		'good_faith_due'     => $company['good_faith_due'],
		'last_vetted'        => $company['last_vetted'],
		'flags'              => $company['flags'],
		'mes'                => $company['mes'],
		'mes_options'        => $company['mes_options'],
		'conversations'      => $company['conversations'],
		'log'                => get_agent_log( $id ),
		'sponsorships'       => array_map(
			static fn( array $sponsorship ): array => array(
				'id'         => (int) $sponsorship['id'],
				'name'       => (string) $sponsorship['title']['raw'],
				'status'     => (string) $sponsorship['status'],
				'event'      => $sponsorship['event'],
				'domain'     => $sponsorship['domain'],
				'approved'   => $sponsorship['approved'],
				'approvable' => $sponsorship['approvable'],
			),
			$sponsorships->get_data()
		),
		'url'                => get_app_url( 'company', $id ),
	);
}

/**
 * Lists sponsorships.
 *
 * @param array|null $input The ability's input.
 * @return array|\WP_Error
 */
function list_sponsorships( ?array $input = null ): array|\WP_Error {
	$input  = (array) $input;
	$params = array(
		'status'   => $input['status'] ?? array( 'svs-pending' ),
		'orderby'  => 'date',
		'order'    => 'asc',
		'page'     => $input['page'] ?? 1,
		'per_page' => $input['per_page'] ?? 20,
		'_fields'  => 'id,title,status,company,event,domain,approved,approvable',
	);

	if ( isset( $input['company'] ) ) {
		$params['parent'] = array( (int) $input['company'] );
	}

	$response = ask_rest( 'GET', '/wp/v2/sv-sponsorships', $params );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return array(
		'sponsorships' => array_map(
			static fn( array $sponsorship ): array => array(
				'id'         => (int) $sponsorship['id'],
				'name'       => (string) $sponsorship['title']['raw'],
				'status'     => (string) $sponsorship['status'],
				'company'    => $sponsorship['company'],
				'event'      => $sponsorship['event'],
				'domain'     => $sponsorship['domain'],
				'approved'   => $sponsorship['approved'],
				'approvable' => $sponsorship['approvable'],
			),
			$response->get_data()
		),
		'total'        => (int) $response->get_headers()['X-WP-Total'],
		'total_pages'  => (int) $response->get_headers()['X-WP-TotalPages'],
	);
}

/**
 * Gets a sponsorship.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error
 */
function get_sponsorship( array $input ): array|\WP_Error {
	$id          = (int) $input['id'];
	$sponsorship = ask_rest(
		'GET',
		'/wp/v2/sv-sponsorships/' . $id,
		array( '_fields' => 'id,title,status,allowed_statuses,company,suggestion,event,sponsor,domain,approved,approved_domain,website_changed,approvable,approval_hint,needs_approval_email,good_faith,conversations' )
	);

	if ( is_wp_error( $sponsorship ) ) {
		return $sponsorship;
	}

	$sponsorship = $sponsorship->get_data();

	return array(
		'id'                   => $id,
		'name'                 => (string) $sponsorship['title']['raw'],
		'status'               => (string) $sponsorship['status'],
		'allowed_statuses'     => $sponsorship['allowed_statuses'],
		'company'              => $sponsorship['company'],
		'suggestion'           => $sponsorship['suggestion'],
		'event'                => $sponsorship['event'],
		'sponsor'              => $sponsorship['sponsor'],
		'domain'               => $sponsorship['domain'],
		'approved'             => $sponsorship['approved'],
		'approved_domain'      => $sponsorship['approved_domain'],
		'website_changed'      => $sponsorship['website_changed'],
		'approvable'           => $sponsorship['approvable'],
		'approval_hint'        => $sponsorship['approval_hint'],
		'needs_approval_email' => $sponsorship['needs_approval_email'],
		'good_faith'           => $sponsorship['good_faith'],
		'conversations'        => $sponsorship['conversations'],
		'log'                  => get_agent_log( $id ),
		'url'                  => get_app_url( 'sponsorship', $id ),
	);
}

/**
 * Creates a company, and links a sponsorship to it if given one.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The company.
 */
function create_company_for_agent( array $input ): array|\WP_Error {
	$created = ask_rest(
		'POST',
		'/wp/v2/sv-companies',
		array(
			'title'   => (string) $input['name'],
			'status'  => 'sv-needs-vetting',
			'domains' => $input['domains'] ?? array(),
		)
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$company_id = (int) $created->get_data()['id'];

	if ( ! empty( $input['sponsorship'] ) ) {
		$linked = ask_rest( 'POST', '/wp/v2/sv-sponsorships/' . (int) $input['sponsorship'], array( 'company' => $company_id ) );

		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
	}

	return get_company( array( 'id' => $company_id ) );
}

/**
 * Changes the company details it's given.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The company.
 */
function update_company( array $input ): array|\WP_Error {
	$params = array();
	$meta   = array(
		'contact_first_name' => '_sv_contact_first_name',
		'contact_last_name'  => '_sv_contact_last_name',
		'contact_email'      => '_sv_contact_email',
		'gpl_findings'       => '_sv_gpl_findings',
		'trademark_findings' => '_sv_trademark_findings',
		'not_eligible_until' => '_sv_not_eligible_until',
	);

	foreach ( $meta as $name => $key ) {
		if ( isset( $input[ $name ] ) ) {
			$params['meta'][ $key ] = (string) $input[ $name ];
		}
	}

	if ( isset( $input['name'] ) ) {
		$params['title'] = (string) $input['name'];
	}

	foreach ( array( 'domains', 'mes' ) as $name ) {
		if ( isset( $input[ $name ] ) ) {
			$params[ $name ] = $input[ $name ];
		}
	}

	if ( ! $params ) {
		return new \WP_Error( 'sv_nothing_to_update', __( 'Give at least one detail to change.', 'wordcamporg' ) );
	}

	$updated = ask_rest( 'POST', '/wp/v2/sv-companies/' . (int) $input['id'], $params );

	return is_wp_error( $updated ) ? $updated : get_company( $input );
}

/**
 * Changes a company's status.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The company.
 */
function set_company_status( array $input ): array|\WP_Error {
	$updated = ask_rest( 'POST', '/wp/v2/sv-companies/' . (int) $input['id'], array( 'status' => (string) $input['status'] ) );

	return is_wp_error( $updated ) ? $updated : get_company( $input );
}

/**
 * Sends a company's compliance request.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error
 */
function send_compliance_request_for_agent( array $input ): array|\WP_Error {
	$sent = ask_rest( 'POST', sprintf( '/sponsor-vetting/v1/companies/%d/compliance-request', (int) $input['id'] ), array( 'sponsorship' => (int) $input['sponsorship'] ) );

	return is_wp_error( $sent ) ? $sent : $sent->get_data();
}

/**
 * Links a sponsorship to a company.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The sponsorship.
 */
function link_sponsorship_for_agent( array $input ): array|\WP_Error {
	$linked = ask_rest( 'POST', '/wp/v2/sv-sponsorships/' . (int) $input['id'], array( 'company' => (int) $input['company'] ) );

	return is_wp_error( $linked ) ? $linked : get_sponsorship( $input );
}

/**
 * Changes a sponsorship's status, approving it if asked to.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The sponsorship.
 */
function set_sponsorship_status( array $input ): array|\WP_Error {
	$params = array( 'status' => (string) $input['status'] );

	if ( 'svs-approved' === $params['status'] ) {
		// The approval is for the website the agent checked, as it is for the one a vetter was shown.
		if ( ! isset( $input['domain'] ) ) {
			return new \WP_Error( 'sv_domain_required', __( 'Name the domain you checked to approve a sponsorship.', 'wordcamporg' ) );
		}

		$params['shown_domain'] = (string) $input['domain'];
		$params['good_faith']   = ! empty( $input['good_faith'] );
	}

	$updated = ask_rest( 'POST', '/wp/v2/sv-sponsorships/' . (int) $input['id'], $params );

	return is_wp_error( $updated ) ? $updated : get_sponsorship( $input );
}

/**
 * Confirms the website an event changed an approved sponsorship to.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The sponsorship.
 */
function confirm_website_for_agent( array $input ): array|\WP_Error {
	$confirmed = ask_rest( 'POST', '/wp/v2/sv-sponsorships/' . (int) $input['id'], array( 'approved_domain' => (string) $input['domain'] ) );

	return is_wp_error( $confirmed ) ? $confirmed : get_sponsorship( $input );
}

/**
 * Sends a sponsorship's approval email again.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error
 */
function send_approval_email_for_agent( array $input ): array|\WP_Error {
	$sent = ask_rest( 'POST', sprintf( '/sponsor-vetting/v1/sponsorships/%d/approval-email', (int) $input['id'] ) );

	return is_wp_error( $sent ) ? $sent : $sent->get_data();
}

/**
 * Adds a note to a company or sponsorship.
 *
 * @param array $input The ability's input.
 * @return array|\WP_Error The record's notes and history.
 */
function add_note_for_agent( array $input ): array|\WP_Error {
	$id     = (int) $input['id'];
	$routes = array(
		COMPANY_POST_TYPE     => '/wp/v2/sv-companies/',
		SPONSORSHIP_POST_TYPE => '/wp/v2/sv-sponsorships/',
	);
	$route  = $routes[ get_post_type( $id ) ] ?? '';

	if ( '' === $route ) {
		return new \WP_Error( 'sv_no_record', __( 'That’s neither a company nor a sponsorship.', 'wordcamporg' ) );
	}

	$added = ask_rest( 'POST', $route . $id, array( 'note' => (string) $input['note'] ) );

	return is_wp_error( $added ) ? $added : array( 'log' => get_agent_log( $id ) );
}

/**
 * Gets a record's notes and status changes, newest first, as plain data rather than the screen's HTML.
 *
 * @param int $post_id The company or sponsorship.
 * @return array[] Each entry's `time` and who it's `by`, and either its `note` or the status it changed `from` and
 *                 `to`.
 */
function get_agent_log( int $post_id ): array {
	$statuses = get_statuses( (string) get_post_type( $post_id ) );

	return array_map(
		static function ( array $entry ) use ( $statuses ): array {
			$user = get_userdata( (int) $entry['user_id'] );
			$item = array(
				'time' => gmdate( 'c', (int) $entry['time'] ),
				'by'   => $user ? $user->display_name : __( 'System', 'wordcamporg' ),
			);

			if ( isset( $entry['text'] ) ) {
				$item['note'] = (string) $entry['text'];
			} else {
				$item['from'] = 'new' === $entry['from'] ? 'new' : ( $statuses[ $entry['from'] ] ?? (string) $entry['from'] );
				$item['to']   = $statuses[ $entry['to'] ] ?? (string) $entry['to'];
			}

			return $item;
		},
		get_log( $post_id )
	);
}

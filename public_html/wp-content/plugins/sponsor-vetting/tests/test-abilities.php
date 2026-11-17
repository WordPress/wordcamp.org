<?php
/**
 * Tests for the abilities an agent vets sponsors with over MCP.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\register_company_meta;
use function WordCamp\Sponsor_Vetting\register_post_types;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\set_company_domains;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its camp sites, where the events' sponsors live.
 *
 * @group sponsor-vetting
 */
class Test_Abilities extends Database_TestCase {
	/**
	 * Registers the post types and their meta on Central, the only site where they're in REST.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();
		// Registered again, since an earlier test may have registered them from another site.
		register_post_types();
		register_company_meta();
	}

	/**
	 * Clears the subroles and the REST server the abilities set up.
	 */
	public function tear_down(): void {
		$GLOBALS['wcorg_subroles'] = array();
		$GLOBALS['wp_rest_server'] = null;

		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * Only sponsor vetters use the abilities: anyone else is refused before they ask anything.
	 *
	 * @dataProvider data_users
	 *
	 * @param string $subrole The user's subrole.
	 * @param string $code    The error's code, or an empty string if it runs.
	 */
	public function test_only_vetters_use_the_abilities( string $subrole, string $code ): void {
		$this->sign_in( $subrole );

		$result = wp_get_ability( 'sponsor-vetting/list-companies' )->execute();

		$this->assertSame( $code, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	/**
	 * Who uses the abilities.
	 *
	 * @return array[]
	 */
	public function data_users(): array {
		return array(
			'a vetter'   => array( 'sponsor_vetter', '' ),
			'a wrangler' => array( 'wordcamp_wrangler', 'ability_invalid_permissions' ),
		);
	}

	/**
	 * An agent takes a new sponsorship from waiting for a company to approved: it creates the company, records its
	 * findings and contact, moves it through the queue to Compliant, which approves the sponsorship for the website
	 * vetted, and notes why. Each step lands where the vetting screen's would, under the agent's user.
	 */
	public function test_agent_vets_a_sponsor_start_to_finish(): void {
		$sponsorship_id = $this->create_camp_sponsorship( 'https://acme.test/' );
		// The event was told some other way, so approving sends it no email.
		update_post_meta( $sponsorship_id, '_sv_approval_notified', 1 );

		$this->sign_in( 'sponsor_vetter' );

		$company = $this->run_ability(
			'create-company',
			array(
				'name'        => 'Acme',
				'domains'     => array( 'acme.test' ),
				'sponsorship' => $sponsorship_id,
			)
		);
		$id      = $company['id'];

		$this->run_ability(
			'update-company',
			array(
				'id'                 => $id,
				'contact_email'      => 'Dana@Acme.test',
				'gpl_findings'       => '- Themes are **GPL**.',
				'trademark_findings' => '- No issues.',
			)
		);
		$this->run_ability(
			'set-company-status',
			array(
				'id'     => $id,
				'status' => 'sv-in-progress',
			)
		);
		$this->run_ability(
			'set-company-status',
			array(
				'id'     => $id,
				'status' => 'sv-compliant',
			)
		);
		$this->run_ability(
			'add-note',
			array(
				'id'   => $id,
				'note' => 'Checked the site.',
			)
		);

		$company = $this->run_ability( 'get-company', array( 'id' => $id ) );

		$this->assertSame(
			array(
				'name'         => 'Acme',
				'status'       => 'sv-compliant',
				'domains'      => array( 'acme.test' ),
				'email'        => 'dana@acme.test',
				'findings'     => array( '- Themes are **GPL**.', '- No issues.' ),
				'sponsorships' => array(
					array(
						'id'     => $sponsorship_id,
						'status' => 'svs-approved',
						'domain' => 'acme.test',
					),
				),
				'latest note'  => array(
					'by'   => wp_get_current_user()->display_name,
					'note' => 'Checked the site.',
				),
			),
			array(
				'name'         => $company['name'],
				'status'       => $company['status'],
				'domains'      => $company['domains'],
				'email'        => $company['contact']['email'],
				'findings'     => array( $company['gpl_findings'], $company['trademark_findings'] ),
				'sponsorships' => array_map( static fn( array $sponsorship ): array => array_intersect_key( $sponsorship, array_flip( array( 'id', 'status', 'domain' ) ) ), $company['sponsorships'] ),
				'latest note'  => array_intersect_key( $company['log'][0], array_flip( array( 'by', 'note' ) ) ),
			)
		);
	}

	/**
	 * A change the workflow refuses reaches the agent as an error, and leaves the record as it was, rather than
	 * looking like it worked.
	 *
	 * @dataProvider data_refusals
	 *
	 * @param string $ability The ability, after `sponsor-vetting/`.
	 * @param array  $input   Its input, without the ID, which is the company's or the sponsorship's.
	 * @param string $code    The error's code.
	 */
	public function test_refusals_reach_the_agent( string $ability, array $input, string $code ): void {
		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-not-eligible',
			)
		);
		$sponsorship_id = $this->create_camp_sponsorship( 'https://acme.test/' );

		wp_update_post(
			array(
				'ID'          => $sponsorship_id,
				'post_parent' => $company_id,
			)
		);

		$this->sign_in( 'sponsor_vetter' );

		$id     = str_contains( $ability, 'company' ) ? $company_id : $sponsorship_id;
		$result = wp_get_ability( 'sponsor-vetting/' . $ability )->execute( array( 'id' => $id ) + $input );

		$this->assertSame(
			array( $code, 'sv-not-eligible', 'svs-pending' ),
			array( is_wp_error( $result ) ? $result->get_error_code() : '', get_post_status( $company_id ), get_post_status( $sponsorship_id ) )
		);
	}

	/**
	 * Changes the workflow refuses.
	 *
	 * @return array[]
	 */
	public function data_refusals(): array {
		return array(
			'a status the company can’t move to'         => array( 'set-company-status', array( 'status' => 'sv-compliant' ), 'sv_status_not_allowed' ),
			'an approval without the website checked'    => array( 'set-sponsorship-status', array( 'status' => 'svs-approved' ), 'sv_domain_required' ),
			'an approval for another website than checked' => array(
				'set-sponsorship-status',
				array(
					'status' => 'svs-approved',
					'domain' => 'other.test',
				),
				'sv_website_changed_since_shown',
			),
		);
	}

	/**
	 * The lists find what an agent works through: the companies still being vetted, a company by its website, and
	 * the sponsorships waiting for a company.
	 *
	 * @dataProvider data_lists
	 *
	 * @param string $ability The ability, after `sponsor-vetting/`.
	 * @param array  $input   Its input.
	 * @param string $key     Where the output lists the records.
	 * @param array  $names   The records listed, by name.
	 */
	public function test_lists_find_the_work( string $ability, array $input, string $key, array $names ): void {
		$companies = array(
			'Acme'   => 'sv-needs-vetting',
			'Nova'   => 'sv-in-progress',
			'Zenith' => 'sv-compliant',
		);

		foreach ( $companies as $name => $status ) {
			$companies[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => COMPANY_POST_TYPE,
					'post_status' => $status,
					'post_title'  => $name,
				)
			);
		}

		set_company_domains( $companies['Zenith'], array( 'zenith.test' ) );

		$sponsorships = array(
			'Waiting' => 0,
			'Linked'  => $companies['Acme'],
		);

		foreach ( $sponsorships as $name => $company_id ) {
			self::factory()->post->create(
				array(
					'post_type'   => SPONSORSHIP_POST_TYPE,
					'post_status' => 'svs-pending',
					'post_title'  => $name,
					'post_parent' => $company_id,
					'meta_input'  => array( '_sv_blog_id' => self::$year_dot_2018_site_id ),
				)
			);
		}

		$this->sign_in( 'sponsor_vetter' );

		$listed = wp_list_pluck( $this->run_ability( $ability, $input )[ $key ], 'name' );
		sort( $listed );

		$this->assertSame( $names, $listed );
	}

	/**
	 * What an agent lists, and what it finds.
	 *
	 * @return array[]
	 */
	public function data_lists(): array {
		return array(
			'the companies still being vetted'          => array( 'list-companies', array(), 'companies', array( 'Acme', 'Nova' ) ),
			'a company by its website'                  => array( 'list-companies', array( 'domain' => 'https://www.zenith.test/about' ), 'companies', array( 'Zenith' ) ),
			'the sponsorships waiting for a company'    => array( 'list-sponsorships', array( 'company' => 0 ), 'sponsorships', array( 'Waiting' ) ),
		);
	}

	/**
	 * Runs an ability, failing the test if it returns an error.
	 *
	 * @param string $ability The ability, after `sponsor-vetting/`.
	 * @param array  $input   Its input.
	 * @return array Its output.
	 */
	private function run_ability( string $ability, array $input ): array {
		$result = wp_get_ability( 'sponsor-vetting/' . $ability )->execute( $input );

		$this->assertNotWPError( $result );

		return $result;
	}

	/**
	 * Signs in a new user with a subrole, and starts the REST API, as an MCP request has before any ability runs.
	 *
	 * The MCP adapter starts with it, and registers its own abilities only if that's before the first ability is
	 * looked up.
	 *
	 * @param string $subrole The subrole.
	 */
	private function sign_in( string $subrole ): void {
		$user_id                   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$GLOBALS['wcorg_subroles'] = array( $user_id => array( $subrole ) );
		$GLOBALS['wp_rest_server'] = null;

		wp_set_current_user( $user_id );
		rest_get_server();
	}

	/**
	 * Creates a pending sponsorship, without a company, for a camp's sponsor with a website.
	 *
	 * @param string $website The website the camp entered.
	 * @return int The sponsorship.
	 */
	private function create_camp_sponsorship( string $website ): int {
		switch_to_blog( self::$year_dot_2018_site_id );
		$sponsor_id = self::factory()->post->create(
			array(
				'post_type'  => 'wcb_sponsor',
				'meta_input' => array( '_wcpt_sponsor_website' => $website ),
			)
		);
		restore_current_blog();

		return self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-pending',
				'meta_input'  => array(
					'_sv_blog_id'    => self::$year_dot_2018_site_id,
					'_sv_sponsor_id' => $sponsor_id,
				),
			)
		);
	}
}

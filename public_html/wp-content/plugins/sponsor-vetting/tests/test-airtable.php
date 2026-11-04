<?php
/**
 * Tests for the one-time Airtable import.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\find_companies_by_domain;
use function WordCamp\Sponsor_Vetting\get_camps_by_name;
use function WordCamp\Sponsor_Vetting\get_conversations;
use function WordCamp\Sponsor_Vetting\get_log;
use function WordCamp\Sponsor_Vetting\get_sponsorship_statuses;
use function WordCamp\Sponsor_Vetting\group_airtable_rows;
use function WordCamp\Sponsor_Vetting\import_airtable_company;
use function WordCamp\Sponsor_Vetting\link_sponsorship;
use function WordCamp\Sponsor_Vetting\needs_approval_email;
use function WordCamp\Sponsor_Vetting\normalize_event_name;
use function WordCamp\Sponsor_Vetting\parse_csv;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\request_vetting;
use function WordCamp\Sponsor_Vetting\set_company_domains;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its Central and camp sites.
 *
 * @group sponsor-vetting
 */
class Test_Airtable extends Database_TestCase {
	/**
	 * A company vetted before the re-vetting period keeps its status, dates and approval; a dry run writes nothing and
	 * predicts the run, and a second run leaves the company alone but links an event that matches a camp since. Only
	 * mapped Help Scout links become conversations, and an event in two rows goes by the newer one. A lapsed company
	 * with a pending sponsorship is queued, whether the import linked it or it was linked already, and both runs report
	 * it; a Good Faith deadline runs from the first approval, even one whose event matches no camp, and a newer
	 * Canceled row doesn't set the company's status. Carried-over approvals offer no approval email.
	 */
	public function test_import_keeps_old_approvals_and_links_events_on_rerun(): void {
		foreach ( array( self::$year_dot_2018_site_id, self::$year_dot_2019_site_id ) as $site_id ) {
			switch_to_blog( $site_id );
			foreach ( array( 'acme', 'initech', 'hooli', 'umbrella' ) as $name ) {
				self::factory()->post->create(
					array(
						'post_type'  => 'wcb_sponsor',
						'post_title' => $name,
						'meta_input' => array( '_wcpt_sponsor_website' => "https://$name.example/" ),
					)
				);
			}
			restore_current_blog();
		}

		switch_to_blog( self::$year_dot_2018_site_id );
		$soylent_sponsor = self::factory()->post->create(
			array(
				'post_type'  => 'wcb_sponsor',
				'post_title' => 'Soylent',
				'meta_input' => array( '_wcpt_sponsor_website' => 'https://soylent.example/' ),
			)
		);
		restore_current_blog();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();
		self::factory()->post->create(
			array(
				'post_type'  => 'wordcamp',
				'post_title' => 'WordCamp Seattle 2018',
				'meta_input' => array( '_site_id' => self::$year_dot_2018_site_id ),
			)
		);

		$file = wp_tempnam( 'airtable.csv' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test fixture.
			$file,
			"\xEF\xBB\xBFSponsor Name,Company Link,Sponsor Email address,Current Event Name,GPL,Status,Approval Status,Approval Date,Private Notes,Email conversation link,Last modified,Good Faith\n"
			. "Acme,https://www.acme.example/,pat@acme.example,\"wordcamp seattle 2018, WordCamp Seattle 2019\",All good.,Compliant,Yes,\"March 3, 2025\",\"Asked twice.\nFixed.\",,3/4/2025 9:00am\n"
			. "Globex,globex.example,,,,Compliance Requested,,,,\"https://freescout.wordpress.net/conversation/123 https://secure.helpscout.net/conversation/3127341156/901940#thread-1 https://secure.helpscout.net/conversation/1/2\",3/4/2025 9:00am\n"
			. "Initech,initech.example,,WordCamp Seattle 2018,,Compliant,,,,,3/5/2025 9:00am\n"
			. "Initech,initech.example,,WordCamp Seattle 2018,,Compliant,Yes,,,,3/1/2025 9:00am\n"
			. "Hooli,hooli.example,,WordCamp Seattle 2018,,Needs Vetting,Yes,,,,3/4/2025 9:00am\n"
			. "Soylent,soylent.example,,WordCamp Seattle 2018,,Compliant,,,,,3/4/2025 9:00am\n"
			. "Umbrella,umbrella.example,,,,Canceled,,,,,7/1/2025 9:00am\n"
			. "Umbrella,umbrella.example,,WordCamp Seattle 2018,,Compliance Requested,Yes,\"June 1, 2025\",,,6/1/2025 9:00am,Yes\n"
			. "Umbrella,umbrella.example,,WordCamp Nowhere 2025,,Compliance Requested,Yes,\"May 1, 2025\",,,5/1/2025 9:00am,Yes\n"
			. "No Link Co,,,WordCamp Seattle 2018,,Compliant,Yes,,,,3/4/2025 9:00am\n"
		);
		$rows = parse_csv( $file );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A test fixture.

		$grouped = group_airtable_rows( $rows );
		$dry_run = import_airtable_company( 'acme.example', $grouped['companies']['acme.example'], get_camps_by_name(), true );

		$this->assertCount( 1, $grouped['skipped'] );
		$this->assertSame( array(), find_companies_by_domain( 'acme.example' ) );

		$first = import_airtable_company( 'acme.example', $grouped['companies']['acme.example'], get_camps_by_name(), false );

		self::factory()->post->create(
			array(
				'post_type'  => 'wordcamp',
				'post_title' => 'WordCamp Seattle 2019',
				'meta_input' => array( '_site_id' => self::$year_dot_2019_site_id ),
			)
		);
		$second = import_airtable_company( 'acme.example', $grouped['companies']['acme.example'], get_camps_by_name(), false );

		$expected = array(
			'wordcamp seattle 2018' => 'approved',
			'WordCamp Seattle 2019' => 'no camp has this name',
		);
		$this->assertSame( $expected, $dry_run['sponsorships'] );
		$this->assertSame( $expected, $first['sponsorships'] );
		$this->assertSame( 'existing', $second['result'] );
		$this->assertSame(
			array(
				'wordcamp seattle 2018' => 'already Approved',
				'WordCamp Seattle 2019' => 'approved',
			),
			$second['sponsorships']
		);
		$this->assertSame( array( $first['company_id'] ), find_companies_by_domain( 'acme.example' ) );

		$globex = import_airtable_company( 'globex.example', $grouped['companies']['globex.example'], get_camps_by_name(), false );

		$this->assertSame( array( 123, 110535 ), array_column( get_conversations( $globex['company_id'], 'compliance' ), 'id' ) );

		$initech_dry_run = import_airtable_company( 'initech.example', $grouped['companies']['initech.example'], get_camps_by_name(), true );
		$hooli_dry_run   = import_airtable_company( 'hooli.example', $grouped['companies']['hooli.example'], get_camps_by_name(), true );
		$hooli           = import_airtable_company( 'hooli.example', $grouped['companies']['hooli.example'], get_camps_by_name(), false );
		$initech         = import_airtable_company( 'initech.example', $grouped['companies']['initech.example'], get_camps_by_name(), false );

		$this->assertSame( array( 'WordCamp Seattle 2018' => 'left pending: approved in Airtable, but the company isn’t Compliant' ), $hooli_dry_run['sponsorships'] );
		$this->assertSame( $hooli_dry_run['sponsorships'], $hooli['sponsorships'] );
		$this->assertSame( array( 'WordCamp Seattle 2018' => 'pending' ), $initech['sponsorships'] );
		$this->assertSame( array( 'sv-needs-vetting', 'sv-needs-vetting' ), array( $initech_dry_run['status'], $initech['status'] ) );

		// A company intake made before the import, already holding a pending sponsorship, and vetted long ago.
		$soylent_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliant',
			)
		);
		set_company_domains( $soylent_id, array( 'soylent.example' ) );
		link_sponsorship( request_vetting( self::$year_dot_2018_site_id, $soylent_sponsor ), $soylent_id );
		update_post_meta( $soylent_id, '_sv_last_vetted', strtotime( '-1 year' ) );

		$soylent = import_airtable_company( 'soylent.example', $grouped['companies']['soylent.example'], get_camps_by_name(), false );

		$this->assertSame( array( 'existing', 'sv-needs-vetting' ), array( $soylent['result'], $soylent['status'] ) );

		$umbrella = import_airtable_company( 'umbrella.example', $grouped['companies']['umbrella.example'], get_camps_by_name(), false );

		$this->assertSame( 'sv-compliance-req', $umbrella['status'] );
		$this->assertSame( '2025-05-16', get_post_meta( $umbrella['company_id'], '_sv_good_faith_due', true ) );
		$this->assertSame(
			array( 'svs-pending' ),
			wp_list_pluck(
				get_posts(
					array(
						'post_type'   => SPONSORSHIP_POST_TYPE,
						'post_status' => array_keys( get_sponsorship_statuses() ),
						'post_parent' => $initech['company_id'],
					)
				),
				'post_status'
			)
		);

		$sponsorships = get_posts(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-approved',
				'post_parent' => $first['company_id'],
			)
		);
		$notes        = array_column( get_log( $first['company_id'] ), 'text' );

		$this->assertSame( 'sv-compliant', get_post_status( $first['company_id'] ) );
		$this->assertSame( 'All good.', get_post_meta( $first['company_id'], '_sv_gpl_findings', true ) );
		$this->assertSame( strtotime( '2025-03-03 UTC' ), (int) get_post_meta( $first['company_id'], '_sv_last_vetted', true ) );
		$this->assertCount( 2, $sponsorships );
		$this->assertFalse( needs_approval_email( $sponsorships[0]->ID ) );
		$this->assertSame( array( strtotime( '2025-03-03 UTC' ), strtotime( '2025-03-03 UTC' ) ), array_map( static fn( \WP_Post $sponsorship ): int => (int) get_post_meta( $sponsorship->ID, '_sv_approved_at', true ), $sponsorships ) );
		$this->assertSame( array( "Imported from Airtable.\nCurrent event name: wordcamp seattle 2018, WordCamp Seattle 2019\nApproval date: March 3, 2025\nPrivate notes: Asked twice.\nFixed." ), array_values( array_filter( $notes, static fn( ?string $note ): bool => str_starts_with( (string) $note, 'Imported' ) ) ) );

		restore_current_blog();
	}

	/**
	 * Airtable names a camp that Central titles without its year, or with its country, by the year it starts, and
	 * spells it without accents or abbreviated. A camp's title still wins, and a name camps on two sites share matches
	 * neither.
	 */
	public function test_camps_match_by_start_year(): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$camps = array(
			array( 'WordCamp Kraków, Poland', '2025-06-07', 101 ),
			array( 'WordCamp Kathmandu', '2018-09-15', 102 ),
			array( 'WordCamp Kathmandu', '2026-09-18', 103 ),
			array( 'WordCamp Europe', '2025-06-05', 104 ),
			array( 'WordCamp Lisboa 2025', '2025-05-01', 105 ),
			array( 'WordCamp Lisboa', '2025-11-01', 106 ),
		);

		foreach ( $camps as list( $title, $start, $site_id ) ) {
			self::factory()->post->create(
				array(
					'post_type'  => 'wordcamp',
					'post_title' => $title,
					'meta_input' => array(
						'_site_id'                => $site_id,
						'Start Date (YYYY-mm-dd)' => strtotime( "$start UTC" ),
					),
				)
			);
		}

		$names = get_camps_by_name();

		restore_current_blog();

		$events = array( 'WordCamp Krakow 2025', 'WordCamp Kathmandu 2026', 'WordCamp Kathmandu', 'WCEU 2025', 'WordCamp Lisboa 2025' );

		$this->assertSame( array( 101, 103, 0, 104, 105 ), array_map( static fn( string $event ): int => $names[ normalize_event_name( $event ) ] ?? -1, $events ) );
	}

	/**
	 * A row that isn't approved puts its company in the queue for a camp still to come, but not for one that's over.
	 */
	public function test_import_skips_pending_sponsorships_of_past_events(): void {
		foreach ( array( self::$year_dot_2018_site_id, self::$year_dot_2019_site_id ) as $site_id ) {
			switch_to_blog( $site_id );
			self::factory()->post->create(
				array(
					'post_type'  => 'wcb_sponsor',
					'post_title' => 'Initech',
					'meta_input' => array( '_wcpt_sponsor_website' => 'https://initech.example/' ),
				)
			);
			restore_current_blog();
		}

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();

		$camps = array(
			'WordCamp Seattle 2018' => array( self::$year_dot_2018_site_id, time() + MONTH_IN_SECONDS ),
			'WordCamp Seattle 2019' => array( self::$year_dot_2019_site_id, time() - MONTH_IN_SECONDS ),
		);

		foreach ( $camps as $title => list( $site_id, $end ) ) {
			self::factory()->post->create(
				array(
					'post_type'  => 'wordcamp',
					'post_title' => $title,
					'meta_input' => array(
						'_site_id'              => $site_id,
						'End Date (YYYY-mm-dd)' => $end,
					),
				)
			);
		}

		$file = wp_tempnam( 'airtable.csv' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test fixture.
			$file,
			"Sponsor Name,Company Link,Current Event Name,Status,Approval Status,Last modified\n"
			. "Initech,initech.example,\"WordCamp Seattle 2018\nWordCamp Seattle 2019\",Needs Vetting,,3/4/2025 9:00am\n"
		);
		$grouped = group_airtable_rows( parse_csv( $file ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A test fixture.

		$initech      = import_airtable_company( 'initech.example', $grouped['companies']['initech.example'], get_camps_by_name(), false );
		$sponsorships = get_posts(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => array_keys( get_sponsorship_statuses() ),
				'post_parent' => $initech['company_id'],
			)
		);

		restore_current_blog();

		$this->assertSame(
			array(
				'WordCamp Seattle 2018' => 'pending',
				'WordCamp Seattle 2019' => 'skipped: the event is over',
			),
			$initech['sponsorships']
		);
		$this->assertCount( 1, $sponsorships );
	}
}

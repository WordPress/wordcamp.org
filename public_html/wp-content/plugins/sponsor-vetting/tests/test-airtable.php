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
use function WordCamp\Sponsor_Vetting\group_airtable_rows;
use function WordCamp\Sponsor_Vetting\import_airtable_company;
use function WordCamp\Sponsor_Vetting\parse_csv;
use function WordCamp\Sponsor_Vetting\register_types;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its Central and camp sites.
 *
 * @group sponsor-vetting
 */
class Test_Airtable extends Database_TestCase {
	/**
	 * A company vetted more than a year ago keeps its status, dates and approval; a dry run writes nothing, and a
	 * second run leaves the company alone but links an event that matches a camp since.
	 */
	public function test_import_keeps_old_approvals_and_links_events_on_rerun(): void {
		foreach ( array( self::$year_dot_2018_site_id, self::$year_dot_2019_site_id ) as $site_id ) {
			switch_to_blog( $site_id );
			self::factory()->post->create(
				array(
					'post_type'  => 'wcb_sponsor',
					'post_title' => 'Acme',
					'meta_input' => array( '_wcpt_sponsor_website' => 'https://acme.example/' ),
				)
			);
			restore_current_blog();
		}

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
			"\xEF\xBB\xBFSponsor Name,Company Link,Sponsor Email address,Current Event Name,GPL,Status,Approval Status,Approval Date,Private Notes,Email conversation link,Last modified\n"
			. "Acme,https://www.acme.example/,pat@acme.example,\"wordcamp seattle 2018, WordCamp Seattle 2019\",All good.,Compliant,Yes,\"March 3, 2025\",\"Asked twice.\nFixed.\",,3/4/2025 9:00am\n"
			. "Globex,globex.example,,,,Compliance Requested,,,,\"https://freescout.wordpress.net/conversation/123 https://secure.helpscout.net/conversation/3127341156/901940#thread-1\",3/4/2025 9:00am\n"
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
		$this->assertSame( array( strtotime( '2025-03-03 UTC' ), strtotime( '2025-03-03 UTC' ) ), array_map( static fn( \WP_Post $sponsorship ): int => (int) get_post_meta( $sponsorship->ID, '_sv_approved_at', true ), $sponsorships ) );
		$this->assertSame( array( "Imported from Airtable.\nCurrent event name: wordcamp seattle 2018, WordCamp Seattle 2019\nApproval date: March 3, 2025\nPrivate notes: Asked twice.\nFixed." ), array_values( array_filter( $notes, static fn( ?string $note ): bool => str_starts_with( (string) $note, 'Imported' ) ) ) );

		restore_current_blog();
	}
}

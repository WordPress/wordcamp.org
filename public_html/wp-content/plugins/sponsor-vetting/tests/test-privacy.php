<?php
/**
 * Tests for erasing a sponsor contact's personal data.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WP_UnitTestCase;
use function WordCamp\Sponsor_Vetting\erase_personal_data;
use function WordCamp\Sponsor_Vetting\get_company_domains;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\set_company_domains;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * @group sponsor-vetting
 */
class Test_Privacy extends WP_UnitTestCase {
	/**
	 * Registers the post types, as `init` does on Central, where privacy requests run.
	 */
	public function set_up(): void {
		parent::set_up();

		register_types();
	}

	/**
	 * Erasing removes the contact's details from their company, and only theirs, and keeps the vetting record,
	 * which is about the company.
	 */
	public function test_erasure_keeps_the_vetting_record(): void {
		$contact = array(
			'_sv_contact_first_name' => 'Pat',
			'_sv_contact_last_name'  => 'Lee',
			'_sv_contact_email'      => 'pat@acme.example',
		);

		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-not-eligible',
				'meta_input'  => $contact + array( '_sv_trademark_findings' => 'Uses “WordPress” in its product name.' ),
			)
		);
		$other_id   = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliant',
				'meta_input'  => array( '_sv_contact_email' => 'sam@other.example' ),
			)
		);

		set_company_domains( $company_id, array( 'acme.example' ) );

		$result = erase_personal_data( 'Pat@Acme.example' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array(), array_filter( array_map( static fn( string $key ): string => (string) get_post_meta( $company_id, $key, true ), array_keys( $contact ) ) ) );
		$this->assertSame( 'sv-not-eligible', get_post_status( $company_id ) );
		$this->assertSame( array( 'acme.example' ), get_company_domains( $company_id ) );
		$this->assertSame( 'Uses “WordPress” in its product name.', get_post_meta( $company_id, '_sv_trademark_findings', true ) );
		$this->assertSame( 'sam@other.example', get_post_meta( $other_id, '_sv_contact_email', true ) );
	}
}

<?php
/**
 * Tests for what the sponsor invoices dashboard shows and allows.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\approve_sponsorship;
use function WordCamp\Sponsor_Vetting\check_invoice_approval;
use function WordCamp\Sponsor_Vetting\confirm_website;
use function WordCamp\Sponsor_Vetting\find_sponsorship;
use function WordCamp\Sponsor_Vetting\get_invoice_statuses;
use function WordCamp\Sponsor_Vetting\website_changed;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/trait-submits-invoices.php';

/**
 * Extends `Database_TestCase` for its Central and camp sites.
 *
 * @group sponsor-vetting
 */
class Test_Invoices extends Database_TestCase {
	use Submits_Invoices;

	/**
	 * Creates a sponsor on a camp and submits an invoice for it, which requests vetting.
	 *
	 * @param int    $blog_id The camp site.
	 * @param string $website The sponsor's website.
	 * @return int[] The sponsor's and the invoice's IDs.
	 */
	protected function submit_invoice( int $blog_id, string $website ): array {
		switch_to_blog( $blog_id );

		$sponsor_id = self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_status' => 'publish',
				'post_title'  => 'Acme',
				'meta_input'  => $this->get_invoiceable_sponsor_meta() + array( '_wcpt_sponsor_website' => $website ),
			)
		);
		$invoice_id = $this->invoice_sponsor( $sponsor_id, 'wcbsi_submitted' );

		restore_current_blog();

		return array( $sponsor_id, $invoice_id );
	}

	/**
	 * Checks whether the camp's invoice may be approved, as the dashboard does from the camp's site.
	 *
	 * @param int $invoice_id The invoice on the 2018 camp.
	 * @return string `allowed`, or the refusal's error code.
	 */
	protected function check( int $invoice_id ): string {
		switch_to_blog( self::$year_dot_2018_site_id );
		$allowed = check_invoice_approval( true, $invoice_id );
		restore_current_blog();

		return is_wp_error( $allowed ) ? $allowed->get_error_code() : 'allowed';
	}

	/**
	 * Sets the camp sponsor's website, as the sponsor screen or Multi-Event propagation does.
	 *
	 * @param int    $sponsor_id The sponsor on the 2018 camp.
	 * @param string $website    The website.
	 */
	protected function set_website( int $sponsor_id, string $website ): void {
		switch_to_blog( self::$year_dot_2018_site_id );
		update_post_meta( $sponsor_id, '_wcpt_sponsor_website', $website );
		restore_current_blog();
	}

	/**
	 * An invoice can be approved only once its sponsorship is, and again only after a vetter confirms a website
	 * the camp changed afterwards, including while the sponsorship sat in the trash. Changing it back needs no
	 * confirmation, and an approval or a confirmation only accepts the website the vetter was shown. A sponsor the
	 * camp deletes isn't taken for a changed website, but its invoice can't be approved either.
	 */
	public function test_invoice_approval_follows_the_sponsorship_and_its_website(): void {
		list( $sponsor_id, $invoice_id ) = $this->submit_invoice( self::$year_dot_2018_site_id, 'https://acme.example/' );

		$this->assertSame( 'sv_invoice_not_approved', $this->check( $invoice_id ) );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$sponsorship_id = find_sponsorship( self::$year_dot_2018_site_id, $sponsor_id );
		wp_update_post(
			array(
				'ID'          => (int) get_post_field( 'post_parent', $sponsorship_id ),
				'post_status' => 'sv-compliant',
			)
		);
		$stale_approval = approve_sponsorship( $sponsorship_id, false, 'other.example' );
		$this->assertTrue( approve_sponsorship( $sponsorship_id, false, 'https://www.acme.example/' ) );
		restore_current_blog();

		$this->assertSame( 'sv_website_changed_since_shown', $stale_approval->get_error_code() );
		$this->assertSame( 'allowed', $this->check( $invoice_id ) );

		$this->set_website( $sponsor_id, 'https://www.other.example' );
		$this->assertSame( 'sv_invoice_website_changed', $this->check( $invoice_id ) );

		$this->set_website( $sponsor_id, 'acme.example' );
		$this->assertSame( 'allowed', $this->check( $invoice_id ) );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_trash_post( $sponsorship_id );
		restore_current_blog();
		$this->set_website( $sponsor_id, 'https://elsewhere.example' );
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_untrash_post( $sponsorship_id );
		restore_current_blog();

		$this->assertSame( 'sv_invoice_website_changed', $this->check( $invoice_id ) );

		$this->set_website( $sponsor_id, 'https://other.example' );
		$this->set_website( $sponsor_id, 'https://third.example' );
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$stale           = confirm_website( $sponsorship_id, 'other.example' );
		$confirmed       = confirm_website( $sponsorship_id, 'third.example' );
		$approved_domain = get_post_meta( $sponsorship_id, '_sv_approved_domain', true );
		restore_current_blog();

		$this->assertWPError( $stale );
		$this->assertTrue( $confirmed );
		$this->assertSame( 'third.example', $approved_domain );
		$this->assertSame( 'allowed', $this->check( $invoice_id ) );

		switch_to_blog( self::$year_dot_2018_site_id );
		wp_delete_post( $sponsor_id, true );
		restore_current_blog();
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$changed = website_changed( $sponsorship_id );
		restore_current_blog();

		$this->assertFalse( $changed );
		$this->assertSame( 'sv_invoice_sponsor_gone', $this->check( $invoice_id ) );
	}

	/**
	 * The dashboard gets each listed invoice's vetting by camp and sponsor, and invoices from before the index
	 * stored a sponsor read as not requested.
	 */
	public function test_dashboard_statuses_per_invoice(): void {
		list( $sponsor_2018, $invoice_2018 ) = $this->submit_invoice( self::$year_dot_2018_site_id, 'https://acme.example/' );
		list( , $invoice_2019 )              = $this->submit_invoice( self::$year_dot_2019_site_id, 'https://globex.example/' );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$sponsorship_id = find_sponsorship( self::$year_dot_2018_site_id, $sponsor_2018 );
		wp_update_post(
			array(
				'ID'          => (int) get_post_field( 'post_parent', $sponsorship_id ),
				'post_status' => 'sv-compliant',
			)
		);
		approve_sponsorship( $sponsorship_id );
		restore_current_blog();

		$statuses = get_invoice_statuses(
			array(),
			array(
				(object) array(
					'blog_id'    => (string) self::$year_dot_2018_site_id,
					'invoice_id' => (string) $invoice_2018,
					'sponsor_id' => (string) $sponsor_2018,
				),
				// Written by dashboard code that doesn't index the sponsor.
				(object) array(
					'blog_id'    => (string) self::$year_dot_2019_site_id,
					'invoice_id' => (string) $invoice_2019,
					'sponsor_id' => '0',
				),
				(object) array(
					'blog_id'    => (string) self::$year_dot_2019_site_id,
					'invoice_id' => '999',
					'sponsor_id' => '0',
				),
			)
		);

		$this->assertSame(
			array( 'Approved', 'Pending (In review)', 'Not requested' ),
			array_map( 'wp_strip_all_tags', array_values( $statuses ) )
		);
	}
}

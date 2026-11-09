<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Archive\update_group_archive_status;
use function WordCamp\Groups\Frontend\Group_Location\get_location_label;
use function WordCamp\Groups\Frontend\Group_Location\save_location;
use function WordCamp\Groups\Frontend\Group_Location\search_groups;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Find_Groups extends Groups_TestCase {
	/**
	 * @var int[]
	 */
	private $site_ids = array();

	/**
	 * Remove the sites a test created.
	 */
	protected function tearDown(): void {
		foreach ( $this->site_ids as $site_id ) {
			wp_delete_site( $site_id );
		}

		parent::tearDown();
	}

	/**
	 * Create a site with an in-person location.
	 *
	 * @param string $slug       The site's slug.
	 * @param string $city       The city.
	 * @param string $country    The two-letter country code.
	 * @param int    $network_id The network to create the site on.
	 * @return int The site ID.
	 */
	private function create_group( string $slug, string $city, string $country, int $network_id = GROUPS_NETWORK_ID ): int {
		$site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => "/group/{$slug}/",
				'network_id' => $network_id,
			)
		);

		$this->site_ids[] = $site_id;

		switch_to_blog( $site_id );
		save_location(
			array(
				'type'        => 'physical',
				'city'        => $city,
				'countryCode' => $country,
			)
		);
		restore_current_blog();

		return $site_id;
	}

	/**
	 * Get the site IDs matching a search term.
	 *
	 * @param string $term The search term.
	 * @return int[]
	 */
	private function search( string $term ): array {
		return array_map( 'intval', wp_list_pluck( search_groups( $term ), 'blog_id' ) );
	}

	/**
	 * A city matches in any case, and on part of its name.
	 */
	public function test_matches_city() {
		$berlin = $this->create_group( 'berlin', 'Berlin', 'DE' );
		$paris  = $this->create_group( 'paris', 'Paris', 'FR' );

		$this->assertSame( array( $berlin ), $this->search( 'berlin' ) );
		$this->assertSame( array( $berlin ), $this->search( 'BERL' ) );
		$this->assertSame( array( $paris ), $this->search( 'Paris' ) );
	}

	/**
	 * A country name finds every group in that country, ordered by slug.
	 */
	public function test_matches_country_name() {
		$munich = $this->create_group( 'munich', 'Munich', 'DE' );
		$berlin = $this->create_group( 'berlin', 'Berlin', 'DE' );
		$this->create_group( 'paris', 'Paris', 'FR' );

		$this->assertSame( array( $berlin, $munich ), $this->search( 'germany' ) );
	}

	/**
	 * LIKE wildcards in the term are literal, so they can't match every group.
	 */
	public function test_wildcards_are_literal() {
		$this->create_group( 'berlin', 'Berlin', 'DE' );

		$this->assertSame( array(), $this->search( '%' ) );
		$this->assertSame( array(), $this->search( 'B_rlin' ) );
	}

	/**
	 * An empty search returns nothing rather than listing every group.
	 */
	public function test_empty_term_returns_nothing() {
		$this->create_group( 'berlin', 'Berlin', 'DE' );

		$this->assertSame( array(), $this->search( '' ) );
		$this->assertSame( array(), $this->search( '   ' ) );
	}

	/**
	 * Online groups have no place to match on.
	 */
	public function test_online_groups_are_not_matched() {
		$site_id = $this->create_group( 'remote', 'Berlin', 'DE' );

		switch_to_blog( $site_id );
		save_location( array( 'type' => 'online' ) );
		restore_current_blog();

		$this->assertSame( array(), $this->search( 'Berlin' ) );
	}

	/**
	 * Archived groups are not listed.
	 */
	public function test_archived_groups_are_excluded() {
		$berlin = $this->create_group( 'berlin', 'Berlin', 'DE' );

		$this->assertTrue( update_group_archive_status( $berlin, true ) );

		$this->assertSame( array(), $this->search( 'Berlin' ) );
	}

	/**
	 * A site outside the Groups network is never a group, even with a location.
	 */
	public function test_sites_on_other_networks_are_excluded() {
		$this->create_group( 'not-a-group', 'Berlin', 'DE', 1 );

		$this->assertSame( array(), $this->search( 'Berlin' ) );
	}

	/**
	 * A group's location label can be read from another group's page.
	 */
	public function test_location_label_reads_the_given_site() {
		$berlin = $this->create_group( 'berlin', 'Berlin', 'DE' );

		$this->assertSame( 'Berlin, Germany', get_location_label( $berlin ) );
	}
}

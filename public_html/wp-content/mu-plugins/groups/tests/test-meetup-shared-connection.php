<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Meetup_Shared_Connection;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Meetup_Shared_Connection extends Groups_TestCase {

	/**
	 * A token shaped like the one `Meetup_OAuth2_Client` stores.
	 *
	 * @var array
	 */
	protected $token = array(
		'access_token'  => 'central-access',
		'refresh_token' => 'central-refresh',
		'expires_in'    => 3600,
		'timestamp'     => 1792000000,
	);

	/**
	 * Clear the token on both networks.
	 */
	protected function tearDown(): void {
		delete_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION );
		$this->delete_groups_row();

		parent::tearDown();
	}

	/**
	 * Whether the Groups network has a token row of its own, read past the filters.
	 */
	protected function groups_row_exists(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key = %s",
				GROUPS_NETWORK_ID,
				Meetup_Shared_Connection\TOKEN_OPTION
			)
		);
	}

	/**
	 * Remove any Groups network token row, past the filters.
	 */
	protected function delete_groups_row(): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->sitemeta,
			array(
				'site_id'  => GROUPS_NETWORK_ID,
				'meta_key' => Meetup_Shared_Connection\TOKEN_OPTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		wp_cache_delete( GROUPS_NETWORK_ID . ':' . Meetup_Shared_Connection\TOKEN_OPTION, 'site-options' );
	}

	/**
	 * The Groups network sees Central's connection.
	 */
	public function test_groups_reads_central_token() {
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );

		$this->assertSame( $this->token, get_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
	}

	/**
	 * With no connection on Central, the Groups network has none either.
	 */
	public function test_groups_reads_empty_when_central_has_none() {
		$this->assertSame( array(), get_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, array() ) );
	}

	/**
	 * A row left on the Groups network from before is ignored in favor of Central's.
	 */
	public function test_groups_ignores_its_own_stale_row() {
		global $wpdb;

		$wpdb->insert(
			$wpdb->sitemeta,
			array(
				'site_id'    => GROUPS_NETWORK_ID,
				'meta_key'   => Meetup_Shared_Connection\TOKEN_OPTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => maybe_serialize( array( 'access_token' => 'stale' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );

		$this->assertSame( 'central-access', get_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION )['access_token'] );
	}

	/**
	 * A refresh on the Groups network lands on Central, and Groups keeps no copy.
	 */
	public function test_groups_refresh_writes_to_central() {
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );

		$refreshed = array_merge( $this->token, array( 'access_token' => 'refreshed-access' ) );
		update_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $refreshed );

		$this->assertSame( $refreshed, get_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
		$this->assertSame( $refreshed, get_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
		$this->assertFalse( $this->groups_row_exists() );
	}

	/**
	 * A reset on the Groups network resets Central's token.
	 */
	public function test_groups_reset_deletes_central_token() {
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );

		delete_network_option( GROUPS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION );

		$this->assertFalse( get_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
	}

	/**
	 * Central's own reads and writes are untouched.
	 */
	public function test_central_is_unaffected() {
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );
		$this->assertSame( $this->token, get_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );

		delete_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION );
		$this->assertFalse( get_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
	}

	/**
	 * Other networks keep their own option.
	 */
	public function test_other_networks_are_unaffected() {
		update_network_option( WORDCAMP_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION, $this->token );

		$this->assertFalse( get_network_option( EVENTS_NETWORK_ID, Meetup_Shared_Connection\TOKEN_OPTION ) );
	}
}

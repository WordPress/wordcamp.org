<?php

namespace WordCamp\Budgets\Tests;

use ReflectionMethod;
use WP_UnitTestCase;
use WordCamp_Budget_Tool;

defined( 'WPINC' ) || die();

/**
 * The default rows of a new budget.
 *
 * @group budgets
 */
class Test_Default_Budget extends WP_UnitTestCase {
	/** @var int|null The ID of the root blog, if this class created it. */
	protected static $created_root_blog = null;

	/**
	 * Create Central, where `get_wordcamp_post()` looks for the event's WordCamp post.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::$created_root_blog = $factory->blog->create( array(
				'blog_id'    => WORDCAMP_ROOT_BLOG_ID,
				'network_id' => WORDCAMP_NETWORK_ID,
			) );
		}
	}

	/**
	 * Remove the root blog this class created.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$created_root_blog ) {
			wp_delete_site( self::$created_root_blog );
			self::$created_root_blog = null;
		}
	}

	/**
	 * Get the default budget for the current site.
	 *
	 * @return array
	 */
	protected function get_default_budget() {
		$method = new ReflectionMethod( WordCamp_Budget_Tool::class, '_get_default_budget' );

		return $method->invoke( null );
	}

	/**
	 * Create the current site's WordCamp post on Central.
	 *
	 * @param string $event_subtype The event's `event_subtype` meta, if any.
	 */
	protected function make_wordcamp( $event_subtype ) {
		$site_id = get_current_blog_id();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$wordcamp_id = self::factory()->post->create( array(
			'post_type'   => 'wordcamp',
			'post_status' => 'wcpt-scheduled',
		) );
		update_post_meta( $wordcamp_id, '_site_id', $site_id );
		if ( $event_subtype ) {
			update_post_meta( $wordcamp_id, 'event_subtype', $event_subtype );
		}
		restore_current_blog();
	}

	/**
	 * Get the income notes of a budget.
	 *
	 * @param array $budget The budget rows.
	 *
	 * @return string[]
	 */
	protected function get_income_notes( $budget ) {
		return array_values( wp_list_pluck( wp_list_filter( $budget, array( 'type' => 'income' ) ), 'note' ) );
	}

	/**
	 * Campus Connect budgets ask how many campuses the community sponsorship covers (#1750).
	 */
	public function test_campus_connect_community_sponsorships_note_asks_for_campuses() {
		$this->make_wordcamp( 'campusconnect' );

		$notes = $this->get_income_notes( $this->get_default_budget() );

		$this->assertContains( 'Community Sponsorships (Max $300 USD per campus x [# of Campuses])', $notes );
		$this->assertNotContains( 'Community Sponsorships', $notes );
	}

	/**
	 * Other events keep the plain note.
	 */
	public function test_other_events_keep_plain_community_sponsorships_note() {
		$this->make_wordcamp( '' );

		$notes = $this->get_income_notes( $this->get_default_budget() );

		$this->assertContains( 'Community Sponsorships', $notes );
	}
}

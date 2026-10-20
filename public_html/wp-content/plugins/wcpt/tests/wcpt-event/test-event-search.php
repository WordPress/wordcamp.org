<?php

namespace WordCamp\WCPT\Tests;
use WP_UnitTestCase;
use WordCamp_Admin;
use Meetup_Admin;

defined( 'WPINC' ) || die();

/**
 * Tests for Event_Loader search functionality
 *
 * @group wcpt
 */
class Test_Event_Search extends WP_UnitTestCase {

	/**
	 * @covers WordCamp_Admin::get_searchable_meta_keys
	 */
	public function test_wordcamp_searchable_meta_keys_returns_array() {
		$keys = WordCamp_Admin::get_searchable_meta_keys();
		$this->assertIsArray( $keys );
		$this->assertNotEmpty( $keys );
	}

	/**
	 * @covers WordCamp_Admin::get_searchable_meta_keys
	 */
	public function test_wordcamp_searchable_meta_keys_includes_organizer_name() {
		$keys = WordCamp_Admin::get_searchable_meta_keys();
		$this->assertContains( 'Organizer Name', $keys );
	}

	/**
	 * @covers WordCamp_Admin::get_searchable_meta_keys
	 */
	public function test_wordcamp_searchable_meta_keys_includes_location() {
		$keys = WordCamp_Admin::get_searchable_meta_keys();
		$this->assertContains( 'Location', $keys );
	}

	/**
	 * @covers WordCamp_Admin::get_searchable_meta_keys
	 */
	public function test_wordcamp_searchable_meta_keys_excludes_urls() {
		$keys = WordCamp_Admin::get_searchable_meta_keys();
		$this->assertNotContains( 'URL', $keys, 'URL field should not be searchable for performance' );
	}

	/**
	 * @covers Meetup_Admin::get_searchable_meta_keys
	 */
	public function test_meetup_searchable_meta_keys_returns_array() {
		$keys = Meetup_Admin::get_searchable_meta_keys();
		$this->assertIsArray( $keys );
		$this->assertNotEmpty( $keys );
	}

	/**
	 * @covers Meetup_Admin::get_searchable_meta_keys
	 */
	public function test_meetup_searchable_meta_keys_includes_organizer_name() {
		$keys = Meetup_Admin::get_searchable_meta_keys();
		$this->assertContains( 'Organizer Name', $keys );
	}

	/**
	 * @covers Meetup_Admin::get_searchable_meta_keys
	 */
	public function test_meetup_searchable_meta_keys_excludes_urls() {
		$keys = Meetup_Admin::get_searchable_meta_keys();
		$this->assertNotContains( 'Meetup URL', $keys, 'Meetup URL field should not be searchable for performance' );
	}

	/**
	 * Test that search query includes postmeta when searching WordCamp posts
	 */
	public function test_search_query_includes_postmeta_join() {
		// Create a WordCamp post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_title'  => 'Test WordCamp',
				'post_status' => 'wcpt-needs-vetting',
			)
		);

		// Add organizer name meta.
		update_post_meta( $post_id, 'Organizer Name', 'John Doe' );

		// Perform search for the organizer name.
		$query = new \WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				's'         => 'John Doe',
			)
		);

		// Verify the post is found.
		$this->assertSame( 1, $query->found_posts, 'Search should find post by organizer name' );
		$this->assertSame( $post_id, $query->posts[0]->ID );
	}

	/**
	 * Test that search query finds posts by Location meta
	 */
	public function test_search_query_finds_by_location() {
		// Create a WordCamp post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_title'  => 'Test WordCamp 2',
				'post_status' => 'wcpt-needs-vetting',
			)
		);

		// Add Location meta.
		update_post_meta( $post_id, 'Location', 'San Francisco, CA, USA' );

		// Perform search for part of the location.
		$query = new \WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				's'         => 'San Francisco',
			)
		);

		// Verify the post is found.
		$this->assertSame( 1, $query->found_posts, 'Search should find post by Location' );
		$this->assertSame( $post_id, $query->posts[0]->ID );
	}

	/**
	 * Test that search still finds posts by post_title (original behavior)
	 */
	public function test_search_query_still_finds_by_title() {
		// Create a WordCamp post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_title'  => 'Seattle WordCamp',
				'post_status' => 'wcpt-needs-vetting',
			)
		);

		// Perform search for title.
		$query = new \WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				's'         => 'Seattle',
			)
		);

		// Verify the post is found.
		$this->assertSame( 1, $query->found_posts, 'Search should still find post by title' );
		$this->assertSame( $post_id, $query->posts[0]->ID );
	}

	/**
	 * Test that search doesn't affect non-event post types
	 */
	public function test_search_does_not_affect_regular_posts() {
		// Create a regular post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);

		// Add some meta that matches our search.
		update_post_meta( $post_id, 'Organizer Name', 'Jane Smith' );

		// Perform search on regular posts.
		$query = new \WP_Query(
			array(
				'post_type' => 'post',
				's'         => 'Jane Smith',
			)
		);

		// Regular posts should not be found by meta.
		$this->assertSame( 0, $query->found_posts, 'Regular posts should not be found by meta search' );
	}
}

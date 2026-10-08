<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests that the listing shortcodes and widgets show published posts only.
 *
 * The three sidebar widgets store their rendered markup in a transient and
 * serve it to every visitor, so the listings must not depend on who runs the
 * query.
 *
 * @group wc-post-types
 */
class Test_Listing_Visibility extends WP_UnitTestCase {
	/**
	 * The statuses each listing is checked against, keyed by title prefix.
	 *
	 * @var array
	 */
	const STATUSES = array(
		'Published' => array( 'post_status' => 'publish' ),
		'Private'   => array( 'post_status' => 'private' ),
		'Draft'     => array( 'post_status' => 'draft' ),
		'Locked'    => array(
			'post_status'   => 'publish',
			'post_password' => 'hunter2',
		),
	);

	/**
	 * An administrator, who can read private posts of these types.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Post IDs by "{$prefix} {$label}" title.
	 *
	 * @var array
	 */
	protected static $posts = array();

	/**
	 * Create one post per status for each of the four listed post types.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );

		$level = $factory->term->create( array( 'taxonomy' => 'wcb_sponsor_level' ) );

		$types = array(
			'wcb_speaker'   => 'Speaker',
			'wcb_session'   => 'Session',
			'wcb_organizer' => 'Organizer',
			'wcb_sponsor'   => 'Sponsor',
		);

		foreach ( $types as $type => $label ) {
			foreach ( self::STATUSES as $prefix => $args ) {
				$title = "$prefix $label";

				$post_args = array(
					'post_type'    => $type,
					'post_title'   => $title,
					'post_content' => "Body of the $title.",
				);

				$id = $factory->post->create( array_merge( $args, $post_args ) );

				if ( 'wcb_sponsor' === $type ) {
					wp_set_object_terms( $id, $level, 'wcb_sponsor_level' );
				}

				self::$posts[ $title ] = $id;
			}
		}

		// Every speaker presents the published session, so its meta names them.
		foreach ( self::STATUSES as $prefix => $args ) {
			add_post_meta( self::$posts['Published Session'], '_wcpt_speaker_id', self::$posts[ "$prefix Speaker" ] );
		}
	}

	/**
	 * Remove the fixtures.
	 */
	public static function wpTearDownAfterClass() {
		foreach ( self::$posts as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Register the widgets and act as the administrator.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WCPT_Widget_Speakers' ) ) {
			$GLOBALS['wcpt_plugin']->register_widgets();
		}

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Drop the widget caches the tests fill.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );

		foreach ( array( 'wcpt_speakers', 'wcpt_sessions', 'wcpt_organizers' ) as $id_base ) {
			delete_transient( 'wcpt-' . md5( "$id_base-2" ) );
		}

		parent::tear_down();
	}

	/**
	 * Assert that only the published, password-free post of a type is listed.
	 *
	 * @param string $markup Rendered listing.
	 * @param string $label  Post type label used in the fixture titles.
	 */
	protected function assert_published_only( $markup, $label ) {
		$this->assertStringContainsString( "Published $label", $markup );

		foreach ( array( 'Private', 'Draft', 'Locked' ) as $prefix ) {
			$this->assertStringNotContainsString( "$prefix $label", $markup );
		}
	}

	/**
	 * @dataProvider data_shortcodes
	 */
	public function test_shortcode_lists_published_posts_only( $shortcode, $label ) {
		$this->assert_published_only( do_shortcode( $shortcode ), $label );
	}

	/**
	 * Shortcodes and the label of the post type they list.
	 */
	public function data_shortcodes() {
		return array(
			array( '[speakers]', 'Speaker' ),
			array( '[sessions]', 'Session' ),
			array( '[organizers]', 'Organizer' ),
			array( '[sponsors]', 'Sponsor' ),
		);
	}

	/**
	 * The session meta names the speakers, so it is subject to the same rule.
	 */
	public function test_session_meta_names_published_speakers_only() {
		$this->assert_published_only( do_shortcode( '[sessions show_meta="true"]' ), 'Speaker' );
	}

	/**
	 * A track with no sessions has no speakers, rather than all of them.
	 */
	public function test_speakers_shortcode_with_empty_track_lists_nobody() {
		self::factory()->term->create( array(
			'taxonomy' => 'wcb_track',
			'slug'     => 'empty-track',
		) );

		$this->assertSame( '', do_shortcode( '[speakers track="empty-track"]' ) );
	}

	/**
	 * A widget primed by an administrator serves the same markup to a visitor.
	 *
	 * @dataProvider data_widgets
	 */
	public function test_widget_cached_by_admin_lists_published_posts_only( $widget, $id_base, $label ) {
		$instance = array(
			'title'  => '',
			'count'  => 10,
			'random' => false,
		);
		$args     = array(
			'widget_id'     => "$id_base-2",
			'before_widget' => '',
			'after_widget'  => '',
			'before_title'  => '',
			'after_title'   => '',
		);

		ob_start();
		the_widget( $widget, $instance, $args );
		$this->assert_published_only( ob_get_clean(), $label );

		// Confirm the admin render filled the transient the anonymous one reads, so the second assertion cannot pass for the wrong reason.
		$this->assertNotFalse( get_transient( 'wcpt-' . md5( "$id_base-2" ) ) );

		wp_set_current_user( 0 );

		ob_start();
		the_widget( $widget, $instance, $args );
		$this->assert_published_only( ob_get_clean(), $label );
	}

	/**
	 * Widget classes, their id bases and the label of the post type they list.
	 */
	public function data_widgets() {
		return array(
			array( 'WCPT_Widget_Speakers', 'wcpt_speakers', 'Speaker' ),
			array( 'WCPT_Widget_Sessions', 'wcpt_sessions', 'Session' ),
			array( 'WCPT_Widget_Organizers', 'wcpt_organizers', 'Organizer' ),
		);
	}
}

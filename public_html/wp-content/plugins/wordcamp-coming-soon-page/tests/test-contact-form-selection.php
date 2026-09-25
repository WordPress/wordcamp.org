<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_UnitTestCase;
use WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::get_contact_form_shortcode
 */
class Test_Contact_Form_Selection extends WP_UnitTestCase {
	/**
	 * @var WordCamp_Coming_Soon_Page
	 */
	protected static $coming_soon;

	/**
	 * @var int Older page with a contact form.
	 */
	protected static $older_page_id;

	/**
	 * @var int Newer page with a contact form.
	 */
	protected static $newer_page_id;

	/**
	 * @var int Page without any form.
	 */
	protected static $formless_page_id;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$coming_soon = new WordCamp_Coming_Soon_Page();

		self::$older_page_id = $factory->post->create( array(
			'post_type'    => 'page',
			'post_date'    => '2026-01-01 10:00:00',
			'post_content' => '[contact-form][/contact-form]',
		) );

		self::$newer_page_id = $factory->post->create( array(
			'post_type'    => 'page',
			'post_date'    => '2026-02-01 10:00:00',
			'post_content' => '[contact-form][/contact-form]',
		) );

		self::$formless_page_id = $factory->post->create( array(
			'post_type'    => 'page',
			'post_date'    => '2026-03-01 10:00:00',
			'post_content' => 'No form here.',
		) );
	}

	/**
	 * Registers a stand-in for Jetpack's contact-form shortcode that reports
	 * which page it was rendered on, so the tests do not depend on Jetpack.
	 */
	public function set_up(): void {
		parent::set_up();

		$form_stub = function () {
			return 'FORM_FROM_PAGE_' . get_the_ID();
		};

		add_shortcode( 'contact-form', $form_stub );
	}

	/**
	 * Cleans the shortcode and the plugin settings between tests.
	 */
	public function tear_down(): void {
		remove_shortcode( 'contact-form' );
		delete_option( 'wccsp_settings' );
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Without a selection, the form on the oldest page wins.
	 */
	public function test_default_uses_the_oldest_page_with_a_form(): void {
		$rendered = self::$coming_soon->get_contact_form_shortcode();

		$this->assertSame( 'FORM_FROM_PAGE_' . self::$older_page_id, $rendered );
	}

	/**
	 * A selected page's form wins over the oldest one.
	 */
	public function test_selected_page_wins(): void {
		update_option( 'wccsp_settings', array( 'contact_form_page_id' => self::$newer_page_id ) );

		$rendered = self::$coming_soon->get_contact_form_shortcode();

		$this->assertSame( 'FORM_FROM_PAGE_' . self::$newer_page_id, $rendered );
	}

	/**
	 * A selected page without a form falls back to the oldest page's form.
	 */
	public function test_selected_page_without_a_form_falls_back(): void {
		update_option( 'wccsp_settings', array( 'contact_form_page_id' => self::$formless_page_id ) );

		$rendered = self::$coming_soon->get_contact_form_shortcode();

		$this->assertSame( 'FORM_FROM_PAGE_' . self::$older_page_id, $rendered );
	}

	/**
	 * With no selection saved, a published global post must not leak in through
	 * get_post( 0 ) and steal the selection.
	 */
	public function test_no_selection_ignores_the_global_post(): void {
		update_option( 'wccsp_settings', array( 'contact_form_page_id' => 0 ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberately recreates the global post that exists during a real page render.
		$GLOBALS['post'] = get_post( self::$newer_page_id );

		$rendered = self::$coming_soon->get_contact_form_shortcode();

		$this->assertSame( 'FORM_FROM_PAGE_' . self::$older_page_id, $rendered );
	}

	/**
	 * A selection pointing at a trashed page falls back to the oldest page's form.
	 */
	public function test_trashed_selected_page_falls_back(): void {
		$trashed_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_date'    => '2026-04-01 10:00:00',
			'post_content' => '[contact-form][/contact-form]',
		) );
		wp_trash_post( $trashed_id );

		update_option( 'wccsp_settings', array( 'contact_form_page_id' => $trashed_id ) );

		$rendered = self::$coming_soon->get_contact_form_shortcode();

		$this->assertSame( 'FORM_FROM_PAGE_' . self::$older_page_id, $rendered );
	}
}

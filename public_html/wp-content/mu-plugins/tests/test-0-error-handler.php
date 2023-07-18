<?php

namespace WordCamp\Error_Handling\Tests;
use function WordCamp\Error_Handling\{ get_destination_channels, is_third_party_file };
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group error-handler
 */
class Test_Error_Handling extends WP_UnitTestCase {
	/**
	 * @covers WordCamp\Error_Handling\is_third_party_file
	 *
	 * @dataProvider data_is_third_party_file
	 */
	public function test_is_third_party_file( $file, $expected ) {
		$actual = is_third_party_file( $file );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_is_third_party_file().
	 *
	 * @return array
	 */
	public function data_is_third_party_file() {
		// Be careful to use trailing slashes correctly, see the note in `is_third_party_file()`.

		return array(
			/*
			 * Third party code.
			 */
			'xmlrpc' => array(
				ABSPATH . 'xmlrpc.php',
				true,
			),

			'core `wp-*.php` root file' => array(
				ABSPATH . 'wp-cron.php',
				true,
			),

			'core admin' => array(
				ABSPATH . 'wp-admin/includes/class-wp-site-health.php',
				true,
			),

			'core include' => array(
				ABSPATH . 'wp-includes/SimplePie/Registry.php',
				true,
			),

			'core-themes' => array(
				WP_CONTENT_DIR . '/themes/twentytwenty/functions.php',
				true,
			),

			'hyperdb' => array(
				WP_PLUGIN_DIR . '/hyperdb/db.php',
				true,
			),

			'camptix-paystack' => array(
				WP_PLUGIN_DIR . '/camptix-paystack/includes/class-paystack.php',
				true,
			),

			/*
			 * Exceptions.
			 */
			"`index.php` could be Core's front controller, or our wrapper" => array(
				ABSPATH . 'index.php',
				false,
			),

			'gutenberg errors are sent to a special channel' => array(
				WP_PLUGIN_DIR . '/gutenberg/build/block-library/blocks/latest-posts.php',
				false,
			),

			'jetpack errors are sent to a special channel' => array(
				WP_PLUGIN_DIR . '/jetpack/jetpack.php',
				false,
			),

			/*
			 * WordCamp code.
			 */
			'wp-config.php' => array(
				ABSPATH . 'wp-config.php',
				false,
			),

			'cron mu-plugin' => array(
				SUT_WPMU_PLUGIN_DIR . '/cron.php',
				false,
			),

			'wcpt' => array(
				WP_PLUGIN_DIR . '/wcpt/wcpt-admin.php',
				false,
			),

			'campsite' => array(
				WP_CONTENT_DIR . '/themes/campsite-2017/functions.php',
				false,
			),
		);
	}

	/**
	 * @covers WordCamp\Error_Handling\get_destination_channels
	 *
	 * @dataProvider data_get_destination_channels
	 */
	public function test_get_destination_channels( $file, $environment, $is_fatal_error, $expected ) {
		$actual = get_destination_channels( $file, $environment, $is_fatal_error );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_destination_channels().
	 *
	 * @return array
	 */
	public function data_get_destination_channels() {
		return array(
			'production | jetpack | warning - only jetpack channel' => array(
				WP_PLUGIN_DIR . '/jetpack/jetpack.php',
				'production',
				false,
				array( WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL ),
			),

			'production | gutenberg | warning - only gutenberg channel' => array(
				WP_PLUGIN_DIR . '/gutenberg/build/block-library/blocks/latest-posts.php',
				'production',
				false,
				array( WORDCAMP_LOGS_GUTENBERG_SLACK_CHANNEL ),
			),

			'production | jetpack | fatal - both channels' => array(
				WP_PLUGIN_DIR . '/jetpack/jetpack.php',
				'production',
				true,
				array( WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL, WORDCAMP_LOGS_SLACK_CHANNEL ),
			),

			'production | custom | warning - main channel' => array(
				WP_PLUGIN_DIR . '/wcpt/wcpt-admin.php',
				'production',
				false,
				array( WORDCAMP_LOGS_SLACK_CHANNEL ),
			),

			'production | custom | fatal - main channel' => array(
				WP_PLUGIN_DIR . '/wcpt/wcpt-admin.php',
				'production',
				true,
				array( WORDCAMP_LOGS_SLACK_CHANNEL ),
			),

			'development | gutenberg | warning - ignored' => array(
				WP_PLUGIN_DIR . '/gutenberg/build/block-library/blocks/latest-posts.php',
				'development',
				false,
				array(),
			),

			'development | custom | warning - DM' => array(
				WP_PLUGIN_DIR . '/wcpt/wcpt-admin.php',
				'development',
				false,
				array( SANDBOX_SLACK_USERNAME ),
			),

			'local | custom | fatal - ignored' => array(
				WP_PLUGIN_DIR . '/wcpt/wcpt-admin.php',
				'local',
				true,
				array(),
			),
		);
	}
}

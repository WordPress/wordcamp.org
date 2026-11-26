<?php

namespace WordCamp\Groups\Tests;

use WP_Error;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\get_group_info;
use function WordCamp\Groups\Frontend\REST\update_group_info;
use function WordCamp\Groups\Frontend\Site_Language\get_installed_locales;
use function WordCamp\Groups\Frontend\Site_Language\get_options;
use function WordCamp\Groups\Frontend\Site_Language\get_site_language;
use function WordCamp\Groups\Frontend\Site_Language\save_site_language;
use function WordCamp\Groups\Frontend\Site_Language\validate_locale;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Group-level site language helpers and `/group-info` integration.
 *
 * @group groups
 */
class Test_Groups_Site_Language extends Groups_TestCase {

	/**
	 * An organizer, who may change this group's settings.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Creates the organizer each test acts as.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Clears `WPLANG` and any stub filters installed by a test.
	 */
	protected function tearDown(): void {
		remove_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );
		remove_filter( 'translations_api', array( $this, 'filter_translations_api' ) );
		delete_site_transient( 'available_translations' );
		delete_option( 'WPLANG' );
		delete_option( 'blogname' );

		parent::tearDown();
	}

	/**
	 * Stub installed locales so tests do not depend on downloaded `.mo` files.
	 *
	 * @return string[]
	 */
	public function filter_available_languages(): array {
		return array( 'pt_BR', 'pl_PL', 'es_ES' );
	}

	/**
	 * Stub core's translation API so `get_locale_names()` never makes an HTTP request.
	 *
	 * @param false|array $result Filtered result.
	 * @param string      $type   Translation type.
	 *
	 * @return false|array
	 */
	public function filter_translations_api( $result, string $type ) {
		if ( 'core' !== $type ) {
			return $result;
		}

		return array(
			'translations' => array(
				array(
					'language'     => 'pl_PL',
					'english_name' => 'Polish',
					'native_name'  => 'Polski',
				),
				array(
					'language'     => 'pt_BR',
					'english_name' => 'Portuguese (Brazil)',
					'native_name'  => 'Português do Brasil',
				),
				array(
					'language'     => 'es_ES',
					'english_name' => 'Spanish (Spain)',
					'native_name'  => 'Español',
				),
			),
		);
	}

	/**
	 * US English is represented as `''`, matching core's General Settings.
	 */
	public function test_defaults_to_empty_and_normalizes_en_us() {
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );

		$this->assertSame( '', get_site_language() );

		update_option( 'WPLANG', 'en_US' );
		$this->assertSame( '', get_site_language() );

		save_site_language( 'pl_PL' );
		$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );
		$this->assertSame( 'pl_PL', get_site_language() );

		save_site_language( 'en_US' );
		$this->assertSame( '', get_option( 'WPLANG' ) );
	}

	/**
	 * English (United States) stays first, followed by installed locales sorted by native name.
	 */
	public function test_options_list_english_first_and_sort_installed_locales_by_native_name() {
		delete_site_transient( 'available_translations' );
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );
		add_filter( 'translations_api', array( $this, 'filter_translations_api' ), 10, 2 );

		$this->assertSame( array( 'pt_BR', 'pl_PL', 'es_ES' ), get_installed_locales() );

		$options = get_options();

		$this->assertSame(
			array(
				array(
					'code' => '',
					'name' => 'English (United States)',
				),
				array(
					'code' => 'es_ES',
					'name' => 'Español',
				),
				array(
					'code' => 'pl_PL',
					'name' => 'Polski',
				),
				array(
					'code' => 'pt_BR',
					'name' => 'Português do Brasil',
				),
			),
			$options
		);
	}

	/**
	 * Only empty string, `en_US`, or an installed locale is accepted.
	 */
	public function test_validate_locale_accepts_installed_and_rejects_uninstalled() {
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );

		$this->assertSame( '', validate_locale( '' ) );
		$this->assertSame( '', validate_locale( 'en_US' ) );
		$this->assertSame( 'pl_PL', validate_locale( ' pl_PL ' ) );

		$invalid = validate_locale( 'xx_INVALID' );

		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'wporg_groups_invalid_site_language', $invalid->get_error_code() );
	}

	/**
	 * `/group-info` exposes the current `siteLanguage` and `siteLanguages` choices.
	 */
	public function test_group_info_exposes_site_language_and_options() {
		delete_site_transient( 'available_translations' );
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );
		add_filter( 'translations_api', array( $this, 'filter_translations_api' ), 10, 2 );

		wp_set_current_user( $this->editor_id );
		save_site_language( 'pl_PL' );

		$data = get_group_info()->get_data();

		$this->assertSame( 'pl_PL', $data['siteLanguage'] );
		$this->assertCount( 4, $data['siteLanguages'] );
		$this->assertSame( '', $data['siteLanguages'][0]['code'] );
		$this->assertContains( 'pl_PL', wp_list_pluck( $data['siteLanguages'], 'code' ) );
	}

	/**
	 * Saving updates or clears `WPLANG`, invalid values fail atomically, and absent fields stay intact.
	 */
	public function test_group_info_saves_clears_and_rejects_invalid_site_language() {
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );
		wp_set_current_user( $this->editor_id );
		update_option( 'blogname', 'Olsztyn WordPress Community' );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$request->set_param( 'site_language', 'pl_PL' );
		$response = update_group_info( $request );

		$this->assertNotWPError( $response );
		$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );
		$this->assertSame( 'pl_PL', $response->get_data()['siteLanguage'] );

		// Omitting `site_language` preserves the stored locale.
		$unrelated = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$unrelated->set_param( 'title', 'Olsztyn WordPress' );
		$this->assertNotWPError( update_group_info( $unrelated ) );
		$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );

		// An invalid locale fails before writing unrelated fields or clobbering `WPLANG`.
		$invalid = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$invalid->set_param( 'title', 'Should Not Be Saved' );
		$invalid->set_param( 'site_language', 'xx_INVALID' );
		$error = update_group_info( $invalid );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'wporg_groups_invalid_site_language', $error->get_error_code() );
		$this->assertSame( 'Olsztyn WordPress', get_option( 'blogname' ) );
		$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );

		// Passing an empty string switches back to US English.
		$clear = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$clear->set_param( 'site_language', '' );
		$this->assertNotWPError( update_group_info( $clear ) );
		$this->assertSame( '', get_option( 'WPLANG' ) );
	}

	/**
	 * Non-string `site_language` payloads (`null` or `array`) return 400 instead of resetting to English.
	 */
	public function test_group_info_rejects_null_and_array_site_language() {
		add_filter( 'get_available_languages', array( $this, 'filter_available_languages' ) );
		wp_set_current_user( $this->editor_id );
		save_site_language( 'pl_PL' );

		foreach ( array( null, array( 'pl_PL' ) ) as $invalid_value ) {
			$json_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
			$json_request->set_header( 'content-type', 'application/json' );
			$json_request->set_body( wp_json_encode( array( 'site_language' => $invalid_value ) ) );
			$json_response = rest_do_request( $json_request );

			$this->assertSame( 400, $json_response->get_status() );
			$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );

			$direct_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
			$direct_request->set_param( 'site_language', $invalid_value );
			$direct_error = update_group_info( $direct_request );

			$this->assertInstanceOf( WP_Error::class, $direct_error );
			$this->assertSame( 'wporg_groups_invalid_site_language', $direct_error->get_error_code() );
			$this->assertSame( 400, $direct_error->get_error_data()['status'] );
			$this->assertSame( 'pl_PL', get_option( 'WPLANG' ) );
		}
	}
}

<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Modal\get_upload_space_config;
use function WordCamp\Groups\Frontend\Modal\localize_block_script;
use function WordCamp\Groups\Frontend\Upload_Space\format_space;
use function WordCamp\Groups\Frontend\Upload_Space\get_upload_space;

use const WordCamp\Groups\Frontend\Upload_Space\MORE_SPACE_URL;
use const WordCamp\Groups\Frontend\Upload_Space\SPACE_ALLOWED;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Upload space on group sites (#2361).
 *
 * @group groups
 */
class Test_Groups_Upload_Space extends Groups_TestCase {

	/**
	 * Space the site reports as used, in MB, through `pre_get_space_used`.
	 *
	 * @var float
	 */
	private $space_used = 0.0;

	/**
	 * Starts each test from core's default network space, with nothing used.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_site_option( 'blog_upload_space', 100 );
		delete_site_option( 'upload_space_check_disabled' );

		$this->space_used = 0.0;
		add_filter( 'pre_get_space_used', array( $this, 'filter_space_used' ) );
	}

	/**
	 * Stops reporting the made-up space used.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_get_space_used', array( $this, 'filter_space_used' ) );
		delete_site_option( 'upload_space_check_disabled' );

		parent::tearDown();
	}

	/**
	 * @return float Space used, in MB.
	 */
	public function filter_space_used(): float {
		return $this->space_used;
	}

	/**
	 * Group sites get more than the network's 100 MB.
	 */
	public function test_group_sites_get_more_than_the_network_default(): void {
		$this->assertSame( SPACE_ALLOWED, (int) get_space_allowed() );
	}

	/**
	 * A network default bigger than `SPACE_ALLOWED` is kept.
	 */
	public function test_a_bigger_network_default_is_kept(): void {
		update_site_option( 'blog_upload_space', 2048 );

		$this->assertSame( 2048, (int) get_space_allowed() );
	}

	/**
	 * A super admin's setting for one site wins, even when it's lower.
	 */
	public function test_a_sites_own_space_wins(): void {
		update_option( 'blog_upload_space', 50 );

		$this->assertSame( 50, (int) get_space_allowed() );
	}

	/**
	 * Data provider for test_status().
	 */
	public function data_status(): array {
		return array(
			'plenty of room'        => array( 10, 'ok' ),
			'just under 90%'        => array( 921, 'ok' ),
			'90% used'              => array( 922, 'near' ),
			'used up'               => array( 1024, 'full' ),
			'over, after an import' => array( 1100, 'full' ),
		);
	}

	/**
	 * The status the picker warns on.
	 *
	 * @dataProvider data_status
	 *
	 * @param float  $used_mb  Space used.
	 * @param string $expected Status.
	 */
	public function test_status( float $used_mb, string $expected ): void {
		$this->space_used = $used_mb;

		$space = get_upload_space();

		$this->assertSame( $expected, $space['status'] );
		$this->assertSame( SPACE_ALLOWED * MB_IN_BYTES, $space['allowed'] );
		$this->assertSame( (int) round( $used_mb * MB_IN_BYTES ), $space['used'] );
	}

	/**
	 * With the network's space check off, nothing is ever full.
	 */
	public function test_nothing_is_full_with_the_space_check_off(): void {
		update_site_option( 'upload_space_check_disabled', 1 );
		$this->space_used = 5000;

		$this->assertSame( 'ok', get_upload_space()['status'] );
	}

	/**
	 * Core's message, defined only in wp-admin, is swapped for one that also
	 * says where to ask for more.
	 */
	public function test_full_message_replaces_cores(): void {
		$this->assertFalse( has_action( 'upload_ui_over_quota', 'multisite_over_quota_message' ) );

		ob_start();
		do_action( 'upload_ui_over_quota' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'all 1 GB of its upload space', $output );
		$this->assertStringContainsString( 'href="' . MORE_SPACE_URL . '"', $output );
	}

	/**
	 * Regression: the media library's templates rendered on a group's front
	 * end fataled for organizers once the site was over its space.
	 *
	 * Core's `multisite_over_quota_message()` is defined under PHPUnit, so
	 * leaving it hooked prints its message here instead of fataling.
	 */
	public function test_media_templates_render_when_the_space_is_used_up(): void {
		require_once ABSPATH . WPINC . '/media-template.php';

		$this->space_used = 1100;

		ob_start();
		wp_print_media_templates();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Upload Limit Exceeded', $output );
		$this->assertStringContainsString( 'all 1 GB of its upload space', $output );
		$this->assertStringNotContainsString( 'Sorry, you have used your space allocation', $output );
	}

	/**
	 * Data provider for test_format_space().
	 */
	public function data_format_space(): array {
		return array(
			'megabytes'             => array( 950 * MB_IN_BYTES, '950 MB' ),
			'a whole gigabyte'      => array( GB_IN_BYTES, '1 GB' ),
			'a gigabyte and a half' => array( 1536 * MB_IN_BYTES, '1.5 GB' ),
			'just over a gigabyte'  => array( 1040 * MB_IN_BYTES, '1 GB' ),
			'nearly two gigabytes'  => array( 1900 * MB_IN_BYTES, '1.9 GB' ),
		);
	}

	/**
	 * Space reads without a stray ".0", and isn't rounded to whole gigabytes.
	 *
	 * @dataProvider data_format_space
	 *
	 * @param int    $bytes    Space.
	 * @param string $expected As shown.
	 */
	public function test_format_space( int $bytes, string $expected ): void {
		$this->assertSame( $expected, format_space( $bytes ) );
	}

	/**
	 * Organizers get the space in the modal config, formatted for the picker.
	 */
	public function test_config_for_an_organizer(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->space_used = 950;

		$this->assertSame(
			array(
				'status'       => 'near',
				'used'         => '950 MB',
				'allowed'      => '1 GB',
				'moreSpaceUrl' => MORE_SPACE_URL,
			),
			get_upload_space_config()
		);
	}

	/**
	 * Once the space is used up, the picker's help leaves the file size out,
	 * which core would give as 0 B, and the notice says why instead.
	 */
	public function test_config_leaves_the_file_size_out_when_full(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->space_used = 1100;

		$handle = 'wporg-group-settings-view-script';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, 'https://example.org/view.js', array(), '1', true );
		}

		localize_block_script( '', array( 'blockName' => 'wporg/group-settings' ) );

		$data = (string) wp_scripts()->get_data( $handle, 'data' );

		$this->assertMatchesRegularExpression( '/var wporgGroupsEventModal = (\{.*\});/', $data );
		preg_match( '/var wporgGroupsEventModal = (\{.*\});/', $data, $matches );
		$config = json_decode( $matches[1], true );

		$this->assertSame( '', $config['maxUploadSize'] );
		$this->assertSame( 'full', $config['uploadSpace']['status'] );
	}

	/**
	 * Members who can't manage events don't get the site's storage figures.
	 */
	public function test_config_for_a_member(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->space_used = 1100;

		$this->assertSame( array( 'status' => 'ok' ), get_upload_space_config() );
	}
}

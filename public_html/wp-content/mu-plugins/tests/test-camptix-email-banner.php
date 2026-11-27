<?php

namespace WordCamp\Tests;

use function WordCamp\CampTix_Tweaks\{ get_email_banner_url, validate_email_banner };

defined( 'WPINC' ) || die();

// The addon loads on CampTix's `camptix_load_addons`, which this suite never fires.
require_once dirname( __DIR__ ) . '/camptix-tweaks/addons/email-banner.php';

/**
 * Tests for the per-site CampTix email banner.
 *
 * @group mu-plugins
 * @group camptix
 *
 * @package WordCamp\Tests
 */
class Test_CampTix_Email_Banner extends Database_TestCase {
	/**
	 * Reset the CampTix options after each test.
	 */
	public function tear_down() {
		delete_option( 'camptix_options' );
		$this->reload_camptix_options();

		parent::tear_down();
	}

	/**
	 * Make CampTix re-read its options, which it caches.
	 */
	protected function reload_camptix_options() {
		global $camptix;

		if ( $camptix ) {
			$camptix->load_options();
		}
	}

	/**
	 * Create an image attachment.
	 *
	 * @return int
	 */
	protected function create_image() {
		return self::factory()->attachment->create_object(
			'email-banner.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
	}

	/**
	 * Render the email header the way `render_html_emails()` does.
	 *
	 * @param string $banner_url
	 *
	 * @return string
	 */
	protected function render_header( $banner_url ) {
		$phpmailer          = new \stdClass();
		$phpmailer->Subject = 'Your ticket'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$logo_url           = 'https://example.org/wordpress-logo.png';

		ob_start();
		require dirname( __DIR__ ) . '/camptix-tweaks/views/html-mail-header.php';

		return ob_get_clean();
	}

	/**
	 * An image attachment is saved, and anything else clears the banner.
	 */
	public function test_validate_keeps_only_image_attachments() {
		$image_id    = $this->create_image();
		$document_id = self::factory()->attachment->create_object(
			'agenda.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);

		$this->assertSame(
			$image_id,
			validate_email_banner( array(), array( 'email_banner_id' => (string) $image_id ) )['email_banner_id']
		);

		$this->assertArrayNotHasKey(
			'email_banner_id',
			validate_email_banner( array( 'email_banner_id' => $image_id ), array( 'email_banner_id' => (string) $document_id ) )
		);

		$this->assertArrayNotHasKey(
			'email_banner_id',
			validate_email_banner( array( 'email_banner_id' => $image_id ), array( 'email_banner_id' => '' ) )
		);
	}

	/**
	 * Image formats that many email clients can't display are rejected.
	 */
	public function test_validate_rejects_images_email_clients_cannot_display() {
		$image_id = $this->create_image();
		$webp_id  = self::factory()->attachment->create_object(
			'email-banner.webp',
			0,
			array( 'post_mime_type' => 'image/webp' )
		);

		$this->assertArrayNotHasKey(
			'email_banner_id',
			validate_email_banner( array( 'email_banner_id' => $image_id ), array( 'email_banner_id' => (string) $webp_id ) )
		);

		update_option( 'camptix_options', array( 'email_banner_id' => $webp_id ) );
		$this->reload_camptix_options();

		$this->assertSame( '', get_email_banner_url() );
	}

	/**
	 * Saving another Setup tab, which doesn't submit the field, keeps the banner.
	 */
	public function test_validate_keeps_banner_when_field_not_submitted() {
		$image_id = $this->create_image();

		$this->assertSame(
			array( 'email_banner_id' => $image_id ),
			validate_email_banner( array( 'email_banner_id' => $image_id ), array( 'currency' => 'USD' ) )
		);
	}

	/**
	 * Without a banner, emails keep the WordPress logo and event name.
	 */
	public function test_header_without_banner_keeps_default() {
		$this->assertSame( '', get_email_banner_url() );

		$header = $this->render_header( get_email_banner_url() );

		$this->assertStringContainsString( 'src="https://example.org/wordpress-logo.png"', $header );
		$this->assertStringContainsString( '<h1', $header );
	}

	/**
	 * With a banner, emails show it instead of the logo and event name.
	 */
	public function test_header_with_banner_replaces_logo() {
		$image_id = $this->create_image();
		update_option( 'camptix_options', array( 'email_banner_id' => $image_id ) );
		$this->reload_camptix_options();

		$banner_url = get_email_banner_url();
		$this->assertSame( wp_get_attachment_image_url( $image_id, 'large' ), $banner_url );

		$header = $this->render_header( $banner_url );

		$this->assertStringContainsString( 'src="' . esc_url( $banner_url ) . '"', $header );
		$this->assertStringContainsString( 'alt="' . esc_attr( get_wordcamp_name() ) . '"', $header );
		$this->assertStringNotContainsString( 'wordpress-logo.png', $header );
		$this->assertStringNotContainsString( '<h1', $header );
	}
}

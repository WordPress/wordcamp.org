<?php

namespace WordCamp\RemoteCSS;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_User_Interface
 *
 * @group remote-css
 */
class Test_User_Interface extends WP_UnitTestCase {
	/**
	 * Test that valid URLs are allowed
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_valid_url_allowed() {
		$original_url  = 'https://api.github.com/repos/WordPress/example.wordcamp.org-2015/contents/style.css';
		$validated_url = validate_remote_css_url( $original_url );

		$this->assertSame( $original_url, $validated_url );
	}

	/**
	 * Test that empty URLs are invalid
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_empty_url_is_invalid() {
		$this->setExpectedException( '\Exception', 'URL was invalid' );
		validate_remote_css_url( '' );
	}

	/**
	 * Test that absolute file paths are invalid
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_absolute_file_paths_are_invalid() {
		$this->setExpectedException( '\Exception', 'URL was invalid' );
		validate_remote_css_url( '/etc/password' );
	}

	/**
	 * Test that relative file paths are invalid
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_relative_file_paths_are_invalid() {
		$this->setExpectedException( '\Exception', 'URL was invalid' );
		validate_remote_css_url( '../wp-config.php' );
	}

	/**
	 * Test that non-HTTP(S) protocols are invalid
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_non_http_s_protocols_invalid() {
		$this->setExpectedException( '\Exception', 'URL was invalid' );
		validate_remote_css_url( 'ssh://api.github.com/repos/WordPress/example.wordcamp.org-2015/contents/style.css' );
	}

	/**
	 * Test that non-whitelisted URLs are blocked
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_non_whitelisted_urls_blocked() {
		$this->setExpectedException( '\Exception', 'only certain third-party platforms can be used' );
		validate_remote_css_url( 'https://example.org/style.css' );
	}

	/**
	 * Test that non-CSS extensions are blocked
	 *
	 * @covers \WordCamp\RemoteCSS\validate_remote_css_url()
	 */
	public function test_non_css_extensions_blocked() {
		$this->setExpectedException( '\Exception', 'URL must be a vanilla CSS file' );
		validate_remote_css_url( 'https://api.github.com/repos/WordPress/example.wordcamp.org-2015/contents/style.scss' );
	}
}

<?php
/**
 * Tests for `WCP_Encryption`'s dual-read key fallback.
 */

declare( strict_types = 1 );

namespace WordCamp\Budgets\Tests;

use WP_UnitTestCase;
use WCP_Encryption;

defined( 'WPINC' ) || die();

/**
 * Reading bank details while the encryption key is being rotated.
 *
 * Without the fallback, data encrypted under the old key leaves `maybe_decrypt()` returning `''` until the
 * migration script catches up, and the next save writes that over the real bank details.
 *
 * @group budgets
 */
class Test_Encryption extends WP_UnitTestCase {
	/** @var array The key properties as they were before a test rewrote them, keyed by property name. */
	protected $original_keys = array();

	/**
	 * Remember the keys the rest of the suite runs with.
	 *
	 * `init()` only reads the constants while `self::$key` is null, so keys left behind would leak into
	 * every later test.
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( array( 'key', 'hmac_key', 'fallback_key', 'fallback_hmac_key' ) as $property ) {
			$this->original_keys[ $property ] = WCP_Encryption::$$property;
		}
	}

	/**
	 * Put the suite's keys back.
	 */
	public function tear_down(): void {
		foreach ( $this->original_keys as $property => $value ) {
			WCP_Encryption::$$property = $value;
		}

		parent::tear_down();
	}

	/**
	 * Set the key pairs directly, the way the migration script does.
	 *
	 * Constants can't be redefined between tests, and `init()` only reads them once per request.
	 *
	 * @param string $key               The current encryption key.
	 * @param string $hmac_key          The current HMAC key.
	 * @param string $fallback_key      The previous encryption key, or an empty string for none.
	 * @param string $fallback_hmac_key The previous HMAC key, or an empty string for none.
	 */
	protected function set_keys( string $key, string $hmac_key, string $fallback_key = '', string $fallback_hmac_key = '' ): void {
		WCP_Encryption::$key               = $key;
		WCP_Encryption::$hmac_key          = $hmac_key;
		WCP_Encryption::$fallback_key      = $fallback_key;
		WCP_Encryption::$fallback_hmac_key = $fallback_hmac_key;
	}

	/**
	 * Data written since the rotation is still read with the current key.
	 */
	public function test_decrypt_uses_the_current_key_first(): void {
		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'old-hmac' );

		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->assertSame( 'DE89370400440532013000', WCP_Encryption::decrypt( $encrypted ) );
	}

	/**
	 * Data written before the rotation is still readable afterwards.
	 */
	public function test_decrypt_falls_back_to_the_previous_key(): void {
		$this->set_keys( 'old-key', 'old-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'old-hmac' );

		$this->assertSame( 'DE89370400440532013000', WCP_Encryption::decrypt( $encrypted ) );
	}

	/**
	 * The point of the fallback: `maybe_decrypt()` hands back the real value, not the empty string.
	 */
	public function test_maybe_decrypt_returns_previous_key_data(): void {
		$this->set_keys( 'old-key', 'old-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'old-hmac' );

		$error = null;
		$this->assertSame( 'DE89370400440532013000', WCP_Encryption::maybe_decrypt( $encrypted, $error ) );
		$this->assertNull( $error );
	}

	/**
	 * New data is always written under the current key, never the fallback.
	 *
	 * Demoting the pair that encrypted it is the only way it stays readable, so passing proves which key
	 * `encrypt()` used.
	 */
	public function test_encrypt_uses_the_current_key(): void {
		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'old-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'newer-key', 'newer-hmac', 'new-key', 'new-hmac' );

		$this->assertSame( 'DE89370400440532013000', WCP_Encryption::decrypt( $encrypted ) );
	}

	/**
	 * Data that matches neither pair is still rejected.
	 */
	public function test_decrypt_rejects_data_matching_neither_key(): void {
		$this->set_keys( 'unknown-key', 'unknown-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'old-hmac' );
		$decrypted = WCP_Encryption::decrypt( $encrypted );

		$this->assertWPError( $decrypted );
		$this->assertSame( 'HMAC mismatch.', $decrypted->get_error_message() );
	}

	/**
	 * The fallback key is never used on data the fallback HMAC didn't vouch for.
	 *
	 * The fallback key here *would* decrypt the data; the mismatched HMAC key has to stop it anyway.
	 */
	public function test_decrypt_requires_a_matching_fallback_hmac(): void {
		$this->set_keys( 'old-key', 'old-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'new-key', 'new-hmac', 'old-key', 'some-other-hmac' );
		$decrypted = WCP_Encryption::decrypt( $encrypted );

		$this->assertWPError( $decrypted );
		$this->assertSame( 'HMAC mismatch.', $decrypted->get_error_message() );
	}

	/**
	 * With no previous key configured, nothing changes for the existing behaviour.
	 */
	public function test_decrypt_rejects_previous_key_data_when_no_fallback_is_set(): void {
		$this->set_keys( 'old-key', 'old-hmac' );
		$encrypted = WCP_Encryption::encrypt( 'DE89370400440532013000' );

		$this->set_keys( 'new-key', 'new-hmac' );
		$decrypted = WCP_Encryption::decrypt( $encrypted );

		$this->assertWPError( $decrypted );
		$this->assertSame( 'HMAC mismatch.', $decrypted->get_error_message() );
	}
}

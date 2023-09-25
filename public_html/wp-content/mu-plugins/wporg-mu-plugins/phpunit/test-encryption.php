<?php
use WordPressdotorg\MU_Plugins\Encryption\HiddenString;
use const WordPressdotorg\MU_Plugins\Encryption\{ PREFIX, NONCE_LENGTH, KEY_LENGTH };
use function WordPressdotorg\MU_Plugins\Encryption\{encrypt, decrypt, is_encrypted, get_encryption_key, generate_encryption_key };

class Test_WPORG_Encryption extends WP_UnitTestCase {

	public function wpSetUpBeforeClass() {
		self::_wporg_encryption_keys();
	}

	public static function _wporg_encryption_keys() {
		if ( function_exists( 'wporg_encryption_keys' ) ) {
			return;
		}

		function wporg_encryption_keys() {
			static $keys = false;

			if ( ! $keys ) {
				$keys = [
					'default'   => generate_encryption_key(),
					'secondary' => generate_encryption_key(),
				];
			}

			return $keys;
		}
	}

	public function test_encrypt_decrypt() {
		$input     = 'This is a plaintext string. It contains no sensitive data.';
		$context   = 'USER1';
		$encrypted = encrypt( $input, $context );

		$this->assertNotEquals( $input, $encrypted );
		$this->assertStringNotContainsString( $context, $encrypted );

		// Decrypt without $context.
		try {
			decrypt( $encrypted, '' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Invalid cipher text.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Decrypt with incorrect $context.
		try {
			decrypt( $encrypted, 'USER2' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Invalid cipher text.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Decrypt with incorrect key specified.
		try {
			decrypt( $encrypted, $context, 'secondary' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Invalid cipher text.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Decrypt with unknown key specified.
		try {
			decrypt( $encrypted, $context, 'unknown-key' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Encryption key "unknown-key" not defined.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		$decrypted = decrypt( $encrypted, $context );

		$this->assertTrue( $decrypted instanceOf HiddenString );

		$this->assertNotEquals( $input, $decrypted );
		$this->assertEquals( $input, $decrypted->getString() );
	}

	public function test_is_encrypted() {
		$this->assertFalse( is_encrypted( 'TEST STRING' ) );
		$this->assertFalse( is_encrypted( PREFIX ) );
		$this->assertFalse( is_encrypted( PREFIX . 'TEST STRING' ) );

		$string_prefix_length = str_repeat( '.', mb_strlen( PREFIX, '8bit' ) );
		$string_nonce_length  = str_repeat( '.', NONCE_LENGTH );

		$this->assertFalse( is_encrypted( $string_prefix_length . $string_nonce_length ) );
		$this->assertFalse( is_encrypted( $string_prefix_length . $string_nonce_length . 'TEST STRING' ) );

		$this->assertTrue( is_encrypted( PREFIX . $string_nonce_length ) );
		$this->assertTrue( is_encrypted( PREFIX . $string_nonce_length . 'TEST STRING' ) );

		$test_string = 'This is a plaintext string. It contains no sensitive data.';
		$this->assertTrue( is_encrypted( encrypt( $test_string, 'context' ) ) );
	}

	public function test_generate_key_different() {
		$one_key = generate_encryption_key();

		$length = mb_strlen( $one_key->getString(), '8bit' );
		$this->assertEquals( KEY_LENGTH, $length );

		$two_key = generate_encryption_key();
		$this->assertNotEquals( $one_key->getString(), $two_key->getString() );
	}

	public function test_get_encryption_key() {
		$this->assertSame( wporg_encryption_keys()['default']->getString(), get_encryption_key()->getString() );
		$this->assertSame( wporg_encryption_keys()['default']->getString(), get_encryption_key( '' )->getString() );
		$this->assertSame( wporg_encryption_keys()['default']->getString(), get_encryption_key( false )->getString() );

		$this->assertSame( wporg_encryption_keys()['secondary']->getString(), get_encryption_key( 'secondary' )->getString() );

		// Get an unknown key.
		try {
			get_encryption_key( 'unknown-key' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Encryption key "unknown-key" not defined.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}
	}

	public function test_can_encrypt_hiddenstring() {
		$hidden_string = new HiddenString( "TEST STRING" );
		$context       = 'test-context';

		$encrypted = encrypt( $hidden_string, $context );

		$this->assertTrue( is_encrypted( $encrypted ) );

		$this->assertSame( $hidden_string->getString(), decrypt( $encrypted, $context )->getString() );
	}

	public function test_encrypt_decrypt_invalid_inputs() {
		$context = 'test-context';

		// Invalid key specified.
		try {
			encrypt( 'TEST STRING', $context, 'unknown-key' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Encryption key "unknown-key" not defined.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Not-encrypted Invalid data that.
		try {
			decrypt( PREFIX . 'TESTSTRINGTESTSTRINGTESTSTRINGTESTSTRINGTESTSTRINGTESTSTRING', $context );
		} catch( Exception $e ) {
			// This is thrown by sodium_hex2bin().
			$this->assertEquals( 'invalid hex string', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Not-encrypted Possibly-valid data.
		try {
			decrypt( PREFIX . '012345678901234567890123456789012345678901234567890123456789', $context );
		} catch( Exception $e ) {
			$this->assertEquals( 'Invalid cipher text.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Invalid key specified, not-encrypted data that's not long enough.
		try {
			decrypt( 'TEST STRING', $context, 'unknown-key' );
		} catch( Exception $e ) {
			$this->assertEquals( 'Value is not encrypted.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

		// Not-encrypted data that's not long enough.
		try {
			decrypt( 'TEST STRING', $context );
		} catch( Exception $e ) {
			$this->assertEquals( 'Value is not encrypted.', $e->getMessage() );
		} finally {
			$this->assertNotEmpty( $e, 'No Exception thrown?' );
			unset( $e );
		}

	}

	public function test_exported_functions() {
		// This only tests the behavioural functions, not the encryption/decryption.

		$input   = 'This is a plaintext string. It contains no sensitive data.';
		$context = 'test-context';

		$encrypted = wporg_encrypt( $input, $context );

		$this->assertNotEquals( $input, $encrypted );

		$decrypted = wporg_decrypt( $encrypted, $context );

		$this->assertTrue( $decrypted instanceOf HiddenString );

		$this->assertNotSame( $input, $decrypted );
		$this->assertEquals( $input, $decrypted->getString() );
		$this->assertEquals( $input, (string) $decrypted );

		$this->assertFalse( wporg_encrypt( '', $context,  'unknown-key' ) );
		$this->assertFalse( wporg_decrypt( '', $context, 'unknown-key' ) );
		$this->assertFalse( wporg_decrypt( 'TEST STRING', $context ) );
	}

}

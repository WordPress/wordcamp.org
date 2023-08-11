<?php
namespace WordPressdotorg\MU_Plugins\Encryption;
use Exception;
/**
 * Plugin Name: WordPress.org Encryption
 * Description: Encryption functions for use on WordPress.org.
 */
require __DIR__ . '/exports.php';

/**
 * Prefix for encrypted secrets. Contains a version identifier.
 *
 * $t1$ -> v1 (RFC 6238, encrypted with XChaCha20-Poly1305, with a key derived from HMAC-SHA256
 *      of the defined key.
 *
 * @var string
 */
const PREFIX = '$t1$';

/**
 * The length of the keys.
 *
 * @var int
 */
const KEY_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

/**
 * The length of the per-encrypted-item nonce.
 *
 * @var int
 */
const NONCE_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

/**
 * Encrypt a value.
 *
 * @param string $value    Value to encrypt.
 * @param string $context  Additional, authenticated data. This is used in the verification of the authentication tag appended to the ciphertext, but it is not encrypted or stored in the ciphertext.
 * @param string $key_name The name of the key to use for encryption. Optional.
 * @return string Encrypted value, exceptions thrown on error.
 */
function encrypt( $value, string $context, string $key_name = '' ) {
	$nonce = random_bytes( NONCE_LENGTH );
	if ( ! $nonce ) {
		throw new Exception( 'Unable to create a nonce.' );
	}

	if ( empty( $context ) ) {
		throw new Exception( '$context cannot be empty.' );
	}

	if ( $value instanceOf HiddenString ) {
		$value = $value->getString();
	}

	$key       = get_encryption_key( $key_name );
	$encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $value, $context, $nonce, $key->getString() );

	sodium_memzero( $value );

	return PREFIX . sodium_bin2hex( $nonce . $encrypted );
}

/**
 * Decrypt a value.
 *
 * @param string $value    Value to decrypt.
 * @param string $context  Additional, authenticated data. This is used in the verification of the authentication tag appended to the ciphertext, but it is not encrypted or stored in the ciphertext.
 * @param string $key_name The name of the key to use for decryption. Optional.
 * @return HiddenString Decrypted value.
 */
function decrypt( string $value, string $context, string $key_name = '' ) : HiddenString {
	if ( ! is_encrypted( $value ) ) {
		throw new Exception( 'Value is not encrypted.' );
	}

	// Remove the prefix, and convert back to binary.
	$value = mb_substr( $value, mb_strlen( PREFIX, '8bit' ), null, '8bit' );
	$value = sodium_hex2bin( $value );

	if ( mb_strlen( $value, '8bit' ) < NONCE_LENGTH ) {
		throw new Exception( 'Invalid cipher text.' );
	}

	$key       = get_encryption_key( $key_name );
	$nonce     = mb_substr( $value, 0, NONCE_LENGTH, '8bit' );
	$value     = mb_substr( $value, NONCE_LENGTH, null, '8bit' );
	$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $value, $context, $nonce, $key->getString() );

	sodium_memzero( $nonce );
	sodium_memzero( $value );

	if ( false === $plaintext ) {
		throw new Exception( 'Invalid cipher text.' );
	}

	return new HiddenString( $plaintext );
}

/**
 * Check if a value is encrypted.
 *
 * @param HiddenString|string $value Value to check.
 * @return bool True if the value is encrypted, false otherwise.
 */
function is_encrypted( $value ) {
	if ( $value instanceOf HiddenString ) {
		$value = $value->getString();
	}

	if ( ! str_starts_with( $value, PREFIX ) ) {
		return false;
	}

	if ( mb_strlen( $value, '8bit' ) < NONCE_LENGTH + mb_strlen( PREFIX, '8bit' ) ) {
		return false;
	}

	sodium_memzero( $value );

	return true;
}

/**
 * Get the encryption key.
 *
 * @param string $key_name The name of the key to use for decryption.
 * @return HiddenString The encryption key.
 */
function get_encryption_key( string $key_name = '' ) {

	$keys = [];
	if ( function_exists( 'wporg_encryption_keys' ) ) {
		$keys = wporg_encryption_keys();
	}

	if ( ! $key_name ) {
		$key_name = 'default';
	}

	if ( ! isset( $keys[ $key_name ] ) ) {
		throw new Exception( sprintf( 'Encryption key "%s" not defined.', $key_name ) );
	}

	return $keys[ $key_name ];
}

/**
 * Generate a random encryption key.
 *
 * @return HiddenString The encryption key.
 */
function generate_encryption_key() {
	return new HiddenString( random_bytes( KEY_LENGTH ) );
}

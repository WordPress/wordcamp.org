<?php
use WordPressdotorg\MU_Plugins\Encryption\HiddenString;
/**
 * This file contains globally-exported function names for the Encryption plugin.
 *
 * It provides a wrapper around the libsodium's Authenticated Encryption with
 * Additional Data ciphers (AEAD with XChaCha20-Poly1305)
 *
 * NOTE: $context should always be passed, and should either be set to the stringy User ID, or a unique-per-item string.
 *       The context is not stored within the Encrypted data, but is used to validate that the value is being decrypted in the same context.
 */

/**
 * Encrypt a value, with authentication.
 *
 * Unlike the Encryption plugin, this function simply returns false for any errors.
 *
 * @param string $value    The plaintext value.
 * @param string $context  Additional, authenticated data. This is used in the verification of the authentication tag appended to the ciphertext, but it is not encrypted or stored in the ciphertext.
 * @param string $key_name The name of the key to use for encryption. Optional.
 * @return string|false The encrypted value, or false on error.
 */
function wporg_encrypt( $value, string $context, string $key_name = '' ) {
	try {
		return \WordPressdotorg\MU_Plugins\Encryption\encrypt( $value, $context, $key_name );
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Decrypt a value, with authentication.
 *
 * Unlike the Encryption plugin, this function simply returns false for any errors, and
 * HiddenStrings that can be cast to string as needed.
 *
 * @param string $value    The encrypted value.
 * @param string $context  Additional, authenticated data. This is used in the verification of the authentication tag appended to the ciphertext, but it is not encrypted or stored in the ciphertext.
 * @param string $key_name The name of the key to use for decryption. Optional.
 * @return HiddenString|false The decrypted value stored within a HiddenString instance, or false on error.
 */
function wporg_decrypt( string $value, string $context, string $key_name = '' ) {
	try {
		$value = \WordPressdotorg\MU_Plugins\Encryption\decrypt( $value, $context, $key_name );

		return new HiddenString( $value->getString(), false );
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Determine if a value is encrypted.
 *
 * @param HiddenString|string $value The value to check.
 * @return bool True if the value is encrypted, false otherwise.
 */
function wporg_is_encrypted( string $value ) : bool {
	return \WordPressdotorg\MU_Plugins\Encryption\is_encrypted( $value );
}
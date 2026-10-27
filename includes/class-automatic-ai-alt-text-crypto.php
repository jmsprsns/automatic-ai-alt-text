<?php
/**
 * Encrypts the AI API keys before they're stored in the database.
 *
 * The key is derived from the site's AUTH salt in wp-config.php, which never
 * lives in the database, so a leaked database backup or a SQL injection in
 * another plugin doesn't hand over a working API key. Uses libsodium
 * (bundled with PHP 7.2+, and polyfilled by WordPress since 5.2), with
 * OpenSSL as a fallback.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Secret storage helper.
 */
final class Automatic_AI_Alt_Text_Crypto {

	/**
	 * Prefix of values sealed with libsodium.
	 */
	const SODIUM = 'aaat-s1:';

	/**
	 * Prefix of values sealed with OpenSSL (AES-256-GCM).
	 */
	const OPENSSL = 'aaat-o1:';

	/**
	 * 32-byte key derived from the site's salts.
	 *
	 * @return string Raw bytes.
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'automatic-ai-alt-text|api-keys', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain Secret.
	 * @return string Sealed value, or '' when it can't be sealed (never stored in clear).
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( 24 );

				return self::SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage.
			}

			if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$sealed = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );

				if ( false !== $sealed ) {
					return self::OPENSSL . base64_encode( $iv . $tag . $sealed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage.
				}
			}
		} catch ( Exception $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Sealed value.
	 * @return string Secret, or '' when it can't be opened (for example the salts changed).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		try {
			if ( 0 === strpos( $stored, self::SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$raw = base64_decode( substr( $stored, strlen( self::SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored binary.

				if ( false === $raw || strlen( $raw ) < 40 ) {
					return '';
				}

				$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), self::key() );

				return false === $plain ? '' : $plain;
			}

			if ( 0 === strpos( $stored, self::OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
				$raw = base64_decode( substr( $stored, strlen( self::OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored binary.

				if ( false === $raw || strlen( $raw ) < 29 ) {
					return '';
				}

				$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

				return false === $plain ? '' : $plain;
			}
		} catch ( Exception $e ) {
			return '';
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- PHP 7+ only, which the plugin requires.
			return '';
		}

		return '';
	}
}

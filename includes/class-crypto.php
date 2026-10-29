<?php
/**
 * Keys, request signatures and payload encryption.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cryptographic helpers shared by the source and the target.
 *
 * A migration key carries a 256-bit secret. Two sub-keys are derived from it:
 * one authenticates the target's requests (HMAC-SHA256), the other encrypts and
 * authenticates the source's responses (XChaCha20-Poly1305). The libsodium
 * functions come from PHP's sodium extension or, failing that, the
 * sodium_compat polyfill bundled with WordPress.
 */
final class Crypto {

	/**
	 * Version tag of the key format and the signature scheme.
	 */
	public const VERSION = 'siirto1';

	/**
	 * Returns a new random secret.
	 *
	 * @return string 32 raw bytes.
	 */
	public static function new_secret(): string {
		return random_bytes( 32 );
	}

	/**
	 * Returns a new random identifier.
	 *
	 * @param int $bytes Entropy in bytes.
	 *
	 * @return string Lowercase hex.
	 */
	public static function new_id( int $bytes = 16 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Encodes the string an administrator copies from the source to the target.
	 *
	 * @param string $url    Source site home URL.
	 * @param string $id     Key ID.
	 * @param string $secret Raw secret.
	 *
	 * @return string
	 */
	public static function encode_key( string $url, string $id, string $secret ): string {
		return self::VERSION . '.' . self::b64url_encode(
			(string) wp_json_encode(
				array(
					'u' => $url,
					'i' => $id,
					's' => self::b64url_encode( $secret ),
				)
			)
		);
	}

	/**
	 * Parses a key string. Returns null if it is malformed.
	 *
	 * @param string $key Key string.
	 *
	 * @return array{url: string, id: string, secret: string}|null
	 */
	public static function decode_key( string $key ): ?array {
		$key = preg_replace( '/\s+/', '', $key );
		if ( ! is_string( $key ) || ! str_starts_with( $key, self::VERSION . '.' ) ) {
			return null;
		}

		$data = json_decode( self::b64url_decode( substr( $key, strlen( self::VERSION ) + 1 ) ), true );
		if ( ! is_array( $data ) || ! isset( $data['u'], $data['i'], $data['s'] ) ) {
			return null;
		}

		$secret = self::b64url_decode( (string) $data['s'] );
		$url    = (string) $data['u'];
		$id     = (string) $data['i'];

		$parts = wp_parse_url( $url );
		$valid = is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'], $parts['query'], $parts['fragment'] );

		if ( 32 !== strlen( $secret ) || ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! $valid ) {
			return null;
		}

		return array(
			'url'    => untrailingslashit( $url ),
			'id'     => $id,
			'secret' => $secret,
		);
	}

	/**
	 * Builds the canonical string a request signature covers.
	 *
	 * Every field that changes what the source does is included, so a captured
	 * request can't be replayed (seq), redirected to another action (route) or
	 * altered (body hash).
	 *
	 * @param string $route     REST route, e.g. `/jcore-siirto/v1/source/hello`.
	 * @param string $key_id    Key ID.
	 * @param string $client_id Random ID of the target job.
	 * @param int    $seq       Strictly increasing request counter.
	 * @param int    $time      Unix time of the request.
	 * @param string $body      Raw request body.
	 *
	 * @return string
	 */
	public static function canonical( string $route, string $key_id, string $client_id, int $seq, int $time, string $body ): string {
		return implode( "\n", array( self::VERSION, 'POST', $route, $key_id, $client_id, (string) $seq, (string) $time, hash( 'sha256', $body ) ) );
	}

	/**
	 * Signs a canonical request string.
	 *
	 * @param string $secret    Raw secret.
	 * @param string $canonical Output of canonical().
	 *
	 * @return string Hex HMAC.
	 */
	public static function sign( string $secret, string $canonical ): string {
		return hash_hmac( 'sha256', $canonical, self::derive( $secret, 'auth' ) );
	}

	/**
	 * Encrypts a response payload.
	 *
	 * @param string $secret    Raw secret.
	 * @param string $plaintext Payload.
	 * @param string $context   Associated data binding the payload to its request.
	 *
	 * @return string Nonce followed by ciphertext.
	 */
	public static function seal( string $secret, string $plaintext, string $context ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );

		return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $context, $nonce, self::derive( $secret, 'seal' ) );
	}

	/**
	 * Decrypts and authenticates a response payload.
	 *
	 * @param string $secret  Raw secret.
	 * @param string $sealed  Output of seal().
	 * @param string $context Associated data the payload must have been sealed with.
	 *
	 * @return string|null Null if the payload was tampered with or sealed for another request.
	 */
	public static function open( string $secret, string $sealed, string $context ): ?string {
		$nonce_length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( strlen( $sealed ) < $nonce_length + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				substr( $sealed, $nonce_length ),
				$context,
				substr( $sealed, 0, $nonce_length ),
				self::derive( $secret, 'seal' )
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Encrypts a secret for storage in the database.
	 *
	 * The key comes from the salts in wp-config.php, so a leaked database dump
	 * alone does not reveal live migration secrets.
	 *
	 * @param string $secret Raw secret.
	 *
	 * @return string Base64.
	 */
	public static function wrap( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored in a text column.
		return base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::storage_key() ) );
	}

	/**
	 * Reverses wrap().
	 *
	 * @param string $wrapped Output of wrap().
	 *
	 * @return string|null Null if the salts have changed or the value is corrupt.
	 */
	public static function unwrap( string $wrapped ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See wrap().
		$raw = base64_decode( $wrapped, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::storage_key()
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Derives a purpose-specific sub-key.
	 *
	 * @param string $secret  Raw secret.
	 * @param string $purpose Purpose label.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function derive( string $secret, string $purpose ): string {
		return sodium_crypto_generichash( self::VERSION . ':' . $purpose, $secret, 32 );
	}

	/**
	 * Returns the at-rest encryption key.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function storage_key(): string {
		return sodium_crypto_generichash( 'jcore-siirto-storage', hash( 'sha256', wp_salt( 'auth' ), true ), 32 );
	}

	/**
	 * URL-safe Base64 without padding.
	 *
	 * @param string $data Raw bytes.
	 *
	 * @return string
	 */
	public static function b64url_encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding for a copy-pasteable key.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Reverses b64url_encode().
	 *
	 * @param string $data Encoded string.
	 *
	 * @return string Empty on malformed input.
	 */
	public static function b64url_decode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See b64url_encode().
		$raw = base64_decode( strtr( $data, '-_', '+/' ), true );

		return false === $raw ? '' : $raw;
	}
}

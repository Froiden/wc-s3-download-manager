<?php
/**
 * Security helpers: encryption, rate limiting, key/bucket policy.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Cross-cutting security utilities.
 */
final class Security {

	/**
	 * Encrypt a string for option storage using WordPress salts.
	 *
	 * This is defense-in-depth against casual DB dumps / option exports.
	 * It is not a substitute for a dedicated secrets manager or KMS.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$key    = self::encryption_key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return 'enc:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value produced by encrypt().
	 */
	public static function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}
		// Legacy plaintext (pre-encryption) migration path.
		if ( ! str_starts_with( $payload, 'enc:' ) && ! str_starts_with( $payload, 'plain:' ) ) {
			return $payload;
		}
		if ( str_starts_with( $payload, 'plain:' ) ) {
			$decoded = base64_decode( substr( $payload, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $decoded ? '' : $decoded;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $payload, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 48 ) {
			return '';
		}
		$key    = self::encryption_key();
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		$calc   = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		if ( ! hash_equals( $mac, $calc ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive a 32-byte key from WordPress salts.
	 */
	private static function encryption_key(): string {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $const ) {
			if ( defined( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		if ( '' === $material ) {
			$material = 'wcs3dm-insecure-fallback-change-wp-salts';
		}
		return hash( 'sha256', $material . '|wcs3dm-credentials-v1', true );
	}

	/**
	 * Simple per-user/action rate limit using transients.
	 *
	 * @return bool True if allowed, false if rate-limited.
	 */
	public static function rate_limit( string $action, int $max = 30, int $window_seconds = 60 ): bool {
		$user_id = get_current_user_id();
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key     = 'wcs3dm_rl_' . md5( $action . '|' . $user_id . '|' . $ip );
		$bucket  = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = array( 'count' => 0, 'start' => time() );
		}
		if ( ( time() - (int) $bucket['start'] ) >= $window_seconds ) {
			$bucket = array( 'count' => 0, 'start' => time() );
		}
		++$bucket['count'];
		set_transient( $key, $bucket, $window_seconds );
		return (int) $bucket['count'] <= $max;
	}

	/**
	 * Whether an object key is allowed under the configured prefix (no escape).
	 */
	public static function key_under_prefix( string $key, string $prefix ): bool {
		$key    = ltrim( str_replace( '\\', '/', $key ), '/' );
		$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
		if ( '' === $prefix ) {
			return false;
		}
		if ( ! str_ends_with( $prefix, '/' ) ) {
			$prefix .= '/';
		}
		if ( str_contains( $key, '..' ) ) {
			return false;
		}
		return str_starts_with( $key, $prefix );
	}

	/**
	 * Whether a bucket name matches the configured bucket (case-sensitive S3 rules).
	 */
	public static function bucket_allowed( string $bucket, string $configured ): bool {
		$bucket     = trim( $bucket );
		$configured = trim( $configured );
		if ( '' === $bucket || '' === $configured ) {
			return false;
		}
		return hash_equals( $configured, $bucket );
	}

	/**
	 * Sanitize browse/list prefix so it cannot escape the configured root prefix.
	 */
	public static function constrain_list_prefix( string $requested, string $root_prefix ): string {
		$root = ltrim( str_replace( '\\', '/', $root_prefix ), '/' );
		if ( ! str_ends_with( $root, '/' ) ) {
			$root .= '/';
		}
		$requested = ltrim( str_replace( '\\', '/', $requested ), '/' );
		$requested = preg_replace( '#\.\./#', '', $requested ) ?? '';
		if ( '' === $requested || ! str_starts_with( $requested, $root ) ) {
			return $root;
		}
		return $requested;
	}

	/**
	 * Safe basename for Content-Disposition on signed URLs.
	 */
	public static function safe_filename( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = preg_replace( '/[^\w.\- ()\[\]]+/u', '_', $name ) ?? 'download.bin';
		return $name !== '' ? $name : 'download.bin';
	}
}

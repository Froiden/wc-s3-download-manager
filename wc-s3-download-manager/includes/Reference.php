<?php
/**
 * Plugin-owned download reference helpers.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Encodes and decodes download references.
 *
 * Stored value is a WooCommerce shortcode so product save validation accepts it.
 * Older wcs3dm:// values are still recognized.
 */
final class Reference {

	/**
	 * Build a WooCommerce-safe download reference.
	 */
	public static function build( string $bucket, string $key ): string {
		$bucket  = trim( $bucket );
		$key     = ltrim( str_replace( '\\', '/', $key ), '/' );
		$payload = $bucket . "\n" . $key;
		$token   = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return '[wcs3dm file="' . $token . '"]';
	}

	/**
	 * Whether a file string belongs to this plugin.
	 */
	public static function is_ours( string $file ): bool {
		$file = trim( $file );
		if ( 0 === stripos( $file, WCS3DM_SCHEME . '://' ) ) {
			return true;
		}
		return 1 === preg_match( '/^\[wcs3dm\s+file="[A-Za-z0-9\-_]+"\]$/', $file );
	}

	/**
	 * Parse bucket and key from a reference.
	 *
	 * @return array{bucket:string,key:string}|null
	 */
	public static function parse( string $file ): ?array {
		$file = trim( $file );
		if ( 0 === stripos( $file, WCS3DM_SCHEME . '://' ) ) {
			return self::parse_scheme( $file );
		}
		if ( preg_match( '/^\[wcs3dm\s+file="([A-Za-z0-9\-_]+)"\]$/', $file, $matches ) ) {
			$raw = strtr( $matches[1], '-_', '+/' );
			$pad = strlen( $raw ) % 4;
			if ( $pad ) {
				$raw .= str_repeat( '=', 4 - $pad );
			}
			$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! is_string( $decoded ) || ! str_contains( $decoded, "\n" ) ) {
				return null;
			}
			$parts = explode( "\n", $decoded, 2 );
			if ( '' === $parts[0] || '' === $parts[1] ) {
				return null;
			}
			return array(
				'bucket' => $parts[0],
				'key'    => $parts[1],
			);
		}
		return null;
	}

	/**
	 * Validate an S3 object key (no traversal, no scheme injection).
	 */
	public static function validate_key( string $key ): bool {
		$key = str_replace( '\\', '/', $key );
		if ( '' === $key || strlen( $key ) > 1024 ) {
			return false;
		}
		if ( str_contains( $key, '..' ) ) {
			return false;
		}
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $key ) ) {
			return false;
		}
		return (bool) preg_match( '#^[a-zA-Z0-9!_.*\'()/\-]+$#', $key ) || (bool) preg_match( '#^[\x20-\x7E]+$#', $key );
	}

	/**
	 * Filename a customer should see. Strips the internal product and checksum prefix.
	 */
	public static function customer_filename( string $key ): string {
		$name = basename( str_replace( '\\', '/', $key ) );
		$stripped = preg_replace(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-[0-9a-f]{12}-/i',
			'',
			$name
		);
		if ( is_string( $stripped ) && '' !== $stripped ) {
			$name = $stripped;
		}
		return Security::safe_filename( $name );
	}

	/**
	 * Friendly label for the admin activity list.
	 */
	public static function label( string $file ): string {
		$parsed = self::parse( $file );
		if ( $parsed ) {
			return basename( $parsed['key'] );
		}
		$name = basename( str_replace( '\\', '/', $file ) );
		return '' !== $name ? $name : $file;
	}

	/**
	 * Parse the legacy URI scheme.
	 *
	 * @return array{bucket:string,key:string}|null
	 */
	private static function parse_scheme( string $file ): ?array {
		$path  = substr( $file, strlen( WCS3DM_SCHEME . '://' ) );
		$parts = explode( '/', $path, 2 );
		if ( count( $parts ) < 2 || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}
		return array(
			'bucket' => rawurldecode( $parts[0] ),
			'key'    => self::decode_key( $parts[1] ),
		);
	}

	/**
	 * Decode key path.
	 */
	private static function decode_key( string $key ): string {
		$segments = explode( '/', $key );
		$segments = array_map( 'rawurldecode', $segments );
		return implode( '/', $segments );
	}
}

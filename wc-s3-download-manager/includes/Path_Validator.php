<?php
/**
 * Safe local filesystem path resolution.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Resolves WooCommerce download URLs/paths to local files with traversal protection.
 */
final class Path_Validator {

	/**
	 * Attempt to resolve a download file reference to a local absolute path.
	 *
	 * @return array{ok:bool,path?:string,message:string}
	 */
	public static function resolve_local( string $file ): array {
		$file = trim( $file );
		if ( '' === $file ) {
			return array( 'ok' => false, 'message' => 'Empty file reference.' );
		}
		if ( Reference::is_ours( $file ) ) {
			return array( 'ok' => false, 'message' => 'Already an S3-managed reference.' );
		}

		// Absolute filesystem path.
		if ( self::is_absolute_path( $file ) ) {
			return self::validate_existing( $file );
		}

		// Site URL → path.
		$upload = wp_upload_dir( null, false );
		$candidates = array();

		if ( preg_match( '#^https?://#i', $file ) ) {
			$home = home_url( '/' );
			$content = content_url( '/' );
			if ( 0 === stripos( $file, $home ) ) {
				$rel = substr( $file, strlen( $home ) );
				$candidates[] = ABSPATH . ltrim( (string) wp_parse_url( $rel, PHP_URL_PATH ), '/' );
			}
			if ( 0 === stripos( $file, $content ) ) {
				$rel = substr( $file, strlen( $content ) );
				$candidates[] = WP_CONTENT_DIR . '/' . ltrim( (string) wp_parse_url( $rel, PHP_URL_PATH ), '/' );
			}
			if ( ! empty( $upload['baseurl'] ) && 0 === stripos( $file, $upload['baseurl'] ) ) {
				$rel = substr( $file, strlen( $upload['baseurl'] ) );
				$candidates[] = trailingslashit( $upload['basedir'] ) . ltrim( (string) wp_parse_url( $rel, PHP_URL_PATH ), '/' );
			}
			// Reject external hosts for migration source.
			$host = wp_parse_url( $file, PHP_URL_HOST );
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $host && $site_host && strtolower( (string) $host ) !== strtolower( (string) $site_host ) ) {
				return array( 'ok' => false, 'message' => 'Remote URL is not on this site; migration requires a local file.' );
			}
		} else {
			// Relative to ABSPATH or uploads.
			$candidates[] = ABSPATH . ltrim( $file, '/' );
			if ( ! empty( $upload['basedir'] ) ) {
				$candidates[] = trailingslashit( $upload['basedir'] ) . ltrim( $file, '/' );
			}
			$candidates[] = WP_CONTENT_DIR . '/' . ltrim( $file, '/' );
		}

		foreach ( $candidates as $candidate ) {
			$result = self::validate_existing( $candidate );
			if ( $result['ok'] ) {
				return $result;
			}
		}

		return array( 'ok' => false, 'message' => 'Could not resolve a readable local file for this download reference.' );
	}

	/**
	 * Validate path exists, is a file, and sits under an approved directory.
	 *
	 * @return array{ok:bool,path?:string,message:string}
	 */
	public static function validate_existing( string $path ): array {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return array( 'ok' => false, 'message' => 'File does not exist or is not readable.' );
		}
		if ( ! self::is_under_approved( $real ) ) {
			return array( 'ok' => false, 'message' => 'Path is outside approved WordPress download/upload directories.' );
		}
		if ( self::is_protected_path( $real ) ) {
			return array( 'ok' => false, 'message' => 'Refusing to use WordPress core, plugin, theme, or backup paths.' );
		}
		return array( 'ok' => true, 'path' => $real, 'message' => 'OK' );
	}

	/**
	 * Whether path is under uploads or explicitly approved WC download dirs.
	 */
	public static function is_under_approved( string $real_path ): bool {
		$allowed = self::allowed_roots();
		foreach ( $allowed as $root ) {
			$root_real = realpath( $root );
			if ( false === $root_real ) {
				continue;
			}
			if ( 0 === strpos( $real_path, trailingslashit( $root_real ) ) || $real_path === $root_real ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Allowed filesystem roots.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_roots(): array {
		$upload = wp_upload_dir( null, false );
		$roots  = array();
		if ( ! empty( $upload['basedir'] ) ) {
			$roots[] = $upload['basedir'];
		}
		$roots[] = WP_CONTENT_DIR . '/uploads';
		$roots[] = WP_CONTENT_DIR . '/woocommerce_uploads';

		/**
		 * Filter approved local roots for migration/cleanup.
		 *
		 * @param array<int, string> $roots Roots.
		 */
		return apply_filters( 'wcs3dm_allowed_local_roots', $roots );
	}

	/**
	 * Block core/plugin/theme/backup paths even if somehow nested.
	 */
	public static function is_protected_path( string $real_path ): bool {
		$blocked_markers = array(
			'/wp-admin/',
			'/wp-includes/',
			'/wp-content/plugins/',
			'/wp-content/themes/',
			'/wp-content/mu-plugins/',
			'/wp-content/upgrade/',
			'/wp-content/debug.log',
		);
		$normalized = str_replace( '\\', '/', $real_path );
		foreach ( $blocked_markers as $marker ) {
			if ( str_contains( $normalized, $marker ) ) {
				return true;
			}
		}
		// Block WordPress root PHP files.
		if ( preg_match( '#/wp-[a-z-]+\.php$#', $normalized ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Absolute path detection.
	 */
	private static function is_absolute_path( string $path ): bool {
		if ( str_starts_with( $path, '/' ) ) {
			return true;
		}
		return (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $path );
	}

	/**
	 * SHA-256 of a local file (streaming).
	 */
	public static function sha256_file( string $path ): string|false {
		return hash_file( 'sha256', $path );
	}
}

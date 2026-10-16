<?php
/**
 * PHPUnit bootstrap (unit tests without full WordPress).
 *
 * @package WCS3DM
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'WCS3DM_SCHEME' ) ) {
	define( 'WCS3DM_SCHEME', 'wcs3dm' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wcs3dm-fake-wp/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// Minimal WordPress polyfills used by unit-tested helpers.
if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $value Value.
	 */
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str String.
	 */
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * @param string $filename Filename.
	 */
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) ?? $filename;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * @param string $title Title.
	 */
	function sanitize_title( string $title ): string {
		return strtolower( preg_replace( '/[^A-Za-z0-9-]+/', '-', $title ) ?? $title );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 */
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length Length.
	 * @param bool $special Special chars.
	 */
	function wp_generate_password( int $length = 12, bool $special = true ): string {
		unset( $special );
		return substr( bin2hex( random_bytes( max( 1, (int) ceil( $length / 2 ) ) ) ), 0, $length );
	}
}

$GLOBALS['wcs3dm_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wcs3dm_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param bool   $autoload Autoload.
	 */
	function update_option( string $key, mixed $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['wcs3dm_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $key Key.
	 */
	function delete_option( string $key ): bool {
		unset( $GLOBALS['wcs3dm_test_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 */
	function wp_json_encode( $data ): string|false {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param string               $url URL.
	 * @param array<string, mixed> $args Args.
	 * @return array{body:string,response:array{code:int}}
	 */
	function wp_remote_get( string $url, array $args = array() ): array {
		unset( $url, $args );
		return array(
			'body'     => (string) ( $GLOBALS['wcs3dm_expected_body'] ?? '' ),
			'response' => array( 'code' => 200 ),
		);
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array<string, mixed>|\WP_Error $response Response.
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		if ( ! is_array( $response ) ) {
			return 0;
		}
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array<string, mixed>|\WP_Error $response Response.
	 */
	function wp_remote_retrieve_body( $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}
}

$GLOBALS['wcs3dm_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( string $key ): mixed {
		$item = $GLOBALS['wcs3dm_test_transients'][ $key ] ?? null;
		if ( ! is_array( $item ) ) {
			return false;
		}
		if ( $item['expires'] > 0 && $item['expires'] < time() ) {
			unset( $GLOBALS['wcs3dm_test_transients'][ $key ] );
			return false;
		}
		return $item['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $key        Key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Expiration seconds.
	 */
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['wcs3dm_test_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + $expiration : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-wcs3dm' );
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
	define( 'LOGGED_IN_KEY', 'test-logged-in-key' );
	define( 'NONCE_KEY', 'test-nonce-key' );
	define( 'AUTH_SALT', 'test-auth-salt' );
	define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
}

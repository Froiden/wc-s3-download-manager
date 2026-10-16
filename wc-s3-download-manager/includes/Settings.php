<?php
/**
 * Plugin settings storage.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Manages non-secret settings and credential options.
 *
 * Credentials are stored in WordPress options. This is convenient but is not
 * equivalent to a dedicated secrets manager (AWS Secrets Manager, Vault, etc.).
 * Prefer IAM roles or temporary session credentials when the host supports them.
 */
final class Settings {

	public const OPTION_SETTINGS    = 'wcs3dm_settings';
	public const OPTION_CREDENTIALS = 'wcs3dm_credentials';

	/**
	 * Default non-secret settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'region'                 => 'us-east-1',
			'bucket'                 => '',
			'endpoint'               => '',
			'prefix'                 => 'woocommerce-downloads/',
			'url_expiry_minutes'     => 15,
			'storage_class'          => 'STANDARD',
			'server_side_encryption' => '',
			'kms_key_id'             => '',
			'path_style'             => false,
			'multipart_threshold'    => 104857600, // 100 MB.
			'multipart_part_size'    => 16777216, // 16 MB.
			'cloudfront_domain'       => '',
			'cloudfront_key_pair_id'  => '',
			'cleanup_enabled'        => false,
			'cleanup_grace_days'     => 7,
			'migration_batch_size'   => 5,
			'use_iam_role'           => false,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Persist non-secret settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function save( array $input ): array {
		$current = $this->all();
		$clean   = array(
			'region'                 => sanitize_text_field( (string) ( $input['region'] ?? $current['region'] ) ),
			'bucket'                 => sanitize_text_field( (string) ( $input['bucket'] ?? $current['bucket'] ) ),
			'endpoint'               => esc_url_raw( (string) ( $input['endpoint'] ?? $current['endpoint'] ) ),
			'prefix'                 => $this->sanitize_prefix( (string) ( $input['prefix'] ?? $current['prefix'] ) ),
			'url_expiry_minutes'     => max( 1, min( 60, absint( $input['url_expiry_minutes'] ?? $current['url_expiry_minutes'] ) ) ),
			'storage_class'          => sanitize_text_field( (string) ( $input['storage_class'] ?? $current['storage_class'] ) ),
			'server_side_encryption' => sanitize_text_field( (string) ( $input['server_side_encryption'] ?? $current['server_side_encryption'] ) ),
			'kms_key_id'             => sanitize_text_field( (string) ( $input['kms_key_id'] ?? $current['kms_key_id'] ) ),
			'path_style'             => ! empty( $input['path_style'] ),
			'multipart_threshold'    => max( 5242880, absint( $input['multipart_threshold'] ?? $current['multipart_threshold'] ) ),
			'multipart_part_size'    => max( 5242880, absint( $input['multipart_part_size'] ?? $current['multipart_part_size'] ) ),
			'cloudfront_domain'       => sanitize_text_field( (string) ( $input['cloudfront_domain'] ?? $current['cloudfront_domain'] ) ),
			'cloudfront_key_pair_id'  => sanitize_text_field( (string) ( $input['cloudfront_key_pair_id'] ?? $current['cloudfront_key_pair_id'] ) ),
			'cleanup_enabled'        => ! empty( $input['cleanup_enabled'] ),
			'cleanup_grace_days'     => max( 0, absint( $input['cleanup_grace_days'] ?? $current['cleanup_grace_days'] ) ),
			'migration_batch_size'   => max( 1, min( 50, absint( $input['migration_batch_size'] ?? $current['migration_batch_size'] ) ) ),
			'use_iam_role'           => ! empty( $input['use_iam_role'] ),
		);

		update_option( self::OPTION_SETTINGS, $clean, false );

		$creds_input = array();
		if ( isset( $input['access_key'] ) && '' !== (string) $input['access_key'] ) {
			$creds_input['access_key'] = sanitize_text_field( (string) $input['access_key'] );
		}
		if ( isset( $input['secret_key'] ) && '' !== (string) $input['secret_key'] && '********' !== (string) $input['secret_key'] ) {
			$creds_input['secret_key'] = (string) $input['secret_key'];
		}
		if ( array_key_exists( 'session_token', $input ) ) {
			$creds_input['session_token'] = sanitize_text_field( (string) $input['session_token'] );
		}
		if ( isset( $input['cloudfront_private_key'] ) && '' !== (string) $input['cloudfront_private_key'] && '********' !== (string) $input['cloudfront_private_key'] ) {
			$creds_input['cloudfront_private_key'] = (string) $input['cloudfront_private_key'];
		}

		if ( $creds_input ) {
			$this->save_credentials( $creds_input );
		}

		return $clean;
	}

	/**
	 * Get credentials (never send to browser). Values are decrypted in memory only.
	 *
	 * @return array{access_key?:string,secret_key?:string,session_token?:string,cloudfront_private_key?:string}
	 */
	public function credentials(): array {
		$stored = get_option( self::OPTION_CREDENTIALS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'access_key', 'secret_key', 'session_token', 'cloudfront_private_key' ) as $key ) {
			if ( ! empty( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
				$out[ $key ] = Security::decrypt( $stored[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Whether credentials appear configured (or IAM role mode).
	 */
	public function has_credentials(): bool {
		if ( $this->get( 'use_iam_role' ) ) {
			return true;
		}
		$c = $this->credentials();
		return ! empty( $c['access_key'] ) && ! empty( $c['secret_key'] );
	}

	/**
	 * Masked credential view for admin UI.
	 *
	 * @return array<string, string>
	 */
	public function masked_credentials(): array {
		$c = $this->credentials();
		return array(
			'access_key'             => isset( $c['access_key'] ) ? $this->mask( (string) $c['access_key'] ) : '',
			'secret_key_set'         => ! empty( $c['secret_key'] ) ? '1' : '0',
			'session_token_set'      => ! empty( $c['session_token'] ) ? '1' : '0',
			'cloudfront_private_key_set' => ! empty( $c['cloudfront_private_key'] ) ? '1' : '0',
		);
	}

	/**
	 * Merge and store credentials (encrypted at rest with WP salts).
	 *
	 * @param array<string, string> $input Partial credentials.
	 */
	public function save_credentials( array $input ): void {
		$current = $this->credentials();
		foreach ( array( 'access_key', 'secret_key', 'session_token', 'cloudfront_private_key' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = (string) $input[ $key ];
			}
		}
		$encrypted = array();
		foreach ( $current as $key => $value ) {
			$encrypted[ $key ] = Security::encrypt( (string) $value );
		}
		// autoload=false keeps secrets out of the alloptions cache blob.
		update_option( self::OPTION_CREDENTIALS, $encrypted, false );
	}

	/**
	 * Clear stored credentials.
	 */
	public function clear_credentials(): void {
		delete_option( self::OPTION_CREDENTIALS );
		update_option( self::OPTION_CREDENTIALS, array(), false );
	}

	/**
	 * Normalize object key prefix.
	 */
	private function sanitize_prefix( string $prefix ): string {
		$prefix = trim( str_replace( '\\', '/', $prefix ), '/' );
		$prefix = preg_replace( '#/+#', '/', $prefix ) ?? '';
		if ( '' === $prefix ) {
			return 'woocommerce-downloads/';
		}
		return $prefix . '/';
	}

	/**
	 * Mask a secret for display.
	 */
	private function mask( string $value ): string {
		$len = strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return substr( $value, 0, 2 ) . str_repeat( '*', max( 0, $len - 4 ) ) . substr( $value, -2 );
	}
}

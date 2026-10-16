<?php
/**
 * Operational logger with secret redaction.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Stores recent log entries in an option (capped).
 */
final class Logger {

	public const OPTION = 'wcs3dm_logs';
	public const MAX    = 500;

	/**
	 * Log levels.
	 */
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';
	public const SUCCESS = 'success';

	/**
	 * Write a log entry.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context (will be redacted).
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$entry = array(
			'time'    => gmdate( 'c' ),
			'level'   => $level,
			'message' => $this->redact( $message ),
			'context' => $this->redact_context( $context ),
		);

		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		array_unshift( $logs, $entry );
		$logs = array_slice( $logs, 0, self::MAX );
		update_option( self::OPTION, $logs, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WCS3DM][' . $level . '] ' . $entry['message'] );
		}
	}

	/**
	 * Convenience helpers.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function success( string $message, array $context = array() ): void {
		$this->log( self::SUCCESS, $message, $context );
	}

	/**
	 * Retrieve logs.
	 *
	 * @param int $limit Max entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit = 100 ): array {
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		return array_slice( $logs, 0, max( 1, $limit ) );
	}

	/**
	 * Clear logs.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Redact secrets from a string.
	 */
	public function redact( string $text ): string {
		$patterns = array(
			'/AKIA[0-9A-Z]{16}/'                                          => '[ACCESS_KEY_REDACTED]',
			'/(X-Amz-Security-Token=)[^&\s]+/i'                           => '$1[REDACTED]',
			'/(X-Amz-Credential=)[^&\s]+/i'                               => '$1[REDACTED]',
			'/(X-Amz-Signature=)[^&\s]+/i'                                => '$1[REDACTED]',
			'/(session[_-]?token["\']?\s*[:=]\s*["\']?)[^"\'\s]+/i'       => '$1[REDACTED]',
			'/(secret[_-]?access[_-]?key["\']?\s*[:=]\s*["\']?)[^"\'\s]+/i' => '$1[REDACTED]',
			'/(download_file=)[^&\s]+/i'                                  => '$1[REDACTED]',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}
		// Strip query strings from likely signed URLs.
		$text = (string) preg_replace( '#(https?://[^\s?]+\.amazonaws\.com/[^\s?]*)\?[^\s]+#i', '$1?[SIGNED_URL_REDACTED]', $text );
		return $text;
	}

	/**
	 * Redact context array.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private function redact_context( array $context ): array {
		$blocked = array( 'access_key', 'secret_key', 'session_token', 'password', 'Authorization', 'signed_url', 'presigned_url', 'token' );
		$out     = array();
		foreach ( $context as $key => $value ) {
			if ( in_array( (string) $key, $blocked, true ) ) {
				$out[ $key ] = '[REDACTED]';
				continue;
			}
			if ( is_string( $value ) ) {
				$out[ $key ] = $this->redact( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = '[complex]';
			}
		}
		return $out;
	}

	/**
	 * Map AWS error codes to actionable guidance.
	 */
	public static function diagnose_aws_error( string $code, string $message = '' ): string {
		$map = array(
			'AccessDenied'            => 'IAM policy is missing required permission for this bucket/prefix. Grant s3:GetObject/PutObject/ListBucket as needed.',
			'InvalidAccessKeyId'      => 'The access key ID is invalid or disabled. Create a new IAM user key (never use root).',
			'SignatureDoesNotMatch'   => 'Secret key does not match the access key, or the system clock is skewed. Re-enter the secret key and check server time.',
			'PermanentRedirect'       => 'Bucket is in a different region than configured. Update the region setting.',
			'AuthorizationHeaderMalformed' => 'Incorrect region for this bucket. Update AWS region to the bucket\'s actual region.',
			'NoSuchBucket'            => 'Bucket name does not exist or is misspelled.',
			'InvalidBucketName'       => 'Bucket name is invalid for S3 naming rules.',
			'ExpiredToken'            => 'Session token expired. Refresh temporary credentials.',
			'RequestTimeout'          => 'Network timeout during S3 request. Retry; check firewall and endpoint.',
			'EntityTooLarge'          => 'Object exceeds single PUT limit. Multipart upload should be used for large files.',
		);
		$hint = $map[ $code ] ?? 'See AWS error documentation for this code.';
		return trim( $code . ': ' . $hint . ( $message ? ' (' . $message . ')' : '' ) );
	}
}

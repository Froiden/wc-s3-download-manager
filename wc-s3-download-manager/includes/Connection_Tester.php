<?php
/**
 * Live AWS connection and permission tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Runs discrete S3 capability checks.
 */
final class Connection_Tester {

	public const PASSED     = 'passed';
	public const FAILED     = 'failed';
	public const NOT_TESTED = 'not_tested';

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * @var S3_Client
	 */
	private S3_Client $s3;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, Logger $logger, ?S3_Client $s3 = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->s3       = $s3 ?? new S3_Client( $settings, $logger );
	}

	/**
	 * Run full test suite.
	 *
	 * @return array{tests:array<int,array<string,string>>,summary:string,leftover_key?:string}
	 */
	public function run(): array {
		$tests = array();
		$key   = $this->settings->get( 'prefix' ) . 'health-check/' . wp_generate_password( 12, false ) . '.txt';
		$body  = 'wcs3dm-health-check-' . gmdate( 'c' );

		// 1. Authentication (STS-like: cheap signed API call via GetBucketLocation or List with MaxKeys=0).
		$auth = $this->test_auth();
		$tests[] = $auth;
		if ( self::PASSED !== $auth['status'] ) {
			return $this->finish( $tests, $key );
		}

		// 2. Bucket existence.
		$head = $this->s3->head_bucket();
		$tests[] = $this->row( 'bucket_exists', 'Bucket existence', $head['ok'] ? self::PASSED : self::FAILED, $head['message'] );
		if ( ! $head['ok'] ) {
			return $this->finish( $tests, $key );
		}

		// 3. Bucket region.
		$loc = $this->s3->get_bucket_location();
		if ( $loc['ok'] ) {
			$configured = (string) $this->settings->get( 'region' );
			$actual     = (string) ( $loc['region'] ?? '' );
			$match      = $this->regions_match( $configured, $actual );
			$tests[]    = $this->row(
				'bucket_region',
				'Bucket region',
				$match ? self::PASSED : self::FAILED,
				$match
					? $loc['message'] . ' (matches configured region).'
					: sprintf( 'Configured region "%s" does not match bucket region "%s". Update the region setting.', $configured, $actual )
			);
		} else {
			$tests[] = $this->row( 'bucket_region', 'Bucket region', self::FAILED, $loc['message'] );
		}

		// 4. List permission.
		$list = $this->s3->list_objects( (string) $this->settings->get( 'prefix' ), 1 );
		$tests[] = $this->row( 'list_objects', 'List objects', $list['ok'] ? self::PASSED : self::FAILED, $list['ok'] ? 'ListObjectsV2 succeeded.' : ( $list['message'] ?? 'Failed' ) );

		// 5. Upload test object.
		$put = $this->s3->put_string( $key, $body );
		$tests[] = $this->row( 'upload', 'Upload test object', $put['ok'] ? self::PASSED : self::FAILED, $put['ok'] ? 'Uploaded temporary object.' : $put['message'] );
		if ( ! $put['ok'] ) {
			$tests = $this->skip_remaining( $tests, array( 'read', 'delete', 'presign', 'presign_fetch' ) );
			return $this->finish( $tests, $key );
		}

		// 6. Read test object.
		$get = $this->s3->get_object( $key );
		$read_ok = $get['ok'] && ( ( $get['body'] ?? '' ) === $body );
		$tests[] = $this->row( 'read', 'Read test object', $read_ok ? self::PASSED : self::FAILED, $read_ok ? 'Object body matched.' : ( $get['message'] ?? 'Body mismatch.' ) );

		// 7. Delete (optional — report clearly if denied).
		$del = $this->s3->delete_object( $key );
		$leftover = null;
		if ( $del['ok'] ) {
			$tests[] = $this->row( 'delete', 'Delete test object', self::PASSED, 'Temporary object deleted.' );
		} else {
			$leftover = $key;
			$tests[]  = $this->row(
				'delete',
				'Delete test object',
				self::FAILED,
				$del['message'] . ' The test object may remain. Key: ' . $key
			);
		}

		// 8 & 9. Presign — re-upload if delete succeeded so we have an object, or use leftover.
		$presign_key = $key;
		if ( $del['ok'] ) {
			$re = $this->s3->put_string( $key, $body );
			if ( ! $re['ok'] ) {
				$tests[] = $this->row( 'presign', 'Generate presigned URL', self::NOT_TESTED, 'Could not re-create test object for presign.' );
				$tests[] = $this->row( 'presign_fetch', 'Fetch via presigned URL', self::NOT_TESTED, 'Skipped.' );
				return $this->finish( $tests, $key, $leftover );
			}
		}

		$presign = $this->s3->presign( $presign_key );
		$tests[] = $this->row( 'presign', 'Generate presigned URL', $presign['ok'] ? self::PASSED : self::FAILED, $presign['ok'] ? 'Presigned URL created (not displayed).' : $presign['message'] );

		if ( $presign['ok'] && ! empty( $presign['url'] ) ) {
			$fetch = $this->fetch_url( (string) $presign['url'], $body );
			$tests[] = $this->row( 'presign_fetch', 'Fetch via presigned URL', $fetch['ok'] ? self::PASSED : self::FAILED, $fetch['message'] );
		} else {
			$tests[] = $this->row( 'presign_fetch', 'Fetch via presigned URL', self::NOT_TESTED, 'Skipped because presign failed.' );
		}

		// Cleanup after presign tests.
		$cleanup = $this->s3->delete_object( $presign_key );
		if ( ! $cleanup['ok'] ) {
			$leftover = $presign_key;
		} else {
			$leftover = null;
		}

		return $this->finish( $tests, $key, $leftover );
	}

	/**
	 * Authentication probe without treating ListBuckets as sufficient.
	 *
	 * @return array{id:string,label:string,status:string,message:string}
	 */
	private function test_auth(): array {
		try {
			$this->s3->client();
		} catch ( \Throwable $e ) {
			return $this->row( 'auth', 'AWS authentication', self::FAILED, $e->getMessage() );
		}

		// Use GetCallerIdentity via STS if available; otherwise a signed ListObjects on the configured bucket.
		if ( class_exists( \Aws\Sts\StsClient::class ) && ! $this->settings->get( 'endpoint' ) ) {
			try {
				$config = array(
					'version' => 'latest',
					'region'  => (string) $this->settings->get( 'region', 'us-east-1' ),
				);
				if ( ! $this->settings->get( 'use_iam_role' ) ) {
					$creds = $this->settings->credentials();
					$credential = array(
						'key'    => (string) ( $creds['access_key'] ?? '' ),
						'secret' => (string) ( $creds['secret_key'] ?? '' ),
					);
					if ( ! empty( $creds['session_token'] ) ) {
						$credential['token'] = (string) $creds['session_token'];
					}
					$config['credentials'] = $credential;
				}
				$sts = new \Aws\Sts\StsClient( $config );
				$sts->getCallerIdentity();
				return $this->row( 'auth', 'AWS authentication', self::PASSED, 'Credentials accepted by STS GetCallerIdentity.' );
			} catch ( \Throwable $e ) {
				// Fall through to S3 probe for compatible endpoints.
				$this->logger->warning( 'STS probe failed; falling back to S3 auth probe', array( 'error' => $e->getMessage() ) );
			}
		}

		$probe = $this->s3->list_objects( (string) $this->settings->get( 'prefix' ), 1 );
		if ( $probe['ok'] ) {
			return $this->row( 'auth', 'AWS authentication', self::PASSED, 'Signed S3 request succeeded.' );
		}
		// Auth-specific failures.
		$msg = (string) ( $probe['message'] ?? 'Authentication failed.' );
		if ( str_contains( $msg, 'InvalidAccessKeyId' ) || str_contains( $msg, 'SignatureDoesNotMatch' ) || str_contains( $msg, 'ExpiredToken' ) ) {
			return $this->row( 'auth', 'AWS authentication', self::FAILED, $msg );
		}
		// Other errors (e.g. AccessDenied on list) still prove auth worked if not credential errors — but AccessDenied can also mean bad key on some providers.
		if ( str_contains( $msg, 'AccessDenied' ) ) {
			return $this->row( 'auth', 'AWS authentication', self::PASSED, 'Credentials were accepted but ListObjects is denied. Continuing bucket tests.' );
		}
		return $this->row( 'auth', 'AWS authentication', self::FAILED, $msg );
	}

	/**
	 * HTTP GET a URL and compare body (for presign verification).
	 *
	 * @return array{ok:bool,message:string}
	 */
	private function fetch_url( string $url, string $expected ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => 'HTTP request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'ok' => false, 'message' => 'Unexpected HTTP status ' . $code . ' when fetching presigned URL.' );
		}
		if ( $body !== $expected ) {
			return array( 'ok' => false, 'message' => 'Presigned URL returned unexpected body content.' );
		}
		return array( 'ok' => true, 'message' => 'Presigned URL returned the expected object body.' );
	}

	/**
	 * @param array<int, array<string, string>> $tests Tests.
	 * @return array{tests:array<int,array<string,string>>,summary:string,leftover_key?:string}
	 */
	private function finish( array $tests, string $key, ?string $leftover = null ): array {
		$failed = 0;
		$passed = 0;
		foreach ( $tests as $t ) {
			if ( self::FAILED === $t['status'] ) {
				++$failed;
			} elseif ( self::PASSED === $t['status'] ) {
				++$passed;
			}
		}
		$summary = sprintf( '%d passed, %d failed, %d not tested.', $passed, $failed, count( $tests ) - $passed - $failed );
		$this->logger->log( $failed ? Logger::WARNING : Logger::SUCCESS, 'Connection test: ' . $summary );

		$out = array(
			'tests'   => $tests,
			'summary' => $summary,
		);
		if ( $leftover ) {
			$out['leftover_key'] = $leftover;
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, string>> $tests Tests.
	 * @param array<int, string>                $ids   Remaining ids.
	 * @return array<int, array<string, string>>
	 */
	private function skip_remaining( array $tests, array $ids ): array {
		$labels = array(
			'read'          => 'Read test object',
			'delete'        => 'Delete test object',
			'presign'       => 'Generate presigned URL',
			'presign_fetch' => 'Fetch via presigned URL',
		);
		foreach ( $ids as $id ) {
			$tests[] = $this->row( $id, $labels[ $id ] ?? $id, self::NOT_TESTED, 'Skipped due to earlier failure.' );
		}
		return $tests;
	}

	/**
	 * @return array{id:string,label:string,status:string,message:string}
	 */
	private function row( string $id, string $label, string $status, string $message ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $this->logger->redact( $message ),
		);
	}

	/**
	 * Compare region strings (us-east-1 vs empty LocationConstraint).
	 */
	private function regions_match( string $configured, string $actual ): bool {
		$normalize = static function ( string $r ): string {
			$r = strtolower( trim( $r ) );
			return '' === $r ? 'us-east-1' : $r;
		};
		return $normalize( $configured ) === $normalize( $actual );
	}
}

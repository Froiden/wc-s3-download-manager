<?php
/**
 * AWS S3 client wrapper.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

/**
 * Thin wrapper around AWS SDK S3 client.
 */
class S3_Client {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Cached SDK client.
	 *
	 * @var S3Client|null
	 */
	protected ?S3Client $client = null;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Whether SDK is available.
	 */
	public static function sdk_available(): bool {
		return class_exists( S3Client::class );
	}

	/**
	 * Get or create the SDK client.
	 *
	 * @throws \RuntimeException When misconfigured.
	 */
	public function client(): S3Client {
		if ( $this->client instanceof S3Client ) {
			return $this->client;
		}

		if ( ! self::sdk_available() ) {
			throw new \RuntimeException( 'AWS SDK for PHP is not available. Run composer install.' );
		}

		$config = array(
			'version'     => 'latest',
			'region'      => (string) $this->settings->get( 'region', 'us-east-1' ),
			'http'        => array(
				'timeout'         => 60,
				'connect_timeout' => 10,
			),
		);

		$endpoint = (string) $this->settings->get( 'endpoint', '' );
		if ( '' !== $endpoint ) {
			$config['endpoint'] = $endpoint;
		}
		if ( $this->settings->get( 'path_style' ) ) {
			$config['use_path_style_endpoint'] = true;
		}

		if ( ! $this->settings->get( 'use_iam_role' ) ) {
			$creds = $this->settings->credentials();
			if ( empty( $creds['access_key'] ) || empty( $creds['secret_key'] ) ) {
				throw new \RuntimeException( 'AWS credentials are not configured.' );
			}
			$credential = array(
				'key'    => (string) $creds['access_key'],
				'secret' => (string) $creds['secret_key'],
			);
			if ( ! empty( $creds['session_token'] ) ) {
				$credential['token'] = (string) $creds['session_token'];
			}
			$config['credentials'] = $credential;
		}

		$this->client = new S3Client( $config );
		return $this->client;
	}

	/**
	 * Configured bucket name.
	 */
	public function bucket(): string {
		return (string) $this->settings->get( 'bucket', '' );
	}

	/**
	 * Configured prefix.
	 */
	public function prefix(): string {
		return (string) $this->settings->get( 'prefix', 'woocommerce-downloads/' );
	}

	/**
	 * Head bucket / existence check.
	 *
	 * @return array{ok:bool,message:string,region?:string}
	 */
	public function head_bucket( ?string $bucket = null ): array {
		$bucket = $bucket ?: $this->bucket();
		try {
			$this->client()->headBucket( array( 'Bucket' => $bucket ) );
			return array( 'ok' => true, 'message' => 'Bucket exists and is reachable.' );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Get bucket location.
	 *
	 * @return array{ok:bool,region?:string,message:string}
	 */
	public function get_bucket_location( ?string $bucket = null ): array {
		$bucket = $bucket ?: $this->bucket();
		try {
			$result = $this->client()->getBucketLocation( array( 'Bucket' => $bucket ) );
			$region = (string) ( $result['LocationConstraint'] ?? 'us-east-1' );
			if ( '' === $region ) {
				$region = 'us-east-1';
			}
			return array( 'ok' => true, 'region' => $region, 'message' => 'Bucket region: ' . $region );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * List objects under a prefix.
	 *
	 * @return array{ok:bool,objects?:array<int,array<string,mixed>>,prefixes?:array<int,string>,message?:string,next_token?:string|null}
	 */
	public function list_objects( string $prefix = '', int $max = 50, ?string $token = null, string $delimiter = '/' ): array {
		$bucket = $this->bucket();
		$args   = array(
			'Bucket'  => $bucket,
			'Prefix'  => $prefix,
			'MaxKeys' => max( 1, min( 1000, $max ) ),
		);
		if ( '' !== $delimiter ) {
			$args['Delimiter'] = $delimiter;
		}
		if ( $token ) {
			$args['ContinuationToken'] = $token;
		}

		try {
			$result   = $this->client()->listObjectsV2( $args );
			$objects  = array();
			$prefixes = array();
			foreach ( $result->get( 'Contents' ) ?? array() as $item ) {
				$key = (string) ( $item['Key'] ?? '' );
				if ( '' === $key || str_ends_with( $key, '/' ) ) {
					continue;
				}
				$objects[] = array(
					'key'           => $key,
					'size'          => (int) ( $item['Size'] ?? 0 ),
					'last_modified' => isset( $item['LastModified'] ) ? (string) $item['LastModified'] : '',
				);
			}
			foreach ( $result->get( 'CommonPrefixes' ) ?? array() as $cp ) {
				$prefixes[] = (string) ( $cp['Prefix'] ?? '' );
			}
			return array(
				'ok'         => true,
				'objects'    => $objects,
				'prefixes'   => $prefixes,
				'next_token' => ! empty( $result['IsTruncated'] ) ? (string) ( $result['NextContinuationToken'] ?? '' ) : null,
			);
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Put a small object from string body.
	 *
	 * @return array{ok:bool,message:string,key?:string}
	 */
	public function put_string( string $key, string $body, string $content_type = 'text/plain' ): array {
		if ( ! Reference::validate_key( $key ) || ! Security::key_under_prefix( $key, $this->prefix() ) ) {
			return array( 'ok' => false, 'message' => 'Invalid object key or outside configured prefix.' );
		}
		$args = array(
			'Bucket'       => $this->bucket(),
			'Key'          => $key,
			'Body'         => $body,
			'ContentType'  => $content_type,
			'StorageClass' => (string) $this->settings->get( 'storage_class', 'STANDARD' ),
		);
		$args = $this->apply_encryption( $args );

		try {
			$this->client()->putObject( $args );
			return array( 'ok' => true, 'message' => 'Uploaded.', 'key' => $key );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Upload a local file (auto multipart for large files).
	 *
	 * @return array{ok:bool,message:string,key?:string,bytes?:int}
	 */
	public function upload_file( string $local_path, string $key, ?callable $progress = null ): array {
		if ( ! is_readable( $local_path ) ) {
			return array( 'ok' => false, 'message' => 'Local file is not readable.' );
		}
		if ( ! Reference::validate_key( $key ) || ! Security::key_under_prefix( $key, $this->prefix() ) ) {
			return array( 'ok' => false, 'message' => 'Invalid object key or outside configured prefix.' );
		}

		$size      = (int) filesize( $local_path );
		$threshold = (int) $this->settings->get( 'multipart_threshold', 104857600 );
		$options   = array(
			'bucket' => $this->bucket(),
			'key'    => $key,
			'params' => $this->apply_encryption(
				array(
					'StorageClass' => (string) $this->settings->get( 'storage_class', 'STANDARD' ),
				)
			),
		);

		try {
			$source = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $source ) {
				return array( 'ok' => false, 'message' => 'Unable to open local file.' );
			}

			if ( $size >= $threshold ) {
				$options['part_size'] = (int) $this->settings->get( 'multipart_part_size', 16777216 );
				if ( $progress ) {
					$options['before_upload'] = static function () use ( $progress ): void {
						$progress();
					};
				}
				$uploader = new MultipartUploader( $this->client(), $source, $options );
			} else {
				$uploader = new ObjectUploader( $this->client(), $this->bucket(), $key, $source, 'private', array( 'params' => $options['params'] ) );
			}

			try {
				$uploader->upload();
			} catch ( MultipartUploadException $e ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->logger->error( 'Multipart upload failed', array( 'key' => $key, 'error' => $e->getMessage() ) );
				return array( 'ok' => false, 'message' => 'Multipart upload failed: ' . $e->getMessage() );
			}

			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array( 'ok' => true, 'message' => 'Uploaded.', 'key' => $key, 'bytes' => $size );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => $e->getMessage() );
		}
	}

	/**
	 * Head object metadata.
	 *
	 * @return array{ok:bool,size?:int,etag?:string,message:string}
	 */
	public function head_object( string $key, ?string $bucket = null ): array {
		try {
			$result = $this->client()->headObject(
				array(
					'Bucket' => $bucket ?: $this->bucket(),
					'Key'    => $key,
				)
			);
			return array(
				'ok'      => true,
				'size'    => (int) ( $result['ContentLength'] ?? 0 ),
				'etag'    => trim( (string) ( $result['ETag'] ?? '' ), '"' ),
				'message' => 'Object exists.',
			);
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Get object body (for small verification payloads).
	 *
	 * @return array{ok:bool,body?:string,message:string}
	 */
	public function get_object( string $key, ?string $bucket = null ): array {
		try {
			$result = $this->client()->getObject(
				array(
					'Bucket' => $bucket ?: $this->bucket(),
					'Key'    => $key,
				)
			);
			$body = (string) $result['Body'];
			return array( 'ok' => true, 'body' => $body, 'message' => 'Retrieved.' );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Delete object.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function delete_object( string $key, ?string $bucket = null ): array {
		try {
			$this->client()->deleteObject(
				array(
					'Bucket' => $bucket ?: $this->bucket(),
					'Key'    => $key,
				)
			);
			return array( 'ok' => true, 'message' => 'Deleted.' );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Create a presigned GET URL.
	 *
	 * @param string|null $download_filename Optional Content-Disposition filename.
	 * @return array{ok:bool,url?:string,message:string}
	 */
	public function presign( string $key, ?int $expiry_minutes = null, ?string $bucket = null, ?string $download_filename = null ): array {
		$minutes = $expiry_minutes ?? (int) $this->settings->get( 'url_expiry_minutes', 15 );
		$minutes = max( 1, min( 60, $minutes ) );

		if ( ! Reference::validate_key( $key ) ) {
			return array( 'ok' => false, 'message' => 'Invalid object key.' );
		}

		$bucket = $bucket ?: $this->bucket();
		if ( ! Security::bucket_allowed( $bucket, $this->bucket() ) ) {
			return array( 'ok' => false, 'message' => 'Bucket not allowed.' );
		}
		if ( ! Security::key_under_prefix( $key, $this->prefix() ) ) {
			return array( 'ok' => false, 'message' => 'Object key outside configured prefix.' );
		}

		try {
			$args = array(
				'Bucket' => $bucket,
				'Key'    => $key,
			);
			if ( $download_filename ) {
				$safe = Security::safe_filename( $download_filename );
				$args['ResponseContentDisposition'] = 'attachment; filename="' . $safe . '"';
			}
			$cmd     = $this->client()->getCommand( 'GetObject', $args );
			$request = $this->client()->createPresignedRequest( $cmd, '+' . $minutes . ' minutes' );
			$url     = (string) $request->getUri();
			return array( 'ok' => true, 'url' => $url, 'message' => 'Presigned URL generated.' );
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => $e->getMessage() );
		}
	}

	/**
	 * Stream object body to compute SHA-256 without loading entirely into memory for moderate sizes.
	 *
	 * @return array{ok:bool,checksum?:string,size?:int,message:string}
	 */
	public function checksum_object( string $key, ?string $bucket = null ): array {
		try {
			$result = $this->client()->getObject(
				array(
					'Bucket' => $bucket ?: $this->bucket(),
					'Key'    => $key,
				)
			);
			$ctx  = hash_init( 'sha256' );
			$body = $result['Body'];
			$size = 0;
			while ( ! $body->eof() ) {
				$chunk = $body->read( 1024 * 1024 );
				$size += strlen( $chunk );
				hash_update( $ctx, $chunk );
			}
			return array(
				'ok'       => true,
				'checksum' => hash_final( $ctx ),
				'size'     => $size,
				'message'  => 'Checksum computed.',
			);
		} catch ( AwsException $e ) {
			return array(
				'ok'      => false,
				'message' => Logger::diagnose_aws_error( (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?: $e->getMessage() ),
			);
		}
	}

	/**
	 * Apply SSE settings to PutObject args.
	 *
	 * @param array<string, mixed> $args Args.
	 * @return array<string, mixed>
	 */
	protected function apply_encryption( array $args ): array {
		$sse = (string) $this->settings->get( 'server_side_encryption', '' );
		if ( 'AES256' === $sse ) {
			$args['ServerSideEncryption'] = 'AES256';
		} elseif ( 'aws:kms' === $sse ) {
			$args['ServerSideEncryption'] = 'aws:kms';
			$kms = (string) $this->settings->get( 'kms_key_id', '' );
			if ( '' !== $kms ) {
				$args['SSEKMSKeyId'] = $kms;
			}
		}
		return $args;
	}
}

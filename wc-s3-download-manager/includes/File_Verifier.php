<?php
/**
 * Post-upload / migration verification.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Verifies S3 objects and download references.
 */
final class File_Verifier {

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
	 * Verify object exists with expected size; optionally compare checksum.
	 *
	 * Note: S3 ETag is not assumed to be an MD5 checksum (multipart uploads differ).
	 *
	 * @return array{ok:bool,checks:array<string,array{status:string,message:string}>,message:string}
	 */
	public function verify_object( string $key, int $expected_size, ?string $expected_sha256 = null, bool $full_checksum = true ): array {
		$checks = array();

		$head = $this->s3->head_object( $key );
		if ( ! $head['ok'] ) {
			$checks['exists'] = array( 'status' => 'failed', 'message' => $head['message'] );
			return array( 'ok' => false, 'checks' => $checks, 'message' => 'Object missing.' );
		}
		$checks['exists'] = array( 'status' => 'passed', 'message' => 'Object exists.' );

		$size = (int) ( $head['size'] ?? 0 );
		if ( $expected_size > 0 && $size !== $expected_size ) {
			$checks['size'] = array(
				'status'  => 'failed',
				'message' => sprintf( 'Size mismatch: expected %d, got %d.', $expected_size, $size ),
			);
			return array( 'ok' => false, 'checks' => $checks, 'message' => 'Size mismatch.' );
		}
		$checks['size'] = array( 'status' => 'passed', 'message' => 'Size matches (' . $size . ' bytes).' );

		if ( $expected_sha256 && $full_checksum ) {
			$sum = $this->s3->checksum_object( $key );
			if ( ! $sum['ok'] ) {
				$checks['checksum'] = array( 'status' => 'failed', 'message' => $sum['message'] );
				return array( 'ok' => false, 'checks' => $checks, 'message' => 'Checksum verification failed.' );
			}
			if ( ! hash_equals( strtolower( $expected_sha256 ), strtolower( (string) $sum['checksum'] ) ) ) {
				$checks['checksum'] = array( 'status' => 'failed', 'message' => 'SHA-256 mismatch.' );
				return array( 'ok' => false, 'checks' => $checks, 'message' => 'Checksum mismatch.' );
			}
			$checks['checksum'] = array( 'status' => 'passed', 'message' => 'SHA-256 matches.' );
		} elseif ( $expected_sha256 ) {
			$checks['checksum'] = array( 'status' => 'skipped', 'message' => 'Full checksum skipped for this run; size verified.' );
		}

		$presign = $this->s3->presign( $key );
		if ( ! $presign['ok'] ) {
			$checks['presign'] = array( 'status' => 'failed', 'message' => $presign['message'] );
			return array( 'ok' => false, 'checks' => $checks, 'message' => 'Could not presign.' );
		}
		$checks['presign'] = array( 'status' => 'passed', 'message' => 'Presigned URL generated.' );

		// The URL is signed for GET. A Range request checks it without downloading the whole object.
		if ( ! empty( $presign['url'] ) && function_exists( 'wp_remote_get' ) ) {
			$resp = wp_remote_get(
				$presign['url'],
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'headers'     => array( 'Range' => 'bytes=0-0' ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				$checks['presign_access'] = array( 'status' => 'warning', 'message' => $resp->get_error_message() );
			} else {
				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( ( $code >= 200 && $code < 300 ) || 206 === $code ) {
					$checks['presign_access'] = array( 'status' => 'passed', 'message' => 'Presigned URL is accessible.' );
				} else {
					$checks['presign_access'] = array( 'status' => 'warning', 'message' => 'Signed URL check returned HTTP ' . $code . '. Object itself was verified.' );
				}
			}
		}

		return array( 'ok' => true, 'checks' => $checks, 'message' => 'Verification passed.' );
	}

	/**
	 * Verify a product download reference points at our scheme and object exists.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function verify_product_reference( int $product_id, string $download_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'ok' => false, 'message' => 'Product not found.' );
		}
		$downloads = $product->get_downloads();
		if ( empty( $downloads[ $download_id ] ) ) {
			return array( 'ok' => false, 'message' => 'Download ID not found on product.' );
		}
		/** @var \WC_Product_Download $download */
		$download = $downloads[ $download_id ];
		$file     = $download->get_file();
		if ( ! Reference::is_ours( $file ) ) {
			return array( 'ok' => false, 'message' => 'Product reference is not an S3-managed URI.' );
		}
		$parsed = Reference::parse( $file );
		if ( null === $parsed ) {
			return array( 'ok' => false, 'message' => 'Invalid S3 reference on product.' );
		}
		$head = $this->s3->head_object( $parsed['key'], $parsed['bucket'] );
		if ( ! $head['ok'] ) {
			return array( 'ok' => false, 'message' => 'S3 object missing for saved reference.' );
		}
		return array( 'ok' => true, 'message' => 'Product reference verified.' );
	}
}

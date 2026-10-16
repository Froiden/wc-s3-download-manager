<?php
/**
 * Admin / AJAX file uploads to S3.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Handles multipart-aware uploads and key generation.
 */
final class File_Uploader {

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
	 * Upload a PHP uploaded file to S3.
	 *
	 * @param array<string, mixed> $file     $_FILES element.
	 * @param int                  $product_id Product ID for key namespacing.
	 * @return array{ok:bool,message:string,reference?:string,key?:string,size?:int}
	 */
	public function upload_from_files_array( array $file, int $product_id = 0 ): array {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return array( 'ok' => false, 'message' => 'No valid uploaded file.' );
		}
		if ( ! empty( $file['error'] ) ) {
			return array( 'ok' => false, 'message' => 'Upload error code: ' . (int) $file['error'] );
		}

		/**
		 * Max upload size in bytes (default 512 MB). Filterable for hosts with higher limits.
		 *
		 * @param int $max Max bytes.
		 */
		$max_bytes = (int) apply_filters( 'wcs3dm_max_upload_bytes', 512 * 1024 * 1024 );
		$size      = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( (string) $file['tmp_name'] );
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			return array( 'ok' => false, 'message' => 'File exceeds the maximum allowed upload size.' );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? 'file.bin' ) );
		// Block double extensions that often hide executables.
		if ( preg_match( '/\.(php|phtml|phar|exe|sh|bat|cmd|js)\./i', $name ) || preg_match( '/\.(php|phtml|phar)$/i', $name ) ) {
			return array( 'ok' => false, 'message' => 'This file type is not allowed for product downloads.' );
		}

		$key = $this->build_key( $name, $product_id );
		if ( ! Security::key_under_prefix( $key, $this->s3->prefix() ) ) {
			return array( 'ok' => false, 'message' => 'Generated object key failed prefix policy.' );
		}

		$result = $this->s3->upload_file( (string) $file['tmp_name'], $key );
		if ( ! $result['ok'] ) {
			$this->logger->error( 'S3 upload failed', array( 'key' => $key, 'error' => $result['message'] ) );
			return $result;
		}

		$bucket = $this->s3->bucket();
		$ref    = Reference::build( $bucket, $key );
		$this->logger->success( 'File uploaded to S3', array( 'key' => $key, 'bytes' => $result['bytes'] ?? 0 ) );

		return array(
			'ok'        => true,
			'message'   => 'Uploaded to S3.',
			'reference' => $ref,
			'key'       => $key,
			'size'      => (int) ( $result['bytes'] ?? 0 ),
			'name'      => $name,
		);
	}

	/**
	 * Upload an already-local path (migration / admin).
	 *
	 * @return array{ok:bool,message:string,reference?:string,key?:string,bytes?:int}
	 */
	public function upload_path( string $local_path, string $key ): array {
		$result = $this->s3->upload_file( $local_path, $key );
		if ( ! $result['ok'] ) {
			return $result;
		}
		return array(
			'ok'        => true,
			'message'   => $result['message'],
			'reference' => Reference::build( $this->s3->bucket(), $key ),
			'key'       => $key,
			'bytes'     => (int) ( $result['bytes'] ?? 0 ),
		);
	}

	/**
	 * Deterministic destination key for migrations.
	 */
	public function migration_key( int $product_id, string $download_id, string $filename, string $checksum ): string {
		$safe_name = sanitize_file_name( $filename );
		$short     = substr( $checksum, 0, 12 );
		$prefix    = $this->s3->prefix();
		return $prefix . 'products/' . $product_id . '/' . sanitize_title( $download_id ) . '-' . $short . '-' . $safe_name;
	}

	/**
	 * Build a unique upload key.
	 */
	public function build_key( string $filename, int $product_id = 0 ): string {
		$safe   = sanitize_file_name( $filename );
		$prefix = $this->s3->prefix();
		$uniq   = wp_generate_password( 8, false );
		if ( $product_id > 0 ) {
			return $prefix . 'products/' . $product_id . '/' . gmdate( 'Y/m' ) . '/' . $uniq . '-' . $safe;
		}
		return $prefix . 'uploads/' . gmdate( 'Y/m' ) . '/' . $uniq . '-' . $safe;
	}
}

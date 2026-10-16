<?php
/**
 * Environment diagnostics.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM;

/**
 * Checks PHP extensions, SDK, WooCommerce, and queue availability.
 */
final class Diagnostics {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run diagnostics.
	 *
	 * @return array<int, array{id:string,label:string,status:string,message:string}>
	 */
	public function run(): array {
		$rows = array();

		$rows[] = $this->check(
			'php_version',
			'PHP version',
			version_compare( PHP_VERSION, WCS3DM_MIN_PHP, '>=' ),
			'PHP ' . PHP_VERSION,
			'Requires PHP ' . WCS3DM_MIN_PHP . '+'
		);

		$rows[] = $this->check( 'ext_curl', 'cURL extension', extension_loaded( 'curl' ), 'Enabled', 'Install/enable PHP curl' );
		$rows[] = $this->check( 'ext_simplexml', 'SimpleXML extension', extension_loaded( 'simplexml' ), 'Enabled', 'Required by AWS SDK' );
		$rows[] = $this->check( 'ext_openssl', 'OpenSSL extension', extension_loaded( 'openssl' ), 'Enabled', 'Recommended for TLS and CloudFront signing' );
		$rows[] = $this->check( 'ext_mbstring', 'mbstring extension', extension_loaded( 'mbstring' ), 'Enabled', 'Recommended' );
		$rows[] = $this->check( 'ext_json', 'JSON extension', extension_loaded( 'json' ), 'Enabled', 'Required' );
		$rows[] = $this->check( 'aws_sdk', 'AWS SDK for PHP', S3_Client::sdk_available(), 'Available', 'Run composer install in the plugin directory' );

		$wc_ok = class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WCS3DM_MIN_WC, '>=' );
		$rows[] = $this->check(
			'woocommerce',
			'WooCommerce',
			$wc_ok,
			$wc_ok ? 'WooCommerce ' . WC_VERSION : 'Missing or outdated',
			'Requires WooCommerce ' . WCS3DM_MIN_WC . '+'
		);

		$as = function_exists( 'as_enqueue_async_action' );
		$rows[] = $this->check(
			'action_scheduler',
			'Action Scheduler',
			$as,
			$as ? 'Available via WooCommerce' : 'Not found — WP-Cron fallback will be used',
			'Background migrations work best with Action Scheduler'
		);

		$rows[] = $this->check(
			'credentials',
			'AWS credentials configured',
			$this->settings->has_credentials(),
			$this->settings->get( 'use_iam_role' ) ? 'IAM role mode enabled' : 'Access key configured',
			'Enter access keys or enable IAM role mode'
		);

		$bucket = (string) $this->settings->get( 'bucket' );
		$rows[] = $this->check( 'bucket', 'Bucket configured', '' !== $bucket, $bucket ?: 'Not set', 'Set a bucket name in settings' );

		$upload_max = $this->to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post_max   = $this->to_bytes( (string) ini_get( 'post_max_size' ) );
		$rows[]     = array(
			'id'      => 'upload_limits',
			'label'   => 'PHP upload limits',
			'status'  => 'info',
			'message' => sprintf( 'upload_max_filesize=%s, post_max_size=%s, max_execution_time=%s', ini_get( 'upload_max_filesize' ), ini_get( 'post_max_size' ), ini_get( 'max_execution_time' ) ),
		);

		$can_manage = current_user_can( 'manage_woocommerce' );
		$rows[]     = $this->check( 'cap_manage', 'Current user can manage WooCommerce', $can_manage, 'OK', 'Administrator/shop manager capability required' );

		unset( $upload_max, $post_max );

		return $rows;
	}

	/**
	 * @return array{id:string,label:string,status:string,message:string}
	 */
	private function check( string $id, string $label, bool $ok, string $ok_msg, string $fail_msg ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $ok ? 'passed' : 'failed',
			'message' => $ok ? $ok_msg : $fail_msg,
		);
	}

	/**
	 * Convert ini size to bytes.
	 */
	private function to_bytes( string $val ): int {
		$val = trim( $val );
		if ( '' === $val ) {
			return 0;
		}
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$num  = (int) $val;
		return match ( $last ) {
			'g' => $num * 1024 * 1024 * 1024,
			'm' => $num * 1024 * 1024,
			'k' => $num * 1024,
			default => (int) $val,
		};
	}
}

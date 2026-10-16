<?php
/**
 * Connection tester with mocked S3 client.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\Connection_Tester;
use WCS3DM\Logger;
use WCS3DM\S3_Client;
use WCS3DM\Settings;

/**
 * @covers \WCS3DM\Connection_Tester
 */
final class ConnectionTesterMockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wcs3dm_test_options'] = array();
		update_option(
			Settings::OPTION_SETTINGS,
			array_merge(
				Settings::defaults(),
				array(
					'region'       => 'us-east-1',
					'bucket'       => 'test-bucket',
					'prefix'       => 'woocommerce-downloads/',
					'use_iam_role' => true,
				)
			)
		);
	}

	public function test_auth_failure_short_circuits(): void {
		$s3 = $this->createMock( S3_Client::class );
		$s3->method( 'client' )->willThrowException( new \RuntimeException( 'AWS credentials are not configured.' ) );

		$tester = new Connection_Tester( new Settings(), new Logger(), $s3 );
		$result = $tester->run();

		$this->assertNotEmpty( $result['tests'] );
		$this->assertSame( Connection_Tester::FAILED, $result['tests'][0]['status'] );
		$this->assertSame( 'auth', $result['tests'][0]['id'] );
	}

	public function test_successful_suite_with_mocks(): void {
		$s3 = $this->createMock( S3_Client::class );
		$s3->method( 'client' )->willReturn( $this->createStub( \Aws\S3\S3Client::class ) );
		$s3->method( 'head_bucket' )->willReturn( array( 'ok' => true, 'message' => 'ok' ) );
		$s3->method( 'get_bucket_location' )->willReturn( array( 'ok' => true, 'region' => 'us-east-1', 'message' => 'Bucket region: us-east-1' ) );
		$s3->method( 'list_objects' )->willReturn( array( 'ok' => true, 'objects' => array(), 'prefixes' => array() ) );
		$s3->method( 'put_string' )->willReturnCallback(
			static function ( string $key, string $body ): array {
				$GLOBALS['wcs3dm_expected_body'] = $body;
				return array( 'ok' => true, 'message' => 'Uploaded.', 'key' => $key );
			}
		);
		$s3->method( 'get_object' )->willReturnCallback(
			static function () {
				return array( 'ok' => true, 'body' => $GLOBALS['wcs3dm_expected_body'] ?? '', 'message' => 'ok' );
			}
		);
		$s3->method( 'delete_object' )->willReturn( array( 'ok' => true, 'message' => 'Deleted.' ) );
		$s3->method( 'presign' )->willReturn( array( 'ok' => true, 'url' => 'https://example.test/signed', 'message' => 'ok' ) );

		$tester = new Connection_Tester( new Settings(), new Logger(), $s3 );
		$result = $tester->run();

		$failed = array_filter(
			$result['tests'],
			static fn( array $t ): bool => Connection_Tester::FAILED === $t['status']
		);
		$this->assertSame( array(), array_values( $failed ), (string) wp_json_encode( $result['tests'] ) );
	}

	public function test_upload_failure_marks_remaining_not_tested(): void {
		$s3 = $this->createMock( S3_Client::class );
		$s3->method( 'client' )->willReturn( $this->createStub( \Aws\S3\S3Client::class ) );
		$s3->method( 'head_bucket' )->willReturn( array( 'ok' => true, 'message' => 'ok' ) );
		$s3->method( 'get_bucket_location' )->willReturn( array( 'ok' => true, 'region' => 'us-east-1', 'message' => 'ok' ) );
		$s3->method( 'list_objects' )->willReturn( array( 'ok' => true, 'objects' => array() ) );
		$s3->method( 'put_string' )->willReturn( array( 'ok' => false, 'message' => 'AccessDenied: denied' ) );

		$tester = new Connection_Tester( new Settings(), new Logger(), $s3 );
		$result = $tester->run();
		$by_id  = array();
		foreach ( $result['tests'] as $t ) {
			$by_id[ $t['id'] ] = $t['status'];
		}
		$this->assertSame( Connection_Tester::FAILED, $by_id['upload'] );
		$this->assertSame( Connection_Tester::NOT_TESTED, $by_id['presign'] );
	}
}

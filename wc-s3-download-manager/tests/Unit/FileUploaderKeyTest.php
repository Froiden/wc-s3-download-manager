<?php
/**
 * File uploader key generation tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\File_Uploader;
use WCS3DM\Logger;
use WCS3DM\S3_Client;
use WCS3DM\Settings;

/**
 * @covers \WCS3DM\File_Uploader
 */
final class FileUploaderKeyTest extends TestCase {

	public function test_migration_key_is_deterministic(): void {
		update_option( Settings::OPTION_SETTINGS, array_merge( Settings::defaults(), array( 'prefix' => 'woocommerce-downloads/' ) ) );

		$s3 = $this->getMockBuilder( S3_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'prefix', 'bucket' ) )
			->getMock();
		$s3->method( 'prefix' )->willReturn( 'woocommerce-downloads/' );
		$s3->method( 'bucket' )->willReturn( 'bucket' );

		$uploader = new File_Uploader( new Settings(), new Logger(), $s3 );
		$key1     = $uploader->migration_key( 10, 'abc-def', 'Theme.zip', str_repeat( 'a', 64 ) );
		$key2     = $uploader->migration_key( 10, 'abc-def', 'Theme.zip', str_repeat( 'a', 64 ) );
		$this->assertSame( $key1, $key2 );
		$this->assertStringContainsString( 'woocommerce-downloads/products/10/', $key1 );
		$this->assertStringEndsWith( 'Theme.zip', $key1 );
	}
}

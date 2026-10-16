<?php
/**
 * Path validator tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\Path_Validator;
use WCS3DM\Reference;

/**
 * @covers \WCS3DM\Path_Validator
 */
final class PathValidatorTest extends TestCase {

	public function test_s3_reference_is_not_local(): void {
		$ref = Reference::build( 'bucket', 'key/file.zip' );
		$result = Path_Validator::resolve_local( $ref );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'S3-managed', $result['message'] );
	}

	public function test_protected_paths(): void {
		$this->assertTrue( Path_Validator::is_protected_path( '/var/www/wp-includes/functions.php' ) );
		$this->assertTrue( Path_Validator::is_protected_path( '/var/www/wp-content/plugins/hello.php' ) );
		$this->assertFalse( Path_Validator::is_protected_path( '/var/www/wp-content/uploads/woocommerce_uploads/file.zip' ) );
	}

	public function test_sha256_of_temp_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'wcs3dm' );
		$this->assertNotFalse( $path );
		file_put_contents( $path, 'hello-wcs3dm' );
		$hash = Path_Validator::sha256_file( $path );
		unlink( $path );
		$this->assertSame( hash( 'sha256', 'hello-wcs3dm' ), $hash );
	}
}

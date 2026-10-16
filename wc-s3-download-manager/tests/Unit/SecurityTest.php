<?php
/**
 * Security helper tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\Security;

/**
 * @covers \WCS3DM\Security
 */
final class SecurityTest extends TestCase {

	public function test_encrypt_decrypt_roundtrip(): void {
		$secret = 'AKIA_TEST_SECRET_VALUE_123';
		$enc    = Security::encrypt( $secret );
		$this->assertStringStartsWith( 'enc:', $enc );
		$this->assertNotSame( $secret, $enc );
		$this->assertSame( $secret, Security::decrypt( $enc ) );
	}

	public function test_decrypt_rejects_tampered_payload(): void {
		$enc  = Security::encrypt( 'hello' );
		$raw  = base64_decode( substr( $enc, 4 ), true );
		$this->assertNotFalse( $raw );
		$raw[20] = chr( ord( $raw[20] ) ^ 0xFF );
		$bad     = 'enc:' . base64_encode( $raw );
		$this->assertSame( '', Security::decrypt( $bad ) );
	}

	public function test_key_under_prefix(): void {
		$prefix = 'woocommerce-downloads/';
		$this->assertTrue( Security::key_under_prefix( 'woocommerce-downloads/a/b.zip', $prefix ) );
		$this->assertFalse( Security::key_under_prefix( 'other/a.zip', $prefix ) );
		$this->assertFalse( Security::key_under_prefix( 'woocommerce-downloads/../etc/passwd', $prefix ) );
	}

	public function test_constrain_list_prefix(): void {
		$root = 'woocommerce-downloads/';
		$this->assertSame( $root, Security::constrain_list_prefix( '../secret/', $root ) );
		$this->assertSame( $root, Security::constrain_list_prefix( 'uploads/', $root ) );
		$this->assertSame( 'woocommerce-downloads/products/', Security::constrain_list_prefix( 'woocommerce-downloads/products/', $root ) );
	}

	public function test_bucket_allowed(): void {
		$this->assertTrue( Security::bucket_allowed( 'my-bucket', 'my-bucket' ) );
		$this->assertFalse( Security::bucket_allowed( 'other', 'my-bucket' ) );
		$this->assertFalse( Security::bucket_allowed( '', 'my-bucket' ) );
	}

	public function test_rate_limit(): void {
		$GLOBALS['wcs3dm_test_transients'] = array();
		$this->assertTrue( Security::rate_limit( 'unit_test_rl', 2, 60 ) );
		$this->assertTrue( Security::rate_limit( 'unit_test_rl', 2, 60 ) );
		$this->assertFalse( Security::rate_limit( 'unit_test_rl', 2, 60 ) );
	}
}

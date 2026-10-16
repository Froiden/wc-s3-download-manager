<?php
/**
 * Reference unit tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\Reference;

/**
 * @covers \WCS3DM\Reference
 */
final class ReferenceTest extends TestCase {

	public function test_build_and_parse_roundtrip(): void {
		$ref = Reference::build( 'my-bucket', 'woocommerce-downloads/a/b.zip' );
		$this->assertTrue( Reference::is_ours( $ref ) );
		$parsed = Reference::parse( $ref );
		$this->assertNotNull( $parsed );
		$this->assertSame( 'my-bucket', $parsed['bucket'] );
		$this->assertSame( 'woocommerce-downloads/a/b.zip', $parsed['key'] );
	}

	public function test_rejects_non_plugin_urls(): void {
		$this->assertFalse( Reference::is_ours( 'https://example.com/file.zip' ) );
		$this->assertNull( Reference::parse( 'https://example.com/file.zip' ) );
	}

	public function test_validate_key_blocks_traversal(): void {
		$this->assertFalse( Reference::validate_key( '../etc/passwd' ) );
		$this->assertFalse( Reference::validate_key( 'http://evil' ) );
		$this->assertTrue( Reference::validate_key( 'woocommerce-downloads/file.zip' ) );
	}
}

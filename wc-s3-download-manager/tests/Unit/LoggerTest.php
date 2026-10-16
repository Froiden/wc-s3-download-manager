<?php
/**
 * Logger redaction tests.
 *
 * @package WCS3DM
 */

declare(strict_types=1);

namespace WCS3DM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCS3DM\Logger;

/**
 * @covers \WCS3DM\Logger
 */
final class LoggerTest extends TestCase {

	public function test_redacts_access_keys_and_signed_url_params(): void {
		$logger = new Logger();
		$text   = 'key AKIAIOSFODNN7EXAMPLE sig X-Amz-Signature=abcdef123 and https://bucket.s3.amazonaws.com/x?X-Amz-Security-Token=tok&X-Amz-Credential=cred';
		$out    = $logger->redact( $text );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $out );
		$this->assertStringContainsString( '[ACCESS_KEY_REDACTED]', $out );
		$this->assertStringContainsString( '[REDACTED]', $out );
	}

	public function test_diagnose_aws_errors(): void {
		$msg = Logger::diagnose_aws_error( 'AccessDenied' );
		$this->assertStringContainsString( 'IAM', $msg );
		$msg2 = Logger::diagnose_aws_error( 'SignatureDoesNotMatch' );
		$this->assertStringContainsString( 'Secret', $msg2 );
	}
}

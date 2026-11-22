<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** Source-level contract tests for the existing A2/A3 OTP-based Patient login. */
final class PatientPortalOtpLoginTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$path = dirname( __DIR__, 3 ) . '/assets/js/cpms-patient-portal.js';
		self::assertFileExists( $path );
		$this->source = (string) file_get_contents( $path );
		self::assertNotSame( '', $this->source );
	}

	public function testPatientLoginUsesOnlyTheExistingOtpRoutesAndLoginPurpose(): void {
		self::assertStringContainsString( "parsed.otp_request_path !== '/otp/request'", $this->source );
		self::assertStringContainsString( "parsed.otp_verify_path !== '/otp/verify'", $this->source );
		self::assertStringContainsString( "body: JSON.stringify({ mobile: mobile, purpose: 'login' })", $this->source );
		self::assertStringContainsString( 'body: JSON.stringify({ mobile: mobile, code: code })', $this->source );
		self::assertStringNotContainsString( 'clinic_id:', substr( $this->source, strpos( $this->source, 'function bindPatientOtpLogin()' ), 6500 ) );
	}

	public function testSessionIsReusedOnlyAfterSuccessfulOtpVerifyAndPersianDigitsAreNormalized(): void {
		self::assertStringContainsString( "credentials: 'same-origin'", substr( $this->source, strpos( $this->source, 'function bindPatientOtpLogin()' ), 6500 ) );
		self::assertStringContainsString( "payload.session_issued === true", $this->source );
		self::assertStringContainsString( 'window.location.reload();', $this->source );
		self::assertStringContainsString( '/[\\u06F0-\\u06F9]/g', $this->source );
		self::assertStringContainsString( '/[\\u0660-\\u0669]/g', $this->source );
	}
	public function testUndeliveredOtpCannotRevealTheCodeStep(): void {
		self::assertStringContainsString( 'result.body.data.sms_sent !== true', $this->source );
		self::assertStringContainsString( 'codeRequested = true;', $this->source );
	}

	public function testProfileOmitsNationalIdAndDeadEndOtpErrorCopyIsReplaced(): void {
		self::assertStringNotContainsString( 'کد معتبر نیست یا ورود برای این حساب مجاز نیست', $this->source );
		self::assertStringContainsString( 'function bindLinkRecord(config)', $this->source );
		self::assertStringContainsString( 'config.link_record_path', $this->source );

		$otpFrontendPath = dirname( __DIR__, 3 ) . '/src/Frontend/PatientOtpLogin.php';
		self::assertFileExists( $otpFrontendPath );
		$otpFrontend = (string) file_get_contents( $otpFrontendPath );
		self::assertStringNotContainsString( 'در صورت نبود حساب فعال، هنگام رزرو یا ثبت پرونده در مطب بررسی می‌شود', $otpFrontend );
	}

	public function testPatientLoginSlice1JsSpecPassesWithNode(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available.' );

		$spec = dirname( __DIR__, 2 ) . '/js/patient-login-slice1.spec.js';
		self::assertFileExists( $spec );

		$output = [];
		$code   = 1;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $code );
		self::assertSame( 0, $code, implode( "\n", $output ) );
	}
}

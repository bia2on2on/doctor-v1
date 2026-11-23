<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Application\Auth\PatientNidHmac;
use ClinicCore\Application\Auth\PatientRegistrationService;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStagePolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Integration regressions for the Q1 owner rule applied at the public
 * self-registration seam (`PatientRegistrationService::register()`, §4 Slice 2).
 *
 * Fixture license state per test (all ordinary signed documents):
 *   control      – valid term, expires time() + 30 days (Stage 1);
 *   t0Minus1     – signed T0 = 2026-01-05 12:00:00 UTC, gate clock frozen at T0 - 1s;
 *   atT0 / t0P1  – same T0, gate clock frozen at T0 and T0 + 1s;
 *   stage2       – signed T0 = time() - 10 days, gate clock frozen at T0 + 2 days;
 *   t1 / t1Plus  – signed T0 = time() - 40 days, gate clock frozen at LicenseStagePolicy::t1(T0) and + 3600s;
 *   linking      – same construction as t0P1 (SAME frozen clock, SAME gate instance);
 *   failClosed   – valid future document installed, gate double whose assert() throws.
 * T1 is always computed through the production LicenseStagePolicy::t1().
 */
final class PatientRegistrationLicenseGateTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_NEW      = '09127770111';
    private const MOBILE_EXISTING = '09127770112';
    private const NID_EXISTING    = '0010000003';
    private const LICENSE_BLOCK_MESSAGE = 'مجوز سامانه اجازه ایجاد بیمار جدید را نمی‌دهد؛ خدمات مجاز برای بیماران موجود ادامه دارد';

    private int $clinicId;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        PatientTwoProofTestHelper::reset();

        $this->clinicId = $this->migratedDefaultClinicId();
        App::settingsFactory()->forClinic( $this->clinicId )->set( 'public.listing_opt_in', true );
        PatientTwoProofTestHelper::acting_as( 0 );
    }

    protected function tearDown(): void {
        \ClinicCore\Application\Scope\ScopeContext::clear();
        remove_all_filters( 'cpms_license_public_key' );
        PatientTwoProofTestHelper::reset();
        App::resetScope();
        parent::tearDown();
    }

    public function testControlValidLicenseRegistersNewPatientViaRest(): void {
        $this->installSignedExpiration( time() + 30 * 86400 );
        $patientsBefore = $this->patientsCount();
        $nid            = PatientTwoProofTestHelper::next_valid_national_id();
        $step1          = $this->seedStep1( self::MOBILE_NEW );

        $resp     = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicId,
                'mobile'      => self::MOBILE_NEW,
                'given_name'  => 'تست',
                'family_name' => 'لایسنس',
                'national_id' => $nid,
                'birth_date'  => '1990-01-01',
            ],
            $step1['rest_nonce']
        );
        $data     = $resp->get_data();
        self::assertSame( 200, $resp->get_status() );
        self::assertIsArray( $data );
        self::assertArrayHasKey( 'data', $data );
        self::assertIsArray( $data['data'] );
        self::assertArrayHasKey( 'registered', $data['data'] );
        self::assertTrue( $data['data']['registered'] );
        self::assertArrayHasKey( 'patient_id', $data['data'] );
        self::assertGreaterThan( 0, (int) $data['data']['patient_id'] );
        self::assertSame( $patientsBefore + 1, $this->patientsCount() );
    }

    public function testOneSecondBeforeT0AllowsRegistration(): void {
        $t0      = strtotime( '2027-01-15 12:00:00 UTC' );
        $this->installSignedExpiration( $t0 );
        $service = $this->registrationServiceAtClock( $t0 - 1 );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $result = $service->register( $step1['user_id'], $this->clinicId, self::MOBILE_NEW, 'تست', 'یک ثانیه', $nid, '1990-01-01', '198.51.100.9', $t0 - 1, $step1['token'] );

        self::assertTrue( $result['registered'] );
        self::assertGreaterThan( 0, (int) $result['patient_id'] );
        $row = $this->patientRow( (int) $result['patient_id'] );
        self::assertIsArray( $row );
        self::assertSame( 'active', (string) $row['status'] );
    }

    public function testExactlyAtT0DeniesNewPatientRegistration(): void {
        $t0      = strtotime( '2026-01-05 12:00:00 UTC' );
        $this->installSignedExpiration( $t0 );
        $service = $this->registrationServiceAtClock( $t0 );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, $t0 );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    public function testOneSecondAfterT0DeniesNewPatientRegistration(): void {
        $t0      = strtotime( '2026-01-05 12:00:00 UTC' );
        $this->installSignedExpiration( $t0 );
        $service = $this->registrationServiceAtClock( $t0 + 1 );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, $t0 + 1 );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    public function testStage2AtT0Plus2DaysDeniesNewPatientRegistration(): void {
        $t0      = time() - 10 * 86400;
        $this->installSignedExpiration( $t0 );
        $frozen  = $t0 + 2 * 86400;
        $service = $this->registrationServiceAtClock( $frozen );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, $frozen );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    public function testStage3AtT1DeniesNewPatientRegistration(): void {
        $t0      = time() - 40 * 86400;
        $t1      = LicenseStagePolicy::t1( $t0 );
        $this->installSignedExpiration( $t0 );
        $service = $this->registrationServiceAtClock( $t1 );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, $t1 );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    public function testStage3AfterT1DeniesNewPatientRegistration(): void {
        $t0      = time() - 40 * 86400;
        $t1      = LicenseStagePolicy::t1( $t0 );
        $frozen  = $t1 + 3600;
        $this->installSignedExpiration( $t0 );
        $service = $this->registrationServiceAtClock( $frozen );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, $frozen );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    public function testMatchingExistingRecordLinksAtT0Plus1SecondUnderSameGateInstance(): void {
        $t0      = strtotime( '2026-01-05 12:00:00 UTC' );
        $this->installSignedExpiration( $t0 );
        $gate    = new SignedLicenseGate( App::licenseService(), null, static fn (): int => $t0 + 1 );
        $service = $this->registrationServiceWithGate( $gate );
        $before  = $this->patientsCount();

        // Same gate instance + same frozen clock: a NEW patient denial proves the gate is consulted and denies.
        $newStep1  = $this->seedStep1( self::MOBILE_NEW );
        $thrownNew = $this->attemptRegister( $service, $newStep1, self::MOBILE_NEW, PatientTwoProofTestHelper::next_valid_national_id(), $t0 + 1 );
        self::assertInstanceOf( BookingException::class, $thrownNew );
        self::assertSame( 'CLINIC_LICENSE_BLOCKED', $thrownNew->errorCode );

        // SAME gate instance + SAME frozen clock: matching pre-existing record links, no new row.
        $seedNid   = PatientNidHmac::normalize( self::NID_EXISTING );
        self::assertNotNull( $seedNid );
        $seededId  = $this->insertPatient( self::MOBILE_EXISTING, $seedNid );
        $linkStep1 = $this->seedStep1( self::MOBILE_EXISTING );
        $result    = $service->register( $linkStep1['user_id'], $this->clinicId, self::MOBILE_EXISTING, 'مریم', 'قدیمی', $seedNid, '1990-01-01', '198.51.100.10', $t0 + 1, $linkStep1['token'] );

        self::assertTrue( $result['registered'] );
        self::assertSame( $seededId, (int) $result['patient_id'] );
        self::assertSame( $before + 1, $this->patientsCount(), 'Linking must not create a new cpms_patients row (exactly the seeded row is present).' );
    }

    public function testFailClosedWhenGateCannotBeConsultedDeniesNewPatientRegistration(): void {
        $this->installSignedExpiration( time() + 30 * 86400 );
        $throwing = new class() implements LicenseGate {
            public function assert( string $operation, array $context = [] ): \ClinicCore\Domain\Licensing\LicenseDecision {
                throw new \RuntimeException( 'license gate unavailable' );
            }

            public function state(): string {
                return 'license:unreachable';
            }

            public function isReadOnly(): bool {
                return true;
            }
        };
        $service = $this->registrationServiceWithGate( $throwing );
        $step1   = $this->seedStep1( self::MOBILE_NEW );
        $before  = $this->patientsCount();
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();

        $this->assertLicenseBlockDenied( $service, $step1, $nid, time() );
        $this->assertRegistrationUntouched( $before, $step1['user_id'], self::MOBILE_NEW, $step1['token'] );
    }

    /**
     * @param array{user_id: int, token: string} $step1
     */
    private function assertLicenseBlockDenied( PatientRegistrationService $service, array $step1, string $nid, int $nowTs ): void {
        $thrown = $this->attemptRegister( $service, $step1, self::MOBILE_NEW, $nid, $nowTs );
        self::assertInstanceOf( BookingException::class, $thrown );
        self::assertSame( 'CLINIC_LICENSE_BLOCKED', $thrown->errorCode );
        self::assertSame( 503, $thrown->httpStatus );
        self::assertSame( self::LICENSE_BLOCK_MESSAGE, $thrown->getMessage() );
    }

    /**
     * @param array{user_id: int, token: string} $step1
     */
    private function attemptRegister( PatientRegistrationService $service, array $step1, string $mobile, string $nid, int $nowTs ): ?BookingException {
        try {
            $service->register( $step1['user_id'], $this->clinicId, $mobile, 'تست', 'لایسنس', $nid, '1990-01-01', '198.51.100.11', $nowTs, $step1['token'] );
        } catch ( BookingException $e ) {
            return $e;
        }

        return null;
    }

    private function assertRegistrationUntouched( int $patientsBefore, int $wpUserId, string $mobile, string $token ): void {
        self::assertSame( $patientsBefore, $this->patientsCount(), 'No cpms_patients row may be created on denial.' );
        $row = App::db()->fetchRow(
            'SELECT state FROM ' . App::db()->table( 'cpms_patient_logins' ) . ' WHERE kind = %s AND wp_user_id = %d LIMIT 1',
            [ PatientLoginRepository::KIND_ACCOUNT, $wpUserId ]
        );
        self::assertIsArray( $row );
        self::assertArrayHasKey( 'state', $row );
        self::assertSame( PatientLoginRepository::STATE_PENDING, (string) $row['state'], 'Pending account must not be activated on denial.' );
        self::assertTrue(
            ( new PatientSessionProofStore() )->has_valid_registration_mobile_proof( $wpUserId, $this->clinicId, $mobile, time(), $token ),
            'Registration proof must remain valid (unconsumed) on denial.'
        );
    }

    private function patientsCount(): int {
        return (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patientRow( int $patientId ): ?array {
        $row = App::db()->fetchRow( 'SELECT id, status, mobile FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1', [ $patientId ] );

        return is_array( $row ) ? $row : null;
    }

    private function insertPatient( string $mobile, string $nationalId ): int {
        $now = App::db()->nowUtcSql();
        App::db()->insert(
            'cpms_patients',
            [
                'clinic_id'   => $this->clinicId,
                'mrn'         => 'MR-LIC-' . uniqid(),
                'first_name'  => 'بیمار',
                'last_name'   => 'قدیمی',
                'mobile'      => $mobile,
                'national_id' => $nationalId,
                'birth_date'  => '1990-01-01',
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        return App::db()->wpdb_last_insert_id();
    }

    private function registrationServiceAtClock( int $now ): PatientRegistrationService {
        return $this->registrationServiceWithGate( new SignedLicenseGate( App::licenseService(), null, static fn (): int => $now ) );
    }

    private function registrationServiceWithGate( LicenseGate $gate ): PatientRegistrationService {
        $db = App::db();

        return new PatientRegistrationService(
            $db,
            new PatientLoginRepository( $db ),
            new PatientSessionProofStore(),
            App::patient_session_scope(),
            App::rate(),
            new PatientNidHmac(),
            new PatientIdentityRepository( $db ),
            App::patient_identity_service(),
            App::patient_identity_activator(),
            App::audit(),
            App::op(),
            $gate,
            null,
            App::patient_access()
        );
    }

    /**
     * @return array{user_id: int, token: string, rest_nonce: string}
     */
    private function seedStep1( string $mobile ): array {
        $code = (string) random_int( 100000, 999999 );
        $this->seedOtp( $this->clinicId, $mobile, $code, OtpService::PURPOSE_VERIFY_MOBILE );

        return $this->performStep1VerifyRest( $this->clinicId, $mobile, $code );
    }

    /**
     * @return array{user_id: int, token: string, rest_nonce: string}
     */
    private function performStep1VerifyRest( int $clinicId, string $mobile, string $code ): array {
        PatientTwoProofTestHelper::acting_as( 0 );
        $capturedToken  = '';
        $capturedCookie = '';
        $tokenHook      = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$capturedToken, &$capturedCookie ): void {
            unset( $expire, $expiration, $userId, $scheme );
            $capturedCookie = $cookie;
            $capturedToken  = $token;
        };
        add_action( 'set_logged_in_cookie', $tokenHook, 10, 6 );
        try {
            $resp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/verify',
                [
                    'mobile'    => $mobile,
                    'code'      => $code,
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                    'clinic_id' => $clinicId,
                ]
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $tokenHook, 10 );
        }

        $respData = $resp->get_data();
        self::assertIsArray( $respData );
        self::assertSame( 200, $resp->get_status() );
        self::assertArrayHasKey( 'data', $respData );
        self::assertIsArray( $respData['data'] );
        $payload = $respData['data'];
        self::assertArrayHasKey( 'user_id', $payload );
        self::assertArrayHasKey( 'session_issued', $payload );
        self::assertArrayHasKey( 'rest_nonce', $payload );
        self::assertTrue( (bool) $payload['session_issued'] );
        $userId   = (int) $payload['user_id'];
        self::assertGreaterThan( 0, $userId );
        $token    = $capturedToken;
        self::assertNotSame( '', $token );
        $this->activateStep1Session( $userId, $token, $capturedCookie );

        return [
            'user_id'    => $userId,
            'token'      => $token,
            'rest_nonce' => (string) $payload['rest_nonce'],
        ];
    }

    private function activateStep1Session( int $userId, string $token, string $cookie ): void {
        if ( '' !== $cookie ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
        }
        wp_set_current_user( $userId );
        App::patient_session_scope()->set_in_process_session_token( $userId, $token );
    }

    private function seedOtp( int $clinicId, string $mobile, string $code, string $purpose ): void {
        $pepper = defined( 'CPMS_PEPPER' ) && '' !== (string) CPMS_PEPPER
            ? (string) CPMS_PEPPER
            : (string) get_option( 'cpms_otp_pepper', '' );
        if ( '' === $pepper ) {
            $pepper = bin2hex( random_bytes( 32 ) );
            update_option( 'cpms_otp_pepper', $pepper, 'no' );
        }

        $inserted = App::db()->insert(
            'cpms_otp_tokens',
            [
                'clinic_id'  => $clinicId,
                'mobile'     => $mobile,
                'code_hash'  => OtpPolicy::hashCode( $code, $pepper ),
                'purpose'    => $purpose,
                'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ) . '.000',
                'attempts'   => 0,
                'created_at' => App::db()->nowUtcSql(),
            ]
        );
        self::assertTrue( (bool) $inserted );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatchRest( string $method, string $route, array $body ): WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        $res = rest_do_request( $req );
        self::assertInstanceOf( WP_REST_Response::class, $res );

        return $res;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatchRestWithNonce( string $method, string $route, array $body, string $nonce ): WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_header( 'X-WP-Nonce', $nonce );
        if ( [] !== $body ) {
            $req->set_body( (string) wp_json_encode( $body ) );
        }
        $res = rest_do_request( $req );
        self::assertInstanceOf( WP_REST_Response::class, $res );

        return $res;
    }

    private function migratedDefaultClinicId(): int {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'default'
            )
        );
        self::assertGreaterThan( 0, $id, 'The migrated default Clinic must exist.' );

        return $id;
    }
}

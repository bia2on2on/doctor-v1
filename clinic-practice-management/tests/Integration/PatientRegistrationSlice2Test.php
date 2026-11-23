<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientCredentialGuard;
use ClinicCore\Application\Auth\PatientRegistrationService;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Frontend\PatientOtpLogin;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * Integration tests for PATIENT-REGISTRATION-SLICE-2:
 *  - Public self-registration via `POST /clinic/v1/patient/register`
 *  - Atomic creation vs existing matching-NID record link vs OQ-D9 NULL-NID refusal
 *  - Uniform indistinguishable `403 CLINIC_PATIENT_PROOF_FAILED` across all failure branches
 *  - Server-trusted clinic enforcement (public opt-in directory / sole active clinic)
 *  - §4.4 N2 initial-credential guard (password / reset / app-password blocked)
 *  - Booking pending-appointment cap = 2 across bound patients + reschedule exclusion
 *  - Zero raw/masked NID in audit logs, rate-limit keys, HTTP responses, or usermeta
 */
final class PatientRegistrationSlice2Test extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_NEW      = '09125550101';
    private const MOBILE_EXISTING = '09125550102';
    private const MOBILE_NULL_NID = '09125550103';
    private const MOBILE_ARCHIVED = '09125550104';
    private const MOBILE_OTHER    = '09125550105';
    private const MOBILE_CAP      = '09125550106';

    private const NID_NEW      = '0499370899';
    private const NID_EXISTING = '0010000003';
    private const NID_OTHER    = '0010000001';
    private const NID_CAP      = '0084575948';

    private int $orgId;
    private int $clinicListed;
    private int $clinicPrivate;
    private int $locationListed;
    private int $clinicianListed;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        PatientTwoProofTestHelper::reset();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->orgId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ 1 ]
        );
        $this->clinicListed = 1;
        App::settingsFactory()->forClinic( $this->clinicListed )->set( 'public.listing_opt_in', true );

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'Private Unlisted Clinic S2',
                'slug'            => 'private-s2-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicPrivate = $db->wpdb_last_insert_id();
        App::settingsFactory()->forClinic( $this->clinicPrivate )->set( 'public.listing_opt_in', false );

        $db->insert(
            'cpms_locations',
            [
                'clinic_id'  => $this->clinicListed,
                'name'       => 'Main Loc S2',
                'timezone'   => 'UTC',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->locationListed = $db->wpdb_last_insert_id();

        $docId = (int) wp_create_user( 'doc_s2_' . uniqid(), 'doc-pass-1234', 'doc_s2_' . uniqid() . '@test.local' );
        $doc   = get_userdata( $docId );
        self::assertNotFalse( $doc );
        $doc->set_role( RolesAndCapabilities::ROLE_DOCTOR );

        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicListed,
                'wp_user_id' => $docId,
                'full_name'  => 'دکتر تست ثبت‌نام',
                'specialty'  => 'عمومی',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->clinicianListed = $db->wpdb_last_insert_id();

        $this->installSignedExpiration( time() + 30 * 86400 );
        PatientTwoProofTestHelper::acting_as( 0 );
    }

    protected function tearDown(): void {
        ScopeContext::clear();
        remove_all_filters( 'cpms_license_public_key' );
        PatientTwoProofTestHelper::reset();
        App::resetScope();
        parent::tearDown();
    }

    public function testNewPatientRegistrationCreatesFullChainIssuesTwoProofSessionAndExcludesPii(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '123456', OtpService::PURPOSE_VERIFY_MOBILE );

        $auditBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );

        $capturedToken = '';
        $tokenHook     = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$capturedToken ): void {
            $capturedToken = $token;
        };
        add_action( 'set_logged_in_cookie', $tokenHook, 10, 6 );
        try {
            $resp = $this->dispatchRest(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => $this->clinicListed,
                    'mobile'      => '۰۹۱۲۵۵۵۰۱۰۱',
                    'code'        => '۱۲۳۴۵۶',
                    'given_name'  => 'سارا',
                    'family_name' => 'محمدی',
                    'national_id' => '۰۴۹۹۳۷۰۸۹۹',
                    'birth_date'  => '۱۹۹۳-۰۷-۲۱',
                ]
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $tokenHook, 10 );
        }

        self::assertSame( 200, $resp->get_status() );
        $body = $resp->get_data();
        self::assertIsArray( $body );
        self::assertArrayHasKey( 'data', $body );
        self::assertIsArray( $body['data'] );
        foreach ( [ 'registered', 'session_issued', 'user_id', 'patient_id', 'binding_id', 'clinic_id' ] as $expectedKey ) {
            self::assertArrayHasKey( $expectedKey, $body['data'] );
        }
        self::assertTrue( $body['data']['registered'] );
        self::assertTrue( $body['data']['session_issued'] );
        $userId    = (int) $body['data']['user_id'];
        $patientId = (int) $body['data']['patient_id'];
        $bindingId = (int) $body['data']['binding_id'];
        self::assertGreaterThan( 0, $userId );
        self::assertGreaterThan( 0, $patientId );
        self::assertGreaterThan( 0, $bindingId );
        self::assertSame( $this->clinicListed, (int) $body['data']['clinic_id'] );

        // Zero PII / NID / MRN in REST response.
        $encoded = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( self::NID_NEW, $encoded );
        self::assertStringNotContainsString( self::MOBILE_NEW, $encoded );
        self::assertStringNotContainsString( 'mrn', $encoded );
        self::assertArrayNotHasKey( 'national_id', $body['data'] );
        self::assertArrayNotHasKey( 'mrn', $body['data'] );
        self::assertArrayNotHasKey( 'birth_date', $body['data'] );

        // Patient row created with birth_date + identity_id.
        $patient = App::db()->fetchRow( 'SELECT * FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $patientId ] );
        self::assertIsArray( $patient );
        self::assertSame( 'سارا', (string) $patient['first_name'] );
        self::assertSame( 'محمدی', (string) $patient['last_name'] );
        self::assertSame( self::MOBILE_NEW, (string) $patient['mobile'] );
        self::assertSame( self::NID_NEW, (string) $patient['national_id'] );
        self::assertSame( '1993-07-21', (string) $patient['birth_date'] );
        self::assertGreaterThan( 0, (int) ( $patient['identity_id'] ?? 0 ) );

        // Account + binding active; WP user is pure patient; session holds both proofs + clinic scope.
        $logins  = new PatientLoginRepository( App::db() );
        $account = $logins->find_account_by_user_id( $userId );
        self::assertIsArray( $account );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $account['state'] );
        self::assertSame( 'سارا', (string) $account['given_name'] );
        self::assertSame( 'محمدی', (string) $account['family_name'] );

        $binding = $logins->find_binding( $userId, $this->clinicListed, $patientId, true );
        self::assertIsArray( $binding );
        self::assertSame( $bindingId, (int) $binding['id'] );

        self::assertTrue( App::patient_access()->is_pure_patient_actor( $userId ) );
        $tokens = WP_Session_Tokens::get_instance( $userId )->get_all();
        self::assertCount( 1, $tokens );
        self::assertNotSame( '', $capturedToken );
        $proofStore = new PatientSessionProofStore();
        self::assertTrue( $proofStore->has_valid_mobile_proof( $userId, self::MOBILE_NEW, time(), $capturedToken ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $userId, $patientId, $this->clinicListed, time(), $capturedToken ) );
        self::assertSame( $this->clinicListed, App::patient_session_scope()->resolve_clinic_id( $userId ) );

        // Audit wrote PATIENT_REGISTERED without raw or masked NID, and rate limits hashed NID.
        $audits = App::db()->fetchAll(
            'SELECT action, after_json, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' ORDER BY id DESC LIMIT 5'
        );
        self::assertGreaterThan( $auditBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) ) );
        $foundRegAudit = false;
        foreach ( $audits as $row ) {
            $blob = (string) ( $row['after_json'] ?? '' ) . (string) ( $row['meta_json'] ?? '' );
            self::assertStringNotContainsString( self::NID_NEW, $blob );
            self::assertStringNotContainsString( substr( self::NID_NEW, -4 ), $blob );
            if ( 'PATIENT_REGISTERED' === (string) ( $row['action'] ?? '' ) ) {
                $foundRegAudit = true;
            }
        }
        self::assertTrue( $foundRegAudit );

        $rateKeys = App::db()->fetchAll( 'SELECT window_key FROM ' . App::db()->table( 'cpms_rate_limits' ) );
        self::assertNotEmpty( $rateKeys, 'Registration must record non-empty rate-limit keys to inspect.' );
        foreach ( $rateKeys as $rk ) {
            self::assertArrayHasKey( 'window_key', $rk );
            self::assertNotSame( '', (string) $rk['window_key'] );
            self::assertStringNotContainsString( self::NID_NEW, (string) $rk['window_key'] );
            self::assertStringNotContainsString( self::MOBILE_NEW, (string) $rk['window_key'] );
        }

        // §4.4 N2 guard: password sign-in, password reset, and app passwords are all blocked.
        $userObj = get_userdata( $userId );
        self::assertInstanceOf( \WP_User::class, $userObj );
        $authWrong = wp_authenticate( $userObj->user_login, 'wrong-password' );
        $authEmail = wp_authenticate( $userObj->user_email, 'wrong-password' );
        self::assertInstanceOf( WP_Error::class, $authWrong );
        self::assertInstanceOf( WP_Error::class, $authEmail );
        self::assertSame( PatientCredentialGuard::ERROR_CODE, $authWrong->get_error_code() );
        self::assertSame( PatientCredentialGuard::ERROR_CODE, $authEmail->get_error_code() );
        self::assertFalse( (bool) apply_filters( 'allow_password_reset', true, $userId ) );
        self::assertFalse( (bool) apply_filters( 'wp_is_application_passwords_available_for_user', true, $userObj ) );
    }

    public function testExistingRecordWithMatchingNidLinksAndPreservesRecordAndBackfillsNullBirthDate(): void {
        $existingId = $this->insertPatient( $this->clinicListed, self::MOBILE_EXISTING, 'علی', 'کاظمی', self::NID_EXISTING, null );
        $this->seedOtp( $this->clinicListed, self::MOBILE_EXISTING, '223344', OtpService::PURPOSE_VERIFY_MOBILE );

        $resp = $this->dispatchRest(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => self::MOBILE_EXISTING,
                'code'        => '223344',
                'given_name'  => 'DifferentGiven',
                'family_name' => 'DifferentFamily',
                'national_id' => self::NID_EXISTING,
                'birth_date'  => '1990-02-10',
            ]
        );

        self::assertSame( 200, $resp->get_status() );
        $linkData = $resp->get_data();
        self::assertIsArray( $linkData );
        self::assertArrayHasKey( 'data', $linkData );
        self::assertIsArray( $linkData['data'] );
        self::assertArrayHasKey( 'patient_id', $linkData['data'] );
        self::assertSame( $existingId, (int) $linkData['data']['patient_id'] );

        $row = App::db()->fetchRow( 'SELECT first_name, last_name, birth_date FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $existingId ] );
        self::assertIsArray( $row );
        self::assertSame( 'علی', (string) $row['first_name'], 'Existing record first_name must not be overwritten.' );
        self::assertSame( 'کاظمی', (string) $row['last_name'], 'Existing record last_name must not be overwritten.' );
        self::assertSame( '1990-02-10', (string) $row['birth_date'], 'NULL birth_date should be completed.' );
    }

    public function testFailureBranchesProduceIndistinguishableUniformEnvelopeAndZeroSideEffects(): void {
        $this->insertPatient( $this->clinicListed, self::MOBILE_NULL_NID, 'بدون', 'کدملی', null );
        $this->insertPatient( $this->clinicListed, self::MOBILE_ARCHIVED, 'بایگانی', 'شده', self::NID_OTHER, '1988-01-01', 'archived' );
        $this->insertPatient( $this->clinicListed, self::MOBILE_OTHER, 'دیگر', 'بیمار', self::NID_OTHER, '1989-05-05' );
        $existingMismatchId = $this->insertPatient( $this->clinicListed, self::MOBILE_EXISTING, 'عدم', 'تطابق', self::NID_EXISTING, '1991-01-01' );

        $usersBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users );
        $patientsBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
        $loginsBefore   = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) );
        $rateBefore     = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_rate_limits' ) );
        $auditBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        $otpBefore      = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_otp_tokens' ) );

        $responses = [];

        // 1) Wrong OTP code.
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '111222', OtpService::PURPOSE_VERIFY_MOBILE );
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW, 'code' => '999999',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
        ] );

        // 2) Invalid NID checksum.
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW, 'code' => '111222',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => '1234567890', 'birth_date' => '1990-01-01',
        ] );

        // 3) Future birth_date.
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW, 'code' => '111222',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '2099-01-01',
        ] );

        // 4) Private unlisted clinic in multi-clinic setup.
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicPrivate, 'mobile' => self::MOBILE_NEW, 'code' => '111222',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
        ] );

        // 5) OQ-D9: existing record with NULL national_id must NEVER be auto-linked.
        $this->seedOtp( $this->clinicListed, self::MOBILE_NULL_NID, '333444', OtpService::PURPOSE_VERIFY_MOBILE );
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NULL_NID, 'code' => '333444',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
        ] );

        // 6) Existing record with mismatching NID.
        $this->seedOtp( $this->clinicListed, self::MOBILE_EXISTING, '444555', OtpService::PURPOSE_VERIFY_MOBILE );
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_EXISTING, 'code' => '444555',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
        ] );

        // 7) Archived record on same mobile (D-A).
        $this->seedOtp( $this->clinicListed, self::MOBILE_ARCHIVED, '555666', OtpService::PURPOSE_VERIFY_MOBILE );
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_ARCHIVED, 'code' => '555666',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_OTHER, 'birth_date' => '1988-01-01',
        ] );

        // 8) u_pat_nid duplicate NID collision on another patient in same clinic -> atomic rollback (no ghost WP user).
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '666777', OtpService::PURPOSE_VERIFY_MOBILE );
        $responses[] = $this->dispatchRest( 'POST', '/clinic/v1/patient/register', [
            'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW, 'code' => '666777',
            'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_OTHER, 'birth_date' => '1990-01-01',
        ] );

        // 9) Query-string leak rejected uniformly.
        $reqQuery = new WP_REST_Request( 'POST', '/clinic/v1/patient/register' );
        $reqQuery->set_query_params( [ 'national_id' => self::NID_NEW ] );
        $reqQuery->set_header( 'Content-Type', 'application/json' );
        $reqQuery->set_body( (string) wp_json_encode( [ 'clinic_id' => $this->clinicListed ] ) );
        $responses[] = rest_do_request( $reqQuery );

        // Compare every failure branch strictly: HTTP status, code, message, data keys must be identical.
        $baseline = null;
        foreach ( $responses as $idx => $resp ) {
            self::assertSame( 403, $resp->get_status(), 'Branch #' . $idx . ' status mismatch' );
            $data = $resp->get_data();
            self::assertIsArray( $data );
            self::assertArrayHasKey( 'code', $data, 'Branch #' . $idx );
            self::assertArrayHasKey( 'message', $data, 'Branch #' . $idx );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $data['code'], 'Branch #' . $idx );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $data['message'], 'Branch #' . $idx );
            if ( null === $baseline ) {
                $baseline = $data;
            } else {
                self::assertSame( $baseline, $data, 'Failure branch #' . $idx . ' differed from uniform envelope' );
            }
        }

        // The 3 checked identity/account tables (wp_users, cpms_patients, cpms_patient_logins) have zero writes;
        // expected security telemetry (seeded OTP challenges, rate-limit counters, failure audits) persists.
        self::assertSame( $usersBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users ) );
        self::assertSame( $patientsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) ) );
        self::assertSame( $loginsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) ) );
        self::assertSame( $otpBefore + 5, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_otp_tokens' ) ) );
        self::assertGreaterThan( $rateBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_rate_limits' ) ) );
        self::assertGreaterThan( $auditBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) ) );
        self::assertGreaterThan( 0, $existingMismatchId );
    }

    public function testNestedTransactionGuardFailsClosed(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '777888', OtpService::PURPOSE_VERIFY_MOBILE );
        $thrown = null;
        App::db()->transactional(
            function () use ( &$thrown ): void {
                try {
                    App::patient_registration_service()->register(
                        $this->clinicListed,
                        self::MOBILE_NEW,
                        '777888',
                        'Test',
                        'Nested',
                        self::NID_NEW,
                        '1990-01-01'
                    );
                } catch ( BookingException $e ) {
                    $thrown = $e;
                }
            }
        );
        self::assertInstanceOf( BookingException::class, $thrown );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $thrown->errorCode );
        self::assertSame( 403, $thrown->httpStatus );
    }

    public function testAccountPendingAppointmentCapTwoBlocksThirdHoldAllowsRescheduleAndRejectsUnprovedCrossClinicBinding(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_CAP, '888999', OtpService::PURPOSE_VERIFY_MOBILE );
        $token     = '';
        $tokenHook = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $issuedToken ) use ( &$token ): void {
            $token = $issuedToken;
        };
        add_action( 'set_logged_in_cookie', $tokenHook, 10, 6 );
        try {
            $reg = App::patient_registration_service()->register(
                $this->clinicListed,
                self::MOBILE_CAP,
                '888999',
                'مریم',
                'رضایی',
                self::NID_CAP,
                '1991-03-14',
                '198.51.100.77',
                time(),
                true
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $tokenHook, 10 );
        }

        $userId    = (int) $reg['user_id'];
        $patientId = (int) $reg['patient_id'];
        self::assertNotSame( '', $token );
        PatientTwoProofTestHelper::acting_as( $userId );
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie(
            $userId,
            time() + PatientSessionProofStore::SESSION_TTL_SECONDS,
            'logged_in',
            $token
        );
        App::patient_session_scope()->set_in_process_session_token( $userId, $token );

        $date1 = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $date2 = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date3 = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $slot1 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $slot2 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00' );
        $slot3 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date3, '11:00:00' );

        $booking = App::bookingService();
        $h1      = $booking->hold( $userId, $this->clinicianListed, $date1, '09:00:00', $slot1, null, $this->locationListed );
        $appt1   = $booking->confirm( $h1['hold_token'], $userId, null, 'cap-c1-' . uniqid() );

        $h2      = $booking->hold( $userId, $this->clinicianListed, $date2, '10:00:00', $slot2, null, $this->locationListed );
        $booking->confirm( $h2['hold_token'], $userId, null, 'cap-c2-' . uniqid() );

        // 3rd hold must be blocked at cap = 2.
        $capDenied = null;
        try {
            $booking->hold( $userId, $this->clinicianListed, $date3, '11:00:00', $slot3, null, $this->locationListed );
        } catch ( BookingException $e ) {
            $capDenied = $e;
        }
        self::assertInstanceOf( BookingException::class, $capDenied );
        self::assertSame( 'CLINIC_BOOKING_CAP_EXCEEDED', $capDenied->errorCode );
        self::assertSame( 422, $capDenied->httpStatus );

        // Reschedule of appt1 at cap = 2 must succeed (replacement replaces source, total remains 2).
        $rescheduled = $booking->reschedule( $userId, (int) $appt1['id'], $this->clinicianListed, $date3, '11:00:00', 'cap-r1-' . uniqid(), $slot3 );
        self::assertArrayHasKey( 'id', $rescheduled );
        self::assertGreaterThan( 0, (int) $rescheduled['id'] );

        // §2.8 / §8 / §10: if an active binding exists on clinicPrivate without a session proof, cap check fails closed (403).
        $patientB = $this->insertPatient( $this->clinicPrivate, '09125550199', 'مریم', 'ب', '0061234567', '1991-03-14' );
        App::db()->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicPrivate,
                'patient_id'     => $patientB,
                'wp_user_id'     => $userId,
                'mobile_at_link' => '09125550199',
                'is_primary'     => 0,
                'linked_at'      => App::db()->nowUtcSql(),
            ]
        );
        ( new PatientLoginRepository( App::db() ) )->upsert_active_binding( $userId, $this->clinicPrivate, $patientB, App::db()->nowUtcSql() );

        $slot4       = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, gmdate( 'Y-m-d', time() + 6 * 86400 ), '12:00:00' );
        $unprovedEx  = null;
        try {
            $booking->hold( $userId, $this->clinicianListed, gmdate( 'Y-m-d', time() + 6 * 86400 ), '12:00:00', $slot4, null, $this->locationListed );
        } catch ( BookingException $e ) {
            $unprovedEx = $e;
        }
        self::assertInstanceOf( BookingException::class, $unprovedEx );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unprovedEx->errorCode );
        self::assertSame( 403, $unprovedEx->httpStatus );
        self::assertNotSame( '', $token );
        self::assertGreaterThan( 0, $patientId );
    }

    public function testGuestPortalAndPublicBookingRenderRegistrationSurfacesWithoutLeakingPrivateClinics(): void {
        PatientTwoProofTestHelper::acting_as( 0 );
        ob_start();
        PatientOtpLogin::render();
        $portalHtml = (string) ob_get_clean();

        self::assertStringContainsString( 'data-role="patient-register-form"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-national-id"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-birth-date"', $portalHtml );
        self::assertStringNotContainsString( 'Private Unlisted Clinic S2', $portalHtml );

        $bookingHtml = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicListed . '"]' );
        self::assertStringContainsString( 'data-auth-step="otp-register"', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-national-id"', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-birth-date"', $bookingHtml );
        self::assertStringContainsString( 'data-register-path="/patient/register"', $bookingHtml );
    }

    public function testSoleActiveOptedOutClinicMatchesNonexistentAcrossAllThreePathsAndOptedInWitnessPassesUnderSameSpy(): void {
        $db = App::db();
        $db->update( 'cpms_clinics', [ 'status' => 'archived' ], [ 'id' => $this->clinicPrivate ] );
        self::assertSame(
            1,
            (int) $db->fetchValue( "SELECT COUNT(*) FROM " . $db->table( 'cpms_clinics' ) . " WHERE status = 'active'" ),
            'Precondition: exactly one active clinic exists.'
        );
        App::settingsFactory()->forClinic( $this->clinicListed )->set( 'public.listing_opt_in', false );
        \ClinicCore\Settings\Settings::flushCache();

        $optInMobile  = '09125550181';
        $optOutMobile = '09125550183';
        $this->seedOtp( $this->clinicListed, $optOutMobile, '334455', OtpService::PURPOSE_VERIFY_MOBILE );

        $settingsTable       = $db->table( 'cpms_settings' );
        $otpTable            = $db->table( 'cpms_otp_tokens' );
        $usersTable          = $db->wpdb()->users;
        $patientsTable       = $db->table( 'cpms_patients' );
        $loginsTable         = $db->table( 'cpms_patient_logins' );
        $optInSettingReads   = 0;
        $otpInserts          = 0;
        $patientUserInserts  = 0;
        $optInSpy            = static function ( $query ) use (
            $settingsTable,
            $otpTable,
            $usersTable,
            $patientsTable,
            $loginsTable,
            &$optInSettingReads,
            &$otpInserts,
            &$patientUserInserts
        ) {
            $sql = (string) $query;
            if ( false !== stripos( $sql, $settingsTable ) && false !== strpos( $sql, 'public.listing_opt_in' ) ) {
                ++$optInSettingReads;
            }
            if ( 1 === preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $otpTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$otpInserts;
            }
            if (
                1 === preg_match( '/^\s*INSERT\s+INTO\s+`?(?:' . preg_quote( $usersTable, '/' ) . '|' . preg_quote( $patientsTable, '/' ) . '|' . preg_quote( $loginsTable, '/' ) . ')`?\b/i', $sql )
            ) {
                ++$patientUserInserts;
            }

            return $query;
        };

        $normalizeHtml = static function ( string $html ): string {
            return (string) preg_replace( '/cpms-pp-login-[a-z0-9]+/i', 'cpms-pp-login-ID', $html );
        };

        PatientTwoProofTestHelper::acting_as( 0 );
        add_filter( 'query', $optInSpy, 5 );
        try {
            // 1) Sole active opted-out clinic across all 3 paths.
            ob_start();
            PatientOtpLogin::render();
            $optedOutHtml = $normalizeHtml( (string) ob_get_clean() );
            self::assertStringNotContainsString( 'data-role="patient-register-form"', $optedOutHtml );

            $optedOutOtpResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/request',
                [
                    'clinic_id' => $this->clinicListed,
                    'mobile'    => $optOutMobile,
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                ]
            );
            self::assertSame( 404, $optedOutOtpResp->get_status() );
            $optedOutOtpData = $optedOutOtpResp->get_data();
            self::assertIsArray( $optedOutOtpData );
            self::assertArrayHasKey( 'code', $optedOutOtpData );
            self::assertSame( 'CLINIC_NOT_FOUND', $optedOutOtpData['code'] );

            $optedOutRegResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => $this->clinicListed,
                    'mobile'      => $optOutMobile,
                    'code'        => '334455',
                    'given_name'  => 'سارا',
                    'family_name' => 'مخفی',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ]
            );
            self::assertSame( 403, $optedOutRegResp->get_status() );
            $optedOutRegData = $optedOutRegResp->get_data();
            self::assertIsArray( $optedOutRegData );
            self::assertArrayHasKey( 'code', $optedOutRegData );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $optedOutRegData['code'] );
            self::assertGreaterThanOrEqual( 3, $optInSettingReads );
            self::assertSame( 0, $otpInserts );
            self::assertSame( 0, $patientUserInserts );

            // 2) Nonexistent clinic / zero active clinics: identical across all 3 paths.
            $optInSettingReads  = 0;
            $otpInserts         = 0;
            $patientUserInserts = 0;

            $db->update( 'cpms_clinics', [ 'status' => 'archived' ], [ 'id' => $this->clinicListed ] );
            ob_start();
            PatientOtpLogin::render();
            $noClinicHtml = $normalizeHtml( (string) ob_get_clean() );
            $db->update( 'cpms_clinics', [ 'status' => 'active' ], [ 'id' => $this->clinicListed ] );
            self::assertSame( $optedOutHtml, $noClinicHtml );

            $nonexistentOtpResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/request',
                [
                    'clinic_id' => 999999,
                    'mobile'    => $optOutMobile,
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                ]
            );
            self::assertSame( 404, $nonexistentOtpResp->get_status() );
            self::assertSame( $optedOutOtpData, $nonexistentOtpResp->get_data() );

            $nonexistentRegResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => 999999,
                    'mobile'      => $optOutMobile,
                    'code'        => '334455',
                    'given_name'  => 'سارا',
                    'family_name' => 'مخفی',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ]
            );
            self::assertSame( 403, $nonexistentRegResp->get_status() );
            self::assertSame( $optedOutRegData, $nonexistentRegResp->get_data() );
            self::assertSame( 0, $otpInserts );
            self::assertSame( 0, $patientUserInserts );

            // 3) Positive witness: same sole active clinic opted in under the SAME spy.
            $optInSettingReads  = 0;
            $otpInserts         = 0;
            $patientUserInserts = 0;
            App::settingsFactory()->forClinic( $this->clinicListed )->set( 'public.listing_opt_in', true );
            \ClinicCore\Settings\Settings::flushCache();

            ob_start();
            PatientOtpLogin::render();
            $optedInHtml = (string) ob_get_clean();
            self::assertStringContainsString( 'data-role="patient-register-form"', $optedInHtml );

            $witnessOtpResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/request',
                [
                    'clinic_id' => $this->clinicListed,
                    'mobile'    => $optInMobile,
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                ]
            );
            self::assertSame( 200, $witnessOtpResp->get_status() );
            $witnessOtpData = $witnessOtpResp->get_data();
            self::assertIsArray( $witnessOtpData );
            self::assertArrayHasKey( 'data', $witnessOtpData );
            self::assertTrue( $witnessOtpData['data']['sms_sent'] );
            self::assertSame( 1, $otpInserts );

            $pepper = defined( 'CPMS_PEPPER' ) && '' !== (string) CPMS_PEPPER
                ? (string) CPMS_PEPPER
                : (string) get_option( 'cpms_otp_pepper', '' );
            self::assertNotSame( '', $pepper );
            $latestOtpId = (int) $db->fetchValue(
                'SELECT id FROM ' . $otpTable . ' WHERE clinic_id = %d AND mobile = %s ORDER BY id DESC LIMIT 1',
                [ $this->clinicListed, $optInMobile ]
            );
            self::assertGreaterThan( 0, $latestOtpId );
            $db->update( 'cpms_otp_tokens', [ 'code_hash' => OtpPolicy::hashCode( '556677', $pepper ) ], [ 'id' => $latestOtpId ] );

            $witnessRegResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => $this->clinicListed,
                    'mobile'      => $optInMobile,
                    'code'        => '556677',
                    'given_name'  => 'سارا',
                    'family_name' => 'ثبت‌شده',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ]
            );
            self::assertSame( 200, $witnessRegResp->get_status() );
            $witnessRegData = $witnessRegResp->get_data();
            self::assertIsArray( $witnessRegData );
            self::assertArrayHasKey( 'data', $witnessRegData );
            self::assertTrue( $witnessRegData['data']['registered'] );
            self::assertGreaterThanOrEqual( 3, $optInSettingReads );
            self::assertSame( 1, $otpInserts );
            self::assertGreaterThanOrEqual( 3, $patientUserInserts );
        } finally {
            remove_filter( 'query', $optInSpy, 5 );
        }
    }

    public function testBookingGateRefusesLegacyProvedPatientMissingBirthDateInsideTransactionAllowsStaffAndPassesCompleteWitness(): void {
        $db  = App::db();
        $now = $db->nowUtcSql();

        // Legacy proved patient: valid national_id, MISSING birth_date.
        $legacyMobile    = '09125550171';
        $legacyPatientId = $this->insertPatient( $this->clinicListed, $legacyMobile, 'قدیمی', 'ناقص', self::NID_EXISTING, null );
        $legacyUserId    = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $legacyUserId,
            $this->clinicListed,
            $legacyPatientId,
            [
                'mobile'             => $legacyMobile,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        // Staff-created incomplete patient: missing BOTH national_id and birth_date.
        $walkinPatientId = $this->insertPatient( $this->clinicListed, '09125550173', 'حضوری', 'بدون‌کد', null, null );

        // Complete-profile proved patient witness.
        $completeMobile    = '09125550172';
        $completePatientId = $this->insertPatient( $this->clinicListed, $completeMobile, 'کامل', 'تست', self::NID_CAP, '1992-05-10' );
        $completeUserId    = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $completeUserId,
            $this->clinicListed,
            $completePatientId,
            [
                'mobile'             => $completeMobile,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $staffUserId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_SECRETARY ] );
        App::membership_service()->create_membership( $this->clinicListed, $staffUserId, RolesAndCapabilities::ROLE_SECRETARY );

        $date1            = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $date2            = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date3            = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $date4            = gmdate( 'Y-m-d', time() + 6 * 86400 );
        $slotStaff        = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $slotHoldFail     = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00' );
        $slotConfirmFail  = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date3, '11:00:00' );
        $slotWitness      = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date4, '12:00:00' );

        $apptsTable = $db->table( 'cpms_appointments' );
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $patsTable  = $db->table( 'cpms_patients' );
        $booking    = App::bookingService();

        $apptsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable );

        // 1) Staff creation MUST succeed for an incomplete patient (no national_id, no birth_date).
        ScopeContext::set( ClinicScope::forClinic( $this->clinicListed, $this->locationListed )->withOrganization( $this->orgId ) );
        try {
            $staffAppt = $booking->createByStaff( $staffUserId, $walkinPatientId, $this->clinicianListed, $date1, '09:00:00', 'پذیرش حضوری', $slotStaff );
        } finally {
            ScopeContext::clear();
        }
        self::assertArrayHasKey( 'appointment_id', $staffAppt );
        self::assertGreaterThan( 0, (int) $staffAppt['appointment_id'] );
        $expectedAppts = $apptsBefore + 1;
        self::assertSame( $expectedAppts, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );

        $txStarts             = 0;
        $txRollbacks          = 0;
        $txCommits            = 0;
        $txActive             = false;
        $patientGateLockReads = 0;
        $patientGateReadsInTx = 0;
        $holdInserts          = 0;
        $appointmentInserts   = 0;
        $bookingSpy           = static function ( $query ) use (
            $patsTable,
            $holdsTable,
            $apptsTable,
            &$txStarts,
            &$txRollbacks,
            &$txCommits,
            &$txActive,
            &$patientGateLockReads,
            &$patientGateReadsInTx,
            &$holdInserts,
            &$appointmentInserts
        ) {
            $sql = trim( (string) $query );
            if ( 1 === preg_match( '/^(?:\/\*cpms\*\/\s*)?(?:START\s+TRANSACTION|BEGIN)\b/i', $sql ) ) {
                ++$txStarts;
                $txActive = true;
            }
            if ( 1 === preg_match( '/^(?:\/\*cpms\*\/\s*)?ROLLBACK\b/i', $sql ) ) {
                ++$txRollbacks;
                $txActive = false;
            }
            if ( 1 === preg_match( '/^(?:\/\*cpms\*\/\s*)?COMMIT\b/i', $sql ) ) {
                ++$txCommits;
                $txActive = false;
            }
            if ( false !== stripos( $sql, $patsTable ) && false !== stripos( $sql, 'FOR UPDATE' ) ) {
                ++$patientGateLockReads;
                if ( $txActive ) {
                    ++$patientGateReadsInTx;
                }
            }
            if ( 1 === preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $holdsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$holdInserts;
            }
            if ( 1 === preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $apptsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$appointmentInserts;
            }

            return $query;
        };

        add_filter( 'query', $bookingSpy, 5 );
        try {
            // 2) Self-service hold() refuses legacy proved patient missing birth_date inside transaction.
            PatientTwoProofTestHelper::acting_as( $legacyUserId );
            $holdEx = null;
            try {
                $booking->hold( $legacyUserId, $this->clinicianListed, $date2, '10:00:00', $slotHoldFail, $legacyPatientId, $this->locationListed );
            } catch ( BookingException $e ) {
                $holdEx = $e;
            }
            self::assertInstanceOf( BookingException::class, $holdEx );
            self::assertSame( 'CLINIC_VALIDATION_FAILED', $holdEx->errorCode );
            self::assertSame( 400, $holdEx->httpStatus );
            self::assertGreaterThanOrEqual( 1, $txStarts );
            self::assertSame( 1, $patientGateLockReads );
            self::assertSame( 1, $patientGateReadsInTx );
            self::assertGreaterThanOrEqual( 1, $txRollbacks );
            self::assertSame( 0, $holdInserts );
            self::assertSame( 0, $appointmentInserts );
            self::assertSame( $expectedAppts, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );

            // 3) Self-service confirm() refuses legacy proved patient missing birth_date inside transaction.
            $seedHoldToken = 'hold-legacy-' . bin2hex( random_bytes( 6 ) );
            $db->update( 'cpms_schedule_slots', [ 'held_count' => 1 ], [ 'id' => $slotConfirmFail ] );
            $db->insert(
                'cpms_slot_holds',
                [
                    'clinic_id'         => $this->clinicListed,
                    'slot_id'           => $slotConfirmFail,
                    'holder_wp_user_id' => $legacyUserId,
                    'holder_mobile'     => $legacyMobile,
                    'patient_id'        => $legacyPatientId,
                    'token'             => $seedHoldToken,
                    'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 600 ) . '.000',
                    'status'            => 'active',
                    'created_at'        => $now,
                ]
            );

            $txStarts             = 0;
            $txRollbacks          = 0;
            $txCommits            = 0;
            $txActive             = false;
            $patientGateLockReads = 0;
            $patientGateReadsInTx = 0;
            $holdInserts          = 0;
            $appointmentInserts   = 0;

            $confirmEx = null;
            try {
                $booking->confirm( $seedHoldToken, $legacyUserId, null, 'confirm-legacy-' . uniqid() );
            } catch ( BookingException $e ) {
                $confirmEx = $e;
            }
            self::assertInstanceOf( BookingException::class, $confirmEx );
            self::assertSame( 'CLINIC_VALIDATION_FAILED', $confirmEx->errorCode );
            self::assertSame( 400, $confirmEx->httpStatus );
            self::assertGreaterThanOrEqual( 1, $txStarts );
            self::assertSame( 1, $patientGateLockReads );
            self::assertSame( 1, $patientGateReadsInTx );
            self::assertGreaterThanOrEqual( 1, $txRollbacks );
            self::assertSame( 0, $appointmentInserts );
            self::assertSame( $expectedAppts, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );

            // 4) Positive witness: complete-profile proved patient succeeds under the SAME spy.
            $txStarts             = 0;
            $txRollbacks          = 0;
            $txCommits            = 0;
            $txActive             = false;
            $patientGateLockReads = 0;
            $patientGateReadsInTx = 0;
            $holdInserts          = 0;
            $appointmentInserts   = 0;

            PatientTwoProofTestHelper::acting_as( $completeUserId );
            $witnessHold    = $booking->hold( $completeUserId, $this->clinicianListed, $date4, '12:00:00', $slotWitness, $completePatientId, $this->locationListed );
            $witnessConfirm = $booking->confirm( (string) $witnessHold['hold_token'], $completeUserId, null, 'confirm-complete-' . uniqid() );
            self::assertArrayHasKey( 'appointment_id', $witnessConfirm );
            self::assertGreaterThan( 0, (int) $witnessConfirm['appointment_id'] );
            self::assertGreaterThanOrEqual( 2, $txCommits );
            self::assertSame( 2, $patientGateLockReads );
            self::assertSame( 2, $patientGateReadsInTx );
            self::assertSame( 1, $holdInserts );
            self::assertSame( 1, $appointmentInserts );
            self::assertSame( $expectedAppts + 1, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );
        } finally {
            remove_filter( 'query', $bookingSpy, 5 );
        }
    }

    public function testNullClinicOtpTokenIsRejectedForRegistrationInAnyClinic(): void {
        $mobile = '09125550191';
        $this->seedOtp( null, $mobile, '445566', OtpService::PURPOSE_VERIFY_MOBILE );

        $usersBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users );
        $patientsBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
        $loginsBefore   = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) );

        $resp = $this->dispatchRest(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => $mobile,
                'code'        => '445566',
                'given_name'  => 'تست',
                'family_name' => 'بدون‌کلینیک',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1993-07-21',
            ]
        );

        self::assertSame( 403, $resp->get_status() );
        $data = $resp->get_data();
        self::assertIsArray( $data );
        self::assertArrayHasKey( 'code', $data );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $data['code'] );
        $consumedAt = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table( 'cpms_otp_tokens' ) . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [ $mobile ]
        );
        self::assertNull( $consumedAt );
        self::assertSame( $usersBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users ) );
        self::assertSame( $patientsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) ) );
        self::assertSame( $loginsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) ) );
    }

    private function seedOtp( ?int $clinicId, string $mobile, string $code, string $purpose ): void {
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

    private function insertPatient(
        int $clinicId,
        string $mobile,
        string $firstName,
        string $lastName,
        ?string $nationalId,
        ?string $birthDate = '1990-01-01',
        string $status = 'active'
    ): int {
        $now = App::db()->nowUtcSql();
        App::db()->insert(
            'cpms_patients',
            [
                'clinic_id'   => $clinicId,
                'mrn'         => 'MR-S2-' . uniqid(),
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'mobile'      => $mobile,
                'national_id' => $nationalId,
                'birth_date'  => $birthDate,
                'status'      => $status,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        return App::db()->wpdb_last_insert_id();
    }

    private function insertSlot( int $clinicId, int $locationId, int $clinicianId, string $date, string $time ): int {
        $now = App::db()->nowUtcSql();
        App::db()->insert(
            'cpms_schedule_slots',
            [
                'clinic_id'    => $clinicId,
                'location_id'  => $locationId,
                'clinician_id' => $clinicianId,
                'slot_date'    => $date,
                'slot_time'    => $time,
                'duration_min' => 20,
                'capacity'     => 2,
                'booked_count' => 0,
                'held_count'   => 0,
                'is_open'      => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        return App::db()->wpdb_last_insert_id();
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
}

<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientCredentialGuard;
use ClinicCore\Application\Auth\PatientRegistrationService;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Auth\PatientSessionScope;
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
        remove_all_filters( 'cpms_license_public_key' );
        PatientTwoProofTestHelper::reset();
        App::resetScope();
        parent::tearDown();
    }

    public function testNewPatientRegistrationCreatesFullChainIssuesTwoProofSessionAndExcludesPii(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '123456', OtpService::PURPOSE_VERIFY_MOBILE );

        $auditBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );

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

        self::assertSame( 200, $resp->get_status() );
        $body = $resp->get_data();
        self::assertTrue( (bool) ( $body['data']['registered'] ?? false ) );
        self::assertTrue( (bool) ( $body['data']['session_issued'] ?? false ) );
        $userId    = (int) ( $body['data']['user_id'] ?? 0 );
        $patientId = (int) ( $body['data']['patient_id'] ?? 0 );
        $bindingId = (int) ( $body['data']['binding_id'] ?? 0 );
        self::assertGreaterThan( 0, $userId );
        self::assertGreaterThan( 0, $patientId );
        self::assertGreaterThan( 0, $bindingId );
        self::assertSame( $this->clinicListed, (int) ( $body['data']['clinic_id'] ?? 0 ) );

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
        $sessionToken = array_key_first( $tokens );
        self::assertIsString( $sessionToken );
        $proofStore = new PatientSessionProofStore();
        self::assertTrue( $proofStore->has_valid_mobile_proof( $userId, self::MOBILE_NEW, time(), $sessionToken ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $userId, $patientId, $this->clinicListed, time(), $sessionToken ) );
        self::assertSame( $this->clinicListed, App::patient_session_scope()->resolve_session_clinic_id( $userId ) );

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
        foreach ( $rateKeys as $rk ) {
            self::assertStringNotContainsString( self::NID_NEW, (string) ( $rk['window_key'] ?? '' ) );
            self::assertStringNotContainsString( self::MOBILE_NEW, (string) ( $rk['window_key'] ?? '' ) );
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
        self::assertSame( $existingId, (int) ( $resp->get_data()['data']['patient_id'] ?? 0 ) );

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
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $data['code'] ?? null, 'Branch #' . $idx );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $data['message'] ?? null, 'Branch #' . $idx );
            if ( null === $baseline ) {
                $baseline = $data;
            } else {
                self::assertSame( $baseline, $data, 'Failure branch #' . $idx . ' differed from uniform envelope' );
            }
        }

        // Zero side effects in DB (no ghost WP user, no patient created, no login row).
        self::assertSame( $usersBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users ) );
        self::assertSame( $patientsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) ) );
        self::assertSame( $loginsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) ) );
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

        $userId    = (int) $reg['user_id'];
        $patientId = (int) $reg['patient_id'];
        $tokens    = WP_Session_Tokens::get_instance( $userId )->get_all();
        $token     = (string) array_key_first( $tokens );
        PatientTwoProofTestHelper::acting_as( $userId );

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
        self::assertGreaterThan( 0, (int) ( $rescheduled['id'] ?? 0 ) );

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

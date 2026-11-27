<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientCredentialGuard;
use ClinicCore\Application\Auth\PatientRegistrationService;
use ClinicCore\Application\Auth\PatientSessionProofStore;
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
 * Integration tests for PATIENT-REGISTRATION-SLICE-2 (Two-Step Split):
 *  - Step 1: `POST /clinic/v1/otp/verify` (`purpose=verify_mobile`, `clinic_id`) consumes OTP single-use,
 *    creates/reuses `STATE_PENDING` account (zero `cpms_patients` row, zero bindings), issues session + `cpms_reg_proofs`
 *  - Step 2: `POST /clinic/v1/patient/register` (with `X-WP-Nonce`, NO OTP `code`) completes profile + NID
 *  - Incomplete Step-1 redirect & `#complete-registration` portal completion (T5)
 *  - Clinic-less `verify_mobile` keeps returning `user_id=0, session_issued=false` (T7)
 *  - Uniform indistinguishable `403 CLINIC_PATIENT_PROOF_FAILED` across all failure branches
 *  - §4.4 N2 initial-credential guard + pending-appointment cap = 2 + zero NID leaks
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
    private int $clinicListedB;
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
                'name'            => 'Second Public Clinic S2',
                'slug'            => 'public-b-s2-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicListedB = $db->wpdb_last_insert_id();
        App::settingsFactory()->forClinic( $this->clinicListedB )->set( 'public.listing_opt_in', true );

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

        $patientsBeforeStep1 = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
        $step1               = $this->performStep1VerifyRest( $this->clinicListed, '۰۹۱۲۵۵۵۰۱۰۱', '123456' );
        self::assertSame( 200, $step1['response']->get_status() );
        self::assertTrue( $step1['session_issued'] );
        self::assertTrue( $step1['pending_registration'] );
        self::assertGreaterThan( 0, $step1['user_id'] );
        self::assertNotSame( '', $step1['rest_nonce'] );
        self::assertNotSame( '', $step1['token'] );

        // Step 1 MUST NOT create a cpms_patients row or any bindings, and keeps account in STATE_PENDING.
        self::assertSame(
            $patientsBeforeStep1,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) ),
            'Step 1 must NOT insert a cpms_patients row before Step 2.'
        );
        $logins       = new PatientLoginRepository( App::db() );
        $pendingAcct  = $logins->find_account_by_user_id( $step1['user_id'] );
        self::assertIsArray( $pendingAcct );
        self::assertSame( PatientLoginRepository::STATE_PENDING, $pendingAcct['state'] );
        self::assertNull( $pendingAcct['given_name'] );
        self::assertNull( $pendingAcct['family_name'] );
        self::assertSame( [], $logins->find_active_bindings_for_user( $step1['user_id'] ) );

        $proofStore = new PatientSessionProofStore();
        self::assertTrue( $proofStore->has_valid_registration_mobile_proof( $step1['user_id'], $this->clinicListed, self::MOBILE_NEW, time(), $step1['token'] ) );

        // Step 1 OTP must be consumed single-use and replay refused.
        $replayResp = $this->dispatchRest(
            'POST',
            '/clinic/v1/otp/verify',
            [
                'mobile'    => self::MOBILE_NEW,
                'code'      => '123456',
                'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                'clinic_id' => $this->clinicListed,
            ]
        );
        self::assertSame( 400, $replayResp->get_status() );
        $replayData = $replayResp->get_data();
        self::assertIsArray( $replayData );
        self::assertArrayHasKey( 'code', $replayData );
        self::assertSame( 'CLINIC_OTP_INVALID', $replayData['code'] );

        // T9 / Fix B: Step-1 mobile-proof-only session MUST be refused on all clinical & booking endpoints.
        $meDenied = $this->dispatchRestWithNonce( 'GET', '/clinic/v1/patient/me', [], $step1['rest_nonce'] );
        self::assertSame( 403, $meDenied->get_status() );
        $meDeniedData = $meDenied->get_data();
        self::assertIsArray( $meDeniedData );
        self::assertArrayHasKey( 'code', $meDeniedData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $meDeniedData['code'] );

        $visitsDenied = $this->dispatchRestWithNonce( 'GET', '/clinic/v1/visits', [], $step1['rest_nonce'] );
        self::assertSame( 403, $visitsDenied->get_status() );
        $visitsDeniedData = $visitsDenied->get_data();
        self::assertIsArray( $visitsDeniedData );
        self::assertArrayHasKey( 'code', $visitsDeniedData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $visitsDeniedData['code'] );

        $holdDenied = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/booking/hold',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => gmdate( 'Y-m-d', time() + 3 * 86400 ),
                'slot_time'    => '10:00:00',
            ],
            $step1['rest_nonce']
        );
        self::assertSame( 403, $holdDenied->get_status() );
        $holdDeniedData = $holdDenied->get_data();
        self::assertIsArray( $holdDeniedData );
        self::assertArrayHasKey( 'code', $holdDeniedData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $holdDeniedData['code'] );

        // Step 2: submit profile + National ID WITHOUT OTP code.
        $auditBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        $resp        = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => '۰۹۱۲۵۵۵۰۱۰۱',
                'given_name'  => 'سارا',
                'family_name' => 'محمدی',
                'national_id' => '۰۴۹۹۳۷۰۸۹۹',
                'birth_date'  => '۱۹۹۳-۰۷-۲۱',
            ],
            $step1['rest_nonce']
        );

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
        self::assertSame( $step1['user_id'], $userId );
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

        // Patient row created ONLY at Step 2 with birth_date + identity_id.
        $patient = App::db()->fetchRow( 'SELECT * FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $patientId ] );
        self::assertIsArray( $patient );
        self::assertSame( 'سارا', (string) $patient['first_name'] );
        self::assertSame( 'محمدی', (string) $patient['last_name'] );
        self::assertSame( self::MOBILE_NEW, (string) $patient['mobile'] );
        self::assertSame( self::NID_NEW, (string) $patient['national_id'] );
        self::assertSame( '1993-07-21', (string) $patient['birth_date'] );
        self::assertArrayHasKey( 'identity_id', $patient );
        self::assertGreaterThan( 0, (int) $patient['identity_id'] );

        // Account + binding active; WP user is pure patient; session holds both proofs + clinic scope; reg proof consumed.
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
        self::assertTrue( $proofStore->has_valid_mobile_proof( $userId, self::MOBILE_NEW, time(), $step1['token'] ) );
        self::assertFalse( $proofStore->has_valid_registration_mobile_proof( $userId, $this->clinicListed, self::MOBILE_NEW, time(), $step1['token'] ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $userId, $patientId, $this->clinicListed, time(), $step1['token'] ) );
        self::assertSame( $this->clinicListed, App::patient_session_scope()->resolve_clinic_id( $userId ) );

        // Replay of Step 2 after completion must fail closed.
        $step2Replay = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => self::MOBILE_NEW,
                'given_name'  => 'سارا',
                'family_name' => 'محمدی',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1993-07-21',
            ],
            $step1['rest_nonce']
        );
        self::assertSame( 403, $step2Replay->get_status() );
        $step2ReplayData = $step2Replay->get_data();
        self::assertIsArray( $step2ReplayData );
        self::assertArrayHasKey( 'code', $step2ReplayData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $step2ReplayData['code'] );

        // Audit wrote PATIENT_REGISTERED without raw or masked NID, and rate limits hashed NID.
        $audits = App::db()->fetchAll(
            'SELECT action, after_json, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' ORDER BY id DESC LIMIT 5'
        );
        self::assertGreaterThan( $auditBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) ) );
        $foundRegAudit = false;
        foreach ( $audits as $row ) {
            self::assertArrayHasKey( 'action', $row );
            self::assertArrayHasKey( 'after_json', $row );
            self::assertArrayHasKey( 'meta_json', $row );
            $blob = (string) $row['after_json'] . (string) $row['meta_json'];
            self::assertStringNotContainsString( self::NID_NEW, $blob );
            self::assertStringNotContainsString( substr( self::NID_NEW, -4 ), $blob );
            if ( 'PATIENT_REGISTERED' === (string) $row['action'] ) {
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
        $step1 = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_EXISTING, '223344' );
        self::assertTrue( $step1['session_issued'] );

        $resp = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => self::MOBILE_EXISTING,
                'given_name'  => 'DifferentGiven',
                'family_name' => 'DifferentFamily',
                'national_id' => self::NID_EXISTING,
                'birth_date'  => '1990-02-10',
            ],
            $step1['rest_nonce']
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

        // Pre-seed Step 1 sessions for mobiles tested in Step 2 refusal branches so Step 2 failure invariants
        // verify zero cpms_patients / appointments writes & exact uniform 403 envelopes.
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '111222', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1New = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_NEW, '111222' );
        self::assertTrue( $step1New['session_issued'] );

        $this->seedOtp( $this->clinicListed, self::MOBILE_NULL_NID, '333444', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1NullNid = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_NULL_NID, '333444' );
        self::assertTrue( $step1NullNid['session_issued'] );

        $this->seedOtp( $this->clinicListed, self::MOBILE_EXISTING, '444555', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1Existing = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_EXISTING, '444555' );
        self::assertTrue( $step1Existing['session_issued'] );

        $this->seedOtp( $this->clinicListed, self::MOBILE_ARCHIVED, '555666', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1Archived = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_ARCHIVED, '555666' );
        self::assertTrue( $step1Archived['session_issued'] );

        $staffUserId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_DOCTOR ] );
        App::membership_service()->create_membership( $this->clinicListed, $staffUserId, RolesAndCapabilities::ROLE_DOCTOR );

        $mixedUserId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        $mixedUser   = get_userdata( $mixedUserId );
        self::assertInstanceOf( \WP_User::class, $mixedUser );
        $mixedUser->add_role( RolesAndCapabilities::ROLE_SECRETARY );

        $usersBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users );
        $patientsBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
        $loginsBefore   = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) );
        $apptsBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_appointments' ) );
        $rateBefore     = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_rate_limits' ) );
        $auditBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        $otpBefore      = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_otp_tokens' ) );

        $responses = [];

        // 1) Anonymous caller (even with guest nonce) attempting Step 2 without Step 1 proof.
        PatientTwoProofTestHelper::acting_as( 0 );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            wp_create_nonce( 'wp_rest' )
        );

        // 2) Staff actor refused on Step 2.
        wp_set_current_user( $staffUserId );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            wp_create_nonce( 'wp_rest' )
        );

        // 3) Mixed patient+staff actor refused on Step 2.
        wp_set_current_user( $mixedUserId );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            wp_create_nonce( 'wp_rest' )
        );

        // 4) Cross-clinic proof refusal: verified for clinicListed, submitting Step 2 for clinicListedB!
        $this->activateStep1Session( $step1New );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListedB, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 5) Wrong mobile mismatch (body mobile differs from Step 1 verified mobile).
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => '09125550198',
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 6) Invalid NID checksum.
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => '1234567890', 'birth_date' => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 7) Future birth_date.
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '2099-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 8) Private unlisted clinic in multi-clinic setup.
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicPrivate, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 9) u_pat_nid duplicate NID collision on another patient in same clinic -> atomic rollback.
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NEW,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_OTHER, 'birth_date' => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );

        // 10) OQ-D9: existing record with NULL national_id must NEVER be auto-linked.
        $this->activateStep1Session( $step1NullNid );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_NULL_NID,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            $step1NullNid['rest_nonce']
        );

        // 11) Existing record with mismatching NID.
        $this->activateStep1Session( $step1Existing );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_EXISTING,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_NEW, 'birth_date' => '1990-01-01',
            ],
            $step1Existing['rest_nonce']
        );

        // 12) Archived record on same mobile (D-A).
        $this->activateStep1Session( $step1Archived );
        $responses[] = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id' => $this->clinicListed, 'mobile' => self::MOBILE_ARCHIVED,
                'given_name' => 'A', 'family_name' => 'B', 'national_id' => self::NID_OTHER, 'birth_date' => '1988-01-01',
            ],
            $step1Archived['rest_nonce']
        );

        // 13) Query-string leak rejected uniformly.
        $this->activateStep1Session( $step1New );
        $reqQuery = new WP_REST_Request( 'POST', '/clinic/v1/patient/register' );
        $reqQuery->set_query_params( [ 'national_id' => self::NID_NEW ] );
        $reqQuery->set_header( 'Content-Type', 'application/json' );
        $reqQuery->set_header( 'X-WP-Nonce', $step1New['rest_nonce'] );
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

        // Also assert TTL expiry (>3600s) fails closed at Step 2 service level.
        $ttlEx = null;
        try {
            App::patient_registration_service()->register(
                $step1New['user_id'],
                $this->clinicListed,
                self::MOBILE_NEW,
                'A',
                'B',
                self::NID_NEW,
                '1990-01-01',
                '198.51.100.1',
                time() + PatientSessionProofStore::PROOF_TTL_SECONDS + 1,
                $step1New['token']
            );
        } catch ( BookingException $e ) {
            $ttlEx = $e;
        }
        self::assertInstanceOf( BookingException::class, $ttlEx );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $ttlEx->errorCode );
        self::assertSame( 403, $ttlEx->httpStatus );

        // Zero writes on wp_users, cpms_patients, cpms_patient_logins, cpms_appointments, cpms_otp_tokens.
        self::assertSame( $usersBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users ) );
        self::assertSame( $patientsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) ) );
        self::assertSame( $loginsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) ) );
        self::assertSame( $apptsBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_appointments' ) ) );
        self::assertSame( $otpBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_otp_tokens' ) ) );
        self::assertGreaterThan( $rateBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_rate_limits' ) ) );
        self::assertGreaterThan( $auditBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) ) );
        self::assertGreaterThan( 0, $existingMismatchId );

        // Positive witness: valid Step 2 completion for $step1New succeeds cleanly after all refusals.
        $this->activateStep1Session( $step1New );
        $witnessResp = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => self::MOBILE_NEW,
                'given_name'  => 'سارا',
                'family_name' => 'شاهد',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1990-01-01',
            ],
            $step1New['rest_nonce']
        );
        self::assertSame( 200, $witnessResp->get_status() );
        $witnessData = $witnessResp->get_data();
        self::assertIsArray( $witnessData );
        self::assertArrayHasKey( 'data', $witnessData );
        self::assertIsArray( $witnessData['data'] );
        self::assertArrayHasKey( 'registered', $witnessData['data'] );
        self::assertTrue( $witnessData['data']['registered'] );
    }

    public function testNestedTransactionGuardFailsClosed(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '777888', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1  = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_NEW, '777888' );
        $thrown = null;
        App::db()->transactional(
            function () use ( $step1, &$thrown ): void {
                try {
                    App::patient_registration_service()->register(
                        $step1['user_id'],
                        $this->clinicListed,
                        self::MOBILE_NEW,
                        'Test',
                        'Nested',
                        self::NID_NEW,
                        '1990-01-01',
                        '0.0.0.0',
                        null,
                        $step1['token']
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
        $step1 = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_CAP, '888999' );
        self::assertTrue( $step1['session_issued'] );

        $reg = App::patient_registration_service()->register(
            $step1['user_id'],
            $this->clinicListed,
            self::MOBILE_CAP,
            'مریم',
            'رضایی',
            self::NID_CAP,
            '1991-03-14',
            '198.51.100.77',
            time(),
            $step1['token']
        );

        $userId    = (int) $reg['user_id'];
        $patientId = (int) $reg['patient_id'];
        $token     = $step1['token'];
        self::assertNotSame( '', $token );

        $date1 = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $date2 = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date3 = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $slot1 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $slot2 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00' );
        $slot3 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date3, '11:00:00' );

        $booking = App::bookingService();
        $h1      = $booking->hold( $userId, $this->clinicianListed, $date1, '09:00:00', $slot1, null, $this->locationListed );
        $appt1   = $booking->confirm( $h1['hold_token'], $userId, null, 'cap-c1-' . uniqid() );

        $h2 = $booking->hold( $userId, $this->clinicianListed, $date2, '10:00:00', $slot2, null, $this->locationListed );
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

        $slot4      = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, gmdate( 'Y-m-d', time() + 6 * 86400 ), '12:00:00' );
        $unprovedEx = null;
        try {
            $booking->hold( $userId, $this->clinicianListed, gmdate( 'Y-m-d', time() + 6 * 86400 ), '12:00:00', $slot4, null, $this->locationListed );
        } catch ( BookingException $e ) {
            $unprovedEx = $e;
        }
        self::assertInstanceOf( BookingException::class, $unprovedEx );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unprovedEx->errorCode );
        self::assertSame( 403, $unprovedEx->httpStatus );
        self::assertGreaterThan( 0, $patientId );
    }

    public function testGuestPortalAndPublicBookingRenderTwoStepRegistrationWithoutProfileBeforeOtpVerify(): void {
        PatientTwoProofTestHelper::acting_as( 0 );
        ob_start();
        PatientOtpLogin::render();
        $portalHtml = (string) ob_get_clean();

        self::assertStringContainsString( 'data-role="patient-register-form"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-code-step"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-otp-verify"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-details-step" hidden', $portalHtml );
        self::assertStringContainsString( 'data-role="register-national-id"', $portalHtml );
        self::assertStringContainsString( 'data-role="register-birth-date"', $portalHtml );
        self::assertStringNotContainsString( 'Private Unlisted Clinic S2', $portalHtml );

        // Ensure register-code is outside register-details-step.
        $codePos    = strpos( $portalHtml, 'data-role="register-code"' );
        $detailsPos = strpos( $portalHtml, 'data-role="register-details-step"' );
        self::assertNotFalse( $codePos );
        self::assertNotFalse( $detailsPos );
        self::assertLessThan( $detailsPos, $codePos );

        $bookingHtml = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicListed . '"]' );
        self::assertStringContainsString( 'data-auth-step="otp-register"', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-code-step" hidden', $bookingHtml );
        self::assertStringContainsString( 'data-auth-action="reg-otp-verify"', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-details" hidden', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-national-id"', $bookingHtml );
        self::assertStringContainsString( 'data-role="reg-birth-date"', $bookingHtml );
        self::assertStringContainsString( 'data-register-path="/patient/register"', $bookingHtml );
    }

    public function testIncompleteStep1RegistrationRedirectsPublicBookingAndRendersPortalCompletion(): void {
        $this->seedOtp( $this->clinicListed, self::MOBILE_NEW, '616263', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->performStep1VerifyRest( $this->clinicListed, self::MOBILE_NEW, '616263' );
        self::assertTrue( $step1['session_issued'] );

        $state = App::patient_access()->registration_completion_state( $step1['user_id'] );
        self::assertTrue( $state['incomplete'] );
        self::assertTrue( $state['has_valid_proof'] );
        self::assertSame( $this->clinicListed, $state['clinic_id'] );
        self::assertSame( self::MOBILE_NEW, $state['mobile'] );

        $redirectUrl = PublicBookingShortcode::incomplete_registration_redirect_url();
        self::assertStringEndsWith( '#complete-registration', $redirectUrl );

        // Public booking surface refuses booking continuation and points to #complete-registration.
        $bookingHtml = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicListed . '"]' );
        self::assertStringContainsString( 'data-role="complete-registration-redirect"', $bookingHtml );
        self::assertStringContainsString( '#complete-registration', $bookingHtml );
        self::assertStringNotContainsString( 'hold_path', $bookingHtml );
        self::assertStringNotContainsString( 'confirm_path', $bookingHtml );

        // Trying to hold a booking while incomplete fails closed at the gate.
        $holdBlocked = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/booking/hold',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => gmdate( 'Y-m-d', time() + 3 * 86400 ),
                'slot_time'    => '10:00:00',
            ],
            $step1['rest_nonce']
        );
        self::assertSame( 403, $holdBlocked->get_status() );
        $holdBlockedData = $holdBlocked->get_data();
        self::assertIsArray( $holdBlockedData );
        self::assertArrayHasKey( 'code', $holdBlockedData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $holdBlockedData['code'] );

        // Patient Portal renders #complete-registration Step 2 form and suppresses link-record-form.
        ob_start();
        PatientPortalPage::render();
        $portalHtml = (string) ob_get_clean();
        self::assertStringContainsString( 'id="complete-registration"', $portalHtml );
        self::assertStringContainsString( 'data-role="complete-registration-form"', $portalHtml );
        self::assertStringContainsString( 'data-role="complete-reg-national-id"', $portalHtml );
        self::assertStringNotContainsString( 'data-role="link-record-form"', $portalHtml );

        // Positive witness: completing Step 2 clears incomplete state and unlocks booking surface.
        $completeResp = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => self::MOBILE_NEW,
                'given_name'  => 'مریم',
                'family_name' => 'تکمیل',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1991-03-14',
            ],
            $step1['rest_nonce']
        );
        self::assertSame( 200, $completeResp->get_status() );
        $afterState = App::patient_access()->registration_completion_state( $step1['user_id'] );
        self::assertFalse( $afterState['incomplete'] );
        self::assertFalse( $afterState['has_valid_proof'] );

        $bookingUnlockedHtml = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicListed . '"]' );
        self::assertStringNotContainsString( 'data-role="complete-registration-redirect"', $bookingUnlockedHtml );
        self::assertStringContainsString( 'hold_path', $bookingUnlockedHtml );

        ob_start();
        PatientPortalPage::render();
        $portalAfterHtml = (string) ob_get_clean();
        self::assertStringNotContainsString( 'id="complete-registration"', $portalAfterHtml );

        // Separate expired-proof check: a pending Step-1 account whose reg proof expired keeps incomplete=true, has_valid_proof=false.
        $expiredMobile = '09125550177';
        $this->seedOtp( $this->clinicListed, $expiredMobile, '646566', OtpService::PURPOSE_VERIFY_MOBILE );
        $expiredStep1 = $this->performStep1VerifyRest( $this->clinicListed, $expiredMobile, '646566' );
        self::assertTrue( $expiredStep1['session_issued'] );
        ( new PatientSessionProofStore() )->consume_registration_mobile_proof( $expiredStep1['user_id'], $this->clinicListed, $expiredMobile, time(), $expiredStep1['token'] );
        $expiredState = App::patient_access()->registration_completion_state( $expiredStep1['user_id'] );
        self::assertTrue( $expiredState['incomplete'] );
        self::assertFalse( $expiredState['has_valid_proof'] );
    }

    public function testSoleActiveOptedOutClinicMatchesNonexistentAcrossAllThreePathsAndOptedInWitnessPassesUnderSameSpy(): void {
        $db = App::db();
        $db->update( 'cpms_clinics', [ 'status' => 'archived' ], [ 'id' => $this->clinicListedB ] );
        $db->update( 'cpms_clinics', [ 'status' => 'archived' ], [ 'id' => $this->clinicPrivate ] );
        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' ) . " WHERE status = 'active'" ),
            'Precondition: exactly one active clinic exists.'
        );
        App::settingsFactory()->forClinic( $this->clinicListed )->set( 'public.listing_opt_in', false );
        \ClinicCore\Settings\Settings::flushCache();

        $optInMobile  = '09125550181';
        $optOutMobile = '09125550183';
        $this->seedOtp( $this->clinicListed, $optOutMobile, '334455', OtpService::PURPOSE_VERIFY_MOBILE );

        $settingsTable      = $db->table( 'cpms_settings' );
        $otpTable           = $db->table( 'cpms_otp_tokens' );
        $usersTable         = $db->wpdb()->users;
        $patientsTable      = $db->table( 'cpms_patients' );
        $loginsTable        = $db->table( 'cpms_patient_logins' );
        $optInSettingReads  = 0;
        $otpInserts         = 0;
        $patientUserInserts = 0;
        $optInSpy           = static function ( $query ) use (
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
            return (string) preg_replace( '/cpms-patient-(?:otp|reg)-[a-z0-9]+/i', 'cpms-patient-ID', $html );
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

            $optedOutVerifyResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/verify',
                [
                    'clinic_id' => $this->clinicListed,
                    'mobile'    => $optOutMobile,
                    'code'      => '334455',
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                ]
            );
            self::assertSame( 404, $optedOutVerifyResp->get_status() );
            $optedOutVerifyData = $optedOutVerifyResp->get_data();
            self::assertIsArray( $optedOutVerifyData );
            self::assertArrayHasKey( 'code', $optedOutVerifyData );
            self::assertArrayHasKey( 'message', $optedOutVerifyData );
            self::assertArrayHasKey( 'data', $optedOutVerifyData );
            self::assertSame( 'CLINIC_NOT_FOUND', $optedOutVerifyData['code'] );

            $optedOutRegResp = $this->dispatchRestWithNonce(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => $this->clinicListed,
                    'mobile'      => $optOutMobile,
                    'given_name'  => 'سارا',
                    'family_name' => 'مخفی',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ],
                wp_create_nonce( 'wp_rest' )
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

            $nonexistentVerifyResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/verify',
                [
                    'clinic_id' => 999999,
                    'mobile'    => $optOutMobile,
                    'code'      => '334455',
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                ]
            );
            self::assertSame( 404, $nonexistentVerifyResp->get_status() );
            $nonexistentVerifyData = $nonexistentVerifyResp->get_data();
            self::assertIsArray( $nonexistentVerifyData );
            self::assertArrayHasKey( 'code', $nonexistentVerifyData );
            self::assertArrayHasKey( 'message', $nonexistentVerifyData );
            self::assertArrayHasKey( 'data', $nonexistentVerifyData );
            self::assertSame( 'CLINIC_NOT_FOUND', $nonexistentVerifyData['code'] );
            self::assertSame( $optedOutVerifyData, $nonexistentVerifyData );

            $nonexistentRegResp = $this->dispatchRestWithNonce(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => 999999,
                    'mobile'      => $optOutMobile,
                    'given_name'  => 'سارا',
                    'family_name' => 'مخفی',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ],
                wp_create_nonce( 'wp_rest' )
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

            $witnessStep1 = $this->performStep1VerifyRest( $this->clinicListed, $optInMobile, '556677' );
            self::assertSame( 200, $witnessStep1['response']->get_status() );
            self::assertTrue( $witnessStep1['session_issued'] );

            $witnessRegResp = $this->dispatchRestWithNonce(
                'POST',
                '/clinic/v1/patient/register',
                [
                    'clinic_id'   => $this->clinicListed,
                    'mobile'      => $optInMobile,
                    'given_name'  => 'سارا',
                    'family_name' => 'ثبت‌شده',
                    'national_id' => self::NID_NEW,
                    'birth_date'  => '1993-07-21',
                ],
                $witnessStep1['rest_nonce']
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

        $date1           = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $date2           = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date3           = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $date4           = gmdate( 'Y-m-d', time() + 6 * 86400 );
        $slotStaff       = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $slotHoldFail    = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00' );
        $slotConfirmFail = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date3, '11:00:00' );
        $slotWitness     = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date4, '12:00:00' );

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

    public function testPatientRescheduleRefusesIncompleteProfileInsideTransactionAllowsStaffAndCancelAndPassesCompleteWitness(): void {
        $db = App::db();

        // Incomplete proved patient: valid national_id, MISSING birth_date.
        $legacyMobile    = '09125550174';
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

        // Complete-profile proved patient witness.
        $completeMobile    = '09125550175';
        $completePatientId = $this->insertPatient( $this->clinicListed, $completeMobile, 'کامل', 'جابه', self::NID_CAP, '1992-05-10' );
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

        $date1              = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $date2              = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slotLegacyOld      = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $slotLegacyTarget   = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '10:00:00' );
        $slotStaffTarget    = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '11:00:00' );
        $slotCompleteOld    = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '09:00:00' );
        $slotCompleteTarget = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00' );

        $booking = App::bookingService();
        ScopeContext::set( ClinicScope::forClinic( $this->clinicListed, $this->locationListed )->withOrganization( $this->orgId ) );
        try {
            $legacyAppt   = $booking->createByStaff( $staffUserId, $legacyPatientId, $this->clinicianListed, $date1, '09:00:00', 'نوبت ناقص', $slotLegacyOld );
            $completeAppt = $booking->createByStaff( $staffUserId, $completePatientId, $this->clinicianListed, $date2, '09:00:00', 'نوبت کامل', $slotCompleteOld );
        } finally {
            ScopeContext::clear();
        }
        self::assertArrayHasKey( 'appointment_id', $legacyAppt );
        self::assertArrayHasKey( 'appointment_id', $completeAppt );
        $legacyApptId   = (int) $legacyAppt['appointment_id'];
        $completeApptId = (int) $completeAppt['appointment_id'];

        $apptsTable = $db->table( 'cpms_appointments' );
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $patsTable  = $db->table( 'cpms_patients' );
        $auditTable = $db->table( 'cpms_audit_logs' );

        $apptsBefore  = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable );
        $holdsBefore  = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable );
        $auditsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $auditTable );

        $txStarts             = 0;
        $txRollbacks          = 0;
        $txCommits            = 0;
        $txActive             = false;
        $patientGateLockReads = 0;
        $patientGateReadsInTx = 0;
        $apptInserts          = 0;
        $apptUpdates          = 0;
        $holdInserts          = 0;
        $slotCapacityWrites   = 0;
        $auditInserts         = 0;

        $rescheduleSpy = static function ( $query ) use (
            $patsTable,
            $holdsTable,
            $apptsTable,
            $slotsTable,
            $auditTable,
            &$txStarts,
            &$txRollbacks,
            &$txCommits,
            &$txActive,
            &$patientGateLockReads,
            &$patientGateReadsInTx,
            &$apptInserts,
            &$apptUpdates,
            &$holdInserts,
            &$slotCapacityWrites,
            &$auditInserts
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
            if ( 1 === preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $apptsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$apptInserts;
            }
            if ( 1 === preg_match( '/^UPDATE\s+`?' . preg_quote( $apptsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$apptUpdates;
            }
            if ( 1 === preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $holdsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$holdInserts;
            }
            if ( 1 === preg_match( '/^UPDATE\s+`?' . preg_quote( $slotsTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$slotCapacityWrites;
            }
            if ( 1 === preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $auditTable, '/' ) . '`?\b/i', $sql ) ) {
                ++$auditInserts;
            }

            return $query;
        };

        add_filter( 'query', $rescheduleSpy, 5 );
        try {
            // (a) Incomplete patient reschedule is denied inside the transaction with zero mutations.
            PatientTwoProofTestHelper::acting_as( $legacyUserId );
            $rescheduleEx = null;
            try {
                $booking->reschedule( $legacyUserId, $legacyApptId, $this->clinicianListed, $date1, '10:00:00', 'resched-legacy-' . uniqid(), $slotLegacyTarget );
            } catch ( BookingException $e ) {
                $rescheduleEx = $e;
            }
            self::assertInstanceOf( BookingException::class, $rescheduleEx );
            self::assertSame( 'CLINIC_VALIDATION_FAILED', $rescheduleEx->errorCode );
            self::assertSame( 400, $rescheduleEx->httpStatus );
            self::assertGreaterThanOrEqual( 1, $txStarts );
            self::assertSame( 1, $patientGateLockReads );
            self::assertSame( 1, $patientGateReadsInTx );
            self::assertGreaterThanOrEqual( 1, $txRollbacks );
            self::assertSame( 0, $apptInserts );
            self::assertSame( 0, $apptUpdates );
            self::assertSame( 0, $holdInserts );
            self::assertSame( 0, $slotCapacityWrites );
            self::assertSame( 0, $auditInserts );
            self::assertSame( $apptsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );
            self::assertSame( $holdsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable ) );
            self::assertSame( $auditsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $auditTable ) );

            $origApptRow = $db->fetchRow( 'SELECT status, rescheduled_to FROM ' . $apptsTable . ' WHERE id = %d', [ $legacyApptId ] );
            self::assertIsArray( $origApptRow );
            self::assertArrayHasKey( 'status', $origApptRow );
            self::assertArrayHasKey( 'rescheduled_to', $origApptRow );
            self::assertSame( 'confirmed', (string) $origApptRow['status'] );
            self::assertNull( $origApptRow['rescheduled_to'] );

            $oldSlotRow = $db->fetchRow( 'SELECT booked_count FROM ' . $slotsTable . ' WHERE id = %d', [ $slotLegacyOld ] );
            self::assertIsArray( $oldSlotRow );
            self::assertArrayHasKey( 'booked_count', $oldSlotRow );
            self::assertSame( 1, (int) $oldSlotRow['booked_count'] );

            $targetSlotRow = $db->fetchRow( 'SELECT booked_count FROM ' . $slotsTable . ' WHERE id = %d', [ $slotLegacyTarget ] );
            self::assertIsArray( $targetSlotRow );
            self::assertArrayHasKey( 'booked_count', $targetSlotRow );
            self::assertSame( 0, (int) $targetSlotRow['booked_count'] );

            // (b) Positive witness under the SAME spy: complete-profile patient reschedule succeeds.
            $txStarts             = 0;
            $txRollbacks          = 0;
            $txCommits            = 0;
            $txActive             = false;
            $patientGateLockReads = 0;
            $patientGateReadsInTx = 0;
            $apptInserts          = 0;
            $apptUpdates          = 0;
            $holdInserts          = 0;
            $slotCapacityWrites   = 0;
            $auditInserts         = 0;

            PatientTwoProofTestHelper::acting_as( $completeUserId );
            $witnessRes = $booking->reschedule( $completeUserId, $completeApptId, $this->clinicianListed, $date2, '10:00:00', 'resched-complete-' . uniqid(), $slotCompleteTarget );
            self::assertArrayHasKey( 'appointment_id', $witnessRes );
            self::assertArrayHasKey( 'previous_appointment_id', $witnessRes );
            self::assertArrayHasKey( 'status', $witnessRes );
            self::assertGreaterThan( 0, (int) $witnessRes['appointment_id'] );
            self::assertSame( $completeApptId, (int) $witnessRes['previous_appointment_id'] );
            self::assertSame( 'confirmed', (string) $witnessRes['status'] );
            self::assertGreaterThanOrEqual( 1, $txCommits );
            self::assertSame( 1, $patientGateLockReads );
            self::assertSame( 1, $patientGateReadsInTx );
            self::assertSame( 1, $apptInserts );
            self::assertGreaterThanOrEqual( 1, $apptUpdates );
            self::assertGreaterThanOrEqual( 2, $slotCapacityWrites );
            self::assertGreaterThanOrEqual( 1, $auditInserts );
            self::assertSame( $apptsBefore + 1, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );

            $completeOldSlot = $db->fetchRow( 'SELECT booked_count FROM ' . $slotsTable . ' WHERE id = %d', [ $slotCompleteOld ] );
            self::assertIsArray( $completeOldSlot );
            self::assertArrayHasKey( 'booked_count', $completeOldSlot );
            self::assertSame( 0, (int) $completeOldSlot['booked_count'] );

            $completeNewSlot = $db->fetchRow( 'SELECT booked_count FROM ' . $slotsTable . ' WHERE id = %d', [ $slotCompleteTarget ] );
            self::assertIsArray( $completeNewSlot );
            self::assertArrayHasKey( 'booked_count', $completeNewSlot );
            self::assertSame( 1, (int) $completeNewSlot['booked_count'] );

            // (c) Staff reschedule for the same incomplete patient succeeds without patient-profile gating.
            $patientGateLockReads = 0;
            $patientGateReadsInTx = 0;
            ScopeContext::set( ClinicScope::forClinic( $this->clinicListed, $this->locationListed )->withOrganization( $this->orgId ) );
            try {
                $staffRes = $booking->rescheduleByStaff( $staffUserId, $legacyApptId, $this->clinicianListed, $date1, '11:00:00', 'resched-staff-' . uniqid(), $slotStaffTarget );
            } finally {
                ScopeContext::clear();
            }
            self::assertArrayHasKey( 'appointment_id', $staffRes );
            self::assertArrayHasKey( 'previous_appointment_id', $staffRes );
            self::assertArrayHasKey( 'status', $staffRes );
            self::assertSame( $legacyApptId, (int) $staffRes['previous_appointment_id'] );
            self::assertSame( 'confirmed', (string) $staffRes['status'] );
            self::assertSame( 0, $patientGateLockReads );

            // (d) Patient cancel by the same incomplete patient still succeeds.
            PatientTwoProofTestHelper::acting_as( $legacyUserId );
            $cancelRes = $booking->cancelByPatient( $legacyUserId, (int) $staffRes['appointment_id'], 'لغو توسط بیمار ناقص' );
            self::assertArrayHasKey( 'appointment_id', $cancelRes );
            self::assertArrayHasKey( 'status', $cancelRes );
            self::assertSame( (int) $staffRes['appointment_id'], (int) $cancelRes['appointment_id'] );
            self::assertSame( 'cancelled_by_patient', (string) $cancelRes['status'] );
        } finally {
            remove_filter( 'query', $rescheduleSpy, 5 );
        }
    }

    public function testNullClinicOtpTokenIsRejectedForRegistrationInAnyClinic(): void {
        $mobile = '09125550191';
        $this->seedOtp( null, $mobile, '445566', OtpService::PURPOSE_VERIFY_MOBILE );

        $usersBefore    = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->wpdb()->users );
        $patientsBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) );
        $loginsBefore   = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) );

        $verifyResp = $this->dispatchRest(
            'POST',
            '/clinic/v1/otp/verify',
            [
                'clinic_id' => $this->clinicListed,
                'mobile'    => $mobile,
                'code'      => '445566',
                'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
            ]
        );
        self::assertSame( 400, $verifyResp->get_status() );
        $verifyData = $verifyResp->get_data();
        self::assertIsArray( $verifyData );
        self::assertArrayHasKey( 'code', $verifyData );
        self::assertSame( 'CLINIC_OTP_INVALID', $verifyData['code'] );

        $resp = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => $mobile,
                'given_name'  => 'تست',
                'family_name' => 'بدون‌کلینیک',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1993-07-21',
            ],
            wp_create_nonce( 'wp_rest' )
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

        // Positive witness: clinic-bound OTP token for the same opted-in clinic succeeds cleanly.
        $witnessMobile = '09125550192';
        $this->seedOtp( $this->clinicListed, $witnessMobile, '556677', OtpService::PURPOSE_VERIFY_MOBILE );
        $witnessStep1 = $this->performStep1VerifyRest( $this->clinicListed, $witnessMobile, '556677' );
        self::assertSame( 200, $witnessStep1['response']->get_status() );
        self::assertTrue( $witnessStep1['session_issued'] );
        $witnessStep2 = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicListed,
                'mobile'      => $witnessMobile,
                'given_name'  => 'شاهد',
                'family_name' => 'کلینیک‌دار',
                'national_id' => self::NID_NEW,
                'birth_date'  => '1993-07-21',
            ],
            $witnessStep1['rest_nonce']
        );
        self::assertSame( 200, $witnessStep2->get_status() );
        $witnessStep2Data = $witnessStep2->get_data();
        self::assertIsArray( $witnessStep2Data );
        self::assertArrayHasKey( 'data', $witnessStep2Data );
        self::assertIsArray( $witnessStep2Data['data'] );
        self::assertArrayHasKey( 'registered', $witnessStep2Data['data'] );
        self::assertTrue( $witnessStep2Data['data']['registered'] );
    }

    public function testConfirmProfileIncompleteDenialPreservesActiveHoldAndCapacityAndReleasesOnlyIdempotency(): void {
        $db  = App::db();
        $now = $db->nowUtcSql();

        $mobile    = '09125550201';
        $patientId = $this->insertPatient(
            $this->clinicListed,
            $mobile,
            'بیمار',
            'ناقص‌الف',
            PatientTwoProofTestHelper::next_valid_national_id(),
            null
        );
        $userId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicListed,
            $patientId,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => true,
            ]
        );

        $slotDate  = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $slotId    = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $slotDate, '09:00:00' );
        $holdToken = 'hold-preserve-a-' . bin2hex( random_bytes( 6 ) );

        $db->update( 'cpms_schedule_slots', [ 'held_count' => 1 ], [ 'id' => $slotId ] );
        $db->insert(
            'cpms_slot_holds',
            [
                'clinic_id'         => $this->clinicListed,
                'slot_id'           => $slotId,
                'holder_wp_user_id' => $userId,
                'holder_mobile'     => $mobile,
                'patient_id'        => $patientId,
                'token'             => $holdToken,
                'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 600 ) . '.000',
                'status'            => 'active',
                'created_at'        => $now,
            ]
        );
        $holdId = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $holdId );

        $unrelatedIdemKey = wp_generate_uuid4();
        $db->insert(
            'cpms_idempotency_keys',
            [
                'key'           => $unrelatedIdemKey,
                'clinic_id'     => $this->clinicListed,
                'wp_user_id'    => $userId,
                'endpoint'      => 'booking/confirm',
                'context_id'    => 0,
                'status'        => 1,
                'response_code' => 200,
                'response_json' => '{"ok":true}',
                'created_at'    => $now,
            ]
        );

        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $apptsTable = $db->table( 'cpms_appointments' );

        $idemKey = wp_generate_uuid4();
        $caught  = null;
        try {
            App::bookingService()->confirm( $holdToken, $userId, null, $idemKey );
        } catch ( BookingException $e ) {
            $caught = $e;
        }

        self::assertInstanceOf( BookingException::class, $caught );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $caught->errorCode );
        self::assertSame( 400, $caught->httpStatus );
        self::assertTrue( $caught->is_profile_incomplete() );
        self::assertSame( [], $caught->data );

        $holdAfterService = $db->fetchRow(
            'SELECT id, status, token, slot_id FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdId ]
        );
        self::assertIsArray( $holdAfterService );
        self::assertArrayHasKey( 'id', $holdAfterService );
        self::assertArrayHasKey( 'status', $holdAfterService );
        self::assertArrayHasKey( 'token', $holdAfterService );
        self::assertArrayHasKey( 'slot_id', $holdAfterService );
        self::assertSame( $holdId, (int) $holdAfterService['id'] );
        self::assertSame( 'active', (string) $holdAfterService['status'] );
        self::assertSame( $holdToken, (string) $holdAfterService['token'] );
        self::assertSame( $slotId, (int) $holdAfterService['slot_id'] );

        $slotAfterService = $db->fetchRow(
            'SELECT capacity, booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotId ]
        );
        self::assertIsArray( $slotAfterService );
        self::assertArrayHasKey( 'capacity', $slotAfterService );
        self::assertArrayHasKey( 'booked_count', $slotAfterService );
        self::assertArrayHasKey( 'held_count', $slotAfterService );
        self::assertSame( 2, (int) $slotAfterService['capacity'] );
        self::assertSame( 0, (int) $slotAfterService['booked_count'] );
        self::assertSame( 1, (int) $slotAfterService['held_count'] );

        $releasedIdemCount = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $idemKey, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertSame( 0, $releasedIdemCount );

        $unrelatedIdemRow = $db->fetchRow(
            'SELECT status, response_code FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d LIMIT 1',
            [ $unrelatedIdemKey, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertIsArray( $unrelatedIdemRow );
        self::assertArrayHasKey( 'status', $unrelatedIdemRow );
        self::assertArrayHasKey( 'response_code', $unrelatedIdemRow );
        self::assertSame( 1, (int) $unrelatedIdemRow['status'] );
        self::assertSame( 200, (int) $unrelatedIdemRow['response_code'] );

        $apptCountAfterService = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d',
            [ $slotId ]
        );
        self::assertSame( 0, $apptCountAfterService );

        // REST boundary witness: POST /clinic/v1/booking/confirm returns 400 without leaking internal discriminator keys in data, while keeping the same hold active.
        $restIdemKey = wp_generate_uuid4();
        $restReq     = new WP_REST_Request( 'POST', '/clinic/v1/booking/confirm' );
        $restReq->set_header( 'Content-Type', 'application/json' );
        $restReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $restReq->set_header( 'Idempotency-Key', $restIdemKey );
        $restReq->set_body( (string) wp_json_encode( [ 'hold_token' => $holdToken ] ) );
        $restResp = rest_do_request( $restReq );
        self::assertInstanceOf( WP_REST_Response::class, $restResp );
        self::assertSame( 400, $restResp->get_status() );
        $restPayload = $restResp->get_data();
        self::assertIsArray( $restPayload );
        self::assertArrayHasKey( 'code', $restPayload );
        self::assertArrayHasKey( 'data', $restPayload );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $restPayload['code'] );
        self::assertIsArray( $restPayload['data'] );
        self::assertSame( [ 'status' => 400 ], $restPayload['data'] );

        $holdAfterRest = $db->fetchRow(
            'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdId ]
        );
        self::assertIsArray( $holdAfterRest );
        self::assertArrayHasKey( 'status', $holdAfterRest );
        self::assertSame( 'active', (string) $holdAfterRest['status'] );

        $slotAfterRest = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotId ]
        );
        self::assertIsArray( $slotAfterRest );
        self::assertArrayHasKey( 'booked_count', $slotAfterRest );
        self::assertArrayHasKey( 'held_count', $slotAfterRest );
        self::assertSame( 0, (int) $slotAfterRest['booked_count'] );
        self::assertSame( 1, (int) $slotAfterRest['held_count'] );

        $restIdemCount = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $restIdemKey, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertSame( 0, $restIdemCount );
    }

    public function testConfirmNonProfileDenialsStillReleaseHoldAndCapacity(): void {
        $db      = App::db();
        $now     = $db->nowUtcSql();
        $booking = App::bookingService();

        $mobile    = '09125550202';
        $patientId = $this->insertPatient(
            $this->clinicListed,
            $mobile,
            'بیمار',
            'کامل‌ب',
            PatientTwoProofTestHelper::next_valid_national_id(),
            '1990-04-12'
        );
        $userId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicListed,
            $patientId,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => true,
            ]
        );

        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $apptsTable = $db->table( 'cpms_appointments' );

        // Case 1: 409 CLINIC_SLOT_TAKEN inside confirm() transaction (slot closed after hold) releases hold + capacity.
        $date1 = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $slot1 = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00' );
        $hold1 = $booking->hold( $userId, $this->clinicianListed, $date1, '09:00:00', $slot1, $patientId, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $hold1 );
        $holdToken1 = (string) $hold1['hold_token'];

        $holdRow1Before = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdToken1 ]
        );
        self::assertIsArray( $holdRow1Before );
        self::assertArrayHasKey( 'id', $holdRow1Before );
        self::assertArrayHasKey( 'status', $holdRow1Before );
        self::assertSame( 'active', (string) $holdRow1Before['status'] );
        $hold1Id = (int) $holdRow1Before['id'];

        $db->update( 'cpms_schedule_slots', [ 'is_open' => 0 ], [ 'id' => $slot1 ] );

        $idemKey1 = wp_generate_uuid4();
        $ex1      = null;
        try {
            $booking->confirm( $holdToken1, $userId, null, $idemKey1 );
        } catch ( BookingException $e ) {
            $ex1 = $e;
        }

        self::assertInstanceOf( BookingException::class, $ex1 );
        self::assertSame( 'CLINIC_SLOT_TAKEN', $ex1->errorCode );
        self::assertSame( 409, $ex1->httpStatus );
        self::assertFalse( $ex1->is_profile_incomplete() );

        $holdRow1After = $db->fetchRow(
            'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $hold1Id ]
        );
        self::assertIsArray( $holdRow1After );
        self::assertArrayHasKey( 'status', $holdRow1After );
        self::assertSame( 'released', (string) $holdRow1After['status'] );

        $slotRow1After = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slot1 ]
        );
        self::assertIsArray( $slotRow1After );
        self::assertArrayHasKey( 'booked_count', $slotRow1After );
        self::assertArrayHasKey( 'held_count', $slotRow1After );
        self::assertSame( 0, (int) $slotRow1After['booked_count'] );
        self::assertSame( 0, (int) $slotRow1After['held_count'] );

        $idemCount1 = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $idemKey1, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertSame( 0, $idemCount1 );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slot1 ] )
        );

        // Case 2: 400 CLINIC_VALIDATION_FAILED non-profile denial inside confirm() transaction (nonexistent DST local time) ALSO releases hold + capacity.
        $db->insert(
            'cpms_locations',
            [
                'clinic_id'  => $this->clinicListed,
                'name'       => 'DST Loc S2',
                'slug'       => 'dst-loc-s2-' . bin2hex( random_bytes( 4 ) ),
                'timezone'   => 'America/New_York',
                'is_primary' => 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $dstLocationId = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $dstLocationId );
        $slot2 = $this->insertSlot( $this->clinicListed, $dstLocationId, $this->clinicianListed, '2027-03-14', '02:30:00' );
        self::assertGreaterThan( 0, $slot2 );
        $holdToken2 = 'hold-dst-b-' . bin2hex( random_bytes( 6 ) );

        $db->update( 'cpms_schedule_slots', [ 'held_count' => 1 ], [ 'id' => $slot2 ] );
        $db->insert(
            'cpms_slot_holds',
            [
                'clinic_id'         => $this->clinicListed,
                'slot_id'           => $slot2,
                'holder_wp_user_id' => $userId,
                'holder_mobile'     => $mobile,
                'patient_id'        => $patientId,
                'token'             => $holdToken2,
                'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 600 ) . '.000',
                'status'            => 'active',
                'created_at'        => $now,
            ]
        );
        $hold2Id = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $hold2Id );

        $idemKey2 = wp_generate_uuid4();
        $ex2      = null;
        try {
            $booking->confirm( $holdToken2, $userId, null, $idemKey2 );
        } catch ( BookingException $e ) {
            $ex2 = $e;
        }

        self::assertInstanceOf( BookingException::class, $ex2 );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $ex2->errorCode );
        self::assertSame( 400, $ex2->httpStatus );
        self::assertFalse( $ex2->is_profile_incomplete() );

        $holdRow2After = $db->fetchRow(
            'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $hold2Id ]
        );
        self::assertIsArray( $holdRow2After );
        self::assertArrayHasKey( 'status', $holdRow2After );
        self::assertSame( 'released', (string) $holdRow2After['status'] );

        $slotRow2After = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slot2 ]
        );
        self::assertIsArray( $slotRow2After );
        self::assertArrayHasKey( 'booked_count', $slotRow2After );
        self::assertArrayHasKey( 'held_count', $slotRow2After );
        self::assertSame( 0, (int) $slotRow2After['booked_count'] );
        self::assertSame( 0, (int) $slotRow2After['held_count'] );

        $idemCount2 = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $idemKey2, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertSame( 0, $idemCount2 );
    }

    public function testConfirmRetryAfterProfileCompletionSucceedsWithSameHoldIdAndSameIdempotencyKey(): void {
        $db      = App::db();
        $booking = App::bookingService();

        $mobile    = '09125550203';
        $patientId = $this->insertPatient(
            $this->clinicListed,
            $mobile,
            'بیمار',
            'تکمیلی',
            PatientTwoProofTestHelper::next_valid_national_id(),
            '1991-06-15'
        );
        $userId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicListed,
            $patientId,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => true,
            ]
        );

        $slotDate = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slotId   = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $slotDate, '10:00:00' );
        $holdRes  = $booking->hold( $userId, $this->clinicianListed, $slotDate, '10:00:00', $slotId, $patientId, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdRes );
        $holdToken = (string) $holdRes['hold_token'];

        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $patsTable  = $db->table( 'cpms_patients' );

        $initialHoldRow = $db->fetchRow(
            'SELECT id, status, token FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdToken ]
        );
        self::assertIsArray( $initialHoldRow );
        self::assertArrayHasKey( 'id', $initialHoldRow );
        self::assertArrayHasKey( 'status', $initialHoldRow );
        $originalHoldId = (int) $initialHoldRow['id'];
        self::assertGreaterThan( 0, $originalHoldId );
        self::assertSame( 'active', (string) $initialHoldRow['status'] );

        // Simulate legacy incomplete profile state at confirm time (birth_date NULL).
        $db->query( 'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d', [ $patientId ] );
        $incompletePatientRow = $db->fetchRow(
            'SELECT birth_date FROM ' . $patsTable . ' WHERE id = %d LIMIT 1',
            [ $patientId ]
        );
        self::assertIsArray( $incompletePatientRow );
        self::assertArrayHasKey( 'birth_date', $incompletePatientRow );
        self::assertNull( $incompletePatientRow['birth_date'] );

        $sharedIdemKey = wp_generate_uuid4();
        $firstEx       = null;
        try {
            $booking->confirm( $holdToken, $userId, 'تلاش اول', $sharedIdemKey );
        } catch ( BookingException $e ) {
            $firstEx = $e;
        }

        self::assertInstanceOf( BookingException::class, $firstEx );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $firstEx->errorCode );
        self::assertSame( 400, $firstEx->httpStatus );
        self::assertTrue( $firstEx->is_profile_incomplete() );

        $holdAfterDenial = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $originalHoldId ]
        );
        self::assertIsArray( $holdAfterDenial );
        self::assertArrayHasKey( 'id', $holdAfterDenial );
        self::assertArrayHasKey( 'status', $holdAfterDenial );
        self::assertSame( $originalHoldId, (int) $holdAfterDenial['id'] );
        self::assertSame( 'active', (string) $holdAfterDenial['status'] );

        // Patient completes profile through the real self-service PatientService::updateMe() flow.
        $updatedView = App::patientService()->updateMe( $userId, [ 'birth_date' => '1991-06-15' ] );
        self::assertIsArray( $updatedView );
        $completedPatientRow = $db->fetchRow(
            'SELECT birth_date FROM ' . $patsTable . ' WHERE id = %d LIMIT 1',
            [ $patientId ]
        );
        self::assertIsArray( $completedPatientRow );
        self::assertArrayHasKey( 'birth_date', $completedPatientRow );
        self::assertSame( '1991-06-15', (string) $completedPatientRow['birth_date'] );

        // Retry confirm with the SAME hold_token (same hold id) AND the SAME Idempotency-Key.
        $confirmed = $booking->confirm( $holdToken, $userId, 'تلاش دوم موفق', $sharedIdemKey );
        self::assertIsArray( $confirmed );
        self::assertArrayHasKey( 'appointment_id', $confirmed );
        self::assertArrayHasKey( 'status', $confirmed );
        $appointmentId = (int) $confirmed['appointment_id'];
        self::assertGreaterThan( 0, $appointmentId );
        self::assertSame( 'confirmed', (string) $confirmed['status'] );

        $holdAfterConfirm = $db->fetchRow(
            'SELECT id, status, token FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $originalHoldId ]
        );
        self::assertIsArray( $holdAfterConfirm );
        self::assertArrayHasKey( 'id', $holdAfterConfirm );
        self::assertArrayHasKey( 'status', $holdAfterConfirm );
        self::assertArrayHasKey( 'token', $holdAfterConfirm );
        self::assertSame( $originalHoldId, (int) $holdAfterConfirm['id'] );
        self::assertSame( 'converted', (string) $holdAfterConfirm['status'] );
        self::assertSame( $holdToken, (string) $holdAfterConfirm['token'] );

        $totalSlotHolds = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $holdsTable . ' WHERE slot_id = %d',
            [ $slotId ]
        );
        self::assertSame( 1, $totalSlotHolds );

        $slotAfterConfirm = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotId ]
        );
        self::assertIsArray( $slotAfterConfirm );
        self::assertArrayHasKey( 'booked_count', $slotAfterConfirm );
        self::assertArrayHasKey( 'held_count', $slotAfterConfirm );
        self::assertSame( 1, (int) $slotAfterConfirm['booked_count'] );
        self::assertSame( 0, (int) $slotAfterConfirm['held_count'] );

        $apptRow = $db->fetchRow(
            'SELECT id, slot_id, patient_id, status FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $appointmentId ]
        );
        self::assertIsArray( $apptRow );
        self::assertArrayHasKey( 'id', $apptRow );
        self::assertArrayHasKey( 'slot_id', $apptRow );
        self::assertArrayHasKey( 'patient_id', $apptRow );
        self::assertArrayHasKey( 'status', $apptRow );
        self::assertSame( $appointmentId, (int) $apptRow['id'] );
        self::assertSame( $slotId, (int) $apptRow['slot_id'] );
        self::assertSame( $patientId, (int) $apptRow['patient_id'] );
        self::assertSame( 'confirmed', (string) $apptRow['status'] );

        $completedIdemRow = $db->fetchRow(
            'SELECT status, response_code FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d LIMIT 1',
            [ $sharedIdemKey, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertIsArray( $completedIdemRow );
        self::assertArrayHasKey( 'status', $completedIdemRow );
        self::assertArrayHasKey( 'response_code', $completedIdemRow );
        self::assertSame( 1, (int) $completedIdemRow['status'] );
        self::assertSame( 200, (int) $completedIdemRow['response_code'] );
    }

    public function testProfileIncompleteDiscriminatorIsTrueOnlyForTwoProfileGateCallersAndFalseElsewhere(): void {
        $db      = App::db();
        $wpdb    = $db->wpdb();
        $booking = App::bookingService();

        // 1) Direct constructors BookingException::of() and new BookingException() always keep discriminator false, even with identical code and message.
        $sameMessage = 'پروفایل شما ناقص است — ابتدا اطلاعات پروفایل خود را تکمیل کنید';
        $viaOf       = BookingException::of( 'CLINIC_VALIDATION_FAILED', $sameMessage, 400 );
        $viaNew      = new BookingException( 'CLINIC_VALIDATION_FAILED', $sameMessage, 400 );
        $viaNamed    = BookingException::profile_incomplete( 'CLINIC_VALIDATION_FAILED', $sameMessage, 400 );
        self::assertFalse( $viaOf->is_profile_incomplete() );
        self::assertFalse( $viaNew->is_profile_incomplete() );
        self::assertTrue( $viaNamed->is_profile_incomplete() );
        self::assertSame( [], $viaNamed->data );

        // 2) Caller #1: assert_patient_profile_complete_for_booking(null) and (0) set discriminator = true.
        $gateById = new \ReflectionMethod( $booking, 'assert_patient_profile_complete_for_booking' );
        $exNullId = null;
        try {
            $gateById->invoke( $booking, null );
        } catch ( BookingException $e ) {
            $exNullId = $e;
        }
        self::assertInstanceOf( BookingException::class, $exNullId );
        self::assertTrue( $exNullId->is_profile_incomplete() );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $exNullId->errorCode );
        self::assertSame( 400, $exNullId->httpStatus );

        $exZeroId = null;
        try {
            $gateById->invoke( $booking, 0 );
        } catch ( BookingException $e ) {
            $exZeroId = $e;
        }
        self::assertInstanceOf( BookingException::class, $exZeroId );
        self::assertTrue( $exZeroId->is_profile_incomplete() );

        // 3) Caller #2: assert_patient_profile_complete_row(null) and incomplete row set discriminator = true (tested through real confirm()).
        $mobile    = '09125550204';
        $patientId = $this->insertPatient(
            $this->clinicListed,
            $mobile,
            'بیمار',
            'جداساز',
            PatientTwoProofTestHelper::next_valid_national_id(),
            '1990-02-02'
        );
        $userId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicListed,
            $patientId,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => true,
            ]
        );

        $slotDate = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slotId   = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $slotDate, '11:00:00' );
        $holdRes  = $booking->hold( $userId, $this->clinicianListed, $slotDate, '11:00:00', $slotId, $patientId, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdRes );
        $holdToken = (string) $holdRes['hold_token'];

        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $patsTable  = $db->table( 'cpms_patients' );

        $holdRowInitial = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdToken ]
        );
        self::assertIsArray( $holdRowInitial );
        self::assertArrayHasKey( 'id', $holdRowInitial );
        $holdId = (int) $holdRowInitial['id'];

        $expectedPatientLockSql = $db->prepare(
            'SELECT id, first_name, last_name, mobile, national_id, birth_date FROM ' . $patsTable . ' WHERE id = %d LIMIT 1 FOR UPDATE',
            [ $patientId ]
        );

        // 3a) Exact-SQL absent-row filter counted once reaches assert_patient_profile_complete_row(null) -> discriminator = true.
        $absentMatches = 0;
        $absentFilter  = static function ( string $query ) use ( $expectedPatientLockSql, $patsTable, &$absentMatches ): string {
            if ( $query !== $expectedPatientLockSql ) {
                return $query;
            }
            ++$absentMatches;
            if ( 1 === $absentMatches ) {
                return 'SELECT id FROM ' . $patsTable . ' WHERE 1 = 0 LIMIT 1';
            }

            return $query;
        };

        $absentIdemKey = wp_generate_uuid4();
        $exAbsentRow   = null;
        add_filter( 'query', $absentFilter, 5 );
        try {
            try {
                $booking->confirm( $holdToken, $userId, null, $absentIdemKey );
            } catch ( BookingException $e ) {
                $exAbsentRow = $e;
            }
        } finally {
            remove_filter( 'query', $absentFilter, 5 );
        }

        self::assertSame( 1, $absentMatches );
        self::assertInstanceOf( BookingException::class, $exAbsentRow );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $exAbsentRow->errorCode );
        self::assertSame( 400, $exAbsentRow->httpStatus );
        self::assertTrue( $exAbsentRow->is_profile_incomplete() );

        $holdAfterAbsent = $db->fetchRow( 'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1', [ $holdId ] );
        self::assertIsArray( $holdAfterAbsent );
        self::assertArrayHasKey( 'status', $holdAfterAbsent );
        self::assertSame( 'active', (string) $holdAfterAbsent['status'] );

        // 4) Exact-SQL DB-error filter counted once on the SAME assert_patient_profile_complete_for_booking read throws LOCK_BUSY_CODE with discriminator = false; assertions outside callback.
        $errorMatches  = 0;
        $errorFilter   = static function ( string $query ) use ( $expectedPatientLockSql, $patsTable, &$errorMatches ): string {
            if ( $query !== $expectedPatientLockSql ) {
                return $query;
            }
            ++$errorMatches;
            if ( 1 === $errorMatches ) {
                return 'SELECT 1 FROM ' . $patsTable . ' WHERE cpms_fault_injection_no_such_column = 1 LIMIT 1';
            }

            return $query;
        };
        $busyIdemKey  = wp_generate_uuid4();
        $exDbBusy     = null;
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $errorFilter, 5 );
        try {
            try {
                $booking->confirm( $holdToken, $userId, null, $busyIdemKey );
            } catch ( BookingException $e ) {
                $exDbBusy = $e;
            }
        } finally {
            remove_filter( 'query', $errorFilter, 5 );
            $wpdb->suppress_errors( $prevSuppress );
        }

        self::assertSame( 1, $errorMatches );
        self::assertInstanceOf( BookingException::class, $exDbBusy );
        self::assertSame( 'CLINIC_BOOKING_BUSY', $exDbBusy->errorCode );
        self::assertSame( 503, $exDbBusy->httpStatus );
        self::assertFalse( $exDbBusy->is_profile_incomplete() );

        $holdAfterBusy = $db->fetchRow( 'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1', [ $holdId ] );
        self::assertIsArray( $holdAfterBusy );
        self::assertArrayHasKey( 'status', $holdAfterBusy );
        self::assertSame( 'active', (string) $holdAfterBusy['status'] );

        $slotAfterBusy = $db->fetchRow( 'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1', [ $slotId ] );
        self::assertIsArray( $slotAfterBusy );
        self::assertArrayHasKey( 'booked_count', $slotAfterBusy );
        self::assertArrayHasKey( 'held_count', $slotAfterBusy );
        self::assertSame( 0, (int) $slotAfterBusy['booked_count'] );
        self::assertSame( 1, (int) $slotAfterBusy['held_count'] );

        $busyIdemCount = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $busyIdemKey, 'booking/confirm', $userId, 0, $this->clinicListed ]
        );
        self::assertSame( 0, $busyIdemCount );

        // 5) Other real BookingService::confirm() BookingException denials also keep discriminator = false.
        $exMissingIdem = null;
        try {
            $booking->confirm( $holdToken, $userId, null, '' );
        } catch ( BookingException $e ) {
            $exMissingIdem = $e;
        }
        self::assertInstanceOf( BookingException::class, $exMissingIdem );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $exMissingIdem->errorCode );
        self::assertFalse( $exMissingIdem->is_profile_incomplete() );

        $exMissingHold = null;
        try {
            $booking->confirm( 'nonexistent-hold-' . bin2hex( random_bytes( 4 ) ), $userId, null, wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $exMissingHold = $e;
        }
        self::assertInstanceOf( BookingException::class, $exMissingHold );
        self::assertSame( 'CLINIC_NOT_FOUND', $exMissingHold->errorCode );
        self::assertFalse( $exMissingHold->is_profile_incomplete() );

        // 6) Caller #2 second branch: incomplete patient row (birth_date = NULL) through real confirm() sets discriminator = true.
        $db->query( 'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d', [ $patientId ] );
        $incompleteIdemKey = wp_generate_uuid4();
        $exIncompleteRow   = null;
        try {
            $booking->confirm( $holdToken, $userId, null, $incompleteIdemKey );
        } catch ( BookingException $e ) {
            $exIncompleteRow = $e;
        }
        self::assertInstanceOf( BookingException::class, $exIncompleteRow );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $exIncompleteRow->errorCode );
        self::assertSame( 400, $exIncompleteRow->httpStatus );
        self::assertTrue( $exIncompleteRow->is_profile_incomplete() );
    }

    public function testPatientReschedulePathRemainsUnchangedBeforeAndAfterProfileIncompleteDenial(): void {
        $db      = App::db();
        $booking = App::bookingService();

        $mobile    = '09125550205';
        $patientId = $this->insertPatient(
            $this->clinicListed,
            $mobile,
            'بیمار',
            'جابه‌جایی',
            PatientTwoProofTestHelper::next_valid_national_id(),
            null
        );
        $userId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicListed,
            $patientId,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $staffUserId = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_SECRETARY ] );
        App::membership_service()->create_membership( $this->clinicListed, $staffUserId, RolesAndCapabilities::ROLE_SECRETARY );

        $dateOld    = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $dateTarget = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slotOld    = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateOld, '09:00:00' );
        $slotTarget = $this->insertSlot( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateTarget, '10:00:00' );

        ScopeContext::set( ClinicScope::forClinic( $this->clinicListed, $this->locationListed )->withOrganization( $this->orgId ) );
        try {
            $created = $booking->createByStaff( $staffUserId, $patientId, $this->clinicianListed, $dateOld, '09:00:00', 'نوبت اولیه', $slotOld );
        } finally {
            ScopeContext::clear();
        }
        self::assertArrayHasKey( 'appointment_id', $created );
        $origApptId = (int) $created['appointment_id'];
        self::assertGreaterThan( 0, $origApptId );

        $apptsTable = $db->table( 'cpms_appointments' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );

        // Assert DB state BEFORE patient reschedule attempt.
        $apptsCountBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable );
        $holdsCountBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable );

        $origBefore = $db->fetchRow(
            'SELECT status, rescheduled_to, slot_id FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $origApptId ]
        );
        self::assertIsArray( $origBefore );
        self::assertArrayHasKey( 'status', $origBefore );
        self::assertArrayHasKey( 'rescheduled_to', $origBefore );
        self::assertArrayHasKey( 'slot_id', $origBefore );
        self::assertSame( 'confirmed', (string) $origBefore['status'] );
        self::assertNull( $origBefore['rescheduled_to'] );
        self::assertSame( $slotOld, (int) $origBefore['slot_id'] );

        $slotOldBefore = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotOld ]
        );
        self::assertIsArray( $slotOldBefore );
        self::assertArrayHasKey( 'booked_count', $slotOldBefore );
        self::assertArrayHasKey( 'held_count', $slotOldBefore );
        self::assertSame( 1, (int) $slotOldBefore['booked_count'] );
        self::assertSame( 0, (int) $slotOldBefore['held_count'] );

        $slotTargetBefore = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTarget ]
        );
        self::assertIsArray( $slotTargetBefore );
        self::assertArrayHasKey( 'booked_count', $slotTargetBefore );
        self::assertArrayHasKey( 'held_count', $slotTargetBefore );
        self::assertSame( 0, (int) $slotTargetBefore['booked_count'] );
        self::assertSame( 0, (int) $slotTargetBefore['held_count'] );

        $reschedIdemKey = wp_generate_uuid4();
        $idemBefore     = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $reschedIdemKey, 'booking/reschedule', $userId, $origApptId, $this->clinicListed ]
        );
        self::assertSame( 0, $idemBefore );

        // Attempt patient reschedule while profile is incomplete.
        PatientTwoProofTestHelper::acting_as( $userId );
        $denied = null;
        try {
            $booking->reschedule( $userId, $origApptId, $this->clinicianListed, $dateTarget, '10:00:00', $reschedIdemKey, $slotTarget );
        } catch ( BookingException $e ) {
            $denied = $e;
        }

        self::assertInstanceOf( BookingException::class, $denied );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $denied->errorCode );
        self::assertSame( 400, $denied->httpStatus );
        self::assertTrue( $denied->is_profile_incomplete() );

        // Assert DB state AFTER denied reschedule matches pre-call state and releases idempotency lock.
        self::assertSame( $apptsCountBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );
        self::assertSame( $holdsCountBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable ) );

        $origAfterDeny = $db->fetchRow(
            'SELECT status, rescheduled_to, slot_id FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $origApptId ]
        );
        self::assertIsArray( $origAfterDeny );
        self::assertArrayHasKey( 'status', $origAfterDeny );
        self::assertArrayHasKey( 'rescheduled_to', $origAfterDeny );
        self::assertArrayHasKey( 'slot_id', $origAfterDeny );
        self::assertSame( 'confirmed', (string) $origAfterDeny['status'] );
        self::assertNull( $origAfterDeny['rescheduled_to'] );
        self::assertSame( $slotOld, (int) $origAfterDeny['slot_id'] );

        $slotOldAfterDeny = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotOld ]
        );
        self::assertIsArray( $slotOldAfterDeny );
        self::assertArrayHasKey( 'booked_count', $slotOldAfterDeny );
        self::assertArrayHasKey( 'held_count', $slotOldAfterDeny );
        self::assertSame( 1, (int) $slotOldAfterDeny['booked_count'] );
        self::assertSame( 0, (int) $slotOldAfterDeny['held_count'] );

        $slotTargetAfterDeny = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTarget ]
        );
        self::assertIsArray( $slotTargetAfterDeny );
        self::assertArrayHasKey( 'booked_count', $slotTargetAfterDeny );
        self::assertArrayHasKey( 'held_count', $slotTargetAfterDeny );
        self::assertSame( 0, (int) $slotTargetAfterDeny['booked_count'] );
        self::assertSame( 0, (int) $slotTargetAfterDeny['held_count'] );

        $idemAfterDeny = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
            [ $reschedIdemKey, 'booking/reschedule', $userId, $origApptId, $this->clinicListed ]
        );
        self::assertSame( 0, $idemAfterDeny );

        // Positive witness: after completing profile, retry with the SAME Idempotency-Key succeeds cleanly.
        App::patientService()->updateMe( $userId, [ 'birth_date' => '1991-09-10' ] );
        $rescheduled = $booking->reschedule( $userId, $origApptId, $this->clinicianListed, $dateTarget, '10:00:00', $reschedIdemKey, $slotTarget );
        self::assertArrayHasKey( 'appointment_id', $rescheduled );
        self::assertArrayHasKey( 'previous_appointment_id', $rescheduled );
        self::assertArrayHasKey( 'status', $rescheduled );
        $newApptId = (int) $rescheduled['appointment_id'];
        self::assertGreaterThan( 0, $newApptId );
        self::assertSame( $origApptId, (int) $rescheduled['previous_appointment_id'] );
        self::assertSame( 'confirmed', (string) $rescheduled['status'] );

        $origAfterSuccess = $db->fetchRow(
            'SELECT status, rescheduled_to FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $origApptId ]
        );
        self::assertIsArray( $origAfterSuccess );
        self::assertArrayHasKey( 'status', $origAfterSuccess );
        self::assertArrayHasKey( 'rescheduled_to', $origAfterSuccess );
        self::assertSame( 'rescheduled', (string) $origAfterSuccess['status'] );
        self::assertSame( $newApptId, (int) $origAfterSuccess['rescheduled_to'] );

        $newApptRow = $db->fetchRow(
            'SELECT status, rescheduled_from, slot_id FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $newApptId ]
        );
        self::assertIsArray( $newApptRow );
        self::assertArrayHasKey( 'status', $newApptRow );
        self::assertArrayHasKey( 'rescheduled_from', $newApptRow );
        self::assertArrayHasKey( 'slot_id', $newApptRow );
        self::assertSame( 'confirmed', (string) $newApptRow['status'] );
        self::assertSame( $origApptId, (int) $newApptRow['rescheduled_from'] );
        self::assertSame( $slotTarget, (int) $newApptRow['slot_id'] );

        $slotOldAfterSuccess = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotOld ]
        );
        self::assertIsArray( $slotOldAfterSuccess );
        self::assertArrayHasKey( 'booked_count', $slotOldAfterSuccess );
        self::assertArrayHasKey( 'held_count', $slotOldAfterSuccess );
        self::assertSame( 0, (int) $slotOldAfterSuccess['booked_count'] );
        self::assertSame( 0, (int) $slotOldAfterSuccess['held_count'] );

        $slotTargetAfterSuccess = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTarget ]
        );
        self::assertIsArray( $slotTargetAfterSuccess );
        self::assertArrayHasKey( 'booked_count', $slotTargetAfterSuccess );
        self::assertArrayHasKey( 'held_count', $slotTargetAfterSuccess );
        self::assertSame( 1, (int) $slotTargetAfterSuccess['booked_count'] );
        self::assertSame( 0, (int) $slotTargetAfterSuccess['held_count'] );

        $idemAfterSuccess = $db->fetchRow(
            'SELECT status, response_code FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d LIMIT 1',
            [ $reschedIdemKey, 'booking/reschedule', $userId, $origApptId, $this->clinicListed ]
        );
        self::assertIsArray( $idemAfterSuccess );
        self::assertArrayHasKey( 'status', $idemAfterSuccess );
        self::assertArrayHasKey( 'response_code', $idemAfterSuccess );
        self::assertSame( 1, (int) $idemAfterSuccess['status'] );
        self::assertSame( 200, (int) $idemAfterSuccess['response_code'] );
    }

    /**
     * Round 2 T1 (deadline): after a profile-incomplete denial on confirm(), the hold
     * keeps its ORIGINAL expires_at unchanged; once test-fixture time travel backdates
     * expires_at into the past (FLAKE FIX (round 3)), both
     * real expiry paths — lazy expiry inside BookingService::confirm() (:867-888) and
     * the scheduled sweeper HoldsExpireHandler (:32-69) — fail confirm with
     * 422 CLINIC_HOLD_EXPIRED, transition the hold out of 'active' to 'expired',
     * decrement held_count to 0, and leave capacity genuinely free so another proved
     * patient can hold and confirm that capacity=1 slot.
     */
    public function testRound2T1HoldDeadlinePreservesOriginalExpiresAtAndReleasesCapacityOnExpiryForAnotherPatient(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $patsTable  = $db->table( 'cpms_patients' );
        $booking    = App::bookingService();
        // FLAKE FIX (round 3): retain normal configured booking.hold_ttl_sec (600s default; no 4s override).

        $mobileA  = '09125550201';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'مهلت', 'اول', $nidA, '1990-02-10' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $mobileB  = '09125550202';
        $nidB     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientB = $this->insertPatient( $this->clinicListed, $mobileB, 'مهلت', 'دوم', $nidB, '1992-08-14' );
        self::assertGreaterThan( 0, $patientB );
        $userB = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userB,
            $this->clinicListed,
            $patientB,
            [
                'mobile'             => $mobileB,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $dateLazy  = gmdate( 'Y-m-d', time() + 14 * 86400 );
        $dateSweep = gmdate( 'Y-m-d', time() + 15 * 86400 );
        $slotLazy  = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateLazy, '09:00:00', 1 );
        $slotSweep = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateSweep, '10:00:00', 1 );
        self::assertGreaterThan( 0, $slotLazy );
        self::assertGreaterThan( 0, $slotSweep );

        // FLAKE FIX (round 3): removed $settings->set( 'booking.hold_ttl_sec', 4 ) + try/finally wrapper.
        // --- Sub-case 1: Lazy expiry release inside BookingService::confirm() (:867-888) ---
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdLazy = $booking->hold( $userA, $this->clinicianListed, $dateLazy, '09:00:00', $slotLazy, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdLazy );
        self::assertArrayHasKey( 'expires_at', $holdLazy );
        $tokenLazy = (string) $holdLazy['hold_token'];

        $holdRowBefore = $db->fetchRow(
            'SELECT id, status, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenLazy ]
        );
        self::assertIsArray( $holdRowBefore );
        self::assertArrayHasKey( 'id', $holdRowBefore );
        self::assertArrayHasKey( 'status', $holdRowBefore );
        self::assertArrayHasKey( 'expires_at', $holdRowBefore );
        $holdIdLazy      = (int) $holdRowBefore['id'];
        $origExpiresLazy = (string) $holdRowBefore['expires_at'];
        self::assertSame( 'active', (string) $holdRowBefore['status'] );

        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patientA ]
        );

        $idemLazy   = wp_generate_uuid4();
        $denyIncomp = null;
        try {
            $booking->confirm( $tokenLazy, $userA, 'پیش از انقضا', $idemLazy );
        } catch ( BookingException $e ) {
            $denyIncomp = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyIncomp );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $denyIncomp->errorCode );
        self::assertSame( 400, $denyIncomp->httpStatus );
        self::assertTrue( $denyIncomp->is_profile_incomplete() );

        // Hold stays active and ORIGINAL expires_at is byte-for-byte unchanged (verified BEFORE test backdate).
        $holdRowAfterDeny = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdLazy ]
        );
        self::assertIsArray( $holdRowAfterDeny );
        self::assertArrayHasKey( 'status', $holdRowAfterDeny );
        self::assertArrayHasKey( 'expires_at', $holdRowAfterDeny );
        self::assertSame( 'active', (string) $holdRowAfterDeny['status'] );
        self::assertSame( $origExpiresLazy, (string) $holdRowAfterDeny['expires_at'] );

        $slotLazyHeld = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotLazy ]
        );
        self::assertIsArray( $slotLazyHeld );
        self::assertArrayHasKey( 'held_count', $slotLazyHeld );
        self::assertArrayHasKey( 'booked_count', $slotLazyHeld );
        self::assertSame( 1, (int) $slotLazyHeld['held_count'] );
        self::assertSame( 0, (int) $slotLazyHeld['booked_count'] );

        // FLAKE FIX (round 3): replace sleep( 5 ) with deterministic test-fixture time travel on holdIdLazy.
        // Even with birth_date still NULL, confirm() checks expiry (:867) BEFORE profile (:1015) and lazily releases capacity.
        $pastExpiresLazy = $this->backdateActiveHoldExpiryForTest( $holdIdLazy );

        $denyExpiredLazy = null;
        try {
            $booking->confirm( $tokenLazy, $userA, 'پس از انقضا', $idemLazy );
        } catch ( BookingException $e ) {
            $denyExpiredLazy = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyExpiredLazy );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $denyExpiredLazy->errorCode );
        self::assertSame( 422, $denyExpiredLazy->httpStatus );
        self::assertFalse( $denyExpiredLazy->is_profile_incomplete() );

        $holdRowLazyExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdLazy ]
        );
        self::assertIsArray( $holdRowLazyExpired );
        self::assertArrayHasKey( 'status', $holdRowLazyExpired );
        self::assertArrayHasKey( 'expires_at', $holdRowLazyExpired );
        self::assertNotSame( 'active', (string) $holdRowLazyExpired['status'] );
        self::assertSame( 'expired', (string) $holdRowLazyExpired['status'] );
        // FLAKE FIX (round 3): lazy expiry transition leaves the backdated expires_at untouched.
        self::assertSame( $pastExpiresLazy, (string) $holdRowLazyExpired['expires_at'] );

        $slotLazyFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotLazy ]
        );
        self::assertIsArray( $slotLazyFreed );
        self::assertArrayHasKey( 'held_count', $slotLazyFreed );
        self::assertArrayHasKey( 'booked_count', $slotLazyFreed );
        self::assertSame( 0, (int) $slotLazyFreed['held_count'] );
        self::assertSame( 0, (int) $slotLazyFreed['booked_count'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotLazy ] )
        );

        // Capacity on slotLazy (capacity=1) is truly free: Patient B holds and confirms it.
        PatientTwoProofTestHelper::acting_as( $userB );
        $holdBLazy    = $booking->hold( $userB, $this->clinicianListed, $dateLazy, '09:00:00', $slotLazy, $patientB, $this->locationListed );
        $confirmBLazy = $booking->confirm( (string) $holdBLazy['hold_token'], $userB, 'رزرو بیمار دوم', wp_generate_uuid4() );
        self::assertArrayHasKey( 'appointment_id', $confirmBLazy );
        $apptBLazyId = (int) $confirmBLazy['appointment_id'];
        self::assertGreaterThan( 0, $apptBLazyId );

        $slotLazyBooked = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotLazy ]
        );
        self::assertIsArray( $slotLazyBooked );
        self::assertArrayHasKey( 'held_count', $slotLazyBooked );
        self::assertArrayHasKey( 'booked_count', $slotLazyBooked );
        self::assertSame( 0, (int) $slotLazyBooked['held_count'] );
        self::assertSame( 1, (int) $slotLazyBooked['booked_count'] );

        $apptRowLazy = $db->fetchRow(
            'SELECT patient_id, wp_user_id, status FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $apptBLazyId ]
        );
        self::assertIsArray( $apptRowLazy );
        self::assertArrayHasKey( 'patient_id', $apptRowLazy );
        self::assertArrayHasKey( 'wp_user_id', $apptRowLazy );
        self::assertArrayHasKey( 'status', $apptRowLazy );
        self::assertSame( $patientB, (int) $apptRowLazy['patient_id'] );
        self::assertSame( $userB, (int) $apptRowLazy['wp_user_id'] );
        self::assertSame( 'confirmed', (string) $apptRowLazy['status'] );

        // --- Sub-case 2: Scheduled sweeper HoldsExpireHandler (:32-69) ---
        PatientTwoProofTestHelper::acting_as( $userA );
        App::patientService()->updateMe( $userA, [ 'birth_date' => '1990-02-10' ] );

        $holdSweep = $booking->hold( $userA, $this->clinicianListed, $dateSweep, '10:00:00', $slotSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdSweep );
        $tokenSweep = (string) $holdSweep['hold_token'];

        $holdSweepBefore = $db->fetchRow(
            'SELECT id, status, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenSweep ]
        );
        self::assertIsArray( $holdSweepBefore );
        self::assertArrayHasKey( 'id', $holdSweepBefore );
        self::assertArrayHasKey( 'status', $holdSweepBefore );
        self::assertArrayHasKey( 'expires_at', $holdSweepBefore );
        $holdIdSweep      = (int) $holdSweepBefore['id'];
        $origExpiresSweep = (string) $holdSweepBefore['expires_at'];
        self::assertSame( 'active', (string) $holdSweepBefore['status'] );

        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patientA ]
        );

        $idemSweep  = wp_generate_uuid4();
        $denySweep1 = null;
        try {
            $booking->confirm( $tokenSweep, $userA, 'ناقص پیش از کران', $idemSweep );
        } catch ( BookingException $e ) {
            $denySweep1 = $e;
        }
        self::assertInstanceOf( BookingException::class, $denySweep1 );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $denySweep1->errorCode );
        self::assertSame( 400, $denySweep1->httpStatus );

        $holdSweepAfterDeny = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepAfterDeny );
        self::assertArrayHasKey( 'status', $holdSweepAfterDeny );
        self::assertArrayHasKey( 'expires_at', $holdSweepAfterDeny );
        self::assertSame( 'active', (string) $holdSweepAfterDeny['status'] );
        self::assertSame( $origExpiresSweep, (string) $holdSweepAfterDeny['expires_at'] );

        // Complete profile, backdate active hold into the past, then run HoldsExpireHandler.
        App::patientService()->updateMe( $userA, [ 'birth_date' => '1990-02-10' ] );
        // FLAKE FIX (round 3): replace sleep( 5 ) with deterministic test-fixture time travel on holdIdSweep.
        $pastExpiresSweep = $this->backdateActiveHoldExpiryForTest( $holdIdSweep );

        $sweeper = new \ClinicCore\Application\Jobs\HoldsExpireHandler( $db );
        $freed   = $sweeper( [] );
        self::assertGreaterThanOrEqual( 1, $freed );

        $holdSweepExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepExpired );
        self::assertArrayHasKey( 'status', $holdSweepExpired );
        self::assertArrayHasKey( 'expires_at', $holdSweepExpired );
        self::assertNotSame( 'active', (string) $holdSweepExpired['status'] );
        self::assertSame( 'expired', (string) $holdSweepExpired['status'] );
        // FLAKE FIX (round 3): sweeper transition leaves the backdated expires_at untouched.
        self::assertSame( $pastExpiresSweep, (string) $holdSweepExpired['expires_at'] );

        $slotSweepFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotSweep ]
        );
        self::assertIsArray( $slotSweepFreed );
        self::assertArrayHasKey( 'held_count', $slotSweepFreed );
        self::assertArrayHasKey( 'booked_count', $slotSweepFreed );
        self::assertSame( 0, (int) $slotSweepFreed['held_count'] );
        self::assertSame( 0, (int) $slotSweepFreed['booked_count'] );

        // Late confirm by Patient A after sweeper fails with 422 CLINIC_HOLD_EXPIRED without double-release.
        $denySweep2 = null;
        try {
            $booking->confirm( $tokenSweep, $userA, 'تلاش دیرهنگام', $idemSweep );
        } catch ( BookingException $e ) {
            $denySweep2 = $e;
        }
        self::assertInstanceOf( BookingException::class, $denySweep2 );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $denySweep2->errorCode );
        self::assertSame( 422, $denySweep2->httpStatus );

        // Another proved patient (Patient B) can immediately hold and confirm slotSweep.
        PatientTwoProofTestHelper::acting_as( $userB );
        $holdBSweep    = $booking->hold( $userB, $this->clinicianListed, $dateSweep, '10:00:00', $slotSweep, $patientB, $this->locationListed );
        $confirmBSweep = $booking->confirm( (string) $holdBSweep['hold_token'], $userB, 'رزرو بیمار دوم پس از کران', wp_generate_uuid4() );
        self::assertArrayHasKey( 'appointment_id', $confirmBSweep );
        self::assertGreaterThan( 0, (int) $confirmBSweep['appointment_id'] );

        $slotSweepBooked = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotSweep ]
        );
        self::assertIsArray( $slotSweepBooked );
        self::assertArrayHasKey( 'held_count', $slotSweepBooked );
        self::assertArrayHasKey( 'booked_count', $slotSweepBooked );
        self::assertSame( 0, (int) $slotSweepBooked['held_count'] );
        self::assertSame( 1, (int) $slotSweepBooked['booked_count'] );
        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotSweep ] )
        );
        // FLAKE FIX (round 3): no booking.hold_ttl_sec restore needed (normal configured TTL never overridden).
    }

    /**
     * PR25-ROUND12 (C4): a hold whose DATETIME(3) fractional expires_at falls inside the
     * current whole second (e.g. ':10.001' with decision now ':10.437') must be recognized
     * as expired AND have its held capacity released in the same second:
     *  - Sub-case 1: BookingService::confirm() lazy expiry transition (guarded UPDATE);
     *  - Sub-case 2: HoldsExpireHandler sweeper (SELECT + guarded UPDATE).
     * Pre-fix, confirm() recognized expiry with a microsecond clock but transitioned with
     * the whole-second nowUtcSql() ('.000'), so the guarded UPDATE matched zero rows and
     * held_count stayed allocated; the sweeper also compared against the whole-second .000.
     * The backdate is bound to the CURRENT whole second with '.001' and the wait is bounded
     * (<= 20 ms busy-wait), so the test is deterministic with no wall-clock TTL sleeps.
     */
    public function testRound2T1FractionalHoldExpiryBoundaryReleasesHeldCapacityInSameSecond(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $booking    = App::bookingService();

        $mobileA  = '09125550241';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'کسر', 'انقضا', $nidA, '1991-03-15' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $dateFracLazy  = gmdate( 'Y-m-d', time() + 18 * 86400 );
        $dateFracSweep = gmdate( 'Y-m-d', time() + 19 * 86400 );
        $slotFracLazy  = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateFracLazy, '11:00:00', 1 );
        $slotFracSweep = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateFracSweep, '12:00:00', 1 );
        self::assertGreaterThan( 0, $slotFracLazy );
        self::assertGreaterThan( 0, $slotFracSweep );

        // --- Sub-case 1: lazy expiry release inside BookingService::confirm() (same whole second) ---
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdLazy = $booking->hold( $userA, $this->clinicianListed, $dateFracLazy, '11:00:00', $slotFracLazy, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdLazy );
        $tokenLazy = (string) $holdLazy['hold_token'];

        $holdRowBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenLazy ]
        );
        self::assertIsArray( $holdRowBefore );
        self::assertSame( 'active', (string) $holdRowBefore['status'] );
        $holdIdLazy = (int) $holdRowBefore['id'];

        $fracExpiresLazy = $this->fractionalExpiryWithinCurrentSecondForTest( $holdIdLazy );

        $denyExpiredFrac = null;
        try {
            $booking->confirm( $tokenLazy, $userA, 'کسر پس از انقضا', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $denyExpiredFrac = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyExpiredFrac );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $denyExpiredFrac->errorCode );
        self::assertSame( 422, $denyExpiredFrac->httpStatus );

        $holdRowLazyExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdLazy ]
        );
        self::assertIsArray( $holdRowLazyExpired );
        self::assertSame( 'expired', (string) $holdRowLazyExpired['status'] );
        self::assertSame( $fracExpiresLazy, (string) $holdRowLazyExpired['expires_at'] );

        $slotFracLazyFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotFracLazy ]
        );
        self::assertIsArray( $slotFracLazyFreed );
        self::assertSame( 0, (int) $slotFracLazyFreed['held_count'] );
        self::assertSame( 0, (int) $slotFracLazyFreed['booked_count'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotFracLazy ] )
        );

        // --- Sub-case 2: sweeper releases the same fractional boundary in the same second ---
        $holdSweep = $booking->hold( $userA, $this->clinicianListed, $dateFracSweep, '12:00:00', $slotFracSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdSweep );
        $tokenSweep = (string) $holdSweep['hold_token'];

        $holdSweepBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenSweep ]
        );
        self::assertIsArray( $holdSweepBefore );
        self::assertSame( 'active', (string) $holdSweepBefore['status'] );
        $holdIdSweep = (int) $holdSweepBefore['id'];

        $fracExpiresSweep = $this->fractionalExpiryWithinCurrentSecondForTest( $holdIdSweep );

        $sweeper = new \ClinicCore\Application\Jobs\HoldsExpireHandler( $db );
        $freed   = $sweeper( [] );
        self::assertGreaterThanOrEqual( 1, $freed );

        $holdSweepExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepExpired );
        self::assertSame( 'expired', (string) $holdSweepExpired['status'] );
        self::assertSame( $fracExpiresSweep, (string) $holdSweepExpired['expires_at'] );

        $slotFracSweepFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotFracSweep ]
        );
        self::assertIsArray( $slotFracSweepFreed );
        self::assertSame( 0, (int) $slotFracSweepFreed['held_count'] );
        self::assertSame( 0, (int) $slotFracSweepFreed['booked_count'] );
    }

    /**
     * Bind an active hold's expires_at to a fractional DATETIME(3) value ('.001') that sits
     * INSIDE the current whole second — i.e. in the past by the decision clock, yet sharing
     * its whole-second prefix with a whole-second nowUtcSql() ('.000'). Pre-fix, the
     * whole-second guarded UPDATE could never match this row. A bounded busy-wait
     * (<= 20 ms) makes the binding deterministic with no wall-clock TTL sleep.
     */
    private function fractionalExpiryWithinCurrentSecondForTest( int $holdId ): string {
        while ( fmod( microtime( true ), 1.0 ) < 0.020 ) {
            usleep( 1000 );
        }
        $expiresAt = gmdate( 'Y-m-d H:i:s', time() ) . '.001';
        $affected  = App::db()->execute(
            'UPDATE ' . App::db()->table( 'cpms_slot_holds' ) . " SET expires_at = %s WHERE id = %d AND status = 'active'",
            [ $expiresAt, $holdId ]
        );
        self::assertSame( 1, $affected );

        return $expiresAt;
    }

    /**
     * PR25-ROUND13 (C4, BEFORE the exact instant): a hold whose DATETIME(3) fractional
     * expires_at is comfortably in the FUTURE must be left untouched — the sweeper must
     * not expire it and confirm() must not take the expiry branch:
     *  - Sub-case 1: HoldsExpireHandler runs while the hold is unexpired — the row stays
     *    `active`, held_count stays 1, expires_at is unchanged;
     *  - Sub-case 2: confirm() on the unexpired hold succeeds (appointment_id > 0), the
     *    hold row becomes `converted`, expires_at unchanged, proving confirm() never
     *    threw CLINIC_HOLD_EXPIRED for a clearly-future expires_at.
     * The hold keeps its default hold_ttl_sec (~600 s in the future); no sleeps.
     */
    public function testRound2T1HoldBeforeExactInstantSweeperLeavesActiveAndConfirmSucceeds(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $booking    = App::bookingService();

        $mobileA  = '09125550251';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'پیش', 'کسر', $nidA, '1991-04-20' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $dateFutureSweep = gmdate( 'Y-m-d', time() + 22 * 86400 );
        $dateFutureLazy  = gmdate( 'Y-m-d', time() + 21 * 86400 );
        $slotFutureSweep = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateFutureSweep, '14:00:00', 1 );
        $slotFutureLazy  = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateFutureLazy, '13:00:00', 1 );
        self::assertGreaterThan( 0, $slotFutureSweep );
        self::assertGreaterThan( 0, $slotFutureLazy );

        // --- Sub-case 1: sweeper leaves an unexpired hold untouched ---
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdSweep = $booking->hold( $userA, $this->clinicianListed, $dateFutureSweep, '14:00:00', $slotFutureSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdSweep );
        $tokenSweep = (string) $holdSweep['hold_token'];

        $holdRowBefore = $db->fetchRow(
            'SELECT id, status, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenSweep ]
        );
        self::assertIsArray( $holdRowBefore );
        self::assertSame( 'active', (string) $holdRowBefore['status'] );
        self::assertGreaterThan( time() + 30, strtotime( (string) $holdRowBefore['expires_at'] ) );

        $sweeper = new \ClinicCore\Application\Jobs\HoldsExpireHandler( $db );
        $sweeper( [] );

        $holdRowAfter = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ (int) $holdRowBefore['id'] ]
        );
        self::assertIsArray( $holdRowAfter );
        self::assertSame( 'active', (string) $holdRowAfter['status'] );
        self::assertSame( (string) $holdRowBefore['expires_at'], (string) $holdRowAfter['expires_at'] );

        $slotSweepRow = $db->fetchRow(
            'SELECT held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotFutureSweep ]
        );
        self::assertIsArray( $slotSweepRow );
        self::assertSame( 1, (int) $slotSweepRow['held_count'] );

        // --- Sub-case 2: confirm() on an unexpired hold creates the appointment ---
        $holdLazy = $booking->hold( $userA, $this->clinicianListed, $dateFutureLazy, '13:00:00', $slotFutureLazy, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdLazy );
        $tokenLazy = (string) $holdLazy['hold_token'];

        $holdLazyRow = $db->fetchRow(
            'SELECT id, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenLazy ]
        );
        self::assertIsArray( $holdLazyRow );

        $confirm = null;
        $deny    = null;
        try {
            $confirm = $booking->confirm( $tokenLazy, $userA, 'پیش از کران', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $deny = $e;
        }
        self::assertNull( $deny, 'confirm() must not throw for an unexpired hold, got ' . ( null !== $deny ? $deny->errorCode : 'none' ) );
        self::assertIsArray( $confirm );
        self::assertArrayHasKey( 'appointment_id', $confirm );
        self::assertGreaterThan( 0, (int) $confirm['appointment_id'] );

        $holdLazyAfter = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ (int) $holdLazyRow['id'] ]
        );
        self::assertIsArray( $holdLazyAfter );
        self::assertSame( 'converted', (string) $holdLazyAfter['status'] );
        self::assertSame( (string) $holdLazyRow['expires_at'], (string) $holdLazyAfter['expires_at'] );
    }

    /**
     * PR25-ROUND13 (C4, 1 ms BEFORE the exact instant, SAME whole second — tightest
     * deterministic neighbour; equality itself is not deterministically testable without
     * clock injection, so this bounds the boundary from below): expires_at is bound to
     * floor((now - 1 ms) to milliseconds), always inside the same whole second (guarded
     * by fmod >= 0.002, <= 2 ms busy-wait):
     *  - Sub-case 1 (lazy): confirm() throws 422 CLINIC_HOLD_EXPIRED, the row becomes
     *    `expired`, held_count returns to 0, expires_at unchanged;
     *  - Sub-case 2 (sweeper): HoldsExpireHandler expires the row identically.
     * Round 12's test covers the same-second boundary with a >= 19 ms margin; this adds
     * the 1 ms adjacency only.
     */
    public function testRound2T1OneMillisecondBeforeInstantSameSecondConfirmAndSweeperRelease(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $booking    = App::bookingService();

        $mobileA  = '09125550252';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'یک', 'کسر', $nidA, '1991-05-16' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $dateTightLazy  = gmdate( 'Y-m-d', time() + 23 * 86400 );
        $dateTightSweep = gmdate( 'Y-m-d', time() + 24 * 86400 );
        $slotTightLazy  = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateTightLazy, '15:00:00', 1 );
        $slotTightSweep = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateTightSweep, '16:00:00', 1 );
        self::assertGreaterThan( 0, $slotTightLazy );
        self::assertGreaterThan( 0, $slotTightSweep );

        // --- Sub-case 1: lazy expiry release inside confirm() (1 ms before, same whole second) ---
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdLazy = $booking->hold( $userA, $this->clinicianListed, $dateTightLazy, '15:00:00', $slotTightLazy, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdLazy );
        $tokenLazy = (string) $holdLazy['hold_token'];

        $holdRowBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenLazy ]
        );
        self::assertIsArray( $holdRowBefore );
        self::assertSame( 'active', (string) $holdRowBefore['status'] );
        $holdIdLazy = (int) $holdRowBefore['id'];

        $fracExpiresLazy = $this->fractionalExpiryOneMillisecondBeforeNowForTest( $holdIdLazy );

        $denyExpiredTight = null;
        try {
            $booking->confirm( $tokenLazy, $userA, 'کسر پس از انقضا', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $denyExpiredTight = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyExpiredTight );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $denyExpiredTight->errorCode );
        self::assertSame( 422, $denyExpiredTight->httpStatus );

        $holdRowLazyExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdLazy ]
        );
        self::assertIsArray( $holdRowLazyExpired );
        self::assertSame( 'expired', (string) $holdRowLazyExpired['status'] );
        self::assertSame( $fracExpiresLazy, (string) $holdRowLazyExpired['expires_at'] );

        $slotTightLazyFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTightLazy ]
        );
        self::assertIsArray( $slotTightLazyFreed );
        self::assertSame( 0, (int) $slotTightLazyFreed['held_count'] );
        self::assertSame( 0, (int) $slotTightLazyFreed['booked_count'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotTightLazy ] )
        );

        // --- Sub-case 2: sweeper releases the same 1 ms-tight boundary ---
        $holdSweep = $booking->hold( $userA, $this->clinicianListed, $dateTightSweep, '16:00:00', $slotTightSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdSweep );
        $tokenSweep = (string) $holdSweep['hold_token'];

        $holdSweepBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenSweep ]
        );
        self::assertIsArray( $holdSweepBefore );
        self::assertSame( 'active', (string) $holdSweepBefore['status'] );
        $holdIdSweep = (int) $holdSweepBefore['id'];

        $fracExpiresSweep = $this->fractionalExpiryOneMillisecondBeforeNowForTest( $holdIdSweep );

        $sweeper = new \ClinicCore\Application\Jobs\HoldsExpireHandler( $db );
        $freed   = $sweeper( [] );
        self::assertGreaterThanOrEqual( 1, $freed );

        $holdSweepExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepExpired );
        self::assertSame( 'expired', (string) $holdSweepExpired['status'] );
        self::assertSame( $fracExpiresSweep, (string) $holdSweepExpired['expires_at'] );

        $slotTightSweepFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTightSweep ]
        );
        self::assertIsArray( $slotTightSweepFreed );
        self::assertSame( 0, (int) $slotTightSweepFreed['held_count'] );
        self::assertSame( 0, (int) $slotTightSweepFreed['booked_count'] );
    }

    /**
     * PR25-ROUND13 (C4, AFTER the exact instant, PREVIOUS whole second): expires_at is
     * bound to time()-1 at '.500' — no busy-wait, fully deterministic:
     *  - Sub-case 1 (lazy): confirm() throws 422 CLINIC_HOLD_EXPIRED, the row becomes
     *    `expired`, held_count returns to 0, expires_at unchanged;
     *  - Sub-case 2 (sweeper): HoldsExpireHandler expires the row identically;
     *  - Sub-case 3 (no revival/extension): a fresh hold() by the same subject mints a
     *    NEW token and leaves the expired row untouched (status `expired`, expires_at
     *    byte-identical) — it is not reused as an active row.
     */
    public function testRound2T1PreviousWholeSecondFractionalExpiryConfirmAndSweeperRelease(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $booking    = App::bookingService();

        $mobileA  = '09125550253';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'قبل', 'کسر', $nidA, '1991-07-22' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $datePrevLazy  = gmdate( 'Y-m-d', time() + 25 * 86400 );
        $datePrevSweep = gmdate( 'Y-m-d', time() + 26 * 86400 );
        $slotPrevLazy  = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $datePrevLazy, '17:00:00', 1 );
        $slotPrevSweep = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $datePrevSweep, '18:00:00', 1 );
        self::assertGreaterThan( 0, $slotPrevLazy );
        self::assertGreaterThan( 0, $slotPrevSweep );

        // --- Sub-case 1: lazy expiry release inside confirm() (previous whole second) ---
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdLazy = $booking->hold( $userA, $this->clinicianListed, $datePrevLazy, '17:00:00', $slotPrevLazy, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdLazy );
        $tokenLazy = (string) $holdLazy['hold_token'];

        $holdRowBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenLazy ]
        );
        self::assertIsArray( $holdRowBefore );
        self::assertSame( 'active', (string) $holdRowBefore['status'] );
        $holdIdLazy = (int) $holdRowBefore['id'];

        $fracExpiresLazy = $this->fractionalExpiryPreviousWholeSecondForTest( $holdIdLazy );

        $denyExpiredPrev = null;
        try {
            $booking->confirm( $tokenLazy, $userA, 'کسر پس از انقضا', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $denyExpiredPrev = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyExpiredPrev );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $denyExpiredPrev->errorCode );
        self::assertSame( 422, $denyExpiredPrev->httpStatus );

        $holdRowLazyExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdLazy ]
        );
        self::assertIsArray( $holdRowLazyExpired );
        self::assertSame( 'expired', (string) $holdRowLazyExpired['status'] );
        self::assertSame( $fracExpiresLazy, (string) $holdRowLazyExpired['expires_at'] );

        $slotPrevLazyFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotPrevLazy ]
        );
        self::assertIsArray( $slotPrevLazyFreed );
        self::assertSame( 0, (int) $slotPrevLazyFreed['held_count'] );
        self::assertSame( 0, (int) $slotPrevLazyFreed['booked_count'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotPrevLazy ] )
        );

        // --- Sub-case 2: sweeper releases the same previous-second boundary ---
        $holdSweep = $booking->hold( $userA, $this->clinicianListed, $datePrevSweep, '18:00:00', $slotPrevSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdSweep );
        $tokenSweep = (string) $holdSweep['hold_token'];

        $holdSweepBefore = $db->fetchRow(
            'SELECT id, status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $tokenSweep ]
        );
        self::assertIsArray( $holdSweepBefore );
        self::assertSame( 'active', (string) $holdSweepBefore['status'] );
        $holdIdSweep = (int) $holdSweepBefore['id'];

        $fracExpiresSweep = $this->fractionalExpiryPreviousWholeSecondForTest( $holdIdSweep );

        $sweeper = new \ClinicCore\Application\Jobs\HoldsExpireHandler( $db );
        $freed   = $sweeper( [] );
        self::assertGreaterThanOrEqual( 1, $freed );

        $holdSweepExpired = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepExpired );
        self::assertSame( 'expired', (string) $holdSweepExpired['status'] );
        self::assertSame( $fracExpiresSweep, (string) $holdSweepExpired['expires_at'] );

        $slotPrevSweepFreed = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotPrevSweep ]
        );
        self::assertIsArray( $slotPrevSweepFreed );
        self::assertSame( 0, (int) $slotPrevSweepFreed['held_count'] );
        self::assertSame( 0, (int) $slotPrevSweepFreed['booked_count'] );

        // --- Sub-case 3: a fresh hold() by the same subject does not revive the expired row ---
        $holdAgain = $booking->hold( $userA, $this->clinicianListed, $datePrevSweep, '18:00:00', $slotPrevSweep, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdAgain );
        self::assertNotSame( $tokenSweep, (string) $holdAgain['hold_token'] );

        $holdSweepAfterRehold = $db->fetchRow(
            'SELECT status, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdSweep ]
        );
        self::assertIsArray( $holdSweepAfterRehold );
        self::assertSame( 'expired', (string) $holdSweepAfterRehold['status'] );
        self::assertSame( $fracExpiresSweep, (string) $holdSweepAfterRehold['expires_at'] );
    }

    /**
     * Bind an active hold's expires_at to the millisecond instant 1 ms BEFORE the current
     * instant (floored to ms), guaranteed to stay inside the same whole second by the
     * <= 2 ms busy-wait guard. Deterministic; no wall-clock TTL sleep.
     */
    private function fractionalExpiryOneMillisecondBeforeNowForTest( int $holdId ): string {
        while ( fmod( microtime( true ), 1.0 ) < 0.002 ) {
            usleep( 500 );
        }
        $expiresAt = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
            ->modify( '-1 millisecond' )
            ->format( 'Y-m-d H:i:s.v' );
        $affected  = App::db()->execute(
            'UPDATE ' . App::db()->table( 'cpms_slot_holds' ) . " SET expires_at = %s WHERE id = %d AND status = 'active'",
            [ $expiresAt, $holdId ]
        );
        self::assertSame( 1, $affected );

        return $expiresAt;
    }

    /**
     * Bind an active hold's expires_at to time()-1 at a fixed '.500' fraction — the
     * PREVIOUS whole second, fully deterministic with no busy-wait.
     */
    private function fractionalExpiryPreviousWholeSecondForTest( int $holdId ): string {
        $expiresAt = gmdate( 'Y-m-d H:i:s', time() - 1 ) . '.500';
        $affected  = App::db()->execute(
            'UPDATE ' . App::db()->table( 'cpms_slot_holds' ) . " SET expires_at = %s WHERE id = %d AND status = 'active'",
            [ $expiresAt, $holdId ]
        );
        self::assertSame( 1, $affected );

        return $expiresAt;
    }

    /**
     * Round 2 T2 (no capture): on a capacity=1 slot, after Patient A's hold is preserved
     * across a profile-incomplete confirm() denial, proved Patient B cannot hold
     * (409 CLINIC_SLOT_TAKEN) or confirm (403 CLINIC_PERMISSION_DENIED) that slot,
     * slot counters continue to reflect Patient A's active hold (held_count=1, booked_count=0),
     * and once Patient A completes the profile and confirms, exactly one appointment exists.
     */
    public function testRound2T2CapacityOnePreservedHoldBlocksSecondPatientUntilFirstCompletesProfileAndConfirms(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $patsTable  = $db->table( 'cpms_patients' );
        $booking    = App::bookingService();

        $mobileA  = '09125550203';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'نگهدارنده', 'اول', $nidA, '1991-04-22' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $mobileB  = '09125550204';
        $nidB     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientB = $this->insertPatient( $this->clinicListed, $mobileB, 'رقیب', 'دوم', $nidB, '1993-11-05' );
        self::assertGreaterThan( 0, $patientB );
        $userB = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userB,
            $this->clinicListed,
            $patientB,
            [
                'mobile'             => $mobileB,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $slotDate = gmdate( 'Y-m-d', time() + 16 * 86400 );
        $slotCap1 = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $slotDate, '11:00:00', 1 );
        self::assertGreaterThan( 0, $slotCap1 );

        // 1) Patient A holds capacity=1 slot, then hits profile-incomplete denial on confirm().
        PatientTwoProofTestHelper::acting_as( $userA );
        $holdA = $booking->hold( $userA, $this->clinicianListed, $slotDate, '11:00:00', $slotCap1, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdA );
        $holdTokenA = (string) $holdA['hold_token'];

        $holdRowA = $db->fetchRow(
            'SELECT id, status, holder_wp_user_id, patient_id, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdTokenA ]
        );
        self::assertIsArray( $holdRowA );
        self::assertArrayHasKey( 'id', $holdRowA );
        $holdIdA     = (int) $holdRowA['id'];
        $origExpires = (string) $holdRowA['expires_at'];

        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patientA ]
        );

        $idemKeyA   = wp_generate_uuid4();
        $deniedInco = null;
        try {
            $booking->confirm( $holdTokenA, $userA, 'ناقص اول', $idemKeyA );
        } catch ( BookingException $e ) {
            $deniedInco = $e;
        }
        self::assertInstanceOf( BookingException::class, $deniedInco );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $deniedInco->errorCode );
        self::assertSame( 400, $deniedInco->httpStatus );
        self::assertTrue( $deniedInco->is_profile_incomplete() );

        // 2) Second proved patient cannot hold (service + REST -> 409 CLINIC_SLOT_TAKEN) or confirm (403 CLINIC_PERMISSION_DENIED).
        PatientTwoProofTestHelper::acting_as( $userB );

        $holdBEx = null;
        try {
            $booking->hold( $userB, $this->clinicianListed, $slotDate, '11:00:00', $slotCap1, $patientB, $this->locationListed );
        } catch ( BookingException $e ) {
            $holdBEx = $e;
        }
        self::assertInstanceOf( BookingException::class, $holdBEx );
        self::assertSame( 'CLINIC_SLOT_TAKEN', $holdBEx->errorCode );
        self::assertSame( 409, $holdBEx->httpStatus );

        $restHoldB     = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/hold',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => $slotDate,
                'slot_time'    => '11:00:00',
                'slot_id'      => $slotCap1,
                'patient_id'   => $patientB,
                'location_id'  => $this->locationListed,
            ]
        );
        $restHoldBData = $restHoldB->get_data();
        self::assertSame( 409, $restHoldB->get_status() );
        self::assertIsArray( $restHoldBData );
        self::assertArrayHasKey( 'code', $restHoldBData );
        self::assertSame( 'CLINIC_SLOT_TAKEN', (string) $restHoldBData['code'] );

        $confirmBEx = null;
        try {
            $booking->confirm( $holdTokenA, $userB, 'تلاش تصاحب', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $confirmBEx = $e;
        }
        self::assertInstanceOf( BookingException::class, $confirmBEx );
        self::assertSame( 'CLINIC_PERMISSION_DENIED', $confirmBEx->errorCode );
        self::assertSame( 403, $confirmBEx->httpStatus );

        // Slot counters and hold row prove Patient A's hold is still counted and untouched.
        $slotMid = $db->fetchRow(
            'SELECT capacity, held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotCap1 ]
        );
        self::assertIsArray( $slotMid );
        self::assertArrayHasKey( 'capacity', $slotMid );
        self::assertArrayHasKey( 'held_count', $slotMid );
        self::assertArrayHasKey( 'booked_count', $slotMid );
        self::assertSame( 1, (int) $slotMid['capacity'] );
        self::assertSame( 1, (int) $slotMid['held_count'] );
        self::assertSame( 0, (int) $slotMid['booked_count'] );

        $holdMid = $db->fetchRow(
            'SELECT status, holder_wp_user_id, patient_id, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdA ]
        );
        self::assertIsArray( $holdMid );
        self::assertArrayHasKey( 'status', $holdMid );
        self::assertArrayHasKey( 'holder_wp_user_id', $holdMid );
        self::assertArrayHasKey( 'patient_id', $holdMid );
        self::assertArrayHasKey( 'expires_at', $holdMid );
        self::assertSame( 'active', (string) $holdMid['status'] );
        self::assertSame( $userA, (int) $holdMid['holder_wp_user_id'] );
        self::assertSame( $patientA, (int) $holdMid['patient_id'] );
        self::assertSame( $origExpires, (string) $holdMid['expires_at'] );
        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable . ' WHERE slot_id = %d', [ $slotCap1 ] )
        );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotCap1 ] )
        );

        // 3) Patient A completes profile and confirms the SAME hold -> exactly one appointment.
        PatientTwoProofTestHelper::acting_as( $userA );
        App::patientService()->updateMe( $userA, [ 'birth_date' => '1991-04-22' ] );
        $confirmedA = $booking->confirm( $holdTokenA, $userA, 'تکمیل نهایی', $idemKeyA );
        self::assertArrayHasKey( 'appointment_id', $confirmedA );
        $apptIdA = (int) $confirmedA['appointment_id'];
        self::assertGreaterThan( 0, $apptIdA );

        $slotEnd = $db->fetchRow(
            'SELECT capacity, held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotCap1 ]
        );
        self::assertIsArray( $slotEnd );
        self::assertArrayHasKey( 'held_count', $slotEnd );
        self::assertArrayHasKey( 'booked_count', $slotEnd );
        self::assertSame( 0, (int) $slotEnd['held_count'] );
        self::assertSame( 1, (int) $slotEnd['booked_count'] );

        $holdEnd = $db->fetchRow(
            'SELECT status, holder_wp_user_id, patient_id FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdA ]
        );
        self::assertIsArray( $holdEnd );
        self::assertArrayHasKey( 'status', $holdEnd );
        self::assertSame( 'converted', (string) $holdEnd['status'] );

        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotCap1 ] )
        );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE patient_id = %d', [ $patientB ] )
        );
        $apptRow = $db->fetchRow(
            'SELECT patient_id, wp_user_id, status FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $apptIdA ]
        );
        self::assertIsArray( $apptRow );
        self::assertArrayHasKey( 'patient_id', $apptRow );
        self::assertArrayHasKey( 'wp_user_id', $apptRow );
        self::assertArrayHasKey( 'status', $apptRow );
        self::assertSame( $patientA, (int) $apptRow['patient_id'] );
        self::assertSame( $userA, (int) $apptRow['wp_user_id'] );
        self::assertSame( 'confirmed', (string) $apptRow['status'] );
    }

    /**
     * Round 2 T3 (identity): a different proved patient attempting to confirm using
     * Patient A's preserved hold token is denied with 403 CLINIC_PERMISSION_DENIED
     * across BOTH a complete-profile intruder (Patient B) and an incomplete-profile
     * intruder (Patient C, birth_date=NULL), over both REST and BookingService::confirm().
     * The hold is neither released nor converted, held_count stays 1, no appointment is
     * created for either intruder, and Patient A remains the sole bound subject.
     */
    public function testRound2T3DifferentProvedPatientCompleteOrIncompleteCannotConfirmOrMutateFirstPatientHold(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $patsTable  = $db->table( 'cpms_patients' );
        $auditTable = $db->table( 'cpms_audit_logs' );
        $booking    = App::bookingService();

        // Patient A: holder whose profile becomes incomplete before confirm().
        $mobileA  = '09125550205';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'صاحب', 'اصلی', $nidA, '1988-03-19' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        // Patient B: proved intruder with COMPLETE profile.
        $mobileB  = '09125550206';
        $nidB     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientB = $this->insertPatient( $this->clinicListed, $mobileB, 'مزاحم', 'کامل', $nidB, '1989-07-12' );
        self::assertGreaterThan( 0, $patientB );
        $userB = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userB,
            $this->clinicListed,
            $patientB,
            [
                'mobile'             => $mobileB,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        // Patient C: proved intruder with INCOMPLETE profile (birth_date = NULL).
        $mobileC  = '09125550207';
        $nidC     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientC = $this->insertPatient( $this->clinicListed, $mobileC, 'مزاحم', 'ناقص', $nidC, null );
        self::assertGreaterThan( 0, $patientC );
        $userC = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userC,
            $this->clinicListed,
            $patientC,
            [
                'mobile'             => $mobileC,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $slotDate = gmdate( 'Y-m-d', time() + 17 * 86400 );
        $slotId   = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $slotDate, '12:00:00', 1 );
        self::assertGreaterThan( 0, $slotId );

        PatientTwoProofTestHelper::acting_as( $userA );
        $holdA = $booking->hold( $userA, $this->clinicianListed, $slotDate, '12:00:00', $slotId, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $holdA );
        $holdTokenA = (string) $holdA['hold_token'];

        $holdRowInit = $db->fetchRow(
            'SELECT id, expires_at FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdTokenA ]
        );
        self::assertIsArray( $holdRowInit );
        self::assertArrayHasKey( 'id', $holdRowInit );
        self::assertArrayHasKey( 'expires_at', $holdRowInit );
        $holdIdA     = (int) $holdRowInit['id'];
        $origExpires = (string) $holdRowInit['expires_at'];

        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patientA ]
        );

        $idemKeyA   = wp_generate_uuid4();
        $denyOwnerA = null;
        try {
            $booking->confirm( $holdTokenA, $userA, 'ناقص صاحب', $idemKeyA );
        } catch ( BookingException $e ) {
            $denyOwnerA = $e;
        }
        self::assertInstanceOf( BookingException::class, $denyOwnerA );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $denyOwnerA->errorCode );
        self::assertSame( 400, $denyOwnerA->httpStatus );

        // 1) Intruder with COMPLETE profile (Patient B): REST + Service both fail 403 CLINIC_PERMISSION_DENIED.
        PatientTwoProofTestHelper::acting_as( $userB );
        $restIntruderB     = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdTokenA,
                'reason'     => 'مزاحم کامل REST',
            ],
            wp_generate_uuid4()
        );
        $restIntruderBData = $restIntruderB->get_data();
        self::assertSame( 403, $restIntruderB->get_status() );
        self::assertIsArray( $restIntruderBData );
        self::assertArrayHasKey( 'code', $restIntruderBData );
        self::assertSame( 'CLINIC_PERMISSION_DENIED', (string) $restIntruderBData['code'] );

        $svcIntruderB = null;
        try {
            $booking->confirm( $holdTokenA, $userB, 'مزاحم کامل Service', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $svcIntruderB = $e;
        }
        self::assertInstanceOf( BookingException::class, $svcIntruderB );
        self::assertSame( 'CLINIC_PERMISSION_DENIED', $svcIntruderB->errorCode );
        self::assertSame( 403, $svcIntruderB->httpStatus );
        self::assertFalse( $svcIntruderB->is_profile_incomplete() );

        $holdAfterB = $db->fetchRow(
            'SELECT status, holder_wp_user_id, patient_id, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdA ]
        );
        self::assertIsArray( $holdAfterB );
        self::assertArrayHasKey( 'status', $holdAfterB );
        self::assertArrayHasKey( 'holder_wp_user_id', $holdAfterB );
        self::assertArrayHasKey( 'patient_id', $holdAfterB );
        self::assertArrayHasKey( 'expires_at', $holdAfterB );
        self::assertSame( 'active', (string) $holdAfterB['status'] );
        self::assertSame( $userA, (int) $holdAfterB['holder_wp_user_id'] );
        self::assertSame( $patientA, (int) $holdAfterB['patient_id'] );
        self::assertSame( $origExpires, (string) $holdAfterB['expires_at'] );

        // 2) Intruder with INCOMPLETE profile (Patient C, birth_date=NULL): ownership check (:843)
        // runs BEFORE profile check (:1015), so REST + Service both fail 403 CLINIC_PERMISSION_DENIED.
        PatientTwoProofTestHelper::acting_as( $userC );
        $restIntruderC     = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdTokenA,
                'reason'     => 'مزاحم ناقص REST',
            ],
            wp_generate_uuid4()
        );
        $restIntruderCData = $restIntruderC->get_data();
        self::assertSame( 403, $restIntruderC->get_status() );
        self::assertIsArray( $restIntruderCData );
        self::assertArrayHasKey( 'code', $restIntruderCData );
        self::assertSame( 'CLINIC_PERMISSION_DENIED', (string) $restIntruderCData['code'] );

        $svcIntruderC = null;
        try {
            $booking->confirm( $holdTokenA, $userC, 'مزاحم ناقص Service', wp_generate_uuid4() );
        } catch ( BookingException $e ) {
            $svcIntruderC = $e;
        }
        self::assertInstanceOf( BookingException::class, $svcIntruderC );
        self::assertSame( 'CLINIC_PERMISSION_DENIED', $svcIntruderC->errorCode );
        self::assertSame( 403, $svcIntruderC->httpStatus );
        self::assertFalse( $svcIntruderC->is_profile_incomplete() );

        $holdAfterC = $db->fetchRow(
            'SELECT status, holder_wp_user_id, patient_id, expires_at FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdIdA ]
        );
        self::assertIsArray( $holdAfterC );
        self::assertArrayHasKey( 'status', $holdAfterC );
        self::assertArrayHasKey( 'holder_wp_user_id', $holdAfterC );
        self::assertArrayHasKey( 'patient_id', $holdAfterC );
        self::assertArrayHasKey( 'expires_at', $holdAfterC );
        self::assertSame( 'active', (string) $holdAfterC['status'] );
        self::assertSame( $userA, (int) $holdAfterC['holder_wp_user_id'] );
        self::assertSame( $patientA, (int) $holdAfterC['patient_id'] );
        self::assertSame( $origExpires, (string) $holdAfterC['expires_at'] );

        $slotAfterIntruders = $db->fetchRow(
            'SELECT held_count, booked_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotId ]
        );
        self::assertIsArray( $slotAfterIntruders );
        self::assertArrayHasKey( 'held_count', $slotAfterIntruders );
        self::assertArrayHasKey( 'booked_count', $slotAfterIntruders );
        self::assertSame( 1, (int) $slotAfterIntruders['held_count'] );
        self::assertSame( 0, (int) $slotAfterIntruders['booked_count'] );

        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE patient_id IN (%d, %d) OR wp_user_id IN (%d, %d) OR slot_id = %d',
                [ $patientB, $patientC, $userB, $userC, $slotId ]
            )
        );
        self::assertGreaterThanOrEqual(
            2,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $auditTable . ' WHERE action = %s AND actor_wp_user_id = %d AND resource_type = %s AND resource_id = %d',
                [ 'FORBIDDEN_ACCESS_ATTEMPT', $userB, 'slot_hold', $holdIdA ]
            )
        );
        self::assertGreaterThanOrEqual(
            2,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $auditTable . ' WHERE action = %s AND actor_wp_user_id = %d AND resource_type = %s AND resource_id = %d',
                [ 'FORBIDDEN_ACCESS_ATTEMPT', $userC, 'slot_hold', $holdIdA ]
            )
        );

        // 3) Patient A completes profile and confirms; appointment is bound strictly to Patient A.
        PatientTwoProofTestHelper::acting_as( $userA );
        App::patientService()->updateMe( $userA, [ 'birth_date' => '1988-03-19' ] );
        $confirmedA = $booking->confirm( $holdTokenA, $userA, 'تأیید صاحب اصلی', $idemKeyA );
        self::assertArrayHasKey( 'appointment_id', $confirmedA );
        $apptIdA = (int) $confirmedA['appointment_id'];
        self::assertGreaterThan( 0, $apptIdA );

        $apptRowA = $db->fetchRow(
            'SELECT patient_id, wp_user_id, status FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $apptIdA ]
        );
        self::assertIsArray( $apptRowA );
        self::assertArrayHasKey( 'patient_id', $apptRowA );
        self::assertArrayHasKey( 'wp_user_id', $apptRowA );
        self::assertArrayHasKey( 'status', $apptRowA );
        self::assertSame( $patientA, (int) $apptRowA['patient_id'] );
        self::assertSame( $userA, (int) $apptRowA['wp_user_id'] );
        self::assertSame( 'confirmed', (string) $apptRowA['status'] );
        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE patient_id IN (%d, %d)',
                [ $patientB, $patientC ]
            )
        );
    }

    /**
     * Round 2 T4 (no duplicates): after profile-incomplete denial (400) and successful
     * retry (200) on a capacity=2 slot, repeating confirm with the SAME Idempotency-Key
     * returns 200 for the same appointment and creates no second appointment (and stored
     * Idempotency::check() replay at :936-939 returns the persisted 200 payload if the
     * completed key is re-sent against an active hold).
     *
     * Reachability note (BookingService.php:857-865, docs/api/api-contract.md:25):
     * while the appointment created from a 'converted' hold remains active ('pending'/'confirmed'),
     * BookingService::confirm() intercepts status === 'converted' BEFORE Idempotency::check() (:936)
     * and returns 200 with that same appointment even for a DIFFERENT Idempotency-Key (creating zero
     * new appointments and zero new idempotency rows); denial on a 'converted' hold with a different
     * Idempotency-Key (422 CLINIC_HOLD_EXPIRED at :865) is reachable once no active appointment exists
     * on (wp_user_id, slot_id, clinic_id) — e.g. after cancellation. Both real behaviors are asserted
     * without faking, and Patient A ends with exactly one cpms_appointments row and booked_count = 1.
     */
    public function testRound2T4ConfirmAfterRetryProducesSingleAppointmentAcrossSameAndDifferentIdempotencyKeys(): void {
        $db         = App::db();
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $apptsTable = $db->table( 'cpms_appointments' );
        $patsTable  = $db->table( 'cpms_patients' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $booking    = App::bookingService();

        $mobileA  = '09125550208';
        $nidA     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientA = $this->insertPatient( $this->clinicListed, $mobileA, 'تکرار', 'یکتا', $nidA, '1991-06-15' );
        self::assertGreaterThan( 0, $patientA );
        $userA = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userA,
            $this->clinicListed,
            $patientA,
            [
                'mobile'             => $mobileA,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        // Use capacity=2 so a buggy duplicate claim would actually raise booked_count to 2.
        $date1       = gmdate( 'Y-m-d', time() + 18 * 86400 );
        $date2       = gmdate( 'Y-m-d', time() + 19 * 86400 );
        $slotPrimary = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date1, '09:00:00', 2 );
        $slotActive2 = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $date2, '10:00:00', 2 );
        self::assertGreaterThan( 0, $slotPrimary );
        self::assertGreaterThan( 0, $slotActive2 );

        PatientTwoProofTestHelper::acting_as( $userA );
        $hold1 = $booking->hold( $userA, $this->clinicianListed, $date1, '09:00:00', $slotPrimary, $patientA, $this->locationListed );
        self::assertArrayHasKey( 'hold_token', $hold1 );
        $holdToken1 = (string) $hold1['hold_token'];

        $holdRow1 = $db->fetchRow(
            'SELECT id FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdToken1 ]
        );
        self::assertIsArray( $holdRow1 );
        self::assertArrayHasKey( 'id', $holdRow1 );
        $holdId1 = (int) $holdRow1['id'];

        // 1) Profile-incomplete first confirm over REST -> 400; hold stays active, idem key released.
        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patientA ]
        );

        $idemKey1  = wp_generate_uuid4();
        $firstResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdToken1,
                'reason'     => 'تلاش اول ناقص',
            ],
            $idemKey1
        );
        $firstData = $firstResp->get_data();
        self::assertSame( 400, $firstResp->get_status() );
        self::assertIsArray( $firstData );
        self::assertArrayHasKey( 'code', $firstData );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', (string) $firstData['code'] );

        // 2) Complete profile and retry REST confirm with SAME Idempotency-Key -> 200.
        App::patientService()->updateMe( $userA, [ 'birth_date' => '1991-06-15' ] );
        $retryResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdToken1,
                'reason'     => 'تلاش دوم کامل',
            ],
            $idemKey1
        );
        $retryBody = $retryResp->get_data();
        self::assertSame( 200, $retryResp->get_status() );
        self::assertIsArray( $retryBody );
        self::assertArrayHasKey( 'data', $retryBody );
        self::assertIsArray( $retryBody['data'] );
        self::assertArrayHasKey( 'appointment_id', $retryBody['data'] );
        self::assertArrayHasKey( 'reference_code', $retryBody['data'] );
        $apptId  = (int) $retryBody['data']['appointment_id'];
        $refCode = (string) $retryBody['data']['reference_code'];
        self::assertGreaterThan( 0, $apptId );
        self::assertNotSame( '', $refCode );

        $storedIdem = $db->fetchRow(
            'SELECT status, response_code, response_json FROM ' . $idemTable
            . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d LIMIT 1',
            [ $idemKey1, 'booking/confirm', $userA, 0, $this->clinicListed ]
        );
        self::assertIsArray( $storedIdem );
        self::assertArrayHasKey( 'status', $storedIdem );
        self::assertArrayHasKey( 'response_code', $storedIdem );
        self::assertArrayHasKey( 'response_json', $storedIdem );
        self::assertSame( 1, (int) $storedIdem['status'] );
        self::assertSame( 200, (int) $storedIdem['response_code'] );
        $storedPayload = json_decode( (string) $storedIdem['response_json'], true );
        self::assertIsArray( $storedPayload );
        self::assertArrayHasKey( 'appointment_id', $storedPayload );
        self::assertSame( $apptId, (int) $storedPayload['appointment_id'] );

        // 3) Third confirm on holdToken1 with SAME Idempotency-Key -> 200, same appointment, no second row.
        $sameKeyResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdToken1,
                'reason'     => 'تلاش سوم همان کلید',
            ],
            $idemKey1
        );
        $sameKeyBody = $sameKeyResp->get_data();
        self::assertSame( 200, $sameKeyResp->get_status() );
        self::assertIsArray( $sameKeyBody );
        self::assertArrayHasKey( 'data', $sameKeyBody );
        self::assertIsArray( $sameKeyBody['data'] );
        self::assertArrayHasKey( 'appointment_id', $sameKeyBody['data'] );
        self::assertArrayHasKey( 'reference_code', $sameKeyBody['data'] );
        self::assertSame( $apptId, (int) $sameKeyBody['data']['appointment_id'] );
        self::assertSame( $refCode, (string) $sameKeyBody['data']['reference_code'] );

        // Stored Idempotency::check() replay (:936-939) when completed idemKey1 is sent on an active hold:
        // returns stored 200 payload for apptId without converting hold2 or creating any second appointment.
        $hold2          = $booking->hold( $userA, $this->clinicianListed, $date2, '10:00:00', $slotActive2, $patientA, $this->locationListed );
        $holdToken2     = (string) $hold2['hold_token'];
        $storedReplay   = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdToken2,
                'reason'     => 'بازپخش ذخیره‌شده',
            ],
            $idemKey1
        );
        $storedRespBody = $storedReplay->get_data();
        self::assertSame( 200, $storedReplay->get_status() );
        self::assertIsArray( $storedRespBody );
        self::assertArrayHasKey( 'data', $storedRespBody );
        self::assertIsArray( $storedRespBody['data'] );
        self::assertSame( $apptId, (int) $storedRespBody['data']['appointment_id'] );

        $hold2Row = $db->fetchRow(
            'SELECT status FROM ' . $holdsTable . ' WHERE token = %s LIMIT 1',
            [ $holdToken2 ]
        );
        self::assertIsArray( $hold2Row );
        self::assertSame( 'active', (string) $hold2Row['status'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotActive2 ] )
        );

        // 4) Different Idempotency-Key on already-converted holdToken1:
        // (a) While apptId is active ('confirmed'), BookingService.php:858-863 returns 200 for the SAME
        // appointment before Idempotency::check() runs, creating no second appointment and no idemKey2 row.
        $idemKey2    = wp_generate_uuid4();
        $diffKeyResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => $holdToken1,
                'reason'     => 'کلید متفاوت روی هولد تبدیل‌شده',
            ],
            $idemKey2
        );
        $diffKeyBody = $diffKeyResp->get_data();
        self::assertSame( 200, $diffKeyResp->get_status() );
        self::assertIsArray( $diffKeyBody );
        self::assertArrayHasKey( 'data', $diffKeyBody );
        self::assertIsArray( $diffKeyBody['data'] );
        self::assertSame( $apptId, (int) $diffKeyBody['data']['appointment_id'] );
        self::assertSame( $refCode, (string) $diffKeyBody['data']['reference_code'] );
        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $idemTable . ' WHERE `key` = %s AND endpoint = %s',
                [ $idemKey2, 'booking/confirm' ]
            )
        );

        // Final invariant on Patient A's converted hold / slotPrimary: exact 1 appointment and booked_count = 1.
        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE slot_id = %d', [ $slotPrimary ] )
        );
        self::assertSame(
            1,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable . ' WHERE patient_id = %d', [ $patientA ] )
        );
        $hold1Final = $db->fetchRow(
            'SELECT status FROM ' . $holdsTable . ' WHERE id = %d LIMIT 1',
            [ $holdId1 ]
        );
        self::assertIsArray( $hold1Final );
        self::assertSame( 'converted', (string) $hold1Final['status'] );

        $slotPrimaryFinal = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotPrimary ]
        );
        self::assertIsArray( $slotPrimaryFinal );
        self::assertArrayHasKey( 'booked_count', $slotPrimaryFinal );
        self::assertArrayHasKey( 'held_count', $slotPrimaryFinal );
        self::assertSame( 1, (int) $slotPrimaryFinal['booked_count'] );
        self::assertSame( 0, (int) $slotPrimaryFinal['held_count'] );

        // (b) Witness on independent Patient B: once the converted hold's appointment is no longer
        // active (cancelled), confirm with a different Idempotency-Key IS denied at :865 (422 CLINIC_HOLD_EXPIRED).
        $mobileB  = '09125550209';
        $nidB     = PatientTwoProofTestHelper::next_valid_national_id();
        $patientB = $this->insertPatient( $this->clinicListed, $mobileB, 'گواه', 'لغوشده', $nidB, '1994-01-20' );
        self::assertGreaterThan( 0, $patientB );
        $userB = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userB,
            $this->clinicListed,
            $patientB,
            [
                'mobile'             => $mobileB,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );
        $dateCancel = gmdate( 'Y-m-d', time() + 20 * 86400 );
        $slotCancel = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateCancel, '11:00:00', 1 );
        self::assertGreaterThan( 0, $slotCancel );

        PatientTwoProofTestHelper::acting_as( $userB );
        $holdCancel    = $booking->hold( $userB, $this->clinicianListed, $dateCancel, '11:00:00', $slotCancel, $patientB, $this->locationListed );
        $confirmCancel = $booking->confirm( (string) $holdCancel['hold_token'], $userB, 'رزرو اولیه', wp_generate_uuid4() );
        $booking->cancelByPatient( $userB, (int) $confirmCancel['appointment_id'], 'لغو توسط بیمار' );

        $deniedConverted = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/booking/confirm',
            [
                'hold_token' => (string) $holdCancel['hold_token'],
                'reason'     => 'کلید متفاوت پس از لغو',
            ],
            wp_generate_uuid4()
        );
        $deniedConvBody  = $deniedConverted->get_data();
        self::assertSame( 422, $deniedConverted->get_status() );
        self::assertIsArray( $deniedConvBody );
        self::assertArrayHasKey( 'code', $deniedConvBody );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', (string) $deniedConvBody['code'] );

        $slotCancelFinal = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotCancel ]
        );
        self::assertIsArray( $slotCancelFinal );
        self::assertSame( 0, (int) $slotCancelFinal['booked_count'] );
        self::assertSame( 0, (int) $slotCancelFinal['held_count'] );
    }

    /**
     * Round 2 T5 (sibling path): REST reschedule (/clinic/v1/appointments/{id}/reschedule)
     * and staff reschedule behavior for a profile-incomplete patient remain unchanged —
     * missing birth_date or national_id fails closed inside the booking transaction with
     * 400 CLINIC_VALIDATION_FAILED, preserves the confirmed appointment and both slot
     * counters untouched, releases the reschedule idempotency claim, leaves staff
     * reschedule unrestricted, and allows retry with the same Idempotency-Key once completed.
     */
    public function testRound2T5RestRescheduleSiblingPathForProfileIncompletePatientRemainsUnchanged(): void {
        $db         = App::db();
        $apptsTable = $db->table( 'cpms_appointments' );
        $slotsTable = $db->table( 'cpms_schedule_slots' );
        $holdsTable = $db->table( 'cpms_slot_holds' );
        $patsTable  = $db->table( 'cpms_patients' );
        $idemTable  = $db->table( 'cpms_idempotency_keys' );
        $booking    = App::bookingService();

        $mobile  = '09125550210';
        $nid     = PatientTwoProofTestHelper::next_valid_national_id();
        $patient = $this->insertPatient( $this->clinicListed, $mobile, 'جابه‌جایی', 'همزاد', $nid, '1990-05-15' );
        self::assertGreaterThan( 0, $patient );
        $user = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        PatientTwoProofTestHelper::seed_patient_proof(
            $user,
            $this->clinicListed,
            $patient,
            [
                'mobile'             => $mobile,
                'seed_session_scope' => true,
                'set_current_user'   => false,
            ]
        );

        $dateOrig   = gmdate( 'Y-m-d', time() + 21 * 86400 );
        $dateTarget = gmdate( 'Y-m-d', time() + 22 * 86400 );
        $slotOld    = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateOrig, '09:00:00', 1 );
        $slotTarget = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateTarget, '10:00:00', 1 );
        self::assertGreaterThan( 0, $slotOld );
        self::assertGreaterThan( 0, $slotTarget );

        PatientTwoProofTestHelper::acting_as( $user );
        $holdOrig   = $booking->hold( $user, $this->clinicianListed, $dateOrig, '09:00:00', $slotOld, $patient, $this->locationListed );
        $origBooked = $booking->confirm( (string) $holdOrig['hold_token'], $user, 'نوبت مبدأ', wp_generate_uuid4() );
        $origApptId = (int) $origBooked['appointment_id'];
        self::assertGreaterThan( 0, $origApptId );

        $holdsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable );
        $apptsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable );
        $reschedKey  = wp_generate_uuid4();

        // 1) Missing birth_date -> REST reschedule fails 400 CLINIC_VALIDATION_FAILED; DB state untouched.
        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = NULL WHERE id = %d',
            [ $patient ]
        );

        $denyBirthResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/appointments/' . $origApptId . '/reschedule',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => $dateTarget,
                'slot_time'    => '10:00:00',
                'slot_id'      => $slotTarget,
            ],
            $reschedKey
        );
        $denyBirthBody = $denyBirthResp->get_data();
        self::assertSame( 400, $denyBirthResp->get_status() );
        self::assertIsArray( $denyBirthBody );
        self::assertArrayHasKey( 'code', $denyBirthBody );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', (string) $denyBirthBody['code'] );

        // 2) Missing national_id -> REST reschedule also fails 400 CLINIC_VALIDATION_FAILED; DB state untouched.
        $db->query(
            'UPDATE ' . $patsTable . ' SET birth_date = %s, national_id = NULL WHERE id = %d',
            [ '1990-05-15', $patient ]
        );

        $denyNidResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/appointments/' . $origApptId . '/reschedule',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => $dateTarget,
                'slot_time'    => '10:00:00',
                'slot_id'      => $slotTarget,
            ],
            $reschedKey
        );
        $denyNidBody = $denyNidResp->get_data();
        self::assertSame( 400, $denyNidResp->get_status() );
        self::assertIsArray( $denyNidBody );
        self::assertArrayHasKey( 'code', $denyNidBody );
        self::assertSame( 'CLINIC_VALIDATION_FAILED', (string) $denyNidBody['code'] );

        self::assertSame( $holdsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $holdsTable ) );
        self::assertSame( $apptsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $apptsTable ) );

        $origAfterDeny = $db->fetchRow(
            'SELECT status, rescheduled_to, slot_id FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $origApptId ]
        );
        self::assertIsArray( $origAfterDeny );
        self::assertArrayHasKey( 'status', $origAfterDeny );
        self::assertArrayHasKey( 'rescheduled_to', $origAfterDeny );
        self::assertArrayHasKey( 'slot_id', $origAfterDeny );
        self::assertSame( 'confirmed', (string) $origAfterDeny['status'] );
        self::assertNull( $origAfterDeny['rescheduled_to'] );
        self::assertSame( $slotOld, (int) $origAfterDeny['slot_id'] );

        $slotOldDeny = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotOld ]
        );
        self::assertIsArray( $slotOldDeny );
        self::assertSame( 1, (int) $slotOldDeny['booked_count'] );
        self::assertSame( 0, (int) $slotOldDeny['held_count'] );

        $slotTargetDeny = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTarget ]
        );
        self::assertIsArray( $slotTargetDeny );
        self::assertSame( 0, (int) $slotTargetDeny['booked_count'] );
        self::assertSame( 0, (int) $slotTargetDeny['held_count'] );

        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $idemTable
                . ' WHERE `key` = %s AND endpoint = %s AND wp_user_id = %d AND context_id = %d AND clinic_id = %d',
                [ $reschedKey, 'booking/reschedule', $user, $origApptId, $this->clinicListed ]
            )
        );

        // 3) Staff reschedule for an incomplete patient remains permitted.
        $staffUser = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_SECRETARY ] );
        App::membership_service()->create_membership( $this->clinicListed, $staffUser, RolesAndCapabilities::ROLE_SECRETARY );
        $walkinPat  = $this->insertPatient( $this->clinicListed, '09125550211', 'حضوری', 'کارمند', null, null );
        $dateStaff1 = gmdate( 'Y-m-d', time() + 23 * 86400 );
        $dateStaff2 = gmdate( 'Y-m-d', time() + 24 * 86400 );
        $slotStaff1 = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateStaff1, '09:00:00', 1 );
        $slotStaff2 = $this->insertSlotWithCapacity( $this->clinicListed, $this->locationListed, $this->clinicianListed, $dateStaff2, '10:00:00', 1 );
        ScopeContext::set( ClinicScope::forClinic( $this->clinicListed, $this->locationListed )->withOrganization( $this->orgId ) );
        try {
            $staffCreated     = $booking->createByStaff( $staffUser, $walkinPat, $this->clinicianListed, $dateStaff1, '09:00:00', 'حضوری', $slotStaff1 );
            $staffRescheduled = $booking->rescheduleByStaff( $staffUser, (int) $staffCreated['appointment_id'], $this->clinicianListed, $dateStaff2, '10:00:00', wp_generate_uuid4(), $slotStaff2 );
        } finally {
            ScopeContext::clear();
        }
        self::assertArrayHasKey( 'appointment_id', $staffRescheduled );
        self::assertGreaterThan( 0, (int) $staffRescheduled['appointment_id'] );

        // 4) Restore national_id and retry REST reschedule with the SAME Idempotency-Key -> 200.
        $db->query(
            'UPDATE ' . $patsTable . ' SET national_id = %s WHERE id = %d',
            [ $nid, $patient ]
        );
        PatientTwoProofTestHelper::acting_as( $user );
        $okResp = $this->dispatchRestBooking(
            'POST',
            '/clinic/v1/appointments/' . $origApptId . '/reschedule',
            [
                'clinician_id' => $this->clinicianListed,
                'slot_date'    => $dateTarget,
                'slot_time'    => '10:00:00',
                'slot_id'      => $slotTarget,
            ],
            $reschedKey
        );
        $okBody = $okResp->get_data();
        self::assertSame( 200, $okResp->get_status() );
        self::assertIsArray( $okBody );
        self::assertArrayHasKey( 'data', $okBody );
        self::assertIsArray( $okBody['data'] );
        self::assertArrayHasKey( 'appointment_id', $okBody['data'] );
        $newApptId = (int) $okBody['data']['appointment_id'];
        self::assertGreaterThan( 0, $newApptId );

        $origAfterOk = $db->fetchRow(
            'SELECT status, rescheduled_to FROM ' . $apptsTable . ' WHERE id = %d LIMIT 1',
            [ $origApptId ]
        );
        self::assertIsArray( $origAfterOk );
        self::assertSame( 'rescheduled', (string) $origAfterOk['status'] );
        self::assertSame( $newApptId, (int) $origAfterOk['rescheduled_to'] );

        $slotOldOk = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotOld ]
        );
        self::assertIsArray( $slotOldOk );
        self::assertSame( 0, (int) $slotOldOk['booked_count'] );
        self::assertSame( 0, (int) $slotOldOk['held_count'] );

        $slotTargetOk = $db->fetchRow(
            'SELECT booked_count, held_count FROM ' . $slotsTable . ' WHERE id = %d LIMIT 1',
            [ $slotTarget ]
        );
        self::assertIsArray( $slotTargetOk );
        self::assertSame( 1, (int) $slotTargetOk['booked_count'] );
        self::assertSame( 0, (int) $slotTargetOk['held_count'] );
    }

    /**
     * @return array{
     *     response: WP_REST_Response,
     *     user_id: int,
     *     session_issued: bool,
     *     pending_registration: bool,
     *     rest_nonce: string,
     *     token: string,
     *     cookie: string
     * }
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
        $userId              = 0;
        $sessionIssued       = false;
        $pendingRegistration = false;
        $restNonce           = '';
        if ( 200 === $resp->get_status() ) {
            self::assertArrayHasKey( 'data', $respData );
            self::assertIsArray( $respData['data'] );
            $payload = $respData['data'];
            self::assertArrayHasKey( 'user_id', $payload );
            self::assertArrayHasKey( 'session_issued', $payload );
            self::assertArrayHasKey( 'pending_registration', $payload );
            self::assertArrayHasKey( 'rest_nonce', $payload );
            $userId              = (int) $payload['user_id'];
            $sessionIssued       = (bool) $payload['session_issued'];
            $pendingRegistration = (bool) $payload['pending_registration'];
            $restNonce           = (string) $payload['rest_nonce'];
        }

        $result = [
            'response'             => $resp,
            'user_id'              => $userId,
            'session_issued'       => $sessionIssued,
            'pending_registration' => $pendingRegistration,
            'rest_nonce'           => $restNonce,
            'token'                => $capturedToken,
            'cookie'               => $capturedCookie,
        ];

        if ( $sessionIssued && $userId > 0 ) {
            $this->activateStep1Session( $result );
        }

        return $result;
    }

    /**
     * @param array{user_id: int, token: string, cookie: string} $step1
     */
    private function activateStep1Session( array $step1 ): void {
        if ( '' !== $step1['cookie'] ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $step1['cookie'];
        }
        wp_set_current_user( $step1['user_id'] );
        if ( '' !== $step1['token'] ) {
            App::patient_session_scope()->set_in_process_session_token( $step1['user_id'], $step1['token'] );
        }
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

    private function insertSlotWithCapacity(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        string $date,
        string $time,
        int $capacity
    ): int {
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
                'capacity'     => $capacity,
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
    private function dispatchRestBooking(
        string $method,
        string $route,
        array $body,
        ?string $idempotencyKey = null
    ): WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        if ( null !== $idempotencyKey ) {
            $req->set_header( 'Idempotency-Key', $idempotencyKey );
        }
        if ( [] !== $body ) {
            $req->set_body( (string) wp_json_encode( $body ) );
        }
        $res = rest_do_request( $req );
        self::assertInstanceOf( WP_REST_Response::class, $res );

        return $res;
    }

    /**
     * FLAKE FIX (round 3): test-fixture time travel — backdates cpms_slot_holds.expires_at
     * of one exact active hold row into the past (WHERE id = %d AND status = 'active') so
     * production expiry paths run deterministically without wall-clock sleep().
     */
    private function backdateActiveHoldExpiryForTest( int $holdId ): string {
        $db            = App::db();
        $pastExpiresAt = gmdate( 'Y-m-d H:i:s', time() - 60 ) . '.000';
        $affected      = $db->execute(
            'UPDATE ' . $db->table( 'cpms_slot_holds' ) . " SET expires_at = %s WHERE id = %d AND status = 'active'",
            [ $pastExpiresAt, $holdId ]
        );
        self::assertSame( 1, $affected );

        return $pastExpiresAt;
    }
}

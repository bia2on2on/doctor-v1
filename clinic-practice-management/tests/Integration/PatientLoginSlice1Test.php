<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Frontend\PatientOtpLogin;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * End-to-end integration tests for PATIENT-LOGIN-SLICE-1 (P2 + P4 + P6):
 *
 *  - P2: national_id is removed from PatientService::ME_EDITABLE, publicView(),
 *        staffView(), REST /patient/me & /patients/{id} responses, and PatientPortalPage.
 *        Staff PatientService::update() continues to accept national_id, enforce uniqueness,
 *        close active bindings, and destroy patient sessions post-commit.
 *  - P4: OtpService::verify() with PURPOSE_LOGIN on a pure-patient non-closed account in
 *        cpms_patient_logins issues an 8h non-persistent WP cookie + OTP mobile proof,
 *        audits LOGIN_SUCCESS + OTP_VERIFY_OK atomically, preserves pending state, and
 *        leaves PatientAccess::level() === LEVEL_NONE until link-record completes.
 *        Nested transactions fail closed. Guests/closed/staff/verify_mobile get zero access.
 *  - P6: Guest OTP login -> 8h session -> PatientPortalPage link-record UI ->
 *        POST /clinic/v1/patient/link-record ({clinic_id, national_id} only, mobile from
 *        session proof) -> promotion to active binding + full portal access.
 */
final class PatientLoginSlice1Test extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_PENDING = '09127770001';
    private const MOBILE_GUEST   = '09127770002';
    private const MOBILE_CLOSED  = '09127770003';
    private const MOBILE_MIXED   = '09127770004';
    private const NID_PENDING    = '0010000003';
    private const NID_OTHER      = '0010000011';
    private const NID_WRONG      = '0011223344';

    private int $clinicA;
    private int $patientPending;
    private int $patientOther;
    private int $userPending;
    private int $userClosed;
    private int $userMixed;
    private int $userStaff;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

        $this->clinicA = 1;
        $this->patientPending = $this->insertPatient(
            $this->clinicA,
            'MR-PLS1-A-' . uniqid(),
            self::MOBILE_PENDING,
            self::NID_PENDING
        );
        $this->patientOther = $this->insertPatient(
            $this->clinicA,
            'MR-PLS1-B-' . uniqid(),
            self::MOBILE_GUEST,
            self::NID_OTHER
        );

        $this->userPending = $this->makeUser( 'pls1_pending_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->userClosed  = $this->makeUser( 'pls1_closed_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->userMixed   = $this->makeUser( 'pls1_mixed_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->userStaff   = $this->makeUser( 'pls1_staff_' . uniqid(), RolesAndCapabilities::ROLE_SECRETARY );

        cpms_test_seed_membership( $this->userMixed, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        cpms_test_seed_membership( $this->userStaff, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );

        $logins = new PatientLoginRepository( App::db() );
        $logins->insert_pending_account( $this->userPending, self::MOBILE_PENDING, 'بیمار', 'درانتظار' );
        $logins->insert_pending_account( $this->userClosed, self::MOBILE_CLOSED, 'بیمار', 'بسته' );
        $logins->close_account( $this->userClosed );
        $logins->insert_pending_account( $this->userMixed, self::MOBILE_MIXED, 'کاربر', 'ترکیبی' );

        $this->installSignedExpiration( time() + 30 * 86400 );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        PatientTwoProofTestHelper::reset();
        remove_all_filters( 'cpms_license_public_key' );
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    // =========================================================================
    // P2: National ID exclusion from patient/staff projections & self-edit
    // =========================================================================

    public function testP2NationalIdExcludedFromViewsRestAndPortalAndSelfEditRejectsOracle(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->userPending );
        $proofStore->record_mobile_proof( $this->userPending, self::MOBILE_PENDING, null, $token );

        App::patient_binding_promoter()->promote(
            $this->userPending,
            $this->clinicA,
            self::MOBILE_PENDING,
            self::NID_PENDING,
            null,
            '0.0.0.0',
            null,
            $token
        );

        // 1. Patient REST GET/PUT/POST/PATCH /patient/me never project national_id or leak raw/masked NID.
        $meGet = $this->dispatchRestWithSessionToken( 'GET', '/clinic/v1/patient/me', [], $this->userPending, $token );
        self::assertSame( 200, $meGet->get_status() );
        $this->assertNoNationalIdProjectionOrLeak( $meGet->get_data(), self::NID_PENDING, 'GET /patient/me' );

        foreach ( [ 'PUT', 'POST', 'PATCH' ] as $method ) {
            // NID-only (duplicate vs unique) produces identical 400 CLINIC_VALIDATION_FAILED (no oracle).
            $dupResp = $this->dispatchRestWithSessionToken( $method, '/clinic/v1/patient/me', [ 'national_id' => self::NID_OTHER ], $this->userPending, $token );
            $uniqResp = $this->dispatchRestWithSessionToken( $method, '/clinic/v1/patient/me', [ 'national_id' => '0499370899' ], $this->userPending, $token );
            self::assertSame( 400, $dupResp->get_status() );
            self::assertSame( 400, $uniqResp->get_status() );
            self::assertSame( 'CLINIC_VALIDATION_FAILED', (string) ( $dupResp->get_data()['code'] ?? '' ) );
            self::assertSame( 'CLINIC_VALIDATION_FAILED', (string) ( $uniqResp->get_data()['code'] ?? '' ) );
            self::assertSame(
                (string) ( $dupResp->get_data()['message'] ?? '' ),
                (string) ( $uniqResp->get_data()['message'] ?? '' ),
                $method . ' /patient/me must not leak a duplicate-NID oracle'
            );

            // Combined with an editable field: editable field saves, national_id is stripped and unchanged in DB.
            $mixed = $this->dispatchRestWithSessionToken( $method, '/clinic/v1/patient/me', [
                'first_name'  => 'Edited_' . $method,
                'national_id' => self::NID_OTHER,
            ], $this->userPending, $token );
            self::assertSame( 200, $mixed->get_status() );
            $this->assertNoNationalIdProjectionOrLeak( $mixed->get_data(), self::NID_PENDING, $method . ' /patient/me' );
            $this->assertNoNationalIdProjectionOrLeak( $mixed->get_data(), self::NID_OTHER, $method . ' /patient/me' );
            self::assertSame(
                self::NID_PENDING,
                (string) App::db()->fetchValue( 'SELECT national_id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientPending ] )
            );
        }

        // 2. Staff REST routes (POST/GET/PUT /patients, POST /patients/search) also omit national_id in views.
        $staffGet = $this->dispatchRest( 'GET', '/clinic/v1/patients/' . $this->patientPending, [], $this->userStaff );
        self::assertSame( 200, $staffGet->get_status() );
        $this->assertNoNationalIdProjectionOrLeak( $staffGet->get_data(), self::NID_PENDING, 'GET /patients/{id}' );

        $staffCreate = $this->dispatchRest( 'POST', '/clinic/v1/patients', [
            'first_name'  => 'کددار',
            'last_name'   => 'آزمایشی',
            'mobile'      => '09127770099',
            'national_id' => '0499370899',
        ], $this->userStaff );
        self::assertSame( 200, $staffCreate->get_status() );
        $this->assertNoNationalIdProjectionOrLeak( $staffCreate->get_data(), '0499370899', 'POST /patients' );

        // 3. Audit logs never contain raw or masked NID.
        $audits = App::db()->fetchAll( 'SELECT action, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        foreach ( $audits as $row ) {
            $meta = (string) ( $row['meta_json'] ?? '' );
            $this->assertNoNationalIdLiteral( $meta, self::NID_PENDING, 'audit ' . (string) $row['action'] );
            $this->assertNoNationalIdLiteral( $meta, '0499370899', 'audit ' . (string) $row['action'] );
        }

        // 4. Staff PUT /patients/{id} updating national_id still works and closes binding + destroys sessions.
        $staffPut = $this->dispatchRest( 'PUT', '/clinic/v1/patients/' . $this->patientPending, [
            'national_id' => '0080000002',
        ], $this->userStaff );
        self::assertSame( 200, $staffPut->get_status() );
        $this->assertNoNationalIdProjectionOrLeak( $staffPut->get_data(), '0080000002', 'PUT /patients/{id}' );
        self::assertNull(
            ( new PatientLoginRepository( App::db() ) )->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true )
        );
        self::assertNull( WP_Session_Tokens::get_instance( $this->userPending )->get( $token ) );
    }

    // =========================================================================
    // P4: OtpService::verify() PURPOSE_LOGIN session issuance & denial matrix
    // =========================================================================

    public function testP4PurposeLoginVerifyIssues8hNonPersistentSessionWithoutPromotingPendingOrGrantingFullAccess(): void {
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '223344', OtpService::PURPOSE_LOGIN );

        $capturedExpiration = null;
        $capturedRemember   = null;
        $capturedCookieUser = null;
        $capturedToken      = null;

        $expFilter = static function ( int $length, int $userId, bool $remember ) use ( &$capturedExpiration, &$capturedRemember ): int {
            $capturedExpiration = $length;
            $capturedRemember   = $remember;
            return $length;
        };
        $cookieHook = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$capturedCookieUser, &$capturedToken ): void {
            $capturedCookieUser = $userId;
            $capturedToken      = $token;
        };

        add_filter( 'auth_cookie_expiration', $expFilter, 1000, 3 );
        add_action( 'set_logged_in_cookie', $cookieHook, 10, 6 );
        try {
            $resp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '223344',
            ], 0, false );
        } finally {
            remove_filter( 'auth_cookie_expiration', $expFilter, 1000 );
            remove_action( 'set_logged_in_cookie', $cookieHook, 10 );
        }

        self::assertSame( 200, $resp->get_status() );
        $data = $resp->get_data()['data'] ?? [];
        self::assertTrue( $data['session_issued'] ?? false );
        self::assertSame( $this->userPending, (int) ( $data['user_id'] ?? 0 ) );
        self::assertSame( [], $data['patient_links'] ?? null );
        self::assertFalse( $data['is_new_user'] ?? true );

        // 8h non-persistent WP session cookie + mobile proof ONLY (no binding proof, no clinic stamp).
        self::assertSame( PatientSessionProofStore::SESSION_TTL_SECONDS, $capturedExpiration );
        self::assertFalse( $capturedRemember );
        self::assertSame( $this->userPending, $capturedCookieUser );
        self::assertIsString( $capturedToken );
        self::assertNotSame( '', $capturedToken );

        $proofStore = new PatientSessionProofStore();
        self::assertTrue( $proofStore->has_valid_mobile_proof( $this->userPending, self::MOBILE_PENDING, time(), $capturedToken ) );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $capturedToken ) );

        // Account stays pending!
        $account = ( new PatientLoginRepository( App::db() ) )->find_account_by_user_id( $this->userPending );
        self::assertIsArray( $account );
        self::assertSame( PatientLoginRepository::STATE_PENDING, $account['state'] );

        // Both OTP_VERIFY_OK and LOGIN_SUCCESS audits are recorded atomically.
        $actions = App::db()->fetchAll(
            'SELECT action FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE actor_wp_user_id = %d ORDER BY id ASC',
            [ $this->userPending ]
        );
        $actionNames = array_column( $actions, 'action' );
        self::assertContains( 'LOGIN_SUCCESS', $actionNames );

        $otpVerifyOkCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action = %s',
            [ 'OTP_VERIFY_OK' ]
        );
        self::assertGreaterThanOrEqual( 1, $otpVerifyOkCount );

        // /patient/me remains denied until link-record completes.
        $meDenied = $this->dispatchRestWithSessionToken( 'GET', '/clinic/v1/patient/me', [], $this->userPending, $capturedToken );
        self::assertSame( 403, $meDenied->get_status() );
        self::assertSame( 'CLINIC_PATIENT_PROOF_FAILED', (string) ( $meDenied->get_data()['code'] ?? '' ) );
    }

    public function testP4DeniedActorsNeverReceiveSessionOnOtpVerify(): void {
        // 1. Guest with cpms_patients row but NO cpms_patient_logins account row.
        $this->seedOtp( $this->clinicA, self::MOBILE_GUEST, '334455', OtpService::PURPOSE_LOGIN );
        $guestResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_GUEST,
            'code'   => '334455',
        ], 0, false );
        self::assertSame( 200, $guestResp->get_status() );
        self::assertFalse( $guestResp->get_data()['data']['session_issued'] ?? true );
        self::assertSame( 0, (int) ( $guestResp->get_data()['data']['user_id'] ?? -1 ) );

        // 2. Closed cpms_patient_logins account.
        $this->seedOtp( $this->clinicA, self::MOBILE_CLOSED, '445566', OtpService::PURPOSE_LOGIN );
        $closedResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_CLOSED,
            'code'   => '445566',
        ], 0, false );
        self::assertSame( 200, $closedResp->get_status() );
        self::assertFalse( $closedResp->get_data()['data']['session_issued'] ?? true );
        self::assertSame( 0, (int) ( $closedResp->get_data()['data']['user_id'] ?? -1 ) );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userClosed )->get_all() );

        // 3. Mixed patient+staff actor (fails is_pure_patient_actor).
        $this->seedOtp( $this->clinicA, self::MOBILE_MIXED, '556677', OtpService::PURPOSE_LOGIN );
        $mixedResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_MIXED,
            'code'   => '556677',
        ], 0, false );
        self::assertSame( 200, $mixedResp->get_status() );
        self::assertFalse( $mixedResp->get_data()['data']['session_issued'] ?? true );
        self::assertSame( 0, (int) ( $mixedResp->get_data()['data']['user_id'] ?? -1 ) );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userMixed )->get_all() );

        // 4. PURPOSE_VERIFY_MOBILE never issues a login session even for an active/pending patient account.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '667788', OtpService::PURPOSE_VERIFY_MOBILE );
        $vmResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile'  => self::MOBILE_PENDING,
            'code'    => '667788',
            'purpose' => OtpService::PURPOSE_VERIFY_MOBILE,
        ], 0, false );
        self::assertSame( 200, $vmResp->get_status() );
        self::assertFalse( $vmResp->get_data()['data']['session_issued'] ?? true );
        self::assertSame( 0, (int) ( $vmResp->get_data()['data']['user_id'] ?? -1 ) );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all() );
    }

    public function testP4AuditInsertFailureRollsBackOtpVerifyAndIssuesZeroSessionAndNestedTxFailsClosed(): void {
        // 1. Nested transaction fails closed before consuming OTP or issuing session.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '778899', OtpService::PURPOSE_LOGIN );
        $nestedThrew     = false;
        $nestedException = null;
        try {
            App::db()->transactional( function (): void {
                App::otpService()->verify( self::MOBILE_PENDING, '778899', OtpService::PURPOSE_LOGIN );
            } );
        } catch ( \Throwable $e ) {
            $nestedThrew     = true;
            $nestedException = $e;
        }
        self::assertTrue( $nestedThrew, 'Nested transaction on PURPOSE_LOGIN must fail closed.' );
        self::assertInstanceOf( OtpException::class, $nestedException );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all() );

        // Token was rolled back/unconsumed; verify DB row is still unconsumed.
        $consumedAt = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table( 'cpms_otp_tokens' ) . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [ self::MOBILE_PENDING ]
        );
        self::assertNull( $consumedAt, 'Failed nested tx must leave OTP token unconsumed.' );

        // 2. DB-error witness: failing the LOGIN_SUCCESS audit insert rolls back OTP consumption & issues ZERO session.
        global $wpdb;
        $auditTable = $wpdb->prefix . 'cpms_audit_logs';
        $poisoned   = false;
        $poisonFn   = static function ( string $sql ) use ( &$poisonFn, &$poisoned, $auditTable ): string {
            if ( ! $poisoned && str_contains( $sql, 'INSERT INTO `' . $auditTable . '`' ) && str_contains( $sql, 'LOGIN_SUCCESS' ) ) {
                $poisoned = true;
                remove_filter( 'query', $poisonFn );
                return 'INSERT INTO `' . $auditTable . '_nonexistent_witness` (id) VALUES (1)';
            }
            return $sql;
        };

        add_filter( 'query', $poisonFn );
        try {
            $resp = null;
            try {
                $resp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                    'mobile' => self::MOBILE_PENDING,
                    'code'   => '778899',
                ], 0, false );
                self::assertGreaterThanOrEqual( 400, $resp->get_status(), 'Failed atomic audit must fail OTP verify.' );
            } catch ( \RuntimeException $e ) {
                self::assertStringContainsString( 'LOGIN_SUCCESS', $e->getMessage() );
            }
        } finally {
            remove_filter( 'query', $poisonFn );
        }

        self::assertTrue( $poisoned, 'Witness filter must fire on LOGIN_SUCCESS audit insert.' );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all(), 'Rollback must leave zero WP sessions.' );
        $stillUnconsumed = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table( 'cpms_otp_tokens' ) . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [ self::MOBILE_PENDING ]
        );
        self::assertNull( $stillUnconsumed, 'Rollback must restore OTP consumed_at to NULL.' );
    }

    // =========================================================================
    // P6: End-to-end Guest OTP -> Link-Record Portal UI -> Full Portal Access
    // =========================================================================

    public function testP6PendingPatientEndToEndGuestOtpToLinkRecordUiToFullPortal(): void {
        // 1. Guest surface copy has no dead-end misleading copy and no national_id.
        ob_start();
        PatientOtpLogin::render();
        $guestHtml = (string) ob_get_clean();
        self::assertStringContainsString( 'data-role="patient-otp-login"', $guestHtml );
        self::assertStringNotContainsString( 'در صورت نبود حساب فعال، هنگام رزرو یا ثبت پرونده در مطب بررسی می‌شود', $guestHtml );
        self::assertStringNotContainsString( 'national_id', $guestHtml );

        // 2. Verify OTP -> get 8h session token + mobile proof.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '889900', OtpService::PURPOSE_LOGIN );
        $sessionToken = '';
        $captureToken = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$sessionToken ): void {
            $sessionToken = $token;
        };
        add_action( 'set_logged_in_cookie', $captureToken, 10, 6 );
        try {
            $verifyResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '889900',
            ], 0, false );
        } finally {
            remove_action( 'set_logged_in_cookie', $captureToken, 10 );
        }
        self::assertSame( 200, $verifyResp->get_status() );
        self::assertTrue( $verifyResp->get_data()['data']['session_issued'] ?? false );
        self::assertNotSame( '', $sessionToken );

        // 3. Render PatientPortalPage as this OTP-verified pending patient -> link-record section appears!
        $portalHtml = $this->renderPortalAs( $this->userPending, $sessionToken );
        self::assertStringContainsString( 'data-role="link-record-section"', $portalHtml );
        self::assertStringContainsString( 'data-role="link-record-form"', $portalHtml );
        self::assertStringContainsString( 'data-role="link-record-clinic"', $portalHtml );
        self::assertStringContainsString( 'data-role="link-record-national-id"', $portalHtml );
        self::assertStringContainsString( '"/patient/link-record"', str_replace( '\\/', '/', $portalHtml ) );
        // Security/privacy checks on rendered HTML:
        self::assertStringNotContainsString( 'data-clinic-id=', $portalHtml );
        self::assertStringNotContainsString( '"clinic_id":', $portalHtml );
        self::assertStringNotContainsString( 'name="national_id"', $portalHtml );
        $this->assertNoNationalIdLiteral( $portalHtml, self::NID_PENDING, 'link-record portal HTML' );
        // Profile form (if rendered) must NOT have data-field="national_id".
        self::assertStringNotContainsString( 'data-field="national_id"', $portalHtml );

        // 4. Submit POST /patient/link-record with ONLY {clinic_id, national_id} (mobile resolved from account + session mobile proof).
        $linkResp = $this->dispatchRestWithSessionToken( 'POST', '/clinic/v1/patient/link-record', [
            'clinic_id'   => $this->clinicA,
            'national_id' => self::NID_PENDING,
        ], $this->userPending, $sessionToken );
        self::assertSame( 200, $linkResp->get_status(), 'Body: ' . wp_json_encode( $linkResp->get_data() ) );
        self::assertSame( $this->patientPending, (int) ( $linkResp->get_data()['data']['patient_id'] ?? 0 ) );
        $this->assertNoNationalIdProjectionOrLeak( $linkResp->get_data(), self::NID_PENDING, 'POST /patient/link-record' );

        // Account is now active, binding proof is live, and /patient/me succeeds without national_id.
        $account = ( new PatientLoginRepository( App::db() ) )->find_account_by_user_id( $this->userPending );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $account['state'] ?? null );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $sessionToken ) );

        $meOk = $this->dispatchRestWithSessionToken( 'GET', '/clinic/v1/patient/me', [], $this->userPending, $sessionToken );
        self::assertSame( 200, $meOk->get_status() );
        $this->assertNoNationalIdProjectionOrLeak( $meOk->get_data(), self::NID_PENDING, 'GET /patient/me post-link' );

        // 5. Multi-clinic privacy check: unconsented private clinic B (in another organization)
        // must NOT appear anywhere in the portal or link-record clinic dropdown.
        $now = App::db()->nowUtcSql();
        App::db()->insert( 'cpms_organizations', [
            'name'       => 'Private Org ZZZ',
            'slug'       => 'private-org-zzz-' . uniqid(),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        $orgB = App::db()->wpdb_last_insert_id();
        App::db()->insert( 'cpms_clinics', [
            'organization_id' => $orgB,
            'name'            => 'Private Unconsented Clinic ZZZ',
            'slug'            => 'private-zzz-' . uniqid(),
            'timezone'        => 'UTC',
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ] );
        $portalAfterClinicB = $this->renderPortalAs( $this->userPending, $sessionToken );
        self::assertStringNotContainsString( 'Private Unconsented Clinic ZZZ', $portalAfterClinicB );
        self::assertStringContainsString( 'data-role="link-record-unavailable"', $portalAfterClinicB );
    }

    public function testP6LinkRecordDeniedWithoutOtpMobileProofOrWrongNidOrNonPurePatient(): void {
        // 1. Password-only WP session (no OTP mobile proof) -> link-record section hidden AND REST denies with 403.
        $pwToken = WP_Session_Tokens::get_instance( $this->userPending )->create( time() + 3600 );
        $pwHtml  = $this->renderPortalAs( $this->userPending, $pwToken );
        self::assertStringNotContainsString( 'data-role="link-record-section"', $pwHtml );

        $noMobileProofResp = $this->dispatchRestWithSessionToken( 'POST', '/clinic/v1/patient/link-record', [
            'clinic_id'   => $this->clinicA,
            'national_id' => self::NID_PENDING,
        ], $this->userPending, $pwToken );
        self::assertSame( 403, $noMobileProofResp->get_status() );
        self::assertSame( 'CLINIC_PATIENT_PROOF_FAILED', (string) ( $noMobileProofResp->get_data()['code'] ?? '' ) );
        $this->assertNoNationalIdProjectionOrLeak( $noMobileProofResp->get_data(), self::NID_PENDING, 'no mobile proof 403' );

        // 2. With OTP mobile proof: wrong NID, missing NID, query-string NID, and denied actors.
        $proofStore = new PatientSessionProofStore();
        $otpToken   = $proofStore->issue_non_persistent_auth_cookie( $this->userPending );
        $proofStore->record_mobile_proof( $this->userPending, self::MOBILE_PENDING, null, $otpToken );

        $wrongNidResp = $this->dispatchRestWithSessionToken( 'POST', '/clinic/v1/patient/link-record', [
            'clinic_id'   => $this->clinicA,
            'national_id' => self::NID_WRONG,
        ], $this->userPending, $otpToken );
        self::assertSame( 403, $wrongNidResp->get_status() );
        self::assertSame( 'CLINIC_PATIENT_PROOF_FAILED', (string) ( $wrongNidResp->get_data()['code'] ?? '' ) );
        $this->assertNoNationalIdProjectionOrLeak( $wrongNidResp->get_data(), self::NID_WRONG, 'wrong NID 403' );

        // Query-string national_id rejected cleanly.
        $this->withSessionCookie( $this->userPending, $otpToken, function (): void {
            $qsReq = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
            $qsReq->set_query_params( [ 'national_id' => self::NID_PENDING ] );
            $qsReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $qsReq->set_header( 'Content-Type', 'application/json' );
            $qsReq->set_body( (string) wp_json_encode( [ 'clinic_id' => $this->clinicA, 'national_id' => self::NID_PENDING ] ) );
            $qsResp = rest_do_request( $qsReq );
            self::assertSame( 403, $qsResp->get_status() );
            self::assertSame( 'CLINIC_PATIENT_PROOF_FAILED', (string) ( $qsResp->get_data()['code'] ?? '' ) );
        } );

        // 3. Denied actors: anonymous, pure staff, mixed patient+staff, closed patient.
        $anonResp = $this->dispatchRest( 'POST', '/clinic/v1/patient/link-record', [
            'clinic_id'   => $this->clinicA,
            'national_id' => self::NID_PENDING,
        ], 0, false );
        self::assertContains( $anonResp->get_status(), [ 401, 403 ] );

        foreach ( [ $this->userStaff, $this->userMixed, $this->userClosed ] as $deniedUid ) {
            $dTok = $proofStore->issue_non_persistent_auth_cookie( $deniedUid );
            $proofStore->record_mobile_proof( $deniedUid, self::MOBILE_PENDING, null, $dTok );
            $deniedResp = $this->dispatchRestWithSessionToken( 'POST', '/clinic/v1/patient/link-record', [
                'clinic_id'   => $this->clinicA,
                'national_id' => self::NID_PENDING,
            ], $deniedUid, $dTok );
            self::assertSame( 403, $deniedResp->get_status(), 'Non-pure/closed actor ' . $deniedUid . ' must receive 403.' );
        }
    }

    // =========================================================================
    // R1, R2, R4, R5: Post-audit fix & regression witnesses
    // =========================================================================

    public function testP4EmptyTokenCaptureClearsHalfIssuedSessionAuditsAndReturnsNeutralResponseWithWitness(): void {
        WP_Session_Tokens::get_instance( $this->userPending )->destroy_all();

        // Baseline neutral response (guest with no cpms_patient_logins account).
        $this->seedOtp( $this->clinicA, self::MOBILE_GUEST, '112233', OtpService::PURPOSE_LOGIN );
        $guestResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_GUEST,
            'code'   => '112233',
        ], 0, false );
        self::assertSame( 200, $guestResp->get_status() );
        $guestData = $guestResp->get_data()['data'] ?? null;
        self::assertSame( [
            'user_id'        => 0,
            'patient_links'  => [],
            'is_new_user'    => false,
            'session_issued' => false,
        ], $guestData );

        // Seam 1: force WP_Session_Tokens::create() -> wp_generate_password(43, false, false) to yield ''.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '223311', OtpService::PURPOSE_LOGIN );
        $clearBefore     = did_action( 'clear_auth_cookie' );
        $auditMaxIdBefore = (int) App::db()->fetchValue( 'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        $tokenFilterHit  = false;
        $emptyTokenHook  = static function ( string $password, int $length, bool $specialChars, bool $extraSpecialChars ) use ( &$tokenFilterHit ): string {
            if ( 43 === $length && ! $specialChars && ! $extraSpecialChars ) {
                $tokenFilterHit = true;
                return '';
            }
            return $password;
        };

        add_filter( 'random_password', $emptyTokenHook, 999, 4 );
        try {
            $failResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '223311',
            ], 0, false );
        } finally {
            remove_filter( 'random_password', $emptyTokenHook, 999 );
        }

        self::assertTrue( $tokenFilterHit, 'random_password seam must have fired during WP_Session_Tokens::create().' );
        self::assertSame( 200, $failResp->get_status() );
        self::assertSame( $guestData, $failResp->get_data()['data'] ?? null, 'Empty token capture must return identical neutral shape.' );
        self::assertGreaterThan( $clearBefore, did_action( 'clear_auth_cookie' ), 'Empty token failure must call wp_clear_auth_cookie().' );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all(), 'Half-issued uncaptured session must be destroyed.' );

        $failAudit = App::db()->fetchRow(
            'SELECT action, actor_wp_user_id, after_json, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1',
            [ $auditMaxIdBefore, 'LOGIN_SESSION_ISSUE_FAILED' ]
        );
        self::assertIsArray( $failAudit );
        self::assertSame( $this->userPending, (int) ( $failAudit['actor_wp_user_id'] ?? 0 ) );
        $afterDecoded = json_decode( (string) ( $failAudit['after_json'] ?? '' ), true );
        self::assertIsArray( $afterDecoded );
        self::assertCount( 2, $afterDecoded );
        self::assertSame( 'login', $afterDecoded['purpose'] ?? null );
        self::assertSame( 'session_token_empty', $afterDecoded['reason'] ?? null );
        self::assertNull( $failAudit['meta_json'] );
        $auditDump = (string) wp_json_encode( $failAudit, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( substr( self::MOBILE_PENDING, -4 ), $auditDump );
        $this->assertNoNationalIdLiteral( $auditDump, self::NID_PENDING, 'R1 empty-token failure audit' );

        // Positive witness: without the failure filter, the exact same path succeeds and issues a session.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '223399', OtpService::PURPOSE_LOGIN );
        $capturedToken = '';
        $tokenHook     = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$capturedToken ): void {
            $capturedToken = $token;
        };
        add_action( 'set_logged_in_cookie', $tokenHook, 10, 6 );
        try {
            $okResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '223399',
            ], 0, false );
        } finally {
            remove_action( 'set_logged_in_cookie', $tokenHook, 10 );
        }
        self::assertSame( 200, $okResp->get_status() );
        self::assertTrue( $okResp->get_data()['data']['session_issued'] ?? false );
        self::assertSame( $this->userPending, (int) ( $okResp->get_data()['data']['user_id'] ?? 0 ) );
        self::assertNotSame( '', $capturedToken );
        self::assertTrue( ( new PatientSessionProofStore() )->has_valid_mobile_proof( $this->userPending, self::MOBILE_PENDING, time(), $capturedToken ) );
    }

    public function testP4MobileProofPersistFailureClearsHalfIssuedSessionAuditsAndReturnsNeutralResponseWithWitness(): void {
        WP_Session_Tokens::get_instance( $this->userPending )->destroy_all();

        $this->seedOtp( $this->clinicA, self::MOBILE_GUEST, '114477', OtpService::PURPOSE_LOGIN );
        $guestResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_GUEST,
            'code'   => '114477',
        ], 0, false );
        self::assertSame( 200, $guestResp->get_status() );
        $guestData = $guestResp->get_data()['data'] ?? null;

        // Seam 2: WP_User_Meta_Session_Tokens subclass lets create() persist a real token,
        // rejects recording `cpms_mobile_proofs` so get_live_session() returns null (record_mobile_proof() === false),
        // and delegates destroy() to parent so we verify OtpService destroys the half-issued session.
        if ( ! class_exists( \WP_User_Meta_Session_Tokens::class ) ) {
            require_once ABSPATH . WPINC . '/class-wp-user-meta-session-tokens.php';
        }
        $failingManager = new class( 0 ) extends \WP_User_Meta_Session_Tokens {
            public static bool $simulatePersistFailure = false;
            public static int $createCount             = 0;
            public static int $proofAttemptCount       = 0;
            public static int $destroyCount            = 0;

            public function __construct( int $user_id ) {
                parent::__construct( $user_id );
            }

            public static function resetCounters(): void {
                self::$simulatePersistFailure = false;
                self::$createCount            = 0;
                self::$proofAttemptCount      = 0;
                self::$destroyCount           = 0;
            }

            /** @return array<string, mixed>|null */
            protected function get_session( $verifier ) {
                if ( self::$simulatePersistFailure ) {
                    return null;
                }
                return parent::get_session( $verifier );
            }

            /** @param array<string, mixed>|null $session */
            protected function update_session( $verifier, $session = null ) {
                if ( null === $session ) {
                    ++self::$destroyCount;
                    parent::update_session( $verifier, null );
                    return;
                }
                if ( isset( $session[ PatientSessionProofStore::KEY_MOBILE_PROOFS ] ) ) {
                    ++self::$proofAttemptCount;
                    self::$simulatePersistFailure = true;
                    return;
                }
                ++self::$createCount;
                parent::update_session( $verifier, $session );
            }
        };
        $failingClass = get_class( $failingManager );
        $failingClass::resetCounters();

        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '334411', OtpService::PURPOSE_LOGIN );
        $clearBefore      = did_action( 'clear_auth_cookie' );
        $cookieBefore     = did_action( 'set_logged_in_cookie' );
        $auditMaxIdBefore = (int) App::db()->fetchValue( 'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
        $managerFilter    = static fn (): string => $failingClass;

        add_filter( 'session_token_manager', $managerFilter, 999 );
        try {
            $failResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '334411',
            ], 0, false );
        } finally {
            remove_filter( 'session_token_manager', $managerFilter, 999 );
        }

        $createCount       = $failingClass::$createCount;
        $proofAttemptCount = $failingClass::$proofAttemptCount;
        $destroyCount      = $failingClass::$destroyCount;
        $failingClass::resetCounters();

        self::assertSame( 1, $createCount, 'Session token creation must have run before proof persistence.' );
        self::assertSame( 1, $proofAttemptCount, 'record_mobile_proof() must have attempted update_session.' );
        self::assertGreaterThanOrEqual( 1, $destroyCount, 'Half-issued session must be destroyed after proof persistence failure.' );
        self::assertGreaterThan( $cookieBefore, did_action( 'set_logged_in_cookie' ) );
        self::assertGreaterThan( $clearBefore, did_action( 'clear_auth_cookie' ), 'Proof persistence failure must call wp_clear_auth_cookie().' );
        self::assertSame( 200, $failResp->get_status() );
        self::assertSame( $guestData, $failResp->get_data()['data'] ?? null, 'Proof persist failure must return identical neutral shape.' );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all(), 'Real usermeta must hold zero leftover sessions.' );

        $failAudit = App::db()->fetchRow(
            'SELECT action, actor_wp_user_id, after_json, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1',
            [ $auditMaxIdBefore, 'LOGIN_SESSION_ISSUE_FAILED' ]
        );
        self::assertIsArray( $failAudit );
        self::assertSame( $this->userPending, (int) ( $failAudit['actor_wp_user_id'] ?? 0 ) );
        $afterDecoded = json_decode( (string) ( $failAudit['after_json'] ?? '' ), true );
        self::assertIsArray( $afterDecoded );
        self::assertCount( 2, $afterDecoded );
        self::assertSame( 'login', $afterDecoded['purpose'] ?? null );
        self::assertSame( 'mobile_proof_persist_failed', $afterDecoded['reason'] ?? null );
        self::assertNull( $failAudit['meta_json'] );
        $auditDump = (string) wp_json_encode( $failAudit, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( substr( self::MOBILE_PENDING, -4 ), $auditDump );
        $this->assertNoNationalIdLiteral( $auditDump, self::NID_PENDING, 'R1 proof-persist failure audit' );

        // Positive witness: clean path still issues session + mobile proof.
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '334499', OtpService::PURPOSE_LOGIN );
        $witnessToken = '';
        $captureHook  = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$witnessToken ): void {
            $witnessToken = $token;
        };
        add_action( 'set_logged_in_cookie', $captureHook, 10, 6 );
        try {
            $okResp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', [
                'mobile' => self::MOBILE_PENDING,
                'code'   => '334499',
            ], 0, false );
        } finally {
            remove_action( 'set_logged_in_cookie', $captureHook, 10 );
        }
        self::assertSame( 200, $okResp->get_status() );
        self::assertTrue( $okResp->get_data()['data']['session_issued'] ?? false );
        self::assertSame( $this->userPending, (int) ( $okResp->get_data()['data']['user_id'] ?? 0 ) );
        self::assertNotSame( '', $witnessToken );
        self::assertTrue( ( new PatientSessionProofStore() )->has_valid_mobile_proof( $this->userPending, self::MOBILE_PENDING, time(), $witnessToken ) );
    }

    public function testR2NationalIdDigitMappingProducesExactPersianAndArabicIndicVariants(): void {
        $variants = $this->toNationalIdVariants( self::NID_PENDING );
        self::assertSame( '0010000003', $variants['ascii'] );
        self::assertSame( '۰۰۱۰۰۰۰۰۰۳', $variants['persian'] );
        self::assertSame( '٠٠١٠٠٠٠٠٠٣', $variants['arabic'] );
        self::assertSame( '***0003', $variants['masked'] );
        self::assertNotSame( self::NID_PENDING, $variants['persian'] );
        self::assertNotSame( self::NID_PENDING, $variants['arabic'] );

        $allDigits = $this->toNationalIdVariants( '0123456789' );
        self::assertSame( '۰۱۲۳۴۵۶۷۸۹', $allDigits['persian'] );
        self::assertSame( '٠١٢٣٤٥٦٧٨٩', $allDigits['arabic'] );
    }

    public function testR4PatientMeRestRouteArgsExposeNoNationalIdAcrossAllMethods(): void {
        $routes = rest_get_server()->get_routes();
        self::assertArrayHasKey( '/clinic/v1/patient/me', $routes );
        $handlers = $routes['/clinic/v1/patient/me'];
        self::assertGreaterThanOrEqual( 2, count( $handlers ) );

        $sawGet      = false;
        $sawEditable = false;
        foreach ( $handlers as $handler ) {
            $methods = is_array( $handler['methods'] ?? null ) ? $handler['methods'] : [];
            $args    = is_array( $handler['args'] ?? null ) ? $handler['args'] : [];
            self::assertArrayNotHasKey( 'national_id', $args, 'REST /patient/me must not expose national_id arg on any method.' );

            if ( ! empty( $methods['GET'] ) ) {
                $sawGet = true;
                self::assertArrayHasKey( 'link_id', $args, 'Positive witness: GET /patient/me exposes link_id.' );
            }
            if ( ! empty( $methods['POST'] ) || ! empty( $methods['PUT'] ) || ! empty( $methods['PATCH'] ) ) {
                $sawEditable = true;
                self::assertArrayHasKey( 'first_name', $args, 'Positive witness: editable /patient/me exposes first_name.' );
                self::assertArrayHasKey( 'link_id', $args, 'Positive witness: editable /patient/me exposes link_id.' );
            }
        }

        self::assertTrue( $sawGet, 'GET /patient/me handler must be registered.' );
        self::assertTrue( $sawEditable, 'POST/PUT/PATCH /patient/me handler must be registered.' );
    }

    public function testR5RealOtpVerifyDeniesPureStaffAndDoctorRolesAndMixedActorWithPurePatientWitness(): void {
        // Baseline neutral guest response through real OtpService::verify().
        $this->seedOtp( $this->clinicA, self::MOBILE_GUEST, '445511', OtpService::PURPOSE_LOGIN );
        $guestRes = App::otpService()->verify( self::MOBILE_GUEST, '445511', OtpService::PURPOSE_LOGIN );
        self::assertSame( [
            'user_id'        => 0,
            'patient_links'  => [],
            'is_new_user'    => false,
            'session_issued' => false,
        ], $guestRes );

        $logins     = new PatientLoginRepository( App::db() );
        $staffRoles = [
            RolesAndCapabilities::ROLE_SECRETARY  => '09127770101',
            RolesAndCapabilities::ROLE_DOCTOR     => '09127770102',
            RolesAndCapabilities::ROLE_MANAGER    => '09127770103',
            RolesAndCapabilities::ROLE_ACCOUNTANT => '09127770104',
        ];

        foreach ( $staffRoles as $role => $mobile ) {
            $uid = $this->makeUser( 'pls1_r5_' . $role . '_' . uniqid(), $role );
            cpms_test_seed_membership( $uid, $this->clinicA, $role );
            $logins->insert_pending_account( $uid, $mobile, 'Staff', $role );
            $this->seedOtp( $this->clinicA, $mobile, '556611', OtpService::PURPOSE_LOGIN );

            $loggedInBefore = did_action( 'set_logged_in_cookie' );
            $authBefore     = did_action( 'set_auth_cookie' );
            $res            = App::otpService()->verify( $mobile, '556611', OtpService::PURPOSE_LOGIN );

            self::assertSame( $loggedInBefore, did_action( 'set_logged_in_cookie' ), $role . ' must trigger zero logged-in cookies.' );
            self::assertSame( $authBefore, did_action( 'set_auth_cookie' ), $role . ' must trigger zero auth cookies.' );
            self::assertSame( [], WP_Session_Tokens::get_instance( $uid )->get_all(), $role . ' must receive zero session tokens.' );
            self::assertSame( $guestRes, $res, $role . ' response must match guest neutral shape.' );
        }

        // Mixed patient+staff actor also denied with zero cookies/sessions.
        $this->seedOtp( $this->clinicA, self::MOBILE_MIXED, '556622', OtpService::PURPOSE_LOGIN );
        $loggedInBefore = did_action( 'set_logged_in_cookie' );
        $authBefore     = did_action( 'set_auth_cookie' );
        $mixedRes       = App::otpService()->verify( self::MOBILE_MIXED, '556622', OtpService::PURPOSE_LOGIN );
        self::assertSame( $loggedInBefore, did_action( 'set_logged_in_cookie' ) );
        self::assertSame( $authBefore, did_action( 'set_auth_cookie' ) );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userMixed )->get_all() );
        self::assertSame( $guestRes, $mixedRes );

        // Positive witness: pure patient on the same real OtpService::verify() path gets a session.
        WP_Session_Tokens::get_instance( $this->userPending )->destroy_all();
        $this->seedOtp( $this->clinicA, self::MOBILE_PENDING, '556633', OtpService::PURPOSE_LOGIN );
        $loggedInBefore = did_action( 'set_logged_in_cookie' );
        $patientRes     = App::otpService()->verify( self::MOBILE_PENDING, '556633', OtpService::PURPOSE_LOGIN );
        self::assertGreaterThan( $loggedInBefore, did_action( 'set_logged_in_cookie' ) );
        self::assertTrue( $patientRes['session_issued'] );
        self::assertSame( $this->userPending, $patientRes['user_id'] );
        self::assertCount( 1, WP_Session_Tokens::get_instance( $this->userPending )->get_all() );
    }

    // =========================================================================
    // T2 & T3: Staff archive binding/session closure & duplicate-NID pre-check
    // =========================================================================

    public function testStaffArchiveViaPatientsPutClosesBindingAndDestroysSessionWithControls(): void {
        $proofStore  = new PatientSessionProofStore();
        $logins      = new PatientLoginRepository( App::db() );
        $tokenTarget = $proofStore->issue_non_persistent_auth_cookie( $this->userPending );
        $proofStore->record_mobile_proof( $this->userPending, self::MOBILE_PENDING, null, $tokenTarget );
        App::patient_binding_promoter()->promote(
            $this->userPending,
            $this->clinicA,
            self::MOBILE_PENDING,
            self::NID_PENDING,
            null,
            '198.51.100.1',
            null,
            $tokenTarget
        );

        // Control (i): second patient in the same clinic with active binding and live WP session token.
        $userOther  = $this->makeUser( 'pls1_arch_ctrl_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins->insert_pending_account( $userOther, self::MOBILE_GUEST, 'بیمار', 'شاهد' );
        $tokenOther = $proofStore->issue_non_persistent_auth_cookie( $userOther );
        $proofStore->record_mobile_proof( $userOther, self::MOBILE_GUEST, null, $tokenOther );
        App::patient_binding_promoter()->promote(
            $userOther,
            $this->clinicA,
            self::MOBILE_GUEST,
            self::NID_OTHER,
            null,
            '198.51.100.2',
            null,
            $tokenOther
        );

        // Assert BEFORE: both bindings are active with non-null live_key and real WP session tokens present.
        $targetBindingBefore = $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true );
        self::assertIsArray( $targetBindingBefore );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $targetBindingBefore['state'] ?? null );
        self::assertNotNull( $targetBindingBefore['live_key'] ?? null );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->userPending )->get( $tokenTarget ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $tokenTarget ) );

        $otherBindingBefore = $logins->find_binding( $userOther, $this->clinicA, $this->patientOther, true );
        self::assertIsArray( $otherBindingBefore );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $otherBindingBefore['state'] ?? null );
        self::assertNotNull( $otherBindingBefore['live_key'] ?? null );
        self::assertIsArray( WP_Session_Tokens::get_instance( $userOther )->get( $tokenOther ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $userOther, $this->patientOther, $this->clinicA, time(), $tokenOther ) );

        // Control (ii): staff edit of a NON-NID, NON-status field leaves binding active and token alive.
        $nonStatusResp = $this->dispatchRest(
            'PUT',
            '/clinic/v1/patients/' . $this->patientPending,
            [ 'address' => 'تهران، خیابان ولیعصر' ],
            $this->userStaff
        );
        self::assertSame( 200, $nonStatusResp->get_status() );
        self::assertSame(
            'تهران، خیابان ولیعصر',
            (string) App::db()->fetchValue( 'SELECT address FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientPending ] )
        );
        self::assertSame(
            'active',
            (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientPending ] )
        );
        $targetBindingAfterEdit = $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true );
        self::assertIsArray( $targetBindingAfterEdit );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $targetBindingAfterEdit['state'] ?? null );
        self::assertNotNull( $targetBindingAfterEdit['live_key'] ?? null );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->userPending )->get( $tokenTarget ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $tokenTarget ) );

        // Act: staff archives target patient via real PUT /clinic/v1/patients/{id}.
        $archiveResp = $this->dispatchRest(
            'PUT',
            '/clinic/v1/patients/' . $this->patientPending,
            [ 'status' => 'archived' ],
            $this->userStaff
        );
        self::assertSame( 200, $archiveResp->get_status() );
        self::assertSame( 'archived', (string) ( $archiveResp->get_data()['data']['status'] ?? '' ) );
        self::assertSame(
            'archived',
            (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientPending ] )
        );

        // Assert AFTER: target binding is closed, live_key is null, and original WP session token is destroyed.
        self::assertNull( $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true ) );
        $closedBinding = $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, false );
        self::assertIsArray( $closedBinding );
        self::assertSame( PatientLoginRepository::STATE_CLOSED, $closedBinding['state'] ?? null );
        self::assertArrayHasKey( 'live_key', $closedBinding );
        self::assertNull( $closedBinding['live_key'] );
        self::assertNull( WP_Session_Tokens::get_instance( $this->userPending )->get( $tokenTarget ), 'Target WP session token must be destroyed after staff archive.' );
        self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all(), 'Target user must have zero surviving sessions.' );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $tokenTarget ) );

        // Control (i) AFTER: second patient in the same clinic stays active and its token survives.
        $otherBindingAfter = $logins->find_binding( $userOther, $this->clinicA, $this->patientOther, true );
        self::assertIsArray( $otherBindingAfter );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $otherBindingAfter['state'] ?? null );
        self::assertNotNull( $otherBindingAfter['live_key'] ?? null );
        self::assertIsArray( WP_Session_Tokens::get_instance( $userOther )->get( $tokenOther ), 'Control (i): second patient session token in same clinic must survive.' );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $userOther, $this->patientOther, $this->clinicA, time(), $tokenOther ) );
    }

    public function testStaffDuplicateNationalIdPreCheckRejectsBeforeWriteAndLeavesBindingAlive(): void {
        $proofStore = new PatientSessionProofStore();
        $logins     = new PatientLoginRepository( App::db() );
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->userPending );
        $proofStore->record_mobile_proof( $this->userPending, self::MOBILE_PENDING, null, $token );
        App::patient_binding_promoter()->promote(
            $this->userPending,
            $this->clinicA,
            self::MOBILE_PENDING,
            self::NID_PENDING,
            null,
            '198.51.100.3',
            null,
            $token
        );

        $patientTable = App::db()->table( 'cpms_patients' );
        $auditTable   = App::db()->table( 'cpms_audit_logs' );
        $targetBefore = App::db()->fetchRow( 'SELECT * FROM ' . $patientTable . ' WHERE id = %d', [ $this->patientPending ] );
        $otherBefore  = App::db()->fetchRow( 'SELECT * FROM ' . $patientTable . ' WHERE id = %d', [ $this->patientOther ] );
        $auditBefore  = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . $auditTable );
        self::assertIsArray( $targetBefore );
        self::assertIsArray( $otherBefore );
        self::assertSame( self::NID_OTHER, (string) ( $otherBefore['national_id'] ?? '' ) );

        $precheckSqlPattern  = '/^\s*SELECT\s+id\s+FROM\s+`?' . preg_quote( $patientTable, '/' ) . '`?\s+WHERE\s+clinic_id\s*=\s*\d+\s+AND\s+national_id\s*=/i';
        $updateSqlPattern    = '/^\s*UPDATE\s+`?' . preg_quote( $patientTable, '/' ) . '`?\s+SET\b/i';
        $precheckSelectHits  = 0;
        $patientUpdateHits   = 0;
        $sqlSpy              = static function ( $query ) use ( $precheckSqlPattern, $updateSqlPattern, &$precheckSelectHits, &$patientUpdateHits ) {
            $sql = (string) $query;
            if ( 1 === preg_match( $precheckSqlPattern, $sql ) ) {
                ++$precheckSelectHits;
            }
            if ( 1 === preg_match( $updateSqlPattern, $sql ) ) {
                ++$patientUpdateHits;
            }

            return $query;
        };

        add_filter( 'query', $sqlSpy, 5 );
        try {
            $dupResp = $this->dispatchRest(
                'PUT',
                '/clinic/v1/patients/' . $this->patientPending,
                [ 'national_id' => self::NID_OTHER ],
                $this->userStaff
            );
        } finally {
            remove_filter( 'query', $sqlSpy, 5 );
        }

        // Exact bounded error shape from PatientService.php:575, distinct from raw SQL / DB unique collision.
        self::assertSame( 400, $dupResp->get_status() );
        $dupData = $dupResp->get_data();
        self::assertSame( 'CLINIC_VALIDATION_FAILED', $dupData['code'] ?? null );
        self::assertSame( 'این کد ملی متعلق به بیمار دیگری است', $dupData['message'] ?? null );
        self::assertSame( 400, $dupData['data']['status'] ?? null );
        $encodedErr = (string) wp_json_encode( $dupData, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( 'Duplicate entry', $encodedErr );
        self::assertStringNotContainsString( 'u_pat_nid', $encodedErr );

        // Pre-check ran find_by_national_id() and threw BEFORE transactional() / UPDATE cpms_patients.
        self::assertSame( 1, $precheckSelectHits, 'Staff duplicate-NID pre-check query must run once.' );
        self::assertSame( 0, $patientUpdateHits, 'Pre-check must reject before any UPDATE cpms_patients reaches the DB boundary.' );

        // Zero mutation on target row, competing patient row, or audit log; binding active and token alive.
        self::assertSame( $targetBefore, App::db()->fetchRow( 'SELECT * FROM ' . $patientTable . ' WHERE id = %d', [ $this->patientPending ] ) );
        self::assertSame( $otherBefore, App::db()->fetchRow( 'SELECT * FROM ' . $patientTable . ' WHERE id = %d', [ $this->patientOther ] ) );
        self::assertSame( $auditBefore, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . $auditTable ) );

        $bindingAfter = $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true );
        self::assertIsArray( $bindingAfter );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $bindingAfter['state'] ?? null );
        self::assertNotNull( $bindingAfter['live_key'] ?? null );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->userPending )->get( $token ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->userPending, $this->patientPending, $this->clinicA, time(), $token ) );

        // Positive witness: submitting the target patient's own NID passes the pre-check ($other['id'] !== $patient_id).
        $samePatientResp = $this->dispatchRest(
            'PUT',
            '/clinic/v1/patients/' . $this->patientPending,
            [
                'national_id' => self::NID_PENDING,
                'first_name'  => 'PrecheckWitness',
            ],
            $this->userStaff
        );
        self::assertSame( 200, $samePatientResp->get_status() );
        self::assertSame( 'PrecheckWitness', (string) ( $samePatientResp->get_data()['data']['first_name'] ?? '' ) );
        self::assertIsArray( $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true ) );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->userPending )->get( $token ) );

        // W1: Valid unique-NID staff edit passes pre-check, executes 1 UPDATE cpms_patients row, and closes binding (§7.6).
        $uniqueNid          = '0499370899';
        $precheckSelectHits = 0;
        $patientUpdateHits  = 0;
        add_filter( 'query', $sqlSpy, 5 );
        try {
            $uniqueResp = $this->dispatchRest(
                'PUT',
                '/clinic/v1/patients/' . $this->patientPending,
                [ 'national_id' => $uniqueNid ],
                $this->userStaff
            );
        } finally {
            remove_filter( 'query', $sqlSpy, 5 );
        }
        self::assertSame( 200, $uniqueResp->get_status() );
        self::assertSame( 1, $precheckSelectHits, 'Valid unique-NID staff edit must run the pre-check query once.' );
        self::assertSame( 1, $patientUpdateHits, 'Valid unique-NID staff edit must execute exactly 1 UPDATE cpms_patients statement.' );
        $updatedRow = App::db()->fetchRow( 'SELECT national_id FROM ' . $patientTable . ' WHERE id = %d', [ $this->patientPending ] );
        self::assertSame( $uniqueNid, (string) ( $updatedRow['national_id'] ?? '' ) );
        self::assertNull( $logins->find_binding( $this->userPending, $this->clinicA, $this->patientPending, true ), 'NID correction must close active binding (§7.6).' );
        self::assertNull( WP_Session_Tokens::get_instance( $this->userPending )->get( $token ), 'NID correction must destroy patient sessions (§7.6).' );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function renderPortalAs( int $userId, string $sessionToken ): string {
        return $this->withSessionCookie( $userId, $sessionToken, static function (): string {
            ob_start();
            PatientPortalPage::render();
            return (string) ob_get_clean();
        } );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchRest( string $method, string $route, array $params, int $userId, bool $withNonce = true ): WP_REST_Response {
        PatientTwoProofTestHelper::acting_as( $userId );
        $request = new WP_REST_Request( $method, $route );
        if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $params ) );
        } else {
            $request->set_query_params( $params );
        }
        if ( $withNonce ) {
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }

        return rest_do_request( $request );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchRestWithSessionToken( string $method, string $route, array $params, int $userId, string $sessionToken ): WP_REST_Response {
        return $this->withSessionCookie(
            $userId,
            $sessionToken,
            fn (): WP_REST_Response => $this->dispatchRest( $method, $route, $params, $userId, true )
        );
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withSessionCookie( int $userId, string $sessionToken, callable $callback ): mixed {
        $prevCookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
        $exp        = time() + PatientSessionProofStore::SESSION_TTL_SECONDS;
        PatientTwoProofTestHelper::acting_as( $userId );
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $userId, $exp, 'logged_in', $sessionToken );
        App::patient_session_scope()->set_in_process_session_token( $userId, $sessionToken );
        try {
            return $callback();
        } finally {
            if ( null === $prevCookie ) {
                unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            } else {
                $_COOKIE[ LOGGED_IN_COOKIE ] = $prevCookie;
            }
        }
    }

    private function assertNoNationalIdProjectionOrLeak( mixed $payload, string $nid, string $context ): void {
        if ( is_array( $payload ) ) {
            self::assertArrayNotHasKey( 'national_id', $payload, $context . ': array must not project national_id.' );
            if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
                self::assertArrayNotHasKey( 'national_id', $payload['data'], $context . ': data must not project national_id.' );
            }
        }
        $encoded = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( '"national_id"', $encoded, $context . ': JSON must not contain national_id key.' );
        $this->assertNoNationalIdLiteral( $encoded, $nid, $context );
    }

    /**
     * @return array{ascii:string, persian:string, arabic:string, masked:string}
     */
    private function toNationalIdVariants( string $nid ): array {
        $persianMap = [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ];
        $arabicMap  = [
            '0' => '٠',
            '1' => '١',
            '2' => '٢',
            '3' => '٣',
            '4' => '٤',
            '5' => '٥',
            '6' => '٦',
            '7' => '٧',
            '8' => '٨',
            '9' => '٩',
        ];

        return [
            'ascii'   => $nid,
            'persian' => strtr( $nid, $persianMap ),
            'arabic'  => strtr( $nid, $arabicMap ),
            'masked'  => '***' . substr( $nid, -4 ),
        ];
    }

    private function assertNoNationalIdLiteral( string $text, string $nid, string $context ): void {
        if ( '' === $nid ) {
            return;
        }
        foreach ( $this->toNationalIdVariants( $nid ) as $variant ) {
            self::assertStringNotContainsString( $variant, $text, $context . ': must never leak national_id variant.' );
        }
    }

    private function insertPatient( int $clinicId, string $mrn, string $mobile, string $nationalId ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patients',
            [
                'clinic_id'   => $clinicId,
                'mrn'         => $mrn,
                'first_name'  => 'Slice1',
                'last_name'   => 'Patient',
                'mobile'      => $mobile,
                'national_id' => $nationalId,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    private function seedOtp( int $clinicId, string $mobile, string $code, string $purpose, int $expiresOffset = 180 ): void {
        $pepper = defined( 'CPMS_PEPPER' ) && '' !== (string) CPMS_PEPPER
            ? (string) CPMS_PEPPER
            : (string) get_option( 'cpms_otp_pepper', '' );
        if ( '' === $pepper ) {
            $pepper = bin2hex( random_bytes( 32 ) );
            update_option( 'cpms_otp_pepper', $pepper, 'no' );
        }

        App::db()->insert(
            'cpms_otp_tokens',
            [
                'clinic_id'  => $clinicId,
                'mobile'     => $mobile,
                'purpose'    => $purpose,
                'code_hash'  => OtpPolicy::hashCode( $code, $pepper ),
                'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expiresOffset ) . '.000',
                'attempts'   => 0,
                'created_at' => App::db()->nowUtcSql(),
            ]
        );
    }

    private function makeUser( string $login, string $role ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $userId;
    }
}

<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
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
        try {
            App::db()->transactional( function (): void {
                App::otpService()->verify( self::MOBILE_PENDING, '778899', OtpService::PURPOSE_LOGIN );
            } );
            self::fail( 'Nested transaction on PURPOSE_LOGIN must fail closed.' );
        } catch ( \Throwable $e ) {
            self::assertSame( [], WP_Session_Tokens::get_instance( $this->userPending )->get_all() );
        }

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

    private function assertNoNationalIdLiteral( string $text, string $nid, string $context ): void {
        if ( '' === $nid ) {
            return;
        }
        $persian    = strtr( $nid, '0123456789', '۰۱۲۳۴۵۶۷۸۹' );
        $arabic     = strtr( $nid, '0123456789', '٠١٢٣٤٥٦٧٨٩' );
        $maskedTail = '***' . substr( $nid, -4 );

        foreach ( [ $nid, $persian, $arabic, $maskedTail ] as $variant ) {
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

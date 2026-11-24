<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\FinanceDelegationPage;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientCredentialGuard;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\PortalLogin;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use IXR_Error;
use RuntimeException;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_User;
use WPDieException;
use wp_xmlrpc_server;

/**
 * Pin-test matrix proving what an account-only (`PURPOSE_LOGIN`) or Step-1
 * pending (`PURPOSE_VERIFY_MOBILE` + `clinic_id`) patient WP session issued by
 * `OtpService::verify()` can reach across every surface class, plus minimal
 * fail-closed fixes on `/wp/v2/users*` and `wp-admin` `profile.php`
 * (`personal_options_update`).
 */
final class PatientAccountOnlySurfaceAuditTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_ACCOUNT_ACTIVE  = '09129990001';
    private const MOBILE_ACCOUNT_PENDING = '09129990002';
    private const MOBILE_STEP1_NEW       = '09129990003';
    private const MOBILE_CLINIC_PATIENT  = '09129990004';
    private const NID_EXISTING           = '0010000003';
    private const NID_NEW_STEP2          = '0499370899';
    private const NID_WRONG              = '0011223344';

    private int $clinicPublic;
    private int $clinicPrivate;
    private int $locationPublic;
    private int $clinicianPublic;
    private int $patientRecord;
    private int $userAccountActive;
    private int $userAccountPending;
    private int $staffDoctorUser;
    private string $staffDoctorPass = 'DocPass#Audit2026!';
    private string $patientKnownPass = 'PatPass#Audit2026!';

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        PatientTwoProofTestHelper::reset();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->clinicPublic = 1;
        Settings::flushCache();
        App::settingsFactory()->forClinic( $this->clinicPublic )->set( 'public.listing_opt_in', true );

        $orgId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $this->clinicPublic ]
        );
        self::assertGreaterThan( 0, $orgId );

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $orgId,
                'name'            => 'Private Audit Clinic Z',
                'slug'            => 'private-audit-z-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicPrivate = $db->wpdb_last_insert_id();
        App::settingsFactory()->forClinic( $this->clinicPrivate )->set( 'public.listing_opt_in', false );

        $this->locationPublic = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d LIMIT 1',
            [ $this->clinicPublic ]
        );
        if ( $this->locationPublic <= 0 ) {
            $db->insert(
                'cpms_locations',
                [
                    'clinic_id'  => $this->clinicPublic,
                    'name'       => 'Audit Main Loc',
                    'timezone'   => 'UTC',
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $this->locationPublic = $db->wpdb_last_insert_id();
        }

        $this->staffDoctorUser = $this->createUser(
            'audit_doc_' . uniqid(),
            $this->staffDoctorPass,
            RolesAndCapabilities::ROLE_DOCTOR
        );
        cpms_test_seed_membership( $this->staffDoctorUser, $this->clinicPublic, RolesAndCapabilities::ROLE_DOCTOR );
        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicPublic,
                'wp_user_id' => $this->staffDoctorUser,
                'full_name'  => 'دکتر ممیزی سیستم',
                'specialty'  => 'عمومی',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->clinicianPublic = $db->wpdb_last_insert_id();

        $db->insert(
            'cpms_patients',
            [
                'clinic_id'   => $this->clinicPublic,
                'mrn'         => 'MR-AUD-' . uniqid(),
                'first_name'  => 'سارا',
                'last_name'   => 'ممیزی',
                'mobile'      => self::MOBILE_CLINIC_PATIENT,
                'national_id' => self::NID_EXISTING,
                'birth_date'  => '1991-05-12',
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $this->patientRecord = $db->wpdb_last_insert_id();

        $this->userAccountActive = $this->createUser(
            'audit_pat_act_' . uniqid(),
            $this->patientKnownPass,
            RolesAndCapabilities::ROLE_PATIENT
        );
        $this->userAccountPending = $this->createUser(
            'audit_pat_pend_' . uniqid(),
            $this->patientKnownPass,
            RolesAndCapabilities::ROLE_PATIENT
        );

        $logins          = new PatientLoginRepository( $db );
        $activeAccountId = $logins->insert_pending_account( $this->userAccountActive, self::MOBILE_ACCOUNT_ACTIVE, 'بیمار', 'بدون‌پرونده' );
        $logins->activate_account_from_pending( $activeAccountId, $this->userAccountActive );
        $logins->insert_pending_account( $this->userAccountPending, self::MOBILE_ACCOUNT_PENDING, 'بیمار', 'درانتظار' );

        $this->installSignedExpiration( time() + 30 * 86400 );
        PatientTwoProofTestHelper::acting_as( 0 );
    }

    protected function tearDown(): void {
        ScopeContext::clear();
        remove_all_filters( 'cpms_license_public_key' );
        PatientTwoProofTestHelper::reset();
        $_GET                      = [];
        $_POST                     = [];
        $_REQUEST                  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset( $GLOBALS['pagenow'], $_COOKIE[ LOGGED_IN_COOKIE ] );
        App::resetScope();
        parent::tearDown();
    }

    public function testOtpSessionIssuanceAnd8hNonPersistentCookieAndPendingStateInvariant(): void {
        $capturedCookieArgs = [];
        $cookieHook         = static function (
            string $cookie,
            int $expire,
            int $expiration,
            int $userId,
            string $scheme,
            string $token
        ) use ( &$capturedCookieArgs ): void {
            $capturedCookieArgs = [
                'expire'     => $expire,
                'expiration' => $expiration,
                'user_id'    => $userId,
                'token'      => $token,
            ];
        };

        // 1) PURPOSE_LOGIN on active 0-binding account -> 8h non-persistent session, LEVEL_NONE.
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '112233', OtpService::PURPOSE_LOGIN );
        $beforeTs = time();
        add_action( 'set_logged_in_cookie', $cookieHook, 10, 6 );
        try {
            $loginResp = $this->dispatchRest(
                'POST',
                '/clinic/v1/otp/verify',
                [
                    'mobile' => self::MOBILE_ACCOUNT_ACTIVE,
                    'code'   => '112233',
                ]
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $cookieHook, 10 );
        }
        $afterTs = time();
        self::assertSame( 200, $loginResp->get_status() );
        $loginData = $loginResp->get_data()['data'] ?? [];
        self::assertSame( $this->userAccountActive, (int) ( $loginData['user_id'] ?? 0 ) );
        self::assertTrue( (bool) ( $loginData['session_issued'] ?? false ) );
        self::assertSame( [], $loginData['patient_links'] ?? null );
        self::assertFalse( (bool) ( $loginData['is_new_user'] ?? true ) );
        self::assertSame( 0, $capturedCookieArgs['expire'] ?? -1, 'Patient auth cookie must be non-persistent (remember=false).' );
        self::assertGreaterThanOrEqual( $beforeTs + PatientSessionProofStore::SESSION_TTL_SECONDS - 5, $capturedCookieArgs['expiration'] ?? 0 );
        self::assertLessThanOrEqual( $afterTs + PatientSessionProofStore::SESSION_TTL_SECONDS + 5, $capturedCookieArgs['expiration'] ?? 0 );
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->userAccountActive, null, $this->clinicPublic, time(), (string) ( $capturedCookieArgs['token'] ?? '' ) )
        );
        self::assertSame( [], App::patient_access()->proved_patient_ids( $this->userAccountActive ) );

        // 2) PURPOSE_LOGIN on pending account preserves STATE_PENDING and grants zero patient access.
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_PENDING, '223344', OtpService::PURPOSE_LOGIN );
        $pendingLogin = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_PENDING, '223344', OtpService::PURPOSE_LOGIN );
        self::assertSame( 200, $pendingLogin['response']->get_status() );
        self::assertSame( $this->userAccountPending, $pendingLogin['user_id'] );
        $accountRow = ( new PatientLoginRepository( App::db() ) )->find_account_by_user_id( $this->userAccountPending );
        self::assertSame( PatientLoginRepository::STATE_PENDING, $accountRow['state'] ?? null );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->userAccountPending, null, $this->clinicPublic, time(), $pendingLogin['token'] ) );

        // 3) Clinic-less PURPOSE_VERIFY_MOBILE creates no user and issues no session.
        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '334455', OtpService::PURPOSE_VERIFY_MOBILE );
        $clinicLess = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '334455', OtpService::PURPOSE_VERIFY_MOBILE );
        self::assertSame( 200, $clinicLess['response']->get_status() );
        self::assertSame( 0, $clinicLess['user_id'] );
        self::assertFalse( $clinicLess['session_issued'] );

        // 4) Step-1 PURPOSE_VERIFY_MOBILE with trusted public clinic_id creates STATE_PENDING only (0 cpms_patients rows).
        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '445566', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '445566', OtpService::PURPOSE_VERIFY_MOBILE, $this->clinicPublic );
        self::assertSame( 200, $step1['response']->get_status() );
        self::assertGreaterThan( 0, $step1['user_id'] );
        self::assertTrue( $step1['session_issued'] );
        self::assertTrue( (bool) ( $step1['response']->get_data()['data']['pending_registration'] ?? false ) );
        self::assertSame( 0, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE mobile = %s', [ self::MOBILE_STEP1_NEW ] ) );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $step1['user_id'], null, $this->clinicPublic, time(), $step1['token'] ) );
    }

    public function testClinicRestPatientAndStaffEndpointsDenyAccountOnlyAndStep1PendingSessions(): void {
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '121212', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '121212', OtpService::PURPOSE_LOGIN );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '232323', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '232323', OtpService::PURPOSE_VERIFY_MOBILE, $this->clinicPublic );

        $patientRoutes = [
            [ 'GET', '/clinic/v1/patient/me', [], [] ],
            [ 'POST', '/clinic/v1/patient/me', [ 'first_name' => 'Hack' ], [] ],
            [ 'GET', '/clinic/v1/patient/my-records', [], [] ],
            [ 'POST', '/clinic/v1/patient/enroll', [ 'clinic_id' => $this->clinicPublic ], [] ],
            [ 'POST', '/clinic/v1/booking/hold', [ 'clinician_id' => $this->clinicianPublic, 'slot_date' => '2026-12-01', 'slot_time' => '10:00:00' ], [] ],
            [ 'POST', '/clinic/v1/booking/confirm', [ 'hold_token' => 'tok-audit' ], [] ],
            [ 'GET', '/clinic/v1/booking/resume', [], [ 'hold_token' => 'tok-audit' ] ],
            [ 'GET', '/clinic/v1/appointments/mine', [], [] ],
            [ 'GET', '/clinic/v1/visits', [], [] ],
            [ 'GET', '/clinic/v1/prescriptions', [], [] ],
            [ 'GET', '/clinic/v1/patients/' . $this->patientRecord . '/files', [], [] ],
            [ 'GET', '/clinic/v1/notifications', [], [] ],
            [ 'POST', '/clinic/v1/notifications/read', [ 'all' => true ], [] ],
        ];

        $staffRoutes = [
            [ 'POST', '/clinic/v1/patients/search', [ 'q' => 'سارا' ] ],
            [ 'GET', '/clinic/v1/patients/' . $this->patientRecord, [] ],
            [ 'GET', '/clinic/v1/queue', [] ],
            [ 'GET', '/clinic/v1/staff/portal/reception/context', [] ],
            [ 'GET', '/clinic/v1/doctor/portal/context', [] ],
            [ 'GET', '/clinic/v1/reports', [] ],
        ];

        foreach ( [ $accountOnly, $step1 ] as $session ) {
            $this->activateSession( $session );
            $nonce = wp_create_nonce( 'wp_rest' );

            foreach ( $patientRoutes as [ $method, $route, $body, $query ] ) {
                $resp = $this->dispatchRestWithNonce( $method, $route, $body, $nonce, $query );
                self::assertSame( 403, $resp->get_status(), $method . ' ' . $route . ' must fail closed for account-only/pending session.' );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $resp->get_data()['code'] ?? null );
            }

            foreach ( $staffRoutes as [ $method, $route, $body ] ) {
                $resp = $this->dispatchRestWithNonce( $method, $route, $body, $nonce );
                self::assertContains( $resp->get_status(), [ 400, 403 ], $method . ' ' . $route . ' must deny patient session.' );
                if ( 400 === $resp->get_status() ) {
                    self::assertSame( 'CLINIC_SCOPE_REQUIRED', $resp->get_data()['code'] ?? null );
                }
            }

            // Wrong NID on link-record always yields uniform 403 CLINIC_PATIENT_PROOF_FAILED.
            $wrongLink = $this->dispatchRestWithNonce(
                'POST',
                '/clinic/v1/patient/link-record',
                [ 'clinic_id' => $this->clinicPublic, 'national_id' => self::NID_WRONG ],
                $nonce
            );
            self::assertSame( 403, $wrongLink->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $wrongLink->get_data()['code'] ?? null );
        }

        // PURPOSE_LOGIN account-only cannot call Step-2 /patient/register (lacks cpms_reg_proofs & pending state).
        $this->activateSession( $accountOnly );
        $regDenied = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicPublic,
                'mobile'      => self::MOBILE_ACCOUNT_ACTIVE,
                'given_name'  => 'تست',
                'family_name' => 'ثبت',
                'national_id' => self::NID_NEW_STEP2,
                'birth_date'  => '1992-01-01',
            ],
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 403, $regDenied->get_status() );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $regDenied->get_data()['code'] ?? null );

        // Positive control: Step-1 pending session CAN complete Step-2 POST /clinic/v1/patient/register with valid NID.
        $this->activateSession( $step1 );
        $regOk = $this->dispatchRestWithNonce(
            'POST',
            '/clinic/v1/patient/register',
            [
                'clinic_id'   => $this->clinicPublic,
                'mobile'      => self::MOBILE_STEP1_NEW,
                'given_name'  => 'نازنین',
                'family_name' => 'ممیزی',
                'national_id' => self::NID_NEW_STEP2,
                'birth_date'  => '1994-03-21',
            ],
            $step1['rest_nonce']
        );
        self::assertSame( 200, $regOk->get_status(), 'Positive control Step-2 register must succeed.' );
    }

    public function testCoreWpRestUsersEndpointsBlockMutationContextEditAndEnumerationWhilePreservingReadMe(): void {
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '343434', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '343434', OtpService::PURPOSE_LOGIN );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '454545', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '454545', OtpService::PURPOSE_VERIFY_MOBILE, $this->clinicPublic );

        foreach ( [ $accountOnly, $step1 ] as $session ) {
            $this->activateSession( $session );
            $nonce     = wp_create_nonce( 'wp_rest' );
            $beforeUsr = get_userdata( $session['user_id'] );
            self::assertNotFalse( $beforeUsr );

            // Safe view on /wp/v2/users/me remains 200 (zero patient/clinic PHI).
            $meView = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $nonce );
            self::assertSame( 200, $meView->get_status() );
            $meBody = (string) wp_json_encode( $meView->get_data() );
            self::assertStringNotContainsString( self::NID_EXISTING, $meBody );
            self::assertStringNotContainsString( self::MOBILE_ACCOUNT_ACTIVE, $meBody );
            self::assertStringNotContainsString( self::MOBILE_STEP1_NEW, $meBody );
            self::assertStringNotContainsString( 'Private Audit Clinic Z', $meBody );

            // context=edit, own numeric ID, application-passwords prefix, collection listing, other-user lookup, and writes MUST fail closed (403).
            $meEditCtx = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $nonce, [ 'context' => 'edit' ] );
            self::assertSame( 403, $meEditCtx->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $meEditCtx->get_data()['code'] ?? null );

            $ownIdEditCtx = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/' . $session['user_id'], [], $nonce, [ 'context' => 'edit' ] );
            self::assertSame( 403, $ownIdEditCtx->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $ownIdEditCtx->get_data()['code'] ?? null );

            $ownIdPost = $this->dispatchRestWithNonce(
                'POST',
                '/wp/v2/users/' . $session['user_id'],
                [
                    'first_name' => 'TamperedOwnId',
                    'email'      => 'tampered-id-' . uniqid() . '@evil.test',
                    'password'   => 'TamperedPass123!',
                ],
                $nonce
            );
            self::assertSame( 403, $ownIdPost->get_status(), 'POST /wp/v2/users/{own_id} must fail closed.' );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $ownIdPost->get_data()['code'] ?? null );

            foreach ( [ 'PUT', 'PATCH' ] as $ownWriteMethod ) {
                $ownIdWrite = $this->dispatchRestWithNonce(
                    $ownWriteMethod,
                    '/wp/v2/users/' . $session['user_id'],
                    [
                        'first_name' => 'TamperedOwnId',
                        'email'      => 'tampered-id-' . uniqid() . '@evil.test',
                        'password'   => 'TamperedPass123!',
                    ],
                    $nonce
                );
                self::assertSame( 403, $ownIdWrite->get_status(), $ownWriteMethod . ' /wp/v2/users/{own_id} must fail closed.' );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $ownIdWrite->get_data()['code'] ?? null );
            }

            foreach ( [ '/wp/v2/users/me/application-passwords', '/wp/v2/users/' . $session['user_id'] . '/application-passwords' ] as $appPassRoute ) {
                $appPassList = $this->dispatchRestWithNonce( 'GET', $appPassRoute, [], $nonce );
                self::assertSame( 403, $appPassList->get_status(), 'GET ' . $appPassRoute . ' must fail closed.' );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $appPassList->get_data()['code'] ?? null );

                $appPassCreate = $this->dispatchRestWithNonce( 'POST', $appPassRoute, [ 'name' => 'audit-token' ], $nonce );
                self::assertSame( 403, $appPassCreate->get_status(), 'POST ' . $appPassRoute . ' must fail closed.' );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $appPassCreate->get_data()['code'] ?? null );
            }

            $usersList = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users', [], $nonce );
            self::assertSame( 403, $usersList->get_status() );
            $usersListData = $usersList->get_data();
            self::assertIsArray( $usersListData );
            self::assertArrayHasKey( 'code', $usersListData );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $usersListData['code'] );

            $otherUser = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/' . $this->staffDoctorUser, [], $nonce );
            self::assertSame( 403, $otherUser->get_status() );
            $otherUserData = $otherUser->get_data();
            self::assertIsArray( $otherUserData );
            self::assertArrayHasKey( 'code', $otherUserData );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $otherUserData['code'] );

            foreach ( [ 'POST', 'PUT', 'PATCH' ] as $writeMethod ) {
                $writeResp = $this->dispatchRestWithNonce(
                    $writeMethod,
                    '/wp/v2/users/me',
                    [
                        'first_name' => 'Tampered',
                        'email'      => 'tampered-' . uniqid() . '@evil.test',
                        'password'   => 'TamperedPass123!',
                    ],
                    $nonce
                );
                self::assertSame( 403, $writeResp->get_status(), $writeMethod . ' /wp/v2/users/me must fail closed.' );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $writeResp->get_data()['code'] ?? null );
            }

            $afterUsr = get_userdata( $session['user_id'] );
            self::assertNotFalse( $afterUsr );
            self::assertSame( $beforeUsr->user_email, $afterUsr->user_email );
            self::assertSame( $beforeUsr->user_pass, $afterUsr->user_pass );
            self::assertSame( $beforeUsr->first_name, $afterUsr->first_name );
        }

        // H5 fail-closed witness: exact-SQL lookup failure still blocks enumeration with CLINIC_PATIENT_PROOF_FAILED.
        global $wpdb;
        $this->activateSession( $accountOnly );
        $h5Nonce     = wp_create_nonce( 'wp_rest' );
        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$accountOnly['user_id']} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        try {
            $errList     = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users', [], $h5Nonce );
            $errListHits = $hits;
            $errListSeen = $seenQueries;

            $hits         = 0;
            $seenQueries  = 0;
            $errOther     = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/' . $this->staffDoctorUser, [], $h5Nonce );
            $errOtherHits = $hits;
            $errOtherSeen = $seenQueries;
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
        }
        self::assertGreaterThan( 0, $errListSeen );
        self::assertSame( 1, $errListHits );
        self::assertSame( 403, $errList->get_status() );
        $errListData = $errList->get_data();
        self::assertIsArray( $errListData );
        self::assertArrayHasKey( 'code', $errListData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $errListData['code'] );

        self::assertGreaterThan( 0, $errOtherSeen );
        self::assertSame( 1, $errOtherHits );
        self::assertSame( 403, $errOther->get_status() );
        $errOtherData = $errOther->get_data();
        self::assertIsArray( $errOtherData );
        self::assertArrayHasKey( 'code', $errOtherData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $errOtherData['code'] );

        // Positive control: staff user keeps full /wp/v2/users/me read(edit) + update capability.
        PatientTwoProofTestHelper::acting_as( $this->staffDoctorUser );
        $staffNonce = wp_create_nonce( 'wp_rest' );
        $staffEdit  = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $staffNonce, [ 'context' => 'edit' ] );
        self::assertSame( 200, $staffEdit->get_status() );
        $staffPatch = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'دکتر' ], $staffNonce );
        self::assertSame( 200, $staffPatch->get_status() );
    }

    public function testWpAdminGetRedirectsAndProfileMutationPostDeniedAndAdminPostHandlersDenied(): void {
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '565656', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '565656', OtpService::PURPOSE_LOGIN );
        $this->activateSession( $accountOnly );

        // 1) GET /wp-admin/* redirects to frontend Patient Portal.
        $redirect = $this->captureGuardWpAdminRedirect( $this->userAccountActive, [ 'page' => 'cpms-patient' ], 'GET' );
        self::assertNotNull( $redirect );
        self::assertSame( PatientPortalShell::portal_url(), $redirect );

        // 2) Generic guardWpAdmin POST contract stays non-interfering while profile.php personal_options_update is blocked (403).
        self::assertNull( $this->captureGuardWpAdminRedirect( $this->userAccountActive, [ 'page' => 'index' ], 'POST' ) );

        $patUser   = get_userdata( $this->userAccountActive );
        $staffUser = get_userdata( $this->staffDoctorUser );
        self::assertInstanceOf( WP_User::class, $patUser );
        self::assertInstanceOf( WP_User::class, $staffUser );
        $staffEmailBefore = (string) $staffUser->user_email;

        $capturedMail  = [];
        $mailFilter    = static function ( array $atts ) use ( &$capturedMail ): array {
            $capturedMail[] = $atts;
            return $atts;
        };
        $preMailFilter = static function ( mixed $pre ): mixed {
            unset( $pre );
            return false;
        };

        $prevPost = $_POST;
        add_filter( 'wp_mail', $mailFilter, 1 );
        add_filter( 'pre_wp_mail', $preMailFilter, 1, 1 );
        try {
            $this->activateSession( $accountOnly );
            $_POST['email'] = (string) $patUser->user_email;
            $hookDied       = false;
            try {
                do_action( 'personal_options_update', $this->userAccountActive );
            } catch ( WPDieException ) {
                $hookDied = true;
            }
            self::assertTrue( $hookDied, 'personal_options_update hook must fail closed for pure patient.' );

            // Positive control: staff user is not blocked on personal_options_update and triggers no side effect.
            PatientTwoProofTestHelper::acting_as( $this->staffDoctorUser );
            $_POST['email'] = $staffEmailBefore;
            $staffHookDied  = false;
            try {
                do_action( 'personal_options_update', $this->staffDoctorUser );
            } catch ( WPDieException ) {
                $staffHookDied = true;
            }
            self::assertFalse( $staffHookDied, 'Positive control: personal_options_update must not block staff accounts.' );
        } finally {
            remove_filter( 'wp_mail', $mailFilter, 1 );
            remove_filter( 'pre_wp_mail', $preMailFilter, 1 );
            $_POST = $prevPost;
        }

        $staffUserAfter = get_userdata( $this->staffDoctorUser );
        self::assertInstanceOf( WP_User::class, $staffUserAfter );
        self::assertSame( $staffEmailBefore, (string) $staffUserAfter->user_email );
        self::assertSame( '', (string) get_user_meta( $this->staffDoctorUser, '_new_email', true ) );
        self::assertSame( [], $capturedMail );

        // 3) admin-post handlers: PortalLogin denies account-only/pending patient; FinanceDelegationPage denies non-admin.
        self::assertFalse( PortalLogin::is_authorized( $this->userAccountActive, 'staff' ) );
        self::assertFalse( PortalLogin::is_authorized( $this->userAccountActive, 'doctor' ) );

        $this->activateSession( $accountOnly );
        $_POST = [
            '_cpms_nonce'       => wp_create_nonce( 'cpms_finance_delegation' ),
            'clinic_id'         => (string) $this->clinicPublic,
            'membership_id'     => '1',
            'capability'        => RolesAndCapabilities::INVOICE_VOID,
            'delegation_action' => 'delegate',
        ];
        $_REQUEST['_cpms_nonce'] = $_POST['_cpms_nonce'];
        $adminPostRedirect       = null;
        $escape                  = static function ( $url ) use ( &$adminPostRedirect ): string {
            $adminPostRedirect = (string) $url;
            throw new RuntimeException( 'intercepted_redirect' );
        };
        add_filter( 'wp_redirect', $escape, 1 );
        try {
            FinanceDelegationPage::handle();
        } catch ( RuntimeException ) {
            // Expected redirect after AuthorizationException.
        } finally {
            remove_filter( 'wp_redirect', $escape, 1 );
        }
        self::assertNotNull( $adminPostRedirect );
        self::assertStringContainsString(
            'واگذاری انجام نشد',
            (string) get_transient( 'cpms_finance_delegation_notice_' . $this->userAccountActive )
        );
    }

    public function testXmlRpcClosedOnlyForPatientAccountsAndOpenForStaff(): void {
        if ( ! class_exists( wp_xmlrpc_server::class ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
            $ixrFile    = ABSPATH . WPINC . '/class-IXR.php';
            $serverFile = ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
            if ( file_exists( $ixrFile ) ) {
                require_once $ixrFile;
            }
            if ( file_exists( $serverFile ) ) {
                require_once $serverFile;
            }
        }

        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '676767', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '676767', OtpService::PURPOSE_VERIFY_MOBILE, $this->clinicPublic );

        $activeUserObj = get_userdata( $this->userAccountActive );
        $pendUserObj   = get_userdata( $this->userAccountPending );
        $step1UserObj  = get_userdata( $step1['user_id'] );
        $staffUserObj  = get_userdata( $this->staffDoctorUser );
        self::assertNotFalse( $activeUserObj );
        self::assertNotFalse( $pendUserObj );
        self::assertNotFalse( $step1UserObj );
        self::assertNotFalse( $staffUserObj );

        $prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset( $_SERVER['REMOTE_ADDR'] );
        try {
            // 1) Password/app-password/reset guards reject patient accounts even with valid WP password.
            foreach ( [ $activeUserObj, $pendUserObj, $step1UserObj ] as $patUser ) {
                $auth = wp_authenticate( $patUser->user_login, $this->patientKnownPass );
                self::assertInstanceOf( WP_Error::class, $auth );
                self::assertSame( PatientCredentialGuard::ERROR_CODE, $auth->get_error_code() );
                self::assertFalse( apply_filters( 'allow_password_reset', true, (int) $patUser->ID ) );
                self::assertFalse( apply_filters( 'wp_is_application_passwords_available_for_user', true, $patUser ) );
            }

            $staffAuth = wp_authenticate( $staffUserObj->user_login, $this->staffDoctorPass );
            self::assertInstanceOf( WP_User::class, $staffAuth, 'Positive control: wp_authenticate must allow non-patient staff accounts.' );

            // 2) Real wp_xmlrpc_server denies patient accounts and allows staff positive control.
            if ( class_exists( wp_xmlrpc_server::class ) ) {
                foreach ( [ $activeUserObj, $pendUserObj, $step1UserObj ] as $patUser ) {
                    $patXmlrpc = new wp_xmlrpc_server();
                    $res       = $patXmlrpc->wp_getUsersBlogs( [ $patUser->user_login, $this->patientKnownPass ] );
                    self::assertInstanceOf( IXR_Error::class, $res, 'XML-RPC must fail closed for patient account ' . $patUser->user_login );
                    self::assertSame( 403, $res->code );
                }

                $staffXmlrpcSrv = new wp_xmlrpc_server();
                $staffLogin     = $staffXmlrpcSrv->login( $staffUserObj->user_login, $this->staffDoctorPass );
                self::assertInstanceOf( WP_User::class, $staffLogin, 'Positive control: wp_xmlrpc_server::login must allow non-patient staff.' );
                $staffXmlrpc = $staffXmlrpcSrv->wp_getUsersBlogs( [ $staffUserObj->user_login, $this->staffDoctorPass ] );
                self::assertIsArray( $staffXmlrpc, 'Positive control: XML-RPC must remain open for non-patient staff accounts.' );
            }
        } finally {
            if ( null === $prevRemoteAddr ) {
                unset( $_SERVER['REMOTE_ADDR'] );
            } else {
                $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr;
            }
        }
    }

    public function testStaffDoctorManagementPortalsAndPublicBookingShortcodeAndPatientPortalExposeNoData(): void {
        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '787878', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '787878', OtpService::PURPOSE_LOGIN );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_STEP1_NEW, '898989', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1 = $this->verifyOtpWithSession( self::MOBILE_STEP1_NEW, '898989', OtpService::PURPOSE_VERIFY_MOBILE, $this->clinicPublic );

        $mgmtService = App::management_portal_service();

        foreach ( [ $accountOnly, $step1 ] as $session ) {
            $this->activateSession( $session );
            $uid = $session['user_id'];

            // Staff / Doctor / Management portal exclusion.
            self::assertSame( [], StaffPortalShell::eligible_modules( $uid ) );
            self::assertFalse( DoctorPortalShell::isDoctorUser( wp_get_current_user() ) );
            self::assertSame( [], $mgmtService->organizations( $uid ) );
            self::assertSame( [], $mgmtService->clinics( $uid ) );
            self::assertFalse( $mgmtService->has_any_surface( $uid ) );

            ob_start();
            include StaffPortalShell::template_path();
            $staffHtml = (string) ob_get_clean();
            self::assertStringContainsString( 'data-role="portal-access-denied"', $staffHtml );
            self::assertStringNotContainsString( self::NID_EXISTING, $staffHtml );
            self::assertStringNotContainsString( 'Private Audit Clinic Z', $staffHtml );

            // PatientPortalPage renders zero appointments/records/booking clinics and never leaks PHI or private clinics.
            ob_start();
            PatientPortalPage::render();
            $portalHtml = (string) ob_get_clean();
            self::assertStringContainsString( 'نوبت پیش‌رویی ندارید.', $portalHtml );
            self::assertStringNotContainsString( self::NID_EXISTING, $portalHtml );
            self::assertStringNotContainsString( self::MOBILE_CLINIC_PATIENT, $portalHtml );
            self::assertStringNotContainsString( 'Private Audit Clinic Z', $portalHtml );
            self::assertStringNotContainsString( 'data-role="booking-patient-chooser"', $portalHtml );
        }

        // Step-1 pending sees complete-registration section and incomplete booking notice without hold/confirm paths.
        $this->activateSession( $step1 );
        ob_start();
        PatientPortalPage::render();
        $step1PortalHtml = (string) ob_get_clean();
        self::assertStringContainsString( 'id="complete-registration"', $step1PortalHtml );
        self::assertStringNotContainsString( 'data-role="link-record-section"', $step1PortalHtml );
        self::assertStringNotContainsString( 'data-role="booking-section"', $step1PortalHtml );

        $step1ShortcodeHtml = PublicBookingShortcode::render( [ 'clinic_id' => $this->clinicPublic ] );
        self::assertStringContainsString( 'data-role="complete-registration-redirect"', $step1ShortcodeHtml );
        self::assertStringNotContainsString( 'hold_path', $step1ShortcodeHtml );
        self::assertStringNotContainsString( 'confirm_path', $step1ShortcodeHtml );

        // Account-only sees link-record UI (requires NID), empty booking state, and zero patient chooser on shortcode.
        $this->activateSession( $accountOnly );
        ob_start();
        PatientPortalPage::render();
        $accountPortalHtml = (string) ob_get_clean();
        self::assertStringContainsString( 'data-role="link-record-section"', $accountPortalHtml );
        self::assertStringContainsString( 'data-role="link-record-national-id"', $accountPortalHtml );
        self::assertStringContainsString( 'data-role="booking-empty-state"', $accountPortalHtml );

        $accountShortcodeHtml = PublicBookingShortcode::render( [ 'clinic_id' => $this->clinicPublic ] );
        self::assertStringNotContainsString( 'data-role="booking-patient-chooser"', $accountShortcodeHtml );
        self::assertStringNotContainsString( self::NID_EXISTING, $accountShortcodeHtml );
        self::assertStringNotContainsString( self::MOBILE_CLINIC_PATIENT, $accountShortcodeHtml );
    }

    /**
     * @return array{response: WP_REST_Response, user_id: int, session_issued: bool, rest_nonce: string, token: string, cookie: string}
     */
    private function verifyOtpWithSession( string $mobile, string $code, string $purpose, ?int $clinicId = null ): array {
        $capturedToken  = '';
        $capturedCookie = '';
        $hook           = static function ( string $cookie, int $expire, int $expiration, int $userId, string $scheme, string $token ) use ( &$capturedToken, &$capturedCookie ): void {
            unset( $expire, $expiration, $userId, $scheme );
            $capturedToken  = $token;
            $capturedCookie = $cookie;
        };

        $body = [
            'mobile'  => $mobile,
            'code'    => $code,
            'purpose' => $purpose,
        ];
        if ( null !== $clinicId ) {
            $body['clinic_id'] = $clinicId;
        }

        add_action( 'set_logged_in_cookie', $hook, 10, 6 );
        try {
            $resp = $this->dispatchRest( 'POST', '/clinic/v1/otp/verify', $body );
        } finally {
            remove_action( 'set_logged_in_cookie', $hook, 10 );
        }

        $data          = $resp->get_data()['data'] ?? [];
        $userId        = (int) ( $data['user_id'] ?? 0 );
        $sessionIssued = (bool) ( $data['session_issued'] ?? false );
        $restNonce     = (string) ( $data['rest_nonce'] ?? '' );

        $result = [
            'response'       => $resp,
            'user_id'        => $userId,
            'session_issued' => $sessionIssued,
            'rest_nonce'     => $restNonce,
            'token'          => $capturedToken,
            'cookie'         => $capturedCookie,
        ];

        if ( $sessionIssued && $userId > 0 ) {
            $this->activateSession( $result );
        }

        return $result;
    }

    /**
     * @param array{user_id: int, token: string, cookie: string} $session
     */
    private function activateSession( array $session ): void {
        if ( '' !== $session['cookie'] ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $session['cookie'];
        }
        wp_set_current_user( $session['user_id'] );
        if ( '' !== $session['token'] ) {
            App::patient_session_scope()->set_in_process_session_token( $session['user_id'], $session['token'] );
        }
    }

    /**
     * @param array<string, string> $get
     */
    private function captureGuardWpAdminRedirect( int $userId, array $get, string $method ): ?string {
        wp_set_current_user( $userId );
        $_GET                      = $get;
        $_REQUEST                  = $get;
        $_POST                     = [];
        $_SERVER['REQUEST_METHOD'] = $method;

        $redirect = null;
        $escape   = static function ( $url ) use ( &$redirect ): string {
            $redirect = (string) $url;
            throw new RuntimeException( 'intercepted_wp_redirect' );
        };
        add_filter( 'wp_redirect', $escape, 1 );
        try {
            PatientPortalPage::guardWpAdmin();
        } catch ( RuntimeException ) {
            // Expected when redirect fires.
        } finally {
            remove_filter( 'wp_redirect', $escape, 1 );
            $_GET                      = [];
            $_REQUEST                  = [];
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return $redirect;
    }

    private function createUser( string $login, string $password, string $role ): int {
        $userId = wp_insert_user(
            [
                'user_login' => $login,
                'user_pass'  => $password,
                'user_email' => $login . '@audit.test.local',
                'role'       => $role,
            ]
        );
        self::assertIsInt( $userId );
        self::assertGreaterThan( 0, $userId );

        return $userId;
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
     * @param array<string, mixed> $query
     */
    private function dispatchRestWithNonce( string $method, string $route, array $body, string $nonce, array $query = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        $req->set_header( 'X-WP-Nonce', $nonce );
        if ( [] !== $query ) {
            $req->set_query_params( $query );
        }
        if ( [] !== $body ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( $body ) );
        }
        $res = rest_do_request( $req );
        self::assertInstanceOf( WP_REST_Response::class, $res );

        return $res;
    }

    /**
     * MUTATION LINE (a): If `guard_actor_state` falls back to `isPatientOnly()` (`false` on DB failure)
     * or guards do not deny `UNKNOWN`, `POST /wp/v2/users/me` returns 200 and `personal_options_update` proceeds.
     */
    public function testLookupFailureFailsClosedOnPatientUsersMeWriteAndProfileHook(): void {
        global $wpdb;

        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '778899', OtpService::PURPOSE_LOGIN );
        $session = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '778899', OtpService::PURPOSE_LOGIN );
        $this->activateSession( $session );

        $patUser = get_userdata( $this->userAccountActive );
        self::assertInstanceOf( WP_User::class, $patUser );

        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$this->userAccountActive} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };

        $prevPost     = $_POST;
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        try {
            $nonce     = wp_create_nonce( 'wp_rest' );
            $writeResp = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'Tampered' ], $nonce );
            $restHits  = $hits;
            $restSeen  = $seenQueries;

            $hits           = 0;
            $seenQueries    = 0;
            $_POST['email'] = (string) $patUser->user_email;
            $hookDied       = false;
            try {
                do_action( 'personal_options_update', $this->userAccountActive );
            } catch ( WPDieException ) {
                $hookDied = true;
            }
            $hookHits = $hits;
            $hookSeen = $seenQueries;
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
            $_POST = $prevPost;
        }

        self::assertGreaterThan( 0, $restSeen );
        self::assertSame( 1, $restHits );
        self::assertSame( 403, $writeResp->get_status() );
        $writeData = $writeResp->get_data();
        self::assertIsArray( $writeData );
        self::assertArrayHasKey( 'code', $writeData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $writeData['code'] );

        self::assertTrue( $hookDied );
        self::assertGreaterThan( 0, $hookSeen );
        self::assertSame( 1, $hookHits );
    }

    /**
     * MUTATION LINE (b): If `guard_actor_state` runs the scoped lookup before WP role candidacy
     * or treats non-patient accounts as `UNKNOWN`, staff `POST /wp/v2/users/me` returns 403 and hook dies.
     */
    public function testLookupFailureSpyDoesNotBlockStaffDoctorWpRoleAccount(): void {
        global $wpdb;

        PatientTwoProofTestHelper::acting_as( $this->staffDoctorUser );
        $staffUser = get_userdata( $this->staffDoctorUser );
        self::assertInstanceOf( WP_User::class, $staffUser );

        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$this->staffDoctorUser} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $preMail     = static function ( mixed $pre ): mixed {
            unset( $pre );
            return false;
        };

        $prevPost     = $_POST;
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        add_filter( 'pre_wp_mail', $preMail, 1, 1 );
        try {
            $nonce          = wp_create_nonce( 'wp_rest' );
            $staffResp      = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'دکتر-تست' ], $nonce );
            $_POST['email'] = (string) $staffUser->user_email;
            $hookDied       = false;
            try {
                do_action( 'personal_options_update', $this->staffDoctorUser );
            } catch ( WPDieException ) {
                $hookDied = true;
            }
        } finally {
            remove_filter( 'query', $fault, 5 );
            remove_filter( 'pre_wp_mail', $preMail, 1 );
            $wpdb->suppress_errors( $prevSuppress );
            $_POST = $prevPost;
        }

        self::assertGreaterThan( 0, $seenQueries );
        self::assertSame( 0, $hits );
        self::assertSame( 200, $staffResp->get_status() );
        self::assertFalse( $hookDied );
    }

    /**
     * MUTATION LINE (c): If `guard_wp_users_rest` blocks safe `GET /wp/v2/users/me`, status becomes 403;
     * if bound patients are exempted from write blocking, `POST /wp/v2/users/me` returns 200.
     */
    public function testFullyBoundPatientAllowedSelfReadAndDeniedSelfUpdate(): void {
        $boundUserId = $this->createUser( 'audit_bound_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        PatientTwoProofTestHelper::seed_patient_proof( $boundUserId, $this->clinicPublic, $this->patientRecord );

        $nonce = wp_create_nonce( 'wp_rest' );
        $meGet = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $nonce );
        self::assertSame( 200, $meGet->get_status() );

        $mePost = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'Tampered' ], $nonce );
        self::assertSame( 403, $mePost->get_status() );
        $postData = $mePost->get_data();
        self::assertIsArray( $postData );
        self::assertArrayHasKey( 'code', $postData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $postData['code'] );

        global $wpdb;
        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$boundUserId} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        try {
            $errGet     = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $nonce );
            $errGetHits = $hits;
            $errGetSeen = $seenQueries;

            $hits        = 0;
            $seenQueries = 0;
            $errPost     = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'Tampered' ], $nonce );
            $errPostHits = $hits;
            $errPostSeen = $seenQueries;
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
        }
        self::assertGreaterThan( 0, $errGetSeen );
        self::assertSame( 1, $errGetHits );
        self::assertSame( 200, $errGet->get_status() );
        self::assertGreaterThan( 0, $errPostSeen );
        self::assertSame( 1, $errPostHits );
        self::assertSame( 403, $errPost->get_status() );
        $errPostData = $errPost->get_data();
        self::assertIsArray( $errPostData );
        self::assertArrayHasKey( 'code', $errPostData );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $errPostData['code'] );
    }

    /**
     * MUTATION LINE (d): If `guard_actor_state` fails to classify `administrator` (`manage_options`)
     * as `NOT_PATIENT`, `GET /wp/v2/users` returns 403.
     */
    public function testAdministratorWitnessNotBlockedOnUsersCollection(): void {
        $adminId = $this->createUser( 'audit_admin_' . uniqid(), 'AdminPass#Audit2026!', 'administrator' );
        wp_set_current_user( $adminId );

        $nonce = wp_create_nonce( 'wp_rest' );
        $resp  = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users', [], $nonce );
        self::assertSame( 200, $resp->get_status() );
    }

    /**
     * MUTATION LINE (e): If `guard_actor_state` treats a mixed `ROLE_PATIENT` + staff-role account
     * as `PATIENT`, `POST /wp/v2/users/me` returns 403 instead of 200.
     */
    public function testMixedPatientAndStaffRoleAccountNotBlockedOnOwnMeUpdate(): void {
        $mixedId   = $this->createUser( 'audit_mixed_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $mixedUser = get_userdata( $mixedId );
        self::assertInstanceOf( WP_User::class, $mixedUser );
        $mixedUser->add_role( RolesAndCapabilities::ROLE_DOCTOR );

        wp_set_current_user( $mixedId );
        $nonce = wp_create_nonce( 'wp_rest' );
        $resp  = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'MixedUpdated' ], $nonce );
        self::assertSame( 200, $resp->get_status() );
    }

    /**
     * Item 1 (`N2`) + `guardWpAdmin` mutation coverage: denies `?newuseremail=<hash>` on `admin_init`
     * across GET and POST for `PATIENT` and `UNKNOWN` before core `user-edit.php:108-120` runs.
     * LIMITATION: exercises `PatientPortalPage::guardWpAdmin()` (verified hooked on `admin_init`) directly, not HTTP `wp-admin/profile.php`.
     */
    public function testPendingNewEmailHashDeniedViaGetAndPostAndOnLookupFailure(): void {
        global $wpdb;

        self::assertSame( 10, has_action( 'admin_init', [ PatientPortalPage::class, 'guardWpAdmin' ] ) );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '343434', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '343434', OtpService::PURPOSE_LOGIN );
        $this->activateSession( $accountOnly );

        $patBefore = get_userdata( $this->userAccountActive );
        self::assertInstanceOf( WP_User::class, $patBefore );
        $origEmail = (string) $patBefore->user_email;

        $hash        = 'audit-email-hash-' . uniqid();
        $pendingMeta = [
            'hash'     => $hash,
            'newemail' => 'hijacked-' . uniqid() . '@evil.test',
        ];
        update_user_meta( $this->userAccountActive, '_new_email', $pendingMeta );

        $prevGet     = $_GET;
        $prevRequest = $_REQUEST;
        $prevPost    = $_POST;
        $prevMethod  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $prevPagenow = $GLOBALS['pagenow'] ?? null;

        $getDied     = false;
        $getMsg      = '';
        $getRedirect = null;
        $postDied    = false;
        $postMsg     = '';
        $escape      = static function ( $url ) use ( &$getRedirect ): string {
            $getRedirect = (string) $url;
            throw new RuntimeException( 'intercepted_wp_redirect' );
        };

        add_filter( 'wp_redirect', $escape, 1 );
        try {
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [ 'newuseremail' => $hash ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException $e ) {
                $getDied = true;
                $getMsg  = $e->getMessage();
            } catch ( RuntimeException ) {
                $getDied = false;
            }

            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException $e ) {
                $postDied = true;
                $postMsg  = $e->getMessage();
            }
        } finally {
            remove_filter( 'wp_redirect', $escape, 1 );
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }

        self::assertTrue( $getDied );
        self::assertNull( $getRedirect );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $getMsg );
        self::assertTrue( $postDied );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $postMsg );

        $patAfter = get_userdata( $this->userAccountActive );
        self::assertInstanceOf( WP_User::class, $patAfter );
        self::assertSame( $origEmail, (string) $patAfter->user_email );
        self::assertSame( $pendingMeta, get_user_meta( $this->userAccountActive, '_new_email', true ) );

        // UNKNOWN lookup failure: POST newuseremail still dies 403 AND GET cpms-patient still redirects via guardWpAdmin.
        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$this->userAccountActive} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        $errPostDied = false;
        try {
            $this->activateSession( $accountOnly );
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [ 'newuseremail' => $hash ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $errPostDied = true;
            }
            $errPostHits = $hits;
            $errPostSeen = $seenQueries;

            $hits        = 0;
            $seenQueries = 0;
            $errRedirect = $this->captureGuardWpAdminRedirect( $this->userAccountActive, [ 'page' => 'cpms-patient' ], 'GET' );
            $errRedirHit = $hits;
            $errRedirSee = $seenQueries;
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }

        self::assertTrue( $errPostDied );
        self::assertGreaterThan( 0, $errPostSeen );
        self::assertSame( 1, $errPostHits );
        self::assertGreaterThan( 0, $errRedirSee );
        self::assertSame( 1, $errRedirHit );
        self::assertSame( PatientPortalShell::portal_url(), $errRedirect );

        $patAfterErr = get_userdata( $this->userAccountActive );
        self::assertInstanceOf( WP_User::class, $patAfterErr );
        self::assertSame( $origEmail, (string) $patAfterErr->user_email );

        // Positive control: staff account is not blocked on POST newuseremail.
        PatientTwoProofTestHelper::acting_as( $this->staffDoctorUser );
        $staffDied = false;
        try {
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [ 'newuseremail' => $hash ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $staffDied = true;
            }
        } finally {
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }
        self::assertFalse( $staffDied );
    }

    /**
     * Item 2 (`N3`): legacy `ROLE_PATIENT` user with NO `cpms_patient_logins` row and valid WP password
     * is denied XML-RPC (`wp.editProfile` and `wp.getUsersBlogs`), while staff XML-RPC succeeds.
     */
    public function testLegacyPatientWithoutLoginRowDeniedOnXmlRpcEditProfileWhileStaffAllowed(): void {
        if ( ! class_exists( wp_xmlrpc_server::class ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
            $ixrFile    = ABSPATH . WPINC . '/class-IXR.php';
            $serverFile = ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
            if ( file_exists( $ixrFile ) ) {
                require_once $ixrFile;
            }
            if ( file_exists( $serverFile ) ) {
                require_once $serverFile;
            }
        }
        self::assertTrue( class_exists( wp_xmlrpc_server::class ) );

        $legacyId   = $this->createUser( 'audit_legacy_pat_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $legacyUser = get_userdata( $legacyId );
        $staffUser  = get_userdata( $this->staffDoctorUser );
        self::assertInstanceOf( WP_User::class, $legacyUser );
        self::assertInstanceOf( WP_User::class, $staffUser );
        self::assertSame(
            0,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) . ' WHERE wp_user_id = %d', [ $legacyId ] )
        );

        $prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset( $_SERVER['REMOTE_ADDR'] );
        try {
            $patSrv      = new wp_xmlrpc_server();
            $editDenied  = $patSrv->wp_editProfile( [ 1, $legacyUser->user_login, $this->patientKnownPass, [ 'first_name' => 'TamperedLegacy' ] ] );
            $blogsDenied = ( new wp_xmlrpc_server() )->wp_getUsersBlogs( [ $legacyUser->user_login, $this->patientKnownPass ] );
            $staffEdit   = ( new wp_xmlrpc_server() )->wp_editProfile( [ 1, $staffUser->user_login, $this->staffDoctorPass, [ 'first_name' => 'دکتر-xmlrpc' ] ] );
        } finally {
            if ( null === $prevRemoteAddr ) {
                unset( $_SERVER['REMOTE_ADDR'] );
            } else {
                $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr;
            }
        }

        self::assertInstanceOf( IXR_Error::class, $editDenied );
        self::assertSame( 403, $editDenied->code );
        self::assertInstanceOf( IXR_Error::class, $blogsDenied );
        self::assertSame( 403, $blogsDenied->code );
        self::assertTrue( $staffEdit );

        $legacyAfter = get_userdata( $legacyId );
        $staffAfter  = get_userdata( $this->staffDoctorUser );
        self::assertInstanceOf( WP_User::class, $legacyAfter );
        self::assertInstanceOf( WP_User::class, $staffAfter );
        self::assertSame( '', (string) $legacyAfter->first_name );
        self::assertSame( 'دکتر-xmlrpc', (string) $staffAfter->first_name );
    }

    /**
     * Round 2 Item 2: denies `$_GET['dismiss']` (`user-edit.php:124-129`) across GET and POST
     * for `PATIENT` and `UNKNOWN` before core deletes `_new_email` usermeta, while staff succeeds.
     */
    public function testPendingNewEmailDismissDeniedViaGetAndPostAndOnLookupFailure(): void {
        global $wpdb;

        self::assertSame( 10, has_action( 'admin_init', [ PatientPortalPage::class, 'guardWpAdmin' ] ) );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '565656', OtpService::PURPOSE_LOGIN );
        $accountOnly = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '565656', OtpService::PURPOSE_LOGIN );
        $this->activateSession( $accountOnly );

        $hash        = 'audit-dismiss-hash-' . uniqid();
        $pendingMeta = [
            'hash'     => $hash,
            'newemail' => 'pending-dismiss-' . uniqid() . '@clinic.test',
        ];
        update_user_meta( $this->userAccountActive, '_new_email', $pendingMeta );

        $dismissKey   = $this->userAccountActive . '_new_email';
        $dismissNonce = wp_create_nonce( 'dismiss-' . $dismissKey );

        $prevGet     = $_GET;
        $prevRequest = $_REQUEST;
        $prevPost    = $_POST;
        $prevMethod  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $prevPagenow = $GLOBALS['pagenow'] ?? null;

        $getDied     = false;
        $getMsg      = '';
        $getRedirect = null;
        $postDied    = false;
        $postMsg     = '';
        $escape      = static function ( $url ) use ( &$getRedirect ): string {
            $getRedirect = (string) $url;
            throw new RuntimeException( 'intercepted_wp_redirect' );
        };

        add_filter( 'wp_redirect', $escape, 1 );
        try {
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [
                'dismiss'  => $dismissKey,
                '_wpnonce' => $dismissNonce,
            ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException $e ) {
                $getDied = true;
                $getMsg  = $e->getMessage();
            } catch ( RuntimeException ) {
                $getDied = false;
            }

            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException $e ) {
                $postDied = true;
                $postMsg  = $e->getMessage();
            }
        } finally {
            remove_filter( 'wp_redirect', $escape, 1 );
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }

        self::assertTrue( $getDied );
        self::assertNull( $getRedirect );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $getMsg );
        self::assertTrue( $postDied );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $postMsg );
        self::assertSame( $pendingMeta, get_user_meta( $this->userAccountActive, '_new_email', true ) );

        // UNKNOWN lookup failure: POST dismiss still dies 403 and preserves `_new_email`.
        $orgTable    = App::db()->table( 'cpms_organization_memberships' );
        $exactSql    = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$this->userAccountActive} AND status = 'active' LIMIT 1";
        $hits        = 0;
        $seenQueries = 0;
        $fault       = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        $errPostDied = false;
        try {
            $this->activateSession( $accountOnly );
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [
                'dismiss'  => $dismissKey,
                '_wpnonce' => $dismissNonce,
            ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $errPostDied = true;
            }
            $errPostHits = $hits;
            $errPostSeen = $seenQueries;
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }

        self::assertTrue( $errPostDied );
        self::assertGreaterThan( 0, $errPostSeen );
        self::assertSame( 1, $errPostHits );
        self::assertSame( $pendingMeta, get_user_meta( $this->userAccountActive, '_new_email', true ) );

        // Positive control: staff account is not blocked on POST dismiss.
        PatientTwoProofTestHelper::acting_as( $this->staffDoctorUser );
        $staffDied = false;
        try {
            $GLOBALS['pagenow']        = 'profile.php';
            $_GET                      = [ 'dismiss' => $this->staffDoctorUser . '_new_email' ];
            $_REQUEST                  = $_GET;
            $_POST                     = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $staffDied = true;
            }
        } finally {
            $_GET                      = $prevGet;
            $_REQUEST                  = $prevRequest;
            $_POST                     = $prevPost;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }
        self::assertFalse( $staffDied );
    }

    /**
     * Round 2 Items 3 & 4: legacy `ROLE_PATIENT` user with NO `cpms_patient_logins` row is denied
     * WP password reset (`allow_password_reset`, already-valid key `validate_password_reset`, direct
     * `reset_password`) and Application Passwords, while staff and mixed `ROLE_PATIENT` + staff-role
     * accounts remain unaffected (including XML-RPC).
     */
    public function testLegacyPatientWithoutLoginRowDeniedPasswordResetAndAppPasswordsWhileStaffAndMixedAllowed(): void {
        if ( ! class_exists( wp_xmlrpc_server::class ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
            $ixrFile    = ABSPATH . WPINC . '/class-IXR.php';
            $serverFile = ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
            if ( file_exists( $ixrFile ) ) {
                require_once $ixrFile;
            }
            if ( file_exists( $serverFile ) ) {
                require_once $serverFile;
            }
        }
        self::assertTrue( class_exists( wp_xmlrpc_server::class ) );

        $legacyId   = $this->createUser( 'audit_legacy_rst_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $legacyUser = get_userdata( $legacyId );
        $staffUser  = get_userdata( $this->staffDoctorUser );
        $mixedId    = $this->createUser( 'audit_mixed_xml_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $mixedUser  = get_userdata( $mixedId );
        self::assertInstanceOf( WP_User::class, $legacyUser );
        self::assertInstanceOf( WP_User::class, $staffUser );
        self::assertInstanceOf( WP_User::class, $mixedUser );
        $mixedUser->add_role( RolesAndCapabilities::ROLE_DOCTOR );

        self::assertSame(
            0,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) . ' WHERE wp_user_id = %d', [ $legacyId ] )
        );
        self::assertSame(
            0,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_logins' ) . ' WHERE wp_user_id = %d', [ $mixedId ] )
        );

        // Item 3a (key generation): blocked for legacy patient, allowed for staff and mixed.
        self::assertFalse( apply_filters( 'allow_password_reset', true, $legacyId ) );
        $legacyKeyErr = get_password_reset_key( $legacyUser );
        self::assertInstanceOf( WP_Error::class, $legacyKeyErr );
        self::assertSame( 'no_password_reset', $legacyKeyErr->get_error_code() );

        $staffKey = get_password_reset_key( $staffUser );
        self::assertIsString( $staffKey );
        self::assertNotSame( '', $staffKey );

        // Item 3a (already-valid reset key): validate_password_reset & direct reset_password() both block legacy patient.
        $rawValidKey = 'ValidKeyAudit123456';
        wp_update_user(
            [
                'ID'                  => $legacyId,
                'user_activation_key' => time() . ':' . wp_fast_hash( $rawValidKey ),
            ]
        );
        $validatedLegacy = check_password_reset_key( $rawValidKey, $legacyUser->user_login );
        self::assertInstanceOf( WP_User::class, $validatedLegacy );

        $legacyResetErrors = new WP_Error();
        do_action( 'validate_password_reset', $legacyResetErrors, $validatedLegacy );
        self::assertTrue( $legacyResetErrors->has_errors() );
        self::assertContains( PatientCredentialGuard::ERROR_CODE, $legacyResetErrors->get_error_codes() );

        $validatedStaff = check_password_reset_key( $staffKey, $staffUser->user_login );
        self::assertInstanceOf( WP_User::class, $validatedStaff );
        $staffResetErrors = new WP_Error();
        do_action( 'validate_password_reset', $staffResetErrors, $validatedStaff );
        self::assertFalse( $staffResetErrors->has_errors() );

        $legacyHashBefore = (string) $legacyUser->user_pass;
        $resetDied        = false;
        $resetDieMsg      = '';
        try {
            reset_password( $legacyUser, 'AttemptedLegacyReset123!' );
        } catch ( WPDieException $e ) {
            $resetDied   = true;
            $resetDieMsg = $e->getMessage();
        }
        self::assertTrue( $resetDied );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $resetDieMsg );
        $legacyAfterReset = get_userdata( $legacyId );
        self::assertInstanceOf( WP_User::class, $legacyAfterReset );
        self::assertSame( $legacyHashBefore, (string) $legacyAfterReset->user_pass );

        // Item 3b: Application Passwords denied for legacy patient, allowed for staff and mixed.
        add_filter( 'wp_is_application_passwords_available', '__return_true', 999 );
        try {
            $legacyAppAllowed = wp_is_application_passwords_available_for_user( $legacyUser );
            $staffAppAllowed  = wp_is_application_passwords_available_for_user( $staffUser );
            $mixedAppAllowed  = wp_is_application_passwords_available_for_user( $mixedUser );
        } finally {
            remove_filter( 'wp_is_application_passwords_available', '__return_true', 999 );
        }
        self::assertFalse( $legacyAppAllowed );
        self::assertTrue( $staffAppAllowed );
        self::assertTrue( $mixedAppAllowed );

        // Item 4: mixed patient + staff role account remains unaffected on XML-RPC.
        $prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset( $_SERVER['REMOTE_ADDR'] );
        try {
            $mixedEdit = ( new wp_xmlrpc_server() )->wp_editProfile(
                [ 1, $mixedUser->user_login, $this->patientKnownPass, [ 'first_name' => 'MixedXmlRpcOk' ] ]
            );
        } finally {
            if ( null === $prevRemoteAddr ) {
                unset( $_SERVER['REMOTE_ADDR'] );
            } else {
                $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr;
            }
        }
        self::assertTrue( $mixedEdit );
        $mixedAfter = get_userdata( $mixedId );
        self::assertInstanceOf( WP_User::class, $mixedAfter );
        self::assertSame( 'MixedXmlRpcOk', (string) $mixedAfter->first_name );
    }

    /**
     * Round 3 Task D (`N1`, `authorize-application.php`) + Task C (`wp_authenticate_application_password`):
     * exercises the closest PHPUnit equivalents to `authorize-application.php` (`admin_init` with
     * `$pagenow = 'authorize-application.php'` and `REQUEST_URI`, `wp_is_application_passwords_available_for_user`,
     * REST `POST /wp/v2/users/me/application-passwords`, and `wp_authenticate_application_password`) across
     * OTP patient, legacy patient, `patient+subscriber`, `UNKNOWN` lookup-failure, staff, and mixed accounts.
     *
     * INFERENCE (revert-to-fail):
     * - Reverting `authorize_application_password` check in `PatientPortalPage::guardWpAdmin()` (`:224-228`)
     *   lets `POST authorize-application.php` reach core `:16-38` without dying 403 on `admin_init`.
     * - Reverting `PatientCredentialGuard::filter_app_passwords_available()` (`:154-159`) lets
     *   `authorize-application.php:104` pass to nonce printing (`:240`) and lets `wp_authenticate_application_password` succeed.
     * - Reverting `PatientPortalPage::guard_wp_users_rest()` (`:258-277`) lets REST `POST /wp/v2/users/me/application-passwords` return 201.
     */
    public function testAuthorizeApplicationClosureViaAdminInitAvailabilityAndRestForAllTargets(): void {
        global $wpdb;

        self::assertSame( 10, has_action( 'admin_init', [ PatientPortalPage::class, 'guardWpAdmin' ] ) );

        $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '778899', OtpService::PURPOSE_LOGIN );
        $otpToken = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '778899', OtpService::PURPOSE_LOGIN );

        $legacyId = $this->createUser( 'audit_n1_legacy_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );

        $subPatId   = $this->createUser( 'audit_n1_sub_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $subPatUser = get_userdata( $subPatId );
        self::assertInstanceOf( WP_User::class, $subPatUser );
        $subPatUser->add_role( 'subscriber' );

        $unknownId = $this->createUser( 'audit_n1_unk_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );

        $mixedId   = $this->createUser( 'audit_n1_mix_' . uniqid(), $this->patientKnownPass, RolesAndCapabilities::ROLE_PATIENT );
        $mixedUser = get_userdata( $mixedId );
        self::assertInstanceOf( WP_User::class, $mixedUser );
        $mixedUser->add_role( RolesAndCapabilities::ROLE_DOCTOR );

        $cases = [
            'otp_patient'             => [ 'uid' => $this->userAccountActive, 'expect_denied' => true, 'expect_get_redirect' => true, 'fault' => false, 'otp' => $otpToken ],
            'legacy_patient'          => [ 'uid' => $legacyId, 'expect_denied' => true, 'expect_get_redirect' => true, 'fault' => false, 'otp' => null ],
            'patient_plus_subscriber' => [ 'uid' => $subPatId, 'expect_denied' => true, 'expect_get_redirect' => false, 'fault' => false, 'otp' => null ],
            'unknown_lookup_failure'  => [ 'uid' => $unknownId, 'expect_denied' => true, 'expect_get_redirect' => true, 'fault' => true, 'otp' => null ],
            'staff_doctor'            => [ 'uid' => $this->staffDoctorUser, 'expect_denied' => false, 'expect_get_redirect' => false, 'fault' => false, 'otp' => null ],
            'mixed_patient_doctor'    => [ 'uid' => $mixedId, 'expect_denied' => false, 'expect_get_redirect' => false, 'fault' => false, 'otp' => null ],
        ];

        $prevGet        = $_GET;
        $prevPost       = $_POST;
        $prevRequest    = $_REQUEST;
        $prevMethod     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $prevRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        $prevPagenow    = $GLOBALS['pagenow'] ?? null;

        add_filter( 'wp_is_application_passwords_available', '__return_true', 999 );
        add_filter( 'application_password_is_api_request', '__return_true', 999 );
        try {
            foreach ( $cases as $label => $cfg ) {
                $uid = (int) $cfg['uid'];
                if ( null !== $cfg['otp'] ) {
                    $this->activateSession( (string) $cfg['otp'] );
                } else {
                    PatientTwoProofTestHelper::acting_as( $uid );
                }
                $userObj = get_userdata( $uid );
                self::assertInstanceOf( WP_User::class, $userObj, $label );

                $orgTable = App::db()->table( 'cpms_organization_memberships' );
                $exactSql = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$uid} AND status = 'active' LIMIT 1";
                $hits     = 0;
                $fault    = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits ): mixed {
                    if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                        ++$hits;
                        return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
                    }
                    return $query;
                };
                $prevSuppress = (bool) $wpdb->suppress_errors( true );
                if ( $cfg['fault'] ) {
                    add_filter( 'query', $fault, 5 );
                }

                $getRedirect = null;
                $postDied    = false;
                $postMsg     = '';
                $escape      = static function ( $url ) use ( &$getRedirect ): string {
                    $getRedirect = (string) $url;
                    throw new RuntimeException( 'intercepted_wp_redirect' );
                };

                try {
                    // 1) GET authorize-application.php via `PatientPortalPage::guardWpAdmin()` (verified hooked at priority 10 on `admin_init`).
                    $GLOBALS['pagenow']        = 'authorize-application.php';
                    $_SERVER['REQUEST_URI']    = '/wp-admin/authorize-application.php';
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    $_GET                      = [];
                    $_POST                     = [];
                    $_REQUEST                  = [];
                    add_filter( 'wp_redirect', $escape, 1 );
                    try {
                        PatientPortalPage::guardWpAdmin();
                    } catch ( RuntimeException $e ) {
                        if ( 'intercepted_wp_redirect' !== $e->getMessage() ) {
                            throw $e;
                        }
                    } finally {
                        remove_filter( 'wp_redirect', $escape, 1 );
                    }

                    // 2) POST authorize-application.php (`action=authorize_application_password`) via `PatientPortalPage::guardWpAdmin()`.
                    $_SERVER['REQUEST_METHOD'] = 'POST';
                    $_POST                     = [
                        'action'   => 'authorize_application_password',
                        'approve'  => '1',
                        'app_name' => 'AuditApp',
                    ];
                    $_REQUEST                  = $_POST;
                    try {
                        PatientPortalPage::guardWpAdmin();
                    } catch ( WPDieException $e ) {
                        $postDied = true;
                        $postMsg  = $e->getMessage();
                    }

                    // 3) WP core :104 availability check (blocks :240 nonce even when GET renders for patient+subscriber).
                    $appAvailable = wp_is_application_passwords_available_for_user( $userObj );

                    // 4) Real REST POST /wp/v2/users/me/application-passwords (auth-app.js path).
                    $_POST    = [];
                    $_REQUEST = [];
                    $nonce    = wp_create_nonce( 'wp_rest' );
                    $restResp = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me/application-passwords', [ 'name' => 'AuditApp_' . $label ], $nonce );
                    $stored   = WP_Application_Passwords::get_user_application_passwords( $uid );
                } finally {
                    if ( $cfg['fault'] ) {
                        remove_filter( 'query', $fault, 5 );
                    }
                    $wpdb->suppress_errors( $prevSuppress );
                }

                if ( $cfg['fault'] ) {
                    self::assertGreaterThanOrEqual( 4, $hits, $label );
                }
                if ( $cfg['expect_get_redirect'] ) {
                    self::assertSame( PatientPortalShell::portal_url(), $getRedirect, $label );
                } else {
                    self::assertNull( $getRedirect, $label );
                }

                if ( $cfg['expect_denied'] ) {
                    self::assertTrue( $postDied, $label );
                    self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $postMsg, $label );
                    self::assertFalse( $appAvailable, $label );
                    self::assertSame( 403, $restResp->get_status(), $label );
                    $restData = $restResp->get_data();
                    self::assertIsArray( $restData, $label );
                    self::assertArrayHasKey( 'code', $restData, $label );
                    self::assertSame( PatientAccess::PROOF_FAILED_CODE, $restData['code'], $label );
                    self::assertSame( [], $stored, $label );

                    // 5) Even if an app password row existed directly in usermeta, `wp_authenticate_application_password` rejects it (`user.php:429`).
                    $seeded = WP_Application_Passwords::create_new_application_password( $uid, [ 'name' => 'DirectSeed_' . $label ] );
                    self::assertIsArray( $seeded, $label );
                    self::assertTrue( WP_Application_Passwords::is_in_use(), $label );
                    if ( $cfg['fault'] ) {
                        add_filter( 'query', $fault, 5 );
                    }
                    try {
                        $appAuth = wp_authenticate_application_password( null, $userObj->user_login, (string) $seeded[0] );
                    } finally {
                        if ( $cfg['fault'] ) {
                            remove_filter( 'query', $fault, 5 );
                        }
                        WP_Application_Passwords::delete_all_application_passwords( $uid );
                    }
                    self::assertInstanceOf( WP_Error::class, $appAuth, $label );
                    self::assertSame( 'application_passwords_disabled_for_user', $appAuth->get_error_code(), $label );
                } else {
                    self::assertFalse( $postDied, $label );
                    self::assertTrue( $appAvailable, $label );
                    self::assertSame( 201, $restResp->get_status(), $label );
                    self::assertCount( 1, $stored, $label );
                    $restData = $restResp->get_data();
                    self::assertIsArray( $restData, $label );
                    self::assertArrayHasKey( 'password', $restData, $label );
                    $appAuth = wp_authenticate_application_password( null, $userObj->user_login, (string) $restData['password'] );
                    self::assertInstanceOf( WP_User::class, $appAuth, $label );
                    self::assertSame( $uid, (int) $appAuth->ID, $label );
                    WP_Application_Passwords::delete_all_application_passwords( $uid );
                }
            }
        } finally {
            remove_filter( 'wp_is_application_passwords_available', '__return_true', 999 );
            remove_filter( 'application_password_is_api_request', '__return_true', 999 );
            $_GET                      = $prevGet;
            $_POST                     = $prevPost;
            $_REQUEST                  = $prevRequest;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevRequestUri ) {
                unset( $_SERVER['REQUEST_URI'] );
            } else {
                $_SERVER['REQUEST_URI'] = $prevRequestUri;
            }
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function roleCombinationMatrixProvider(): array {
        return [
            'otp_patient'                  => [ 'otp_patient' ],
            'legacy_patient'               => [ 'legacy_patient' ],
            'patient_plus_subscriber'      => [ 'patient_plus_subscriber' ],
            'subscriber_with_login_row'    => [ 'subscriber_with_login_row' ],
            'patient_empty_custom_role'    => [ 'patient_empty_custom_role' ],
            'unknown_patient_lookup_fault' => [ 'unknown_patient_lookup_fault' ],
            'staff_doctor'                 => [ 'staff_doctor' ],
            'admin_manage_options'         => [ 'admin_manage_options' ],
            'mixed_patient_plus_doctor'    => [ 'mixed_patient_plus_doctor' ],
            'patient_active_custom_role'   => [ 'patient_active_custom_role' ],
            'patient_wp_custom_cap_role'   => [ 'patient_wp_custom_cap_role' ],
            'staff_doctor_lookup_fault'    => [ 'staff_doctor_lookup_fault' ],
        ];
    }

    /**
     * Round 3 Task B + Task E: tests the single shared predicate `PatientAccess::patient_target_state()`
     * across every role combination in Task B over all 6 surfaces (`wp-admin` redirect, `personal_options_update`
     * + `newuseremail` + `dismiss`, `REST /wp/v2/users*`, `XML-RPC wp_editProfile` & `wp_getUsersBlogs`,
     * WP reset key [`allow_password_reset`, `get_password_reset_key`, `validate_password_reset`, `reset_password`],
     * and Application Passwords [`wp_is_application_passwords_available_for_user`, REST create]).
     *
     * INFERENCE (revert-to-fail):
     * - Reverting `PatientAccess::patient_target_state()` (`PatientAccess.php:283-325`) or its delegation
     *   in `PatientPortalPage` (`:207-268`) and `PatientCredentialGuard` (`:108-196`) breaks `patient_plus_subscriber`,
     *   `subscriber_with_login_row`, `patient_empty_custom_role`, `patient_active_custom_role`, or `unknown_patient_lookup_fault`.
     * - Reverting `1 !== count(roles)` in `PatientPortalPage::guardWpAdmin()` (`:218`) redirects `patient_plus_subscriber`
     *   on GET `profile.php`, breaking `WooCommerceAdminAccessProfileTest.php:284`.
     *
     * @dataProvider roleCombinationMatrixProvider
     */
    public function testSharedPredicateMatrixAcrossRoleCombinationsAndSurfaces( string $caseKey ): void {
        global $wpdb;

        if ( ! class_exists( wp_xmlrpc_server::class ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
            $ixrFile    = ABSPATH . WPINC . '/class-IXR.php';
            $serverFile = ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
            if ( file_exists( $ixrFile ) ) {
                require_once $ixrFile;
            }
            if ( file_exists( $serverFile ) ) {
                require_once $serverFile;
            }
        }
        self::assertTrue( class_exists( wp_xmlrpc_server::class ) );

        $uid            = 0;
        $pass           = $this->patientKnownPass;
        $otpToken       = null;
        $expectDenied   = true;
        $expectRedirect = true;
        $expectState    = 'PATIENT';
        $injectFault    = false;

        switch ( $caseKey ) {
            case 'otp_patient':
                $uid = $this->userAccountActive;
                $this->seedOtp( $this->clinicPublic, self::MOBILE_ACCOUNT_ACTIVE, '616161', OtpService::PURPOSE_LOGIN );
                $otpToken = $this->verifyOtpWithSession( self::MOBILE_ACCOUNT_ACTIVE, '616161', OtpService::PURPOSE_LOGIN );
                break;

            case 'legacy_patient':
                $uid = $this->createUser( 'mat_legacy_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                break;

            case 'patient_plus_subscriber':
                $uid = $this->createUser( 'mat_patsub_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $u   = get_userdata( $uid );
                self::assertInstanceOf( WP_User::class, $u );
                $u->add_role( 'subscriber' );
                $expectRedirect = false; // WooCommerceAdminAccessProfileTest:284 GET exception preserved.
                break;

            case 'subscriber_with_login_row':
                $uid    = $this->createUser( 'mat_sublogin_' . uniqid(), $pass, 'subscriber' );
                $logins = new PatientLoginRepository( App::db() );
                $aid    = $logins->insert_pending_account( $uid, '09123334455', 'ساب', 'بیمار' );
                $logins->activate_account_from_pending( $aid, $uid );
                break;

            case 'patient_empty_custom_role':
                $uid = $this->createUser( 'mat_patemp_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $rk  = 'cx_mat_empty_' . substr( uniqid(), -6 );
                $this->insertActiveCustomRoleDef( $this->clinicPublic, $rk, [] );
                App::membership_service()->create_membership( $this->clinicPublic, $uid, $rk );
                break;

            case 'unknown_patient_lookup_fault':
                $uid         = $this->createUser( 'mat_unk_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $expectState = 'UNKNOWN';
                $injectFault = true;
                break;

            case 'staff_doctor':
                $uid            = $this->staffDoctorUser;
                $pass           = $this->staffDoctorPass;
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                break;

            case 'admin_manage_options':
                $uid            = $this->createUser( 'mat_admin_' . uniqid(), $pass, 'administrator' );
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                break;

            case 'mixed_patient_plus_doctor':
                $uid = $this->createUser( 'mat_mixed_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $u   = get_userdata( $uid );
                self::assertInstanceOf( WP_User::class, $u );
                $u->add_role( RolesAndCapabilities::ROLE_DOCTOR );
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                break;

            case 'patient_active_custom_role':
                $uid = $this->createUser( 'mat_patcap_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $rk  = 'cx_mat_staff_' . substr( uniqid(), -6 );
                $this->insertActiveCustomRoleDef( $this->clinicPublic, $rk, [ RolesAndCapabilities::QUEUE_READ ] );
                App::membership_service()->create_membership( $this->clinicPublic, $uid, $rk );
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                break;

            case 'patient_wp_custom_cap_role':
                $wpRoleKey = 'cx_wp_cap_' . substr( uniqid(), -6 );
                if ( null === get_role( $wpRoleKey ) ) {
                    add_role( $wpRoleKey, 'Matrix WP Custom Cap', [ 'read' => true, RolesAndCapabilities::APPT_READ => true ] );
                }
                $uid = $this->createUser( 'mat_patwp_' . uniqid(), $pass, RolesAndCapabilities::ROLE_PATIENT );
                $u   = get_userdata( $uid );
                self::assertInstanceOf( WP_User::class, $u );
                $u->add_role( $wpRoleKey );
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                break;

            case 'staff_doctor_lookup_fault':
                $uid            = $this->staffDoctorUser;
                $pass           = $this->staffDoctorPass;
                $expectDenied   = false;
                $expectRedirect = false;
                $expectState    = 'NOT_PATIENT';
                $injectFault    = true;
                break;
        }

        if ( null !== $otpToken ) {
            $this->activateSession( $otpToken );
        } else {
            PatientTwoProofTestHelper::acting_as( $uid );
        }

        $userObj = get_userdata( $uid );
        self::assertInstanceOf( WP_User::class, $userObj, $caseKey );

        $origEmail   = (string) $userObj->user_email;
        $origHash    = (string) $userObj->user_pass;
        $pendingMeta = [
            'hash'     => 'mat-hash-' . uniqid(),
            'newemail' => 'mat-new-' . uniqid() . '@clinic.test',
        ];
        update_user_meta( $uid, '_new_email', $pendingMeta );

        $orgTable     = App::db()->table( 'cpms_organization_memberships' );
        $exactSql     = 'SELECT id FROM ' . $orgTable . " WHERE wp_user_id = {$uid} AND status = 'active' LIMIT 1";
        $hits         = 0;
        $seenQueries  = 0;
        $fault        = static function ( mixed $query ) use ( $exactSql, $orgTable, &$hits, &$seenQueries ): mixed {
            ++$seenQueries;
            if ( is_string( $query ) && trim( $query ) === $exactSql ) {
                ++$hits;
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $orgTable;
            }
            return $query;
        };
        $preMail      = static fn ( mixed $null ): bool => true;
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        if ( $injectFault ) {
            add_filter( 'query', $fault, 5 );
        }
        add_filter( 'pre_wp_mail', $preMail, 1, 1 );
        add_filter( 'wp_is_application_passwords_available', '__return_true', 999 );

        $prevGet        = $_GET;
        $prevPost       = $_POST;
        $prevRequest    = $_REQUEST;
        $prevMethod     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $prevPagenow    = $GLOBALS['pagenow'] ?? null;
        $prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset( $_SERVER['REMOTE_ADDR'] );

        try {
            self::assertSame( $expectState, App::patient_access()->patient_target_state( $userObj ), $caseKey );

            // 1) wp-admin GET redirect (profile.php).
            $GLOBALS['pagenow'] = 'profile.php';
            $getRedirect        = $this->captureGuardWpAdminRedirect( $uid, [], 'GET' );
            if ( $expectRedirect ) {
                self::assertSame( PatientPortalShell::portal_url(), $getRedirect, $caseKey );
            } else {
                self::assertNull( $getRedirect, $caseKey );
            }

            // 2) personal_options_update + POST newuseremail + POST dismiss.
            $GLOBALS['pagenow']        = 'profile.php';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST                     = [ 'email' => $origEmail ];
            $profileDied               = false;
            try {
                do_action( 'personal_options_update', $uid );
            } catch ( WPDieException ) {
                $profileDied = true;
            }

            $_GET              = [ 'newuseremail' => $pendingMeta['hash'] ];
            $_REQUEST          = $_GET;
            $newEmailDied      = false;
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $newEmailDied = true;
            }

            $_GET         = [ 'dismiss' => $uid . '_new_email', '_wpnonce' => wp_create_nonce( 'dismiss-' . $uid . '_new_email' ) ];
            $_REQUEST     = $_GET;
            $dismissDied  = false;
            try {
                PatientPortalPage::guardWpAdmin();
            } catch ( WPDieException ) {
                $dismissDied = true;
            }

            // 3) REST /wp/v2/users/me (GET + POST) and /wp/v2/users (GET).
            $_GET     = [];
            $_POST    = [];
            $_REQUEST = [];
            $nonce    = wp_create_nonce( 'wp_rest' );
            $meGet    = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/me', [], $nonce );
            $mePost   = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me', [ 'first_name' => 'MatRest_' . $caseKey ], $nonce );
            $listGet  = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users', [], $nonce );

            // 4) XML-RPC wp_editProfile & wp_getUsersBlogs.
            $xmlEdit  = ( new wp_xmlrpc_server() )->wp_editProfile( [ 1, $userObj->user_login, $pass, [ 'first_name' => 'MatXml_' . $caseKey ] ] );
            $xmlBlogs = ( new wp_xmlrpc_server() )->wp_getUsersBlogs( [ $userObj->user_login, $pass ] );

            // 5) Reset key + already-valid key validate_password_reset + direct reset_password().
            $allowReset = apply_filters( 'allow_password_reset', true, $uid );
            $keyRes     = get_password_reset_key( $userObj );
            $rawValid   = 'MatResetKey123456';
            if ( $expectDenied ) {
                wp_update_user(
                    [
                        'ID'                  => $uid,
                        'user_activation_key' => time() . ':' . wp_fast_hash( $rawValid ),
                    ]
                );
                $checkUser = check_password_reset_key( $rawValid, $userObj->user_login );
            } else {
                self::assertIsString( $keyRes, $caseKey );
                $checkUser = check_password_reset_key( $keyRes, $userObj->user_login );
            }
            $resetErrors = new WP_Error();
            do_action( 'validate_password_reset', $resetErrors, $checkUser );
            $directResetDied = false;
            if ( $expectDenied ) {
                try {
                    reset_password( $userObj, 'MatNewPass123!' );
                } catch ( WPDieException ) {
                    $directResetDied = true;
                }
            }

            // 6) Application Passwords availability + REST creation.
            $appAvail = wp_is_application_passwords_available_for_user( $userObj );
            $appRest  = $this->dispatchRestWithNonce( 'POST', '/wp/v2/users/me/application-passwords', [ 'name' => 'MatApp_' . $caseKey ], $nonce );
        } finally {
            if ( $injectFault ) {
                remove_filter( 'query', $fault, 5 );
            }
            remove_filter( 'pre_wp_mail', $preMail, 1 );
            remove_filter( 'wp_is_application_passwords_available', '__return_true', 999 );
            $wpdb->suppress_errors( $prevSuppress );
            WP_Application_Passwords::delete_all_application_passwords( $uid );
            $_GET                      = $prevGet;
            $_POST                     = $prevPost;
            $_REQUEST                  = $prevRequest;
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            if ( null === $prevPagenow ) {
                unset( $GLOBALS['pagenow'] );
            } else {
                $GLOBALS['pagenow'] = $prevPagenow;
            }
            if ( null === $prevRemoteAddr ) {
                unset( $_SERVER['REMOTE_ADDR'] );
            } else {
                $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr;
            }
        }

        if ( $injectFault && 'unknown_patient_lookup_fault' === $caseKey ) {
            self::assertGreaterThanOrEqual( 4, $hits, $caseKey );
        } elseif ( $injectFault && 'staff_doctor_lookup_fault' === $caseKey ) {
            self::assertGreaterThan( 0, $seenQueries, $caseKey );
            self::assertSame( 0, $hits, $caseKey );
        }

        self::assertSame( 200, $meGet->get_status(), $caseKey );

        $afterUser = get_userdata( $uid );
        self::assertInstanceOf( WP_User::class, $afterUser, $caseKey );

        if ( $expectDenied ) {
            self::assertTrue( $profileDied, $caseKey );
            self::assertTrue( $newEmailDied, $caseKey );
            self::assertTrue( $dismissDied, $caseKey );
            self::assertSame( $origEmail, (string) $afterUser->user_email, $caseKey );
            self::assertSame( $pendingMeta, get_user_meta( $uid, '_new_email', true ), $caseKey );
            self::assertSame( 403, $mePost->get_status(), $caseKey );
            self::assertSame( 403, $listGet->get_status(), $caseKey );
            self::assertInstanceOf( IXR_Error::class, $xmlEdit, $caseKey );
            self::assertSame( 403, $xmlEdit->code, $caseKey );
            self::assertInstanceOf( IXR_Error::class, $xmlBlogs, $caseKey );
            self::assertSame( 403, $xmlBlogs->code, $caseKey );
            self::assertSame( '', (string) $afterUser->first_name, $caseKey );
            self::assertFalse( $allowReset, $caseKey );
            self::assertInstanceOf( WP_Error::class, $keyRes, $caseKey );
            self::assertSame( 'no_password_reset', $keyRes->get_error_code(), $caseKey );
            self::assertTrue( $resetErrors->has_errors(), $caseKey );
            self::assertContains( PatientCredentialGuard::ERROR_CODE, $resetErrors->get_error_codes(), $caseKey );
            self::assertTrue( $directResetDied, $caseKey );
            self::assertSame( $origHash, (string) $afterUser->user_pass, $caseKey );
            self::assertFalse( $appAvail, $caseKey );
            self::assertSame( 403, $appRest->get_status(), $caseKey );
        } else {
            self::assertFalse( $profileDied, $caseKey );
            self::assertFalse( $newEmailDied, $caseKey );
            self::assertFalse( $dismissDied, $caseKey );
            self::assertSame( 200, $mePost->get_status(), $caseKey );
            self::assertTrue( $xmlEdit, $caseKey );
            self::assertIsArray( $xmlBlogs, $caseKey );
            self::assertSame( 'MatXml_' . $caseKey, (string) $afterUser->first_name, $caseKey );
            self::assertTrue( $allowReset, $caseKey );
            self::assertIsString( $keyRes, $caseKey );
            self::assertFalse( $resetErrors->has_errors(), $caseKey );
            self::assertTrue( $appAvail, $caseKey );
            self::assertSame( 201, $appRest->get_status(), $caseKey );
        }
    }

    /** @param list<string> $capabilities */
    private function insertActiveCustomRoleDef( int $clinicId, string $roleKey, array $capabilities ): void {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $defs   = $wpdb->prefix . 'cpms_custom_role_defs';
        $defCap = $wpdb->prefix . 'cpms_custom_role_capabilities';

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $roleKey,
                'active',
                1,
                $now,
                $now
            )
        );
        $defId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $defId );

        foreach ( $capabilities as $capability ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$defCap} (role_def_id, capability, created_at) VALUES (%d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $defId,
                    $capability,
                    $now
                )
            );
        }
    }
}

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

            $otherUser = $this->dispatchRestWithNonce( 'GET', '/wp/v2/users/' . $this->staffDoctorUser, [], $nonce );
            self::assertSame( 403, $otherUser->get_status() );

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
}

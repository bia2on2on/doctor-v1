<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientBindingPromoter;
use ClinicCore\Application\Auth\PatientNidHmac;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Patients\PatientEnrollmentOptions;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Frontend\PortalLogin;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * End-to-end integration tests for the two-proof patient engine (`cpms_patient_logins`,
 * `WP_Session_Tokens`, `PatientBindingPromoter`, `PatientAccess::level()` /
 * `authorize_two_proof_request()`, `PatientNidHmac`, `PatientPortalPage::isPatientOnly()`,
 * `PublicBookingShortcode`, and NID-correction binding closure).
 */
final class PatientTwoProofEnforcementIntegrationTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_A   = '09128880001';
    private const MOBILE_B   = '09128880002';
    private const MOBILE_DEP = '09128880003';
    private const NID_A      = '0010000003';
    private const NID_WRONG  = '0011223344';
    private const NID_DEP    = '0010000011';

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
    private int $locationA;
    private int $patientA;
    private int $patientNoNid;
    private int $patientDep;
    private int $patientUserA;
    private int $patientUserB;
    private int $staffDocUser;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->clinicA = 1;
        $this->orgId   = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $this->clinicA ]
        );
        self::assertGreaterThan( 0, $this->orgId );

        $this->locationA = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d LIMIT 1',
            [ $this->clinicA ]
        );
        self::assertGreaterThan( 0, $this->locationA );

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'TwoProof Clinic B',
                'slug'            => 'two-proof-clinic-b-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB = $db->wpdb_last_insert_id();

        $this->patientA     = $this->insertPatient( $this->clinicA, 'MR-TP-A-' . uniqid(), self::MOBILE_A, self::NID_A );
        $this->patientNoNid = $this->insertPatient( $this->clinicB, 'MR-TP-NN-' . uniqid(), self::MOBILE_B, null );
        $this->patientDep   = $this->insertPatient( $this->clinicA, 'MR-TP-D-' . uniqid(), self::MOBILE_DEP, self::NID_DEP );

        $this->patientUserA = $this->makeUser( 'tp_pat_a_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserB = $this->makeUser( 'tp_pat_b_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->staffDocUser = $this->makeUser( 'tp_doc_' . uniqid(), RolesAndCapabilities::ROLE_DOCTOR );
        cpms_test_seed_membership( $this->staffDocUser, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );

        $logins = new PatientLoginRepository( $db );
        $logins->insert_pending_account( $this->patientUserA, self::MOBILE_A, 'PatA', 'Test' );
        $logins->insert_pending_account( $this->patientUserB, self::MOBILE_B, 'PatB', 'Test' );

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

    public function testTwoProofPositiveFlowPromotesAllFourArtefactsWith8hNonPersistentSessionAndNoSqlNid(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $token, '8h non-persistent WP session token must be issued.' );

        // Verify WP session expiration is bounded to ~8 hours (28800s), not 14d persistent cookie.
        $sessionData = WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token );
        self::assertIsArray( $sessionData );
        $ttlRemaining = (int) $sessionData['expiration'] - time();
        self::assertGreaterThan( 28000, $ttlRemaining );
        self::assertLessThanOrEqual( PatientSessionProofStore::SESSION_TTL_SECONDS + 5, $ttlRemaining );

        $this->seedOtp( $this->clinicA, self::MOBILE_A, '424242', OtpService::PURPOSE_VERIFY_MOBILE );

        $capturedSql = [];
        $sqlSpy      = static function ( $query ) use ( &$capturedSql ) {
            $capturedSql[] = (string) $query;

            return $query;
        };

        $now = time();
        add_filter( 'query', $sqlSpy, 5 );
        try {
            $result = App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '424242',
                '198.51.100.10',
                $now,
                $token
            );
        } finally {
            remove_filter( 'query', $sqlSpy, 5 );
        }

        self::assertSame( $this->patientA, $result['patient_id'] );
        self::assertSame( $this->clinicA, $result['clinic_id'] );
        self::assertGreaterThan( 0, $result['binding_id'] );

        // H1: Candidate patient lookup SQL must NEVER contain the NID literal or `national_id =`.
        $patientSelects = array_values(
            array_filter(
                $capturedSql,
                static fn ( string $q ): bool => 1 === preg_match( '/\A\s*SELECT\b[\s\S]*\bFROM\s+[`"]?\w*cpms_patients\b/i', $q )
            )
        );
        self::assertNotSame( [], $patientSelects );
        foreach ( $patientSelects as $selectSql ) {
            self::assertStringNotContainsString( self::NID_A, $selectSql );
            self::assertStringNotContainsString( 'national_id =', $selectSql );
        }

        // Verify all 4 artefacts and no raw NID in session/audit/rate_limits.
        $logins  = new PatientLoginRepository( App::db() );
        $account = $logins->find_account_by_user_id( $this->patientUserA );
        self::assertIsArray( $account );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $account['state'] );

        $binding = $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA );
        self::assertIsArray( $binding );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $binding['state'] );
        self::assertSame( PatientLoginRepository::METHOD_TWO_FACTOR, $binding['verification_method'] );
        self::assertSame( $this->clinicA . ':' . $this->patientA, $binding['live_key'] );

        $gate = App::patient_access();
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            $gate->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 60, $token )
        );
        self::assertSame(
            [ $this->patientA ],
            $gate->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 60, $token )
        );

        foreach ( [ PatientAccess::MODE_CLINICAL, PatientAccess::MODE_BOOKING_APPOINTMENT, PatientAccess::MODE_PATIENT_RESOURCE ] as $mode ) {
            $decision = $gate->authorize_two_proof_request(
                $this->patientUserA,
                $this->clinicA,
                $this->patientA,
                null,
                $mode,
                false,
                $now + 60,
                $token
            );
            self::assertTrue( $decision['allowed'], 'Mode ' . $mode . ' must allow after two-proof promotion.' );
            self::assertSame( 200, $decision['http_status'] );
        }

        // Raw NID must NEVER appear in session metadata, rate limits, or audit logs.
        $updatedSession = WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token );
        self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $updatedSession ) );

        $rateKeys = App::db()->fetchAll(
            'SELECT window_key FROM ' . App::db()->table( 'cpms_rate_limits' )
        );
        foreach ( $rateKeys as $rk ) {
            self::assertStringNotContainsString( self::NID_A, (string) ( $rk['window_key'] ?? '' ) );
            self::assertStringNotContainsString( self::MOBILE_A, (string) ( $rk['window_key'] ?? '' ) );
        }

        $auditPayloads = App::db()->fetchAll(
            'SELECT before_json, after_json, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' )
            . " WHERE action = 'PATIENT_BINDING_PROMOTED'"
        );
        self::assertCount( 1, $auditPayloads );
        self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $auditPayloads[0] ) );
        self::assertStringNotContainsString( self::MOBILE_A, (string) wp_json_encode( $auditPayloads[0] ) );
    }

    public function testOtpMismatchWrongNidMissingNidAndReusedOtpReturnUniform403AndLeaveNoBinding(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $now        = time();

        // 1) Wrong NID with valid OTP -> uniform 403, no binding, and OTP is NOT restored.
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '112233', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_WRONG,
                '112233',
                '198.51.100.11',
                $now,
                $token
            );
            self::fail( 'Wrong NID must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $e->getMessage() );
        }

        // Retrying with the SAME OTP and the CORRECT NID must ALSO fail (single-use OTP not restored).
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '112233',
                '198.51.100.11',
                $now,
                $token
            );
            self::fail( 'Consumed OTP must not be reusable after failed NID check.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }

        // 2) Record without national_id (M2) -> uniform 403, never auto-linked.
        $tokenB = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserB );
        $this->seedOtp( $this->clinicB, self::MOBILE_B, '223344', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserB,
                $this->clinicB,
                self::MOBILE_B,
                self::NID_A,
                '223344',
                '198.51.100.12',
                $now,
                $tokenB
            );
            self::fail( 'Record without national_id must never auto-link.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $e->getMessage() );
        }

        // 3) Wrong-purpose OTP (`login` instead of `verify_mobile`) -> uniform 403.
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '334455', OtpService::PURPOSE_LOGIN );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '334455',
                '198.51.100.13',
                $now,
                $token
            );
            self::fail( 'Wrong OTP purpose must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }

        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now, $token ) );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserB, $this->patientNoNid, $this->clinicB, $now, $tokenB ) );
    }

    public function testSessionProofExpiryDestructionCrossTokenAndDepthZeroAreEnforced(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $tokenOther = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $now        = time();

        // Depth > 0 fails closed without consuming OTP.
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '445566', OtpService::PURPOSE_VERIFY_MOBILE );
        App::db()->transactional(
            function () use ( $now, $token ): void {
                try {
                    App::patient_binding_promoter()->promote(
                        $this->patientUserA,
                        $this->clinicA,
                        self::MOBILE_A,
                        self::NID_A,
                        '445566',
                        '198.51.100.14',
                        $now,
                        $token
                    );
                    self::fail( 'Nested transaction must fail closed.' );
                } catch ( BookingException $e ) {
                    self::assertSame( 403, $e->httpStatus );
                    self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
                }
            }
        );

        // OTP must still be unconsumed at depth 0 and succeed now!
        $promoted = App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            self::NID_A,
            '445566',
            '198.51.100.14',
            $now,
            $token
        );
        self::assertGreaterThan( 0, $promoted['binding_id'] );

        $gate = App::patient_access();
        // Positive control on proving token within 60m.
        self::assertSame( PatientAccess::LEVEL_FULL, $gate->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 10, $token ) );
        // Negative: different session token of the SAME user has NO proof -> none!
        self::assertSame( PatientAccess::LEVEL_NONE, $gate->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 10, $tokenOther ) );
        // Negative: past 60m absolute proof TTL -> none!
        self::assertSame( PatientAccess::LEVEL_NONE, $gate->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 3601, $token ) );

        // Negative: session destruction kills access immediately.
        $proofStore->destroy_all_sessions( $this->patientUserA );
        self::assertSame( PatientAccess::LEVEL_NONE, $gate->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 10, $token ) );
    }

    public function testCompetingAccountDependentIntersectionCapAndRateLimits(): void {
        $proofStore = new PatientSessionProofStore();
        $tokenA     = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $tokenB     = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserB );
        $now        = time();

        // Bind Patient A and Dependent (distinct mobile) inside PatientUserA's session.
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '551100', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            self::NID_A,
            '551100',
            '198.51.100.20',
            $now,
            $tokenA
        );

        // Dependent without fresh OTP for distinct mobile fails closed (M7).
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_DEP,
                self::NID_DEP,
                null,
                '198.51.100.20',
                $now,
                $tokenA
            );
            self::fail( 'Dependent with distinct mobile must require its own OTP.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
        }

        // Dependent with its own OTP + NID succeeds in the same session.
        $this->seedOtp( $this->clinicA, self::MOBILE_DEP, '552200', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_DEP,
            self::NID_DEP,
            '552200',
            '198.51.100.20',
            $now,
            $tokenA
        );

        $proved = App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 5, $tokenA );
        sort( $proved );
        $expected = [ $this->patientA, $this->patientDep ];
        sort( $expected );
        self::assertSame( $expected, $proved );

        // Competing account cannot bind already-bound Patient A (u_pl_live).
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '553300', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserB,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '553300',
                '198.51.100.21',
                $now,
                $tokenB
            );
            self::fail( 'Competing live binding must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }

        // Verify HMAC NID rate-limit bucket stops excessive attempts on account/NID.
        $attemptsBlocked = 0;
        for ( $i = 0; $i < PatientBindingPromoter::RATE_ACCOUNT_MAX + 2; $i++ ) {
            try {
                App::patient_binding_promoter()->promote(
                    $this->patientUserB,
                    $this->clinicA,
                    self::MOBILE_B,
                    self::NID_WRONG,
                    '999999',
                    '198.51.100.22',
                    $now,
                    $tokenB
                );
            } catch ( BookingException $e ) {
                self::assertSame( 403, $e->httpStatus );
                ++$attemptsBlocked;
            }
        }
        self::assertSame( PatientBindingPromoter::RATE_ACCOUNT_MAX + 2, $attemptsBlocked );

        // Control at threshold: an otherwise-valid request (valid mobile, valid OTP, matching NID)
        // on the throttled account MUST still fail closed and leave the OTP unconsumed.
        $validMobile = '09128880009';
        $validNid    = '0020000006';
        $this->insertPatient( $this->clinicA, 'MR-TP-VALID-RL-' . uniqid(), $validMobile, $validNid );
        $this->seedOtp( $this->clinicA, $validMobile, '441122', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserB,
                $this->clinicA,
                $validMobile,
                $validNid,
                '441122',
                '198.51.100.23',
                $now,
                $tokenB
            );
            self::fail( 'Otherwise-valid attempt at account rate-limit threshold must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }
        $logins = new PatientLoginRepository( App::db() );
        self::assertSame( [], $logins->find_active_bindings_for_user( $this->patientUserB ) );
        $unconsumedOtp = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_otp_tokens' )
            . ' WHERE mobile = %s AND consumed_at IS NULL',
            [ $validMobile ]
        );
        self::assertSame( 1, $unconsumedOtp, 'Rate-limited valid control must not consume OTP.' );
    }

    public function testRateLimitBucketsAccountMobileNidAndPreviousKeyWithValidControls(): void {
        $proofStore = new PatientSessionProofStore();
        $logins     = new PatientLoginRepository( App::db() );
        $now        = time();

        // 0) Positive control below threshold: fresh user + valid OTP + matching NID succeeds.
        $posMobile = '09128850001';
        $posNid    = '0030000009';
        $posPat    = $this->insertPatient( $this->clinicA, 'MR-RL-POS-' . uniqid(), $posMobile, $posNid );
        $posUser   = $this->makeUser( 'tp_rl_pos_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins->insert_pending_account( $posUser, $posMobile, 'RL', 'Pos' );
        $posToken = $proofStore->issue_non_persistent_auth_cookie( $posUser );
        $this->seedOtp( $this->clinicA, $posMobile, '112233', OtpService::PURPOSE_VERIFY_MOBILE );
        $posResult = App::patient_binding_promoter()->promote(
            $posUser,
            $this->clinicA,
            $posMobile,
            $posNid,
            '112233',
            '198.51.101.1',
            $now,
            $posToken
        );
        self::assertSame( $posPat, $posResult['patient_id'] );

        // 1) Independent account bucket (`pl-acct:<uid>`): exhaust on fresh account, then valid inputs fail.
        $acctMobile = '09128850002';
        $acctNid    = '0040000001';
        $this->insertPatient( $this->clinicA, 'MR-RL-ACCT-' . uniqid(), $acctMobile, $acctNid );
        $acctUser = $this->makeUser( 'tp_rl_acct_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins->insert_pending_account( $acctUser, $acctMobile, 'RL', 'Acct' );
        $acctToken = $proofStore->issue_non_persistent_auth_cookie( $acctUser );
        for ( $i = 0; $i < PatientBindingPromoter::RATE_ACCOUNT_MAX; $i++ ) {
            try {
                App::patient_binding_promoter()->promote(
                    $acctUser,
                    $this->clinicA,
                    $acctMobile,
                    self::NID_WRONG,
                    '999999',
                    '198.51.101.' . ( 10 + $i ),
                    $now,
                    $acctToken
                );
                self::fail( 'Wrong credentials must fail closed.' );
            } catch ( BookingException $e ) {
                self::assertSame( 403, $e->httpStatus );
            }
        }
        $this->seedOtp( $this->clinicA, $acctMobile, '223344', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $acctUser,
                $this->clinicA,
                $acctMobile,
                $acctNid,
                '223344',
                '198.51.101.29',
                $now,
                $acctToken
            );
            self::fail( 'Account rate-limit bucket must block otherwise-valid attempt at threshold.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }
        self::assertSame( [], $logins->find_active_bindings_for_user( $acctUser ) );

        // 2) Independent mobile bucket (`pl-mob:<hmac>`): distinct accounts & NIDs & IPs exhaust one mobile.
        $mobTarget = '09128870001';
        $mobNid    = '0090000005';
        $this->insertPatient( $this->clinicA, 'MR-RL-MOB-' . uniqid(), $mobTarget, $mobNid );
        for ( $i = 0; $i < PatientBindingPromoter::RATE_MOBILE_MAX; $i++ ) {
            $u = $this->makeUser( 'tp_rl_mob_' . $i . '_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
            $logins->insert_pending_account( $u, sprintf( '09128871%03d', $i ), 'RL', 'Mob' );
            $t = $proofStore->issue_non_persistent_auth_cookie( $u );
            try {
                App::patient_binding_promoter()->promote(
                    $u,
                    $this->clinicA,
                    $mobTarget,
                    self::NID_WRONG,
                    '999999',
                    '198.51.102.' . ( 10 + $i ),
                    $now,
                    $t
                );
                self::fail( 'Wrong credentials must fail closed.' );
            } catch ( BookingException $e ) {
                self::assertSame( 403, $e->httpStatus );
            }
        }
        $mobFreshUser  = $this->makeUser( 'tp_rl_mob_ok_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins->insert_pending_account( $mobFreshUser, '09128871999', 'RL', 'MobOk' );
        $mobFreshToken = $proofStore->issue_non_persistent_auth_cookie( $mobFreshUser );
        $this->seedOtp( $this->clinicA, $mobTarget, '334455', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $mobFreshUser,
                $this->clinicA,
                $mobTarget,
                $mobNid,
                '334455',
                '198.51.102.99',
                $now,
                $mobFreshToken
            );
            self::fail( 'Mobile rate-limit bucket must block otherwise-valid attempt from fresh account.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }
        self::assertSame( [], $logins->find_active_bindings_for_user( $mobFreshUser ) );

        // 3) Independent NID bucket (`nid:<hmac>`): distinct accounts, mobiles & IPs exhaust one NID.
        $nidTarget = '0050000004';
        for ( $i = 0; $i < PatientBindingPromoter::RATE_NID_MAX; $i++ ) {
            $m = sprintf( '0912889%04d', $i + 1 );
            $u = $this->makeUser( 'tp_rl_nid_' . $i . '_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
            $logins->insert_pending_account( $u, $m, 'RL', 'Nid' );
            $t = $proofStore->issue_non_persistent_auth_cookie( $u );
            try {
                App::patient_binding_promoter()->promote(
                    $u,
                    $this->clinicA,
                    $m,
                    $nidTarget,
                    '999999',
                    '198.51.103.' . ( 10 + $i ),
                    $now,
                    $t
                );
                self::fail( 'Wrong OTP must fail closed.' );
            } catch ( BookingException $e ) {
                self::assertSame( 403, $e->httpStatus );
            }
        }
        $nidFreshMobile = '09128890099';
        $this->insertPatient( $this->clinicA, 'MR-RL-NID-' . uniqid(), $nidFreshMobile, $nidTarget );
        $nidFreshUser  = $this->makeUser( 'tp_rl_nid_ok_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins->insert_pending_account( $nidFreshUser, $nidFreshMobile, 'RL', 'NidOk' );
        $nidFreshToken = $proofStore->issue_non_persistent_auth_cookie( $nidFreshUser );
        $this->seedOtp( $this->clinicA, $nidFreshMobile, '445566', OtpService::PURPOSE_VERIFY_MOBILE );
        try {
            App::patient_binding_promoter()->promote(
                $nidFreshUser,
                $this->clinicA,
                $nidFreshMobile,
                $nidTarget,
                '445566',
                '198.51.103.99',
                $now,
                $nidFreshToken
            );
            self::fail( 'NID HMAC rate-limit bucket must block otherwise-valid attempt from fresh account/mobile/IP.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }
        self::assertSame( [], $logins->find_active_bindings_for_user( $nidFreshUser ) );

        // 4) Previous-key overlap bucket (`cpms_nid_hmac_key_previous`): rotated key still enforces prior-window hits.
        $prevActiveOpt   = get_option( PatientNidHmac::OPTION_KEY, null );
        $prevPreviousOpt = get_option( PatientNidHmac::OPTION_PREVIOUS_KEY, null );
        try {
            $oldKey = 'old-hmac-key-material-32-bytes-01';
            $newKey = 'new-hmac-key-material-32-bytes-02';
            update_option( PatientNidHmac::OPTION_KEY, $oldKey, 'no' );
            delete_option( PatientNidHmac::OPTION_PREVIOUS_KEY );

            $rotNid      = '0060000007';
            $rotMobile   = '09128860001';
            $oldBucket   = ( new PatientNidHmac() )->rate_limit_key( $rotNid );
            $rateLimiter = App::rate();
            for ( $i = 0; $i < PatientBindingPromoter::RATE_NID_MAX; $i++ ) {
                $rateLimiter->hit( $oldBucket, PatientBindingPromoter::RATE_NID_MAX, PatientBindingPromoter::RATE_WINDOW_SEC );
            }

            update_option( PatientNidHmac::OPTION_KEY, $newKey, 'no' );
            update_option( PatientNidHmac::OPTION_PREVIOUS_KEY, $oldKey, 'no' );

            $this->insertPatient( $this->clinicA, 'MR-RL-ROT-' . uniqid(), $rotMobile, $rotNid );
            $rotUser  = $this->makeUser( 'tp_rl_rot_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
            $logins->insert_pending_account( $rotUser, $rotMobile, 'RL', 'Rot' );
            $rotToken = $proofStore->issue_non_persistent_auth_cookie( $rotUser );
            $this->seedOtp( $this->clinicA, $rotMobile, '556677', OtpService::PURPOSE_VERIFY_MOBILE );
            try {
                App::patient_binding_promoter()->promote(
                    $rotUser,
                    $this->clinicA,
                    $rotMobile,
                    $rotNid,
                    '556677',
                    '198.51.104.1',
                    $now,
                    $rotToken
                );
                self::fail( 'Previous-key overlap bucket must block otherwise-valid attempt after key rotation.' );
            } catch ( BookingException $e ) {
                self::assertSame( 403, $e->httpStatus );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
            }
            self::assertSame( [], $logins->find_active_bindings_for_user( $rotUser ) );
        } finally {
            if ( null === $prevActiveOpt || '' === $prevActiveOpt ) {
                delete_option( PatientNidHmac::OPTION_KEY );
            } else {
                update_option( PatientNidHmac::OPTION_KEY, $prevActiveOpt, 'no' );
            }
            if ( null === $prevPreviousOpt || '' === $prevPreviousOpt ) {
                delete_option( PatientNidHmac::OPTION_PREVIOUS_KEY );
            } else {
                update_option( PatientNidHmac::OPTION_PREVIOUS_KEY, $prevPreviousOpt, 'no' );
            }
        }
    }

    public function testPatientSelfUpdateMeNidChangeClosesBindingsAndDestroysSessionsWithControls(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $now        = time();

        $this->seedOtp( $this->clinicA, self::MOBILE_A, '667700', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            self::NID_A,
            '667700',
            '198.51.105.1',
            $now,
            $token
        );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ) );

        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->patientUserA, $now + PatientSessionProofStore::SESSION_TTL_SECONDS, 'logged_in', $token );
        wp_set_current_user( $this->patientUserA );
        $logins = new PatientLoginRepository( App::db() );

        // Control 1: non-NID update via updateMe() leaves active binding and session intact.
        $updatedName = App::patientService()->updateMe( $this->patientUserA, [ 'first_name' => 'NoClosure' ] );
        self::assertSame( 'NoClosure', $updatedName['first_name'] ?? null );
        self::assertArrayNotHasKey( 'national_id', $updatedName, 'P2: national_id must not be projected by updateMe().' );
        self::assertNotNull( $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, true ) );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token ) );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ) );

        // Control 2 (P2): national_id is removed from ME_EDITABLE — NID-only updateMe() fails
        // with generic empty-fields validation (no duplicate-NID oracle), and mixed payload
        // ignores national_id without mutating DB or closing bindings.
        $this->insertPatient( $this->clinicA, 'MR-DUP-' . uniqid(), '09128880099', '0070000001' );
        try {
            App::patientService()->updateMe( $this->patientUserA, [ 'national_id' => '0070000001' ] );
            self::fail( 'NID-only updateMe() must fail with no editable fields.' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
        }
        $mixedRes = App::patientService()->updateMe( $this->patientUserA, [
            'first_name'  => 'NoOracle',
            'national_id' => '0070000001',
        ] );
        self::assertSame( 'NoOracle', $mixedRes['first_name'] ?? null );
        self::assertArrayNotHasKey( 'national_id', $mixedRes );
        self::assertSame( self::NID_A, (string) App::db()->fetchValue( 'SELECT national_id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientA ] ) );
        self::assertNotNull( $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, true ) );
        self::assertIsArray( WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token ) );

        // Positive closure: staff changing national_id via PatientService::update() closes binding and destroys sessions post-commit.
        cpms_test_seed_membership( $this->staffDocUser, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        App::scope()->clinicId = $this->clinicA;
        $staffUpdated = App::patientService()->update( $this->patientA, [ 'national_id' => '0080000002' ], $this->staffDocUser );
        self::assertArrayNotHasKey( 'national_id', $staffUpdated, 'P2: staffView must not project national_id.' );
        self::assertNull( $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, true ) );
        $closedBinding = $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, false );
        self::assertIsArray( $closedBinding );
        self::assertSame( PatientLoginRepository::STATE_CLOSED, $closedBinding['state'] );
        self::assertNull( $closedBinding['live_key'] );
        self::assertNull( WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token ), 'Patient sessions must be destroyed after staff NID change.' );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ) );
    }

    public function testHmacOptionWriteFailureFailsClosedWithSqlWitness(): void {
        global $wpdb;

        $prevOpt        = get_option( PatientNidHmac::OPTION_KEY, null );
        $optionsTable   = preg_quote( $wpdb->options, '/' );
        $exactInsertSql = '/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . $optionsTable . '`?\s*\([^)]*option_name[^)]*\)\s*VALUES\s*\(\s*\'' . preg_quote( PatientNidHmac::OPTION_KEY, '/' ) . '\'/i';

        $witnessMatches = 0;
        $witnessFilter  = static function ( $query ) use ( $exactInsertSql, &$witnessMatches ) {
            if ( 1 === preg_match( $exactInsertSql, (string) $query ) ) {
                ++$witnessMatches;
            }

            return $query;
        };

        $faultMatches = 0;
        $faultFilter  = static function ( $query ) use ( $exactInsertSql, &$faultMatches ) {
            if ( 1 === preg_match( $exactInsertSql, (string) $query ) ) {
                ++$faultMatches;

                return 'INSERT INTO cpms_nonexistent_hmac_options (x) VALUES (1)';
            }

            return $query;
        };

        $suppressBefore = $wpdb->suppress_errors;
        try {
            // 1) Witness pass: unblocked option insert hits exact SQL once and persists key.
            delete_option( PatientNidHmac::OPTION_KEY );
            wp_cache_delete( PatientNidHmac::OPTION_KEY, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            $hmac = new PatientNidHmac();
            add_filter( 'query', $witnessFilter, 5 );
            try {
                $witnessKey = $hmac->rate_limit_key( self::NID_A );
            } finally {
                remove_filter( 'query', $witnessFilter, 5 );
            }
            self::assertSame( 1, $witnessMatches, 'Witness pass must observe exact option INSERT once.' );
            self::assertStringStartsWith( 'nid:', $witnessKey );
            self::assertNotSame( '', (string) get_option( PatientNidHmac::OPTION_KEY, '' ) );

            // 2) Fault pass: block exact option INSERT -> fail closed (never return ephemeral key).
            delete_option( PatientNidHmac::OPTION_KEY );
            wp_cache_delete( PatientNidHmac::OPTION_KEY, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            $wpdb->suppress_errors( true );
            $caughtHmac     = null;
            $caughtPromoter = null;

            add_filter( 'query', $faultFilter, 5 );
            try {
                try {
                    $hmac->rate_limit_key( self::NID_A );
                } catch ( \RuntimeException $e ) {
                    $caughtHmac = $e;
                }

                $proofStore = new PatientSessionProofStore();
                $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
                try {
                    App::patient_binding_promoter()->promote(
                        $this->patientUserA,
                        $this->clinicA,
                        self::MOBILE_A,
                        self::NID_A,
                        '999999',
                        '198.51.106.1',
                        time(),
                        $token
                    );
                } catch ( BookingException $e ) {
                    $caughtPromoter = $e;
                }
            } finally {
                remove_filter( 'query', $faultFilter, 5 );
                $wpdb->suppress_errors( $suppressBefore );
            }

            self::assertSame( 2, $faultMatches, 'Fault filter must match the exact option INSERT for both calls.' );
            self::assertInstanceOf( \RuntimeException::class, $caughtHmac );
            self::assertSame( 'Failed to persist HMAC key', $caughtHmac->getMessage() );
            self::assertInstanceOf( BookingException::class, $caughtPromoter );
            self::assertSame( 403, $caughtPromoter->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $caughtPromoter->errorCode );
        } finally {
            $wpdb->suppress_errors( $suppressBefore );
            if ( null === $prevOpt || '' === $prevOpt ) {
                delete_option( PatientNidHmac::OPTION_KEY );
            } else {
                update_option( PatientNidHmac::OPTION_KEY, $prevOpt, 'no' );
            }
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( PatientNidHmac::OPTION_KEY, 'options' );
        }
    }

    public function testShortcodeAndPortalBookingSurfaceIntersectChooserWithProvedPatientsOnly(): void {
        $db  = App::db();
        $now = $db->nowUtcSql();
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'public.listing_opt_in', true );
        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicA,
                'full_name'  => 'Dr Chooser Shortcode',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $mob1 = '09128840001';
        $mob2 = '09128840002';
        $nid1 = '0020000006';
        $nid2 = '0030000009';

        $db->insert(
            'cpms_patients',
            [
                'clinic_id'   => $this->clinicA,
                'mrn'         => 'MR-CH-1-' . uniqid(),
                'first_name'  => 'PatChooserOne',
                'last_name'   => 'Proved',
                'mobile'      => $mob1,
                'national_id' => $nid1,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $p1 = $db->wpdb_last_insert_id();

        $db->insert(
            'cpms_patients',
            [
                'clinic_id'   => $this->clinicA,
                'mrn'         => 'MR-CH-2-' . uniqid(),
                'first_name'  => 'PatChooserTwo',
                'last_name'   => 'Unproved',
                'mobile'      => $mob2,
                'national_id' => $nid2,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $p2 = $db->wpdb_last_insert_id();

        $user   = $this->makeUser( 'tp_chooser_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins = new PatientLoginRepository( $db );
        $logins->insert_pending_account( $user, $mob1, 'Chooser', 'User' );

        foreach ( [ [ $p1, $mob1, 1 ], [ $p2, $mob2, 0 ] ] as $spec ) {
            $db->insert(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $this->clinicA,
                    'patient_id'     => $spec[0],
                    'wp_user_id'     => $user,
                    'mobile_at_link' => $spec[1],
                    'is_primary'     => $spec[2],
                    'linked_at'      => $now,
                ]
            );
        }

        $token = cpms_test_seed_patient_session( $user, $this->clinicA );
        $t0    = time();
        wp_set_current_user( $user );

        // 1) Negative control (0 of 2 proved): neither [cpms_public_booking] nor render_for_patient_portal() exposes chooser/names.
        $scZero     = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
        $portalZero = PublicBookingShortcode::render_for_patient_portal( $this->clinicA );
        foreach ( [ $scZero, $portalZero ] as $htmlZero ) {
            self::assertStringNotContainsString( 'data-role="patient-chooser"', $htmlZero );
            self::assertStringNotContainsString( 'PatChooserOne', $htmlZero );
            self::assertStringNotContainsString( 'PatChooserTwo', $htmlZero );
            self::assertStringNotContainsString( 'data-patient-id="' . $p1 . '"', $htmlZero );
            self::assertStringNotContainsString( 'data-patient-id="' . $p2 . '"', $htmlZero );
        }

        // 2) Partial proof (1 of 2 proved): chooser still hidden; unproved patient does not leak.
        $this->seedOtp( $this->clinicA, $mob1, '113355', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote( $user, $this->clinicA, $mob1, $nid1, '113355', '198.51.107.1', $t0, $token );
        $scOne     = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
        $portalOne = PublicBookingShortcode::render_for_patient_portal( $this->clinicA );
        foreach ( [ $scOne, $portalOne ] as $htmlOne ) {
            self::assertStringNotContainsString( 'data-role="patient-chooser"', $htmlOne );
            self::assertStringNotContainsString( 'PatChooserTwo', $htmlOne );
            self::assertStringNotContainsString( 'data-patient-id="' . $p2 . '"', $htmlOne );
        }

        // 3) Positive control (2 of 2 proved): chooser renders BOTH proved patients on both surfaces.
        $this->seedOtp( $this->clinicA, $mob2, '224466', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote( $user, $this->clinicA, $mob2, $nid2, '224466', '198.51.107.1', $t0, $token );
        $scTwo     = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
        $portalTwo = PublicBookingShortcode::render_for_patient_portal( $this->clinicA );
        foreach ( [ $scTwo, $portalTwo ] as $htmlTwo ) {
            self::assertStringContainsString( 'data-role="patient-chooser"', $htmlTwo );
            self::assertStringContainsString( 'data-patient-id="' . $p1 . '"', $htmlTwo );
            self::assertStringContainsString( 'data-patient-id="' . $p2 . '"', $htmlTwo );
            self::assertStringContainsString( 'PatChooserOne', $htmlTwo );
            self::assertStringContainsString( 'PatChooserTwo', $htmlTwo );
        }
    }

    public function testPatientPortalPageGatesEnrollmentOptionsOnFullTwoFactorProof(): void {
        $db    = App::db();
        $stamp = $db->nowUtcSql();
        $orgId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $this->clinicA ]
        );
        self::assertGreaterThan( 0, $orgId );

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $orgId,
                'name'            => 'Portal Enroll Dest',
                'slug'            => 'portal-enroll-dest-' . uniqid(),
                'timezone'        => 'UTC',
                'created_at'      => $stamp,
                'updated_at'      => $stamp,
            ]
        );
        $destClinic = $db->wpdb_last_insert_id();
        $db->insert(
            'cpms_locations',
            [
                'clinic_id'  => $destClinic,
                'name'       => 'Portal Enroll Dest Loc',
                'slug'       => 'portal-enroll-dest-loc-' . uniqid(),
                'timezone'   => 'UTC',
                'is_primary' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]
        );

        $mob = '09128830001';
        $nid = '0060000007';
        $pat = $this->insertPatient( $this->clinicA, 'MR-ENROLL-GATE-' . uniqid(), $mob, $nid );
        $uid = $this->makeUser( 'tp_enroll_gate_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $logins = new PatientLoginRepository( $db );
        $logins->insert_pending_account( $uid, $mob, 'Enroll', 'Gate' );
        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicA,
                'patient_id'     => $pat,
                'wp_user_id'     => $uid,
                'mobile_at_link' => $mob,
                'is_primary'     => 1,
                'linked_at'      => $stamp,
            ]
        );

        $identityId = App::patient_identity_activator()->activate_for_new_patient( $this->clinicA, $pat, $mob, $uid );
        App::patient_identity_service()->link_user( $orgId, $identityId, $uid, $mob );

        $underlyingState = App::patient_enrollment_options()->for_user( $uid )['state'] ?? '';
        self::assertNotSame( PatientEnrollmentOptions::STATE_UNVERIFIED, $underlyingState );

        $proofStore                 = new PatientSessionProofStore();
        $token                      = $proofStore->issue_non_persistent_auth_cookie( $uid );
        $now                        = time();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, $now + PatientSessionProofStore::SESSION_TTL_SECONDS, 'logged_in', $token );
        wp_set_current_user( $uid );

        // 1) LEVEL_NONE: PatientPortalPage must gate enrollment_options to STATE_UNVERIFIED.
        ob_start();
        PatientPortalPage::render();
        $htmlNone = (string) ob_get_clean();
        self::assertStringContainsString( 'data-role="enrollment-section" data-state="unverified"', $htmlNone );
        self::assertStringNotContainsString( 'Portal Enroll Dest', $htmlNone );

        // 2) Mobile proof only (OTP-only, no NID binding proof): level remains LEVEL_NONE (< LEVEL_FULL) and enrollment stays STATE_UNVERIFIED.
        self::assertTrue( $proofStore->record_mobile_proof( $uid, $mob, $now, $token ) );
        self::assertTrue( $proofStore->has_valid_mobile_proof( $uid, $mob, $now + 1, $token ) );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $uid, $pat, $this->clinicA, $now + 1, $token ) );
        ob_start();
        PatientPortalPage::render();
        $htmlOtp = (string) ob_get_clean();
        self::assertStringContainsString( 'data-role="enrollment-section" data-state="unverified"', $htmlOtp );
        self::assertStringNotContainsString( 'Portal Enroll Dest', $htmlOtp );

        // 3) LEVEL_FULL (after two-factor promote): PatientPortalPage unlocks underlying PatientEnrollmentOptions.
        $this->seedOtp( $this->clinicA, $mob, '667788', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote( $uid, $this->clinicA, $mob, $nid, '667788', '198.51.108.1', $now, $token );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $uid, null, null, $now + 1, $token ) );

        ob_start();
        PatientPortalPage::render();
        $htmlFull = (string) ob_get_clean();
        self::assertStringNotContainsString( 'data-role="enrollment-section" data-state="unverified"', $htmlFull );
        self::assertStringContainsString( 'data-role="enrollment-section" data-state="' . $underlyingState . '"', $htmlFull );
    }

    public function testStaffPatientSeparationAcrossAllAuthoritySourcesAndPortalSurfaces(): void {
        $proofStore = new PatientSessionProofStore();
        $gate       = App::patient_access();

        // Patient + active Clinic-scoped membership.
        $scopedPat = $this->makeUser( 'tp_scoped_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        cpms_test_seed_membership( $scopedPat, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertFalse( PatientPortalPage::isPatientOnly( $scopedPat ), 'Scoped staff membership must fail isPatientOnly().' );
        self::assertFalse( $gate->is_pure_patient_actor( $scopedPat ) );

        // Patient + active Organization-scoped membership.
        $orgPat = $this->makeUser( 'tp_org_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $nowSql = App::db()->nowUtcSql();
        App::db()->insert(
            'cpms_organization_memberships',
            [
                'organization_id' => $this->orgId,
                'wp_user_id'      => $orgPat,
                'role_key'        => RolesAndCapabilities::ROLE_MANAGER,
                'status'          => 'active',
                'created_at'      => $nowSql,
                'updated_at'      => $nowSql,
            ]
        );
        self::assertFalse( PatientPortalPage::isPatientOnly( $orgPat ), 'Org membership must fail isPatientOnly().' );
        self::assertFalse( $gate->is_pure_patient_actor( $orgPat ) );

        // Multi-role WP user must NOT render CONTINUATION_PATIENT on [cpms_public_booking].
        $multiPat = $this->makeUser( 'tp_multi_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $u        = get_userdata( $multiPat );
        self::assertNotFalse( $u );
        $u->add_role( RolesAndCapabilities::ROLE_DOCTOR );
        self::assertFalse( PatientPortalPage::isPatientOnly( $multiPat ) );
        wp_set_current_user( $multiPat );
        $shortcodeHtml = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
        self::assertStringNotContainsString( 'cpms-public-booking__patient-continuation', $shortcodeHtml );

        // Positive control: pure patient still passes isPatientOnly().
        self::assertTrue( PatientPortalPage::isPatientOnly( $this->patientUserA ) );

        // Positive control: legitimate staff actor keeps access to staff route `GET /queue`.
        wp_set_current_user( $this->staffDocUser );
        $req = new WP_REST_Request( 'GET', '/clinic/v1/queue' );
        $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $req->set_header( 'X-CPMS-Clinic-Id', (string) $this->clinicA );
        $res = rest_do_request( $req );
        self::assertSame( 200, $res->get_status() );
        self::assertNotNull( $proofStore );
    }

    public function testNidCorrectionSeverAccountClosureAndRestoreCloseBindingsAndDestroySessions(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $now        = time();

        $this->seedOtp( $this->clinicA, self::MOBILE_A, '667788', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            self::NID_A,
            '667788',
            '198.51.100.30',
            $now,
            $token
        );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ) );

        // Staff NID correction (§7.6) closes active binding and destroys WP sessions.
        App::replaceExplicitScope( ClinicScope::forClinic( $this->clinicA, $this->locationA ) );
        App::patientService()->update( $this->patientA, [ 'national_id' => '0499370899' ], $this->staffDocUser );
        App::resetScope();

        $logins = new PatientLoginRepository( App::db() );
        self::assertNull( $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, true ) );
        $closedBinding = $logins->find_binding( $this->patientUserA, $this->clinicA, $this->patientA, false );
        self::assertIsArray( $closedBinding );
        self::assertSame( PatientLoginRepository::STATE_CLOSED, $closedBinding['state'] );
        self::assertNull( $closedBinding['live_key'] );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ) );

        // Re-prove with the corrected NID, then verify close_account preserves mobile (D-A).
        $token2 = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '778899', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            '0499370899',
            '778899',
            '198.51.100.30',
            $now,
            $token2
        );
        self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token2 ) );

        self::assertGreaterThanOrEqual( 1, $logins->recompute_keys_and_close_on_restore() );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token2 ) );

        self::assertTrue( $logins->close_account( $this->patientUserA ) );
        $closedAcct = $logins->find_account_by_user_id( $this->patientUserA );
        self::assertIsArray( $closedAcct );
        self::assertSame( PatientLoginRepository::STATE_CLOSED, $closedAcct['state'] );
        self::assertSame( self::MOBILE_A, $closedAcct['mobile'], 'D-A: closed account must preserve reserved mobile.' );
    }

    public function testDbReadFailureOnPatientLoginsFailsClosedWithWitnessAndStrictRestoration(): void {
        $proofStore = new PatientSessionProofStore();
        $token      = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $now        = time();

        $this->seedOtp( $this->clinicA, self::MOBILE_A, '889900', OtpService::PURPOSE_VERIFY_MOBILE );
        App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            self::MOBILE_A,
            self::NID_A,
            '889900',
            '198.51.100.40',
            $now,
            $token
        );

        $gate           = App::patient_access();
        $loginTable     = preg_quote( App::db()->table( 'cpms_patient_logins' ), '/' );
        $exactLookupSql = '/^\s*SELECT\s+id,\s+kind,\s+wp_user_id,\s+mobile,\s+state,\s+clinic_id,\s+patient_id,\s+verified_at,\s+verification_method,\s+live_key,\s+account_key,\s+given_name,\s+family_name,\s+created_at,\s+updated_at\s+FROM\s+' . $loginTable . '\s+WHERE\s+kind\s*=\s*\'account\'\s+AND\s+wp_user_id\s*=\s*' . $this->patientUserA . '\s+LIMIT\s+1\s*$/';
        $witnessMatches = 0;
        $witnessSeen    = [];
        $witness        = static function ( $query ) use ( $exactLookupSql, &$witnessMatches, &$witnessSeen ) {
            $sql = (string) $query;
            if ( 1 === preg_match( $exactLookupSql, $sql ) ) {
                ++$witnessMatches;
                $witnessSeen[] = $sql;
            }

            return $query;
        };

        // 1. Clean witness pass: observe-only, exact SQL hits once and grants access.
        add_filter( 'query', $witness, 5 );
        try {
            $witnessDecision = $gate->authorize_two_proof_request(
                $this->patientUserA,
                $this->clinicA,
                $this->patientA,
                null,
                PatientAccess::MODE_CLINICAL,
                false,
                $now + 5,
                $token
            );
        } finally {
            remove_filter( 'query', $witness, 5 );
        }
        self::assertSame( 1, $witnessMatches );
        self::assertCount( 1, $witnessSeen );
        self::assertTrue( $witnessDecision['allowed'] );
        self::assertSame( 200, $witnessDecision['http_status'] );

        // 2. Single fault injection pass: corrupt ONLY that exact query, assert everything outside callbacks.
        global $wpdb;
        $strictBefore = App::db()->is_strict();
        $faultMatches = 0;
        $faultSeen    = [];
        $fault        = static function ( $query ) use ( $exactLookupSql, &$faultMatches, &$faultSeen ) {
            $sql = (string) $query;
            if ( 1 === preg_match( $exactLookupSql, $sql ) ) {
                ++$faultMatches;
                $faultSeen[] = $sql;

                return 'SELECT cpms_fault_no_such_col FROM cpms_fault_nonexistent_table';
            }

            return $query;
        };

        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        try {
            $deniedDecision = $gate->authorize_two_proof_request(
                $this->patientUserA,
                $this->clinicA,
                $this->patientA,
                null,
                PatientAccess::MODE_CLINICAL,
                false,
                $now + 5,
                $token
            );
        } finally {
            remove_filter( 'query', $fault, 5 );
            $wpdb->suppress_errors( $prevSuppress );
        }

        self::assertSame( 1, $faultMatches );
        self::assertSame( $witnessSeen, $faultSeen );
        self::assertFalse( $deniedDecision['allowed'] );
        self::assertSame( 403, $deniedDecision['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $deniedDecision['code'] );
        self::assertSame( $strictBefore, App::db()->is_strict() );
    }

    public function testLinkRecordRestEndpointPromotesBindingDispatchesVerificationSmsAndRejectsQueryIdentifiers(): void {
        $proofStore     = new PatientSessionProofStore();
        $token          = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $previousCookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;

        try {
            $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->patientUserA, time() + 28800, 'logged_in', $token );
            wp_set_current_user( $this->patientUserA );
            $nonce = wp_create_nonce( 'wp_rest' );

            // Query-string identifiers must be rejected fail-closed with 403 PROOF_FAILED_CODE.
            $queryReject = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
            $queryReject->set_query_params(
                [
                    'national_id' => self::NID_A,
                    'code'        => '554433',
                    'mobile'      => self::MOBILE_A,
                ]
            );
            $queryReject->set_header( 'X-WP-Nonce', $nonce );
            $queryResp = rest_do_request( $queryReject );
            self::assertSame( 403, $queryResp->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $queryResp->get_data()['code'] ?? null );

            // Missing clinic_id in JSON body must also fail closed.
            $missingClinic = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
            $missingClinic->set_header( 'Content-Type', 'application/json' );
            $missingClinic->set_header( 'X-WP-Nonce', $nonce );
            $missingClinic->set_body(
                (string) wp_json_encode(
                    [
                        'mobile'      => self::MOBILE_A,
                        'national_id' => self::NID_A,
                        'code'        => '554433',
                    ]
                )
            );
            $missingResp = rest_do_request( $missingClinic );
            self::assertSame( 403, $missingResp->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $missingResp->get_data()['code'] ?? null );

            // Uniform failure parity on POST /patient/link-record: wrong OTP, expired OTP, wrong NID, missing NID, record without NID.
            $failUser = $this->makeUser( 'tp_link_fail_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
            ( new PatientLoginRepository( App::db() ) )->insert_pending_account( $failUser, '09128880098', 'Fail', 'Pat' );
            $this->seedOtp( $this->clinicA, self::MOBILE_A, '121212', OtpService::PURPOSE_VERIFY_MOBILE, -60 );
            $this->seedOtp( $this->clinicA, self::MOBILE_A, '343434', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
            $this->seedOtp( $this->clinicB, self::MOBILE_B, '353535', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
            $failurePayloads = [
                [
                    'user' => $this->patientUserA,
                    'body' => [ 'clinic_id' => $this->clinicA, 'mobile' => self::MOBILE_A, 'national_id' => self::NID_A, 'code' => '000000' ],
                ],
                [
                    'user' => $this->patientUserA,
                    'body' => [ 'clinic_id' => $this->clinicA, 'mobile' => self::MOBILE_A, 'national_id' => self::NID_A, 'code' => '121212' ],
                ],
                [
                    'user' => $failUser,
                    'body' => [ 'clinic_id' => $this->clinicA, 'mobile' => self::MOBILE_A, 'national_id' => '0499370899', 'code' => '343434' ],
                ],
                [
                    'user' => $failUser,
                    'body' => [ 'clinic_id' => $this->clinicA, 'mobile' => self::MOBILE_A, 'national_id' => '', 'code' => '343434' ],
                ],
                [
                    'user' => $this->patientUserB,
                    'body' => [ 'clinic_id' => $this->clinicB, 'mobile' => self::MOBILE_B, 'national_id' => self::NID_A, 'code' => '353535' ],
                ],
            ];
            $smsCountBefore = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_sms_messages' ) );
            foreach ( $failurePayloads as $case ) {
                $uid = (int) $case['user'];
                if ( $uid === $this->patientUserA ) {
                    $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->patientUserA, time() + 28800, 'logged_in', $token );
                } else {
                    $tokOther = $proofStore->issue_non_persistent_auth_cookie( $uid );
                    $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + 28800, 'logged_in', $tokOther );
                }
                wp_set_current_user( $uid );
                $failReq = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
                $failReq->set_header( 'Content-Type', 'application/json' );
                $failReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
                $failReq->set_body( (string) wp_json_encode( $case['body'] ) );
                $failResp = rest_do_request( $failReq );
                self::assertSame( 403, $failResp->get_status() );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $failResp->get_data()['code'] ?? null );
                self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $failResp->get_data()['message'] ?? null );
                self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $failResp->get_data() ) );
                self::assertStringNotContainsString( self::NID_WRONG, (string) wp_json_encode( $failResp->get_data() ) );
            }
            $smsCountAfterFail = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_sms_messages' ) );
            self::assertSame( $smsCountBefore, $smsCountAfterFail, 'Failed link-record attempts must dispatch zero verification SMS.' );

            wp_set_current_user( $this->patientUserA );
            $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->patientUserA, time() + 28800, 'logged_in', $token );
            $nonce                       = wp_create_nonce( 'wp_rest' );

            // Staff user cannot call /patient/link-record.
            wp_set_current_user( $this->staffDocUser );
            $staffReq = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
            $staffReq->set_header( 'Content-Type', 'application/json' );
            $staffReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $staffReq->set_body(
                (string) wp_json_encode(
                    [
                        'clinic_id'   => $this->clinicA,
                        'mobile'      => self::MOBILE_A,
                        'national_id' => self::NID_A,
                        'code'        => '554433',
                    ]
                )
            );
            $staffResp = rest_do_request( $staffReq );
            self::assertSame( 403, $staffResp->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $staffResp->get_data()['code'] ?? null );

            // Valid two-factor promotion through POST /clinic/v1/patient/link-record.
            wp_set_current_user( $this->patientUserA );
            $this->seedOtp( $this->clinicA, self::MOBILE_A, '554433', OtpService::PURPOSE_VERIFY_MOBILE );

            $okReq = new WP_REST_Request( 'POST', '/clinic/v1/patient/link-record' );
            $okReq->set_header( 'Content-Type', 'application/json' );
            $okReq->set_header( 'X-WP-Nonce', $nonce );
            $okReq->set_body(
                (string) wp_json_encode(
                    [
                        'clinic_id'   => $this->clinicA,
                        'mobile'      => self::MOBILE_A,
                        'national_id' => self::NID_A,
                        'code'        => '554433',
                    ]
                )
            );
            $okResp = rest_do_request( $okReq );
            self::assertSame( 200, $okResp->get_status(), (string) wp_json_encode( $okResp->get_data() ) );
            self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $okResp->get_data() ) );
            $payload = $okResp->get_data()['data'] ?? [];
            self::assertSame( $this->patientA, (int) ( $payload['patient_id'] ?? 0 ) );
            self::assertSame( $this->clinicA, (int) ( $payload['clinic_id'] ?? 0 ) );
            self::assertSame( PatientAccess::LEVEL_FULL, App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, time(), $token ) );

            // Immediate follow-up GET /patient/me succeeds with the stamped session proof.
            $meReq = new WP_REST_Request( 'GET', '/clinic/v1/patient/me' );
            $meReq->set_header( 'X-WP-Nonce', $nonce );
            $meResp = rest_do_request( $meReq );
            self::assertSame( 200, $meResp->get_status(), (string) wp_json_encode( $meResp->get_data() ) );
            self::assertSame( $this->patientA, (int) ( $meResp->get_data()['data']['id'] ?? 0 ) );
            self::assertArrayNotHasKey( 'national_id', $meResp->get_data()['data'] ?? [], 'P2: /patient/me must never project national_id.' );
            self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $meResp->get_data() ) );
            $auditLogs = App::db()->fetchAll( 'SELECT action, meta_json FROM ' . App::db()->table( 'cpms_audit_logs' ) );
            self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $auditLogs ) );

            // Verification notice SMS (PATIENT_BINDING_VERIFIED) was queued/recorded to the exact same mobile with zero NID.
            $smsRow = App::db()->fetchRow(
                'SELECT event, recipient, context_type, context_id, clinic_id, message, vars_json FROM ' . App::db()->table( 'cpms_sms_messages' )
                . ' WHERE clinic_id = %d AND event = %s AND context_id = %d ORDER BY id DESC LIMIT 1',
                [ $this->clinicA, SmsEvents::PATIENT_BINDING_VERIFIED, $this->patientA ]
            );
            self::assertIsArray( $smsRow );
            self::assertSame( SmsEvents::PATIENT_BINDING_VERIFIED, (string) $smsRow['event'] );
            self::assertSame( self::MOBILE_A, (string) $smsRow['recipient'] );
            self::assertSame( 'patient_binding', (string) $smsRow['context_type'] );
            self::assertStringNotContainsString( self::NID_A, (string) ( $smsRow['message'] ?? '' ) );
            self::assertStringNotContainsString( self::NID_A, (string) ( $smsRow['vars_json'] ?? '' ) );

            $opLogs = App::db()->fetchAll( 'SELECT message, context_json FROM ' . App::db()->table( 'cpms_operational_logs' ) );
            self::assertStringNotContainsString( self::NID_A, (string) wp_json_encode( $opLogs ) );
        } finally {
            if ( null === $previousCookie ) {
                unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            } else {
                $_COOKIE[ LOGGED_IN_COOKIE ] = $previousCookie;
            }
        }
    }

    public function testExpiredOtpCrossMobileOtpAndSharedMobileIsolation(): void {
        $proofStore = new PatientSessionProofStore();
        $tokenA     = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserA );
        $tokenB     = $proofStore->issue_non_persistent_auth_cookie( $this->patientUserB );
        $now        = time();

        // 1) Expired OTP fails closed with uniform 403 and leaves no binding.
        $this->seedOtp( $this->clinicA, self::MOBILE_A, '135790', OtpService::PURPOSE_VERIFY_MOBILE, -30 );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '135790',
                '198.51.100.50',
                $now,
                $tokenA
            );
            self::fail( 'Expired OTP must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }

        // 2) OTP issued for another mobile (MOBILE_B) fails closed when promoting MOBILE_A.
        $this->seedOtp( $this->clinicA, self::MOBILE_B, '246810', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                self::MOBILE_A,
                self::NID_A,
                '246810',
                '198.51.100.51',
                $now,
                $tokenA
            );
            self::fail( 'OTP issued for another mobile must fail closed.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }

        // 3) Shared mobile across two patients in clinicA and clinicB: NID + clinic scope selects only the matching patient.
        $sharedMobile   = '09127770099';
        $sharedNid1     = '0499370899';
        $sharedNid2     = '0079948278';
        $sharedPatient1 = $this->insertPatient( $this->clinicA, 'MR-SH-1-' . uniqid(), $sharedMobile, $sharedNid1 );
        $sharedPatient2 = $this->insertPatient( $this->clinicB, 'MR-SH-2-' . uniqid(), $sharedMobile, $sharedNid2 );

        // Wrong NID (or other clinic's NID) on shared mobile in clinicA binds neither record.
        $this->seedOtp( $this->clinicA, $sharedMobile, '717171', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserA,
                $this->clinicA,
                $sharedMobile,
                $sharedNid2,
                '717171',
                '198.51.100.52',
                $now,
                $tokenA
            );
            self::fail( 'Mismatched NID on shared mobile must bind neither patient.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
        }

        // Valid OTP + sharedNid1 in clinicA binds ONLY sharedPatient1, never sharedPatient2.
        $this->seedOtp( $this->clinicA, $sharedMobile, '727272', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
        $res = App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            $sharedMobile,
            $sharedNid1,
            '727272',
            '198.51.100.52',
            $now,
            $tokenA
        );
        self::assertSame( $sharedPatient1, $res['patient_id'] );
        self::assertSame( [ $sharedPatient1 ], App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 5, $tokenA ) );
        self::assertSame( [], App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicB, $now + 5, $tokenA ) );
        self::assertSame( PatientAccess::LEVEL_NONE, App::patient_access()->level( $this->patientUserA, $sharedPatient2, $this->clinicB, $now + 5, $tokenA ) );

        // Competing account B cannot hijack sharedPatient1 in clinicA with the shared mobile + sharedNid1.
        $this->seedOtp( $this->clinicA, $sharedMobile, '737373', OtpService::PURPOSE_VERIFY_MOBILE, 180 );
        try {
            App::patient_binding_promoter()->promote(
                $this->patientUserB,
                $this->clinicA,
                $sharedMobile,
                $sharedNid1,
                '737373',
                '198.51.100.53',
                $now,
                $tokenB
            );
            self::fail( 'Competing account must not bind already-bound shared-mobile record.' );
        } catch ( BookingException $e ) {
            self::assertSame( 403, $e->httpStatus );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $e->errorCode );
        }
    }

    public function testMixedPatientDoctorAccountIsRejectedOnPortalLogin(): void {
        $db  = App::db();
        $now = $db->nowUtcSql();

        // Pure doctor with active clinician + membership is authorized for doctor & staff portals.
        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicA,
                'full_name'  => 'Dr Pure',
                'wp_user_id' => $this->staffDocUser,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        self::assertTrue( PortalLogin::is_authorized( $this->staffDocUser, 'doctor' ) );
        self::assertTrue( PortalLogin::is_authorized( $this->staffDocUser, 'staff' ) );

        // Pure patient must be rejected on both portals.
        self::assertFalse( PortalLogin::is_authorized( $this->patientUserA, 'doctor' ), 'Pure patient must be rejected on doctor portal login.' );
        self::assertFalse( PortalLogin::is_authorized( $this->patientUserA, 'staff' ), 'Pure patient must be rejected on staff portal login.' );

        // Mixed-role user holding BOTH cpms_doctor AND cpms_patient must be rejected on PortalLogin.
        $mixedUser = $this->makeUser( 'tp_mixed_doc_' . uniqid(), RolesAndCapabilities::ROLE_DOCTOR );
        $userObj   = get_userdata( $mixedUser );
        self::assertNotFalse( $userObj );
        $userObj->add_role( RolesAndCapabilities::ROLE_PATIENT );
        cpms_test_seed_membership( $mixedUser, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicA,
                'full_name'  => 'Dr Mixed',
                'wp_user_id' => $mixedUser,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        self::assertFalse( PortalLogin::is_authorized( $mixedUser, 'doctor' ), 'Mixed patient+doctor account must be rejected on doctor portal login.' );
        self::assertFalse( PortalLogin::is_authorized( $mixedUser, 'staff' ), 'Mixed patient+doctor account must be rejected on staff portal login.' );
        self::assertFalse( PatientPortalPage::isPatientOnly( $mixedUser ), 'Mixed patient+doctor account must also be rejected on patient-only portal.' );

        // Positive controls across staff authority sources on PortalLogin:
        // 1) WP secretary role + Clinic membership.
        $wpSec = $this->makeUser( 'tp_wp_sec_' . uniqid(), RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $wpSec, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertTrue( PortalLogin::is_authorized( $wpSec, 'staff' ) );

        // 2) Subscriber with built-in secretary Clinic membership.
        $subSec = $this->makeUser( 'tp_sub_sec_' . uniqid(), 'subscriber' );
        cpms_test_seed_membership( $subSec, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertTrue( PortalLogin::is_authorized( $subSec, 'staff' ) );

        // 3) Subscriber with active Clinic custom role granting REPORT_READ.
        $subCustom = $this->makeUser( 'tp_sub_custom_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_tp_portal_rep', [ RolesAndCapabilities::REPORT_READ ] );
        App::membership_service()->create_membership( $this->clinicA, $subCustom, 'cx_tp_portal_rep' );
        self::assertTrue( PortalLogin::is_authorized( $subCustom, 'staff' ) );

        // 4) Subscriber with explicit capability grant of REPORT_READ.
        $subGrant = $this->makeUser( 'tp_sub_grant_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_tp_portal_empty', [] );
        $grantMid = App::membership_service()->create_membership( $this->clinicA, $subGrant, 'cx_tp_portal_empty' );
        App::membership_service()->set_capability( $grantMid, RolesAndCapabilities::REPORT_READ, 'grant' );
        self::assertTrue( PortalLogin::is_authorized( $subGrant, 'staff' ) );

        // 5) Subscriber with active clinician + Clinic membership on 'doctor' portal.
        $subDoc = $this->makeUser( 'tp_sub_doc_' . uniqid(), 'subscriber' );
        cpms_test_seed_membership( $subDoc, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        $db->insert(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicA,
                'full_name'  => 'Dr Subscriber',
                'wp_user_id' => $subDoc,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        self::assertTrue( PortalLogin::is_authorized( $subDoc, 'doctor' ) );
    }

    private function insertPatient( int $clinicId, string $mrn, string $mobile, ?string $nationalId ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patients',
            [
                'clinic_id'   => $clinicId,
                'mrn'         => $mrn,
                'first_name'  => 'Two',
                'last_name'   => 'Proof',
                'mobile'      => $mobile,
                'national_id' => $nationalId,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    /**
     * @param list<string> $capabilities
     */
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

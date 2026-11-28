<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * Gaps for T-L29 and T-L31 of docs/architecture/patient-identity-login-design.md
 * (patient-identity family; the rows in the §9 table that describe the 14-day sweep's
 * neighbours, not the license-activation family).
 *
 * Only the legs that are BOTH uncovered and deterministic are tested here. The
 * destroy-versus-update race is a WordPress limitation the design records rather than fixes,
 * so it has no test; the 60-minute absolute window and post-destroy READS are already
 * asserted elsewhere and are not re-asserted as if they were new.
 *
 * No PHP runs in the authoring sandbox: every behavioural statement below is either read
 * from the cited production source or observed in CI. Nothing here is claimed as executed
 * locally.
 */
final class PatientTwoProofGapsTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_A     = '09128881002';
    private const MOBILE_SH1   = '09128881001';
    private const MOBILE_SH2   = '09128881003';
    private const NID_A        = '0010000123';
    private const NID_SH1      = '0010000107';
    private const NID_SH2      = '0010000115';

    private int $orgId;
    private int $clinicA;
    private int $patientA;
    private int $patientShared1;
    private int $patientShared2;
    private int $patientUserA;

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

        $this->patientA       = $this->insertPatient( 'MR-GAP-A-' . uniqid(), self::MOBILE_A, self::NID_A );
        $this->patientShared1 = $this->insertPatient( 'MR-GAP-S1-' . uniqid(), self::MOBILE_SH1, self::NID_SH1 );
        $this->patientShared2 = $this->insertPatient( 'MR-GAP-S2-' . uniqid(), self::MOBILE_SH2, self::NID_SH2 );

        $this->patientUserA = $this->makeUser( 'gap_pat_a_' . uniqid() );

        $logins = new PatientLoginRepository( $db );
        $logins->insert_pending_account( $this->patientUserA, self::MOBILE_A, 'GapA', 'Test' );

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

    /**
     * T-L31 (uncovered leg): a proof WRITE refuses once the token is gone, and the refused
     * write leaves no session behind.
     *
     * WHAT THIS TEST ACTUALLY EXERCISES (OBSERVED by reading the store): both writers hit the
     * INITIAL live-session guard, `PatientSessionProofStore::get_live_session()`, which returns
     * null for a missing token — `record_mobile_proof()` at :77-80 and `record_binding_proof()`
     * at :128-131. The SECOND, pre-mutation re-read (:92-95 / :146-148) is NOT reached here and
     * is not deterministically testable without an injection seam, so this test makes no claim
     * about it. The design's "re-reads before writing" sentence describes that second branch,
     * not this one.
     *
     * The `level()` call at the end uses a fixture with an ACTIVE account and an ACTIVE durable
     * binding, so it really does reach the proof store (:443) and return LEVEL_NONE at :458 —
     * see the separate test below, where the same fixture also proves LEVEL_FULL positively.
     */
    public function testProofWriteFailsClosedOnceTheSessionTokenIsGone(): void {
        $store = new PatientSessionProofStore();
        $now   = time();

        // A real durable account + binding + identity edge, so the gate can reach the store.
        $seeded = $this->seedActiveAccountAndBinding();

        // POSITIVE CONTROLS, asserted BEFORE any destruction: `destroy_all_sessions()` below
        // ends every session of this user, so a control after it could only see refusals.
        $liveToken = $store->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $liveToken, 'a live session token was issued' );
        self::assertTrue(
            $store->record_mobile_proof( $this->patientUserA, self::MOBILE_A, $now, $liveToken ),
            'control: a live session accepts the mobile proof write'
        );
        self::assertTrue(
            $store->record_binding_proof( $this->patientUserA, $this->clinicA, $this->patientA, (int) $seeded['binding_id'], (string) $seeded['verified_at'], $now, $liveToken ),
            'control: a live session accepts the binding proof write'
        );
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 1, $liveToken ),
            'control: the proved live session really reaches the proof store and returns LEVEL_FULL'
        );

        $liveSessionsBefore = array_keys( WP_Session_Tokens::get_instance( $this->patientUserA )->get_all() );
        self::assertContains( $liveToken, $liveSessionsBefore, 'precondition: the live token is in the store' );

        // Second session, destroyed, then the same writes are attempted through it.
        $deadToken = $store->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $deadToken, 'the second session token was issued' );
        self::assertNotSame( $liveToken, $deadToken, 'the two sessions have distinct tokens' );

        $store->destroy_all_sessions( $this->patientUserA );

        self::assertNull(
            WP_Session_Tokens::get_instance( $this->patientUserA )->get( $deadToken ),
            'precondition: the token is really gone from the session store'
        );
        self::assertFalse(
            $store->record_mobile_proof( $this->patientUserA, self::MOBILE_A, $now, $deadToken ),
            'T-L31: the mobile-proof write fails closed when the token is gone'
        );
        self::assertFalse(
            $store->record_binding_proof( $this->patientUserA, $this->clinicA, $this->patientA, (int) $seeded['binding_id'], (string) $seeded['verified_at'], $now, $deadToken ),
            'T-L31: the binding-proof write fails closed when the token is gone'
        );

        // The refused writes must not have RESURRECTED the session or stored a proof in it.
        self::assertNull(
            WP_Session_Tokens::get_instance( $this->patientUserA )->get( $deadToken ),
            'after the refused writes the destroyed token is still absent'
        );
        self::assertSame(
            [],
            WP_Session_Tokens::get_instance( $this->patientUserA )->get_all(),
            'after destroy_all_sessions and two refused writes, the user has no sessions at all'
        );

        // ... and the gate gives that session nothing.
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 2, $deadToken ),
            'T-L31: no level is granted on the destroyed session'
        );
    }

    /**
     * T-L31 (the leg the shallow version could not reach): `level()` on a LIVE session with
     * NO proofs written on it is LEVEL_NONE, and the SAME fixture becomes LEVEL_FULL once the
     * real store writes both proofs on that same token.
     *
     * This is the leg that actually exercises the proof store through the gate: the fixture has
     * an ACTIVE account and an ACTIVE two_factor binding, so `level()` passes the early
     * `[] === $bindings` return (`PatientAccess.php:429-430`) and calls
     * `has_valid_binding_proof()` at :443. Without that durable binding the gate would return
     * LEVEL_NONE at :430 for ANY token, live or dead — which is exactly the short-circuit the
     * earlier version of this file suffered from.
     */
    public function testLiveSessionWithoutProofsIsNoneAndWithProofsIsFull(): void {
        $store  = new PatientSessionProofStore();
        $now    = time();
        $seeded = $this->seedActiveAccountAndBinding();

        // A brand-new live session: durable binding exists, but no proof has been written.
        $token = $store->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $token, 'a live session token was issued' );
        self::assertSame(
            0,
            $this->proofCount( $token ),
            'precondition: the fresh session carries no proof entries'
        );

        // Denial class (a): the proof gate, reached because the binding exists.
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 1, $token ),
            'T-L31: a live session with no proofs gets LEVEL_NONE although the binding is durable'
        );

        // POSITIVE CONTROL on the SAME fixture and the SAME token: write both proofs, get FULL.
        self::assertTrue(
            $store->record_mobile_proof( $this->patientUserA, self::MOBILE_A, $now + 2, $token ),
            'control: the mobile proof is written on this live session'
        );
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 3, $token ),
            'control: a mobile proof alone is still not enough for LEVEL_FULL'
        );
        self::assertTrue(
            $store->record_binding_proof( $this->patientUserA, $this->clinicA, $this->patientA, (int) $seeded['binding_id'], (string) $seeded['verified_at'], $now + 2, $token ),
            'control: the binding proof is written on this live session'
        );
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 3, $token ),
            'control: both proofs on the same token give LEVEL_FULL'
        );
    }

    /**
     * T-L29 at the gate: record B of the SAME clinic shares record A's Organization identity
     * but has no binding of its own, so a session that proved A proves nothing about B.
     */
    public function testSharedIdentityGrantsNoAccessToTheOtherRecord(): void {
        $db  = App::db();
        $now = time();

        // Record A proved through the REAL promoter (matching NID + OTP), not a helper shortcut.
        $token      = $this->promoteRecord( $this->patientA, self::MOBILE_A, self::NID_A );
        $identA     = $this->identityIdOf( $this->patientA );
        self::assertGreaterThan( 0, $identA, 'record A carries an Organization identity' );

        // Record B must really SHARE that identity for this test to be about shared identity.
        $this->shareIdentity( $this->patientShared1, $identA );
        self::assertSame(
            $identA,
            $this->identityIdOf( $this->patientShared1 ),
            'fixture: record B shares record A identity in the same Organization'
        );

        // POSITIVE CONTROL: record A is fully proved at the gate on this very token.
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 5, $token ),
            'control: the bound record reaches LEVEL_FULL'
        );
        self::assertSame(
            [ $this->patientA ],
            App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 5, $token ),
            'control: exactly the bound record is proved'
        );

        // T-L29: the shared identity grants nothing on record B.
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientShared1, $this->clinicA, $now + 5, $token ),
            'T-L29: sharing record A identity grants no level on record B'
        );
        self::assertNotContains(
            $this->patientShared1,
            App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 5, $token ),
            'T-L29: the proved set never widens to the shared-identity record'
        );

        // P3 ASSERTED DIRECTLY on the predicate-backed repository method.
        $repo = new PatientRepository( $db );
        $linkA = $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d AND patient_id = %d LIMIT 1',
            [ $this->patientUserA, $this->patientA ]
        );
        self::assertNotNull( $linkA, 'fixture: record A has a user link row' );
        $resolvedA = $repo->find_active_linked_patient_by_link( $this->patientUserA, (int) $linkA );
        self::assertNotNull( $resolvedA, 'P3: the predicate-backed resolver resolves the PROVED record' );
        self::assertSame( $this->patientA, (int) $resolvedA['id'], 'P3: it resolves the expected record' );

        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicA,
                'patient_id'     => $this->patientShared1,
                'wp_user_id'     => $this->patientUserA,
                'mobile_at_link' => self::MOBILE_SH1,
                'is_primary'     => 0,
                'linked_at'      => $db->nowUtcSql(),
            ]
        );
        $linkB = (int) $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $linkB, 'fixture: record B has a user link row' );
        self::assertNull(
            $repo->find_active_linked_patient_by_link( $this->patientUserA, $linkB ),
            'P3: the predicate-backed resolver does NOT resolve the shared-identity record without its own binding'
        );

        // Record B becomes reachable only after ITS OWN two proofs, through the real promoter.
        $tokenB = $this->promoteRecord( $this->patientShared1, self::MOBILE_SH1, self::NID_SH1 );
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            App::patient_access()->level( $this->patientUserA, $this->patientShared1, $this->clinicA, $now + 6, $tokenB ),
            'T-L29: record B is reachable only after its own proofs'
        );
        self::assertSame(
            $identA,
            $this->identityIdOf( $this->patientShared1 ),
            'T-L29: proving record B did not change the shared identity'
        );
    }

    /**
     * T-L29 at the REST boundary. TWO labelled sub-cases, because the HTTP status and error
     * code are IDENTICAL for both gates (403 + PROOF_FAILED_CODE) and only the observer
     * reason distinguishes them:
     *
     *   CASE R - the link RESOLVES through the P3 predicate, but the session holds no proof
     *            for that record, so the LEVEL check refuses ('two_proof_required',
     *            PatientAccess.php:696).
     *   CASE U - the shared-identity record has NO active two_factor binding of its own, so
     *            the P3 predicate's binding term (PatientRepository.php:422-438) makes the link
     *            UNRESOLVABLE and the LINK RESOLVER refuses ('link_not_found',
     *            PatientAccess.php:629). Note the identity term is USER-scoped
     *            (PatientRepository.php:453: `pil.wp_user_id = l.wp_user_id`), so record B
     *            INHERITS A's identity link - which is precisely the safeguard under test:
     *            sharing identity_id still makes a record unreachable until that record has its
     *            own verification.
     *
     * The reason is captured through the `decision_observer` constructor seam of
     * `PatientAccess` (:159, consumed by `observe_decision()`), and each sub-case first
     * asserts the precondition on `find_active_linked_patient_by_link()` directly, so the
     * denial class is PROVEN rather than inferred from the shared 403.
     */
    public function testHttpRefusalNamesTheGateForResolvableAndUnresolvableRecords(): void {
        $db  = App::db();
        $now = time();

        $token  = $this->promoteRecord( $this->patientA, self::MOBILE_A, self::NID_A );
        $identA = $this->identityIdOf( $this->patientA );
        $this->shareIdentity( $this->patientShared1, $identA );

        $repo = new PatientRepository( $db );

        // ---- CASE R: resolvable link, refusal must come from the LEVEL check ----
        // Give record B the two durable edges the P3 predicate needs (identity-user link +
        // active binding) but NOT a session proof, so resolution succeeds and the gate can
        // only refuse at the level check.
        $this->makeRecordResolvableWithoutSessionProof( $this->patientShared1, self::MOBILE_SH1 );

        $linkR = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d AND patient_id = %d LIMIT 1',
            [ $this->patientUserA, $this->patientShared1 ]
        );
        self::assertGreaterThan( 0, $linkR, 'CASE R fixture: record B has a link row' );
        self::assertNotNull(
            $repo->find_active_linked_patient_by_link( $this->patientUserA, $linkR ),
            'CASE R precondition: the link really RESOLVES through the P3 predicate'
        );

        $reasonsR = [];
        $gateR    = new PatientAccess(
            $db,
            new PatientRepository( $db ),
            logins: new PatientLoginRepository( $db ),
            proofs: new PatientSessionProofStore(),
            decision_observer: static function ( array $d ) use ( &$reasonsR ): void {
                $reasonsR[] = (string) $d['reason'];
            }
        );

        $decisionR = $gateR->authorize_two_proof_request( $this->patientUserA, null, null, $linkR, PatientAccess::MODE_CLINICAL, false, $now + 5, $token );

        self::assertFalse( $decisionR['allowed'], 'CASE R: the request is refused' );
        self::assertSame( 403, $decisionR['http_status'], 'CASE R: refusal is 403' );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $decisionR['code'], 'CASE R: refusal carries the proof code' );
        self::assertSame(
            'two_proof_required',
            (string) $decisionR['reason'],
            'CASE R: the LEVEL check refuses (PatientAccess.php:696), not the link resolver'
        );
        self::assertSame( [ 'two_proof_required' ], $reasonsR, 'CASE R: the observer saw exactly that one reason' );
        self::assertNotSame(
            'link_not_found',
            (string) $decisionR['reason'],
            'CASE R: the resolver did NOT refuse - this is what separates it from CASE U'
        );

        // Positive control for CASE R on the SAME resolvable link: a session holding that
        // record's own proofs is allowed.
        $tokenB = $this->promoteRecord( $this->patientShared1, self::MOBILE_SH1, self::NID_SH1 );
        $allowedR = $gateR->authorize_two_proof_request( $this->patientUserA, null, null, $linkR, PatientAccess::MODE_CLINICAL, false, $now + 6, $tokenB );
        self::assertTrue( $allowedR['allowed'], 'CASE R control: same link is allowed once that record is proved in the session' );

        // ---- CASE U: unresolvable link, refusal must come from the LINK RESOLVER ----
        $identU = $this->identityIdOf( $this->patientA );
        $this->shareIdentity( $this->patientShared2, $identU );
        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicA,
                'patient_id'     => $this->patientShared2,
                'wp_user_id'     => $this->patientUserA,
                'mobile_at_link' => self::MOBILE_SH2,
                'is_primary'     => 0,
                'linked_at'      => $db->nowUtcSql(),
            ]
        );
        $linkU = (int) $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $linkU, 'CASE U fixture: record has a link row' );
        self::assertNull(
            $repo->find_active_linked_patient_by_link( $this->patientUserA, $linkU ),
            'CASE U precondition: the P3 predicate does NOT resolve the shared-identity record'
        );

        $reasonsU = [];
        $gateU    = new PatientAccess(
            $db,
            new PatientRepository( $db ),
            logins: new PatientLoginRepository( $db ),
            proofs: new PatientSessionProofStore(),
            decision_observer: static function ( array $d ) use ( &$reasonsU ): void {
                $reasonsU[] = (string) $d['reason'];
            }
        );

        $decisionU = $gateU->authorize_two_proof_request( $this->patientUserA, null, null, $linkU, PatientAccess::MODE_CLINICAL, false, $now + 5, $token );

        self::assertFalse( $decisionU['allowed'], 'CASE U: the request is refused' );
        self::assertSame( 403, $decisionU['http_status'], 'CASE U: refusal is 403, same as CASE R' );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $decisionU['code'], 'CASE U: same code as CASE R' );
        self::assertSame(
            'link_not_found',
            (string) $decisionU['reason'],
            'CASE U: the LINK RESOLVER refuses (PatientAccess.php:629), not the level check'
        );
        self::assertSame( [ 'link_not_found' ], $reasonsU, 'CASE U: the observer saw exactly that one reason' );

        // Positive control on the SAME gate and token: record A's own link resolves and is
        // served, so the refusal above is about that record, not a broken gate.
        $linkA = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d AND patient_id = %d LIMIT 1',
            [ $this->patientUserA, $this->patientA ]
        );
        $okU = $gateU->authorize_two_proof_request( $this->patientUserA, null, null, $linkA, PatientAccess::MODE_CLINICAL, false, $now + 5, $token );
        self::assertTrue( $okU['allowed'], 'CASE U control: record A link is allowed on the same gate and token' );
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Activates the account and creates a real ACTIVE durable binding for record A, so the
     * gate cannot short-circuit at the `[] === $bindings` return.
     *
     * @return array{binding_id: int, verified_at: string}
     */
    private function seedActiveAccountAndBinding(): array {
        $logins = new PatientLoginRepository( App::db() );
        $account = $logins->find_account_by_user_id( $this->patientUserA );
        if ( null !== $account && PatientLoginRepository::STATE_PENDING === (string) ( $account['state'] ?? '' ) ) {
            $logins->activate_account_from_pending( (int) $account['id'], $this->patientUserA );
        }
        $account = $logins->find_account_by_user_id( $this->patientUserA );
        self::assertNotNull( $account, 'fixture: the account exists' );
        self::assertSame(
            PatientLoginRepository::STATE_ACTIVE,
            (string) $account['state'],
            'fixture: the account is ACTIVE so level() does not stop at the state check'
        );

        $db          = App::db();
        $verified_at = $db->nowUtcSql();
        $binding     = $logins->upsert_active_binding( $this->patientUserA, $this->clinicA, $this->patientA, $verified_at );
        self::assertGreaterThan( 0, (int) $binding['id'], 'fixture: an active binding exists' );

        // Identity edges: without them level() would return LEVEL_BOOKING, not LEVEL_FULL.
        $identity = App::patient_identity_activator()->activate_for_new_patient(
            $this->clinicA,
            $this->patientA,
            self::MOBILE_A,
            $this->patientUserA
        );
        self::assertGreaterThan( 0, $identity, 'fixture: record A has an Organization identity' );
        if ( ! ( new PatientIdentityRepository( $db ) )->user_link_exists( $identity, $this->patientUserA ) ) {
            App::patient_identity_service()->link_user( $this->orgId, $identity, $this->patientUserA, self::MOBILE_A );
        }
        self::assertTrue(
            ( new PatientIdentityRepository( $db ) )->user_link_exists( $identity, $this->patientUserA ),
            'fixture: the identity <-> user edge exists, so LEVEL_FULL is reachable'
        );

        // Clinic patient <-> WP user edge. `has_full_identity_edges()` (PatientAccess.php:1060-1081)
        // INNER JOINs `cpms_patient_user_links`, so without this row `level()` proves the binding
        // but stops at LEVEL_BOOKING (:474) and never returns LEVEL_FULL (:470). Same shape the
        // production promoter writes (PatientBindingPromoter::ensure_patient_user_link()).
        if ( 0 === (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d AND patient_id = %d LIMIT 1',
            [ $this->patientUserA, $this->patientA ]
        ) ) {
            self::assertTrue(
                $db->insert(
                    'cpms_patient_user_links',
                    [
                        'clinic_id'      => $this->clinicA,
                        'patient_id'     => $this->patientA,
                        'wp_user_id'     => $this->patientUserA,
                        'mobile_at_link' => self::MOBILE_A,
                        'is_primary'     => 0,
                        'linked_at'      => $db->nowUtcSql(),
                    ]
                ),
                'fixture: the clinic patient <-> user link was inserted'
            );
        }

        return [
            'binding_id'  => (int) $binding['id'],
            'verified_at' => (string) $binding['verified_at'],
        ];
    }

    /**
     * Drives the REAL promoter (OTP + matching NID) for a record and returns its live token.
     */
    private function promoteRecord( int $patientId, string $mobile, string $nid ): string {
        $store = new PatientSessionProofStore();
        $token = $store->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $token, 'fixture: a live session token was issued for the promotion' );

        $this->seedOtp( $mobile, '424242' );

        $promoted = App::patient_binding_promoter()->promote(
            $this->patientUserA,
            $this->clinicA,
            $mobile,
            $nid,
            '424242',
            '198.51.100.90',
            time(),
            $token
        );
        self::assertSame( $patientId, (int) $promoted['patient_id'], 'fixture: the promoter bound the intended record' );

        return $token;
    }

    /**
     * Gives record B the durable edges the P3 predicate needs, WITHOUT a session proof, so a
     * request for it reaches the LEVEL check (Case R).
     */
    private function makeRecordResolvableWithoutSessionProof( int $patientId, string $mobile ): void {
        $db  = App::db();
        $logins = new PatientLoginRepository( $db );
        $now = $db->nowUtcSql();

        $logins->upsert_active_binding( $this->patientUserA, $this->clinicA, $patientId, $now );

        $identity = App::patient_identity_activator()->activate_for_new_patient(
            $this->clinicA,
            $patientId,
            $mobile,
            $this->patientUserA
        );
        self::assertGreaterThan( 0, $identity, 'fixture: record B has an Organization identity' );
        if ( ! ( new PatientIdentityRepository( $db ) )->user_link_exists( $identity, $this->patientUserA ) ) {
            App::patient_identity_service()->link_user( $this->orgId, $identity, $this->patientUserA, $mobile );
        }

        if ( 0 === (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d AND patient_id = %d LIMIT 1',
            [ $this->patientUserA, $patientId ]
        ) ) {
            $db->insert(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $this->clinicA,
                    'patient_id'     => $patientId,
                    'wp_user_id'     => $this->patientUserA,
                    'mobile_at_link' => $mobile,
                    'is_primary'     => 0,
                    'linked_at'      => $now,
                ]
            );
        }
    }

    /**
     * Makes a record share another record's Organization identity through the REAL production
     * edge writer - `PatientIdentityService::link_clinical_record()`, the single audited
     * identity boundary - never a raw UPDATE. The resulting equality is then ASSERTED as a
     * fixture precondition, so "shared identity" below is a measured fact, not an assumption.
     */
    private function shareIdentity( int $patientId, int $identityId ): void {
        App::patient_identity_service()->link_clinical_record( $this->orgId, $identityId, $this->clinicA, $patientId );
        self::assertSame(
            $identityId,
            $this->identityIdOf( $patientId ),
            'fixture precondition: the record really carries the shared identity_id'
        );
    }

    private function identityIdOf( int $patientId ): int {
        return (int) App::db()->fetchValue(
            'SELECT identity_id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
            [ $patientId ]
        );
    }

    /** Counts the proof entries stored in a session, so "no proof" is asserted, not assumed. */
    private function proofCount( string $token ): int {
        $session = WP_Session_Tokens::get_instance( $this->patientUserA )->get( $token );
        if ( ! is_array( $session ) ) {
            return -1;
        }
        $count = 0;
        foreach ( [ PatientSessionProofStore::KEY_MOBILE_PROOFS, PatientSessionProofStore::KEY_BINDING_PROOFS ] as $key ) {
            if ( isset( $session[ $key ] ) && is_array( $session[ $key ] ) ) {
                $count += count( $session[ $key ] );
            }
        }

        return $count;
    }

    private function seedOtp( string $mobile, string $code ): void {
        $pepper = defined( 'CPMS_PEPPER' ) && '' !== (string) CPMS_PEPPER
            ? (string) CPMS_PEPPER
            : (string) get_option( 'cpms_otp_pepper', '' );
        if ( '' === $pepper ) {
            $pepper = bin2hex( random_bytes( 32 ) );
            update_option( 'cpms_otp_pepper', $pepper, 'no' );
        }

        self::assertTrue(
            App::db()->insert(
                'cpms_otp_tokens',
                [
                    'clinic_id'  => $this->clinicA,
                    'mobile'     => $mobile,
                    'purpose'    => OtpService::PURPOSE_VERIFY_MOBILE,
                    'code_hash'  => \ClinicCore\Domain\Otp\OtpPolicy::hashCode( $code, $pepper ),
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 180 ) . '.000',
                    'attempts'   => 0,
                    'created_at' => App::db()->nowUtcSql(),
                ]
            ),
            'fixture: OTP row seeded for ' . $mobile
        );
    }

    private function insertPatient( string $mrn, string $mobile, string $nationalId ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_patients',
                [
                    'clinic_id'   => $this->clinicA,
                    'mrn'         => $mrn,
                    'first_name'  => 'Gap',
                    'last_name'   => 'Proof',
                    'mobile'      => $mobile,
                    'national_id' => $nationalId,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            ),
            'fixture: patient row inserted (' . $mrn . ')'
        );
        $id = (int) $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $id, 'fixture: patient id readable (' . $mrn . ')' );

        return $id;
    }

    private function makeUser( string $login ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId, 'fixture: the patient user was created' );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user, 'fixture: the user object is readable' );
        $user->set_role( RolesAndCapabilities::ROLE_PATIENT );

        return $userId;
    }
}

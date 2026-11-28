<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * Gaps for T-L29 and T-L31 of docs/architecture/patient-identity-login-design.md
 * (patient-identity family; rows near :417 and :419).
 *
 * Only the legs that are BOTH uncovered and deterministic are tested here. Legs that
 * document a WordPress limitation (the destroy-vs-update race) or that an existing test
 * already asserts (the 60-minute absolute window, post-destroy reads, cross-token reads)
 * are recorded in the agent log instead of being re-asserted as if they were new.
 */
final class PatientTwoProofGapsTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const MOBILE_SHARED = '09128881001';
    private const MOBILE_SH2    = '09128881003';
    private const MOBILE_A      = '09128881002';
    private const NID_SHARED_1  = '0010000107';
    private const NID_SHARED_2  = '0010000115';
    private const NID_A         = '0010000123';

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
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

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'TwoProof Gaps Clinic B',
                'slug'            => 'twoproof-gaps-b-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB = $db->wpdb_last_insert_id();

        $this->patientA       = $this->insertPatient( $this->clinicA, 'MR-GAP-A-' . uniqid(), self::MOBILE_A, self::NID_A );
        $this->patientShared1 = $this->insertPatient( $this->clinicA, 'MR-GAP-S1-' . uniqid(), self::MOBILE_SHARED, self::NID_SHARED_1 );
        $this->patientShared2 = $this->insertPatient( $this->clinicA, 'MR-GAP-S2-' . uniqid(), self::MOBILE_SH2, self::NID_SHARED_2 );

        $this->patientUserA = $this->makeUser( 'gap_pat_a_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );

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
     * T-L31 (uncovered leg): a proof WRITE must fail closed once the token is gone.
     *
     * §2.7 requires that "before writing, the store re-reads the session and must find the
     * same token still present; if it is gone the write fails closed (no proof)". The existing
     * unit test destroys the session and then asserts a READ (`has_valid_binding_proof`)
     * returns false, which is a different branch: the read fails at `get_live_session()` before
     * the write path is ever entered. This test drives the WRITE path itself.
     */
    public function testProofWriteFailsClosedOnceTheSessionTokenIsGone(): void {
        $store = new PatientSessionProofStore();
        $now   = time();

        // POSITIVE CONTROL: with a live session the write succeeds and the proof reads back.
        // It is asserted BEFORE any destruction: `destroy_all_sessions()` below ends every
        // session of this user, so a control placed after it could only ever see refusals.
        $liveToken = $store->issue_non_persistent_auth_cookie( $this->patientUserA );
        self::assertNotSame( '', $liveToken, 'a live session token was issued' );
        self::assertTrue(
            $store->record_mobile_proof( $this->patientUserA, self::MOBILE_A, $now, $liveToken ),
            'control: a live session accepts the mobile proof write'
        );
        self::assertTrue(
            $store->has_valid_mobile_proof( $this->patientUserA, self::MOBILE_A, $now + 1, $liveToken ),
            'control: the written mobile proof reads back inside the window'
        );

        // Second session: destroy it, then attempt the same writes through it.
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
            'T-L31: the write fails closed when the token is gone (no proof is created)'
        );
        self::assertFalse(
            $store->record_binding_proof( $this->patientUserA, $this->clinicA, $this->patientA, 1, '2026-11-28 00:00:00.000', $now, $deadToken ),
            'T-L31: the binding-proof write fails closed too'
        );

        // No proof survived the refused writes, so the gate stays at `none` for that session.
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientA, $this->clinicA, $now + 2, $deadToken ),
            'T-L31: no level is granted on the destroyed session after the refused write'
        );
    }

    /**
     * T-L29 (uncovered leg): two records of ONE clinic sharing one identity, only record A bound.
     *
     * Record B must stay unproved even though it carries the SAME `identity_id` as record A.
     * This is the P3 claim: an identity edge grants nothing by itself; the binding is decisive.
     * The existing shared-mobile test uses two DIFFERENT clinics and two different identities,
     * so it never exercises a shared identity inside one clinic.
     */
    public function testSharedIdentityGrantsNoAccessToTheOtherRecord(): void {
        $db = App::db();

        // Record A is proved; record B is a second record of the SAME clinic that will share
        // record A's Organization identity without any binding of its own.
        $proof = PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserA,
            $this->clinicA,
            $this->patientA,
            [ 'mobile' => self::MOBILE_A ]
        );
        $token   = (string) $proof['token'];
        $now     = time();
        $identId = (int) $db->fetchValue(
            'SELECT identity_id FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
            [ $this->patientA ]
        );
        self::assertGreaterThan( 0, $identId, 'record A carries an Organization identity' );

        // `activate_for_new_patient()` reuses the caller's single Organization identity
        // (`resolve_verified_identity()`, exactly-one rule), so record B legitimately ends up
        // pointing at the SAME identity as record A — the condition T-L29 names.
        $identB = (int) App::patient_identity_activator()->activate_for_new_patient(
            $this->clinicA,
            $this->patientShared1,
            self::MOBILE_SHARED,
            $this->patientUserA
        );
        self::assertSame( $identId, $identB, 'fixture: both records share ONE Organization identity' );
        self::assertSame(
            $identId,
            (int) $db->fetchValue(
                'SELECT identity_id FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
                [ $this->patientShared1 ]
            ),
            'fixture: record B persisted the shared identity'
        );
        self::assertNull(
            $db->fetchValue(
                'SELECT identity_id FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
                [ $this->patientShared2 ]
            ),
            'the untouched third record carries no identity at all'
        );

        // POSITIVE CONTROL: record A is fully proved at the gate.
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

        // T-L29: neither unbound record is proved, and the shared identity changes nothing.
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientShared1, $this->clinicA, $now + 5, $token ),
            'T-L29: sharing record A identity grants no level on the unbound record'
        );
        self::assertSame(
            PatientAccess::LEVEL_NONE,
            App::patient_access()->level( $this->patientUserA, $this->patientShared2, $this->clinicA, $now + 5, $token ),
            'T-L29: the second unbound record stays none too'
        );
        self::assertNotContains(
            $this->patientShared1,
            App::patient_access()->proved_patient_ids( $this->patientUserA, $this->clinicA, $now + 5, $token ),
            'T-L29: the proved set never widens to the shared-identity record'
        );

        // Record B becomes proved only by its OWN two proofs (matching NID), never by identity.
        $proofB = PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserA,
            $this->clinicA,
            $this->patientShared2,
            [ 'mobile' => self::MOBILE_SH2 ]
        );
        self::assertSame(
            PatientAccess::LEVEL_FULL,
            App::patient_access()->level( $this->patientUserA, $this->patientShared2, $this->clinicA, $now + 6, (string) $proofB['token'] ),
            'T-L29: the second record is reachable only after its own proof'
        );
    }

    /**
     * T-L29 at the HTTP boundary: `GET /patient/me?link_id=<record B>` must be refused,
     * and the refusal must carry no NID.
     *
     * The safeguard is the same proof check reached through the REST layer; the existing
     * shared-mobile test never dispatches a request at the second record's link.
     */
    public function testHttpRequestForTheOtherRecordIsRefusedWithoutIdentityData(): void {
        $db  = App::db();
        $now = time();

        $proof = PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserA,
            $this->clinicA,
            $this->patientA,
            [ 'mobile' => self::MOBILE_A ]
        );
        $token = (string) $proof['token'];

        // A link row exists for record B (so a refusal cannot be "link not found" noise).
        self::assertTrue(
            $db->insert(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $this->clinicA,
                    'patient_id'     => $this->patientShared1,
                    'wp_user_id'     => $this->patientUserA,
                    'mobile_at_link' => self::MOBILE_SHARED,
                    'is_primary'     => 0,
                    'linked_at'      => $db->nowUtcSql(),
                ]
            ),
            'fixture: a link row exists for the unproved record'
        );
        $linkB = (int) $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $linkB, 'fixture: the link row id is readable' );

        // POSITIVE CONTROL: the proved record answers with a body and no NID, on its own token
        // (the one that carries record A's binding proof and nothing else).
        $okResponse = $this->dispatchMe( 0, (string) $proof['token'], $now + 5 );
        $okStatus   = $okResponse->get_status();
        $okBody     = (string) wp_json_encode( $okResponse->get_data() );
        self::assertContains( $okStatus, [ 200 ], 'control: the proved record is served' );
        $this->assertNoNationalId( $okBody, 'the served body' );

        // T-L29/T-L30 boundary: the unproved record is refused.
        $denied = $this->dispatchMe( (int) $linkB, $token, $now + 5 );
        $status = $denied->get_status();
        $data   = $denied->get_data();
        $code   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
        $body   = (string) wp_json_encode( $data );

        // DENIAL CLASS (a) — the target proof gate. `resolve_link_target()` resolves the link
        // (it exists, same clinic) and `authorize_two_proof_request()` then refuses at the level
        // check with `proof_failed_decision()`, which is always 403 + PROOF_FAILED_CODE.
        // The nonce is valid, so the nonce gate cannot be the cause.
        self::assertSame( 403, $status, 'the unproved record is refused by the proof gate' );
        self::assertSame(
            PatientAccess::PROOF_FAILED_CODE,
            $code,
            'the refusal is the two-proof gate, not the link resolver'
        );
        self::assertStringNotContainsString( self::NID_SHARED_1, $body, 'no national ID leaks in the refusal' );
        self::assertStringNotContainsString( self::NID_A, $body, 'no other national ID leaks either' );
        foreach ( [ $this->patientShared1, $this->patientA ] as $recordId ) {
            self::assertStringNotContainsString(
                'MR-GAP-',
                $body,
                'the refusal carries no MRN of record ' . $recordId
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    private function dispatchMe( int $linkId, string $token, int $now ): \WP_REST_Response {
        PatientTwoProofTestHelper::acting_as( $this->patientUserA );
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie(
            $this->patientUserA,
            $now + PatientSessionProofStore::SESSION_TTL_SECONDS,
            'logged_in',
            $token
        );
        App::patient_session_scope()->set_in_process_session_token( $this->patientUserA, $token );

        $request = new WP_REST_Request( 'GET', '/clinic/v1/patient/me' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        if ( $linkId > 0 ) {
            $request->set_param( 'link_id', $linkId );
        }

        return rest_do_request( $request );
    }

    private function assertNoNationalId( string $body, string $where ): void {
        foreach ( [ self::NID_A, self::NID_SHARED_1, self::NID_SHARED_2 ] as $nid ) {
            self::assertStringNotContainsString( $nid, $body, $where . ' carries no national ID' );
            self::assertStringNotContainsString( 'national_id', $body, $where . ' exposes no national_id field' );
        }
    }

    private function insertPatient( int $clinicId, string $mrn, string $mobile, ?string $nationalId ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_patients',
                [
                    'clinic_id'   => $clinicId,
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

    /**
     * @param list<string> $capabilities
     */
    private function makeUser( string $login, string $role ): int {
        return (int) self::factory()->user->create(
            [
                'role'       => $role,
                'user_login' => $login,
            ]
        );
    }
}

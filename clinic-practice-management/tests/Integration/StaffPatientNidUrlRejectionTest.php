<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Integration tests for PR-NID-STAFF-URL (R1, R2, R3):
 *
 *  - R1: POST /clinic/v1/staff/portal/reception/patients
 *  - R2: POST /clinic/v1/patients
 *  - R3: EDITABLE (POST, PUT, PATCH) /clinic/v1/patients/{id}
 *
 * Proves:
 *  - T1..T3: query-only `national_id` is rejected with 403 PatientAccess::PROOF_FAILED_CODE,
 *            zero patient/audit/identity writes, and no NID value echoed in response/error.
 *  - T4..T6: `national_id` in BOTH query and body is rejected identically (403, zero writes).
 *  - T7..T9: body-only `national_id` (JSON and form body) succeeds identically to baseline.
 *  - T10:    every write method registered on R3 enforces the guard (non-vacuous loop).
 *  - T11:    unauthenticated & unauthorised callers with `national_id` in query receive the
 *            exact same status and code as without it (permission_callback runs first).
 */
final class StaffPatientNidUrlRejectionTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const R1_ROUTE = '/clinic/v1/staff/portal/reception/patients';
    private const R2_ROUTE = '/clinic/v1/patients';
    private const R3_BASE  = '/clinic/v1/patients/';

    /** Checksum-valid synthetic 10-digit national IDs used across existing test fixtures. */
    private const NID_VALID = '0499370899';
    private const NID_ALT   = '0013542419';
    private const NID_INIT  = '0010000003';
    private const NID_M1    = '0080000002';
    private const NID_M2    = '0012345673';

    private int $clinicId;
    private int $locationId;
    private int $secretaryId;
    private int $unauthorisedUserId;
    private int $noMembershipSecretaryId;
    private int $existingPatientId;

    protected function setUp(): void {
        parent::setUp();
        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();

        $this->installSignedExpiration( time() + 30 * 86400 );

        $orgId                         = $this->insertOrg( 'NID Staff URL Org' );
        $this->clinicId                = $this->insertClinic( 'NID Staff URL Clinic', $orgId );
        $this->locationId              = $this->insertLocation( $this->clinicId, 'NID Staff URL Loc', 1 );
        $this->secretaryId             = $this->makeUser( 'nid_staff_sec', RolesAndCapabilities::ROLE_SECRETARY );
        $this->unauthorisedUserId      = $this->makeUser( 'nid_staff_sub', 'subscriber' );
        $this->noMembershipSecretaryId = $this->makeUser( 'nid_staff_nomem', RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $this->secretaryId, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $this->existingPatientId = $this->insertPatient(
            $this->clinicId,
            'BeforeFirst',
            'BeforeLast',
            '09123330001',
            self::NID_INIT,
            'MR-NIDURL-' . uniqid()
        );

        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
    }

    protected function tearDown(): void {
        PatientTwoProofTestHelper::reset();
        remove_all_filters( 'cpms_license_public_key' );
        $_GET                    = [];
        $_POST                   = [];
        $_REQUEST                = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =========================================================================
    // T1..T3 — Query-only national_id rejected with 403 & zero DB side effects
    // =========================================================================

    public function testT1_R1ReceptionCreateRejectsQueryOnlyNidWith403AndNoWrite(): void {
        $patientsBefore   = $this->countPatients( $this->clinicId );
        $auditsBefore     = $this->countAudits();
        $identitiesBefore = $this->countIdentities();

        $resp = $this->dispatchStaffRequest(
            'POST',
            self::R1_ROUTE,
            [ 'national_id' => self::NID_VALID ],
            [
                'first_name' => 'T1Query',
                'last_name'  => 'Only',
                'mobile'     => '09123331001',
            ],
            $this->scopeHeaders( $this->clinicId, $this->locationId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T1 R1 query-only NID' );
        self::assertSame( $patientsBefore, $this->countPatients( $this->clinicId ), 'T1: no patient created' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T1: no audit row written' );
        self::assertSame( $identitiesBefore, $this->countIdentities(), 'T1: no identity row created' );
    }

    public function testT2_R2PatientsCreateRejectsQueryOnlyNidWith403AndNoWrite(): void {
        $patientsBefore   = $this->countPatients( $this->clinicId );
        $auditsBefore     = $this->countAudits();
        $identitiesBefore = $this->countIdentities();

        $resp = $this->dispatchStaffRequest(
            'POST',
            self::R2_ROUTE,
            [ 'national_id' => self::NID_VALID ],
            [
                'first_name' => 'T2Query',
                'last_name'  => 'Only',
                'mobile'     => '09123331002',
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T2 R2 query-only NID' );
        self::assertSame( $patientsBefore, $this->countPatients( $this->clinicId ), 'T2: no patient created' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T2: no audit row written' );
        self::assertSame( $identitiesBefore, $this->countIdentities(), 'T2: no identity row created' );
    }

    public function testT3_R3PatientsUpdateRejectsQueryOnlyNidWith403AndNoWrite(): void {
        $rowBefore      = $this->patientRow( $this->existingPatientId );
        $patientsBefore = $this->countPatients( $this->clinicId );
        $auditsBefore   = $this->countAudits();

        $resp = $this->dispatchStaffRequest(
            'PUT',
            self::R3_BASE . $this->existingPatientId,
            [ 'national_id' => self::NID_VALID ],
            [ 'first_name' => 'AfterT3Attempt' ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T3 R3 query-only NID' );
        self::assertSame( $patientsBefore, $this->countPatients( $this->clinicId ), 'T3: patient count unchanged' );
        self::assertSame( $rowBefore, $this->patientRow( $this->existingPatientId ), 'T3: patient row unchanged' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T3: no audit row written' );
    }

    // =========================================================================
    // T4..T6 — Query AND Body national_id rejected with 403 & zero DB side effects
    // =========================================================================

    public function testT4_R1ReceptionCreateRejectsNidInBothQueryAndBody(): void {
        $patientsBefore = $this->countPatients( $this->clinicId );
        $auditsBefore   = $this->countAudits();

        $resp = $this->dispatchStaffRequest(
            'POST',
            self::R1_ROUTE,
            [ 'national_id' => self::NID_VALID ],
            [
                'first_name'  => 'T4Both',
                'last_name'   => 'Rejected',
                'mobile'      => '09123331004',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId, $this->locationId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T4 R1 query+body NID' );
        self::assertSame( $patientsBefore, $this->countPatients( $this->clinicId ), 'T4: no patient created' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T4: no audit row written' );
    }

    public function testT5_R2PatientsCreateRejectsNidInBothQueryAndBody(): void {
        $patientsBefore = $this->countPatients( $this->clinicId );
        $auditsBefore   = $this->countAudits();

        $resp = $this->dispatchStaffRequest(
            'POST',
            self::R2_ROUTE,
            [ 'national_id' => self::NID_VALID ],
            [
                'first_name'  => 'T5Both',
                'last_name'   => 'Rejected',
                'mobile'      => '09123331005',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T5 R2 query+body NID' );
        self::assertSame( $patientsBefore, $this->countPatients( $this->clinicId ), 'T5: no patient created' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T5: no audit row written' );
    }

    public function testT6_R3PatientsUpdateRejectsNidInBothQueryAndBody(): void {
        $rowBefore    = $this->patientRow( $this->existingPatientId );
        $auditsBefore = $this->countAudits();

        $resp = $this->dispatchStaffRequest(
            'PUT',
            self::R3_BASE . $this->existingPatientId,
            [ 'national_id' => self::NID_VALID ],
            [
                'first_name'  => 'AfterT6Attempt',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId
        );

        $this->assertQueryNidRefused403( $resp, self::NID_VALID, 'T6 R3 query+body NID' );
        self::assertSame( $rowBefore, $this->patientRow( $this->existingPatientId ), 'T6: patient row unchanged' );
        self::assertSame( $auditsBefore, $this->countAudits(), 'T6: no audit row written' );
    }

    // =========================================================================
    // T7..T9 — CONTROL: Body-only national_id (JSON and form) succeeds cleanly
    // =========================================================================

    public function testT7_R1ReceptionCreateControlBodyOnlyNidSucceeds(): void {
        $jsonResp = $this->dispatchStaffRequest(
            'POST',
            self::R1_ROUTE,
            [],
            [
                'first_name'  => 'T7Json',
                'last_name'   => 'Control',
                'mobile'      => '09123331007',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId, $this->locationId ),
            $this->secretaryId,
            true,
            true
        );

        self::assertSame( 200, $jsonResp->get_status(), 'T7 JSON body-only control must succeed' );
        $jsonData = $jsonResp->get_data()['data'] ?? [];
        self::assertIsArray( $jsonData );
        $jsonPatientId = (int) ( $jsonData['id'] ?? 0 );
        self::assertGreaterThan( 0, $jsonPatientId );
        self::assertSame( self::NID_VALID, (string) ( $this->patientRow( $jsonPatientId )['national_id'] ?? '' ) );
        self::assertArrayNotHasKey( 'national_id', $jsonData );
        $this->assertNidLeakFree( $jsonResp->get_data(), self::NID_VALID, 'T7 JSON response' );

        $formResp = $this->dispatchStaffRequest(
            'POST',
            self::R1_ROUTE,
            [],
            [
                'first_name'  => 'T7Form',
                'last_name'   => 'Control',
                'mobile'      => '09123331017',
                'national_id' => self::NID_ALT,
            ],
            $this->scopeHeaders( $this->clinicId, $this->locationId ),
            $this->secretaryId,
            true,
            false
        );

        self::assertSame( 200, $formResp->get_status(), 'T7 form body-only control must succeed' );
        $formData      = $formResp->get_data()['data'] ?? [];
        $formPatientId = (int) ( $formData['id'] ?? 0 );
        self::assertGreaterThan( 0, $formPatientId );
        self::assertSame( self::NID_ALT, (string) ( $this->patientRow( $formPatientId )['national_id'] ?? '' ) );
        $this->assertNidLeakFree( $formResp->get_data(), self::NID_ALT, 'T7 form response' );
    }

    public function testT8_R2PatientsCreateControlBodyOnlyNidSucceeds(): void {
        $jsonResp = $this->dispatchStaffRequest(
            'POST',
            self::R2_ROUTE,
            [],
            [
                'first_name'  => 'T8Json',
                'last_name'   => 'Control',
                'mobile'      => '09123331008',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId,
            true,
            true
        );

        self::assertSame( 200, $jsonResp->get_status(), 'T8 JSON body-only control must succeed' );
        $jsonData      = $jsonResp->get_data()['data'] ?? [];
        $jsonPatientId = (int) ( $jsonData['id'] ?? 0 );
        self::assertGreaterThan( 0, $jsonPatientId );
        self::assertSame( self::NID_VALID, (string) ( $this->patientRow( $jsonPatientId )['national_id'] ?? '' ) );
        self::assertArrayNotHasKey( 'national_id', $jsonData );
        $this->assertNidLeakFree( $jsonResp->get_data(), self::NID_VALID, 'T8 JSON response' );

        $formResp = $this->dispatchStaffRequest(
            'POST',
            self::R2_ROUTE,
            [],
            [
                'first_name'  => 'T8Form',
                'last_name'   => 'Control',
                'mobile'      => '09123331018',
                'national_id' => self::NID_ALT,
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId,
            true,
            false
        );

        self::assertSame( 200, $formResp->get_status(), 'T8 form body-only control must succeed' );
        $formData      = $formResp->get_data()['data'] ?? [];
        $formPatientId = (int) ( $formData['id'] ?? 0 );
        self::assertGreaterThan( 0, $formPatientId );
        self::assertSame( self::NID_ALT, (string) ( $this->patientRow( $formPatientId )['national_id'] ?? '' ) );
        $this->assertNidLeakFree( $formResp->get_data(), self::NID_ALT, 'T8 form response' );
    }

    public function testT9_R3PatientsUpdateControlBodyOnlyNidSucceeds(): void {
        $resp = $this->dispatchStaffRequest(
            'PUT',
            self::R3_BASE . $this->existingPatientId,
            [],
            [
                'first_name'  => 'T9Updated',
                'national_id' => self::NID_VALID,
            ],
            $this->scopeHeaders( $this->clinicId ),
            $this->secretaryId,
            true,
            true
        );

        self::assertSame( 200, $resp->get_status(), 'T9 body-only update control must succeed' );
        $data = $resp->get_data()['data'] ?? [];
        self::assertSame( 'T9Updated', (string) ( $data['first_name'] ?? '' ) );
        self::assertArrayNotHasKey( 'national_id', $data );
        self::assertSame( self::NID_VALID, (string) ( $this->patientRow( $this->existingPatientId )['national_id'] ?? '' ) );
        $this->assertNidLeakFree( $resp->get_data(), self::NID_VALID, 'T9 response' );
    }

    // =========================================================================
    // T10 — R3 loops over EVERY registered write method (POST, PUT, PATCH)
    // =========================================================================

    public function testT10_R3AllRegisteredWriteMethodsRejectQueryNidAndAllowBodyControl(): void {
        $routes   = rest_get_server()->get_routes();
        $handlers = $routes['/clinic/v1/patients/(?P<id>\\d+)'] ?? [];
        self::assertNotEmpty( $handlers, 'T10: R3 route must be registered on WP_REST_Server' );

        $writeMethods = [];
        foreach ( $handlers as $handler ) {
            $methods = is_array( $handler['methods'] ?? null ) ? $handler['methods'] : [];
            foreach ( $methods as $method => $enabled ) {
                if ( $enabled && ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
                    $writeMethods[] = (string) $method;
                }
            }
        }
        $writeMethods = array_values( array_unique( $writeMethods ) );
        sort( $writeMethods );
        self::assertSame( [ 'PATCH', 'POST', 'PUT' ], $writeMethods, 'T10: R3 must register all EDITABLE write methods' );

        $controlNids = [
            'PATCH' => self::NID_VALID,
            'POST'  => self::NID_ALT,
            'PUT'   => self::NID_M1,
        ];

        foreach ( $writeMethods as $method ) {
            $rowBefore    = $this->patientRow( $this->existingPatientId );
            $auditsBefore = $this->countAudits();

            // 1) Query-only NID rejected.
            $qResp = $this->dispatchStaffRequest(
                $method,
                self::R3_BASE . $this->existingPatientId,
                [ 'national_id' => self::NID_M2 ],
                [ 'first_name' => 'Blocked_' . $method ],
                $this->scopeHeaders( $this->clinicId ),
                $this->secretaryId
            );
            $this->assertQueryNidRefused403( $qResp, self::NID_M2, 'T10 ' . $method . ' query-only' );
            self::assertSame( $rowBefore, $this->patientRow( $this->existingPatientId ), 'T10 ' . $method . ' query-only: row unchanged' );
            self::assertSame( $auditsBefore, $this->countAudits(), 'T10 ' . $method . ' query-only: audit unchanged' );

            // 2) Query + Body NID rejected.
            $bResp = $this->dispatchStaffRequest(
                $method,
                self::R3_BASE . $this->existingPatientId,
                [ 'national_id' => self::NID_M2 ],
                [ 'first_name' => 'BlockedBoth_' . $method, 'national_id' => self::NID_M2 ],
                $this->scopeHeaders( $this->clinicId ),
                $this->secretaryId
            );
            $this->assertQueryNidRefused403( $bResp, self::NID_M2, 'T10 ' . $method . ' query+body' );
            self::assertSame( $rowBefore, $this->patientRow( $this->existingPatientId ), 'T10 ' . $method . ' query+body: row unchanged' );
            self::assertSame( $auditsBefore, $this->countAudits(), 'T10 ' . $method . ' query+body: audit unchanged' );

            // 3) Control: body-only NID succeeds for this method.
            $targetNid = $controlNids[ $method ];
            $cResp     = $this->dispatchStaffRequest(
                $method,
                self::R3_BASE . $this->existingPatientId,
                [],
                [ 'first_name' => 'Ok_' . $method, 'national_id' => $targetNid ],
                $this->scopeHeaders( $this->clinicId ),
                $this->secretaryId
            );
            self::assertSame( 200, $cResp->get_status(), 'T10 ' . $method . ' body-only control must succeed' );
            self::assertSame( $targetNid, (string) ( $this->patientRow( $this->existingPatientId )['national_id'] ?? '' ), 'T10 ' . $method . ' updated NID' );
            $this->assertNidLeakFree( $cResp->get_data(), $targetNid, 'T10 ' . $method . ' response' );
        }
    }

    // =========================================================================
    // T11 — Unauthenticated & unauthorised callers get identical answer
    //       with vs without query NID (permission_callback runs first)
    // =========================================================================

    public function testT11_UnauthenticatedAndUnauthorisedCallersWithQueryNidMatchNoNidError(): void {
        $routes = [
            'R1' => [
                'method'  => 'POST',
                'route'   => self::R1_ROUTE,
                'body'    => [ 'first_name' => 'Anon', 'last_name' => 'Test', 'mobile' => '09123331011' ],
                'headers' => $this->scopeHeaders( $this->clinicId, $this->locationId ),
            ],
            'R2' => [
                'method'  => 'POST',
                'route'   => self::R2_ROUTE,
                'body'    => [ 'first_name' => 'Anon', 'last_name' => 'Test', 'mobile' => '09123331012' ],
                'headers' => $this->scopeHeaders( $this->clinicId ),
            ],
            'R3' => [
                'method'  => 'PUT',
                'route'   => self::R3_BASE . $this->existingPatientId,
                'body'    => [ 'first_name' => 'AnonEdit' ],
                'headers' => $this->scopeHeaders( $this->clinicId ),
            ],
        ];

        foreach ( $routes as $label => $cfg ) {
            // (a) Unauthenticated (no user, no nonce).
            $anonBaseline = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [],
                $cfg['body'],
                $cfg['headers'],
                0,
                false
            );
            $anonWithNid = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [ 'national_id' => self::NID_VALID ],
                $cfg['body'],
                $cfg['headers'],
                0,
                false
            );
            self::assertSame( $anonBaseline->get_status(), $anonWithNid->get_status(), 'T11 ' . $label . ' anon status parity' );
            self::assertSame( $this->errCode( $anonBaseline ), $this->errCode( $anonWithNid ), 'T11 ' . $label . ' anon code parity' );
            self::assertNotSame( PatientAccess::PROOF_FAILED_CODE, $this->errCode( $anonWithNid ), 'T11 ' . $label . ' anon must not reach NID gate' );

            // (b) Authenticated unauthorised caller (subscriber with valid nonce, no staff cap).
            $subBaseline = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [],
                $cfg['body'],
                $cfg['headers'],
                $this->unauthorisedUserId,
                true
            );
            $subWithNid = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [ 'national_id' => self::NID_VALID ],
                $cfg['body'],
                $cfg['headers'],
                $this->unauthorisedUserId,
                true
            );
            self::assertSame( 403, $subBaseline->get_status(), 'T11 ' . $label . ' unauthorised baseline 403' );
            self::assertSame( $subBaseline->get_status(), $subWithNid->get_status(), 'T11 ' . $label . ' unauthorised status parity' );
            self::assertSame( $this->errCode( $subBaseline ), $this->errCode( $subWithNid ), 'T11 ' . $label . ' unauthorised code parity' );
            self::assertNotSame( PatientAccess::PROOF_FAILED_CODE, $this->errCode( $subWithNid ), 'T11 ' . $label . ' unauthorised must not reach NID gate' );
            $this->assertNidLeakFree( $subWithNid->get_data(), self::NID_VALID, 'T11 ' . $label . ' unauthorised response' );

            // (c) Authenticated ROLE_SECRETARY without active Clinic membership (proves scoped authorization runs before NID gate on R2/R3 too).
            $noMemBaseline = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [],
                $cfg['body'],
                $cfg['headers'],
                $this->noMembershipSecretaryId,
                true
            );
            $noMemWithNid = $this->dispatchStaffRequest(
                $cfg['method'],
                $cfg['route'],
                [ 'national_id' => self::NID_VALID ],
                $cfg['body'],
                $cfg['headers'],
                $this->noMembershipSecretaryId,
                true
            );
            self::assertSame( 403, $noMemBaseline->get_status(), 'T11 ' . $label . ' no-membership secretary baseline 403' );
            self::assertSame( $noMemBaseline->get_status(), $noMemWithNid->get_status(), 'T11 ' . $label . ' no-membership status parity' );
            self::assertSame( $this->errCode( $noMemBaseline ), $this->errCode( $noMemWithNid ), 'T11 ' . $label . ' no-membership code parity' );
            self::assertNotSame( PatientAccess::PROOF_FAILED_CODE, $this->errCode( $noMemWithNid ), 'T11 ' . $label . ' no-membership must not reach NID gate' );
            $this->assertNidLeakFree( $noMemWithNid->get_data(), self::NID_VALID, 'T11 ' . $label . ' no-membership response' );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertQueryNidRefused403( WP_REST_Response $resp, string $nid, string $label ): void {
        self::assertSame( 403, $resp->get_status(), $label . ': expected HTTP 403' );
        $data = $resp->get_data();
        self::assertIsArray( $data, $label . ': error payload must be array' );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, (string) ( $data['code'] ?? '' ), $label . ': reference error code' );
        self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, (string) ( $data['message'] ?? '' ), $label . ': reference error message' );
        $this->assertNidLeakFree( $data, $nid, $label );
    }

    private function assertNidLeakFree( mixed $payload, string $nid, string $label ): void {
        $encoded = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $digits  = (string) preg_replace( '/\D+/', '', $nid );
        self::assertStringNotContainsString( $nid, $encoded, $label . ': raw NID leaked' );
        if ( '' !== $digits ) {
            self::assertStringNotContainsString( $digits, $encoded, $label . ': digits-only NID leaked' );
        }
    }

    private function errCode( WP_REST_Response $resp ): string {
        $data = $resp->get_data();
        if ( $data instanceof WP_Error ) {
            return (string) $data->get_error_code();
        }

        return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
    }

    /**
     * @param array<string, mixed>  $queryParams
     * @param array<string, mixed>  $bodyParams
     * @param array<string, string> $headers
     */
    private function dispatchStaffRequest(
        string $method,
        string $route,
        array $queryParams,
        array $bodyParams,
        array $headers,
        int $userId,
        bool $withNonce = true,
        bool $jsonBody = true
    ): WP_REST_Response {
        ScopeContext::clear();
        App::resetScope();

        if ( $userId > 0 ) {
            PatientTwoProofTestHelper::acting_as( $userId );
        } else {
            wp_set_current_user( 0 );
        }

        $request = new WP_REST_Request( $method, $route );
        if ( [] !== $queryParams ) {
            $request->set_query_params( $queryParams );
        }
        if ( [] !== $bodyParams ) {
            if ( $jsonBody ) {
                $request->set_header( 'Content-Type', 'application/json' );
                $request->set_body( (string) wp_json_encode( $bodyParams ) );
            } else {
                $request->set_body_params( $bodyParams );
            }
        }
        if ( $withNonce ) {
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }
        foreach ( $headers as $k => $v ) {
            $request->set_header( $k, $v );
        }

        return rest_do_request( $request );
    }

    /**
     * @return array<string, string>
     */
    private function scopeHeaders( int $clinicId, ?int $locationId = null ): array {
        $headers = [ 'X-CPMS-Clinic-Id' => (string) $clinicId ];
        if ( null !== $locationId ) {
            $headers['X-CPMS-Location-Id'] = (string) $locationId;
        }

        return $headers;
    }

    private function countPatients( int $clinicId ): int {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d',
            [ $clinicId ]
        );
    }

    private function countAudits(): int {
        return (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) );
    }

    private function countIdentities(): int {
        return (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_identities' ) );
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow( int $patientId ): array {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
            [ $patientId ]
        );

        return is_array( $row ) ? $row : [];
    }

    private function makeUser( string $prefix, string $role ): int {
        $login = $prefix . '_' . bin2hex( random_bytes( 3 ) );
        $id    = (int) wp_create_user( $login, 'Pass!R7StaffUrl9', $login . '@test.local' );
        self::assertGreaterThan( 0, $id );
        $user = get_userdata( $id );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $id;
    }

    private function insertOrg( string $name ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
                $name,
                'org-' . bin2hex( random_bytes( 3 ) ),
                'active',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertClinic( string $name, int $orgId ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $orgId,
                $name,
                'cl-' . bin2hex( random_bytes( 3 ) ),
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );
        App::resetScope();

        return $id;
    }

    private function insertLocation( int $clinicId, string $name, int $primary ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)',
                $clinicId,
                $name,
                'loc-' . bin2hex( random_bytes( 3 ) ),
                'Asia/Tehran',
                $primary,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient( int $clinicId, string $first, string $last, string $mobile, string $nationalId, string $mrn ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id'   => $clinicId,
                'mrn'         => $mrn,
                'first_name'  => $first,
                'last_name'   => $last,
                'mobile'      => $mobile,
                'national_id' => $nationalId,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        self::assertNotFalse( $ok, 'patient fixture insert: ' . $wpdb->last_error );

        return (int) $wpdb->insert_id;
    }
}

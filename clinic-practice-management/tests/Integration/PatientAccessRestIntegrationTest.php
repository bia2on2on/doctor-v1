<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST integration tests for the central PatientAccess gate wired into
 * RestBase and PatientController.
 */
final class PatientAccessRestIntegrationTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const NS = '/clinic/v1';

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
    private int $patientA;
    private int $patientB;
    private int $linkA;
    private int $linkB;
    private int $patientUserA;
    private int $patientUserB;
    private int $patientUserZero;
    private int $secretaryUser;
    private int $staffWithDbLinkOnly;
    private int $patientWithScopedMembership;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->orgId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ 1 ]
        );
        self::assertGreaterThan( 0, $this->orgId );

        $this->clinicA = 1;
        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'Gate Clinic B',
                'slug'            => 'gate-clinic-b-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $this->clinicB );

        $this->patientA = $this->insertPatient( $this->clinicA, 'MR-GATE-A-' . uniqid(), 'Alice', 'ClinicA', '09120010001' );
        $this->patientB = $this->insertPatient( $this->clinicB, 'MR-GATE-B-' . uniqid(), 'Bob', 'ClinicB', '09120020002' );

        $this->patientUserA         = $this->makeUser( 'gate_pat_a_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserB         = $this->makeUser( 'gate_pat_b_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserZero      = $this->makeUser( 'gate_pat_z_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->secretaryUser              = $this->makeUser( 'gate_sec_' . uniqid(), RolesAndCapabilities::ROLE_SECRETARY );
        $this->staffWithDbLinkOnly        = $this->makeUser( 'gate_sec_link_' . uniqid(), RolesAndCapabilities::ROLE_SECRETARY );
        $this->patientWithScopedMembership = $this->makeUser( 'gate_pat_scoped_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );

        cpms_test_seed_membership( $this->secretaryUser, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $this->staffWithDbLinkOnly, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $this->patientWithScopedMembership, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY );

        $this->linkA = $this->insertPatientLink( $this->clinicA, $this->patientA, $this->patientUserA, '09120010001' );
        $this->linkB = $this->insertPatientLink( $this->clinicB, $this->patientB, $this->patientUserB, '09120020002' );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->patientWithScopedMembership, '09120010001' );
        // Even if a staff-only user has a row in cpms_patient_user_links, missing ROLE_PATIENT still fails closed.
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->staffWithDbLinkOnly, '09120010001' );

        // Verified org identity for PatientUserA so enroll positive control into Clinic B works when desired.
        $identityA = App::patient_identity_service()->create_identity( $this->orgId, '09120010001' );
        App::patient_identity_service()->link_user( $this->orgId, $identityA, $this->patientUserA, '09120010001' );

        $this->installSignedExpiration( time() + 30 * 86400 );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        remove_all_filters( 'cpms_license_public_key' );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function testUnauthenticatedRequestIsDeniedWithSameFixturePositiveControl(): void {
        $deniedGet = $this->dispatch( 'GET', self::NS . '/patient/me', [], 0 );
        self::assertSame( 401, $deniedGet->get_status() );
        self::assertSame( PatientAccess::UNAUTHORIZED_CODE, $deniedGet->get_data()['code'] ?? null );

        $deniedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'first_name' => 'Tamper' ], 0 );
        self::assertSame( 401, $deniedPut->get_status() );
        self::assertSame( PatientAccess::UNAUTHORIZED_CODE, $deniedPut->get_data()['code'] ?? null );

        $deniedRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [], 0 );
        self::assertSame( 401, $deniedRecords->get_status() );
        self::assertSame( PatientAccess::UNAUTHORIZED_CODE, $deniedRecords->get_data()['code'] ?? null );

        // Same-fixture positive control: linked patient user A gets 200.
        $allowed = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
        self::assertSame( 200, $allowed->get_status() );
        self::assertSame( $this->patientA, (int) ( $allowed->get_data()['data']['id'] ?? 0 ) );
    }

    public function testStaffUsersAreDeniedPatientRoutesWithSameFixturePositiveControl(): void {
        foreach ( [ $this->secretaryUser, $this->staffWithDbLinkOnly ] as $staffId ) {
            $deniedGet = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $staffId );
            self::assertSame( 403, $deniedGet->get_status() );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedGet->get_data()['code'] ?? null );

            $deniedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'first_name' => 'StaffEdit', 'clinic_id' => $this->clinicA ], $staffId );
            self::assertSame( 403, $deniedPut->get_status() );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedPut->get_data()['code'] ?? null );

            $deniedRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [], $staffId );
            self::assertSame( 403, $deniedRecords->get_status() );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedRecords->get_data()['code'] ?? null );

            $deniedEnroll = $this->dispatch( 'POST', self::NS . '/patient/enroll', [ 'clinic_id' => $this->clinicA, 'first_name' => 'S', 'last_name' => 'T', 'mobile' => '09129990001' ], $staffId );
            self::assertSame( 403, $deniedEnroll->get_status() );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedEnroll->get_data()['code'] ?? null );
        }

        // Verify patient record was untouched.
        $firstName = App::db()->fetchValue( 'SELECT first_name FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientA ] );
        self::assertSame( 'Alice', $firstName );

        // Same-fixture positive control.
        $allowed = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
        self::assertSame( 200, $allowed->get_status() );
        self::assertSame( $this->patientA, (int) ( $allowed->get_data()['data']['id'] ?? 0 ) );
    }

    public function testPatientWithNoLinkAndStaffMembershipBypassAreDeniedWithSameFixturePositiveControl(): void {
        // PatientUserZero has ROLE_PATIENT but zero patient bindings.
        $deniedNoSelector = $this->dispatch( 'GET', self::NS . '/patient/me', [], $this->patientUserZero );
        self::assertSame( 404, $deniedNoSelector->get_status() );
        self::assertSame( PatientAccess::NOT_FOUND_CODE, $deniedNoSelector->get_data()['code'] ?? null );

        $deniedWithClinic = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserZero );
        self::assertSame( 404, $deniedWithClinic->get_status() );
        self::assertSame( PatientAccess::NOT_FOUND_CODE, $deniedWithClinic->get_data()['code'] ?? null );

        $deniedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'first_name' => 'Ghost' ], $this->patientUserZero );
        self::assertSame( 404, $deniedPut->get_status() );
        self::assertSame( PatientAccess::NOT_FOUND_CODE, $deniedPut->get_data()['code'] ?? null );

        // User holds ROLE_PATIENT + patient link in Clinic A + active secretary membership in Clinic B: denied (403) in ALL clinics.
        $deniedStaffClinic = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicB ], $this->patientWithScopedMembership );
        self::assertSame( 403, $deniedStaffClinic->get_status() );
        self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedStaffClinic->get_data()['code'] ?? null );

        $deniedLinkedClinic = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientWithScopedMembership );
        self::assertSame( 403, $deniedLinkedClinic->get_status() );
        self::assertSame( PatientAccess::FORBIDDEN_CODE, $deniedLinkedClinic->get_data()['code'] ?? null );

        // Same-fixture positive control: PatientUserA succeeds in Clinic A where they hold a patient link.
        $allowed = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
        self::assertSame( 200, $allowed->get_status() );
        self::assertSame( $this->patientA, (int) ( $allowed->get_data()['data']['id'] ?? 0 ) );
    }

    public function testCrossClinicPatientIsDeniedWithIdenticalUnknownVsForbiddenResponsesAndPositiveControl(): void {
        // PatientUserB is linked ONLY to Clinic B. Attempting Clinic A via param, header, link_id, or PUT fails closed.
        $forbiddenParam   = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserB );
        $unknownParam     = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => 999999 ], $this->patientUserB );
        $forbiddenHeader  = $this->dispatch( 'GET', self::NS . '/patient/me', [], $this->patientUserB, [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ] );
        $unknownHeader    = $this->dispatch( 'GET', self::NS . '/patient/me', [], $this->patientUserB, [ 'X-CPMS-Clinic-Id' => '999999' ] );
        $forbiddenLink    = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'link_id' => $this->linkA ], $this->patientUserB );
        $unknownLink      = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'link_id' => 999999 ], $this->patientUserB );
        $forbiddenRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [ 'clinic_id' => $this->clinicA ], $this->patientUserB );
        $unknownRecords   = $this->dispatch( 'GET', self::NS . '/patient/my-records', [ 'clinic_id' => 999999 ], $this->patientUserB );
        $forbiddenPut     = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA, 'first_name' => 'CrossEdit' ], $this->patientUserB );
        $unknownPut       = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'clinic_id' => 999999, 'first_name' => 'CrossEdit' ], $this->patientUserB );

        // Identical denial responses for unknown vs forbidden across every selector vector.
        self::assertSame( 404, $forbiddenParam->get_status() );
        self::assertSame( 404, $unknownParam->get_status() );
        self::assertSame( $unknownParam->get_data(), $forbiddenParam->get_data() );

        self::assertSame( 404, $forbiddenHeader->get_status() );
        self::assertSame( $unknownHeader->get_data(), $forbiddenHeader->get_data() );

        self::assertSame( 404, $forbiddenLink->get_status() );
        self::assertSame( $unknownLink->get_data(), $forbiddenLink->get_data() );

        self::assertSame( 404, $forbiddenRecords->get_status() );
        self::assertSame( $unknownRecords->get_data(), $forbiddenRecords->get_data() );

        self::assertSame( 404, $forbiddenPut->get_status() );
        self::assertSame( $unknownPut->get_data(), $forbiddenPut->get_data() );

        // Verify Patient A was never mutated.
        $firstNameA = App::db()->fetchValue( 'SELECT first_name FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientA ] );
        self::assertSame( 'Alice', $firstNameA );

        // Same-fixture positive control: PatientUserB accessing Clinic B (param, header, link_id, PUT) succeeds.
        $allowedGet = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicB ], $this->patientUserB );
        self::assertSame( 200, $allowedGet->get_status() );
        self::assertSame( $this->patientB, (int) ( $allowedGet->get_data()['data']['id'] ?? 0 ) );

        $allowedHeader = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'link_id' => $this->linkB ], $this->patientUserB, [ 'X-CPMS-Clinic-Id' => (string) $this->clinicB ] );
        self::assertSame( 200, $allowedHeader->get_status() );
        self::assertSame( $this->patientB, (int) ( $allowedHeader->get_data()['data']['id'] ?? 0 ) );

        $allowedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicB, 'first_name' => 'Bobby' ], $this->patientUserB );
        self::assertSame( 200, $allowedPut->get_status() );
        self::assertSame( 'Bobby', $allowedPut->get_data()['data']['first_name'] ?? null );
    }

    public function testEnrollDenialParityAndPositiveControl(): void {
        $forbiddenEnroll = $this->dispatch(
            'POST',
            self::NS . '/patient/enroll',
            [ 'clinic_id' => $this->clinicB, 'first_name' => 'Z', 'last_name' => 'Zero', 'mobile' => '09128887766' ],
            $this->patientUserZero
        );
        $unknownEnroll = $this->dispatch(
            'POST',
            self::NS . '/patient/enroll',
            [ 'clinic_id' => 999999, 'first_name' => 'Z', 'last_name' => 'Zero', 'mobile' => '09128887766' ],
            $this->patientUserZero
        );
        self::assertSame( 404, $forbiddenEnroll->get_status() );
        self::assertSame( 404, $unknownEnroll->get_status() );
        self::assertSame( $unknownEnroll->get_data(), $forbiddenEnroll->get_data() );

        // Same-fixture positive control: PatientUserA with verified org identity enrolls in Clinic B.
        $allowedEnroll = $this->dispatch(
            'POST',
            self::NS . '/patient/enroll',
            [ 'clinic_id' => $this->clinicB, 'first_name' => 'Alice', 'last_name' => 'Enrolled', 'mobile' => '09128887767' ],
            $this->patientUserA
        );
        self::assertSame( 200, $allowedEnroll->get_status(), wp_json_encode( $allowedEnroll->get_data() ) );
        self::assertSame( $this->clinicB, (int) ( $allowedEnroll->get_data()['data']['clinic_id'] ?? 0 ) );
    }

    public function testMultiRoleStaffAndPatientUserIsDeniedWithSameFixturePositiveControl(): void {
        $mixedDoctorPatient = $this->makeUser( 'gate_mixed_doc_pat_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $mixedUserObj       = get_userdata( $mixedDoctorPatient );
        self::assertNotFalse( $mixedUserObj );
        $mixedUserObj->add_role( RolesAndCapabilities::ROLE_DOCTOR );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $mixedDoctorPatient, '09120010001' );

        $patientWithStaffCap = $this->makeUser( 'gate_pat_staff_cap_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $capUserObj          = get_userdata( $patientWithStaffCap );
        self::assertNotFalse( $capUserObj );
        $capUserObj->add_cap( RolesAndCapabilities::MEDICAL_READ );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $patientWithStaffCap, '09120010001' );

        $baselineDenied = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->secretaryUser );
        self::assertSame( 403, $baselineDenied->get_status() );

        foreach ( [ $mixedDoctorPatient, $patientWithStaffCap ] as $mixedId ) {
            $deniedMe = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $mixedId );
            self::assertSame( 403, $deniedMe->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedMe->get_data() );

            $deniedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'first_name' => 'MixedEdit', 'clinic_id' => $this->clinicA ], $mixedId );
            self::assertSame( 403, $deniedPut->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedPut->get_data() );

            $deniedRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [], $mixedId );
            self::assertSame( 403, $deniedRecords->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedRecords->get_data() );

            $deniedEnroll = $this->dispatch( 'POST', self::NS . '/patient/enroll', [ 'clinic_id' => $this->clinicA, 'first_name' => 'M', 'last_name' => 'P', 'mobile' => '09129990002' ], $mixedId );
            self::assertSame( 403, $deniedEnroll->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedEnroll->get_data() );
        }

        $firstName = App::db()->fetchValue( 'SELECT first_name FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientA ] );
        self::assertSame( 'Alice', $firstName );

        // Same-fixture positive control: pure patient user A still receives 200.
        $allowedMe = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
        self::assertSame( 200, $allowedMe->get_status() );
        self::assertSame( $this->patientA, (int) ( $allowedMe->get_data()['data']['id'] ?? 0 ) );
    }

    public function testClinicScopedStaffAuthoritiesAreDeniedWithSameFixturePositiveControl(): void {
        // 1. Clinic-local custom role granting a staff capability in Clinic B (WP role is still ROLE_PATIENT only).
        $patientWithCustomRole = $this->makeUser( 'gate_pat_custom_role_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $patientWithCustomRole, '09120010001' );
        $this->insertActiveCustomRoleDef( $this->clinicB, 'cx_gate_intake', [ RolesAndCapabilities::QUEUE_READ ] );
        App::membership_service()->create_membership( $this->clinicB, $patientWithCustomRole, 'cx_gate_intake' );

        // 2. Explicit membership capability grant in Clinic B (empty custom role + explicit grant).
        $patientWithExplicitGrant = $this->makeUser( 'gate_pat_explicit_grant_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $patientWithExplicitGrant, '09120010001' );
        $this->insertActiveCustomRoleDef( $this->clinicB, 'cx_gate_empty', [] );
        $grantMembershipId = App::membership_service()->create_membership( $this->clinicB, $patientWithExplicitGrant, 'cx_gate_empty' );
        App::membership_service()->set_capability( $grantMembershipId, RolesAndCapabilities::APPT_READ, 'grant' );

        $baselineDenied = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->secretaryUser );
        self::assertSame( 403, $baselineDenied->get_status() );
        self::assertSame( PatientAccess::FORBIDDEN_CODE, $baselineDenied->get_data()['code'] ?? null );

        // All three scoped staff authority kinds (built-in membership, custom role, explicit grant) must fail closed identically.
        foreach ( [ $this->patientWithScopedMembership, $patientWithCustomRole, $patientWithExplicitGrant ] as $scopedStaffId ) {
            $deniedMe = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $scopedStaffId );
            self::assertSame( 403, $deniedMe->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedMe->get_data() );

            $deniedPut = $this->dispatch( 'PUT', self::NS . '/patient/me', [ 'first_name' => 'ScopedEdit', 'clinic_id' => $this->clinicA ], $scopedStaffId );
            self::assertSame( 403, $deniedPut->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedPut->get_data() );

            $deniedRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [], $scopedStaffId );
            self::assertSame( 403, $deniedRecords->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedRecords->get_data() );

            $deniedEnroll = $this->dispatch( 'POST', self::NS . '/patient/enroll', [ 'clinic_id' => $this->clinicA, 'first_name' => 'S', 'last_name' => 'C', 'mobile' => '09129990003' ], $scopedStaffId );
            self::assertSame( 403, $deniedEnroll->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedEnroll->get_data() );
        }

        // Fail-closed proof: when the membership authority query errors, even pure patientUserA is denied identically.
        global $wpdb;
        $corruptMemberships = static function ( string $sql ): string {
            if ( str_contains( $sql, 'cpms_clinic_memberships' ) ) {
                return 'SELECT nonexistent_col_for_fail_closed_test FROM wp_nonexistent_cpms_table';
            }

            return $sql;
        };
        $previousSuppression = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $corruptMemberships, 5 );
        try {
            $deniedOnDbError = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
            self::assertSame( 403, $deniedOnDbError->get_status() );
            self::assertSame( $baselineDenied->get_data(), $deniedOnDbError->get_data() );
        } finally {
            remove_filter( 'query', $corruptMemberships, 5 );
            $wpdb->suppress_errors( $previousSuppression );
        }

        $firstName = App::db()->fetchValue( 'SELECT first_name FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d', [ $this->patientA ] );
        self::assertSame( 'Alice', $firstName );

        // Same-fixture positive control: genuinely patient-only user A succeeds on /patient/me and /patient/my-records.
        $allowedMe = $this->dispatch( 'GET', self::NS . '/patient/me', [ 'clinic_id' => $this->clinicA ], $this->patientUserA );
        self::assertSame( 200, $allowedMe->get_status() );
        self::assertSame( $this->patientA, (int) ( $allowedMe->get_data()['data']['id'] ?? 0 ) );

        $allowedRecords = $this->dispatch( 'GET', self::NS . '/patient/my-records', [], $this->patientUserA );
        self::assertSame( 200, $allowedRecords->get_status() );
        self::assertNotEmpty( $allowedRecords->get_data()['data'] ?? [] );
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

    private function insertPatient( int $clinicId, string $mrn, string $firstName, string $lastName, string $mobile ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patients',
            [
                'clinic_id'  => $clinicId,
                'mrn'        => $mrn,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'mobile'     => $mobile,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertPatientLink( int $clinicId, int $patientId, int $userId, string $mobile ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $clinicId,
                'patient_id'     => $patientId,
                'wp_user_id'     => $userId,
                'mobile_at_link' => $mobile,
                'is_primary'     => 1,
                'linked_at'      => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    private function makeUser( string $login, string $role ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $userId;
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function dispatch( string $method, string $route, array $params, int $userId, array $headers = [] ): WP_REST_Response {
        wp_set_current_user( $userId );
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        foreach ( $headers as $name => $val ) {
            $request->set_header( $name, $val );
        }

        return rest_do_request( $request );
    }
}

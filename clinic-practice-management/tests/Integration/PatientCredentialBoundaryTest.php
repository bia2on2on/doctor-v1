<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Patients\PatientAccountPolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * B0-PR2 — patient credential boundary (owner-approved policy).
 *
 * Nobody — including Administrator and Clinic Manager — may create, enroll, set,
 * reset or change the credential of a PATIENT account, or hand out patient
 * binding/access, from any plugin staff path. Each denial is asserted to leave
 * ZERO side effects: no WordPress user, no password/role write, no membership,
 * no audit row and no reset key. Staff creation/update stays working (controls).
 *
 * Patient evidence is deliberately NOT a single WordPress role: role,
 * patient-role membership and a durable patient link each count, and mixed
 * accounts (staff role + patient evidence) fail closed.
 */
final class PatientCredentialBoundaryTest extends WP_UnitTestCase
{
    private int $clinicId = 0;

    /** @var list<int> */
    private array $userIds = [];

    /** @var callable|null Installed `query` fault, if any. */
    private $queryFault = null;

    private ?bool $previousSuppression = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->clinicId = $this->createClinic();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        $this->restoreReads();
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * Force a database error on every SELECT touching $needle (no DDL: a
     * CREATE/DROP would implicitly commit the fixture transaction).
     *
     * @param callable|null $onlyWhen Additional condition: break the read only
     *                               while it returns true.
     */
    private function failReadsMatching( string $needle, ?callable $onlyWhen = null ): void
    {
        global $wpdb;

        self::assertNull( $this->queryFault, 'the read fault must not be installed twice' );

        $fault = static function ( $query ) use ( $needle, $onlyWhen ) {
            $sql = (string) $query;
            if ( $onlyWhen !== null && true !== $onlyWhen() ) {
                return $query;
            }
            if ( 1 === preg_match( '/\A\s*SELECT/i', $sql ) && false !== strpos( $sql, $needle ) ) {
                return 'SELECT cpms_fault_injection_no_such_column FROM ' . $needle;
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        $this->queryFault = $fault;
    }

    private function restoreReads(): void
    {
        if ( $this->queryFault === null ) {
            return;
        }

        remove_filter( 'query', $this->queryFault, 5 );
        $this->queryFault = null;

        global $wpdb;
        $wpdb->suppress_errors( (bool) $this->previousSuppression );
        $this->previousSuppression = null;
    }

    private function membershipRow( int $membershipId ): array
    {
        return (array) App::db()->fetchRow(
            'SELECT id, role_key, status FROM ' . App::db()->table( 'cpms_clinic_memberships' )
            . ' WHERE id = %d LIMIT 1',
            [ $membershipId ]
        );
    }

    private function capabilityOverrides( int $membershipId ): array
    {
        return App::db()->fetchAll(
            'SELECT capability, effect FROM ' . App::db()->table( 'cpms_membership_capabilities' )
            . ' WHERE membership_id = %d ORDER BY capability ASC',
            [ $membershipId ]
        );
    }

    // ==================================================================
    // Fixtures / helpers
    // ==================================================================

    private function createClinic(): int
    {
        global $wpdb;

        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1'
        );
        $this->assertGreaterThan(0, $organizationId, 'organization fixture must exist');

        $slug = 'patient-boundary-' . bin2hex(random_bytes(4));
        $now  = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, %s, %s, %s)',
                $organizationId,
                'Patient boundary ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /** Administrator WITH durable Clinic staff-management authority. */
    private function makeAdmin(): int
    {
        $id             = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->userIds[] = $id;
        cpms_test_seed_membership( $id, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER );
        wp_set_current_user( $id );

        return $id;
    }

    /**
     * Ordinary Subscriber with durable Clinic-manager authority — the exact
     * actor the management portal treats as a Clinic manager (no WP role).
     */
    private function makeClinicManager( string $login ): int
    {
        $id = $this->makeUser( 'subscriber', $login );
        cpms_test_seed_membership( $id, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER );
        wp_set_current_user( $id );

        return $id;
    }

    private function makeUser( string $role, string $login ): int
    {
        $id = (int) self::factory()->user->create(
            [
                'role'       => $role,
                'user_login' => $login,
                'user_email' => $login . '@boundary.test',
                'user_pass'  => 'InitialStaffPass123',
            ]
        );
        $this->userIds[] = $id;

        return $id;
    }

    /** Patient account: WordPress patient role + patient-role membership. */
    private function makePatientAccount( string $login ): int
    {
        $id = $this->makeUser( RolesAndCapabilities::ROLE_PATIENT, $login );
        cpms_test_seed_membership( $id, $this->clinicId, RolesAndCapabilities::ROLE_PATIENT );

        return $id;
    }

    private function auditCount( string $action ): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action = %s',
            [ $action ]
        );
    }

    private function membershipCount( int $clinicId, int $userId ): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' )
            . ' WHERE clinic_id = %d AND wp_user_id = %d',
            [ $clinicId, $userId ]
        );
    }

    private function userExistsByEmail( string $email ): bool
    {
        return get_user_by( 'email', $email ) instanceof \WP_User;
    }

    /** @return list<array<string, mixed>> */
    private function auditRowsFor( string $action, int $wpUserId ): array
    {
        return App::db()->fetchAll(
            'SELECT action, before_json, after_json FROM ' . App::db()->table( 'cpms_audit_logs' )
            . ' WHERE action = %s AND resource_type = %s AND resource_id = %d ORDER BY id ASC',
            [ $action, 'user', $wpUserId ]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function sortedKeys( array $row ): array
    {
        $keys = array_keys( $row );
        sort( $keys );

        return array_map( 'strval', $keys );
    }

    private function passwordHash( int $userId ): string
    {
        return (string) get_userdata( $userId )->user_pass;
    }

    // ==================================================================
    // 1. Evidence rule (no single-role inference)
    // ==================================================================

    public function test_patient_evidence_is_a_union_and_mixed_accounts_fail_closed(): void
    {
        // (a) WordPress patient role only (no membership, no link).
        $roleOnly = $this->makeUser( RolesAndCapabilities::ROLE_PATIENT, 'pb_role_only' );
        self::assertTrue( PatientAccountPolicy::is_patient_account( $roleOnly ) );

        // (b) Patient-role membership only: a plain Subscriber account.
        $membershipOnly = $this->makeUser( 'subscriber', 'pb_membership_only' );
        cpms_test_seed_membership( $membershipOnly, $this->clinicId, RolesAndCapabilities::ROLE_PATIENT );
        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $membershipOnly ),
            'a patient-role membership alone is patient evidence'
        );

        // (c) Durable patient link only.
        $linked = $this->makeUser( 'subscriber', 'pb_linked_only' );
        $this->insertPatientLink( $linked );
        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $linked ),
            'a durable patient link alone is patient evidence'
        );

        // (d) Mixed: patient role/membership PLUS a staff role — fail closed.
        $mixed = $this->makeUser( RolesAndCapabilities::ROLE_SECRETARY, 'pb_mixed' );
        cpms_test_seed_membership( $mixed, $this->clinicId, RolesAndCapabilities::ROLE_PATIENT );
        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $mixed ),
            'a staff role never cancels patient evidence'
        );

        // (e) Unresolvable target — fail closed, never a staff grant.
        self::assertTrue( PatientAccountPolicy::is_patient_account( 0 ) );
        self::assertTrue( PatientAccountPolicy::is_patient_account( 987654 ) );

        // (f) A real staff account is not patient evidence.
        $staff = $this->makeUser( 'subscriber', 'pb_staff_control' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );

        // (g) The patient role key is never an assignable key.
        self::assertTrue( PatientAccountPolicy::is_patient_role_key( RolesAndCapabilities::ROLE_PATIENT ) );
        self::assertFalse( PatientAccountPolicy::is_patient_role_key( RolesAndCapabilities::ROLE_SECRETARY ) );
    }

    private function insertPatientLink( int $wpUserId ): void
    {
        global $wpdb;

        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients'
                . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
                $this->clinicId,
                'MR-PB-' . bin2hex( random_bytes( 3 ) ),
                'Patient',
                'Boundary',
                '0912' . random_int( 1000000, 9999999 ),
                'active',
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links'
                . ' (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)'
                . ' VALUES (%d, %d, %d, %s, 1, %s)',
                $this->clinicId,
                $patientId,
                $wpUserId,
                '09120000000',
                $now
            )
        );
    }

    // ==================================================================
    // 2. Classic staff screen — create / update / reset / attach / toggle
    // ==================================================================

    public function test_classic_screen_cannot_create_a_patient_account(): void
    {
        $admin = $this->makeAdmin();

        $auditBefore = $this->auditCount( 'STAFF_USER_CREATED' );

        $result = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'create',
                'username'     => 'pb_new_patient',
                'display_name' => 'بیمار جدید',
                'email'        => 'pb_new_patient@boundary.test',
                'role'         => RolesAndCapabilities::ROLE_PATIENT,
                'password'     => 'StrongPass123',
            ],
            $admin
        );

        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $result['error'] );
        self::assertSame( '', $result['generated'], 'no password may be generated for a refused create' );
        self::assertSame( 0, $result['user_id'] );

        self::assertFalse( get_user_by( 'login', 'pb_new_patient' ), 'no WordPress user may be created' );
        self::assertFalse( $this->userExistsByEmail( 'pb_new_patient@boundary.test' ) );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_USER_CREATED' ), 'no audit row may be written' );
    }

    public function test_classic_screen_cannot_change_a_patient_password_or_profile(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makePatientAccount( 'pb_patient_edit' );

        $hashBefore  = $this->passwordHash( $patient );
        $auditBefore = $this->auditCount( 'STAFF_USER_UPDATED' );

        $result = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $patient,
                'username'     => 'pb_patient_edit',
                'display_name' => 'نام تغییر یافته',
                'email'        => 'pb_patient_edit@boundary.test',
                'role'         => RolesAndCapabilities::ROLE_DOCTOR,
                'password'     => 'AttackerChosen123',
            ],
            $admin
        );

        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $result['error'] );
        self::assertSame( $hashBefore, $this->passwordHash( $patient ), 'the patient password must not change' );
        self::assertFalse(
            wp_check_password( 'AttackerChosen123', $this->passwordHash( $patient ) ),
            'the refused password must not be usable'
        );
        self::assertContains(
            RolesAndCapabilities::ROLE_PATIENT,
            (array) get_userdata( $patient )->roles,
            'the WordPress role must not change'
        );
        self::assertSame(
            RolesAndCapabilities::ROLE_PATIENT,
            (string) App::membership_service()->membership_for( $this->clinicId, $patient )['role_key'],
            'the membership role must not change'
        );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_USER_UPDATED' ) );
    }

    public function test_classic_screen_refuses_a_mixed_role_target(): void
    {
        $admin = $this->makeAdmin();
        $mixed = $this->makeUser( RolesAndCapabilities::ROLE_SECRETARY, 'pb_mixed_edit' );
        cpms_test_seed_membership( $mixed, $this->clinicId, RolesAndCapabilities::ROLE_PATIENT );

        $hashBefore = $this->passwordHash( $mixed );

        $result = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $mixed,
                'display_name' => 'x',
                'email'        => 'pb_mixed_edit@boundary.test',
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'AnotherChosen123',
            ],
            $admin
        );

        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $result['error'] );
        self::assertSame( $hashBefore, $this->passwordHash( $mixed ) );
    }

    public function test_classic_screen_cannot_initiate_a_patient_password_reset(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makePatientAccount( 'pb_patient_reset' );

        $keyBefore   = (string) get_userdata( $patient )->user_activation_key;
        $auditBefore = $this->auditCount( 'STAFF_PASSWORD_RESET_INITIATED' );

        $result = StaffManagementPage::initiatePasswordReset( $patient, $admin, $this->clinicId );

        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $result['error'] );
        self::assertSame( '', $result['display_name'], 'no target detail may leak on denial' );
        self::assertSame( $keyBefore, (string) get_userdata( $patient )->user_activation_key, 'no reset key may be minted' );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_PASSWORD_RESET_INITIATED' ) );
    }

    public function test_classic_screen_cannot_attach_a_patient_account_or_hand_out_patient_role(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( 'subscriber', 'pb_attach_staff' );
        $wpAdministrator = $this->makeUser( 'administrator', 'pb_attach_admin' );

        $auditBefore = $this->auditCount( 'STAFF_USER_ATTACHED' );

        // (a) Attaching an existing account WITH the patient role key is refused
        // with the stable patient denial (a role-key choice, not target identity).
        $withPatientRole = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'        => $this->clinicId,
                'existing_user_id' => $staff,
                'role'             => RolesAndCapabilities::ROLE_PATIENT,
            ],
            $admin
        );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $withPatientRole['error'] );
        self::assertSame( 0, $this->membershipCount( $this->clinicId, $staff ), 'no membership may be created' );

        // (b) A PATIENT target is refused, and its refusal must be
        // indistinguishable from every other target-user rejection: enumeration
        // neutrality is part of the existing contract (Phase4ExistingStaffOnboardingTest).
        $patient        = $this->makePatientAccount( 'pb_patient_attach' );
        $patientMembers = $this->membershipCount( $this->clinicId, $patient );

        $missingTarget = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'        => $this->clinicId,
                'existing_user_id' => 999999999,
                'role'             => RolesAndCapabilities::ROLE_SECRETARY,
            ],
            $admin
        );
        $administratorTarget = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'        => $this->clinicId,
                'existing_user_id' => $wpAdministrator,
                'role'             => RolesAndCapabilities::ROLE_SECRETARY,
            ],
            $admin
        );
        $patientTarget = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'        => $this->clinicId,
                'existing_user_id' => $patient,
                'role'             => RolesAndCapabilities::ROLE_SECRETARY,
            ],
            $admin
        );

        self::assertNotSame( '', (string) $missingTarget['error'] );
        self::assertSame(
            (string) $missingTarget['error'],
            (string) $administratorTarget['error'],
            'unresolvable id and administrator stay indistinguishable'
        );
        self::assertSame(
            (string) $missingTarget['error'],
            (string) $patientTarget['error'],
            'a patient target must be indistinguishable from the other rejections'
        );
        self::assertStringNotContainsString(
            'بیمار',
            (string) $patientTarget['error'],
            'the patient denial text must never leak on the attach surface'
        );
        self::assertSame( $patientMembers, $this->membershipCount( $this->clinicId, $patient ) );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_USER_ATTACHED' ) );
    }

    public function test_classic_screen_suspends_a_patient_account_and_refuses_its_reactivation(): void
    {
        // CHANGED ASSERTION (deliberate policy change, manager decision): this test
        // used to assert that SUSPEND is refused for a patient account. Revocation
        // is the safe direction, so suspend is now ALLOWED (zero credential side
        // effects) while reactivate stays DENIED. Every reactivate-denial
        // assertion below is the previous one, unchanged.
        $admin   = $this->makeAdmin();
        $patient = $this->makePatientAccount( 'pb_patient_toggle' );

        $hashBefore = $this->passwordHash( $patient );
        $keyBefore  = (string) get_userdata( $patient )->user_activation_key;
        $activateBefore = $this->auditCount( 'STAFF_USER_ACTIVATED' );
        $membershipsBefore = $this->membershipCount( $this->clinicId, $patient );

        $suspend = StaffManagementPage::toggleUser( $patient, 'deactivate', $admin, $this->clinicId );
        self::assertSame( '', $suspend['error'], 'suspension (revocation) is allowed for a patient account' );

        // What suspend writes: membership status, parked-role meta, WP role
        // downgrade to subscriber, one structured audit row. No credential at all.
        self::assertSame(
            'suspended',
            (string) App::membership_service()->membership_for( $this->clinicId, $patient )['status']
        );
        self::assertSame( 'cpms_patient', (string) get_user_meta( $patient, 'cpms_previous_role', true ) );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $patient )->roles ) );
        self::assertSame( $hashBefore, $this->passwordHash( $patient ), 'suspend must not touch the password' );
        self::assertSame( $keyBefore, (string) get_userdata( $patient )->user_activation_key, 'no reset key' );
        self::assertSame( $membershipsBefore, $this->membershipCount( $this->clinicId, $patient ), 'no membership created' );
        self::assertSame( $activateBefore, $this->auditCount( 'STAFF_USER_ACTIVATED' ) );

        $deactivated = $this->auditRowsFor( 'STAFF_USER_DEACTIVATED', $patient );
        self::assertCount( 1, $deactivated, 'suspension is audited exactly once' );
        $after = json_decode( (string) $deactivated[0]['after_json'], true );
        self::assertIsArray( $after );
        self::assertSame(
            [ 'clinic_id', 'membership_status', 'role', 'wp_global_role' ],
            $this->sortedKeys( $after ),
            'the suspension audit row carries structured fields only'
        );
        self::assertStringNotContainsString( '@', (string) $deactivated[0]['after_json'], 'no e-mail in the audit row' );

        // Reactivation stays refused with the same stable denial, zero side effects.
        $actBefore = $this->auditCount( 'STAFF_USER_ACTIVATED' );
        $reactivate = StaffManagementPage::toggleUser( $patient, 'activate', $admin, $this->clinicId );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $reactivate['error'] );
        self::assertSame(
            'suspended',
            (string) App::membership_service()->membership_for( $this->clinicId, $patient )['status'],
            'a refused reactivation leaves the membership suspended'
        );
        self::assertSame( 'cpms_patient', (string) get_user_meta( $patient, 'cpms_previous_role', true ) );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $patient )->roles ) );
        self::assertSame( $actBefore, $this->auditCount( 'STAFF_USER_ACTIVATED' ) );
        self::assertSame( $hashBefore, $this->passwordHash( $patient ) );
    }

    public function test_classic_screen_suspends_a_mixed_account_but_never_reactivates_it(): void
    {
        $admin = $this->makeAdmin();
        $mixed = $this->makeUser( RolesAndCapabilities::ROLE_SECRETARY, 'pb_mixed_toggle' );
        cpms_test_seed_membership( $mixed, $this->clinicId, RolesAndCapabilities::ROLE_PATIENT );

        $activateBefore = $this->auditCount( 'STAFF_USER_ACTIVATED' );

        $suspend = StaffManagementPage::toggleUser( $mixed, 'deactivate', $admin, $this->clinicId );
        self::assertSame( '', $suspend['error'], 'suspending a mixed account is the safe direction' );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $mixed )->roles ) );
        self::assertSame( 'cpms_secretary', (string) get_user_meta( $mixed, 'cpms_previous_role', true ) );

        $reactivate = StaffManagementPage::toggleUser( $mixed, 'activate', $admin, $this->clinicId );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $reactivate['error'] );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $mixed )->roles ), 'the staff role is never restored' );
        self::assertSame( 'cpms_secretary', (string) get_user_meta( $mixed, 'cpms_previous_role', true ) );
        self::assertSame( $activateBefore, $this->auditCount( 'STAFF_USER_ACTIVATED' ) );
    }

    public function test_control_staff_suspend_and_reactivate_still_work(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( RolesAndCapabilities::ROLE_SECRETARY, 'pb_staff_toggle' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        self::assertSame( '', StaffManagementPage::toggleUser( $staff, 'deactivate', $admin, $this->clinicId )['error'] );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $staff )->roles ) );

        self::assertSame(
            '',
            StaffManagementPage::toggleUser( $staff, 'activate', $admin, $this->clinicId )['error'],
            'a pure staff account keeps the normal suspend/reactivate cycle'
        );
        self::assertSame( [ 'cpms_secretary' ], array_values( (array) get_userdata( $staff )->roles ) );
        self::assertSame( '', (string) get_user_meta( $staff, 'cpms_previous_role', true ) );
        self::assertSame(
            'active',
            (string) App::membership_service()->membership_for( $this->clinicId, $staff )['status']
        );
    }

    // ==================================================================
    // 3. Clinic management portal — enroll / role assignment
    // ==================================================================

    public function test_portal_cannot_enroll_a_patient_membership_or_provision_for_it(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_enroll' );
        $email = 'pb_portal_patient_' . bin2hex( random_bytes( 4 ) ) . '@boundary.test';

        $auditBefore = $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_enroll',
            [
                'clinic_id'  => (string) $this->clinicId,
                'identifier' => $email,
                'role_key'   => RolesAndCapabilities::ROLE_PATIENT,
            ]
        );

        self::assertFalse( $result['ok'], 'the patient role key is never enrollable' );
        self::assertFalse( $this->userExistsByEmail( $email ), 'no Subscriber account may be provisioned' );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' ) );
    }

    public function test_portal_cannot_enroll_an_existing_patient_account_as_staff(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_existing' );

        // LINK-ONLY target: no membership at all in this Clinic, so the
        // duplicate-membership refusal cannot be what refuses the enrollment —
        // only the patient-account policy can. Without the guard the enrollment
        // would succeed and create a staff membership for a patient.
        $patient = $this->makeUser( 'subscriber', 'pb_portal_existing' );
        $this->insertPatientLink( $patient );
        $email = (string) get_userdata( $patient )->user_email;

        self::assertSame( 0, $this->membershipCount( $this->clinicId, $patient ) );
        $auditBefore = $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_enroll',
            [
                'clinic_id'  => (string) $this->clinicId,
                'identifier' => $email,
                'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
            ]
        );

        self::assertFalse( $result['ok'], 'an existing patient account is never a staff target' );
        self::assertSame(
            0,
            $this->membershipCount( $this->clinicId, $patient ),
            'no membership may be added to the patient account'
        );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' ) );

        // Control: the same path still enrolls an ordinary new staff account.
        $controlEmail = 'pb_portal_control_' . bin2hex( random_bytes( 4 ) ) . '@boundary.test';
        $control      = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_enroll',
            [
                'clinic_id'  => (string) $this->clinicId,
                'identifier' => $controlEmail,
                'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
            ]
        );
        self::assertTrue( $control['ok'], 'staff enrollment of a non-patient target is unchanged' );
        $controlUser = get_user_by( 'email', $controlEmail );
        self::assertInstanceOf( \WP_User::class, $controlUser );
        self::assertSame(
            1,
            $this->membershipCount( $this->clinicId, (int) $controlUser->ID ),
            'the control target really receives a membership'
        );
    }

    public function test_portal_cannot_assign_the_patient_role_to_a_staff_member(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_role' );
        $staff = $this->makeUser( 'subscriber', 'pb_portal_staff' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $auditBefore = $this->auditCount( 'MEMBERSHIP_ROLE_CHANGED' );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_role',
            [
                'clinic_id'      => (string) $this->clinicId,
                'target_user_id' => (string) $staff,
                'role_key'       => RolesAndCapabilities::ROLE_PATIENT,
            ]
        );

        self::assertFalse( $result['ok'], 'the patient role key is never assignable' );
        self::assertSame(
            RolesAndCapabilities::ROLE_SECRETARY,
            (string) App::membership_service()->membership_for( $this->clinicId, $staff )['role_key'],
            'the membership role must not change'
        );
        self::assertSame( $auditBefore, $this->auditCount( 'MEMBERSHIP_ROLE_CHANGED' ) );
    }

    // ==================================================================
    // 4. Controls — staff creation/update must keep working unchanged
    // ==================================================================

    public function test_control_staff_creation_and_password_update_still_work(): void
    {
        $admin = $this->makeAdmin();

        $created = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'create',
                'username'     => 'pb_control_staff',
                'display_name' => 'کارمند کنترل',
                'email'        => 'pb_control_staff@boundary.test',
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'StrongPass123',
            ],
            $admin
        );
        self::assertSame( '', $created['error'], 'staff creation is unchanged by this policy' );
        $staffId = (int) $created['user_id'];
        self::assertGreaterThan( 0, $staffId );

        $updated = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $staffId,
                'display_name' => 'کارمند کنترل',
                'email'        => 'pb_control_staff@boundary.test',
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'SecondStaffPass123',
            ],
            $admin
        );
        self::assertSame( '', $updated['error'], 'an administrator may still set a staff password' );
        self::assertTrue( wp_check_password( 'SecondStaffPass123', $this->passwordHash( $staffId ) ) );

        $reset = StaffManagementPage::initiatePasswordReset( $staffId, $admin, $this->clinicId );
        self::assertSame( '', $reset['error'], 'staff password reset is unchanged' );
    }

    // ==================================================================
    // 5. Fail-closed evidence reads — a failed read is patient evidence
    // ==================================================================

    public function test_failed_membership_read_is_patient_evidence(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( 'subscriber', 'pb_fault_membership' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $email = (string) get_userdata( $staff )->user_email;
        $hash  = $this->passwordHash( $staff );

        // Positive control: with working reads the same account is ordinary staff.
        // WITHOUT the fail-closed read the fault below looks exactly like
        // "no patient membership", so the update would silently succeed.
        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );

        $this->failReadsMatching( 'cpms_clinic_memberships' );

        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $staff ),
            'a failed membership read must never look like absence of evidence'
        );

        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $staff,
                'display_name' => 'کارمند خرابی',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'FaultMembershipPass123',
            ],
            $admin
        );

        // The refusal itself is what matters here (the exact message may come from
        // an earlier lookup that the same fault also broke); the password write is
        // the side effect that must NOT happen.
        self::assertNotSame( '', $update['error'], 'the update must be refused while the evidence read fails' );
        self::assertSame( $hash, $this->passwordHash( $staff ), 'no password write on an unverified target' );

        $this->restoreReads();
        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ), 'reads work again' );
    }

    public function test_failed_patient_link_read_is_patient_evidence(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( 'subscriber', 'pb_fault_link' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $email = (string) get_userdata( $staff )->user_email;

        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );

        $this->failReadsMatching( 'cpms_patient_user_links' );

        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $staff ),
            'a failed durable-link read must deny'
        );

        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $staff,
                'display_name' => 'کارمند خرابی',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'FaultLinkPass123',
            ],
            $admin
        );

        self::assertNotSame( '', $update['error'], 'the update must be refused while the evidence read fails' );
        $this->restoreReads();
    }

    public function test_failed_parked_role_read_is_patient_evidence(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( 'subscriber', 'pb_fault_parked' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $email = (string) get_userdata( $staff )->user_email;

        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );

        $this->failReadsMatching( 'cpms_previous_role' );

        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $staff ),
            'a failed parked-role read must deny'
        );

        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $staff,
                'display_name' => 'کارمند خرابی',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'FaultParkedPass123',
            ],
            $admin
        );

        self::assertNotSame( '', $update['error'], 'the update must be refused while the evidence read fails' );
        $this->restoreReads();
    }

    // ==================================================================
    // 6. Parked patient role — suspension must not erase the evidence
    // ==================================================================

    public function test_parked_patient_role_keeps_denying_after_suspension(): void
    {
        $admin  = $this->makeAdmin();
        $target = $this->makeUser( RolesAndCapabilities::ROLE_PATIENT, 'pb_parked_patient' );
        // The staff membership is the ONLY non-patient evidence: if the parked
        // role were ignored, this account would look like a plain staff account
        // after the suspension downgraded its WordPress role to Subscriber.
        cpms_test_seed_membership( $target, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $email = (string) get_userdata( $target )->user_email;

        self::assertSame( '', StaffManagementPage::toggleUser( $target, 'deactivate', $admin, $this->clinicId )['error'] );
        self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $target )->roles ) );
        self::assertSame(
            RolesAndCapabilities::ROLE_PATIENT,
            (string) get_user_meta( $target, 'cpms_previous_role', true )
        );

        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $target ),
            'the parked patient role is still patient evidence'
        );

        // (a) classic screen: reactivation stays denied.
        self::assertSame(
            PatientAccountPolicy::DENIAL_MESSAGE,
            StaffManagementPage::toggleUser( $target, 'activate', $admin, $this->clinicId )['error']
        );

        // (b) classic screen: profile/password update stays denied.
        $hash   = $this->passwordHash( $target );
        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $target,
                'display_name' => 'بیمار',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'ParkedPatientPass123',
            ],
            $admin
        );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $update['error'] );
        self::assertSame( $hash, $this->passwordHash( $target ) );

        // (c) management portal: enrollment of the same account stays denied.
        $manager = $this->makeClinicManager( 'pb_mgr_parked' );
        self::assertFalse(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_enroll',
                [
                    'clinic_id'  => (string) $this->clinicId,
                    'identifier' => $email,
                    'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
                ]
            )['ok']
        );
        self::assertSame( 1, $this->membershipCount( $this->clinicId, $target ) );
    }

    // ==================================================================
    // 7. Portal reactivation is a NEW participation: always denied
    // ==================================================================

    public function test_portal_refuses_reactivation_of_a_patient_membership(): void
    {
        $manager    = $this->makeClinicManager( 'pb_mgr_reactivate' );
        $patient    = $this->makeUser( 'subscriber', 'pb_reactivate_patient' );
        $this->insertPatientLink( $patient );
        $membership = cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $suspended = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_status',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'active'        => '0',
            ]
        );
        self::assertTrue( $suspended['ok'], 'suspending patient-side access is allowed' );
        self::assertSame( 'suspended', (string) $this->membershipRow( $membership )['status'] );

        $auditBefore = $this->auditCount( 'MEMBERSHIP_REACTIVATED' );

        $reactivated = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_status',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'active'        => '1',
            ]
        );

        self::assertFalse( $reactivated['ok'], 'a patient membership is never reactivated' );
        self::assertSame( 'suspended', (string) $this->membershipRow( $membership )['status'] );
        self::assertSame( $auditBefore, $this->auditCount( 'MEMBERSHIP_REACTIVATED' ) );
    }

    // ==================================================================
    // 8. Enrollment: a refusal inside the boundary leaves nothing behind
    // ==================================================================

    public function test_enrollment_rolls_back_when_the_new_target_becomes_patient_evidence(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_rollback' );
        $email   = 'pb_rollback_' . bin2hex( random_bytes( 4 ) ) . '@boundary.test';
        $created = false;

        $markCreated = static function () use ( &$created ): void {
            $created = true;
        };
        add_action( 'user_register', $markCreated );

        // Before provisioning the evidence reads work, so the pre-boundary check
        // passes; from the moment the account exists every membership read fails,
        // which the in-transaction re-check must treat as patient evidence.
        $this->failReadsMatching(
            'cpms_clinic_memberships',
            static function () use ( &$created ): bool {
                return true === $created;
            }
        );

        $auditBefore = $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_enroll',
            [
                'clinic_id'  => (string) $this->clinicId,
                'identifier' => $email,
                'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
            ]
        );

        $this->restoreReads();
        remove_action( 'user_register', $markCreated );

        self::assertTrue( $created, 'the account was provisioned before the in-boundary refusal' );
        self::assertFalse( $result['ok'], 'the enrollment must fail' );
        self::assertFalse( $this->userExistsByEmail( $email ), 'no account may survive the refusal' );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_MEMBERSHIP_CREATED' ) );
    }

    // ==================================================================
    // 9. Every other grant path refuses a patient target
    // ==================================================================

    public function test_capability_grants_refuse_a_patient_membership(): void
    {
        $manager    = $this->makeClinicManager( 'pb_mgr_caps' );
        $patient    = $this->makeUser( 'subscriber', 'pb_caps_patient' );
        $this->insertPatientLink( $patient );
        $membership = cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $granted = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'grant',
            ]
        );
        self::assertFalse( $granted['ok'], 'no explicit GRANT may widen a patient membership' );
        self::assertSame( [], $this->capabilityOverrides( $membership ) );

        $denied = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'deny',
            ]
        );
        self::assertTrue( $denied['ok'], 'adding a DENY only narrows access and stays allowed' );
        self::assertSame(
            [ [ 'capability' => RolesAndCapabilities::CONFIG, 'effect' => 'deny' ] ],
            $this->capabilityOverrides( $membership )
        );

        // Financial delegation is a GRANT of a sensitive capability.
        try {
            App::finance_delegation_service()->delegate(
                $manager,
                $this->clinicId,
                $membership,
                RolesAndCapabilities::PAYMENT_REFUND
            );
            self::fail( 'financial delegation to a patient membership must be refused' );
        } catch ( FinanceException $e ) {
            self::assertSame( 'CLINIC_DELEGATION_DENIED', $e->errorCode );
        }

        self::assertSame(
            [ [ 'capability' => RolesAndCapabilities::CONFIG, 'effect' => 'deny' ] ],
            $this->capabilityOverrides( $membership ),
            'the refused delegation wrote no override'
        );
    }
}

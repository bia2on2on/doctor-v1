<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Frontend\ManagementPortalController;
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

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->clinicId = $this->createClinic();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
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

        $auditBefore = $this->auditCount( 'STAFF_USER_ATTACHED' );

        // (a) Attaching an existing account WITH the patient role key is refused.
        $withPatientRole = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'       => $this->clinicId,
                'existing_user_id' => $staff,
                'role'            => RolesAndCapabilities::ROLE_PATIENT,
            ],
            $admin
        );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $withPatientRole['error'] );
        self::assertSame( 0, $this->membershipCount( $this->clinicId, $staff ), 'no membership may be created' );

        // (b) Attaching a PATIENT account as staff is refused too.
        $patient        = $this->makePatientAccount( 'pb_patient_attach' );
        $patientMembers = $this->membershipCount( $this->clinicId, $patient );

        $patientTarget = StaffManagementPage::attachExistingUser(
            [
                'clinic_id'       => $this->clinicId,
                'existing_user_id' => $patient,
                'role'            => RolesAndCapabilities::ROLE_SECRETARY,
            ],
            $admin
        );
        self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $patientTarget['error'] );
        self::assertSame( $patientMembers, $this->membershipCount( $this->clinicId, $patient ) );
        self::assertSame( $auditBefore, $this->auditCount( 'STAFF_USER_ATTACHED' ) );
    }

    public function test_classic_screen_cannot_suspend_or_reactivate_a_patient_account(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makePatientAccount( 'pb_patient_toggle' );

        $membershipBefore = App::membership_service()->membership_for( $this->clinicId, $patient );
        $roleBefore       = (array) get_userdata( $patient )->roles;

        foreach ( [ 'deactivate', 'reactivate' ] as $state ) {
            $result = StaffManagementPage::toggleUser( $patient, $state, $admin, $this->clinicId );
            self::assertSame( PatientAccountPolicy::DENIAL_MESSAGE, $result['error'] );
        }

        $membershipAfter = App::membership_service()->membership_for( $this->clinicId, $patient );
        self::assertSame( (string) $membershipBefore['status'], (string) $membershipAfter['status'] );
        self::assertSame( $roleBefore, (array) get_userdata( $patient )->roles );
        self::assertSame( '', (string) get_user_meta( $patient, 'cpms_previous_role', true ), 'no parked role meta' );
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
        $manager   = $this->makeClinicManager( 'pb_mgr_existing' );
        $patient = $this->makePatientAccount( 'pb_portal_existing' );
        $email   = (string) get_userdata( $patient )->user_email;

        $membershipsBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
            [ $patient ]
        );

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
            $membershipsBefore,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
                [ $patient ]
            ),
            'no membership may be added'
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
}

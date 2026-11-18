<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Admin\RoleCapabilitiesPage;
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
     * @param bool          $is_regex Match $needle as a REGEX instead of a
     *                                substring, so ONE query shape can be broken
     *                                (H4(c): the manager-authorization read uses the
     *                                same table, so a table-wide break masks the
     *                                guard under test).
     */
    private function failReadsMatching( string $needle, ?callable $onlyWhen = null, bool $is_regex = false ): void
    {
        global $wpdb;

        self::assertNull( $this->queryFault, 'the read fault must not be installed twice' );

        $fault = static function ( $query ) use ( $needle, $onlyWhen, $is_regex ) {
            $sql = (string) $query;
            if ( $onlyWhen !== null && true !== $onlyWhen() ) {
                return $query;
            }
            $hit = $is_regex ? 1 === preg_match( $needle, $sql ) : false !== strpos( $sql, $needle );
            if ( 1 === preg_match( '/\A\s*SELECT/i', $sql ) && $hit ) {
                return 'SELECT cpms_fault_injection_no_such_column FROM cpms_fault_injection';
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
            'SELECT id, role_key, status, scope_mode FROM ' . App::db()->table( 'cpms_clinic_memberships' )
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
            'no membership may be added to the patient account in THIS Clinic'
        );
        // G10(b): the total across ALL Clinics is asserted too, so a membership
        // added anywhere (not only in the selected Clinic) is caught.
        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
                [ $patient ]
            ),
            'no membership may be added to the patient account in ANY Clinic'
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

        // (c) management portal: enrollment stays denied in ANOTHER Clinic too.
        //     G10(a): the same-Clinic arm was vacuous — the existing membership
        //     rejects it first — so the target must hold NO membership there.
        $otherClinic = $this->createClinic();
        $manager     = $this->makeClinicManager( 'pb_mgr_parked' );
        cpms_test_seed_membership( $manager, $otherClinic, RolesAndCapabilities::ROLE_MANAGER );
        self::assertSame( 0, $this->membershipCount( $otherClinic, $target ) );

        self::assertFalse(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_enroll',
                [
                    'clinic_id'  => (string) $otherClinic,
                    'identifier' => $email,
                    'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
                ]
            )['ok'],
            'Fails if the parked-patient-role evidence read is removed from PatientAccountPolicy'
        );
        self::assertSame( 0, $this->membershipCount( $otherClinic, $target ) );
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

    // ==================================================================
    // 10. G1-G8 — additional evidence sources and widening guards
    // ==================================================================

    /**
     * G1: the roles array comes from the capabilities usermeta; a failed read
     * there must not look like "no patient role". Fails if the strict
     * capabilities-usermeta read is removed from PatientAccountPolicy.
     */
    public function test_failed_capabilities_usermeta_read_is_patient_evidence(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser( 'subscriber', 'pb_fault_capsrow' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $email = (string) get_userdata( $staff )->user_email;

        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );

        $this->failReadsMatching( 'capabilities' );

        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $staff ),
            'a failed capabilities-usermeta read is patient evidence'
        );

        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $staff,
                'display_name' => 'کارمند خرابی',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'FaultCapsRowPass123',
            ],
            $admin
        );
        self::assertNotSame( '', $update['error'], 'the update must be refused while the role read fails' );
        $this->restoreReads();
    }

    /**
     * G2: an Organization-level identity link alone is patient evidence.
     * Fails if has_patient_identity_link() is removed from the union.
     */
    public function test_identity_link_only_account_is_refused_on_every_staff_path(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_identity' );
        $target  = $this->makeUser( 'subscriber', 'pb_identity_only' );
        $this->insertIdentityLink( $target );
        $email = (string) get_userdata( $target )->user_email;

        self::assertTrue( PatientAccountPolicy::is_patient_account( $target ) );

        // (a) portal enrollment in a Clinic where the account has NO membership.
        self::assertFalse(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_enroll',
                [
                    'clinic_id'  => (string) $this->clinicId,
                    'identifier' => $email,
                    'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
                ]
            )['ok'],
            'Fails if the identity-link evidence source is removed'
        );
        self::assertSame( 0, $this->membershipCount( $this->clinicId, $target ) );

        // (b) credential path. H4(b): the target needs a durable membership in this
        // Clinic, otherwise StaffManagementPage refuses it as "not manageable"
        // (StaffManagementPage.php:1271-1275) and the arm proves nothing about the
        // patient guard. The identity link stays the ONLY patient evidence.
        cpms_test_seed_membership( $target, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertSame( 1, $this->membershipCount( $this->clinicId, $target ) );
        $admin  = $this->makeAdmin();
        $update = StaffManagementPage::upsertUser(
            [
                'clinic_id'    => $this->clinicId,
                'mode'         => 'update',
                'user_id'      => $target,
                'display_name' => 'بیمار',
                'email'        => $email,
                'role'         => RolesAndCapabilities::ROLE_SECRETARY,
                'password'     => 'IdentityOnlyPass123',
            ],
            $admin
        );
        self::assertNotSame( '', $update['error'], 'the credential path must refuse an identity-linked account' );
        self::assertTrue(
            wp_check_password( 'InitialStaffPass123', $this->passwordHash( $target ) ),
            'the password must be unchanged'
        );

        // (c) the same read failing is patient evidence.
        $staff = $this->makeUser( 'subscriber', 'pb_fault_identity' );
        cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );
        $this->failReadsMatching( 'cpms_patient_identity_links' );
        self::assertTrue( PatientAccountPolicy::is_patient_account( $staff ) );
        $this->restoreReads();

        // (d) a non-patient control is unaffected.
        self::assertFalse( PatientAccountPolicy::is_patient_account( $staff ) );
    }

    /**
     * G4: the patient role in ANY position, and across two Clinic suspensions.
     * Fails if the merged parked-roles meta or the all-roles merge is removed.
     */
    public function test_parked_roles_survive_role_order_and_a_second_clinic_suspension(): void
    {
        $admin  = $this->makeAdmin();
        $target = $this->makeUser( RolesAndCapabilities::ROLE_SECRETARY, 'pb_parked_two_clinics' );
        // Staff role FIRST, patient role SECOND: $user->roles[0] is the staff role.
        $user = get_userdata( $target );
        $user->add_role( RolesAndCapabilities::ROLE_PATIENT );
        cpms_test_seed_membership( $target, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $otherClinic = $this->createClinic();
        cpms_test_seed_membership( $target, $otherClinic, RolesAndCapabilities::ROLE_SECRETARY );
        // The acting administrator suspends in BOTH Clinics, so it needs durable
        // staff-management authority in the second one as well.
        cpms_test_seed_membership( $admin, $otherClinic, RolesAndCapabilities::ROLE_MANAGER );

        // First suspension (this Clinic) parks both roles.
        self::assertSame( '', StaffManagementPage::toggleUser( $target, 'deactivate', $admin, $this->clinicId )['error'] );
        self::assertSame( [ 'cpms_patient', 'cpms_secretary' ], self::parkedRoles( $target ) );
        self::assertTrue( PatientAccountPolicy::is_patient_account( $target ) );

        // Second suspension (another Clinic) must MERGE, never overwrite.
        self::assertSame( '', StaffManagementPage::toggleUser( $target, 'deactivate', $admin, $otherClinic )['error'] );
        self::assertSame( [ 'cpms_patient', 'cpms_secretary' ], self::parkedRoles( $target ), 'a second suspension must merge' );

        self::assertTrue( PatientAccountPolicy::is_patient_account( $target ) );
        self::assertSame(
            PatientAccountPolicy::DENIAL_MESSAGE,
            StaffManagementPage::toggleUser( $target, 'activate', $admin, $this->clinicId )['error'],
            'Fails if the parked-roles evidence read is removed'
        );
    }

    /**
     * G5: CLEAR of an explicit DENY widens a patient member; a failed prior-read
     * refuses. Fails if capability_effect_strict() or the widening guard is removed.
     */
    public function test_clear_of_an_explicit_deny_is_refused_for_a_patient_member(): void
    {
        $manager    = $this->makeClinicManager( 'pb_mgr_clear' );
        $patient    = $this->makeUser( 'subscriber', 'pb_clear_patient' );
        $this->insertPatientLink( $patient );
        $membership = cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $deny = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'deny',
            ]
        );
        self::assertTrue( $deny['ok'], 'a DENY only narrows and stays allowed' );

        $clear = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'clear',
            ]
        );
        self::assertFalse( $clear['ok'], 'clearing a DENY would widen a patient member' );
        self::assertSame(
            [ [ 'capability' => RolesAndCapabilities::CONFIG, 'effect' => 'deny' ] ],
            $this->capabilityOverrides( $membership ),
            'the DENY must survive the refused CLEAR'
        );

        // The prior override read failing must refuse too (unknown != "no DENY").
        // MUTATION THAT TURNS THIS RED: removing capability_effect_strict() (or
        // falling back to the soft reader) makes the CLEAR succeed and this
        // assertion fail. H4(c): the fault targets ONLY the portal's strict read
        // (`WHERE membership_id = %d` with no ORDER BY); a table-wide fault would
        // break the manager-authorization read (AuthorizationService.php:97) first
        // and the arm would pass for the wrong reason.
        $this->failReadsMatching( '/SELECT capability, effect FROM \S+ WHERE membership_id = \d+\z/', null, true );
        $faulted = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'clear',
            ]
        );
        $this->restoreReads();
        self::assertFalse( $faulted['ok'], 'Fails if the strict prior-override read is removed' );
    }

    /**
     * G6: a patient member is never widened from Location scope to clinic-wide
     * or by adding Locations; narrowing stays allowed. Fails if the G6 widening
     * guard in set_staff_locations() is removed.
     */
    public function test_patient_member_scope_can_only_narrow(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_scope' );
        $patient = $this->makeUser( 'subscriber', 'pb_scope_patient' );
        $this->insertPatientLink( $patient );
        $membership = cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $location_id = $this->createLocation();

        App::membership_service()->set_scope_mode( $membership, 'location', [ $location_id ] );

        // Widening: Location-scoped -> clinic-wide.
        self::assertFalse(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_locations',
                [
                    'clinic_id'     => (string) $this->clinicId,
                    'membership_id' => (string) $membership,
                    'location_ids'  => '',
                ]
            )['ok'],
            'clinic-wide scope would widen a patient member'
        );
        self::assertSame( 'location', (string) $this->membershipRow( $membership )['scope_mode'] );

        // Widening: adding another Location.
        $second_location = $this->createLocation();
        self::assertFalse(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_locations',
                [
                    'clinic_id'     => (string) $this->clinicId,
                    'membership_id' => (string) $membership,
                    'location_ids'  => $location_id . ',' . $second_location,
                ]
            )['ok'],
            'adding a Location would widen a patient member'
        );
        self::assertSame( [ $location_id ], array_values( array_map( 'intval', App::membership_service()->membership_location_ids( $membership ) ) ) );

        // Narrowing to the SAME set stays allowed (no widening).
        self::assertTrue(
            ManagementPortalController::dispatch(
                $manager,
                'clinic_staff_locations',
                [
                    'clinic_id'     => (string) $this->clinicId,
                    'membership_id' => (string) $membership,
                    'location_ids'  => (string) $location_id,
                ]
            )['ok'],
            'an identical (non-widening) scope change stays allowed'
        );
    }

    /**
     * G7: a patient account is never linked to a clinician profile from the
     * portal. Fails if the portal clinician-link guard is removed.
     */
    public function test_patient_account_is_never_linked_to_a_clinician_profile(): void
    {
        $manager = $this->makeClinicManager( 'pb_mgr_clinician' );
        $patient = $this->makeUser( 'subscriber', 'pb_clinician_patient' );
        $this->insertPatientLink( $patient );
        cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_clinician_register',
            [
                'clinic_id'    => (string) $this->clinicId,
                'target_user_id' => (string) $patient,
                'full_name'    => 'پزشک بیمار',
                'specialty'    => '',
            ]
        );

        self::assertFalse( $result['ok'], 'a patient account is never a clinician identity' );
        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d',
                [ $patient ]
            )
        );
    }

    /**
     * G8: a patient account never claims first-practice manager authority even
     * holding manage_options.
     *
     * H4(a) — the fixture must be ELIGIBLE for claim(): exactly ONE Clinic, no
     * membership in it, no first-setup marker. The earlier version created an extra
     * Clinic, so `not_available` was thrown even with the actor guard removed.
     *
     * MUTATION THAT TURNS THIS RED: removing the `is_patient_account()` guard in
     * FirstPracticeSetupService::claim() — the patient actor then CLAIMS the
     * membership, so `reason_code()` and the "nothing written" assertions fail.
     */
    public function test_patient_actor_cannot_claim_first_practice_manager_membership(): void
    {
        $actor = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->userIds[] = $actor;
        $this->insertPatientLink( $actor );
        wp_set_current_user( $actor );

        // Single-Clinic, unclaimed installation: the ONLY reason claim() can refuse
        // below is the patient-actor guard.
        App::db()->execute(
            'DELETE FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id <> %d',
            [ $this->clinicId ]
        );
        self::assertSame( 1, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinics' ) ) );
        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
                [ $this->clinicId ]
            )
        );
        self::assertNull(
            App::db()->fetchValue(
                'SELECT first_setup_claimed_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
                [ $this->clinicId ]
            )
        );

        try {
            App::first_practice_setup_service()->claim( $actor, $this->clinicId, 'کلینیک بیمار' );
            self::fail( 'a patient actor must not claim first-practice authority' );
        } catch ( FirstPracticeSetupException $e ) {
            self::assertSame( FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR, $e->reason_code() );
        }

        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
                [ $actor ]
            ),
            'nothing may be written for a refused actor'
        );
        self::assertNull(
            App::db()->fetchValue(
                'SELECT first_setup_claimed_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
                [ $this->clinicId ]
            ),
            'the first-setup marker must stay unset'
        );

        // Staff control on the SAME fixture: a non-patient administrator really
        // receives the manager membership, so the refusal above is the guard.
        $staffAdmin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->userIds[] = $staffAdmin;
        wp_set_current_user( $staffAdmin );
        $claimed = App::first_practice_setup_service()->claim( $staffAdmin, $this->clinicId, 'کلینیک کارکنان' );
        self::assertGreaterThan( 0, (int) ( $claimed['membership_id'] ?? 0 ) );
        self::assertSame( 1, $this->membershipCount( $this->clinicId, $staffAdmin ) );
    }

    /** @return list<string> */
    private static function parkedRoles( int $userId ): array {
        $raw     = (string) get_user_meta( $userId, 'cpms_parked_roles', true );
        $decoded = json_decode( $raw, true );

        return is_array( $decoded ) ? array_map( 'strval', $decoded ) : [];
    }

    private function insertIdentityLink( int $wpUserId ): void {
        global $wpdb;

        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_identities'
                . ' (organization_id, internal_ref, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, %s)',
                (int) $wpdb->get_var( 'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' ),
                'REF-' . bin2hex( random_bytes( 4 ) ),
                $now,
                $now
            )
        );
        $identityId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_identity_links'
                . ' (organization_id, identity_id, wp_user_id, mobile_at_link, linked_at)'
                . ' VALUES (%d, %d, %d, %s, %s)',
                (int) $wpdb->get_var( 'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' ),
                $identityId,
                $wpUserId,
                '09120000000',
                $now
            )
        );
    }

    private function createLocation(): int {
        global $wpdb;

        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations'
                . ' (clinic_id, name, slug, timezone, is_active, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, %s, 1, %s, %s)',
                $this->clinicId,
                'Loc ' . bin2hex( random_bytes( 3 ) ),
                'loc-' . bin2hex( random_bytes( 3 ) ),
                'UTC',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    // ==================================================================
    // 11. H1/H2 — parking must PERSIST, and presets must not widen members
    // ==================================================================

    /**
     * H1: the suspension must prove the parked evidence is PERSISTED before it
     * downgrades the WordPress role, and it must not write anything when that
     * evidence cannot be read.
     *
     * MUTATION THAT TURNS THIS RED: dropping require_parked_evidence_persisted()
     * from the suspend transaction — the role is then downgraded to `subscriber`
     * while nothing was parked, and the account loses its only patient evidence.
     */
    public function test_suspension_refuses_when_the_parked_evidence_is_not_persisted(): void
    {
        $admin  = $this->makeAdmin();
        $target = $this->makeUser( RolesAndCapabilities::ROLE_PATIENT, 'pb_h1_persist' );
        $user   = get_userdata( $target );
        $user->add_role( RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $target, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        // The parked-roles write is short-circuited: update_user_meta() returns
        // false and NOTHING is persisted, exactly like a failed write.
        $shortCircuit = static function ( $check, $object_id, $meta_key ) {
            if ( 'cpms_parked_roles' === (string) $meta_key ) {
                return false;
            }

            return $check;
        };
        add_filter( 'update_user_metadata', $shortCircuit, 10, 3 );

        $result = StaffManagementPage::toggleUser( $target, 'deactivate', $admin, $this->clinicId );

        remove_filter( 'update_user_metadata', $shortCircuit, 10 );

        self::assertNotSame( '', $result['error'], 'a suspension whose evidence did not persist must refuse' );
        self::assertSame(
            'active',
            (string) App::membership_service()->membership_for( $this->clinicId, $target )['status'],
            'the membership must not be suspended'
        );
        self::assertContains(
            RolesAndCapabilities::ROLE_PATIENT,
            array_values( (array) get_userdata( $target )->roles ),
            'the live patient role must survive a refused suspension'
        );
        self::assertTrue( PatientAccountPolicy::is_patient_account( $target ) );
        self::assertSame(
            PatientAccountPolicy::DENIAL_MESSAGE,
            StaffManagementPage::toggleUser( $target, 'activate', $admin, $this->clinicId )['error']
        );
    }

    /**
     * H1: a failed READ of the parked evidence refuses the suspension with nothing
     * written (the merged list cannot be computed without it).
     *
     * MUTATION THAT TURNS THIS RED: restoring get_user_meta() as the merge source —
     * the soft read returns "" on the broken query and the suspension proceeds.
     */
    public function test_suspension_refuses_when_the_parked_evidence_cannot_be_read(): void
    {
        $admin  = $this->makeAdmin();
        $target = $this->makeUser( RolesAndCapabilities::ROLE_PATIENT, 'pb_h1_read' );
        cpms_test_seed_membership( $target, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $this->failReadsMatching( 'cpms_parked_roles' );
        $result = StaffManagementPage::toggleUser( $target, 'deactivate', $admin, $this->clinicId );
        $this->restoreReads();

        self::assertNotSame( '', $result['error'], 'an unreadable evidence row must refuse the suspension' );
        self::assertSame(
            'active',
            (string) App::membership_service()->membership_for( $this->clinicId, $target )['status']
        );
        self::assertSame(
            [ RolesAndCapabilities::ROLE_PATIENT ],
            array_values( (array) get_userdata( $target )->roles )
        );
    }

    /**
     * H2: widening a BUILT-IN preset is refused while an active holder of that role
     * is a patient/mixed account; a role with no such holder is unaffected.
     *
     * MUTATION THAT TURNS THIS RED: removing assert_widening_has_no_patient_holder()
     * from apply_capability_matrix() — the patient member then gains the new
     * capability through the preset.
     */
    public function test_matrix_edit_refuses_widening_a_preset_held_by_a_patient(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makeUser( 'subscriber', 'pb_h2_matrix' );
        $this->insertPatientLink( $patient );
        cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        // A capability the secretary preset does NOT already hold (dynamic, so the
        // arm cannot go vacuous if a template changes).
        $widening = $this->capabilityOutside( RolesAndCapabilities::ROLE_SECRETARY );
        $matrix   = $this->matrixWithCurrentCaps();
        $matrix[ RolesAndCapabilities::ROLE_SECRETARY ][] = $widening;

        $before = RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY );

        try {
            RolesCapabilitiesPage::apply_capability_matrix( $matrix, $admin );
            self::fail( 'widening a preset held by a patient must be refused' );
        } catch ( \RuntimeException $e ) {
            self::assertNotSame( '', $e->getMessage() );
        }

        self::assertSame(
            $before,
            RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ),
            'the preset must be unchanged after the refusal'
        );

        // Control: the same widening on a role with no patient holder applies.
        $controlWidening = $this->capabilityOutside( RolesAndCapabilities::ROLE_ACCOUNTANT );
        $control         = $this->matrixWithCurrentCaps();
        $control[ RolesAndCapabilities::ROLE_ACCOUNTANT ][] = $controlWidening;
        RolesCapabilitiesPage::apply_capability_matrix( $control, $admin );
        self::assertTrue(
            true === ( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT )[ $controlWidening ] ?? null ),
            'a preset without a patient holder may still be widened'
        );
    }

    /**
     * H2: a template RESET that would widen a currently narrowed preset is refused
     * while a patient/mixed holder exists.
     *
     * MUTATION THAT TURNS THIS RED: removing assert_widening_has_no_patient_holder()
     * from reset_role_to_template().
     */
    public function test_template_reset_refuses_widening_a_preset_held_by_a_patient(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makeUser( 'subscriber', 'pb_h2_reset' );
        $this->insertPatientLink( $patient );
        cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        // Narrow the preset first: the reset would restore (widen) it.
        $narrowed = array_values(
            array_diff(
                array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ) ) ),
                [ RolesAndCapabilities::PAYMENT_CREATE ]
            )
        );
        self::assertTrue( RolesAndCapabilities::setRoleCaps( RolesAndCapabilities::ROLE_SECRETARY, $narrowed ) );
        $before = RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY );
        self::assertFalse( true === ( $before[ RolesAndCapabilities::PAYMENT_CREATE ] ?? null ) );

        try {
            RolesCapabilitiesPage::reset_role_to_template( RolesAndCapabilities::ROLE_SECRETARY );
            self::fail( 'a reset that widens a preset held by a patient must be refused' );
        } catch ( \RuntimeException $e ) {
            self::assertNotSame( '', $e->getMessage() );
        }

        self::assertSame( $before, RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ) );

        // Control: the same reset on a role with no patient holder is allowed and
        // really restores the template (mirror image of the refusal above).
        $accountantCaps = array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT ) ) );
        self::assertNotSame( [], $accountantCaps );
        $droppedCap = (string) $accountantCaps[0];
        RolesAndCapabilities::setRoleCaps(
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            array_values( array_diff( $accountantCaps, [ $droppedCap ] ) )
        );
        self::assertFalse( true === ( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT )[ $droppedCap ] ?? null ) );

        RolesCapabilitiesPage::reset_role_to_template( RolesAndCapabilities::ROLE_ACCOUNTANT );
        self::assertTrue(
            true === ( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT )[ $droppedCap ] ?? null ),
            'a preset without a patient holder resets to its template'
        );
    }

    /**
     * The full role_caps payload the admin form submits, with every preset at its
     * CURRENT capability set (so only the explicit change under test happens).
     *
     * @return array<string, list<string>>
     */
    /**
     * A capability that the given built-in preset does NOT currently grant.
     */
    private function capabilityOutside( string $role ): string {
        $current    = array_keys( array_filter( RolesAndCapabilities::capsMap( $role ) ) );
        $candidates = array_values( array_diff( RolesAndCapabilities::ALL_CAPS, $current ) );

        self::assertNotSame( [], $candidates, 'the preset must not already hold every capability' );

        return (string) $candidates[0];
    }

    private function matrixWithCurrentCaps(): array {
        $matrix = [];
        $roles  = [
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            RolesAndCapabilities::ROLE_MANAGER,
        ];

        foreach ( $roles as $role ) {
            $matrix[ $role ] = array_keys( array_filter( RolesAndCapabilities::capsMap( $role ) ) );
        }

        return $matrix;
    }
}

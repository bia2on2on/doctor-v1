<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Application\Patients\PatientAccountPolicy;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
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
        // B5 — the fault must survive a change of READER SHAPE: it matches ANY
        // SELECT on the capability rows of THIS membership (the strict reader and the
        // soft capabilities_for() alike, with or without ORDER BY), so swapping the
        // reader cannot dodge it. It is scoped to the target id, so the manager's own
        // authorization read is never faulted first.
        $this->failReadsMatching(
            '/FROM \S*cpms_membership_capabilities\b[^;]*\bWHERE\s+membership_id = ' . (int) $membership . '\b/s',
            null,
            true
        );
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

        // This installation is multi-Clinic and may already be claimed, so the
        // eligibility branch is not reachable from here. The patient-actor guard runs
        // BEFORE it: with the guard the refusal is NOT_ADMINISTRATOR, without it
        // claim() walks on and reports NOT_AVAILABLE.
        // MUTATION THAT TURNS THIS RED: deleting the is_patient_account() gate in
        // FirstPracticeSetupService::claim().
        $marker_before = App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicId ]
        );
        $memberships_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
            [ $actor ]
        );

        try {
            App::first_practice_setup_service()->claim( $actor, $this->clinicId, 'کلینیک بیمار' );
            self::fail( 'a patient actor must not claim first-practice authority' );
        } catch ( FirstPracticeSetupException $e ) {
            self::assertSame( FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR, $e->reason_code() );
            self::assertNotSame( FirstPracticeSetupException::CODE_NOT_AVAILABLE, $e->reason_code() );
        }

        self::assertSame(
            $memberships_before,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d',
                [ $actor ]
            ),
            'nothing may be written for a refused actor'
        );
        self::assertSame(
            $marker_before,
            App::db()->fetchValue(
                'SELECT first_setup_claimed_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
                [ $this->clinicId ]
            ),
            'the first-setup marker must stay unset'
        );

        // Staff control on the SAME fixture: a non-patient administrator is never
        // refused as a patient. Either it really claims (eligible installation) or it
        // stops at eligibility — never at the patient guard.
        $staffAdmin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->userIds[] = $staffAdmin;
        wp_set_current_user( $staffAdmin );

        try {
            $claimed = App::first_practice_setup_service()->claim( $staffAdmin, $this->clinicId, 'کلینیک کارکنان' );
            self::assertGreaterThan( 0, (int) ( $claimed['membership_id'] ?? 0 ) );
            self::assertSame( 1, $this->membershipCount( $this->clinicId, $staffAdmin ) );
        } catch ( FirstPracticeSetupException $e ) {
            self::assertNotSame(
                FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR,
                $e->reason_code(),
                'a staff administrator must never be mistaken for a patient'
            );
        }
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
            RoleCapabilitiesPage::apply_capability_matrix( $matrix, $admin );
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
        RoleCapabilitiesPage::apply_capability_matrix( $control, $admin );
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
            RoleCapabilitiesPage::reset_role_to_template( RolesAndCapabilities::ROLE_SECRETARY );
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

        RoleCapabilitiesPage::reset_role_to_template( RolesAndCapabilities::ROLE_ACCOUNTANT );
        self::assertTrue(
            true === ( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT )[ $droppedCap ] ?? null ),
            'a preset without a patient holder resets to its template'
        );
    }

    /**
     * R2: the target key is ABSENT from the locked override row and a stale, wider copy
     * sits in the cache. The before-state must be the template, so the widening is
     * refused and nothing is persisted.
     *
     * MUTATION THAT TURNS THIS RED: restoring capsMap() in the absent-key branch of
     * caps_from_snapshot() — the helper then returns the cached wider set instead of the
     * template (asserted directly below through the helper's own contract), and the
     * public path persists the very widening this arm requests.
     */
    public function test_preset_before_state_with_an_absent_override_ignores_a_stale_cache(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makeUser( 'subscriber', 'pb_r2_patient' );
        $this->insertPatientLink( $patient );
        cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT );

        self::assertArrayNotHasKey(
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            (array) get_option( RolesAndCapabilities::OPTION_OVERRIDE, [] ),
            'fixture: the accountant preset must have NO durable override'
        );

        $input = $this->matrixWithCurrentCaps();
        $extra = $this->capabilityOutside( RolesAndCapabilities::ROLE_ACCOUNTANT );
        $wide  = array_values( array_unique( array_merge( RolesAndCapabilities::ACCOUNTANT_CAPS, [ $extra ] ) ) );

        // The stale, WIDER copy exists only in the object cache.
        $cached = [ RolesAndCapabilities::ROLE_ACCOUNTANT => $wide ];
        wp_cache_set( RolesAndCapabilities::OPTION_OVERRIDE, $cached, 'options' );
        $alloptions = wp_load_alloptions();
        $alloptions[ RolesAndCapabilities::OPTION_OVERRIDE ] = $cached;
        wp_cache_set( 'alloptions', $alloptions, 'options' );

        // The helper's contract on this exact input: key ABSENT from the locked snapshot.
        $helper = new \ReflectionMethod( RoleCapabilitiesPage::class, 'caps_from_snapshot' );
        $helper->setAccessible( true );
        $before_caps = (array) $helper->invoke( null, RolesAndCapabilities::ROLE_ACCOUNTANT, [] );
        self::assertNotContains(
            $extra,
            $before_caps,
            'the absent-key before-state must be the TEMPLATE, never the stale cached set'
        );

        $input[ RolesAndCapabilities::ROLE_ACCOUNTANT ] = $wide;

        try {
            RoleCapabilitiesPage::apply_capability_matrix( $input, $admin );
            self::fail( 'widening a preset held by a patient must be refused' );
        } catch ( \RuntimeException $e ) {
            self::assertNotSame( '', $e->getMessage() );
        }

        self::assertArrayNotHasKey(
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            (array) get_option( RolesAndCapabilities::OPTION_OVERRIDE, [] ),
            'no override may be persisted for the refused widening'
        );
    }

    /**
     * R1: a preset write must be built from the LOCKED row, so a stale wider override of
     * a role this request never touched is never written back.
     *
     * MUTATION THAT TURNS THIS RED: persisting through RolesAndCapabilities::setRoleCaps()
     * (which rebuilds the option from get_option()) AFTER removing the cache flush in
     * locked_override_snapshot() — the cached wider MANAGER entry is then written back and
     * CONFIG reappears in EVERY persisted map, including the first one the loop writes.
     *
     * Why the write is captured: the matrix walks the presets manager-LAST, so a final
     * end-state assertion alone can pass for the wrong reason (the last role write would
     * overwrite a stale wider entry the mid-loop writer had persisted). The capture below
     * inspects every payload that reaches update_option(), so no intermediate write may
     * ever carry the stale set. With the flush in place the snapshot already protects the
     * end state, so this property is guarded twice and the comment says so.
     */
    public function test_preset_write_never_restores_another_roles_stale_override(): void
    {
        $admin = $this->makeAdmin();

        $manager_caps = array_values(
            array_diff(
                array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_MANAGER ) ) ),
                [ RolesAndCapabilities::CONFIG ]
            )
        );
        self::assertTrue( RolesAndCapabilities::setRoleCaps( RolesAndCapabilities::ROLE_MANAGER, $manager_caps ) );

        // The request is built from DURABLE state, before any stale cache exists.
        $input = $this->matrixWithCurrentCaps();
        $input[ RolesAndCapabilities::ROLE_DOCTOR ] = array_values(
            array_unique( array_merge( RolesAndCapabilities::DOCTOR_CAPS, [ RolesAndCapabilities::EXPORT ] ) )
        );

        // A stale WIDER copy of the MANAGER preset (which this request never touches).
        $cached = [ RolesAndCapabilities::ROLE_MANAGER => array_values( array_unique( array_merge( $manager_caps, [ RolesAndCapabilities::CONFIG ] ) ) ) ];
        wp_cache_set( RolesAndCapabilities::OPTION_OVERRIDE, $cached, 'options' );
        $alloptions = wp_load_alloptions();
        $alloptions[ RolesAndCapabilities::OPTION_OVERRIDE ] = $cached;
        wp_cache_set( 'alloptions', $alloptions, 'options' );

        // R2 — capture every payload that reaches the writer, so a mid-request stale
        // write cannot hide behind the manager-last ordering of the preset loop.
        $captured = [];
        $capture  = static function ( $value ) use ( &$captured ) {
            $captured[] = $value;

            return $value;
        };
        $hook = 'pre_update_option_' . RolesAndCapabilities::OPTION_OVERRIDE;
        add_filter( $hook, $capture, 10, 1 );

        RoleCapabilitiesPage::apply_capability_matrix( $input, $admin );

        remove_filter( $hook, $capture, 10 );

        self::assertNotSame( [], $captured, 'the override write must be observed' );
        foreach ( $captured as $written ) {
            self::assertNotContains(
                RolesAndCapabilities::CONFIG,
                (array) ( $written[ RolesAndCapabilities::ROLE_MANAGER ] ?? [] ),
                'no persisted map, not even a mid-request one, may carry the stale wider manager entry'
            );
        }

        $persisted = (array) get_option( RolesAndCapabilities::OPTION_OVERRIDE, [] );
        $manager   = (array) ( $persisted[ RolesAndCapabilities::ROLE_MANAGER ] ?? [] );
        self::assertNotContains(
            RolesAndCapabilities::CONFIG,
            $manager,
            'the stale wider manager override must not be written back'
        );
        self::assertContains(
            RolesAndCapabilities::EXPORT,
            (array) ( $persisted[ RolesAndCapabilities::ROLE_DOCTOR ] ?? [] ),
            'the requested doctor change must be persisted'
        );
    }

    /**
     * R1: a write that does NOT persist must refuse, roll back and commit no success audit.
     *
     * MUTATION THAT TURNS THIS RED: dropping the read-back verification from
     * persist_override_map() — update_option() returns false here, that value is ignored,
     * the transaction commits and the "saved" audit survives a narrowing that never
     * reached the database.
     */
    public function test_preset_write_refuses_when_the_override_does_not_persist(): void
    {
        $admin = $this->makeAdmin();

        $doctor_before = array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_DOCTOR ) ) );
        $audit_before  = $this->auditCount( 'ROLE_PERMISSION_CHANGED' );

        $input = $this->matrixWithCurrentCaps();
        $dropped = RolesAndCapabilities::SEARCH;
        self::assertContains( $dropped, $input[ RolesAndCapabilities::ROLE_DOCTOR ], 'fixture: the narrowing must actually remove something' );
        $input[ RolesAndCapabilities::ROLE_DOCTOR ] = array_values( array_diff( $input[ RolesAndCapabilities::ROLE_DOCTOR ], [ $dropped ] ) );

        // The write silently does nothing: the filter hands update_option() the OLD value.
        $block = static function ( $value, $old_value ) {
            // A non-empty value is replaced by the OLD one: update_option() then sees no
            // change and writes nothing, which is exactly the failed-write case. The value
            // is never cast (it is an array: a cast would raise a warning-exception).
            return is_array( $value ) && [] !== $value ? $old_value : $value;
        };
        $hook = 'pre_update_option_' . RolesAndCapabilities::OPTION_OVERRIDE;
        add_filter( $hook, $block, 10, 2 );

        $refused = false;
        try {
            RoleCapabilitiesPage::apply_capability_matrix( $input, $admin );
        } catch ( \RuntimeException $e ) {
            $refused = '' !== $e->getMessage();
        }
        remove_filter( $hook, $block, 10 );

        self::assertTrue( $refused, 'a write that did not persist must refuse instead of reporting success' );
        self::assertSame( $audit_before, $this->auditCount( 'ROLE_PERMISSION_CHANGED' ), 'no success audit may be committed' );
        self::assertSame(
            $doctor_before,
            array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_DOCTOR ) ) ),
            'permissions must be unchanged after the refused write'
        );
    }

    /**
     * R3 (portal scope): the membership row changes AT its locking read, so the identity
     * check refuses and nothing is written.
     *
     * MUTATION THAT TURNS THIS RED: dropping the clinic/user/role comparison (or the
     * locking strict read) from set_staff_locations() — the scope write then proceeds on
     * the pre-lock view and succeeds.
     */
    public function test_portal_scope_refuses_a_membership_that_moved_at_the_lock(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r3_scope' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r3_scope_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $audit_before = $this->auditCount( 'MEMBERSHIP_SCOPE_CHANGED' );
        $mode_before  = (string) ( $this->membershipRow( $membership )['scope_mode'] ?? '' );

        $state = (object) [ 'fired' => 0, 'done' => false ];
        $inject = static function ( $query ) use ( $state, $membership ) {
            $current = (string) $query;
            if ( false === strpos( $current, 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\\bWHERE id = ' . $membership . '\\b/', $current ) ) {
                return $query;
            }
            ++$state->fired;
            if ( $state->done ) {
                return $query;
            }
            $state->done = true;
            App::db()->query(
                'UPDATE ' . App::db()->table( 'cpms_clinic_memberships' ) . ' SET role_key = %s WHERE id = %d',
                [ RolesAndCapabilities::ROLE_DOCTOR, $membership ]
            );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_locations',
            [ 'clinic_id' => (string) $this->clinicId, 'membership_id' => (string) $membership, 'location_ids' => '' ]
        );

        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $state->fired, 'the locking read must be observed exactly once' );
        self::assertFalse( $result['ok'], 'a membership that moved at the lock must be refused' );
        self::assertSame( $mode_before, (string) ( $this->membershipRow( $membership )['scope_mode'] ?? '' ), 'the scope must not change on a refused write' );
        self::assertSame( $audit_before, $this->auditCount( 'MEMBERSHIP_SCOPE_CHANGED' ), 'no success audit may be committed' );
    }

    /**
     * R3 (portal capability): the same moved-target refusal on the capability path.
     *
     * MUTATION THAT TURNS THIS RED: dropping the identity comparison in
     * set_staff_capability() — the DENY is written for the moved row.
     */
    public function test_portal_capability_refuses_a_membership_that_moved_at_the_lock(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r3_cap' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r3_cap_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $other = $this->makeUser( 'subscriber', 'pb_r3_cap_other' );
        $audit_before = $this->auditCount( 'MEMBERSHIP_CAPABILITY_CHANGED' );
        $state = (object) [ 'fired' => 0, 'done' => false ];
        // R4 — $other is captured: without it the closure raises an undefined-variable
        // warning (an exception in this harness) that looks like a refusal even when the
        // identity guard is gone.
        $inject = static function ( $query ) use ( $state, $membership, $other ) {
            $current = (string) $query;
            if ( false === strpos( $current, 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\\bWHERE id = ' . $membership . '\\b/', $current ) ) {
                return $query;
            }
            ++$state->fired;
            if ( $state->done ) {
                return $query;
            }
            $state->done = true;
            App::db()->query(
                'UPDATE ' . App::db()->table( 'cpms_clinic_memberships' ) . ' SET wp_user_id = %d WHERE id = %d',
                [ $other, $membership ]
            );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $result = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'deny',
            ]
        );

        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $state->fired, 'the locking read must be observed exactly once' );
        self::assertFalse( $result['ok'], 'a membership that moved at the lock must be refused' );
        self::assertSame( [], $this->capabilityOverrides( $membership ), 'nothing may be written for the moved row' );
        self::assertSame( $audit_before, $this->auditCount( 'MEMBERSHIP_CAPABILITY_CHANGED' ), 'no success audit may be committed' );

        // Control: the SAME request without the injected move is accepted, so the refusal
        // above can only come from the identity guard (the injected UPDATE itself was
        // rolled back with the refused transaction, so the row is the seeded one again).
        $control = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => RolesAndCapabilities::CONFIG,
                'effect'        => 'deny',
            ]
        );
        self::assertTrue( $control['ok'], 'control: the same request without the move must be accepted' );
    }

    /**
     * R3 (finance delegate): the target moves at the locking read → controlled refusal.
     *
     * MUTATION THAT TURNS THIS RED: dropping the identity comparison in
     * FinanceDelegationService::locked_target_membership() — the GRANT is written.
     */
    public function test_finance_delegate_refuses_a_membership_that_moved_at_the_lock(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r3_fin' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r3_fin_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $state = (object) [ 'fired' => 0, 'done' => false ];
        $inject = static function ( $query ) use ( $state, $membership ) {
            $current = (string) $query;
            if ( false === strpos( $current, 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\\bWHERE id = ' . $membership . '\\b/', $current ) ) {
                return $query;
            }
            ++$state->fired;
            if ( $state->done ) {
                return $query;
            }
            $state->done = true;
            App::db()->query(
                'UPDATE ' . App::db()->table( 'cpms_clinic_memberships' ) . ' SET role_key = %s WHERE id = %d',
                [ RolesAndCapabilities::ROLE_ACCOUNTANT, $membership ]
            );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $denied = false;
        try {
            App::finance_delegation_service()->delegate( $manager, $this->clinicId, $membership, RolesAndCapabilities::PAYMENT_REFUND );
        } catch ( FinanceException $e ) {
            $denied = 'CLINIC_DELEGATION_DENIED' === $e->errorCode;
        }

        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $state->fired, 'the locking read must be observed exactly once' );
        self::assertTrue( $denied, 'a moved target must be refused with the controlled error' );
        self::assertSame( [], $this->capabilityOverrides( $membership ), 'nothing may be granted to the moved row' );
    }

    /**
     * R3 (finance delegate): patient evidence that appears AT the lock must refuse the
     * grant, because the classification is re-made on the LOCKED user id.
     *
     * MUTATION THAT TURNS THIS RED: dropping the in-boundary
     * PatientAccountPolicy::is_patient_account( $locked['wp_user_id'] ) recheck — the
     * pre-flight read saw no evidence, so the GRANT is written for a now-patient account.
     */
    public function test_finance_delegate_refuses_patient_evidence_that_appears_at_the_lock(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r3_evidence' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r3_evidence_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $audit_before = $this->auditCount( 'FINANCE_DELEGATION_GRANTED' );

        $state = (object) [ 'fired' => 0, 'done' => false ];
        $inject = function ( $query ) use ( $state, $membership, $staff ) {
            $current = (string) $query;
            if ( false === strpos( $current, 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\\bWHERE id = ' . $membership . '\\b/', $current ) ) {
                return $query;
            }
            ++$state->fired;
            if ( $state->done ) {
                return $query;
            }
            $state->done = true;
            $this->insertPatientLink( $staff );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $refused = null;
        try {
            App::finance_delegation_service()->delegate( $manager, $this->clinicId, $membership, RolesAndCapabilities::PAYMENT_REFUND );
        } catch ( FinanceException $e ) {
            $refused = $e;
        }
        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $state->fired, 'the locking read must be observed exactly once' );
        self::assertNotNull( $refused, 'patient evidence at the lock must refuse the grant' );
        self::assertSame( 403, $refused->httpStatus, 'the refusal must be the patient-evidence one (identity refusals are 409)' );
        self::assertSame( [], $this->capabilityOverrides( $membership ), 'no grant may be written' );
        self::assertSame( $audit_before, $this->auditCount( 'FINANCE_DELEGATION_GRANTED' ), 'no success audit may be committed' );
    }

    /**
     * R3 (finance revoke): the same rule on the revocation path.
     *
     * MUTATION THAT TURNS THIS RED: dropping the identity comparison in revoke() — the
     * GRANT is removed for a row the caller never verified.
     */
    public function test_finance_revoke_refuses_a_membership_that_moved_at_the_lock(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r3_rev' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r3_rev_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        App::finance_delegation_service()->delegate( $manager, $this->clinicId, $membership, RolesAndCapabilities::PAYMENT_REFUND );

        $state = (object) [ 'fired' => 0, 'done' => false ];
        $inject = static function ( $query ) use ( $state, $membership ) {
            $current = (string) $query;
            if ( false === strpos( $current, 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\\bWHERE id = ' . $membership . '\\b/', $current ) ) {
                return $query;
            }
            ++$state->fired;
            if ( $state->done ) {
                return $query;
            }
            $state->done = true;
            App::db()->query(
                'UPDATE ' . App::db()->table( 'cpms_clinic_memberships' ) . ' SET role_key = %s WHERE id = %d',
                [ RolesAndCapabilities::ROLE_ACCOUNTANT, $membership ]
            );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $denied = false;
        try {
            App::finance_delegation_service()->revoke( $manager, $this->clinicId, $membership, RolesAndCapabilities::PAYMENT_REFUND );
        } catch ( FinanceException $e ) {
            $denied = 'CLINIC_DELEGATION_DENIED' === $e->errorCode;
        }

        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $state->fired, 'the locking read must be observed exactly once' );
        self::assertTrue( $denied, 'a moved target must be refused with the controlled error' );
        self::assertSame(
            [ [ 'capability' => RolesAndCapabilities::PAYMENT_REFUND, 'effect' => 'grant' ] ],
            $this->capabilityOverrides( $membership ),
            'the grant must survive the refused revocation'
        );
    }

    /**
     * R4: a failed strict read inside the finance boundary must surface as the page's
     * controlled FinanceException, never as a bare RuntimeException.
     *
     * MUTATION THAT TURNS THIS RED: removing the try/catch in
     * locked_target_membership() — the RuntimeException escapes and the FinanceException
     * assertion fails with an error instead of a refusal.
     */
    public function test_finance_delegate_converts_a_failed_locking_read_into_the_controlled_error(): void
    {
        $manager    = $this->makeClinicManager( 'pb_r4_fin' );
        $staff      = $this->makeUser( 'subscriber', 'pb_r4_fin_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        $this->failReadsMatching( 'SELECT id, clinic_id, wp_user_id, role_key, status, scope_mode' );

        $controlled = false;
        $escaped    = false;
        try {
            App::finance_delegation_service()->delegate( $manager, $this->clinicId, $membership, RolesAndCapabilities::PAYMENT_REFUND );
        } catch ( FinanceException $e ) {
            $controlled = 'CLINIC_DELEGATION_DENIED' === $e->errorCode;
        } catch ( \Throwable $e ) {
            $escaped = true;
        }
        $this->restoreReads();

        self::assertFalse( $escaped, 'no bare RuntimeException may escape the finance boundary' );
        self::assertTrue( $controlled, 'a failed locking read must become the controlled FinanceException' );
        self::assertSame( [], $this->capabilityOverrides( $membership ), 'nothing may be written when the read fails' );
    }

    /**
     * R1: the preset before-state comes from the LOCKED override row, never from a
     * cached (consistent-read) copy that can be wider than the durable state.
     *
     * MUTATION THAT TURNS THIS RED: replacing locked_override_snapshot() /
     * caps_from_snapshot() with RolesCapabilities::capsMap() — the primed cache then
     * reports the wide template, the template request looks like "no change", and the
     * widening is written for a preset held by a patient.
     */
    public function test_preset_before_state_ignores_a_stale_cached_override(): void
    {
        $admin   = $this->makeAdmin();
        $patient = $this->makeUser( 'subscriber', 'pb_r1_patient' );
        $this->insertPatientLink( $patient );
        cpms_test_seed_membership( $patient, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );

        // Durable state: the secretary preset is NARROWED (PAYMENT_CREATE removed).
        $narrowed = array_values(
            array_diff(
                array_keys( array_filter( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ) ) ),
                [ RolesAndCapabilities::PAYMENT_CREATE ]
            )
        );
        self::assertTrue( RolesAndCapabilities::setRoleCaps( RolesAndCapabilities::ROLE_SECRETARY, $narrowed ) );

        // A stale WIDER copy sits in the object cache, as a concurrent writer (or a
        // dropped invalidation) could leave it.
        $wide = [ RolesAndCapabilities::ROLE_SECRETARY => RolesAndCapabilities::SECRETARY_CAPS ];
        wp_cache_set( RolesAndCapabilities::OPTION_OVERRIDE, $wide, 'options' );
        $alloptions = wp_load_alloptions();
        $alloptions[ RolesAndCapabilities::OPTION_OVERRIDE ] = $wide;
        wp_cache_set( 'alloptions', $alloptions, 'options' );

        // Requesting the TEMPLATE is a widening against the durable (narrowed) preset.
        $input = $this->matrixWithCurrentCaps();
        // The widening under test: ask for the built-in template, not the narrowed set.
        $input[ RolesAndCapabilities::ROLE_SECRETARY ] = RolesAndCapabilities::SECRETARY_CAPS;

        try {
            RoleCapabilitiesPage::apply_capability_matrix( $input, $admin );
            self::fail( 'restoring a template over a patient holder must be refused' );
        } catch ( \RuntimeException $e ) {
            self::assertNotSame( '', $e->getMessage() );
        }

        self::assertFalse(
            true === ( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY )[ RolesAndCapabilities::PAYMENT_CREATE ] ?? null ),
            'the narrowed preset must be unchanged after the refusal'
        );
    }

    /**
     * A2(i): a role key that members ALREADY hold is never defined, because defining
     * it would activate its capabilities for them at once.
     *
     * MUTATION THAT TURNS THIS RED: removing the member_user_ids_for_role() guard in
     * CustomRoleManagementService::create() — create() then succeeds and the
     * definition row exists.
     */
    public function test_custom_role_create_refuses_a_key_already_held_by_a_member(): void
    {
        $manager = $this->makeClinicManager( 'pb_b2_create' );
        $holder  = $this->makeUser( 'subscriber', 'pb_b2_holder' );
        // A member holding the key BEFORE any definition of it exists.
        cpms_test_seed_membership( $holder, $this->clinicId, 'cpms_orphan_role' );

        $service = App::custom_role_management_service();
        $caps    = array_slice( array_values( (array) $service->delegable_capabilities( $manager, $this->clinicId ) ), 0, 1 );
        self::assertNotSame( [], $caps, 'the fixture needs one delegable capability' );

        try {
            $service->create( $manager, $this->clinicId, 'cpms_orphan_role', 'نقش یتیم', $caps );
            self::fail( 'a role key already held by a member must not be defined' );
        } catch ( CustomRoleManagementException $e ) {
            self::assertSame( CustomRoleManagementException::CODE_KEY_CONFLICT, $e->reason_code() );
        }

        self::assertNull(
            App::db()->fetchValue(
                'SELECT id FROM ' . App::db()->table( 'cpms_custom_role_defs' ) . ' WHERE clinic_id = %d AND role_key = %s',
                [ $this->clinicId, 'cpms_orphan_role' ]
            ),
            'no definition row may be written for a refused key'
        );
    }

    /**
     * A2(ii): reactivating an INACTIVE definition re-grants its stored capabilities,
     * so a patient holder refuses it and nothing is written.
     *
     * MUTATIONS THAT TURN THIS RED (two separate guards, two separate assertions):
     *  - removing the HOLDER LOOP in the activation branch of
     *    CustomRoleManagementService::transition_status() — reactivation succeeds, the
     *    status flips to active and the version increments;
     *  - removing the LOCKING REFERENCE SCAN
     *    (has_membership_references_for_update()) — the scan counter below drops to 0.
     *    R3: the scan's SERIALIZATION (a concurrent holder blocking on the gap lock)
     *    is not observable in this harness — one transaction per test, service
     *    START TRANSACTION rewritten to SAVEPOINT — so the test asserts that the scan
     *    is actually issued, and says so instead of claiming a blocking proof.
     */
    public function test_custom_role_reactivation_refuses_a_patient_holder(): void
    {
        $manager = $this->makeClinicManager( 'pb_b2_react' );
        $patient = $this->makeUser( 'subscriber', 'pb_b2_patient' );
        $this->insertPatientLink( $patient );

        $service = App::custom_role_management_service();
        $caps    = array_slice( array_values( (array) $service->delegable_capabilities( $manager, $this->clinicId ) ), 0, 1 );
        $role_key = 'cpms_ward_role';
        $created  = $service->create( $manager, $this->clinicId, $role_key, 'نقش بخش', $caps );

        $membership = cpms_test_seed_membership( $patient, $this->clinicId, 'cpms_ward_role' );
        self::assertGreaterThan( 0, $membership );

        $inactive = $service->deactivate( $manager, $this->clinicId, 'cpms_ward_role', (int) $created['version'] );
        self::assertSame( 'inactive', (string) $inactive['status'] );

        // Count the locking reference scan this reactivation must issue:
        //   SELECT id FROM <prefix>cpms_clinic_memberships
        //    WHERE clinic_id = N AND role_key = 'cpms_ward_role' ORDER BY id LIMIT 1 FOR UPDATE
        // (CustomRoleRepository::has_membership_references_for_update()).
        $scans = 0;
        $count = static function ( $query ) use ( &$scans, $role_key ) {
            $sql = (string) $query;
            if ( false === stripos( $sql, 'cpms_clinic_memberships' ) || false === stripos( $sql, 'FOR UPDATE' ) ) {
                return $query;
            }
            if ( 1 === preg_match( '/\bAND\s+role_key = \'' . preg_quote( (string) $role_key, '/' ) . '\'\s+ORDER BY id LIMIT 1 FOR UPDATE\b/', $sql ) ) {
                ++$scans;
            }

            return $query;
        };
        add_filter( 'query', $count, 7 );

        try {
            $service->reactivate( $manager, $this->clinicId, $role_key, (int) $inactive['version'] );
            self::fail( 'reactivation must be refused while a patient holds the role' );
        } catch ( CustomRoleManagementException $e ) {
            self::assertSame( CustomRoleManagementException::CODE_MEMBERSHIP_REFERENCES, $e->reason_code() );
        } finally {
            remove_filter( 'query', $count, 7 );
        }

        self::assertSame( 1, $scans, 'the locking reference scan must be issued exactly once (R3)' );

        self::assertSame(
            'inactive',
            (string) App::db()->fetchValue(
                'SELECT status FROM ' . App::db()->table( 'cpms_custom_role_defs' ) . ' WHERE clinic_id = %d AND role_key = %s',
                [ $this->clinicId, 'cpms_ward_role' ]
            ),
            'the definition must stay inactive'
        );
        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_membership_capabilities' ) . ' WHERE membership_id = %d',
                [ $membership ]
            ),
            'no capability row may be written for the holder'
        );
    }

    /**
     * A2(iii) POSITIVE CONTROL for the guard above: the same reactivation with a STAFF
     * holder succeeds, so the refusal in (ii) is the holder policy and not a broken
     * lifecycle.
     *
     * MUTATION THAT TURNS THIS RED: making the holder check refuse unconditionally.
     */
    public function test_custom_role_reactivation_succeeds_with_a_staff_holder(): void
    {
        $manager = $this->makeClinicManager( 'pb_b2_ok' );
        $staff   = $this->makeUser( 'subscriber', 'pb_b2_staff' );

        $service = App::custom_role_management_service();
        $caps    = array_slice( array_values( (array) $service->delegable_capabilities( $manager, $this->clinicId ) ), 0, 1 );
        $created = $service->create( $manager, $this->clinicId, 'cpms_staff_role', 'نقش کارکنان', $caps );

        cpms_test_seed_membership( $staff, $this->clinicId, 'cpms_staff_role' );
        $inactive = $service->deactivate( $manager, $this->clinicId, 'cpms_staff_role', (int) $created['version'] );
        self::assertSame( 'inactive', (string) $inactive['status'] );

        $active = $service->reactivate( $manager, $this->clinicId, 'cpms_staff_role', (int) $inactive['version'] );
        self::assertSame( 'active', (string) $active['status'] );
        self::assertTrue( (bool) $active['changed'], 'a staff-only holder must not block reactivation' );
    }

    /**
     * A3 (within-session simulation): the competing change lands AFTER the portal's
     * pre-flight read and BEFORE its write boundary. The boundary must re-read on the
     * locked row and refuse, instead of clearing a DENY it never observed.
     *
     * A real second connection is impossible here: the harness runs every test inside
     * one transaction and rewrites service START TRANSACTION into SAVEPOINT
     * (tests/integration-bootstrap.php:229-234, :288), so another session cannot see
     * the fixture rows. The competing row is therefore written by a `query` filter in
     * the SAME session, keyed on the LOCKING read's unique SQL shape (never on a
     * guessed read order), and its own rollback is expected: what proves the refusal
     * is that nothing was written or removed by the CLEAR.
     *
     * MUTATION THAT TURNS THIS RED: removing the lock + in-transaction re-read
     * ($state_held) from set_staff_capability() — no locking read is issued (the
     * counter stays 0), the CLEAR deletes the observed GRANT and reports success.
     */
    public function test_portal_capability_write_refuses_when_the_state_moves_in_the_boundary(): void
    {
        $manager    = $this->makeClinicManager( 'pb_b4_move' );
        $staff      = $this->makeUser( 'subscriber', 'pb_b4_staff' );
        $membership = cpms_test_seed_membership( $staff, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        $capability = RolesAndCapabilities::CONFIG;

        $grant = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => $capability,
                'effect'        => 'grant',
            ]
        );
        self::assertTrue( $grant['ok'], 'a staff member may receive a GRANT' );

        // The competing writer lands at the LOCKING read that the write boundary
        // issues for THIS membership — after the state was observed and before it is
        // re-read under the lock. That query is unique in shape:
        //   SELECT id FROM <prefix>cpms_clinic_memberships WHERE id = N LIMIT 1 FOR UPDATE
        // (MembershipRepository::lock_row_for_update() -> CpmsDb::fetchRowForUpdate(),
        // which appends " FOR UPDATE" at CpmsDb.php:345), so no read-order assumption
        // is needed.
        $fired     = 0;
        $injections = 0;
        $inject    = static function ( $query ) use ( &$fired, &$injections, $membership, $capability ) {
            $sql = (string) $query;
            if ( false === stripos( $sql, 'cpms_clinic_memberships' ) || false === stripos( $sql, 'FOR UPDATE' ) ) {
                return $query;
            }
            if ( 1 !== preg_match( '/\bWHERE\s+id = ' . (int) $membership . '\b/', $sql ) ) {
                return $query;
            }
            ++$fired;
            if ( $injections > 0 ) {
                return $query;
            }
            ++$injections;
            App::db()->query(
                'INSERT INTO ' . App::db()->table( 'cpms_membership_capabilities' )
                . ' (membership_id, capability, effect, created_at) VALUES (%d, %s, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE effect = VALUES(effect)',
                [ $membership, $capability, 'deny', App::db()->nowUtcSql() ]
            );

            return $query;
        };
        add_filter( 'query', $inject, 7 );

        $clear = ManagementPortalController::dispatch(
            $manager,
            'clinic_staff_capability',
            [
                'clinic_id'     => (string) $this->clinicId,
                'membership_id' => (string) $membership,
                'capability'    => $capability,
                'effect'        => 'clear',
            ]
        );

        remove_filter( 'query', $inject, 7 );

        self::assertSame( 1, $injections, 'the competing DENY must be written exactly once (non-vacuity guard)' );
        self::assertGreaterThanOrEqual( 1, $fired, 'the filter must have matched the locked-membership read' );
        self::assertFalse( $clear['ok'], 'a CLEAR must not remove a DENY it never observed' );
        // The competing DENY is written INSIDE the transaction, so the refusal's
        // rollback removes it again; what must be intact is the override the CLEAR
        // OBSERVED. Nothing written, nothing removed.
        self::assertSame(
            [ [ 'capability' => $capability, 'effect' => 'grant' ] ],
            $this->capabilityOverrides( $membership ),
            'the refused CLEAR must neither add nor remove a capability row'
        );
    }

    /**
     * B1: the role-holder scan must read EVERY page, so a holder beyond the first
     * page is still visible to the widening guard.
     *
     * MUTATION THAT TURNS THIS RED: reverting member_user_ids_for_role() to one
     * bounded query (a single LIMIT) — the holder on page 2 and 3 disappear and the
     * page counter drops to one read.
     */
    public function test_role_holder_scan_reads_every_page_of_holders(): void
    {
        $repository = new MembershipRepository( App::db() );
        $expected   = array();

        foreach ( array( 0 => 'pb_page_a', 1 => 'pb_page_b', 2 => 'pb_page_c' ) as $index => $login ) {
            $user = $this->makeUser( 'subscriber', $login );
            if ( 1 === $index ) {
                $this->insertPatientLink( $user );
            }
            cpms_test_seed_membership( $user, $this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT );
            $expected[] = (int) $user;
        }
        sort( $expected );

        $reads   = 0;
        $counter = static function ( $query ) use ( &$reads ) {
            $sql = (string) $query;
            if ( false !== stripos( $sql, 'role_key' ) && false !== stripos( $sql, 'cpms_clinic_memberships' ) ) {
                ++$reads;
            }

            return $query;
        };
        add_filter( 'query', $counter, 6 );
        $found = $repository->member_user_ids_for_role( RolesAndCapabilities::ROLE_ACCOUNTANT, 1 );
        remove_filter( 'query', $counter, 6 );

        self::assertSame( $expected, $found, 'every active holder must be returned, in ascending order' );
        self::assertGreaterThan( 2, $reads, 'a one-row page size must page through the holders (no silent truncation)' );
        self::assertTrue(
            PatientAccountPolicy::is_patient_account( $expected[1] ),
            'the patient holder must be visible to the guard, not skipped by a bound'
        );
    }

    /**
     * B3: an override row that cannot be read refuses the preset write; capsMap()'s
     * template fallback would have shown the template and let the write through.
     *
     * MUTATION THAT TURNS THIS RED: replacing locked_override_snapshot() with
     * RolesAndCapabilities::capsMap() (the soft, template-falling-back read).
     */
    public function test_preset_matrix_refuses_when_the_override_row_cannot_be_read(): void
    {
        $admin  = $this->makeAdmin();
        $input  = $this->matrixWithCurrentCaps();
        $before = RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY );

        $this->failReadsMatching( "option_name = 'cpms_role_caps_override'" );
        $threw = false;
        try {
            RoleCapabilitiesPage::apply_capability_matrix( $input, $admin );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->restoreReads();

        self::assertTrue( $threw, 'an unreadable override row must refuse the write, never fall back to the template' );
        self::assertSame(
            $before,
            RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ),
            'nothing may be written when the before-state is unknown'
        );
    }

    /**
     * A capability that the given built-in preset does NOT currently grant.
     */
    private function capabilityOutside( string $role ): string {
        $current    = array_keys( array_filter( RolesAndCapabilities::capsMap( $role ) ) );
        $candidates = array_values( array_diff( RolesAndCapabilities::ALL_CAPS, $current ) );

        self::assertNotSame( [], $candidates, 'the preset must not already hold every capability' );

        return (string) $candidates[0];
    }

    /**
     * The full role_caps payload the admin form submits, with every preset at its
     * CURRENT capability set (so only the explicit change under test happens).
     *
     * @return array<string, list<string>>
     */
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

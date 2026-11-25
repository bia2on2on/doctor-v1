<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use RuntimeException;
use Throwable;
use ClinicCore\Tests\Support\ExternalRaceWorker;
use WP_UnitTestCase;
use ClinicCore\Tests\Support\CustomRoleLockWaitFixture;

require_once dirname(__DIR__) . '/Support/CustomRoleLockWaitFixture.php';

/**
 * Custom Role Management — Slice 4A, reversible deactivation/reactivation.
 *
 * This is integration coverage against the real WordPress/MySQL-backed
 * AuthorizationService, repositories, transaction wrapper, audit log and
 * server-rendered wp-admin surface. It intentionally does not model archive or
 * deletion: existing membership role keys, capabilities and audit history are
 * retained by deactivation.
 */
final class CustomRoleLifecycleTest extends WP_UnitTestCase
{
    use CustomRoleLockWaitFixture;

    private const RACE_SIGNAL_TIMEOUT_SECONDS = 10;
    private const RACE_WAIT_TIMEOUT_SECONDS = 10;
    private const RACE_CHILD_LOCK_WAIT_TIMEOUT_SECONDS = 20;
    private const RACE_POLL_INTERVAL_MICROSECONDS = 20000;
    private int $clinicA;
    private int $clinicB;
    private int $managerA;
    private int $managerB;
    private int $suspendedManager;
    private int $nonMember;
    private int $member;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();

        global $wpdb;
        $organizationId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'an active Organization fixture must exist');

        $this->clinicA = $this->makeClinic($organizationId, 'crlA');
        $this->clinicB = $this->makeClinic($organizationId, 'crlB');
        $this->managerA = $this->makeUser('crl_mgr_a_' . bin2hex(random_bytes(3)));
        $this->managerB = $this->makeUser('crl_mgr_b_' . bin2hex(random_bytes(3)));
        $this->suspendedManager = $this->makeUser('crl_mgr_sus_' . bin2hex(random_bytes(3)));
        $this->nonMember = $this->makeUser('crl_non_' . bin2hex(random_bytes(3)));
        $this->member = $this->makeUser('crl_member_' . bin2hex(random_bytes(3)));

        App::membership_service()->create_membership($this->clinicA, $this->managerA, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicB, $this->managerB, RolesAndCapabilities::ROLE_MANAGER);
        $suspendedId = App::membership_service()->create_membership($this->clinicA, $this->suspendedManager, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($suspendedId);
        App::membership_service()->create_membership($this->clinicA, $this->member, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($this->managerA);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Authorized deactivation: status only; memberships and explicit effects
    // remain in place, no WordPress-global role mutation.
    // ---------------------------------------------------------------------

    public function testAuthorizedManagerDeactivatesRoleWithoutChangingMembershipOverridesOrWordPressRoles(): void
    {
        $role = $this->createRole('crl_staff', ['cpms_patient_read', 'cpms_search']);
        $membershipRepository = new MembershipRepository(App::db());
        $memberMembership = $membershipRepository->find($this->clinicA, $this->member);
        self::assertNotNull($memberMembership);
        $membershipId = (int) $memberMembership['id'];
        $membershipRepository->update(['role_key' => 'crl_staff'], ['id' => $membershipId]);
        $membershipRepository->set_capability($membershipId, 'cpms_patient_read', 'deny');
        $membershipRepository->set_capability($membershipId, 'cpms_report_read', 'grant');
        $beforeOverrides = $membershipRepository->capabilities_for($membershipId);
        $beforeRoles = (array) get_userdata($this->member)->roles;

        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'));
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'membership DENY outranks the active role');
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_report_read'), 'an explicit GRANT is independent of the role');

        $result = $this->deactivate($this->managerA, $this->clinicA, 'crl_staff', 1);

        self::assertSame('inactive', $result['status']);
        self::assertSame(2, $result['version']);
        self::assertTrue($result['changed']);
        self::assertTrue(App::authorization_service()->can($this->managerA, $this->clinicA, RolesAndCapabilities::CONFIG));
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'), 'inactive definitions supply no role-derived authority');
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'the explicit DENY remains effective');
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_report_read'), 'the explicit GRANT remains governed by AuthorizationService');

        $storedRole = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_staff');
        self::assertNotNull($storedRole, 'deactivation preserves the custom role definition');
        self::assertSame($role['id'], $storedRole['id']);
        self::assertSame(['cpms_patient_read', 'cpms_search'], $storedRole['capabilities']);
        self::assertSame('crl_staff', (string) App::db()->fetchValue(
            'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$membershipId]
        ), 'deactivation must retain the existing membership role key');
        self::assertSame($beforeOverrides, $membershipRepository->capabilities_for($membershipId), 'GRANT/DENY rows must be unchanged');
        self::assertSame($beforeRoles, (array) get_userdata($this->member)->roles, 'no WordPress-global role may be changed');
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));
    }

    public function testReactivationRestoresOnlyRoleDerivedAuthorityAndKeepsExplicitDenyPrecedence(): void
    {
        $this->createRole('crl_restore', ['cpms_search', 'cpms_patient_read']);
        $membershipRepository = new MembershipRepository(App::db());
        $memberMembership = $membershipRepository->find($this->clinicA, $this->member);
        self::assertNotNull($memberMembership);
        $membershipId = (int) $memberMembership['id'];
        $membershipRepository->update(['role_key' => 'crl_restore'], ['id' => $membershipId]);
        $membershipRepository->set_capability($membershipId, 'cpms_search', 'deny');

        $this->deactivate($this->managerA, $this->clinicA, 'crl_restore', 1);
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'));
        $result = $this->reactivate($this->managerA, $this->clinicA, 'crl_restore', 2);

        self::assertSame('active', $result['status']);
        self::assertSame(3, $result['version']);
        self::assertTrue($result['changed']);
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'only the persisted role-derived capability returns');
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'), 'an explicit DENY still has precedence');
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $this->definitionId('crl_restore')));
    }

    public function testReactivationRechecksTheActorsCurrentCapabilityDelegationAuthority(): void
    {
        $role = $this->createRole('crl_fresh', ['cpms_patient_read']);
        $this->deactivate($this->managerA, $this->clinicA, 'crl_fresh', 1);
        $managerMembership = (new MembershipRepository(App::db()))->find_active($this->clinicA, $this->managerA);
        self::assertNotNull($managerMembership);
        $memberships = new MembershipRepository(App::db());
        $memberships->set_capability((int) $managerMembership['id'], 'cpms_patient_read', 'deny');
        $auditBefore = $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']);

        $this->expectLifecycleFailure('reactivate', $this->managerA, $this->clinicA, 'crl_fresh', 2, CustomRoleManagementException::class, 'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE');

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_fresh');
        self::assertNotNull($stored);
        self::assertSame('inactive', $stored['status']);
        self::assertSame(2, $stored['version'], 'a refused reactivation cannot advance the version');
        self::assertSame($auditBefore, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));

        $memberships->remove_capability((int) $managerMembership['id'], 'cpms_patient_read');
        $restored = $this->reactivate($this->managerA, $this->clinicA, 'crl_fresh', 2);
        self::assertSame('active', $restored['status']);
        self::assertSame(3, $restored['version']);
    }

    public function testUnauthorizedForeignClinicSuspendedAndNonmemberActorsCannotChangeStatus(): void
    {
        $roleA = $this->createRole('crl_local', ['cpms_search']);
        $this->service()->create($this->managerB, $this->clinicB, 'crl_foreign', 'Foreign role', ['cpms_search']);

        $this->expectLifecycleFailure('deactivate', $this->member, $this->clinicA, 'crl_local', 1, AuthorizationException::class);
        $this->expectLifecycleFailure('deactivate', $this->nonMember, $this->clinicA, 'crl_local', 1, ScopeRequiredException::class);
        $this->expectLifecycleFailure('deactivate', $this->suspendedManager, $this->clinicA, 'crl_local', 1, ScopeRequiredException::class);
        $this->expectLifecycleFailure('deactivate', $this->managerA, $this->clinicB, 'crl_foreign', 1, ScopeRequiredException::class);

        $storedA = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_local');
        $storedB = (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'crl_foreign');
        self::assertNotNull($storedA);
        self::assertNotNull($storedB);
        self::assertSame('active', $storedA['status']);
        self::assertSame(1, $storedA['version']);
        self::assertSame('active', $storedB['status'], 'a Clinic-A actor cannot change a Clinic-B definition');
        self::assertSame(1, $storedB['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $roleA['id']));
    }

    public function testBuiltInRoleKeysAreNotAddressableThroughCustomRoleLifecycle(): void
    {
        $this->expectLifecycleFailure(
            'deactivate',
            $this->managerA,
            $this->clinicA,
            RolesAndCapabilities::ROLE_MANAGER,
            1,
            CustomRoleManagementException::class,
            CustomRoleManagementException::CODE_INVALID_ROLE_KEY
        );
        self::assertTrue(App::authorization_service()->can($this->managerA, $this->clinicA, RolesAndCapabilities::CONFIG));
    }

    public function testRepeatedSameStatusRequestsAreNoOpsAndStaleVersionFailsSafely(): void
    {
        $role = $this->createRole('crl_idempotent', ['cpms_search']);
        $first = $this->deactivate($this->managerA, $this->clinicA, 'crl_idempotent', 1);
        $auditAfterFirst = $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']);
        $second = $this->deactivate($this->managerA, $this->clinicA, 'crl_idempotent', 2);

        self::assertTrue($first['changed']);
        self::assertFalse($second['changed']);
        self::assertSame(2, $second['version']);
        self::assertSame($auditAfterFirst, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));

        $this->expectLifecycleFailure('reactivate', $this->managerA, $this->clinicA, 'crl_idempotent', 1, CustomRoleManagementException::class, 'CUSTOM_ROLE_STALE_VERSION');
        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_idempotent');
        self::assertNotNull($stored);
        self::assertSame('inactive', $stored['status']);
        self::assertSame(2, $stored['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));

        $active = $this->reactivate($this->managerA, $this->clinicA, 'crl_idempotent', 2);
        $activeNoOp = $this->reactivate($this->managerA, $this->clinicA, 'crl_idempotent', 3);
        self::assertTrue($active['changed']);
        self::assertFalse($activeNoOp['changed']);
        self::assertSame(3, $activeNoOp['version']);
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));
    }

    public function testAuditPersistenceFailureRollsBackStatusAndVersion(): void
    {
        $role = $this->createRole('crl_audit', ['cpms_search']);
        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->deactivate($this->managerA, $this->clinicA, 'crl_audit', 1);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit INSERT must fail the lifecycle operation');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));
        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_audit');
        self::assertNotNull($stored);
        self::assertSame('active', $stored['status']);
        self::assertSame(1, $stored['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));
    }

    // ---------------------------------------------------------------------
    // Genuine MySQL concurrency proof: independent WPDB connections and a
    // committed fixture, verified with the exact Performance Schema wait-edge
    // pattern used by SchedulingLocationLocalBoundaryRedTest.
    // ---------------------------------------------------------------------

    public function testAssignmentWaitsForLockedDefinitionAndRevalidatesAfterDeactivation(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            // Descriptor isolation is only established through /proc/self/fd,
            // which Linux provides. Non-Linux hosts skip explicitly.
            self::markTestSkipped('External race workers require Linux (/proc/self/fd descriptor isolation).');
        }
        if (!function_exists('proc_open') || !class_exists('mysqli')) {
            // Fail-closed on Linux (ROUND7): a missing prerequisite FAILS the
            // test — only the non-Linux host may skip.
            self::fail('proc_open and MySQLi are required on Linux CI runners for the independent MySQL connections; skipping is not allowed.');
        }

        // Keep the repository-seeded definition free of a create-audit row. The
        // target is an existing active membership, as the assignment contract requires.
        $raceRoleKey = 'crl_race_' . bin2hex(random_bytes(3));
        $definitionId = (new CustomRoleRepository(App::db()))->define($this->clinicA, $raceRoleKey, ['cpms_search']);
        $targetUser = $this->makeUser('crl_race_target_' . bin2hex(random_bytes(3)));
        $targetMembershipId = App::membership_service()->create_membership(
            $this->clinicA,
            $targetUser,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        self::assertGreaterThan(0, $targetMembershipId);
        $clinicId = $this->clinicA;
        $managerId = $this->managerA;
        $targetWpUserBefore = get_userdata($targetUser);
        self::assertInstanceOf(\WP_User::class, $targetWpUserBefore);
        $targetWpRolesBefore = (array) $targetWpUserBefore->roles;

        // An independent session cannot observe the WP test transaction's uncommitted
        // fixtures. Commit them explicitly and clean only these fixture rows below.
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $parentConnectionId = (int) App::db()->wpdb()->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        self::assertGreaterThan(0, $parentConnectionId);

        $worker = null;
        $childReaped = false;
        $childStatus = null;
        $childConnectionId = 0;
        $childOutcome = '';
        $childQueryFingerprint = '';
        $childLockQuery = '';
        $observer = null;
        $mysqlVersion = '';
        $isMariaDb = false;
        $mariaBlocked = null;
        $parentLockBefore = null;
        $parentLockAfter = null;
        $waitEvidence = null;
        $waitEvidenceAfterTransition = null;
        $parentTransactionWhileWaiting = null;
        $childTransactionWhileWaiting = null;
        $parentTransactionAfterTransition = null;
        $childTransactionAfterTransition = null;
        $targetRoleAfter = '';
        $definitionStatusAfter = '';
        $targetWpRolesAfter = [];
        $deactivationAuditCount = 0;
        $assignmentAuditCount = 0;
        $queryHooks = isset($GLOBALS['wp_filter']['query']) ? clone $GLOBALS['wp_filter']['query'] : null;

        // Keep the race independent of the test-bootstrap query shims. B installs
        // a one-shot observer for its actual target locking query below.
        remove_all_filters('query');

        try {
            App::db()->transactional(function () use (
                $clinicId,
                $definitionId,
                $managerId,
                $targetUser,
                $raceRoleKey,
                $parentConnectionId,
                &$worker,
                &$childReaped,
                &$childConnectionId,
                &$childQueryFingerprint,
                &$childLockQuery,
                &$observer,
                &$mysqlVersion,
                &$isMariaDb,
                &$mariaBlocked,
                &$parentLockBefore,
                &$parentLockAfter,
                &$waitEvidence,
                &$waitEvidenceAfterTransition,
                &$parentTransactionWhileWaiting,
                &$childTransactionWhileWaiting,
                &$parentTransactionAfterTransition,
                &$childTransactionAfterTransition
            ): void {
                $activeParentConnectionId = (int) App::db()->wpdb()->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                self::assertSame($parentConnectionId, $activeParentConnectionId, 'The locking transaction must remain on the captured parent connection.');

                $lockedDefinition = App::db()->fetchRowForUpdate(
                    'SELECT id, status, version FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id = %d AND clinic_id = %d',
                    [$definitionId, $clinicId]
                );
                self::assertIsArray($lockedDefinition);
                self::assertSame('active', $lockedDefinition['status']);

                // Observe from a third, independent connection. This proves A
                // already owns the exact primary-key record before B is launched.
                $observer = $this->freshMysqli();
                $versionResult = $observer->query('SELECT VERSION()');
                if ($versionResult === false) {
                    throw new RuntimeException('Could not read the CI MySQL server version: ' . $observer->error);
                }
                $versionRow = $versionResult->fetch_row();
                $versionResult->free();
                $mysqlVersion = (string) ($versionRow[0] ?? 'unknown');
                $parentLockBefore = $this->customRoleDefinitionLockForConnection(
                    $observer,
                    $parentConnectionId,
                    $definitionId
                );
                self::assertIsArray(
                    $parentLockBefore,
                    'Parent A must hold a granted X record lock on the exact custom-role definition row before child B starts.'
                );

                // MariaDB cannot report this waiter through its InnoDB
                // views, so the worker is told to prove the block with the exact
                // production statement's own ER_LOCK_WAIT_TIMEOUT and to retry
                // only after an explicit RELEASE. MySQL 8 is unchanged: the
                // flag is never set for it.
                $isMariaDb = stripos($mysqlVersion, 'mariadb') !== false;

                $worker = ExternalRaceWorker::launch(
                    dirname(__DIR__) . '/bin/fork-race-wp-worker.php',
                    [
                        'mode' => 'custom_role_assignment',
                        'manager_id' => $managerId,
                        'clinic_id' => $clinicId,
                        'target_user_id' => $targetUser,
                        'race_role_key' => $raceRoleKey,
                        'definition_id' => $definitionId,
                        'await_release' => $isMariaDb,
                        'lock_wait_sec' => self::RACE_CHILD_LOCK_WAIT_TIMEOUT_SECONDS,
                    ]
                );

                $readyLine = $worker->readLineWait((float) self::RACE_SIGNAL_TIMEOUT_SECONDS);
                self::assertIsString($readyLine, 'Child B did not report its independent connection as ready within the bounded timeout.');
                self::assertMatchesRegularExpression('/^READY:[1-9][0-9]*$/', $readyLine);
                $childConnectionId = (int) substr($readyLine, strlen('READY:'));
                self::assertNotSame($parentConnectionId, $childConnectionId, 'A and B must use genuinely independent MySQL connections.');

                $queryLine = $worker->readLineWait((float) self::RACE_SIGNAL_TIMEOUT_SECONDS);
                if (!is_string($queryLine)) {
                    // Bounded diagnostics on FAIL paths only: engine transaction ids,
                    // lock ids and lock_data (the fixture primary key) are NOT printed.
                    $parentTxn = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                    $childTxn = $this->innodbTransactionForConnection($observer, $childConnectionId);
                    $diagnostics = [
                        'mysql_version' => $mysqlVersion,
                        'parent_connection_id' => $parentConnectionId,
                        'child_connection_id' => $childConnectionId,
                        'parent_transaction_state' => is_array($parentTxn) ? ($parentTxn['trx_state'] ?? null) : null,
                        'child_transaction_state' => is_array($childTxn) ? ($childTxn['trx_state'] ?? null) : null,
                        'wait_edge_observed' => $this->customRoleDefinitionWaitEdge($observer, $childConnectionId, $parentConnectionId, $definitionId) !== null,
                    ];
                    self::fail(
                        'Child B did not signal its target assignment SELECT ... FOR UPDATE within the bounded timeout. Evidence: '
                        . json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    );
                }
                self::assertMatchesRegularExpression('/^LOCK_QUERY:[1-9][0-9]*:[a-f0-9]{64}:[A-Za-z0-9+\/=]+$/', $queryLine);
                $queryParts = explode(':', $queryLine, 4);
                self::assertSame($childConnectionId, (int) $queryParts[1], 'The query-arrival signal must come from the same child connection observed by MySQL.');
                $childQueryFingerprint = $queryParts[2];
                $decodedQuery = base64_decode($queryParts[3], true);
                self::assertIsString($decodedQuery, 'The child must provide its normalized locking SQL for evidence.');
                $childLockQuery = $decodedQuery;
                self::assertSame($childQueryFingerprint, hash('sha256', $childLockQuery));
                self::assertStringContainsString(
                    'from ' . strtolower(App::db()->table('cpms_custom_role_defs')) . ' ',
                    $childLockQuery
                );
                self::assertStringContainsString(
                    'where id = ' . $definitionId . ' and clinic_id = ' . $clinicId,
                    $childLockQuery
                );
                self::assertStringEndsWith('for update', $childLockQuery);

                $mariaBlocked = null;
                if ($isMariaDb) {
                    // Authoritative MariaDB evidence, statement-specific: the child
                    // reports ER_LOCK_WAIT_TIMEOUT for the production statement it
                    // signalled above, named by that statement's own SHA-256.
                    $blockedLine = $worker->readLineWait(75.0);
                    if (!is_string($blockedLine)) {
                        self::fail(
                            'B produced no statement-level evidence within the bounded window. RDIAG='
                            . $this->mariaDbWaitFailureDiagnostics(
                                $observer,
                                $childConnectionId,
                                $parentConnectionId,
                                $definitionId,
                                'none'
                            )
                        );
                    }
                    self::assertMatchesRegularExpression(
                        '/^RESULT:blocked:(1205|3572):[a-f0-9]{64}$/',
                        $blockedLine,
                        'B must prove the block with a driver lock-timeout code and the exact statement hash. RDIAG=line:'
                        . preg_replace('/[^A-Za-z0-9_:]/', '', substr($blockedLine, 0, 120))
                        . ';' . $this->mariaDbWaitFailureDiagnostics(
                            $observer,
                            $childConnectionId,
                            $parentConnectionId,
                            $definitionId,
                            'line'
                        )
                    );
                    $blockedParts = explode(':', $blockedLine);
                    self::assertSame(
                        $childQueryFingerprint,
                        $blockedParts[3],
                        'The lock-timeout evidence must name the exact production SELECT ... FOR UPDATE B signalled.'
                    );
                    self::assertSame(
                        1205,
                        (int) $blockedParts[2],
                        'MariaDB reports the production statement lock-wait denial as ER_LOCK_WAIT_TIMEOUT (1205).'
                    );
                    $mariaBlocked = ['errno' => (int) $blockedParts[2], 'statement_sha256' => $blockedParts[3]];

                    // A must still own the exact row at the moment B timed out.
                    $parentLockWhileChildBlocked = $this->customRoleDefinitionLockForConnection(
                        $observer,
                        $parentConnectionId,
                        $definitionId
                    );
                    self::assertIsArray($parentLockWhileChildBlocked, 'A must still hold the exact definition row lock when B times out.');
                    self::assertSame($parentLockBefore['lock_id'], $parentLockWhileChildBlocked['lock_id']);
                    self::assertTrue($worker->isConfirmedRunning(), 'B must be observably alive and awaiting release, not exited.');
                }

                // Poll current Performance Schema state. The interval only
                // throttles observation; success requires the exact lock-wait edge.
                $deadline = microtime(true) + self::RACE_WAIT_TIMEOUT_SECONDS;
                do {
                    if ($isMariaDb) {
                        // Not observable through the InnoDB views on MariaDB 10.11
                        // (measured); the statement-level proof above stands alone.
                        break;
                    }
                    $waitEvidence = $this->customRoleDefinitionWaitEdge(
                        $observer,
                        $childConnectionId,
                        $parentConnectionId,
                        $definitionId
                    );
                    if ($waitEvidence !== null) {
                        break;
                    }

                    if (!$worker->isRunning()) {
                        $childReaped = true;
                        break;
                    }

                    usleep(self::RACE_POLL_INTERVAL_MICROSECONDS);
                } while (microtime(true) < $deadline);

                if ($waitEvidence === null && !$isMariaDb) {
                    // Bounded diagnostics on FAIL paths only: engine transaction ids,
                    // lock ids and lock_data (the fixture primary key) are NOT printed.
                    $parentTxn = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                    $childTxn = $this->innodbTransactionForConnection($observer, $childConnectionId);
                    $diagnostics = [
                        'mysql_version' => $mysqlVersion,
                        'parent_connection_id' => $parentConnectionId,
                        'child_connection_id' => $childConnectionId,
                        'parent_transaction_state' => is_array($parentTxn) ? ($parentTxn['trx_state'] ?? null) : null,
                        'child_transaction_state' => is_array($childTxn) ? ($childTxn['trx_state'] ?? null) : null,
                        'wait_edge' => null,
                    ];
                    // Sanitized, content-free tokens published by the MariaDB
                    // reporter so a missed edge is diagnosable without logs.
                    $diagnostics['maria_wait_failure'] = $this->mariaDbWaitFailureDiagnostics(
                        $observer,
                        $childConnectionId,
                        $parentConnectionId,
                        $definitionId,
                        $childReaped ? 'reaped' : 'running'
                    );
                    // Decisive, cheap evidence: after the bounded window, read at
                    // most one allowlisted late token from the child. It separates
                    // "child blocked by MariaDB but unobservable" from "child
                    // acquired or failed past the lock". Only exact tokens pass.
                    $lateLine = $worker->readLineWait(25.0);
                    if (!is_string($lateLine)) {
                        $late = 'none';
                    } elseif (preg_match('/^RESULT:(assigned|denied:[A-Z_]+)$/', $lateLine, $match) === 1) {
                        $late = $match[1];
                    } elseif (preg_match('/^RESULT:missing-target-lock-query:[a-z]+$/', $lateLine) === 1) {
                        $late = 'missing-lock-query';
                    } elseif (preg_match('/^ERROR:([A-Za-z0-9_]+)/', $lateLine, $match) === 1) {
                        $late = 'error:' . $match[1];
                    } else {
                        $late = 'other';
                    }
                    $diagnostics['maria_wait_failure'] .= ';late=' . preg_replace('/[^A-Za-z0-9_:]/', '', $late);
                    self::fail(
                        'Did not observe B blocked by A on the exact custom-role definition row before the bounded deadline. Evidence: '
                        . json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        . ' RDIAG=' . $diagnostics['maria_wait_failure']
                    );
                }

                if ($isMariaDb) {
                    // Equivalent, statement-specific proofs on MariaDB: the exact
                    // production statement timed out on A's row lock, A still holds
                    // it, and B is alive awaiting the post-commit retry.
                    self::assertSame((string) $childQueryFingerprint, (string) $mariaBlocked['statement_sha256']);
                    self::assertSame(1205, (int) $mariaBlocked['errno']);
                    $parentTransactionWhileWaiting = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                    self::assertIsArray($parentTransactionWhileWaiting, 'A must still have an open InnoDB transaction while B is blocked.');
                    self::assertSame('RUNNING', $parentTransactionWhileWaiting['trx_state']);
                    $childTransactionWhileWaiting = null;
                    $childTransactionAfterTransition = null;
                } else {
                self::assertSame((string) $childConnectionId, (string) $waitEvidence['requesting_connection_id']);
                self::assertSame((string) $parentConnectionId, (string) $waitEvidence['blocking_connection_id']);
                self::assertTrue(
                    $waitEvidence['requesting_lock_data'] === null
                    || trim((string) $waitEvidence['requesting_lock_data']) === (string) $definitionId,
                    'B must request the same definition record held by A when MySQL exposes requester LOCK_DATA.'
                );
                self::assertSame((string) $definitionId, trim((string) $waitEvidence['blocking_lock_data']));
                self::assertSame('WAITING', $waitEvidence['requesting_lock_status']);
                self::assertSame('GRANTED', $waitEvidence['blocking_lock_status']);
                if (!str_starts_with((string) $parentLockBefore['lock_id'], 'mariadb:behavioural:')) {
                    self::assertSame($parentLockBefore['lock_id'], $waitEvidence['blocking_lock_id']);
                    self::assertSame($parentLockBefore['transaction_id'], $waitEvidence['blocking_transaction_id']);
                }
                // The MariaDB wait-edge query already pins the blocking
                // transaction to the parent connection thread id.
                self::assertSame((string) $parentConnectionId, (string) $waitEvidence['blocking_connection_id']);
                self::assertNotSame($waitEvidence['requesting_transaction_id'], $waitEvidence['blocking_transaction_id']);

                $parentTransactionWhileWaiting = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                $childTransactionWhileWaiting = $this->innodbTransactionForConnection($observer, $childConnectionId);
                self::assertIsArray($parentTransactionWhileWaiting, 'Parent A must still have an open InnoDB transaction while B waits.');
                self::assertIsArray($childTransactionWhileWaiting, 'Child B must still have an open InnoDB transaction while its assignment waits.');
                self::assertSame('RUNNING', $parentTransactionWhileWaiting['trx_state']);
                self::assertSame('LOCK WAIT', $childTransactionWhileWaiting['trx_state']);
                }

                $deactivated = $this->deactivate($managerId, $clinicId, $raceRoleKey, 1);
                self::assertSame('inactive', $deactivated['status']);

                // The nested lifecycle operation must not release A's outer row
                // lock; B must remain blocked until A commits status and audit.
                $parentLockAfter = $this->customRoleDefinitionLockForConnection(
                    $observer,
                    $parentConnectionId,
                    $definitionId
                );
                $waitEvidenceAfterTransition = $this->customRoleDefinitionWaitEdge(
                    $observer,
                    $childConnectionId,
                    $parentConnectionId,
                    $definitionId
                );
                self::assertIsArray($parentLockAfter, 'A must continue holding the definition record lock after deactivation and before commit.');
                if ($isMariaDb) {
                    // B already proved it cannot complete while A holds the row; it
                    // is now deterministically parked awaiting A's RELEASE.
                    self::assertTrue($worker->isConfirmedRunning(), 'B must be observably awaiting release after A deactivated the role.');
                } else {
                    self::assertIsArray($waitEvidenceAfterTransition, 'B must remain blocked by A until A commits the deactivation.');
                }
                self::assertSame($parentLockBefore['lock_id'], $parentLockAfter['lock_id']);
                if (!$isMariaDb) {
                    self::assertSame($waitEvidence['requesting_lock_id'], $waitEvidenceAfterTransition['requesting_lock_id']);
                    self::assertSame($waitEvidence['blocking_lock_id'], $waitEvidenceAfterTransition['blocking_lock_id']);
                }

                $parentTransactionAfterTransition = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                if (!$isMariaDb) {
                    $childTransactionAfterTransition = $this->innodbTransactionForConnection($observer, $childConnectionId);
                }
                self::assertIsArray($parentTransactionAfterTransition);
                self::assertSame('RUNNING', $parentTransactionAfterTransition['trx_state']);
                if (!$isMariaDb) {
                    self::assertIsArray($childTransactionAfterTransition);
                    self::assertSame('LOCK WAIT', $childTransactionAfterTransition['trx_state']);
                }
            });

            // The callback has returned and A has committed. On MariaDB, B already
            // proved the block with its own statement-level timeout and is parked
            // awaiting this explicit release; it then retries the same production
            // assignment and must validate the current, inactive row state.
            if ($isMariaDb && $worker instanceof ExternalRaceWorker) {
                self::assertGreaterThan(0, $worker->writeCommand('RELEASE'), 'A must release B after committing the deactivation.');
            }
            $childRc = $worker instanceof ExternalRaceWorker
                ? $worker->waitForExit((float) self::RACE_SIGNAL_TIMEOUT_SECONDS)
                : -1;
            $childStatus = $worker instanceof ExternalRaceWorker ? $worker->finalStatus() : null;
            $childReaped = true;

            // The worker emits exactly one terminal line ('RESULT:*' or
            // 'ERROR:*') after the retry. Drain the remaining output until
            // EOF (waitForExit already returned, so the worker is gone), then
            // require EXACTLY ONE terminal line — even an identical duplicate
            // is a failure. Unknown non-terminal lines are ignored as before.
            $finalLine = $worker instanceof ExternalRaceWorker ? $worker->readLineWait((float) self::RACE_SIGNAL_TIMEOUT_SECONDS) : null;
            $terminalLines = [];
            $collectTerminalLine = static function (string $line) use (&$terminalLines): void {
                if (str_starts_with($line, 'RESULT:') || str_starts_with($line, 'ERROR:')) {
                    $terminalLines[] = $line;
                }
            };
            if (is_string($finalLine) && $finalLine !== '') {
                $collectTerminalLine($finalLine);
            }
            if ($worker instanceof ExternalRaceWorker) {
                $drain = $worker->drainRemainingLines((float) self::RACE_SIGNAL_TIMEOUT_SECONDS);
                foreach ($drain['lines'] as $drainedLine) {
                    $collectTerminalLine($drainedLine);
                }
                if (is_string($drain['trailing']) && $drain['trailing'] !== '') {
                    $collectTerminalLine($drain['trailing']);
                }
                self::assertTrue(
                    $drain['complete'],
                    'The worker did not reach EOF within the bounded drain window; a later terminal line cannot be ruled out.'
                );
                self::assertFalse(
                    $worker->outputTruncated(),
                    'Worker stdout exceeded the bounded rolling buffer and bytes were discarded; output integrity cannot be proven.'
                );
                self::assertFalse(
                    $worker->outputReadFailed(),
                    'A stdout read failure invalidates the drained-output accounting.'
                );
            }
            foreach ($terminalLines as $line) {
                if (str_starts_with($line, 'RESULT:')) {
                    $childOutcome = substr($line, strlen('RESULT:'));
                } elseif (str_starts_with($line, 'ERROR:')) {
                    $class = trim(substr($line, strlen('ERROR:')));
                    $childOutcome = 'ERROR:' . (preg_match('/\A[A-Za-z0-9_\\]{1,128}\z/D', $class) === 1 ? $class : 'unknown');
                }
            }
            self::assertCount(
                1,
                $terminalLines,
                'The worker must emit exactly one terminal line (RESULT:/ERROR:). A duplicate or a missing terminal line is a failure.'
            );

            // Worker hygiene: B ends with a plain, graceful exit(0); it holds no
            // descriptor of this process, so its shutdown path cannot tear down the
            // harness session. The RESULT/ERROR line asserted below carries the
            // authoritative verdict, including infra failure. The final status was
            // read once (at the observation that first saw the worker gone) and is
            // served from the cache: exit code 0, not signaled, not stopped.
            self::assertSame(0, $childRc, 'Child B must exit with a plain, graceful exit(0) after A commits. Child result: ' . $childOutcome);
            self::assertIsArray($childStatus, 'Child B final status must be readable from the cached single status read.');
            self::assertSame(0, (int) $childStatus['exitCode'], 'Child B exit code must be exactly 0 (strict).');
            self::assertFalse($childStatus['signaled'], 'Child B must not be terminated by a signal (strict).');
            self::assertFalse($childStatus['stopped'], 'Child B must not be stopped (strict).');
            self::assertSame(
                'denied:' . CustomRoleAssignmentException::CODE_DEFINITION_NOT_ACTIVE,
                $childOutcome,
                'B must resume after A commits, read the authoritative inactive status, and deny the stale assignment.'
            );

            $definitionStatusAfter = (string) App::db()->fetchValue(
                'SELECT status FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id = %d AND clinic_id = %d',
                [$definitionId, $clinicId]
            );
            $targetRoleAfter = (string) App::db()->fetchValue(
                'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d AND clinic_id = %d',
                [$targetMembershipId, $clinicId]
            );
            clean_user_cache($targetUser);
            $targetWpUserAfter = get_userdata($targetUser);
            self::assertInstanceOf(\WP_User::class, $targetWpUserAfter);
            $targetWpRolesAfter = (array) $targetWpUserAfter->roles;
            $deactivationAuditCount = $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', $definitionId);
            $assignmentAuditCount = $this->customRoleAssignmentAuditCount($clinicId, $targetMembershipId);

            self::assertSame('inactive', $definitionStatusAfter, 'A must commit the definition status before B resumes.');
            self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $targetRoleAfter, 'A denied assignment must not mutate the existing membership role.');
            self::assertSame($targetWpRolesBefore, $targetWpRolesAfter, 'Assignment must never mutate the target WordPress-global roles.');
            self::assertSame(0, $assignmentAuditCount, 'A denied assignment must not append a role-assigned audit event.');
            self::assertSame(1, $deactivationAuditCount, 'A must commit exactly one deactivation audit event with its status change.');

            // Fail-closed publication boundary: the evidence record is projected
            // through BoundedEvidence (fixed allow-listed keys, strictly validated
            // bounded values). Raw SQL, schema names, fixture ids, role lists and
            // performance_schema lock_data never reach the job log or a mirror.
            $evidence = \ClinicCore\Tests\Support\BoundedEvidence::lifecycle([
                'mysql_version' => $mysqlVersion,
                // 'view' evidence comes from the InnoDB/Performance Schema
                // views; 'statement_timeout' evidence comes from the child's
                // own production statement. The two are never conflated.
                'blocking_evidence_source' => $isMariaDb ? 'statement_timeout' : 'innodb_view',
                'parent' => [
                    'connection_id' => $parentConnectionId,
                    'transaction_id' => $parentLockBefore['transaction_id'] ?? null,
                    'definition_lock_id' => $parentLockBefore['lock_id'] ?? null,
                    'lock_after_deactivation_before_commit' => $parentLockAfter['lock_id'] ?? null,
                    'transaction_state_while_child_waited' => $parentTransactionWhileWaiting['trx_state'] ?? null,
                    'transaction_state_after_deactivation_before_commit' => $parentTransactionAfterTransition['trx_state'] ?? null,
                ],
                'child' => [
                    'connection_id' => $childConnectionId,
                    'transaction_id' => $waitEvidence['requesting_transaction_id'] ?? null,
                    'requesting_lock_id' => $waitEvidence['requesting_lock_id'] ?? null,
                    'transaction_state_while_waiting' => $childTransactionWhileWaiting['trx_state'] ?? null,
                    'transaction_state_after_deactivation_before_commit' => $childTransactionAfterTransition['trx_state'] ?? null,
                    'statement_lock_timeout_errno' => $isMariaDb ? ($mariaBlocked['errno'] ?? null) : null,
                ],
                'wait_edge' => $isMariaDb ? null : [
                    'requesting_connection_id' => $waitEvidence['requesting_connection_id'] ?? null,
                    'blocking_connection_id' => $waitEvidence['blocking_connection_id'] ?? null,
                    'requesting_lock_id' => $waitEvidence['requesting_lock_id'] ?? null,
                    'blocking_lock_id' => $waitEvidence['blocking_lock_id'] ?? null,
                    'requesting_lock_mode' => $waitEvidence['requesting_lock_mode'] ?? null,
                    'requesting_lock_status' => $waitEvidence['requesting_lock_status'] ?? null,
                    'blocking_lock_mode' => $waitEvidence['blocking_lock_mode'] ?? null,
                    'blocking_lock_status' => $waitEvidence['blocking_lock_status'] ?? null,
                    'table' => App::db()->table('cpms_custom_role_defs'),
                    'index' => 'PRIMARY',
                ],
                'statement_block_proof' => $isMariaDb ? $mariaBlocked : null,
                'same_wait_edge_after_deactivation_before_commit' => $isMariaDb ? null : ($waitEvidenceAfterTransition !== null),
                'child_outcome_after_parent_commit' => $childOutcome,
                'definition_status_after_parent_commit' => $definitionStatusAfter,
                'assignment_audit_count_after' => $assignmentAuditCount,
                'deactivation_audit_count_after' => $deactivationAuditCount,
            ]);
            fwrite(
                STDERR,
                'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE '
                . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                . PHP_EOL
            );
        } finally {
            // Nested try/finally: observer close, termination, query-hook
            // restore and committed-fixture cleanup for both clinics ALWAYS
            // run even if termination throws. The unreaped-worker verdict is
            // reported only AFTER all of that has run, as a hard test failure.
            try {
                if ($observer instanceof \mysqli) {
                    $observer->close();
                }
                if ($worker instanceof ExternalRaceWorker) {
                    $worker->terminate();
                }
            } finally {
                try {
                    // Restore WordPress' integration bootstrap query handlers before
                    // fixture cleanup and PHPUnit tearDown rollback.
                    if ($queryHooks !== null) {
                        $GLOBALS['wp_filter']['query'] = $queryHooks;
                    } else {
                        unset($GLOBALS['wp_filter']['query']);
                    }
                } finally {
                    try {
                        $this->cleanupCommittedRaceFixtures(
                            [$this->clinicA, $this->clinicB],
                            [$managerId, $this->managerB, $this->suspendedManager, $this->member, $this->nonMember, $targetUser],
                            $definitionId
                        );
                    } finally {
                        if ($worker instanceof ExternalRaceWorker && $worker->wasNotReaped()) {
                            self::fail('External race worker B could not be reaped after SIGTERM and SIGKILL; refusing to leave an unreaped child behind a green run.');
                        }
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // wp-admin entry/action contract: scoped managers need no WP-global CONFIG;
    // status posts retain nonce validation and the trusted Clinic service gate.
    // ---------------------------------------------------------------------

    public function testClinicScopedManagerCanReachThePageAndRenderedControlsHaveNonceAndConsequences(): void
    {
        $this->createRole('crl_ui', ['cpms_search']);
        wp_set_current_user($this->managerA);
        self::assertFalse(current_user_can(RolesAndCapabilities::CONFIG), 'the clinic manager fixture has no WordPress-global CONFIG grant');

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['_parent_pages'] = [];
        $GLOBALS['_registered_pages'] = [];
        // B-1 (unified menu): the page is no longer its own top-level menu; it is
        // registered under the single «مدیریت مطب» container, whose capability is
        // `read` (never `cpms_config`), so a Clinic-scoped manager still reaches it.
        \ClinicCore\Admin\CpmsAdminMenu::menu();
        CustomRoleDefinitionsPage::menu();
        $container_capability = null;
        foreach ( (array) $GLOBALS['menu'] as $item ) {
            if ( is_array( $item ) && ( $item[2] ?? '' ) === \ClinicCore\Admin\CpmsAdminMenu::parent_slug() ) {
                $container_capability = $item[1] ?? null;
            }
        }
        self::assertSame(
            'read',
            $container_capability,
            'the container entry must only be gated by ordinary wp-admin read access'
        );
        self::assertSame(
            \ClinicCore\Admin\CpmsAdminMenu::parent_slug(),
            $GLOBALS['_parent_pages'][ CustomRoleDefinitionsPage::PAGE_SLUG ] ?? null,
            'an authorized Clinic-local manager must have a reachable management-page entry'
        );

        $oldGet = $_GET;
        $_GET['cpms_clinic_id'] = (string) $this->clinicA;
        try {
            ob_start();
            CustomRoleDefinitionsPage::render();
            $html = (string) ob_get_clean();
            self::assertStringContainsString('cpms_custom_role_deactivate', $html);
            self::assertStringNotContainsString('cpms_custom_role_reactivate', $html, 'an active definition offers deactivation, not reactivation');
            self::assertStringContainsString('عضویت', $html, 'the explanation must say that existing staff assignments are retained');
            self::assertStringContainsString('confirm', $html);
            self::assertStringContainsString('_wpnonce', $html);
            self::assertStringContainsString('version', strtolower($html));
            self::assertStringContainsString('cpms_search', $html);

            $this->deactivate($this->managerA, $this->clinicA, 'crl_ui', 1);
            ob_start();
            CustomRoleDefinitionsPage::render();
            $inactiveHtml = (string) ob_get_clean();
            self::assertStringContainsString('غیرفعال', $inactiveHtml);
            self::assertStringContainsString('cpms_custom_role_reactivate', $inactiveHtml);
        } finally {
            $_GET = $oldGet;
        }
    }

    public function testInvalidStatusNonceStopsTheAdminActionBeforeMutation(): void
    {
        $this->createRole('crl_nonce', ['cpms_search']);
        wp_set_current_user($this->managerA);
        $oldPost = $_POST;
        $oldRequest = $_REQUEST;
        $_POST = [
            'action' => 'cpms_custom_role_deactivate',
            'cpms_clinic_id' => (string) $this->clinicA,
            'role_key' => 'crl_nonce',
            'version' => '1',
            '_wpnonce' => 'invalid-nonce',
        ];
        $_REQUEST = $_POST;
        $dieHandler = static function ($message): void {
            throw new \RuntimeException('wp_die reached: ' . (is_scalar($message) ? (string) $message : 'message'));
        };
        $dieFilter = static function ($handler) use ($dieHandler) {
            return $dieHandler;
        };
        add_filter('wp_die_handler', $dieFilter);
        try {
            try {
                CustomRoleDefinitionsPage::handle_deactivate();
                self::fail('an invalid nonce must stop the state-changing action');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('wp_die reached', $e->getMessage());
            }
        } finally {
            remove_filter('wp_die_handler', $dieFilter);
            $_POST = $oldPost;
            $_REQUEST = $oldRequest;
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_nonce');
        self::assertNotNull($stored);
        self::assertSame('active', $stored['status']);
        self::assertSame(1, $stored['version']);
    }

    // ---------------------------------------------------------------------
    // Test helpers.
    // ---------------------------------------------------------------------

    private function service(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    /** @param list<string> $capabilities */
    private function createRole(string $roleKey, array $capabilities): array
    {
        return $this->service()->create($this->managerA, $this->clinicA, $roleKey, 'Lifecycle ' . $roleKey, $capabilities);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function deactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('deactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function reactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('reactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function transition(string $method, int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        $service = $this->service();
        self::assertTrue(method_exists($service, $method), 'Slice 4A must implement the authorized ' . $method . ' operation');
        /** @var array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} $result */
        $result = call_user_func([$service, $method], $actor, $clinicId, $roleKey, $expectedVersion);

        return $result;
    }

    private function expectLifecycleFailure(
        string $method,
        int $actor,
        int $clinicId,
        string $roleKey,
        int $version,
        string $exceptionClass,
        ?string $reason = null
    ): void {
        try {
            $this->transition($method, $actor, $clinicId, $roleKey, $version);
            self::fail('the lifecycle mutation must be refused');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exceptionClass, $e);
            if ($reason !== null) {
                self::assertSame($reason, method_exists($e, 'reason_code') ? $e->reason_code() : null);
            }
        }
    }

    /** @param list<string> $capabilities */
    private function statusAuditCount(string $action, int $definitionId): int
    {
        global $wpdb;
        $table = App::db()->table('cpms_audit_logs');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE action = %s AND clinic_id = %d AND resource_type = %s AND resource_id = %d",
            $action,
            $this->clinicA,
            'custom_role_definition',
            $definitionId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function definitionId(string $roleKey): int
    {
        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d AND role_key = %s',
            [$this->clinicA, $roleKey]
        );
    }

    private function failAuditInserts(): void
    {
        global $wpdb;
        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');
        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $matches) === 1
                && str_contains($matches[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $matches[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };
        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ($this->auditFault === null) {
            return;
        }
        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;
        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        try {
            $connection = new \mysqli($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
        } catch (\mysqli_sql_exception $exception) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection.', 0, $exception);
        }
        if ($connection->connect_errno !== 0) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection: ' . $connection->connect_error);
        }

        if (!$connection->set_charset('utf8mb4')) {
            throw new RuntimeException('Could not set the observer connection character set: ' . $connection->error);
        }

        return $connection;
    }

    /**
     * @param resource $pipe
     */
    private function customRoleAssignmentAuditCount(int $clinicId, int $membershipId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d',
            [
                $clinicId,
                CustomRoleAssignmentService::AUDIT_ASSIGNED,
                CustomRoleAssignmentService::RESOURCE_TYPE,
                $membershipId,
            ]
        );
    }


    /**
     * Test-only teardown for the committed two-connection fixture. The test
     * asserts the deactivation event before this cleanup; production lifecycle
     * paths never delete audit records, definitions, memberships or overrides.
     *
     * @param list<int> $clinicIds
     * @param list<int> $userIds
     */
    private function cleanupCommittedRaceFixtures(array $clinicIds, array $userIds, int $definitionId): void
    {
        $db = App::db();
        $clinicId = (int) ($clinicIds[0] ?? 0);
        if ($clinicId > 0) {
            // Remove only this ephemeral test event so the integration suite's
            // global hash-chain fixtures and Clinic-count assumptions are reset.
            $db->query(
                'DELETE FROM ' . $db->table('cpms_audit_logs') .
                ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d',
                [$clinicId, 'CUSTOM_ROLE_DEACTIVATED', 'custom_role_definition', $definitionId]
            );
        }
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d',
            [$definitionId]
        );
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE id = %d',
            [$definitionId]
        );
        foreach ($clinicIds as $clinicId) {
            $memberships = $db->fetchAll(
                'SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$clinicId]
            );
            foreach ($memberships as $membership) {
                $membershipId = (int) ($membership['id'] ?? 0);
                if ($membershipId > 0) {
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_capabilities') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE id = %d', [$membershipId]);
                }
            }
        }
        foreach ($userIds as $userId) {
            wp_delete_user($userId);
        }
        foreach ($clinicIds as $clinicId) {
            $db->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$clinicId]);
        }

        // 5) Durable: the parent test transaction was COMMITted durably above
        // (raw COMMIT on the harness connection), so this cleanup's DELETEs sit
        // in a fresh transaction that tearDown()'s ROLLBACK would undo —
        // resurrecting the committed Clinic/user/membership rows for every
        // later suite in the shared database (same pattern and comment as
        // InterruptedSetupRecoveryTest.php and CustomRoleArchivalConcurrencyTest.php).
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::fail('cleanup must commit its fixture removal durably (tearDown rolls back the harness transaction)');
        }
    }

    private function makeClinic(int $organizationId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organizationId,
            'Lifecycle test ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $id = self::factory()->user->create(['user_login' => $login, 'role' => $role]);
        self::assertGreaterThan(0, $id);

        return (int) $id;
    }
}

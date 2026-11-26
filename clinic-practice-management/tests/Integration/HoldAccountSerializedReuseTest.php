<?php
/**
 * PR #25 Round 4C — Subject-bound & account-serialized BookingService::hold() reuse proofs.
 *
 * Covers:
 *  - T-SUBJECT: same account holding two linked patients P1 and P2 in the same
 *    clinic on a capacity >= 2 slot receives distinct tokens and two active
 *    hold rows; repeating hold() for P1 or P2 returns that subject's existing
 *    token without inserting a row, incrementing held_count, or emitting
 *    HOLD_CREATED / booking.hold logs; a historical NULL-subject active hold
 *    row is never conflated with a non-null patient subject (and a 0-link
 *    caller whose resolved subject is null fails closed at mobile lookup before
 *    reaching hold reuse).
 *  - T-EXPIRED: an active-status hold whose expires_at has passed (backdated in
 *    DB, zero sleep) is never reused on either the pre-transaction check or the
 *    post-account-lock in-transaction re-read (C1).
 *  - T-HOLD: deterministic two-connection contention test where the parent
 *    locks the account row in cpms_patient_logins FOR UPDATE, a real child OS
 *    process (proc_open, zero inherited DB sockets, strict exit code 0 with no
 *    signal) calls the real BookingService::hold() and blocks in
 *    information_schema.INNODB_TRX with trx_state = 'LOCK WAIT', the parent
 *    inserts the active hold row and increments held_count once before COMMIT,
 *    and the child unblocks, sees the committed hold via the post-lock plain
 *    SELECT (because PatientLoginRepository::table_exists() was hoisted out of
 *    the REPEATABLE READ transaction), and returns the parent's hold_token
 *    without duplicating the hold or held_count. Includes a capacity-1
 *    different-account 409 CLINIC_SLOT_TAKEN control (C2).
 *
 * All fixtures run inside an IsolatedSchema disposable database dropped in
 * tearDown(); zero rows are committed to the shared WordPress test database.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;

require_once __DIR__ . '/IsolatedSchema.php';
require_once __DIR__ . '/RealTableMigrations.php';

final class HoldAccountSerializedReuseTest extends WP_UnitTestCase
{
    use RealTableMigrations;
    use IsolatedSchema;

    private const CHILD_READY_TIMEOUT_SEC = 15.0;
    private const LOCK_WAIT_TIMEOUT_SEC   = 15.0;
    private const CHILD_EXIT_TIMEOUT_SEC  = 15.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDisposableSchema('hsr');
        $this->migrateDisposableSchema();
        $this->seedWpUsers();
        $this->seedOrganizationGraph();
        $this->seedPatientsAndLinks();
        $this->seedSessions([$this->userAId, $this->userBId]);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropDisposableSchema();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * T-SUBJECT (C3):
     *  - Mutation note (INFERENCE, PHP not executed locally): removing
     *    `AND patient_id = %d` from BookingService.php:587 (pre-tx reuse) or
     *    BookingService.php:638 (in-tx re-read) would make hold() for P2 return
     *    P1's token (and make hold() for P1 reuse the NULL-subject row);
     *    removing the reuse returns at BookingService.php:597-603 and
     *    BookingService.php:648-650 / :733-739 would make the repeat hold() for
     *    P1 mint a new token, insert a third row, and fail the held_count /
     *    audit / op-log non-growth assertions.
     */
    public function testTSubjectSameAccountDistinctPatientsAndNullSubjectNeverConflateTokens(): void
    {
        $db         = new CpmsDb($this->fixtureWpdb);
        $holdsTable = $this->rawTable('cpms_slot_holds');
        $slotsTable = $this->rawTable('cpms_schedule_slots');
        $auditTable = $this->rawTable('cpms_audit_logs');
        $opTable    = $this->rawTable('cpms_operational_logs');

        $patientA2Id = $this->seedSecondLinkedPatientOnAccount($this->userAId, 'MR-DS-A2-' . strtoupper(bin2hex(random_bytes(3))));
        self::assertNotSame($this->patientAId, $patientA2Id);

        $date   = gmdate('Y-m-d', time() + 4 * 86400);
        $slotId = $this->insertSlot(3, 0, $date);

        $stack = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);

        // 1) Hold for P1 on capacity=3 slot -> first token, 1 active row, held_count=1.
        $holdP1 = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $this->patientAId
            )
        );
        self::assertNotSame('', (string) ($holdP1['hold_token'] ?? ''));

        // 2) Hold for P2 under the SAME account on the SAME slot -> distinct token, 2 active rows, held_count=2.
        $holdP2 = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $patientA2Id
            )
        );
        self::assertNotSame('', (string) ($holdP2['hold_token'] ?? ''));
        self::assertNotSame($holdP1['hold_token'], $holdP2['hold_token'], 'P2 must receive a distinct hold_token from P1');

        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT COUNT(*) FROM {$holdsTable} WHERE slot_id = %d AND status = %s", [$slotId, 'active'])
        );
        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$slotId])
        );

        $auditBeforeRepeat = (int) $db->fetchValue("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", ['HOLD_CREATED']);
        $opBeforeRepeat    = (int) $db->fetchValue("SELECT COUNT(*) FROM {$opTable} WHERE message = %s", ['booking.hold']);

        // 3) Repeat hold() for P1 -> returns P1's token without inserting a row, incrementing held_count, or logging.
        $repeatP1 = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $this->patientAId
            )
        );
        self::assertSame($holdP1['hold_token'], $repeatP1['hold_token']);
        self::assertSame($holdP1['expires_at'], $repeatP1['expires_at']);

        // 4) Repeat hold() for P2 -> returns P2's token without inserting a row, incrementing held_count, or logging.
        $repeatP2 = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $patientA2Id
            )
        );
        self::assertSame($holdP2['hold_token'], $repeatP2['hold_token']);
        self::assertSame($holdP2['expires_at'], $repeatP2['expires_at']);

        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT COUNT(*) FROM {$holdsTable} WHERE slot_id = %d", [$slotId])
        );
        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$slotId])
        );
        self::assertSame($auditBeforeRepeat, (int) $db->fetchValue("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", ['HOLD_CREATED']));
        self::assertSame($opBeforeRepeat, (int) $db->fetchValue("SELECT COUNT(*) FROM {$opTable} WHERE message = %s", ['booking.hold']));

        // 5a) Steady-state null-subject reachability proof (Mission 0(c)):
        // resolveBookingPatientForHold() returns null only when linkedActivePatientsForClinic()
        // is empty, which immediately causes mobile_for_user_in_clinic() (BookingService.php:577-580)
        // to fail closed with 400 CLINIC_VALIDATION_FAILED before reaching :584.
        $zeroLinkTag    = $this->fileTag . '_zl_' . bin2hex(random_bytes(3));
        $zeroLinkUserId = (int) wp_create_user('cp_zl_' . $zeroLinkTag, 'pass-zl-12345', $zeroLinkTag . '@test.local');
        self::assertGreaterThan(0, $zeroLinkUserId);
        $zeroLinkUser = get_userdata($zeroLinkUserId);
        self::assertInstanceOf(\WP_User::class, $zeroLinkUser);
        $zeroLinkUser->set_role(RolesAndCapabilities::ROLE_PATIENT);
        $zeroLinkToken   = cpms_test_seed_patient_session($zeroLinkUserId, $this->clinicId);
        $zeroLinkSession = (array) \WP_Session_Tokens::get_instance($zeroLinkUserId)->get($zeroLinkToken);
        $this->capturedTokens[$zeroLinkUserId]   = $zeroLinkToken;
        $this->capturedSessions[$zeroLinkUserId] = $zeroLinkSession;

        $zeroLinkSlotId = $this->insertSlot(2, 0, gmdate('Y-m-d', time() + 5 * 86400));
        $zeroLinkStack  = $this->stackOn($this->fixtureWpdb, true, $zeroLinkUserId, $zeroLinkSession);
        $zeroLinkError  = null;
        try {
            $this->transactionalCommit(
                $this->fixtureWpdb,
                fn (): array => $zeroLinkStack['service']->hold(
                    $zeroLinkUserId,
                    $this->clinicianId,
                    gmdate('Y-m-d', time() + 5 * 86400),
                    '09:00:00',
                    $zeroLinkSlotId,
                    null
                )
            );
        } catch (BookingException $e) {
            $zeroLinkError = $e;
        }
        self::assertInstanceOf(BookingException::class, $zeroLinkError);
        self::assertSame('CLINIC_VALIDATION_FAILED', $zeroLinkError->errorCode);
        self::assertSame(
            0,
            (int) $db->fetchValue("SELECT COUNT(*) FROM {$holdsTable} WHERE slot_id = %d", [$zeroLinkSlotId])
        );

        // 5b) Persisted historical NULL-subject active hold row on the same account/slot
        // is NOT reused when the account calls hold() for non-null subject P1.
        $nullSlotDate = gmdate('Y-m-d', time() + 6 * 86400);
        $nullSlotId   = $this->insertSlot(2, 1, $nullSlotDate);
        $nullToken    = 'tok-null-' . bin2hex(random_bytes(8));
        $futureUtc    = gmdate('Y-m-d H:i:s', time() + 600) . '.000';
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $nullSlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $this->patientMobile($this->patientAId),
            'token'             => $nullToken,
            'expires_at'        => $futureUtc,
            'status'            => 'active',
            'created_at'        => $db->nowUtcSql(),
            'patient_id'        => null,
        ]);

        $nonNullHold = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $nullSlotDate,
                '09:00:00',
                $nullSlotId,
                $this->patientAId
            )
        );
        self::assertNotSame($nullToken, $nonNullHold['hold_token'], 'non-null subject P1 must not reuse a patient_id IS NULL hold');
        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT COUNT(*) FROM {$holdsTable} WHERE slot_id = %d AND status = %s", [$nullSlotId, 'active'])
        );
        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$nullSlotId])
        );
    }

    /**
     * T-EXPIRED (C1):
     *  - Mutation note (INFERENCE, PHP not executed locally): removing
     *    `AND expires_at > %s` from BookingService.php:587 (pre-tx reuse) would
     *    make the sequential repeat hold() return the backdated expired token;
     *    removing `AND expires_at > %s` from BookingService.php:638 (in-tx
     *    re-read) would make the contended child in part 2 return the
     *    parent-inserted expired token instead of minting a fresh active hold.
     */
    public function testTExpiredBackdatedActiveHoldOfSameSubjectIsNeverReused(): void
    {
        $db         = new CpmsDb($this->fixtureWpdb);
        $holdsTable = $this->rawTable('cpms_slot_holds');
        $slotsTable = $this->rawTable('cpms_schedule_slots');

        $date   = gmdate('Y-m-d', time() + 4 * 86400);
        $slotId = $this->insertSlot(2, 0, $date);
        $stack  = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);

        // Part 1: sequential pre-tx C1 check — backdate expires_at in DB (status remains 'active', zero sleep).
        $first = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $this->patientAId
            )
        );
        $firstToken = (string) $first['hold_token'];
        self::assertNotSame('', $firstToken);

        $updated = $this->fixtureWpdb->query(
            $this->fixtureWpdb->prepare(
                "UPDATE {$holdsTable} SET expires_at = %s WHERE token = %s AND status = %s",
                '2020-01-01 00:00:00.000',
                $firstToken,
                'active'
            )
        );
        self::assertSame(1, (int) $updated);

        $second = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $slotId,
                $this->patientAId
            )
        );
        $secondToken = (string) $second['hold_token'];
        self::assertNotSame($firstToken, $secondToken, 'expired hold of same subject must never be reused');
        self::assertGreaterThan($db->nowUtcSql(), (string) $second['expires_at']);
        self::assertSame(
            2,
            (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$slotId])
        );

        // Part 2: contended post-lock in-tx C1 check — child passes pre-tx with 0 holds,
        // blocks on account FOR UPDATE, parent inserts an ALREADY-EXPIRED active-status row
        // and COMMITs; child must NOT reuse the expired row.
        $this->assertSubprocessBackendAvailable();
        $contendedDate   = gmdate('Y-m-d', time() + 5 * 86400);
        $contendedSlotId = $this->insertSlot(2, 0, $contendedDate);
        $expiredParentToken = 'tok-exp-parent-' . bin2hex(random_bytes(8));

        $parentMysqli   = null;
        $observerMysqli = null;
        $worker         = null;
        try {
            $parentMysqli   = $this->freshMysqli();
            $observerMysqli = $this->freshMysqli();
            self::assertTrue((bool) $parentMysqli->query('SET SESSION innodb_lock_wait_timeout = 20'));
            self::assertTrue((bool) $parentMysqli->query('BEGIN'));
            $this->lockAccountRowOnMysqli($parentMysqli, $this->userAId);

            $worker      = $this->spawnChildHoldProcess($this->userAId, $contendedSlotId, $contendedDate, '09:00:00', $this->patientAId);
            $childConnId = $this->awaitChildReadyConnectionId($worker);
            $this->awaitChildAccountLockWait($observerMysqli, $childConnId, $worker);

            $this->insertHoldRowOnMysqli(
                $parentMysqli,
                $contendedSlotId,
                $this->userAId,
                $this->patientAId,
                $this->patientMobile($this->patientAId),
                $expiredParentToken,
                '2020-01-01 00:00:00.000',
                gmdate('Y-m-d H:i:s') . '.000'
            );
            self::assertTrue((bool) $parentMysqli->query('COMMIT'));

            $outcome = $this->reapChildHoldProcess($worker);
            self::assertFalse($outcome['signaled'], 'child must not terminate by signal');
            self::assertFalse($outcome['stopped'], 'child must not be stopped');
            self::assertSame(0, $outcome['exit_code'], 'child must exit strictly with code 0; diag=' . $outcome['stderr']);
            self::assertTrue((bool) ($outcome['payload']['ok'] ?? false), 'child hold() must succeed with a fresh hold');
            $childHold = (array) ($outcome['payload']['result'] ?? []);
            self::assertNotSame($expiredParentToken, (string) ($childHold['hold_token'] ?? ''), 'in-tx re-read must reject expired hold (C1)');
            self::assertGreaterThan($db->nowUtcSql(), (string) ($childHold['expires_at'] ?? ''));
        } finally {
            $this->cleanupRaceResources($worker, $parentMysqli, $observerMysqli);
        }
    }

    /**
     * T-HOLD (C1, C2, C3, C5):
     *  - Mutation note (INFERENCE, PHP not executed locally):
     *    1) Removing the pre-tx `$logins_table_exists` hoist at BookingService.php:609
     *       (or ignoring `$logins_table_exists` at BookingService.php:3196) causes
     *       `SHOW TABLES LIKE %s` (PatientLoginRepository.php:48) to execute inside
     *       hold()'s REPEATABLE READ transaction BEFORE `find_account_by_user_id(..., true)`
     *       (`FOR UPDATE`), opening the child's MVCC snapshot on MySQL 8.0 before the
     *       parent COMMITs; the child then misses the parent's committed hold row,
     *       mints a distinct token, inserts a second active hold row, and increments
     *       `held_count` to 2 — failing the token equality, active-row count = 1, and
     *       `held_count` = 1 assertions.
     *    2) Removing the post-account-lock plain SELECT block at
     *       BookingService.php:635-650 / :733-739 fails the same three assertions on
     *       both MySQL 8.0 and MariaDB 11.4.
     */
    public function testTHoldConcurrentSameAccountSamePatientReusesCommittedHoldAfterAccountLockWait(): void
    {
        $this->assertSubprocessBackendAvailable();

        $db                  = new CpmsDb($this->fixtureWpdb);
        $holdsTable          = $this->rawTable('cpms_slot_holds');
        $slotsTable          = $this->rawTable('cpms_schedule_slots');
        $auditTable          = $this->rawTable('cpms_audit_logs');
        $opTable             = $this->rawTable('cpms_operational_logs');
        $parentHarnessConnId = (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []);
        self::assertGreaterThan(0, $parentHarnessConnId);

        // Capacity = 2 ensures spare capacity exists when the child unblocks, proving
        // non-duplication comes from the post-lock reuse read rather than slot exhaustion.
        $date            = gmdate('Y-m-d', time() + 4 * 86400);
        $slotId          = $this->insertSlot(2, 0, $date);
        $parentToken     = $this->uuid();
        $parentExpiresAt = gmdate('Y-m-d H:i:s', time() + 600) . '.000';
        $holderMobile    = $this->patientMobile($this->patientAId);

        $auditBefore = (int) $db->fetchValue("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", ['HOLD_CREATED']);
        $opBefore    = (int) $db->fetchValue("SELECT COUNT(*) FROM {$opTable} WHERE message = %s", ['booking.hold']);

        $parentMysqli   = null;
        $observerMysqli = null;
        $worker         = null;
        try {
            $parentMysqli   = $this->freshMysqli();
            $observerMysqli = $this->freshMysqli();
            self::assertTrue((bool) $parentMysqli->query('SET SESSION innodb_lock_wait_timeout = 20'));
            self::assertTrue((bool) $parentMysqli->query('BEGIN'));

            // 1) Parent locks the account row in cpms_patient_logins with SELECT ... FOR UPDATE.
            $this->lockAccountRowOnMysqli($parentMysqli, $this->userAId);

            // 2) Child OS process starts with 0 holds on $slotId, passes pre-tx reuse,
            //    and blocks on the account-row FOR UPDATE inside BookingService::hold().
            $worker      = $this->spawnChildHoldProcess($this->userAId, $slotId, $date, '09:00:00', $this->patientAId);
            $childConnId = $this->awaitChildReadyConnectionId($worker);

            // 3) Parent detects child lock wait ONLY by polling information_schema.INNODB_TRX
            //    for trx_state = 'LOCK WAIT' on the child's exact MySQL thread ID.
            $this->awaitChildAccountLockWait($observerMysqli, $childConnId, $worker);

            // 4) While holding the account lock, parent inserts the active hold row (matching
            //    BookingService.php:697-708 columns) and increments held_count once, then COMMITs.
            $nowUtcSql = gmdate('Y-m-d H:i:s') . '.000';
            $this->insertHoldRowOnMysqli(
                $parentMysqli,
                $slotId,
                $this->userAId,
                $this->patientAId,
                $holderMobile,
                $parentToken,
                $parentExpiresAt,
                $nowUtcSql
            );
            $this->incrementSlotHeldCountOnceOnMysqli($parentMysqli, $slotId, $nowUtcSql);
            self::assertTrue((bool) $parentMysqli->query('COMMIT'));

            // 5) Child unblocks, runs post-lock plain SELECT, sees committed hold, exits 0.
            $outcome = $this->reapChildHoldProcess($worker);
            self::assertFalse($outcome['signaled'], 'child exit must be normal, never by signal');
            self::assertFalse($outcome['stopped'], 'child must not be stopped');
            self::assertSame(0, $outcome['exit_code'], 'child must exit strictly with code 0; diag=' . $outcome['stderr']);
            self::assertTrue(
                (bool) ($outcome['payload']['ok'] ?? false),
                'child BookingService::hold() must succeed; payload=' . json_encode($outcome['payload'])
            );

            $childResult = (array) ($outcome['payload']['result'] ?? []);
            self::assertSame($parentToken, (string) ($childResult['hold_token'] ?? ''), 'child must return parent hold_token');
            self::assertSame($parentExpiresAt, (string) ($childResult['expires_at'] ?? ''), 'child must return parent expires_at');
            self::assertSame($slotId, (int) ($childResult['slot']['slot_id'] ?? 0));

            // Fresh read on disposable DB confirms single active hold row & single held_count increment.
            $verifyConn = $this->newDisposableConnection();
            try {
                $verifyDb = new CpmsDb($verifyConn);
                self::assertSame(
                    1,
                    (int) $verifyDb->fetchValue(
                        "SELECT COUNT(*) FROM {$holdsTable} WHERE clinic_id = %d AND slot_id = %d AND holder_wp_user_id = %d AND patient_id = %d AND status = %s",
                        [$this->clinicId, $slotId, $this->userAId, $this->patientAId, 'active']
                    ),
                    'exactly ONE active hold row must exist for (clinic, slot, account, patient)'
                );
                self::assertSame(
                    1,
                    (int) $verifyDb->fetchValue("SELECT COUNT(*) FROM {$holdsTable} WHERE slot_id = %d", [$slotId]),
                    'no duplicate hold row of any status may exist on the slot'
                );
                self::assertSame(
                    1,
                    (int) $verifyDb->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$slotId]),
                    'held_count must have increased exactly once'
                );
                self::assertSame(
                    $auditBefore,
                    (int) $verifyDb->fetchValue("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", ['HOLD_CREATED']),
                    'in-tx reuse must not emit HOLD_CREATED audit'
                );
                self::assertSame(
                    $opBefore,
                    (int) $verifyDb->fetchValue("SELECT COUNT(*) FROM {$opTable} WHERE message = %s", ['booking.hold']),
                    'in-tx reuse must not emit booking.hold operational log'
                );
            } finally {
                $verifyConn->close();
            }

            // Parent harness connection must remain untouched (zero inherited-socket teardown).
            self::assertSame($parentHarnessConnId, (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []));
        } finally {
            $this->cleanupRaceResources($worker, $parentMysqli, $observerMysqli);
        }

        // Control (C2): a DIFFERENT account on a capacity-1 slot held by userA still gets 409 CLINIC_SLOT_TAKEN.
        $capOneDate   = gmdate('Y-m-d', time() + 6 * 86400);
        $capOneSlotId = $this->insertSlot(1, 0, $capOneDate);
        $stackA       = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);
        $stackB       = $this->stackOn($this->fixtureWpdb, true, $this->userBId, $this->capturedSessions[$this->userBId]);

        $winnerA = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stackA['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $capOneDate,
                '09:00:00',
                $capOneSlotId,
                $this->patientAId
            )
        );
        self::assertNotSame('', (string) ($winnerA['hold_token'] ?? ''));

        $otherAccountError = null;
        try {
            $this->transactionalCommit(
                $this->fixtureWpdb,
                fn (): array => $stackB['service']->hold(
                    $this->userBId,
                    $this->clinicianId,
                    $capOneDate,
                    '09:00:00',
                    $capOneSlotId,
                    $this->patientBId
                )
            );
        } catch (BookingException $e) {
            $otherAccountError = $e;
        }
        self::assertInstanceOf(BookingException::class, $otherAccountError);
        self::assertSame('CLINIC_SLOT_TAKEN', $otherAccountError->errorCode);
        self::assertSame(409, $otherAccountError->httpStatus);
        self::assertSame(
            1,
            (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$capOneSlotId])
        );
    }

    /**
     * T-F2 (Mission 3, C1/C4):
     *  - Fixed-row sub-second DATETIME(3) boundary proof (zero sleep; time moves
     *    strictly in test data). End-to-end BookingService::hold() reads real
     *    wall-clock DateTimeImmutable("now") without a public clock-injection seam,
     *    so sub-second boundary evaluation is tested deterministically against real
     *    persisted cpms_slot_holds DATETIME(3) rows via find_reusable_active_hold_at().
     */
    public function testTF2SubSecondFractionalExpiryBoundaryNeverReusesExpiredHold(): void
    {
        $db           = new CpmsDb($this->fixtureWpdb);
        $holdsTable   = $this->rawTable('cpms_slot_holds');
        $holderMobile = $this->patientMobile($this->patientAId);
        $stack        = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);

        // 1) Verify BookingService::hold() writes .000 millisecond precision.
        $mintDate   = gmdate('Y-m-d', time() + 7 * 86400);
        $mintSlotId = $this->insertSlot(2, 0, $mintDate);
        $minted     = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $mintDate,
                '09:00:00',
                $mintSlotId,
                $this->patientAId
            )
        );
        self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000$/', (string) ($minted['expires_at'] ?? '')));

        // 2) Fixed-instant sub-second boundary rows inside the same UTC second (14:30:10).
        $f2Date   = gmdate('Y-m-d', time() + 8 * 86400);
        $f2SlotId = $this->insertSlot(5, 0, $f2Date);
        $fetcher  = new \ReflectionMethod(\ClinicCore\Application\Booking\BookingService::class, 'fetch_active_hold_candidate');
        $filter   = new \ReflectionMethod(\ClinicCore\Application\Booking\BookingService::class, 'filter_unexpired_hold_candidate_at');
        $finder   = new class($fetcher, $filter) {
            public function __construct(
                private readonly \ReflectionMethod $fetcher,
                private readonly \ReflectionMethod $filter
            ) {
            }

            public function invoke(
                object $service,
                int $wpUserId,
                int $clinicId,
                int $slotId,
                ?int $patientId,
                \DateTimeImmutable $nowUtc
            ): ?array {
                $candidate = $this->fetcher->invoke($service, $wpUserId, $clinicId, $slotId, $patientId);

                return $this->filter->invoke($service, $candidate, $nowUtc);
            }
        };
        $fixedNow = new \DateTimeImmutable('2030-05-15 14:30:10.500250', new \DateTimeZone('UTC'));
        $exact500 = new \DateTimeImmutable('2030-05-15 14:30:10.500000', new \DateTimeZone('UTC'));
        $exact501 = new \DateTimeImmutable('2030-05-15 14:30:10.501000', new \DateTimeZone('UTC'));

        // 2a) Earlier fraction in the same second (.250 < .500250): must NOT be reused.
        $earlierToken = 'tok-f2-250-' . bin2hex(random_bytes(6));
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $f2SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $earlierToken,
            'expires_at'        => '2030-05-15 14:30:10.250',
            'status'            => 'active',
            'created_at'        => '2030-05-15 14:20:10.000',
            'patient_id'        => $this->patientAId,
        ]);
        self::assertSame(
            '2030-05-15 14:30:10.250',
            (string) $db->fetchValue("SELECT expires_at FROM {$holdsTable} WHERE token = %s", [$earlierToken]),
            'DATETIME(3) column must preserve millisecond fraction'
        );
        self::assertNull(
            $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, $this->patientAId, $fixedNow),
            'hold expiring at .250 in the same second as .500250 must be treated as expired'
        );
        $this->fixtureWpdb->query($this->fixtureWpdb->prepare("UPDATE {$holdsTable} SET status = %s WHERE token = %s", 'expired', $earlierToken));

        // 2b) Same millisecond (.500 <= .500250 and .500 <= .500000): conservative <= treated as expired.
        $equalMsToken = 'tok-f2-500-' . bin2hex(random_bytes(6));
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $f2SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $equalMsToken,
            'expires_at'        => '2030-05-15 14:30:10.500',
            'status'            => 'active',
            'created_at'        => '2030-05-15 14:20:10.000',
            'patient_id'        => $this->patientAId,
        ]);
        self::assertNull(
            $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, $this->patientAId, $fixedNow),
            'hold expiring at .500 must be treated as expired at .500250'
        );
        self::assertNull(
            $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, $this->patientAId, $exact500),
            'hold expiring at .500 must be treated as expired at exact .500000 boundary'
        );
        $this->fixtureWpdb->query($this->fixtureWpdb->prepare("UPDATE {$holdsTable} SET status = %s WHERE token = %s", 'expired', $equalMsToken));

        // 2c) Later millisecond in the same second (.501 > .500250): live before .501000, expired at .501000.
        $laterToken = 'tok-f2-501-' . bin2hex(random_bytes(6));
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $f2SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $laterToken,
            'expires_at'        => '2030-05-15 14:30:10.501',
            'status'            => 'active',
            'created_at'        => '2030-05-15 14:20:10.000',
            'patient_id'        => $this->patientAId,
        ]);
        $liveRow = $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, $this->patientAId, $fixedNow);
        self::assertIsArray($liveRow, 'hold expiring at .501 must remain live at .500250');
        self::assertSame($laterToken, (string) ($liveRow['token'] ?? ''));
        self::assertSame('2030-05-15 14:30:10.501', (string) ($liveRow['expires_at'] ?? ''));
        self::assertNull(
            $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, $this->patientAId, $exact501),
            'hold expiring at .501 must become expired at .501000'
        );
        $this->fixtureWpdb->query($this->fixtureWpdb->prepare("UPDATE {$holdsTable} SET status = %s WHERE token = %s", 'expired', $laterToken));

        // 2d) Historical NULL-subject SQL branch also enforces sub-second millisecond boundary.
        $nullFracToken = 'tok-f2-null-' . bin2hex(random_bytes(6));
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $f2SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $nullFracToken,
            'expires_at'        => '2030-05-15 14:30:10.400',
            'status'            => 'active',
            'created_at'        => '2030-05-15 14:20:10.000',
            'patient_id'        => null,
        ]);
        self::assertNull(
            $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, null, $fixedNow),
            'NULL-subject hold expiring at .400 must be treated as expired at .500250'
        );
        $this->fixtureWpdb->query(
            $this->fixtureWpdb->prepare(
                "UPDATE {$holdsTable} SET expires_at = %s WHERE token = %s",
                '2030-05-15 14:30:10.800',
                $nullFracToken
            )
        );
        $liveNullRow = $finder->invoke($stack['service'], $this->userAId, $this->clinicId, $f2SlotId, null, $fixedNow);
        self::assertIsArray($liveNullRow, 'NULL-subject hold expiring at .800 must be live at .500250');
        self::assertSame($nullFracToken, (string) ($liveNullRow['token'] ?? ''));
    }

    /**
     * T-R3 (Round 6, R3 / C1):
     *  - End-to-end BookingService::hold() proof (zero sleep) that a stored hold
     *    row whose `expires_at` is empty (''), NULL, omitted, unparseable garbage,
     *    a relative DateTime expression ('+1 hour'), or an out-of-range calendar
     *    date ('2030-02-30 12:00:00.000') fails closed (never reused, never turned
     *    into "now", and never raising an uncaught exception) and mints a fresh
     *    valid hold instead.
     *  - Gate-attribution proof: a positive control on the exact persisted row
     *    without the exact-SQL anchored `query` filter proves every non-expiry
     *    gate accepts `$seedToken`; `$interceptCount === 2` on each subcase proves
     *    both the pre-transaction R3 gate and the in-transaction post-account-lock
     *    R3 gate inspected the row and refused reuse.
     */
    public function testTR3MalformedOrMissingHoldExpiryFailsClosedAndMintsFreshHold(): void
    {
        $db           = new CpmsDb($this->fixtureWpdb);
        $holdsTable   = $this->rawTable('cpms_slot_holds');
        $slotsTable   = $this->rawTable('cpms_schedule_slots');
        $holderMobile = $this->patientMobile($this->patientAId);
        $stack        = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);

        $r3Date    = gmdate('Y-m-d', time() + 9 * 86400);
        $r3SlotId  = $this->insertSlot(8, 1, $r3Date);
        $seedToken = 'tok-r3-seed-' . bin2hex(random_bytes(6));
        $seedExp   = gmdate('Y-m-d H:i:s', time() + 600) . '.000';
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $r3SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $seedToken,
            'expires_at'        => $seedExp,
            'status'            => 'active',
            'created_at'        => gmdate('Y-m-d H:i:s') . '.000',
            'patient_id'        => $this->patientAId,
        ]);

        // Positive baseline: without projection rewrite, hold() reuses $seedToken.
        $baseline = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $r3Date,
                '09:00:00',
                $r3SlotId,
                $this->patientAId
            )
        );
        self::assertSame($seedToken, (string) ($baseline['hold_token'] ?? ''), 'positive control must reuse valid seeded hold');
        self::assertSame(1, (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$r3SlotId]));

        $expectedSelectSql = (string) $this->fixtureWpdb->prepare(
            "SELECT * FROM {$holdsTable} WHERE holder_wp_user_id = %d AND clinic_id = %d AND slot_id = %d AND status = %s AND patient_id = %d ORDER BY expires_at DESC, id DESC LIMIT 1",
            $this->userAId,
            $this->clinicId,
            $r3SlotId,
            'active',
            $this->patientAId
        );

        $projections = [
            'empty_string'     => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, '' AS expires_at, status, created_at, patient_id FROM {$holdsTable}",
            'null_value'       => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, NULL AS expires_at, status, created_at, patient_id FROM {$holdsTable}",
            'omitted_column'   => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, status, created_at, patient_id FROM {$holdsTable}",
            'garbage_text'     => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, 'not-a-valid-utc-datetime' AS expires_at, status, created_at, patient_id FROM {$holdsTable}",
            'relative_expr'    => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, '+1 hour' AS expires_at, status, created_at, patient_id FROM {$holdsTable}",
            'invalid_calendar' => "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, '2030-02-30 12:00:00.000' AS expires_at, status, created_at, patient_id FROM {$holdsTable}",
        ];

        $expectedHeldCount = 1;
        $needlePrefix      = "SELECT * FROM {$holdsTable}";
        foreach ($projections as $label => $selectPrefix) {
            $interceptCount = 0;
            $rewriteFilter  = static function (string $query) use ($expectedSelectSql, $needlePrefix, $selectPrefix, &$interceptCount): string {
                if ($query === $expectedSelectSql) {
                    ++$interceptCount;

                    return $selectPrefix . substr($query, strlen($needlePrefix));
                }

                return $query;
            };

            add_filter('query', $rewriteFilter, 5);
            try {
                $floorBeforeSec = time();
                $minted         = $this->transactionalCommit(
                    $this->fixtureWpdb,
                    fn (): array => $stack['service']->hold(
                        $this->userAId,
                        $this->clinicianId,
                        $r3Date,
                        '09:00:00',
                        $r3SlotId,
                        $this->patientAId
                    )
                );
            } finally {
                remove_filter('query', $rewriteFilter, 5);
            }

            ++$expectedHeldCount;
            $mintedToken = (string) ($minted['hold_token'] ?? '');
            $mintedExp   = (string) ($minted['expires_at'] ?? '');
            self::assertSame(
                2,
                $interceptCount,
                "R3 ({$label}): both pre-tx and in-tx candidate reads must execute and refuse malformed expires_at"
            );
            self::assertNotSame('', $mintedToken, "R3 ({$label}): fresh hold token must be non-empty");
            self::assertNotSame($seedToken, $mintedToken, "R3 ({$label}): malformed expires_at row must never be reused");
            self::assertSame(
                1,
                preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000$/', $mintedExp),
                "R3 ({$label}): minted hold must carry canonical .000 UTC SQL timestamp"
            );
            $parsedMintedExp = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                substr($mintedExp, 0, 19) . '.000000',
                new \DateTimeZone('UTC')
            );
            self::assertInstanceOf(\DateTimeImmutable::class, $parsedMintedExp);
            self::assertGreaterThanOrEqual(
                $floorBeforeSec + 600,
                $parsedMintedExp->getTimestamp(),
                "R3 ({$label}): minted hold must have full future TTL rather than 'now' or raw malformed string"
            );
            self::assertSame(
                $expectedHeldCount,
                (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$r3SlotId]),
                "R3 ({$label}): refusing malformed hold must allocate fresh slot capacity"
            );

            // Mark the newly minted hold released so $seedToken stays the sole active row for the next subcase.
            $this->fixtureWpdb->query(
                $this->fixtureWpdb->prepare(
                    "UPDATE {$holdsTable} SET status = %s WHERE token = %s",
                    'released',
                    $mintedToken
                )
            );
        }

        // Post-filter positive control: $seedToken is still reused once the R3 projection filter is absent.
        $afterControl = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $r3Date,
                '09:00:00',
                $r3SlotId,
                $this->patientAId
            )
        );
        self::assertSame($seedToken, (string) ($afterControl['hold_token'] ?? ''));
    }

    /**
     * T-R1 (Round 6, R1 / C1):
     *  - Part 1 (zero-sleep deterministic post-read proof): proves the reuse
     *    decision evaluates against a fresh instant after the SQL candidate read
     *    returns (and refuses an expired candidate on both pre-tx and in-tx reads).
     *  - Part 2 (bounded child-process Account FOR UPDATE lock-holder proof,
     *    <= 2.25s added wait; child-process lock-wait flake risk): parent holds
     *    cpms_patient_logins FOR UPDATE until INNODB_TRX confirms child LOCK WAIT,
     *    inserts an active hold expiring +500ms into the wait (strictly > child's
     *    pre-tx / pre-lock entry time), waits until >= preWaitEpochSec + 2s and
     *    past that expiry, then COMMITs; child must (a) NOT reuse the hold that
     *    expired during the lock wait and (b) compute its new expires_at from a
     *    fresh post-lock instant (>= commitFloorSec + 600 >= preWaitEpochSec + 602).
     */
    public function testTR1FreshPostReadAndPostLockInstantGovernsReuseAndNewExpiry(): void
    {
        $db           = new CpmsDb($this->fixtureWpdb);
        $holdsTable   = $this->rawTable('cpms_slot_holds');
        $slotsTable   = $this->rawTable('cpms_schedule_slots');
        $holderMobile = $this->patientMobile($this->patientAId);
        $stack        = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);

        // Part 1: zero-sleep deterministic post-read refusal proof.
        $part1Date   = gmdate('Y-m-d', time() + 10 * 86400);
        $part1SlotId = $this->insertSlot(3, 1, $part1Date);
        $seedToken   = 'tok-r1-postread-' . bin2hex(random_bytes(6));
        $this->insertRow($this->fixtureWpdb, $holdsTable, [
            'clinic_id'         => $this->clinicId,
            'slot_id'           => $part1SlotId,
            'holder_wp_user_id' => $this->userAId,
            'holder_mobile'     => $holderMobile,
            'token'             => $seedToken,
            'expires_at'        => gmdate('Y-m-d H:i:s', time() + 600) . '.000',
            'status'            => 'active',
            'created_at'        => gmdate('Y-m-d H:i:s') . '.000',
            'patient_id'        => $this->patientAId,
        ]);

        $control = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold(
                $this->userAId,
                $this->clinicianId,
                $part1Date,
                '09:00:00',
                $part1SlotId,
                $this->patientAId
            )
        );
        self::assertSame($seedToken, (string) ($control['hold_token'] ?? ''));

        $expectedPart1Sql = (string) $this->fixtureWpdb->prepare(
            "SELECT * FROM {$holdsTable} WHERE holder_wp_user_id = %d AND clinic_id = %d AND slot_id = %d AND status = %s AND patient_id = %d ORDER BY expires_at DESC, id DESC LIMIT 1",
            $this->userAId,
            $this->clinicId,
            $part1SlotId,
            'active',
            $this->patientAId
        );
        $needlePrefix  = "SELECT * FROM {$holdsTable}";
        $expiredSelect = "SELECT id, clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, '2020-01-01 00:00:00.000' AS expires_at, status, created_at, patient_id FROM {$holdsTable}";
        $part1Reads    = 0;
        $part1Filter   = static function (string $query) use ($expectedPart1Sql, $needlePrefix, $expiredSelect, &$part1Reads): string {
            if ($query === $expectedPart1Sql) {
                ++$part1Reads;

                return $expiredSelect . substr($query, strlen($needlePrefix));
            }

            return $query;
        };

        add_filter('query', $part1Filter, 5);
        try {
            $part1Minted = $this->transactionalCommit(
                $this->fixtureWpdb,
                fn (): array => $stack['service']->hold(
                    $this->userAId,
                    $this->clinicianId,
                    $part1Date,
                    '09:00:00',
                    $part1SlotId,
                    $this->patientAId
                )
            );
        } finally {
            remove_filter('query', $part1Filter, 5);
        }
        self::assertSame(2, $part1Reads, 'R1 Part 1: both pre-tx and in-tx post-read checks must run and refuse expired candidate');
        self::assertNotSame($seedToken, (string) ($part1Minted['hold_token'] ?? ''));
        self::assertSame(2, (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$part1SlotId]));

        // Part 2: bounded two-connection child-process Account FOR UPDATE lock-holder proof (<= 2.25s added wait).
        $this->assertSubprocessBackendAvailable();
        $contendedDate   = gmdate('Y-m-d', time() + 11 * 86400);
        $contendedSlotId = $this->insertSlot(2, 0, $contendedDate);
        $duringWaitToken = 'tok-r1-wait-' . bin2hex(random_bytes(8));

        $parentMysqli   = null;
        $observerMysqli = null;
        $worker         = null;
        try {
            $parentMysqli   = $this->freshMysqli();
            $observerMysqli = $this->freshMysqli();
            self::assertTrue((bool) $parentMysqli->query('SET SESSION innodb_lock_wait_timeout = 20'));
            self::assertTrue((bool) $parentMysqli->query('BEGIN'));
            $this->lockAccountRowOnMysqli($parentMysqli, $this->userAId);

            $worker      = $this->spawnChildHoldProcess($this->userAId, $contendedSlotId, $contendedDate, '09:00:00', $this->patientAId);
            $childConnId = $this->awaitChildReadyConnectionId($worker);
            $this->awaitChildAccountLockWait($observerMysqli, $childConnId, $worker);

            $lockConfirmedFloat     = microtime(true);
            $preWaitEpochSec        = (int) floor($lockConfirmedFloat);
            $expiringDuringWaitFloat = $lockConfirmedFloat + 0.500;
            $expSec                 = (int) floor($expiringDuringWaitFloat);
            $expMs                  = (int) floor(($expiringDuringWaitFloat - $expSec) * 1000.0);
            if ($expMs > 999) {
                $expMs = 999;
            }
            $expiringDuringWaitSql = gmdate('Y-m-d H:i:s', $expSec) . sprintf('.%03d', $expMs);

            $this->insertHoldRowOnMysqli(
                $parentMysqli,
                $contendedSlotId,
                $this->userAId,
                $this->patientAId,
                $holderMobile,
                $duringWaitToken,
                $expiringDuringWaitSql,
                gmdate('Y-m-d H:i:s') . '.000'
            );
            $this->incrementSlotHeldCountOnceOnMysqli($parentMysqli, $contendedSlotId, gmdate('Y-m-d H:i:s'));

            $waitHardCap = microtime(true) + 2.25;
            while (microtime(true) < $waitHardCap) {
                if (microtime(true) >= ($expiringDuringWaitFloat + 0.150) && time() >= ($preWaitEpochSec + 2)) {
                    break;
                }
                usleep(20000);
            }
            $commitFloorSec = time();
            self::assertGreaterThanOrEqual($preWaitEpochSec + 2, $commitFloorSec, 'bounded wait crossed >= 2 UTC seconds within <= 2.25s');
            self::assertTrue(microtime(true) > $expiringDuringWaitFloat, 'parent COMMIT occurs strictly after during-wait hold expiry');
            self::assertTrue((bool) $parentMysqli->query('COMMIT'));

            $outcome = $this->reapChildHoldProcess($worker);
            self::assertFalse($outcome['signaled'], 'child must not terminate by signal');
            self::assertFalse($outcome['stopped'], 'child must not be stopped');
            self::assertSame(0, $outcome['exit_code'], 'child must exit strictly with code 0; diag=' . $outcome['stderr']);
            self::assertTrue((bool) ($outcome['payload']['ok'] ?? false), 'child hold() must succeed with a fresh hold');

            $childHold      = (array) ($outcome['payload']['result'] ?? []);
            $childToken     = (string) ($childHold['hold_token'] ?? '');
            $childExpiresAt = (string) ($childHold['expires_at'] ?? '');
            self::assertNotSame('', $childToken);
            self::assertNotSame(
                $duringWaitToken,
                $childToken,
                'R1(a): hold whose expires_at elapsed while child waited on account FOR UPDATE must not be reused'
            );
            self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000$/', $childExpiresAt));
            $parsedChildExp = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                substr($childExpiresAt, 0, 19) . '.000000',
                new \DateTimeZone('UTC')
            );
            self::assertInstanceOf(\DateTimeImmutable::class, $parsedChildExp);
            self::assertGreaterThanOrEqual(
                $commitFloorSec + 600,
                $parsedChildExp->getTimestamp(),
                'R1(b): new hold expires_at must be computed from fresh post-lock instant (>= commitFloorSec + 600 >= preWaitEpochSec + 602), never from pre-lock clock'
            );
            self::assertLessThanOrEqual(time() + 660, $parsedChildExp->getTimestamp());
            self::assertSame(
                2,
                (int) $db->fetchValue("SELECT held_count FROM {$slotsTable} WHERE id = %d", [$contendedSlotId]),
                'R1: refusing expired during-wait hold must allocate second slot unit for fresh hold'
            );
        } finally {
            $this->cleanupRaceResources($worker, $parentMysqli, $observerMysqli);
        }
    }

    /**
     * T-R3P (Round 7 ITEM B): Direct ReflectionMethod unit-level verification of
     * BookingService::parse_hold_expires_at_utc(mixed $raw_expires_at): ?DateTimeImmutable.
     *
     * Executes zero SQL queries and zero sleeps. Verifies valid MySQL DATETIME /
     * DATETIME(3) / DATETIME(6) UTC strings parse to UTC DateTimeImmutable with exact
     * microsecond formatting, and every malformed shape (including trailing "\n" that
     * PCRE '$' without 'D' or '\z' would otherwise accept) fails closed to null.
     */
    public function testTR3PParserFailsClosedOnEveryMalformedExpiryShape(): void
    {
        $stack         = $this->stackOn($this->fixtureWpdb, true, $this->userAId, $this->capturedSessions[$this->userAId]);
        $service       = $stack['service'];
        $queriesBefore = (int) $this->fixtureWpdb->num_queries;

        $parser = new \ReflectionMethod(\ClinicCore\Application\Booking\BookingService::class, 'parse_hold_expires_at_utc');
        self::assertTrue($parser->isPrivate(), 'T-R3P: parse_hold_expires_at_utc must remain private');
        self::assertFalse($parser->isStatic(), 'T-R3P: parse_hold_expires_at_utc must be an instance method');
        self::assertSame(1, $parser->getNumberOfRequiredParameters(), 'T-R3P: parse_hold_expires_at_utc must require 1 parameter');

        $validCases = [
            'valid_ms_zero'           => ['2030-01-01 12:00:00.000', '2030-01-01 12:00:00.000000'],
            'valid_1_frac_digit'      => ['2030-01-01 12:00:00.5', '2030-01-01 12:00:00.500000'],
            'valid_2_frac_digits'     => ['2030-01-01 12:00:00.25', '2030-01-01 12:00:00.250000'],
            'valid_3_frac_digits'     => ['2030-01-01 12:00:00.250', '2030-01-01 12:00:00.250000'],
            'valid_6_frac_digits_max' => ['2030-01-01 12:00:00.123456', '2030-01-01 12:00:00.123456'],
            'valid_no_fraction'       => ['2030-01-01 12:00:00', '2030-01-01 12:00:00.000000'],
            'valid_leap_day'          => ['2028-02-29 23:59:59.999', '2028-02-29 23:59:59.999000'],
        ];

        foreach ($validCases as $caseName => $pair) {
            $rawInput          = $pair[0];
            $expectedFormatted = $pair[1];
            $actual            = $parser->invoke($service, $rawInput);

            self::assertInstanceOf(
                \DateTimeImmutable::class,
                $actual,
                'T-R3P valid case must return DateTimeImmutable: ' . $caseName
            );
            self::assertSame(
                $expectedFormatted,
                $actual->format('Y-m-d H:i:s.u'),
                'T-R3P formatted UTC timestamp mismatch for case: ' . $caseName
            );
            self::assertSame(
                'UTC',
                $actual->getTimezone()->getName(),
                'T-R3P timezone name must be UTC for case: ' . $caseName
            );
        }

        $malformedCases = [
            'empty_string'                 => '',
            'null_value'                   => null,
            'int_value'                    => 1893499200,
            'array_value'                  => ['2030-01-01 12:00:00.000'],
            'bool_false'                   => false,
            'relative_now'                 => 'now',
            'relative_plus_one_hour'       => '+1 hour',
            'impossible_date_feb_30'       => '2030-02-30 12:00:00.000',
            'year_0000'                    => '0000-01-01 00:00:00.000',
            'hour_24'                      => '2030-01-01 24:00:00.000',
            'minute_60'                    => '2030-01-01 12:60:00.000',
            'second_60'                    => '2030-01-01 12:00:60.000',
            'trailing_lf_with_fraction'    => "2030-01-01 12:00:00.000\n",
            'trailing_lf_without_fraction' => "2030-01-01 12:00:00\n",
            'trailing_space'               => '2030-01-01 12:00:00.000 ',
            'trailing_crlf'                => "2030-01-01 12:00:00.000\r\n",
            'leading_space'                => ' 2030-01-01 12:00:00.000',
            'leading_lf'                   => "\n2030-01-01 12:00:00.000",
            'iso_t_separator'              => '2030-01-01T12:00:00.000',
            'trailing_letters'             => '2030-01-01 12:00:00.000UTC',
            'embedded_nul_trailing'        => "2030-01-01 12:00:00.000\0",
            'embedded_nul_middle'          => "2030-01-01\0 12:00:00.000",
            'trailing_dot_without_digits'  => '2030-01-01 12:00:00.',
            'seven_frac_digits_overflow'   => '2030-01-01 12:00:00.1234567',
            'non_ascii_persian_digits'     => '۲۰۳۰-۰۱-۰۱ ۱۲:۰۰:۰۰.۰۰۰',
        ];

        foreach ($malformedCases as $caseName => $rawInput) {
            $actual = $parser->invoke($service, $rawInput);
            self::assertNull(
                $actual,
                'T-R3P malformed case must fail closed to null: ' . $caseName
            );
        }

        self::assertSame(
            $queriesBefore,
            (int) $this->fixtureWpdb->num_queries,
            'T-R3P direct parser test must execute zero SQL queries'
        );
    }

    // ================= fixture & subprocess helpers =================

    private function seedSecondLinkedPatientOnAccount(int $userId, string $mrn): int
    {
        $now        = gmdate('Y-m-d H:i:s');
        $patient2Id = $this->insertPatient($mrn, $now);
        $mobile2    = $this->patientMobile($patient2Id);

        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_patient_user_links'), [
            'clinic_id'      => $this->clinicId,
            'patient_id'     => $patient2Id,
            'wp_user_id'     => $userId,
            'mobile_at_link' => $mobile2,
            'is_primary'     => 0,
            'linked_at'      => $now,
        ]);

        $db         = new CpmsDb($this->fixtureWpdb);
        $logins     = new PatientLoginRepository($db);
        $proofStore = new PatientSessionProofStore();
        $token      = $this->capturedTokens[$userId];
        $nowUtc     = $db->nowUtcSql();

        $binding = $logins->upsert_active_binding($userId, $this->clinicId, $patient2Id, $nowUtc);
        $proofStore->record_mobile_proof($userId, $mobile2, null, $token);
        $proofStore->record_binding_proof(
            $userId,
            $this->clinicId,
            $patient2Id,
            (int) $binding['id'],
            (string) $binding['verified_at'],
            null,
            $token
        );

        $this->capturedSessions[$userId] = (array) \WP_Session_Tokens::get_instance($userId)->get($token);

        return $patient2Id;
    }

    private function patientMobile(int $patientId): string
    {
        $mobile = (string) $this->fixtureWpdb->get_var(
            $this->fixtureWpdb->prepare(
                'SELECT mobile FROM ' . $this->rawTable('cpms_patients') . ' WHERE id = %d LIMIT 1',
                $patientId
            )
        );
        self::assertNotSame('', $mobile);

        return $mobile;
    }

    private function assertSubprocessBackendAvailable(): void
    {
        $ok = function_exists('proc_open') && defined('PHP_BINARY') && '' !== PHP_BINARY;
        if ('Linux' === PHP_OS_FAMILY) {
            self::assertTrue(
                $ok,
                'Linux runtime must provide proc_open and non-empty PHP_BINARY regardless of environment'
            );

            return;
        }
        if (!$ok) {
            self::markTestSkipped(
                'Subprocess backend requires proc_open and PHP_BINARY; unavailable on non-Linux OS family: ' . PHP_OS_FAMILY
            );
        }
    }

    private function lockAccountRowOnMysqli(\mysqli $conn, int $userId): void
    {
        $table = $this->rawTable('cpms_patient_logins');
        $stmt  = $conn->prepare("SELECT id FROM `{$table}` WHERE kind = 'account' AND wp_user_id = ? LIMIT 1 FOR UPDATE");
        self::assertInstanceOf(\mysqli_stmt::class, $stmt, 'prepare account FOR UPDATE failed: ' . $conn->error);
        $stmt->bind_param('i', $userId);
        self::assertTrue($stmt->execute(), 'execute account FOR UPDATE failed: ' . $stmt->error);
        $lockedId = 0;
        $stmt->bind_result($lockedId);
        $fetched = $stmt->fetch();
        $stmt->close();
        self::assertTrue((bool) $fetched, 'account row must exist to lock');
        self::assertGreaterThan(0, (int) $lockedId);
    }

    private function insertHoldRowOnMysqli(
        \mysqli $conn,
        int $slotId,
        int $userId,
        int $patientId,
        string $holderMobile,
        string $token,
        string $expiresAt,
        string $createdAt
    ): void {
        $table  = $this->rawTable('cpms_slot_holds');
        $status = 'active';
        $stmt   = $conn->prepare(
            "INSERT INTO `{$table}` (clinic_id, slot_id, holder_wp_user_id, holder_mobile, token, expires_at, status, created_at, patient_id)"
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertInstanceOf(\mysqli_stmt::class, $stmt, 'prepare hold insert failed: ' . $conn->error);
        $clinicId = $this->clinicId;
        $stmt->bind_param(
            'iiisssssi',
            $clinicId,
            $slotId,
            $userId,
            $holderMobile,
            $token,
            $expiresAt,
            $status,
            $createdAt,
            $patientId
        );
        self::assertTrue($stmt->execute(), 'execute hold insert failed: ' . $stmt->error);
        self::assertSame(1, (int) $stmt->affected_rows);
        $stmt->close();
    }

    private function incrementSlotHeldCountOnceOnMysqli(\mysqli $conn, int $slotId, string $updatedAt): void
    {
        $table = $this->rawTable('cpms_schedule_slots');
        $stmt  = $conn->prepare(
            "UPDATE `{$table}` SET held_count = held_count + 1, updated_at = ? WHERE id = ? AND is_open = 1 AND capacity - booked_count - held_count > 0"
        );
        self::assertInstanceOf(\mysqli_stmt::class, $stmt, 'prepare slot held_count update failed: ' . $conn->error);
        $stmt->bind_param('si', $updatedAt, $slotId);
        self::assertTrue($stmt->execute(), 'execute slot held_count update failed: ' . $stmt->error);
        self::assertSame(1, (int) $stmt->affected_rows);
        $stmt->close();
    }

    /**
     * Spawns an isolated PHP CLI subprocess (inheriting zero parent DB sockets)
     * that boots WordPress core + Composer autoload without test SAVEPOINT
     * filters and calls the real BookingService::hold() against $this->disposableDb.
     *
     * @return array{
     *     process: resource,
     *     pipes: array<int, resource>,
     *     stdout: string,
     *     stderr: string,
     *     exit_code: int|null,
     *     signaled: bool,
     *     stopped: bool
     * }
     */
    private function spawnChildHoldProcess(
        int $userId,
        int $slotId,
        string $slotDate,
        string $slotTime,
        int $patientId
    ): array {
        $pluginRoot = dirname(__DIR__, 2);
        $testsDir   = rtrim((string) (getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-test-lib'), '/');
        $configPath = (string) (getenv('WP_TESTS_CONFIG_FILE_PATH') ?: $testsDir . '/wp-tests-config.php');

        $env = [
            'PATH'                      => (string) getenv('PATH'),
            'HOME'                      => (string) getenv('HOME'),
            'WP_TESTS_DIR'              => $testsDir,
            'WP_TESTS_CONFIG_FILE_PATH' => $configPath,
            'WP_CORE_DIR'               => rtrim((string) ABSPATH, '/'),
            'CPMS_HOLD_DB_NAME'         => $this->disposableDb,
            'CPMS_HOLD_DB_USER'         => (string) DB_USER,
            'CPMS_HOLD_DB_PASSWORD'     => (string) DB_PASSWORD,
            'CPMS_HOLD_DB_HOST'         => (string) DB_HOST,
            'CPMS_HOLD_TABLE_PREFIX'    => (string) $this->fixtureWpdb->prefix,
            'CPMS_HOLD_USER_ID'         => (string) $userId,
            'CPMS_HOLD_CLINICIAN_ID'    => (string) $this->clinicianId,
            'CPMS_HOLD_SLOT_DATE'       => $slotDate,
            'CPMS_HOLD_SLOT_TIME'       => $slotTime,
            'CPMS_HOLD_SLOT_ID'         => (string) $slotId,
            'CPMS_HOLD_PATIENT_ID'      => (string) $patientId,
            'CPMS_HOLD_SESSION_TOKEN'   => (string) ($this->capturedTokens[$userId] ?? ''),
            'CPMS_HOLD_SESSION_JSON'    => (string) json_encode($this->capturedSessions[$userId] ?? []),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Map every parent fd > 2 to /dev/null so parent MySQL sockets never survive execvp().
        $fdEntries = @scandir('/proc/self/fd');
        if (is_array($fdEntries)) {
            foreach ($fdEntries as $entry) {
                if (is_string($entry) && ctype_digit($entry)) {
                    $fd = (int) $entry;
                    if ($fd > 2 && !isset($descriptors[$fd]) && false !== @readlink('/proc/self/fd/' . $fd)) {
                        $descriptors[$fd] = ['null'];
                    }
                }
            }
        }

        $process = @proc_open([PHP_BINARY, '-r', self::childWorkerPhpCode()], $descriptors, $pipes, $pluginRoot, $env);
        if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
            throw new RuntimeException('failed to launch child BookingService::hold() process');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process'    => $process,
            'pipes'      => [1 => $pipes[1], 2 => $pipes[2]],
            'stdout'     => '',
            'stderr'     => '',
            'last_stage' => 'spawned',
            'exit_code'  => null,
            'signaled'   => false,
            'stopped'    => false,
        ];
    }

    /**
     * @param array<string, mixed> $worker
     */
    private function pumpChildWorker(array &$worker): ?array
    {
        if (isset($worker['pipes'][1]) && is_resource($worker['pipes'][1])) {
            $chunk = (string) stream_get_contents($worker['pipes'][1]);
            if ('' !== $chunk) {
                $worker['stdout'] .= $chunk;
            }
        }
        if (isset($worker['pipes'][2]) && is_resource($worker['pipes'][2])) {
            $chunk = (string) stream_get_contents($worker['pipes'][2]);
            if ('' !== $chunk) {
                $worker['stderr'] .= $chunk;
            }
        }
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            return null;
        }
        $status = @proc_get_status($worker['process']);
        if (is_array($status) && false === ($status['running'] ?? true) && null === $worker['exit_code']) {
            $worker['exit_code'] = (int) ($status['exitcode'] ?? -1);
            $worker['signaled']  = (bool) ($status['signaled'] ?? false);
            $worker['stopped']   = (bool) ($status['stopped'] ?? false);
        }

        return is_array($status) ? $status : null;
    }

    /**
     * Updates $worker['last_stage'] from any complete JSON lines buffered in
     * stdout without consuming them, keeping strictly alphanumeric stage tokens.
     *
     * @param array<string, mixed> $worker
     */
    private function refreshLastChildStage(array &$worker): string
    {
        $buf   = (string) ($worker['stdout'] ?? '');
        $parts = explode("\n", $buf);
        array_pop($parts);
        foreach ($parts as $rawLine) {
            $trimmed = trim($rawLine);
            if ('' === $trimmed) {
                continue;
            }
            $decoded = json_decode($trimmed, true);
            if (
                is_array($decoded)
                && isset($decoded['stage'])
                && is_string($decoded['stage'])
                && 1 === preg_match('/^[a-z0-9_]{1,32}$/', $decoded['stage'])
            ) {
                $worker['last_stage'] = $decoded['stage'];
            }
        }

        $stage = (string) ($worker['last_stage'] ?? 'unknown');

        return 1 === preg_match('/^[a-z0-9_]{1,32}$/', $stage) ? $stage : 'unknown';
    }

    /**
     * @param array<string, mixed> $worker
     */
    private function awaitChildReadyConnectionId(array &$worker): int
    {
        $deadline = microtime(true) + self::CHILD_READY_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            $status = $this->pumpChildWorker($worker);
            $pos    = strpos((string) $worker['stdout'], "\n");
            if (false !== $pos) {
                $firstLine = trim(substr((string) $worker['stdout'], 0, $pos));
                $worker['stdout'] = substr((string) $worker['stdout'], $pos + 1);
                $decoded = json_decode($firstLine, true);
                if (is_array($decoded) && 'ready' === ($decoded['stage'] ?? '') && (int) ($decoded['connection_id'] ?? 0) > 0) {
                    $worker['last_stage'] = 'ready';

                    return (int) $decoded['connection_id'];
                }
                $this->terminateChildWorker($worker);
                self::fail('unexpected child ready handshake');
            }
            if (is_array($status) && false === ($status['running'] ?? true) && false === strpos((string) $worker['stdout'], "\n")) {
                $this->pumpChildWorker($worker);
                $lastStage = $this->refreshLastChildStage($worker);
                self::fail(
                    'child exited before READY handshake; exit_code='
                    . (string) (int) ($worker['exit_code'] ?? -1)
                    . '; last_stage=' . $lastStage
                );
            }
            usleep(10000);
        }

        $this->terminateChildWorker($worker);
        self::fail('timed out waiting for child READY connection_id');
    }

    /**
     * Polls ONLY information_schema.INNODB_TRX for trx_state = 'LOCK WAIT' on
     * the child's MySQL thread ID with a bounded loop and hard timeout, after
     * observing the child's pipe stage 'lock_query' (emitted immediately before
     * the account FOR UPDATE statement) and sleeping >= 120 ms (> MySQL 8.0
     * InnoDB trx0i_s.cc CACHE_MIN_IDLE_TIME = 100 ms) so trx_i_s_cache never
     * starves on a pre-lock snapshot. Emits strictly value-free diagnostics on
     * premature exit or timeout.
     *
     * @param array<string, mixed> $worker
     */
    private function awaitChildAccountLockWait(\mysqli $observer, int $childConnId, array &$worker): void
    {
        $deadline = microtime(true) + self::LOCK_WAIT_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            $status    = $this->pumpChildWorker($worker);
            $lastStage = $this->refreshLastChildStage($worker);
            if ('lock_query' === $lastStage || 'done' === $lastStage) {
                break;
            }
            if (is_array($status) && false === ($status['running'] ?? true)) {
                $this->pumpChildWorker($worker);
                $lastStage = $this->refreshLastChildStage($worker);
                self::fail(
                    'child exited prematurely before dispatching account FOR UPDATE'
                    . '; child_running=false'
                    . '; child_exit_code=' . (string) (int) ($worker['exit_code'] ?? -1)
                    . '; last_stage=' . $lastStage
                );
            }
            usleep(10000);
        }

        // Sleep > 100 ms (InnoDB trx_i_s_cache CACHE_MIN_IDLE_TIME = 100000 us)
        // before the first INNODB_TRX read and between polls so every read
        // forces a fresh trx_i_s_cache snapshot instead of resetting last_read
        // on a stale pre-lock entry.
        usleep(120000);

        $sql = 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = '
            . (int) $childConnId . " AND trx_state = 'LOCK WAIT'";

        while (microtime(true) < $deadline) {
            $result = $observer->query($sql);
            if (false === $result) {
                $this->terminateChildWorker($worker);
                self::fail('information_schema.INNODB_TRX query failed');
            }
            $row = $result->fetch_row();
            $result->free();
            if ((int) ($row[0] ?? 0) >= 1) {
                return;
            }

            $status = $this->pumpChildWorker($worker);
            if (is_array($status) && false === ($status['running'] ?? true)) {
                $this->pumpChildWorker($worker);
                $lastStage = $this->refreshLastChildStage($worker);
                self::fail(
                    'child exited prematurely without entering INNODB_TRX LOCK WAIT'
                    . '; child_running=false'
                    . '; child_exit_code=' . (string) (int) ($worker['exit_code'] ?? -1)
                    . '; last_stage=' . $lastStage
                );
            }
            usleep(120000);
        }

        usleep(120000);
        $finalStatus  = $this->pumpChildWorker($worker);
        $childRunning = is_array($finalStatus) && (bool) ($finalStatus['running'] ?? false);
        $childExit    = $childRunning
            ? 'none'
            : (string) (null !== $worker['exit_code'] ? (int) $worker['exit_code'] : (int) ($finalStatus['exitcode'] ?? -1));
        $lastStage    = $this->refreshLastChildStage($worker);

        $trxCountDiag  = 'unreadable';
        $trxStatesDiag = 'unreadable';
        $trxRes        = @$observer->query(
            'SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ' . (int) $childConnId
        );
        if ($trxRes instanceof \mysqli_result) {
            $states = [];
            while (null !== ($trxRow = $trxRes->fetch_row())) {
                $cleanState = trim((string) preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($trxRow[0] ?? '')));
                $states[]   = '' !== $cleanState ? substr($cleanState, 0, 32) : 'empty';
            }
            $trxRes->free();
            $trxCountDiag  = (string) count($states);
            $trxStatesDiag = [] === $states ? 'none' : implode(',', $states);
        }

        $processlistDiag = 'unreadable';
        $plRes           = @$observer->query(
            'SELECT COMMAND, STATE FROM information_schema.PROCESSLIST WHERE ID = ' . (int) $childConnId
        );
        if ($plRes instanceof \mysqli_result) {
            $plRow = $plRes->fetch_row();
            $plRes->free();
            if (null === $plRow) {
                $processlistDiag = 'not_found';
            } else {
                $cmdRaw   = (string) preg_replace('/\d+/', '#', (string) ($plRow[0] ?? ''));
                $stateRaw = (string) preg_replace('/\d+/', '#', (string) ($plRow[1] ?? ''));
                $cmdClean = trim((string) preg_replace('/[^A-Za-z# _:\/.-]/', '', $cmdRaw));
                $stClean  = trim((string) preg_replace('/[^A-Za-z# _:\/.-]/', '', $stateRaw));
                $processlistDiag = ('' !== $cmdClean ? substr($cmdClean, 0, 24) : 'empty')
                    . ':'
                    . ('' !== $stClean ? substr($stClean, 0, 64) : 'empty');
            }
        }

        $this->terminateChildWorker($worker);
        $diagMessage = 'timed out waiting for child transaction to reach INNODB_TRX trx_state = LOCK WAIT'
            . '; child_running=' . ($childRunning ? 'true' : 'false')
            . '; child_exit_code=' . $childExit
            . '; last_stage=' . $lastStage
            . '; innodb_trx_count=' . $trxCountDiag
            . '; innodb_trx_states=' . $trxStatesDiag
            . '; processlist_state=' . $processlistDiag;
        if (!$childRunning) {
            self::fail($diagMessage);
        }
        if ('lock_query' !== $lastStage) {
            self::fail($diagMessage);
        }
        if ('0' === $trxCountDiag && str_starts_with($processlistDiag, 'Sleep:')) {
            self::fail($diagMessage);
        }
        if ('0' === $trxCountDiag) {
            self::fail($diagMessage);
        }
        if (str_contains($trxStatesDiag, 'RUNNING')) {
            self::fail($diagMessage);
        }
        self::fail($diagMessage);
    }

    /**
     * @param array<string, mixed> $worker
     * @return array{exit_code: int, signaled: bool, stopped: bool, payload: array<string, mixed>, stderr: string}
     */
    private function reapChildHoldProcess(array &$worker): array
    {
        $deadline = microtime(true) + self::CHILD_EXIT_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            $status = $this->pumpChildWorker($worker);
            if (is_array($status) && false === ($status['running'] ?? true)) {
                $this->pumpChildWorker($worker);
                foreach ([1, 2] as $idx) {
                    if (isset($worker['pipes'][$idx]) && is_resource($worker['pipes'][$idx])) {
                        fclose($worker['pipes'][$idx]);
                    }
                }
                $worker['pipes'] = [];
                $closeCode = -1;
                if (isset($worker['process']) && is_resource($worker['process'])) {
                    $closeCode = @proc_close($worker['process']);
                    $worker['process'] = null;
                }
                $exitCode = null !== $worker['exit_code'] ? (int) $worker['exit_code'] : (int) $closeCode;
                $lines    = array_values(array_filter(array_map('trim', explode("\n", (string) $worker['stdout'])), static fn (string $l): bool => '' !== $l));
                $lastJson = [] !== $lines ? json_decode((string) end($lines), true) : null;

                return [
                    'exit_code' => $exitCode,
                    'signaled'  => (bool) $worker['signaled'],
                    'stopped'   => (bool) $worker['stopped'],
                    'payload'   => is_array($lastJson) ? $lastJson : [],
                    'stderr'    => trim((string) $worker['stderr']),
                ];
            }
            usleep(10000);
        }

        $this->terminateChildWorker($worker);
        self::fail('timed out waiting for child BookingService::hold() process to exit');
    }

    /**
     * @param array<string, mixed>|null $worker
     */
    private function terminateChildWorker(?array &$worker): void
    {
        if (!is_array($worker)) {
            return;
        }
        if (isset($worker['process']) && is_resource($worker['process'])) {
            $status = @proc_get_status($worker['process']);
            if (is_array($status) && ($status['running'] ?? false)) {
                @proc_terminate($worker['process'], 9);
                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline) {
                    $st = @proc_get_status($worker['process']);
                    if (!is_array($st) || false === ($st['running'] ?? false)) {
                        break;
                    }
                    usleep(10000);
                }
            }
            foreach ([1, 2] as $idx) {
                if (isset($worker['pipes'][$idx]) && is_resource($worker['pipes'][$idx])) {
                    @fclose($worker['pipes'][$idx]);
                }
            }
            $worker['pipes'] = [];
            @proc_close($worker['process']);
            $worker['process'] = null;
        }
    }

    /**
     * @param array<string, mixed>|null $worker
     */
    private function cleanupRaceResources(?array &$worker, ?\mysqli $parentMysqli, ?\mysqli $observerMysqli): void
    {
        $this->terminateChildWorker($worker);
        if ($parentMysqli instanceof \mysqli) {
            try {
                @$parentMysqli->query('ROLLBACK');
            } catch (Throwable) {
            }
            try {
                @$parentMysqli->close();
            } catch (Throwable) {
            }
        }
        if ($observerMysqli instanceof \mysqli) {
            try {
                @$observerMysqli->close();
            } catch (Throwable) {
            }
        }
    }

    private static function childWorkerPhpCode(): string
    {
        return implode("\n", [
            'ini_set("display_errors", "0");',
            'ini_set("log_errors", "0");',
            '$emit = static function (array $msg): void {',
            '    fwrite(STDOUT, json_encode($msg) . "\n");',
            '    fflush(STDOUT);',
            '};',
            'try {',
            '    $pluginRoot = getcwd();',
            '    $configPath = (string) getenv("WP_TESTS_CONFIG_FILE_PATH");',
            '    $autoload   = $pluginRoot . "/vendor/autoload.php";',
            '    if ("" === $configPath || !is_file($configPath) || !is_file($autoload)) {',
            '        $emit(["stage" => "done", "ok" => false, "error" => "BOOT_PATHS_MISSING"]);',
            '        exit(2);',
            '    }',
            '    ob_start();',
            '    require $configPath;',
            '    if (!defined("ABSPATH")) {',
            '        $coreDir = rtrim((string) getenv("WP_CORE_DIR"), "/");',
            '        if ("" !== $coreDir) {',
            '            define("ABSPATH", $coreDir . "/");',
            '        }',
            '    }',
            '    if (!defined("WP_USE_THEMES")) {',
            '        define("WP_USE_THEMES", false);',
            '    }',
            '    if (!defined("DISABLE_WP_CRON")) {',
            '        define("DISABLE_WP_CRON", true);',
            '    }',
            '    require_once rtrim((string) ABSPATH, "/") . "/wp-settings.php";',
            '    require_once $autoload;',
            '    ob_end_clean();',
            '    remove_all_filters("query");',
            '    $dbName      = (string) getenv("CPMS_HOLD_DB_NAME");',
            '    $dbUser      = (string) getenv("CPMS_HOLD_DB_USER");',
            '    $dbPass      = (string) getenv("CPMS_HOLD_DB_PASSWORD");',
            '    $dbHost      = (string) getenv("CPMS_HOLD_DB_HOST");',
            '    $prefix      = (string) getenv("CPMS_HOLD_TABLE_PREFIX");',
            '    $userId      = (int) getenv("CPMS_HOLD_USER_ID");',
            '    $clinicianId = (int) getenv("CPMS_HOLD_CLINICIAN_ID");',
            '    $slotDate    = (string) getenv("CPMS_HOLD_SLOT_DATE");',
            '    $slotTime    = (string) getenv("CPMS_HOLD_SLOT_TIME");',
            '    $slotId      = (int) getenv("CPMS_HOLD_SLOT_ID");',
            '    $patientId   = (int) getenv("CPMS_HOLD_PATIENT_ID");',
            '    $sessToken   = (string) getenv("CPMS_HOLD_SESSION_TOKEN");',
            '    $sessData    = json_decode((string) getenv("CPMS_HOLD_SESSION_JSON"), true);',
            '    if (!is_array($sessData)) {',
            '        $sessData = [];',
            '    }',
            '    $own = new \wpdb($dbUser, $dbPass, $dbName, $dbHost);',
            '    $own->set_prefix($prefix);',
            '    $own->show_errors(false);',
            '    $own->suppress_errors(false);',
            '    if (!$own->check_connection(false)) {',
            '        $emit(["stage" => "done", "ok" => false, "error" => "DB_CONNECT_FAILED"]);',
            '        exit(3);',
            '    }',
            '    $own->query("SET SESSION innodb_lock_wait_timeout = 20");',
            '    $connId = (int) $own->get_var("SELECT CONNECTION_ID()");',
            '    if ($connId <= 0) {',
            '        $emit(["stage" => "done", "ok" => false, "error" => "NO_CONN_ID"]);',
            '        exit(4);',
            '    }',
            '    $db              = new \ClinicCore\Infrastructure\Db\CpmsDb($own);',
            '    $op              = new \ClinicCore\Infrastructure\Logging\OpLogger($db);',
            '    $audit           = new \ClinicCore\Infrastructure\Audit\AuditLogger($db, $op);',
            '    $settingsFactory = new \ClinicCore\Settings\SettingsFactory($db, $audit);',
            '    $idem            = new \ClinicCore\Infrastructure\Security\Idempotency($db);',
            '    $registry        = new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry();',
            '    $registry->register(new \ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider($op));',
            '    $registry->register(new \ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider(static fn (): array => []));',
            '    $sms = new \ClinicCore\Application\Notifications\SmsService(',
            '        $db,',
            '        $settingsFactory,',
            '        $registry,',
            '        new \ClinicCore\Infrastructure\Sms\CredentialVault(),',
            '        $audit,',
            '        $op,',
            '        new \ClinicCore\Infrastructure\Queue\JobQueue($db, $op),',
            '        static fn (): int => 0',
            '    );',
            '    $license  = new \ClinicCore\Domain\Licensing\ActiveLicenseGate();',
            '    $sessions = new \ClinicCore\Application\Auth\PatientSessionScope(',
            '        $db,',
            '        static fn (int $uid, ?string $tok) => $uid === $userId ? $sessData : null,',
            '        static fn (int $uid): array => []',
            '    );',
            '    $proofStore = new \ClinicCore\Application\Auth\PatientSessionProofStore(',
            '        static fn (int $uid): object => new class($sessToken, $sessData) {',
            '            public function __construct(',
            '                private readonly string $expectedToken,',
            '                private readonly array $session',
            '            ) {',
            '            }',
            '            public function get(string $token): ?array',
            '            {',
            '                return ("" !== $this->expectedToken && $token === $this->expectedToken) ? $this->session : null;',
            '            }',
            '            public function update(string $token, array $session): void',
            '            {',
            '            }',
            '            public function destroy_all(): void',
            '            {',
            '            }',
            '        },',
            '        static fn (): string => $sessToken',
            '    );',
            '    $patientAccess = new \ClinicCore\Application\Auth\PatientAccess(',
            '        $db,',
            '        new \ClinicCore\Infrastructure\Repository\PatientRepository($db),',
            '        static fn (int $uid): ?array => $uid === $userId',
            '            ? [',
            '                "exists"        => true,',
            '                "roles"         => [\ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT],',
            '                "has_staff_cap" => false,',
            '            ]',
            '            : null,',
            '        logins: new \ClinicCore\Infrastructure\Repository\PatientLoginRepository($db),',
            '        proofs: $proofStore',
            '    );',
            '    $service = new \ClinicCore\Application\Booking\BookingService(',
            '        $db,',
            '        new \ClinicCore\Infrastructure\Repository\SlotRepository($db),',
            '        new \ClinicCore\Infrastructure\Repository\AppointmentRepository($db),',
            '        new \ClinicCore\Infrastructure\Repository\PatientRepository($db),',
            '        $settingsFactory,',
            '        $license,',
            '        $audit,',
            '        $op,',
            '        $idem,',
            '        $sms,',
            '        null,',
            '        null,',
            '        null,',
            '        $sessions,',
            '        $patientAccess',
            '    );',
            '    $loginsTableLower = strtolower($db->table("cpms_patient_logins"));',
            '    $lockQueryEmitted = false;',
            '    add_filter(',
            '        "query",',
            '        static function ($query) use ($emit, $connId, $loginsTableLower, &$lockQueryEmitted) {',
            '            if (!$lockQueryEmitted && is_string($query)) {',
            '                $norm = strtolower(trim((string) preg_replace("/\s+/", " ", $query)));',
            '                if (str_contains($norm, "from " . $loginsTableLower . " ") && str_ends_with($norm, " for update")) {',
            '                    $lockQueryEmitted = true;',
            '                    $emit(["stage" => "lock_query", "connection_id" => $connId]);',
            '                }',
            '            }',
            '            return $query;',
            '        },',
            '        0',
            '    );',
            '    $emit(["stage" => "ready", "connection_id" => $connId]);',
            '    $result = $service->hold($userId, $clinicianId, $slotDate, $slotTime, $slotId, $patientId);',
            '    $own->close();',
            '    $emit(["stage" => "done", "ok" => true, "result" => $result]);',
            '    exit(0);',
            '} catch (\ClinicCore\Domain\Booking\BookingException $e) {',
            '    $emit([',
            '        "stage"       => "done",',
            '        "ok"          => false,',
            '        "error_code"  => $e->errorCode,',
            '        "http_status" => $e->httpStatus,',
            '    ]);',
            '    exit(1);',
            '} catch (\Throwable $e) {',
            '    $emit([',
            '        "stage" => "done",',
            '        "ok"    => false,',
            '        "error" => get_class($e),',
            '    ]);',
            '    exit(1);',
            '}',
        ]);
    }
}

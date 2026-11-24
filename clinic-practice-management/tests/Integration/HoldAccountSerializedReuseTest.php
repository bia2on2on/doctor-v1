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
        if ('true' === (string) getenv('GITHUB_ACTIONS')) {
            self::assertTrue($ok, 'CI integration job must provide proc_open + PHP_BINARY');
        }
        if (!$ok) {
            self::markTestSkipped('proc_open / PHP_BINARY unavailable in this local runtime');
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
            'process'   => $process,
            'pipes'     => [1 => $pipes[1], 2 => $pipes[2]],
            'stdout'    => '',
            'stderr'    => '',
            'exit_code' => null,
            'signaled'  => false,
            'stopped'   => false,
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
                    return (int) $decoded['connection_id'];
                }
                $this->terminateChildWorker($worker);
                self::fail('unexpected child ready handshake: ' . $firstLine);
            }
            if (is_array($status) && false === ($status['running'] ?? true) && false === strpos((string) $worker['stdout'], "\n")) {
                $this->pumpChildWorker($worker);
                self::fail('child exited before READY handshake; stdout=' . $worker['stdout'] . ' stderr=' . $worker['stderr']);
            }
            usleep(10000);
        }

        $this->terminateChildWorker($worker);
        self::fail('timed out waiting for child READY connection_id');
    }

    /**
     * Polls ONLY information_schema.INNODB_TRX for trx_state = 'LOCK WAIT' on
     * the child's MySQL thread ID with a bounded loop and hard timeout.
     *
     * @param array<string, mixed> $worker
     */
    private function awaitChildAccountLockWait(\mysqli $observer, int $childConnId, array &$worker): void
    {
        $deadline = microtime(true) + self::LOCK_WAIT_TIMEOUT_SEC;
        $sql      = 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = '
            . (int) $childConnId . " AND trx_state = 'LOCK WAIT'";

        while (microtime(true) < $deadline) {
            $result = $observer->query($sql);
            if (false === $result) {
                $this->terminateChildWorker($worker);
                self::fail('information_schema.INNODB_TRX query failed: ' . $observer->error);
            }
            $row = $result->fetch_row();
            $result->free();
            if ((int) ($row[0] ?? 0) >= 1) {
                return;
            }

            $status = $this->pumpChildWorker($worker);
            if (is_array($status) && false === ($status['running'] ?? true)) {
                $this->pumpChildWorker($worker);
                self::fail(
                    'child exited prematurely without entering INNODB_TRX LOCK WAIT; exit='
                    . var_export($worker['exit_code'], true)
                    . ' stdout=' . $worker['stdout']
                    . ' stderr=' . $worker['stderr']
                );
            }
            usleep(10000);
        }

        $this->terminateChildWorker($worker);
        self::fail('timed out waiting for child transaction to reach INNODB_TRX trx_state = LOCK WAIT');
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
        return <<<'PHP'
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$emit = static function (array $msg): void {
    fwrite(STDOUT, json_encode($msg) . "\n");
    fflush(STDOUT);
};

try {
    $pluginRoot = getcwd();
    $configPath = (string) getenv('WP_TESTS_CONFIG_FILE_PATH');
    $autoload   = $pluginRoot . '/vendor/autoload.php';
    if ('' === $configPath || !is_file($configPath) || !is_file($autoload)) {
        $emit(['stage' => 'done', 'ok' => false, 'error' => 'BOOT_PATHS_MISSING']);
        exit(2);
    }

    ob_start();
    require $configPath;
    if (!defined('ABSPATH')) {
        $coreDir = rtrim((string) getenv('WP_CORE_DIR'), '/');
        if ('' !== $coreDir) {
            define('ABSPATH', $coreDir . '/');
        }
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    require_once rtrim((string) ABSPATH, '/') . '/wp-settings.php';
    require_once $autoload;
    ob_end_clean();

    remove_all_filters('query');

    $dbName      = (string) getenv('CPMS_HOLD_DB_NAME');
    $dbUser      = (string) getenv('CPMS_HOLD_DB_USER');
    $dbPass      = (string) getenv('CPMS_HOLD_DB_PASSWORD');
    $dbHost      = (string) getenv('CPMS_HOLD_DB_HOST');
    $prefix      = (string) getenv('CPMS_HOLD_TABLE_PREFIX');
    $userId      = (int) getenv('CPMS_HOLD_USER_ID');
    $clinicianId = (int) getenv('CPMS_HOLD_CLINICIAN_ID');
    $slotDate    = (string) getenv('CPMS_HOLD_SLOT_DATE');
    $slotTime    = (string) getenv('CPMS_HOLD_SLOT_TIME');
    $slotId      = (int) getenv('CPMS_HOLD_SLOT_ID');
    $patientId   = (int) getenv('CPMS_HOLD_PATIENT_ID');
    $sessToken   = (string) getenv('CPMS_HOLD_SESSION_TOKEN');
    $sessData    = json_decode((string) getenv('CPMS_HOLD_SESSION_JSON'), true);
    if (!is_array($sessData)) {
        $sessData = [];
    }

    $own = new \wpdb($dbUser, $dbPass, $dbName, $dbHost);
    $own->set_prefix($prefix);
    $own->show_errors(false);
    $own->suppress_errors(false);
    if (!$own->check_connection(false)) {
        $emit(['stage' => 'done', 'ok' => false, 'error' => 'DB_CONNECT_FAILED']);
        exit(3);
    }
    $own->query('SET SESSION innodb_lock_wait_timeout = 20');
    $connId = (int) $own->get_var('SELECT CONNECTION_ID()');
    if ($connId <= 0) {
        $emit(['stage' => 'done', 'ok' => false, 'error' => 'NO_CONN_ID']);
        exit(4);
    }

    $db              = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
    $op              = new \ClinicCore\Infrastructure\Logging\OpLogger($db);
    $audit           = new \ClinicCore\Infrastructure\Audit\AuditLogger($db, $op);
    $settingsFactory = new \ClinicCore\Settings\SettingsFactory($db, $audit);
    $idem            = new \ClinicCore\Infrastructure\Security\Idempotency($db);
    $registry        = new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry();
    $registry->register(new \ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider($op));
    $registry->register(new \ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider(static fn (): array => []));
    $sms = new \ClinicCore\Application\Notifications\SmsService(
        $db,
        $settingsFactory,
        $registry,
        new \ClinicCore\Infrastructure\Sms\CredentialVault(),
        $audit,
        $op,
        new \ClinicCore\Infrastructure\Queue\JobQueue($db, $op),
        static fn (): int => 0
    );
    $license  = new \ClinicCore\Domain\Licensing\ActiveLicenseGate();
    $sessions = new \ClinicCore\Application\Auth\PatientSessionScope(
        $db,
        static fn (int $uid, ?string $tok) => $uid === $userId ? $sessData : null,
        static fn (int $uid): array => []
    );
    $proofStore = new \ClinicCore\Application\Auth\PatientSessionProofStore(
        static fn (int $uid): object => new class($sessToken, $sessData) {
            /** @param array<string, mixed> $session */
            public function __construct(
                private readonly string $expectedToken,
                private readonly array $session
            ) {
            }

            /** @return array<string, mixed>|null */
            public function get(string $token): ?array
            {
                return ('' !== $this->expectedToken && $token === $this->expectedToken) ? $this->session : null;
            }

            /** @param array<string, mixed> $session */
            public function update(string $token, array $session): void
            {
            }

            public function destroy_all(): void
            {
            }
        },
        static fn (): string => $sessToken
    );
    $patientAccess = new \ClinicCore\Application\Auth\PatientAccess(
        $db,
        new \ClinicCore\Infrastructure\Repository\PatientRepository($db),
        static fn (int $uid): ?array => $uid === $userId
            ? [
                'exists'        => true,
                'roles'         => [\ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT],
                'has_staff_cap' => false,
            ]
            : null,
        logins: new \ClinicCore\Infrastructure\Repository\PatientLoginRepository($db),
        proofs: $proofStore
    );
    $service = new \ClinicCore\Application\Booking\BookingService(
        $db,
        new \ClinicCore\Infrastructure\Repository\SlotRepository($db),
        new \ClinicCore\Infrastructure\Repository\AppointmentRepository($db),
        new \ClinicCore\Infrastructure\Repository\PatientRepository($db),
        $settingsFactory,
        $license,
        $audit,
        $op,
        $idem,
        $sms,
        null,
        null,
        null,
        $sessions,
        $patientAccess
    );

    $emit(['stage' => 'ready', 'connection_id' => $connId]);

    $result = $service->hold($userId, $clinicianId, $slotDate, $slotTime, $slotId, $patientId);
    $own->close();
    $emit(['stage' => 'done', 'ok' => true, 'result' => $result]);
    exit(0);
} catch (\ClinicCore\Domain\Booking\BookingException $e) {
    $emit([
        'stage'       => 'done',
        'ok'          => false,
        'error_code'  => $e->errorCode,
        'http_status' => $e->httpStatus,
    ]);
    exit(1);
} catch (\Throwable $e) {
    $emit([
        'stage' => 'done',
        'ok'    => false,
        'error' => get_class($e),
    ]);
    exit(1);
}
PHP;
    }
}

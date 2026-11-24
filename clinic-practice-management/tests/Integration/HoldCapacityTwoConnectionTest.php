<?php

/**
 * Two-connection capacity tests against InnoDB — genuinely overlapping
 * transactions on an ISOLATED, DISPOSABLE database (CI-only).
 *
 * Review-driven redesign (2026-11-07). The previous revision of this file
 * (a) ran against the shared WP test database, (b) committed fixtures into
 * it, (c) deleted jobs/logs/holds by a high-water mark (blast radius: any
 * rows written concurrently, including other tests' rows), and (d) sequenced
 * all phases inside one process, which cannot demonstrate lock waiting. This
 * revision fixes all four:
 *
 *  - Isolation: every test creates a unique schema `cpms_t2c_<hex>` (CREATE
 *    DATABASE), runs the real plugin migrations inside it, points two
 *    independent connections at it, and DROPs the schema in tearDown's
 *    finally. Unrelated data (the shared WP test DB, other tests) can never
 *    be read or modified here, and cleanup is total even on failure: the
 *    whole schema is dropped — there is no scanning and no per-table delete.
 *    See IsolatedSchema.php for the shared machinery.
 *  - Fixture visibility: fixture rows are INSERTed on a connection with
 *    autocommit, so they are durable and visible from the moment they exist.
 *    No fixture is ever written to the harness's global connection.
 *  - Transaction boundaries: production code opens transactions through
 *    CpmsDb, whose marked control statements the WP test bootstrap rewrites
 *    to SAVEPOINTs nested in the framework's outer transaction. The
 *    "confirm must be denied" assertion runs in-process inside a real outer
 *    transaction (transactionalCommit rolls it back on the expected throw);
 *    the mutating workers run production code on a bare connection whose
 *    implicit transaction the worker COMMITs before exiting — the same
 *    top-level commit semantics production has. Verified against MySQL 5.7
 *    semantics: SAVEPOINT on an idle connection is accepted; real DDL
 *    implicitly commits and destroys prior savepoints (which is why
 *    migrations run before any savepoint exists).
 *  - Deterministic overlap, no sleep-race (external-worker backend): a
 *    proc_open() external worker (tests/bin/fork-race-wp-worker.php) signals
 *    readiness through a file barrier; the test then proves from InnoDB's
 *    own lock tables (performance_schema on MySQL, information_schema
 *    innodb_lock_waits on MariaDB) that the worker is in LOCK WAIT behind
 *    the coordinator's connection on the expected table before releasing it.
 *    No sleep picks the winner. The worker inherits no descriptor of this
 *    process (tests/Support/ExternalRaceWorker.php), so its graceful exit(0)
 *    is honest — never a shared-session mysqlnd teardown.
 *  - Same-file fallback (no proc_open backend): runtimes without proc_open()
 *    (e.g. php-wasm) execute a deterministic fallback instead: an
 *    independent connection is parked FOR UPDATE on the target row by a
 *    coordinator, an independent production worker with
 *    innodb_lock_wait_timeout=1 then blocks and is rejected with the
 *    production code for that case (CLINIC_BOOKING_BUSY), the coordinator
 *    rolls back, a fresh production worker succeeds, and a third is rejected
 *    with CLINIC_SLOT_TAKEN. It cannot observe the lock-wait edge itself and
 *    cannot construct a true simultaneous-entry race; those properties
 *    belong to the external-worker backend. Final-state assertions are identical.
 *  - Failure injection without fabricated errors: a test-only wpdb subclass
 *    rewrites the target table to a missing one, so the REAL driver reports
 *    a real failure; counters assert exactly-once fault delivery. There are
 *    no retries to mask deadlocks or errors anywhere in this file.
 *
 * SCOPE — what this file does and does NOT show:
 *  - It exercises production code paths (BookingService::hold/confirm,
 *    HoldsExpireHandler) with genuinely overlapping connections.
 *  - It does NOT prove deadlock-freedom, fairness, crash recovery, or
 *    anything about deployments outside a single test process.
 *  - The external workers cannot read the parent's uncommitted WP session
 *    rows. Each worker builds its stack with a PatientSessionScope seeded
 *    from the session arrays captured in the parent before launch (the
 *    class's documented session_reader/clinics_reader seams; the empty
 *    live-link snapshot is consistent with the disposable schema having no
 *    global link rows), a pure always-allow ActiveLicenseGate (the global
 *    SignedLicenseGate singleton is bound to the parent connection and must
 *    not be touched from a worker), and its own SmsService/JobQueue bound to
 *    the disposable connection. In-process assertions use the production
 *    wiring (App::licenseGate()) instead. The external worker rebuilds this
 *    exact stack on its own connection (tests/Support/DisposableSchemaChildStack).
 *  - Execution status is tracked in the commit/PR that lands this file.
 *    The no-worker fallback backend was executed on MariaDB 11.4.13 (see the
 *    run logs of the PR #330 continuation); the external-worker backend itself
 *    runs where proc_open() exists (CI). Do not claim the external-worker
 *    backend passed anywhere it did not run.
 *
 * PR #330 — two-connection capacity proof (red-first). All fixtures are
 * disposable; nothing here may run on a shared database.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Tests\Support\ExternalRaceWorker;
use WP_UnitTestCase;

require_once __DIR__ . '/IsolatedSchema.php';
require_once __DIR__ . '/RealTableMigrations.php';

final class HoldCapacityTwoConnectionTest extends WP_UnitTestCase
{
    use RealTableMigrations;
    use IsolatedSchema;

    private const READY_TIMEOUT_SEC = 20.0;
    private const LOCK_WAIT_TIMEOUT_SEC = 10.0;
    private const GO_DEADLINE_SEC = 20.0;
    private const CHILD_EXIT_TIMEOUT_SEC = 10.0;

    /** @var array<int, int> PIDs (workers or children) that survived bounded termination; tearDown fails loudly when non-empty. */
    private array $reapFailures = [];

    protected function setUp(): void
    {
        parent::setUp();
        // proc_open() decides the backend at test level (external-worker backend
        // when available, deterministic connection fallback otherwise).

        $this->createDisposableSchema('t2c');
        $this->migrateDisposableSchema();
        $this->seedWpUsers();
        $this->seedOrganizationGraph();
        $this->seedPatientsAndLinks();
        $this->seedSessions([$this->userAId, $this->userBId]);
    }

    protected function tearDown(): void
    {
        try {
            // FAIL CLOSED: any worker that cannot be confirmed stopped —
            // reapFailures — blocks the drop; the schema is retained loudly
            // and the shutdown backstop honors it.
            $pending = array_unique($this->reapFailures);
            sort($pending);
            if ($pending !== []) {
                $this->blockDisposableSchemaDrop('race workers unconfirmed stopped; pids=' . implode(',', $pending));
            }
            $this->dropDisposableSchema();
        } finally {
            parent::tearDown();
        }
    }

    // ================= scenario 1: competing holds, last capacity unit =================

    public function testCompetingHoldOnLastCapacityUnitIsRejectedFromIndependentConnection(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(1, 0, $date);

        if ( ! $this->workerAvailability() ) {
            $this->markRace('scenario=1', 'backend=fallback workers=absent external-worker proof NOT executed');
            $this->runScenarioOneConnectionFallback( $slotId, $date );
            return;
        }
        $this->markRace('scenario=1', 'backend=external-workers workers=present');

        $tag       = sys_get_temp_dir() . '/' . $this->fileTag . '-race1';
        $readyA    = $tag . '-ready-a';
        $goA       = $tag . '-go-a';
        $holdDoneA = $tag . '-hold-done-a';
        $commitA   = $tag . '-commit-a';
        $outcomeA  = $tag . '-outcome-a.json';
        $readyB    = $tag . '-ready-b';
        $outcomeB  = $tag . '-outcome-b.json';
        $raceFiles = [ $readyA, $goA, $holdDoneA, $commitA, $outcomeA, $readyB, $outcomeB ];
        /** @var list<ExternalRaceWorker> $workers */
        $workers   = [];
        $script    = dirname(__DIR__) . '/bin/fork-race-wp-worker.php';

        // The whole forked race lives inside try/finally so coordination files
        // and child PIDs are ALWAYS reaped — on assertion failure exactly as on
        // success. The catch preserves the workers' non-sensitive outcome JSON
        // (result/error code only) on STDERR; nothing is retried or ignored.
        try {
        // Competitor A (external worker): takes the canonical tenant-prefix locks
        // (Organization -> Clinic) AND the Slot row lock, in the same order
        // production's lock_booking_tenant_prefix() uses, waits for GO, runs
        // the production hold(), signals completion, waits for COMMIT, then
        // commits for real. Its connection keeps the locks until COMMIT.
        //
        // Historical note (evidence-backed 2026-11-07): a previous revision of
        // this test pre-locked ONLY the Slot row here. Production hold() locks
        // Organization -> Clinic before the Slot, and competitor B holds those
        // prefix locks while blocked on the Slot — a guaranteed two-transaction
        // deadlock cycle. InnoDB rolled back a victim (measured 6/6 on
        // MariaDB 11.4.13: the A side discarded with error 1213), the forked
        // worker took the catch(\Throwable) escape hatch, hold-done was never
        // signalled, and this exact test failed in CI with
        //   "competitor A did not complete its hold"
        // at HoldCapacityTwoConnectionTest.php:178. Locking the prefix in the
        // same order removes the inversion; the assertion strength is unchanged
        // (B must still be observed blocked behind A before GO, still blocked
        // after hold(), and must still lose with CLINIC_SLOT_TAKEN).
        // Fail-closed launch guard: the external worker holds NO descriptor of
        // this process (see ExternalRaceWorker); if it dies before writing
        // ready, the bounded wait below fails the test and the finally unlinks
        // coordination files — the schema drop then proceeds normally.
        $workerA = ExternalRaceWorker::launch($script, [
            'mode' => 'hold_competitor',
            'db_name' => $this->disposableDb,
            'ready_file' => $readyA,
            'go_file' => $goA,
            'hold_done_file' => $holdDoneA,
            'commit_file' => $commitA,
            'outcome_file' => $outcomeA,
            'slot_id' => $slotId,
            'date' => $date,
            'user_id' => $this->userAId,
            'clinician_id' => $this->clinicianId,
            'organization_id' => $this->organizationId,
            'clinic_id' => $this->clinicId,
            'token' => $this->capturedTokens[$this->userAId] ?? '',
            'session' => $this->capturedSessions[$this->userAId] ?? [],
        ]);
        $workers[] = $workerA;
        self::assertTrue($workerA->pid() > 0, 'competitor A launch');
        self::assertTrue($this->waitForFile($readyA, self::READY_TIMEOUT_SEC), 'competitor A never signalled readiness');
        $coordinatorConnection = $this->readConnectionIdFromFile($readyA);
        self::assertGreaterThan(0, $coordinatorConnection);

        // Competitor B (external worker): runs the same production hold() on its own
        // connection; it must block behind A.
        $workerB = ExternalRaceWorker::launch($script, [
            'mode' => 'hold_loser',
            'db_name' => $this->disposableDb,
            'ready_file' => $readyB,
            'outcome_file' => $outcomeB,
            'slot_id' => $slotId,
            'date' => $date,
            'user_id' => $this->userBId,
            'clinician_id' => $this->clinicianId,
            'token' => $this->capturedTokens[$this->userBId] ?? '',
            'session' => $this->capturedSessions[$this->userBId] ?? [],
        ]);
        $workers[] = $workerB;
        self::assertTrue($workerB->pid() > 0, 'competitor B launch');
        self::assertTrue($this->waitForFile($readyB, self::READY_TIMEOUT_SEC), 'competitor B never signalled readiness');
        $workerConnection = $this->readConnectionIdFromFile($readyB);
        self::assertGreaterThan(0, $workerConnection);

        // Deterministic overlap proof: B is in InnoDB LOCK WAIT on the
        // Organization row (the canonical prefix lock production takes first)
        // behind A's connection BEFORE A proceeds.
        $edge = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_organizations', self::LOCK_WAIT_TIMEOUT_SEC);
        self::assertNotNull($edge, 'competitor B must be observed in InnoDB LOCK WAIT on the tenant prefix behind A');
        self::assertStringContainsString('cpms_organizations', strtolower((string) ($edge['lock_table'] ?? '')));
        $this->markRace('scenario=1', sprintf('lock_wait observed table=%s waiting_connection=%d coordinator=%d', (string) ($edge['lock_table'] ?? 'unknown'), $workerConnection, $coordinatorConnection));

        // A completes its hold while B is blocked.
        file_put_contents($goA, 'go');
        $holdDoneAOk = $this->waitForFile($holdDoneA, self::GO_DEADLINE_SEC);
        self::assertTrue(
            $holdDoneAOk,
            'competitor A did not complete its hold; outcomeA='
            . (string) @file_get_contents($outcomeA)
            . ' outcomeB='
            . (string) @file_get_contents($outcomeB)
        );

        // B must STILL be blocked: A's connection holds the prefix and the Slot row until COMMIT.
        $stillWaiting = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_organizations', 1.0);
        self::assertNotNull($stillWaiting, 'competitor B must remain blocked until A commits');
        $this->markRace('scenario=1', 'still_blocked_after_hold=yes table=cpms_organizations');

        // Release A; B unblocks, sees the capacity consumed, and must lose.
        file_put_contents($commitA, 'go');
        // Bounded, status-checked wait: workers are waited and reaped
        // before control can ever reach schema teardown, never indefinitely.
        $reapedA = $workerA->waitForExit(self::CHILD_EXIT_TIMEOUT_SEC);
        $reapedB = $workerB->waitForExit(self::CHILD_EXIT_TIMEOUT_SEC);
        self::assertSame(0, $reapedA, 'competitor A worker (pid ' . $workerA->pid() . ') did not exit 0 within ' . self::CHILD_EXIT_TIMEOUT_SEC . 's of the COMMIT release');
        self::assertSame(0, $reapedB, 'competitor B worker (pid ' . $workerB->pid() . ') did not exit 0 within ' . self::CHILD_EXIT_TIMEOUT_SEC . 's of the COMMIT release');
        // Workers stay registered in $workers: the finally is idempotent (terminate() no-ops on exited workers).

        $outcomeBJson = json_decode((string) @file_get_contents($outcomeB), true);
        self::assertIsArray($outcomeBJson, 'B must write its outcome');
        self::assertSame('refused', $outcomeBJson['result'] ?? '');
        self::assertSame('CLINIC_SLOT_TAKEN', $outcomeBJson['code'] ?? '');

        // Fresh raw reads: exactly one unit of capacity consumed, exactly one hold.
        self::assertSame('1', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('1', $this->rawScalar("SELECT COUNT(*) FROM " . $this->rawTable('cpms_slot_holds') . " WHERE slot_id = {$slotId} AND status = 'active'"));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        } catch (\Throwable $e) {
            fwrite(STDERR, '[hold-race diagnostic] ' . $this->describeRaceFiles($raceFiles) . PHP_EOL);
            throw $e;
        } finally {
            $this->reapWorkers($workers, $raceFiles, 'scenario=1');
        }
    }

    // ================= scenario 2: expiry worker vs confirm =================

    public function testExpiryWorkerOnIndependentConnectionThenConfirmIsDeniedWithoutDoubleRelease(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() - 3600) . '.000', 'active');

        if ( ! $this->workerAvailability() ) {
            $this->markRace('scenario=2', 'backend=fallback workers=absent external-worker proof NOT executed');
            $this->runScenarioTwoConnectionFallback( $slotId, $holdId );
            return;
        }
        $this->markRace('scenario=2', 'backend=external-workers workers=present');

        $tag       = sys_get_temp_dir() . '/' . $this->fileTag . '-race2';
        $readyA    = $tag . '-ready-a';
        $goA       = $tag . '-go-a';
        $readyB    = $tag . '-ready-b';
        $outcomeB  = $tag . '-outcome-b.json';
        $raceFiles = [ $readyA, $goA, $readyB, $outcomeB ];
        /** @var list<ExternalRaceWorker> $workers */
        $workers   = [];
        $script    = dirname(__DIR__) . '/bin/fork-race-wp-worker.php';

        // Same guarantee as scenario 1: race files and workers are always
        // terminated/closed; worker outcome JSON reaches STDERR on failure only.
        try {
        // Coordinator A (external worker): holds the Hold row's record lock
        // exactly where confirm() takes it (HOLD before SLOT), signals
        // readiness, waits for GO.
        $workerA = ExternalRaceWorker::launch($script, [
            'mode' => 'hold_lock_holder',
            'db_name' => $this->disposableDb,
            'ready_file' => $readyA,
            'go_file' => $goA,
            'hold_id' => $holdId,
        ]);
        $workers[] = $workerA;
        self::assertTrue($workerA->pid() > 0);
        self::assertTrue($this->waitForFile($readyA, self::READY_TIMEOUT_SEC), 'coordinator A never signalled readiness');
        $coordinatorConnection = $this->readConnectionIdFromFile($readyA);
        self::assertGreaterThan(0, $coordinatorConnection);

        // Worker B (external worker): the production HoldsExpireHandler on its
        // own connection; it must block on the Hold row until A releases it.
        $workerB = ExternalRaceWorker::launch($script, [
            'mode' => 'hold_expiry_worker',
            'db_name' => $this->disposableDb,
            'ready_file' => $readyB,
            'outcome_file' => $outcomeB,
        ]);
        $workers[] = $workerB;
        self::assertTrue($workerB->pid() > 0);
        self::assertTrue($this->waitForFile($readyB, self::READY_TIMEOUT_SEC), 'expiry worker never signalled readiness');
        $workerConnection = $this->readConnectionIdFromFile($readyB);

        $edge = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_slot_holds', self::LOCK_WAIT_TIMEOUT_SEC);
        self::assertNotNull($edge, 'the expiry worker must be observed in InnoDB LOCK WAIT on the Hold row');
        self::assertStringContainsString('cpms_slot_holds', strtolower((string) ($edge['lock_table'] ?? '')));
        $this->markRace('scenario=2', sprintf('lock_wait observed table=%s waiting_connection=%d coordinator=%d', (string) ($edge['lock_table'] ?? 'unknown'), $workerConnection, $coordinatorConnection));

        // Release A's hold lock; B expires the hold and frees capacity for real.
        file_put_contents($goA, 'go');
        // Bounded, status-checked wait: workers are waited and reaped
        // before control can ever reach schema teardown, never indefinitely.
        $reapedA = $workerA->waitForExit(self::CHILD_EXIT_TIMEOUT_SEC);
        $reapedB = $workerB->waitForExit(self::CHILD_EXIT_TIMEOUT_SEC);
        self::assertSame(0, $reapedA, 'competitor A worker (pid ' . $workerA->pid() . ') did not exit 0 within ' . self::CHILD_EXIT_TIMEOUT_SEC . 's of the hold-release signal');
        self::assertSame(0, $reapedB, 'expiry worker (pid ' . $workerB->pid() . ') did not exit 0 within ' . self::CHILD_EXIT_TIMEOUT_SEC . 's of the hold-release signal');
        // Workers stay registered in $workers: the finally is idempotent (terminate() no-ops on exited workers).
        $outcomeBJson = json_decode((string) @file_get_contents($outcomeB), true);
        self::assertIsArray($outcomeBJson, 'worker B must write its outcome');
        self::assertSame('ok', $outcomeBJson['result'] ?? '');
        self::assertGreaterThanOrEqual(1, (int) ($outcomeBJson['freed'] ?? 0));

        // Confirm must now be denied in the gate WITHOUT releasing capacity again.
        $confirmError = null;
        try {
            $this->transactionalCommit($this->fixtureWpdb, function () use ($holdId): void {
                $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$this->userAId] ?? '';
                $stack = $this->stackOn($this->fixtureWpdb, false);
                $stack['service']->confirm($this->holdToken($holdId, $this->fixtureWpdb), $this->userAId, null, $this->uuid());
            });
        } catch (BookingException $e) {
            $confirmError = $e;
        }
        self::assertNotNull($confirmError, 'confirm must be denied after the worker expired the hold');
        self::assertSame('CLINIC_HOLD_EXPIRED', $confirmError->errorCode);
        self::assertSame(422, $confirmError->httpStatus);

        // Capacity released exactly once (by the worker), never by confirm.
        self::assertSame('expired', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame(0, $compensationFailures, 'the gate denial must not release capacity or log a release failure');
        } catch (\Throwable $e) {
            fwrite(STDERR, '[hold-race diagnostic] ' . $this->describeRaceFiles($raceFiles) . PHP_EOL);
            throw $e;
        } finally {
            $this->reapWorkers($workers, $raceFiles, 'scenario=2');
        }
    }

    /**
     * CI guard (GitHub Actions only): the integration job MUST be able to
     * launch the external race workers so the two-connection race scenarios
     * execute their external-worker backend instead of silently falling back
     * to the deterministic connection fallback — no green suite may carry an
     * unproven race backend.
     *
     * Soundness argument: both scenarios select their backend purely on
     * workerAvailability() (=== function_exists('proc_open') && PHP_BINARY &&
     * the worker script). Therefore, on a green CI job that also passes this
     * assertion, the fallback was unreachable and every scenario's intrinsic
     * assertions (InnoDB LOCK WAIT edge, still-blocked invariant, bounded
     * worker waits, outcome semantics) were satisfied on the external-worker
     * path.
     *
     * Limits: this proves capability + green outcome only — it does not record
     * marker lines, process timings, or InnoDB internals; it proves nothing
     * about local runs (where GITHUB_ACTIONS is unset and the assertion
     * degrades to skipping). It weakens nothing: the fallback remains fully
     * functional and authoritative off-CI.
     */
    public function testExternalRaceWorkersAvailableOnCiRuns(): void
    {
        if ('true' !== (string) getenv('GITHUB_ACTIONS')) {
            self::markTestSkipped('the external race worker backend is enforced only on CI (GITHUB_ACTIONS=true); local run, nothing asserted');
            return;
        }
        self::assertTrue(function_exists('proc_open'), 'CI integration job must provide proc_open, else both race scenarios silently execute their fallback');
        self::assertTrue(defined('PHP_BINARY') && PHP_BINARY !== '', 'CI integration job must provide PHP_BINARY for the external race workers');
        self::assertFileExists(dirname(__DIR__) . '/bin/fork-race-wp-worker.php', 'CI must see the external race worker script');
    }

    // ================= scenario 3a: injected conversion failure =================

    public function testConversionFailureRollsBackBookingAndCompensatesHoldExactlyOnce(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() + 3600) . '.000', 'active');

        // Failure injection: rewrite ONLY the conversion UPDATE's table to a
        // missing one. The real driver then reports the real failure; no error
        // message is fabricated anywhere.
        $conversionFault = static function (string $sql): ?string {
            if (false !== stripos($sql, 'cpms_slot_holds') && false !== stripos($sql, "status = 'converted'")) {
                return str_ireplace('cpms_slot_holds', 'cpms_slot_holds__missing_two_connection', $sql);
            }
            return null;
        };

        $decoded = $this->confirmWithFaultInProcess($conversionFault, $holdId, $this->userAId);
        self::assertSame('error', $decoded['result'] ?? '');
        self::assertSame('CLINIC_INTERNAL_ERROR', $decoded['code'] ?? '');
        self::assertSame(1, (int) ($decoded['fault_fired'] ?? -1), 'the injected conversion fault fired exactly once');

        // Booking rolled back; compensation released exactly once (hold -> released, counter 0).
        self::assertSame('released', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame(0, $compensationFailures, 'a successful compensation must not log a release failure');

    }

    // ============ scenario 3b: injected compensation-release failure + retry ============

    public function testCompensationReleaseFailureRollsBackAndHoldStaysUsableForRetry(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() + 3600) . '.000', 'active');

        // The conversion fault fires first; additionally the compensation's
        // counter release (release_hold: GREATEST(held_count - 1, 0), no
        // booked_count reference) is rewritten to a missing table.
        $releaseFault = static function (string $sql): ?string {
            if (false !== stripos($sql, 'cpms_slot_holds') && false !== stripos($sql, "status = 'converted'")) {
                return str_ireplace('cpms_slot_holds', 'cpms_slot_holds__missing_two_connection', $sql);
            }
            if (
                false !== stripos($sql, 'cpms_schedule_slots')
                && false !== stripos($sql, 'GREATEST(held_count - 1, 0)')
                && false === stripos($sql, 'booked_count')
            ) {
                return str_ireplace('cpms_schedule_slots', 'cpms_schedule_slots__missing_two_connection', $sql);
            }
            return null;
        };

        $decoded = $this->confirmWithFaultInProcess($releaseFault, $holdId, $this->userAId);
        self::assertSame('error', $decoded['result'] ?? '');
        self::assertSame('CLINIC_INTERNAL_ERROR', $decoded['code'] ?? '');

        // Nothing consumed, built, or released: the hold is untouched and active.
        self::assertSame('active', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('1', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));

        // The compensation failure was logged with the slot_release stage.
        $releaseFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message = 'booking.hold_capacity_release_failed'"
        );
        self::assertSame(1, $releaseFailures, 'the compensation failure must be logged once with stage slot_release');

        // Retry on a fresh connection with faults disabled: must succeed.
        $retry = $this->confirmWithFaultInProcess(null, $holdId, $this->userAId);
        self::assertSame('ok', $retry['result'] ?? '');

        self::assertSame('converted', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('1', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('1', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));

    }

    // ================= no-worker fallback backend + in-process fault helper =================

    private function workerAvailability(): bool
    {
        return function_exists('proc_open')
            && defined('PHP_BINARY')
            && PHP_BINARY !== ''
            && is_file(dirname(__DIR__) . '/bin/fork-race-wp-worker.php');
    }

    /**
     * Deterministic single-process fallback for scenario 1 (no proc_open backend).
     * It proves a weaker but honest property: an independent production
     * connection really WAITS for the Slot row's InnoDB lock held by the
     * coordinator (bounded by its own innodb_lock_wait_timeout=1) and is then
     * rejected with the production code for that case (CLINIC_BOOKING_BUSY);
     * after the coordinator releases the lock, a fresh production hold
     * succeeds and consumes the last unit; a third is rejected with
     * CLINIC_SLOT_TAKEN. It does NOT demonstrate two competitors entering
     * hold() simultaneously nor observe the lock-wait edge — those belong to
     * the external-worker backend. Final-state assertions are identical to it.
     */
    private function runScenarioOneConnectionFallback( int $slotId, string $date ): void
    {
        $connections = [];
        try {
        $coordinator = $connections[] = $this->newDisposableConnection();
        $coordinator->query( 'START TRANSACTION' );
        $locked = $coordinator->get_var(
            $coordinator->prepare( 'SELECT id FROM ' . $coordinator->prefix . 'cpms_schedule_slots WHERE id = %d FOR UPDATE', $slotId )
        );
        self::assertSame( $slotId, (int) $locked, 'coordinator must hold the Slot row lock' );

        // Production hold() on an independent connection behind the held lock.
        $worker = $connections[] = $this->newDisposableConnection();
        $worker->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
        $worker->query( 'START TRANSACTION' );
        $busyError = null;
        try {
            $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$this->userAId] ?? '';
            $stack = $this->stackOn( $worker, false );
            $stack['service']->hold( $this->userAId, $this->clinicianId, $date, '09:00:00', $slotId );
            $worker->query( 'ROLLBACK' );
        } catch ( BookingException $e ) {
            $busyError = $e;
            $worker->query( 'ROLLBACK' );
        }
        self::assertNotNull( $busyError, 'the blocked production hold must be rejected while the Slot row is locked' );
        self::assertSame( 'CLINIC_BOOKING_BUSY', $busyError->errorCode );
        self::assertSame( 503, $busyError->httpStatus );
        $worker->close();

        $coordinator->query( 'ROLLBACK' );
        $coordinator->close();

        // A fresh production hold now succeeds and consumes the last unit.
        $winner = $connections[] = $this->newDisposableConnection();
        $winner->query( 'START TRANSACTION' );
        $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$this->userAId] ?? '';
        $stack = $this->stackOn( $winner, false );
        $winnerResult = $stack['service']->hold( $this->userAId, $this->clinicianId, $date, '09:00:00', $slotId );
        $winner->query( 'COMMIT' );
        self::assertNotSame( '', (string) ( $winnerResult['hold_token'] ?? '' ) );
        $winner->close();

        // A third connection must now be rejected: capacity is consumed.
        $loser = $connections[] = $this->newDisposableConnection();
        $loser->query( 'START TRANSACTION' );
        $loserError = null;
        try {
            $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$this->userBId] ?? '';
            $stack = $this->stackOn( $loser, false );
            $stack['service']->hold( $this->userBId, $this->clinicianId, $date, '09:00:00', $slotId );
            $loser->query( 'COMMIT' );
        } catch ( BookingException $e ) {
            $loserError = $e;
            $loser->query( 'ROLLBACK' );
        }
        self::assertNotNull( $loserError, 'capacity is exhausted; the third hold must be rejected' );
        self::assertSame( 'CLINIC_SLOT_TAKEN', $loserError->errorCode );
        self::assertSame( 409, $loserError->httpStatus );
        $loser->close();

        self::assertSame( '1', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '1', $this->rawScalar( "SELECT COUNT(*) FROM " . $this->rawTable( 'cpms_slot_holds' ) . " WHERE slot_id = {$slotId} AND status = 'active'" ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_appointments' ) . ' WHERE slot_id = ' . $slotId ) );
        } finally {
            foreach ( $connections as $connRef ) {
                try {
                    $connRef->close();
                } catch ( \Throwable $ignored ) {
                    unset( $ignored );
                }
            }
        }
    }

    /**
     * Deterministic single-process fallback for scenario 2 (no proc_open backend).
     * The coordinator holds the Hold row's X lock; the production
     * HoldsExpireHandler on an independent connection with
     * innodb_lock_wait_timeout=1 blocks on its first write, fails, and rolls
     * back — the hold stays active and capacity stays claimed. After the
     * coordinator releases, the handler expires the hold and frees capacity;
     * confirm is then denied with CLINIC_HOLD_EXPIRED (422) and capacity is
     * never released twice.
     */
    private function runScenarioTwoConnectionFallback( int $slotId, int $holdId ): void
    {
        $connections = [];
        try {
        $coordinator = $connections[] = $this->newDisposableConnection();
        $coordinator->query( 'START TRANSACTION' );
        $locked = $coordinator->get_var(
            $coordinator->prepare( 'SELECT id FROM ' . $coordinator->prefix . 'cpms_slot_holds WHERE id = %d FOR UPDATE', $holdId )
        );
        self::assertSame( $holdId, (int) $locked, 'coordinator must hold the Hold row lock' );

        $worker = $connections[] = $this->newDisposableConnection();
        $worker->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
        $worker->query( 'START TRANSACTION' );
        $expireError = null;
        try {
            $stack = $this->stackOn( $worker, false );
            $handler = new HoldsExpireHandler( $stack['db'] );
            $handler->__invoke( [] );
            $worker->query( 'COMMIT' );
        } catch ( \RuntimeException $e ) {
            $expireError = $e;
            $worker->query( 'ROLLBACK' );
        }
        self::assertNotNull( $expireError, 'the expiry worker must fail while the Hold row is locked' );
        self::assertSame( 'active', $this->rawScalar( 'SELECT status FROM ' . $this->rawTable( 'cpms_slot_holds' ) . ' WHERE id = ' . $holdId ) );
        self::assertSame( '1', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        $worker->close();

        $coordinator->query( 'ROLLBACK' );
        $coordinator->close();

        // Now the handler runs clean: it expires the hold and frees capacity.
        $cleanup = $connections[] = $this->newDisposableConnection();
        $cleanup->query( 'START TRANSACTION' );
        $stack = $this->stackOn( $cleanup, false );
        $handler = new HoldsExpireHandler( $stack['db'] );
        $freed = $handler->__invoke( [] );
        $cleanup->query( 'COMMIT' );
        self::assertGreaterThanOrEqual( 1, $freed );
        $cleanup->close();

        // Confirm must now be denied in the gate WITHOUT releasing capacity again.
        $confirmError = null;
        try {
            $this->transactionalCommit( $this->fixtureWpdb, function () use ( $holdId ): void {
                $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$this->userAId] ?? '';
                $stack = $this->stackOn( $this->fixtureWpdb, false );
                $stack['service']->confirm( $this->holdToken( $holdId, $this->fixtureWpdb ), $this->userAId, null, $this->uuid() );
            } );
        } catch ( BookingException $e ) {
            $confirmError = $e;
        }
        self::assertNotNull( $confirmError, 'confirm must be denied after the worker expired the hold' );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $confirmError->errorCode );
        self::assertSame( 422, $confirmError->httpStatus );

        // Capacity released exactly once (by the handler), never by confirm.
        self::assertSame( 'expired', $this->rawScalar( 'SELECT status FROM ' . $this->rawTable( 'cpms_slot_holds' ) . ' WHERE id = ' . $holdId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT booked_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_appointments' ) . ' WHERE slot_id = ' . $slotId ) );
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_operational_logs' )
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame( 0, $compensationFailures, 'the gate denial must not release capacity or log a release failure' );
        } finally {
            foreach ( $connections as $connRef ) {
                try {
                    $connRef->close();
                } catch ( \Throwable $ignored ) {
                    unset( $ignored );
                }
            }
        }
    }

    /**
     * In-process twin of the former forked confirm worker: drives the
     * production confirm() with (or without) a fault injected at the SQL
     * layer. After production rolls its nested transactions back to
     * savepoints, COMMIT only ends the outer transaction — the same
     * top-level durability the fork committed. Returns the result envelope
     * used by the assertions.
     *
     * @return array<string, mixed>
     */
    private function confirmWithFaultInProcess( ?callable $fault, int $holdId, int $userId ): array
    {
        $conn = null;
        try {
            if ( null !== $fault ) {
                $conn = new FaultWpdb( DB_USER, DB_PASSWORD, $this->disposableDb, DB_HOST );
                $conn->set_prefix( $this->fixtureWpdb->prefix );
                $conn->show_errors( false );
                $conn->faultCallback = $fault;
            } else {
                $conn = $this->newDisposableConnection();
            }
            $token = $this->holdToken( $holdId, $conn );
            $_COOKIE[LOGGED_IN_COOKIE] = $this->capturedCookies[$userId] ?? '';
            $stack = $this->stackOn( $conn, false );
            $conn->query( 'START TRANSACTION' );
            try {
                $stack['service']->confirm( $token, $userId, null, $this->uuid() );
            } catch ( \Throwable $e ) {
                $conn->query( 'COMMIT' );
                $result = [
                    'result' => 'error',
                    'code' => $e instanceof BookingException ? $e->errorCode : get_class( $e ),
                    'fault_fired' => isset( $conn->faultFired ) ? $conn->faultFired : null,
                ];
                $this->closeConfirmConnection($conn);
                return $result;
            }
            $conn->query( 'COMMIT' );
            $this->closeConfirmConnection($conn);
            return [ 'result' => 'ok' ];
        } catch ( \Throwable $e ) {
            try {
                if ( null !== $conn ) {
                    $conn->query( 'ROLLBACK' );
                }
            } catch ( \Throwable $ignored ) {
                unset( $ignored );
            }
            $this->closeConfirmConnection($conn);
            return [ 'result' => 'fatal', 'code' => get_class( $e ) ];
        }
    }

    /** Best-effort close for confirm-path connections; never throws. */
    private function closeConfirmConnection(?\wpdb $conn): void
    {
        if (null === $conn) {
            return;
        }
        try {
            $conn->close();
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
    }

    /**
     * Emit a single bounded, non-sensitive race marker line for CI forensics:
     * backend selection, the observed InnoDB lock-wait edge, and the child
     * reap outcome. pids, table names, connection ids and result counters
     * only — never session tokens, OTP codes, bodies or identifiers of real
     * rows beyond the disposable schema's table names.
     */
    private function markRace(string $tag, string $message): void
    {
        fwrite(STDERR, '[hold-race] ' . $tag . ' ' . $message . PHP_EOL);
    }

    /**
     * Concatenate any child outcome files for stderr diagnostics. The workers
     * only ever write result/error-code/class-name JSON — no session tokens,
     * OTP codes, mobile numbers or message bodies — and the parent embeds the
     * same data in the primary assertion message.
     *
     * @param array<int, string> $files
     */
    private function describeRaceFiles(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (false !== $content) {
                $parts[] = basename($file) . '=' . trim($content);
            }
        }
        return $parts === [] ? '(none)' : implode(' ', $parts);
    }

    /**
     * Terminate and close every worker still registered for this test, then
     * delete every coordination file. Safe on any exit path. A worker that
     * refuses to stop inside the bounded terminate() window is recorded in
     * $reapFailures so tearDown fails LOUDLY and refuses the schema DROP —
     * an unconfirmed worker can never slip past the drop.
     *
     * @param list<ExternalRaceWorker> $workers
     * @param array<int, string> $files
     */
    private function reapWorkers(array $workers, array $files, string $tag): void
    {
        foreach ($workers as $worker) {
            $pid = $worker->pid();
            $worker->terminate();
            if ($worker->isRunning()) {
                $this->reapFailures[] = $pid;
                $this->blockDisposableSchemaDrop('external race worker pid ' . $pid . ' unconfirmed stopped; schema retained');
                $this->markRace($tag, 'worker pid=' . $pid . ' still running after bounded SIGTERM/SIGKILL; schema drop REFUSED (fail-closed)');
            }
        }
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /** InnoDB lock-wait edge with a known worker connection id. */
    private function waitForLockWait(int $workerConnection, int $blockingThread, string $table, float $timeout): ?array
    {
        if ($workerConnection <= 0) {
            return null;
        }
        $mysqli = $this->freshMysqli();
        try {
            $isMariaDb = false !== stripos($this->versionOf($mysqli), 'mariadb');
            if ($isMariaDb) {
                $sql = 'SELECT requesting_trx.trx_mysql_thread_id AS requesting_thread, blocking_trx.trx_mysql_thread_id AS blocking_thread,
                               requested_lock.lock_table AS lock_table, requested_lock.lock_index AS lock_index,
                               requested_lock.lock_type AS lock_type, requested_lock.lock_mode AS lock_mode
                        FROM information_schema.innodb_lock_waits waits
                        JOIN information_schema.innodb_trx requesting_trx ON requesting_trx.trx_id = waits.requesting_trx_id
                        JOIN information_schema.innodb_trx blocking_trx ON blocking_trx.trx_id = waits.blocking_trx_id
                        JOIN information_schema.innodb_locks requested_lock ON requested_lock.lock_id = waits.requested_lock_id
                       WHERE requesting_trx.trx_mysql_thread_id = ?';
            } else {
                $sql = 'SELECT requesting_thread.PROCESSLIST_ID AS requesting_thread, blocking_thread.PROCESSLIST_ID AS blocking_thread,
                               requested_lock.OBJECT_NAME AS lock_table, requested_lock.INDEX_NAME AS lock_index,
                               requested_lock.LOCK_TYPE AS lock_type, requested_lock.LOCK_MODE AS lock_mode
                        FROM performance_schema.data_lock_waits waits
                        JOIN performance_schema.data_locks requested_lock
                          ON requested_lock.ENGINE = waits.ENGINE AND requested_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                        JOIN performance_schema.data_locks blocking_lock
                          ON blocking_lock.ENGINE = waits.ENGINE AND blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID
                        JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = requested_lock.THREAD_ID
                        JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = blocking_lock.THREAD_ID
                       WHERE requesting_thread.PROCESSLIST_ID = ?';
            }
            $deadline = microtime(true) + $timeout;
            do {
                $stmt = $mysqli->prepare($sql);
                if (false === $stmt) {
                    return null;
                }
                $stmt->bind_param('i', $workerConnection);
                $stmt->execute();
                $result = $stmt->get_result();
                $found = null;
                while ($result && $row = $result->fetch_assoc()) {
                    if (
                        (int) ($row['blocking_thread'] ?? 0) === $blockingThread
                        && str_contains(strtolower((string) ($row['lock_table'] ?? '')), strtolower($table))
                    ) {
                        $found = $row;
                        break;
                    }
                }
                if ($result) {
                    $result->free();
                }
                $stmt->close();
                if (null !== $found) {
                    return $found;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            return null;
        } finally {
            $mysqli->close();
        }
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Tests\Support\ExternalRaceWorker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ExternalRaceWorker.php';

/**
 * Failure paths and reaping discipline of the external race-worker launcher.
 *
 * These tests never touch a database: every child is a bare PHP script the
 * launcher starts with [PHP_BINARY, script]. On Linux, a missing prerequisite
 * FAILS the test loudly (fail-closed); only a non-Linux host may skip.
 */
final class ExternalRaceWorkerFailurePathsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<ExternalRaceWorker> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            // Descriptor isolation and /proc/self/fd are Linux-only.
            self::markTestSkipped('External race workers require Linux (/proc/self/fd descriptor isolation).');
        }
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::fail('proc_open and proc_terminate are required on Linux CI runners; the external race worker cannot be launched at all.');
        }
        if (!function_exists('posix_kill')) {
            self::fail('ext-posix (posix_kill) is required on Linux CI runners for bounded descendant cleanup and readiness probes; without it the descendant fixture cannot be reaped safely.');
        }
    }

    protected function tearDown(): void
    {
        $errors = [];
        try {
            foreach ($this->workers as $worker) {
                // Each worker gets its own try/catch so one throwing
                // terminate() cannot skip the remaining workers.
                try {
                    $worker->terminate();
                } catch (\Throwable $exception) {
                    $errors[] = get_class($exception) . ': ' . $exception->getMessage();
                }
            }
        } finally {
            // Unlinks and parent::tearDown() run even if terminate() blew up
            // catastrophically — and unlink failures are NOT a new failure condition.
            $this->workers = [];
            foreach ($this->temporaryFiles as $file) {
                @unlink($file);
            }
            $this->temporaryFiles = [];
            parent::tearDown();
        }
        // Aggregated exceptions are rethrown AFTER full cleanup — never swallowed.
        if ($errors !== []) {
            throw new \RuntimeException('tearDown could not terminate all registered workers: ' . implode(' | ', $errors));
        }
    }

    /**
     * A stdin-blocked child that first declares itself ready must be killed
     * by terminate() within its bounds and must be confirmed reaped. The
     * final assertion is strict: a child that ended with exit code 0 must
     * FAIL this test (a blocked child is never expected to exit 0).
     */
    public function testTerminateKillsStdinBlockedWorkerAndConfirmsExit(): void
    {
        $worker = $this->launchWorker($this->blockingWithReadyScript());

        try {
            self::assertGreaterThan(0, $worker->pid(), 'the worker must be a real process');
            self::assertNotSame(getmypid(), $worker->pid(), 'the worker must never be this process');

            $ready = $worker->readLineWait(10.0);
            self::assertSame('READY:stdin-blocked', $ready, 'the child must print its readiness line before blocking');

            $started = microtime(true);
            $worker->terminate();
            $elapsed = microtime(true) - $started;

            self::assertLessThan(8.0, $elapsed, 'terminate() must be bounded (2s SIGTERM grace + 5s SIGKILL grace + poll overhead)');
            self::assertFalse($worker->isRunning(), 'a terminated worker may not report as running');
            self::assertFalse($worker->wasNotReaped(), 'a terminated blocked child must be confirmably reaped');
            self::assertFalse($worker->signalFailed(), 'proc_terminate must not report failure for a live child');
            $status = $worker->finalStatus();
            self::assertIsArray($status, 'the decisive first not-running read must be cached and readable');
            self::assertTrue(
                $status['signaled'] === true || (is_int($status['exitCode']) && $status['exitCode'] > 0),
                'a blocked child must be observed as signaled or as a POSITIVE non-zero exit — a clean exit 0, or exit -1 with signaled=false, means the child was NOT blocked or was never signaled'
            );
        } finally {
            $worker->terminate();
        }
    }

    /**
     * A worker that already exited cleanly must be reaped by the normal
     * close path without any signal, must not be reported as unreaped, and
     * the strict alive check must be false afterwards.
     */
    public function testTerminateAfterCleanExitIsConfirmedAndDoesNotNeedSignals(): void
    {
        $worker = $this->launchWorker($this->exitsCleanlyScript());

        try {
            self::assertSame(0, $worker->waitForExit(20.0), 'a clean exit(0) must be observed as exit code 0');
            $worker->terminate();

            self::assertFalse($worker->wasNotReaped(), 'an already-exited worker must be confirmed reaped');
            self::assertFalse($worker->signalFailed(), 'no signal is needed for an exit-confirmed worker');
            $status = $worker->finalStatus();
            self::assertIsArray($status);
            self::assertSame(0, $status['exitCode']);
            self::assertFalse($status['signaled']);
            self::assertFalse($status['stopped']);
            self::assertFalse($worker->isConfirmedRunning(), 'no exited worker may be reported as confirmed-alive');
        } finally {
            $worker->terminate();
        }
    }

    /**
     * A child that kills ITSELF with SIGKILL must be observed as signaled —
     * the strict final child check in the Integration race test depends on
     * this distinction being preserved in the cached snapshot. There is no
     * PHP-core way for a child to die BY A SIGNAL without an extension, so
     * this test requires ext-posix; on a Linux runner without it, the test
     * FAILS loudly rather than skips (fail-closed).
     */
    public function testSelfSigKillIsObservedAsSignaled(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL')) {
            self::fail('ext-posix with SIGKILL is required on Linux CI runners to observe signaled death of a self-killed child.');
        }
        $worker = $this->launchWorker($this->killsSelfScript());

        try {
            $started = microtime(true);
            // waitForExit() returns the INT exit code (-1 on deadline expiry,
            // which assertTrue could never see), so the boolean observation
            // itself is asserted through waitForConfirmedExit().
            self::assertTrue($worker->waitForConfirmedExit(20.0), 'waitForConfirmedExit() must report TRUE: the child died by its own SIGKILL and the exit must be OBSERVED, not deadline-expired');
            $elapsed = microtime(true) - $started;

            self::assertLessThan(10.0, $elapsed, 'the child died by its own SIGKILL; waitForConfirmedExit() must observe it promptly, not spin to the deadline');
            $status = $worker->finalStatus();
            self::assertIsArray($status, 'the exit observation must be cached');
            self::assertTrue($status['signaled'], 'a SIGKILLed child must be cached as signaled');
            self::assertFalse($worker->wasNotReaped(), 'a SIGKILLed child must be confirmably reaped');
            self::assertFalse($worker->signalFailed(), 'no parent signal was needed');
        } finally {
            $worker->terminate();
        }
    }

    /**
     * Drained output is captured in full, not line-capped: a terminal line
     * after >= 40 noise lines MUST be found.
     */
    public function testDrainFindsEveryTerminalLineAfterNoise(): void
    {
        $worker = $this->launchWorker($this->noiseThenTerminalsScript());

        try {
            self::assertSame(0, $worker->waitForExit(20.0));
            $drain = $worker->drainRemainingLines(5.0);

            self::assertTrue($drain['complete'], 'the worker already exited; the drain must complete');
            self::assertFalse($worker->outputTruncated(), 'the noise volume stays under 1 MiB');
            $terminal = array_values(array_filter(
                $drain['lines'],
                static fn (string $line): bool => str_starts_with($line, 'RESULT:') || str_starts_with($line, 'ERROR:')
            ));
            self::assertCount(2, $terminal, 'both terminal lines, before and after 40 noise lines, must be collected');
            self::assertSame('RESULT:x', $terminal[0]);
            self::assertSame('ERROR:y', $terminal[1]);
        } finally {
            $worker->terminate();
        }
    }

    /**
     * A final line WITHOUT a trailing newline must be returned as the
     * trailing fragment, never silently dropped.
     */
    public function testDrainReturnsUnterminatedFinalLineAsTrailingFragment(): void
    {
        $worker = $this->launchWorker($this->unterminatedTerminalScript());

        try {
            self::assertSame(0, $worker->waitForExit(20.0));
            $drain = $worker->drainRemainingLines(5.0);

            self::assertTrue($drain['complete']);
            self::assertSame([], $drain['lines'], 'the only bytes are the unterminated final line');
            self::assertSame('RESULT:unterminated', $drain['trailing']);
        } finally {
            $worker->terminate();
        }
    }

    /**
     * ROUND9-FIX ITEM A (the requested ORDER, now with the Step B mechanism):
     * the descendant is alive (pid signal) and blocked on a gate file. The
     * direct worker has ALREADY exited and that exit is OBSERVED — the
     * observation is made WITHOUT closing the stdout read end (isConfirmedRunning()
     * -> readStatus() and the final-status cache only; nothing in that path
     * tears a pipe down), BEFORE the drain runs. While the descendant holds
     * the pipe: the drain MUST report complete=false with no lines and a
     * null trailing fragment. After the gate is released the descendant
     * writes its late line and exits; the pipe then reaches EOF and the
     * drain MUST return complete=true with LATE:line received EXACTLY ONCE
     * (newline-terminated, never the trailing fragment). The descendant can
     * never live forever: pid-signalled readiness, 30s self-timeout
     * in-script, TERM then KILL via posix_kill in finally (bounded).
     */
    public function testDrainAfterConfirmedExitDoesNotClaimEofWhileDescendantHoldsStdout(): void
    {
        ['workerScript' => $workerScript, 'pidFile' => $pidFile, 'gateFile' => $gateFile, 'token' => $token] = $this->descendantFixture();
        $worker = $this->launchWorker($workerScript);
        $descendantPid = null;
        $descendantGone = false;

        try {
            $descendantPid = $this->waitForDescendantPid($pidFile, 10.0);
            self::assertNotNull($descendantPid, 'the descendant must signal readiness by writing its pid within the bounded window (proxy assertion)');
            self::assertTrue(
                $this->waitForDirectWorkerExitObserved($worker, 5.0),
                'the direct worker exit must be OBSERVED (final-status cache filled) before the drain runs — without closing the stdout read end'
            );
            $finalStatus = $worker->finalStatus();
            self::assertIsArray($finalStatus, 'the decisive exit observation must be cached and readable');
            self::assertSame(0, $finalStatus['exitCode'], 'the direct worker exits 0 — exit 7 means the STDOUT resource descriptor spec is unsupported on this PHP build');

            $drain = $worker->drainRemainingLines(0.5);
            self::assertFalse($drain['complete'], 'the descendant still holds stdout: EOF is not observable — complete must be false');
            self::assertSame([], $drain['lines'], 'no line may arrive while the descendant is blocked on the gate');
            self::assertNull($drain['trailing'], 'no trailing bytes may exist without an arrival');
            self::assertFalse($this->descendantExited($descendantPid, 0.2, $token), 'the descendant must still be alive while the gate is held');

            touch($gateFile);

            $drainAfter = $worker->drainRemainingLines(5.0);
            self::assertTrue($drainAfter['complete'], 'once the gate is released the descendant writes and exits — the pipe reaches EOF and complete MUST be true');
            self::assertSame(['LATE:line'], $drainAfter['lines'], 'the late line must be received EXACTLY ONCE');
            self::assertNull($drainAfter['trailing'], 'a newline-terminated line is not the trailing fragment');
            self::assertTrue($this->descendantExited($descendantPid, 5.0, $token), 'after the gate is released the descendant must exit within the bounded window');
            $descendantGone = true; // OBSERVED gone: the PID must never be signalled again
        } finally {
            if (!is_file($gateFile)) {
                @touch($gateFile); // let the descendant end even on failure
            }
            if (!$descendantGone) {
                $this->shutdownDescendant($descendantPid, $token);
            }
            $worker->terminate();
        }
    }

    /**
     * ROUND8 ITEM A (the OTHER order — EOF semantics proof), unchanged in
     * substance: the drain runs WITHOUT calling waitForConfirmedExit()
     * first, so the stdout read end stays OPEN and EOF is observable.
     * While the descendant is blocked on the gate: complete MUST be false.
     * After the gate is released the descendant writes its line and exits —
     * the pipe then reaches EOF and the drain MUST return complete=true with
     * the LATE line received EXACTLY once (newline-terminated, not the
     * trailing fragment). Complete may become true ONLY after real feof.
     * NOTE: this deliberately does NOT use the confirmed-exit-first order
     * of the other test; that order now relies on waitForDirectWorkerExitObserved()
     * — the Step B mechanism of the ROUND9-FIX round (see the round-9 log
     * entry) — so this test's contrast is the OPEN-from-the-start read end,
     * and neither order may depend on waitForConfirmedExit() (it closes the
     * read end on the observed exit).
     */
    public function testDrainAfterGateReleaseCapturesLateLineExactlyOnce(): void
    {
        ['workerScript' => $workerScript, 'pidFile' => $pidFile, 'gateFile' => $gateFile, 'token' => $token] = $this->descendantFixture();
        $worker = $this->launchWorker($workerScript);
        $descendantPid = null;
        $descendantGone = false;

        try {
            $descendantPid = $this->waitForDescendantPid($pidFile, 10.0);
            self::assertNotNull($descendantPid, 'the descendant must signal readiness by writing its pid within the bounded window (proxy assertion)');

            $drainBlocked = $worker->drainRemainingLines(2.0);
            self::assertFalse($drainBlocked['complete'], 'the descendant holds the pipe and is blocked on the gate: EOF must not be observable');
            self::assertSame([], $drainBlocked['lines'], 'no line may arrive before the gate is released');
            self::assertNull($drainBlocked['trailing'], 'no trailing bytes may exist without an arrival');

            touch($gateFile);

            $drain = $worker->drainRemainingLines(5.0);
            self::assertTrue($drain['complete'], 'once the gate is released the descendant writes and exits — the pipe reaches EOF and complete MUST be true');
            self::assertSame(['LATE:line'], $drain['lines'], 'the late line must be received EXACTLY ONCE');
            self::assertNull($drain['trailing'], 'a newline-terminated line is not the trailing fragment');
            self::assertTrue($worker->waitForConfirmedExit(5.0), 'the direct worker exit must be observable afterwards');
            self::assertSame(0, $worker->waitForExit(1.0), 'the direct worker exits 0 — exit 7 means the STDOUT resource descriptor spec is unsupported on this PHP build');
            self::assertTrue($this->descendantExited($descendantPid, 5.0, $token), 'the descendant must have exited once its line was written');
            $descendantGone = true; // OBSERVED gone: the PID must never be signalled again
        } finally {
            if (!is_file($gateFile)) {
                @touch($gateFile); // let the descendant end even on failure
            }
            if (!$descendantGone) {
                $this->shutdownDescendant($descendantPid, $token);
            }
            $worker->terminate();
        }
    }

    /**
     * ITEM A (ROUND10-FIX): the PID-reuse guard must REFUSE to signal a
     * PID whose /proc/<pid>/cmdline does NOT carry this run's unique
     * token — a plain `php -r 'sleep(30);'` starts without the token; the
     * guard must refuse both TERM and KILL (returns false, and the child is
     * still alive afterwards). The same-shaped child started WITH the token
     * IS signalled (PHP CLI keeps extra arguments in argv verbatim, so the
     * token appears in /proc/<pid>/cmdline). Each assertion proves which
     * gate refused/allowed. The PHPUnit process's own PID is never passed to
     * any signal helper; the children are cleaned up in finally (TERM,
     * bounded poll, KILL, proc_close).
     */
    public function testGuardedDescendantSignalRefusesUnrelatedProcess(): void
    {
        if (!function_exists('posix_kill')) {
            self::fail('ext-posix (posix_kill) is required on Linux CI runners to verify the PID-reuse guard for the descendant fixture.');
        }
        if (!is_readable('/proc/self/cmdline')) {
            self::fail('/proc is unreadable on this Linux CI runner; the PID-reuse guard cannot be verified.');
        }

        $token = 'cpms-descendant-token=' . bin2hex(random_bytes(8));

        $unrelated = $this->startHarmlessSleepChild([]);
        $carrier = $this->startHarmlessSleepChild([$token]);
        /** @var list<array{process: resource, pid: int}> $children */
        $children = [$unrelated, $carrier];

        try {
            self::assertNotSame(0, $unrelated['pid'], 'the unrelated child must be a real process');
            self::assertNotSame(0, $carrier['pid'], 'the token-carrying child must be a real process');

            self::assertFalse($this->signalDescendant($unrelated['pid'], 15, $token), 'the guard must REFUSE to TERM a process without the token');
            self::assertFalse($this->signalDescendant($unrelated['pid'], 9, $token), 'the guard must REFUSE the KILL step too');
            self::assertTrue(@posix_kill($unrelated['pid'], 0), 'the unrelated child must STILL be alive — the guard sent nothing (signal 0 probe after refusal)');

            // A forked child that has not yet exec'd serves the LAUNCHER's
            // argv in /proc/<pid>/cmdline, which lacks the token; gate the
            // allow-branch assertions on the carrier actually exec'ing (bounded
            // poll — not a fixed sleep).
            self::assertTrue(
                $this->waitForDescendantToken($carrier['pid'], $token, 5.0),
                'the carrier child must exec and show the token in /proc/<pid>/cmdline within the bounded window (bounded poll, not a fixed sleep)'
            );
            self::assertTrue($this->signalDescendant($carrier['pid'], 15, $token), 'the guard must ALLOW the TERM for a process carrying the token');
            $deadline = microtime(true) + 5.0;
            $carrierGone = false;
            while (microtime(true) < $deadline) {
                if (!@posix_kill($carrier['pid'], 0)) {
                    $carrierGone = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($carrierGone, 'the token-carrying child must have died after the allowed TERM (bounded poll)');
        } finally {
            foreach ($children as $child) {
                if (!is_resource($child['process'])) {
                    continue;
                }
                if ($child['pid'] > 1 && @posix_kill($child['pid'], 0)) {
                    @posix_kill($child['pid'], 15); // direct cleanup of our own harmless child: TERM first
                    $deadline = microtime(true) + 2.0;
                    while (microtime(true) < $deadline && @posix_kill($child['pid'], 0)) {
                        usleep(20000);
                    }
                    if (@posix_kill($child['pid'], 0)) {
                        @posix_kill($child['pid'], 9); // then KILL if it ignored TERM
                        $deadline = microtime(true) + 2.0;
                        while (microtime(true) < $deadline && @posix_kill($child['pid'], 0)) {
                            usleep(10000);
                        }
                    }
                }
                @proc_close($child['process']);
            }
        }
    }

    /**
     * Start a harmless child for the PID-reuse guard test: a bare
     * `php -r 'sleep(30);'` that accepts an optional trailing token argument
     * (PHP CLI keeps extra arguments in argv verbatim, so the token appears
     * in /proc/<pid>/cmdline when passed). A failed launch fails the test
     * loudly. Returns the raw process resource and the OS pid (0 when
     * unreadable).
     *
     * @param list<string> $extraArgs
     * @return array{process: resource, pid: int}
     */
    private function startHarmlessSleepChild(array $extraArgs): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge([PHP_BINARY, '-r', 'sleep(30);'], $extraArgs), $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('could not launch the harmless sleep fixture for the PID-reuse guard test');
        }
        $pid = 0;
        $status = @proc_get_status($process);
        if (is_array($status) && isset($status['pid']) && is_int($status['pid']) && $status['pid'] > 0) {
            $pid = (int) $status['pid'];
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return ['process' => $process, 'pid' => $pid];
    }

    /**
     * More than 1 MiB of stdout must set the sticky outputTruncated flag so
     * the caller can fail closed on lost bytes.
     */
    public function testOutputTruncatedFlagIsStickyWhenBufferOverflows(): void
    {
        $worker = $this->launchWorker($this->overflowScript());

        try {
            self::assertSame(0, $worker->waitForExit(20.0));
            self::assertTrue($worker->outputTruncated(), 'over 1 MiB of stdout must discard bytes and raise the sticky flag');
        } finally {
            $worker->terminate();
        }
    }

    /**
     * B5/ROUND7: a raw status array WITHOUT a bool 'running' key — or with a
     * non-bool — is DOUBT. It must not be cached as a final status, must
     * report isRunning() true (doubt direction), and isConfirmedRunning()
     * false. The subclass overrides ONLY the raw seam (readRawStatus()), so
     * readStatus() — the real validation + cache guard — is what runs here.
     * terminate() on an always-doubt worker can never confirm exit: it sends
     * SIGTERM, spins the 2s bound, SIGKILLs, spins the 5s bound, then closes
     * the pipes and refuses the reaping path; wasNotReaped() is therefore
     * TRUE by design (asserted, not assumed). The underlying child really
     * exits quickly — it is unobservable to the doubtful launcher.
     */
    public function testMissingOrNonBoolRunningKeyIsDoubtNeverExit(): void
    {
        foreach (['missing', 'nonbool'] as $shape) {
            $worker = $this->launchDoubtWorker($shape);
            try {
                self::assertTrue($worker->isRunning(), 'doubt must keep the fail-closed alive direction (' . $shape . ')');
                self::assertFalse($worker->isConfirmedRunning(), 'doubt can never confirm alive (' . $shape . ')');
                self::assertNull($worker->finalStatus(), 'doubt must never be cached as a final status (' . $shape . ')');
            } finally {
                $worker->terminate();
                self::assertTrue($worker->wasNotReaped(), 'injected doubt can never confirm exit, so terminate() must refuse the reaping path (' . $shape . ')');
            }
        }
    }

    /**
     * ROUND7 FIX 2(c): after a doubt result, a following raw VALID
     * running=false array IS accepted through the real guard and the
     * final-status cache IS written.
     */
    public function testDoubtThenValidFalseIsAcceptedAndCaches(): void
    {
        $worker = $this->launchDoubtWorker('doubtThenValid');
        try {
            self::assertTrue($worker->isRunning(), 'the first read must be doubt (fail-closed alive)');
            self::assertNull($worker->finalStatus(), 'the doubt read may not write the cache');
            self::assertFalse($worker->isConfirmedRunning(), 'the second raw read reports a valid exit; the strict alive check is false');
            $status = $worker->finalStatus();
            self::assertIsArray($status, 'a valid running=false AFTER doubt MUST be cached by the real guard');
            self::assertSame(0, $status['exitCode']);
            self::assertFalse($status['signaled']);
            self::assertFalse($status['stopped']);
        } finally {
            $worker->terminate();
            self::assertFalse($worker->wasNotReaped(), 'the cache was now valid, so terminate() must reap cleanly');
        }
    }

    /**
     * ROUND8 ITEM B (mechanics, matching the real code): the constructor
     * consumes raw read #1 and caches the first decisive exit (running=false,
     * exit code 137). Afterwards: isRunning() short-circuits on the cache
     * WITHOUT another raw read (counter must NOT grow); isConfirmedRunning()
     * performs a FRESH raw read (no cache short-circuit) — the counter grows
     * by EXACTLY one and the result reflects the injected raw array; the
     * cached snapshot NEVER changes.
     */
    public function testCachedExitIsNotOverwrittenByALaterAliveRead(): void
    {
        $worker = $this->launchDoubtWorker('exitThenAlive');
        try {
            self::assertSame(1, $worker->rawReadCount(), 'the constructor consumes exactly ONE raw read (decisive 137)');
            self::assertFalse($worker->isRunning(), 'a decisive valid exit read caches not-running');
            self::assertSame(1, $worker->rawReadCount(), 'isRunning() short-circuits on the cached final status — the raw counter must NOT grow');
            $snapshot = $worker->finalStatus();
            self::assertIsArray($snapshot);
            self::assertSame(137, $snapshot['exitCode']);
            self::assertTrue($worker->isConfirmedRunning(), 'isConfirmedRunning() performs a FRESH raw read (no cache short-circuit) — the injected running=true satisfies the strict check');
            self::assertSame(2, $worker->rawReadCount(), 'isConfirmedRunning() grew the raw counter by EXACTLY one');
            self::assertSame($snapshot, $worker->finalStatus(), 'a later alive raw read must NOT rewrite the cached final status');
        } finally {
            $worker->terminate();
            self::assertFalse($worker->wasNotReaped());
        }
    }

    /**
     * ROUND8 ITEM B, second case: a LATER raw status running=false with a
     * DIFFERENT exit code (42) must NOT overwrite the cached exit code (137).
     */
    public function testCachedExitIsNotOverwrittenByALaterDifferentExitCode(): void
    {
        $worker = $this->launchDoubtWorker('exitThenDifferentExit');
        try {
            self::assertSame(1, $worker->rawReadCount(), 'the constructor consumes exactly ONE raw read (decisive 137)');
            $snapshot = $worker->finalStatus();
            self::assertIsArray($snapshot);
            self::assertSame(137, $snapshot['exitCode'], 'the first decisive read is cached — 137');
            self::assertFalse($worker->isConfirmedRunning(), 'the second raw read reports a valid exit (42); the strict alive check is false');
            self::assertSame(2, $worker->rawReadCount(), 'the raw counter grew by EXACTLY one');
            self::assertSame(137, $worker->finalStatus()['exitCode'], 'the DIFFERENT later exit code (42) must NOT overwrite the cached exit code (137)');
        } finally {
            $worker->terminate();
            self::assertFalse($worker->wasNotReaped());
        }
    }

    /** @param ExternalRaceWorker $worker */
    private function launchWorker(string $script): ExternalRaceWorker
    {
        $worker = ExternalRaceWorker::launch($script, []);
        $this->workers[] = $worker;

        return $worker;
    }

    /**
     * Descendant fixture (ROUND8 ITEM A, token hardened ROUND10 ITEM A): the
     * worker script spawns a bounded descendant that INHERITS the worker's
     * STDOUT (proc_open with the STDOUT resource as descriptor spec), then
     * exits 0. The descendant signals readiness by writing its pid to a pid
     * file and refuses to write its late line until a gate file exists — no
     * fixed sleep as synchronization. BOTH paths are pre-registered in
     * temporaryFiles (the tearDown unlinks them; the gate may legitimately
     * not exist yet), and the scripts are bounded (pid self-timeout 30s).
     *
     * The descendant is launched with ONE extra argument carrying a unique
     * identity token (`cpms-descendant-token=<16 hex>`), which therefore
     * appears in its /proc/<pid>/cmdline on Linux; the token is unique per
     * test run and is the identity the PID-reuse guard checks before ANY
     * TERM/KILL. The grandchild script ignores the argument.
     *
     * @return array{workerScript: string, pidFile: string, gateFile: string, token: string}
     */
    private function descendantFixture(): array
    {
        $rand = bin2hex(random_bytes(8));
        $pidFile = sys_get_temp_dir() . '/cpms-ext-worker-' . $rand . '.pid';
        $gateFile = sys_get_temp_dir() . '/cpms-ext-worker-' . $rand . '.gate';
        $token = 'cpms-descendant-token=' . bin2hex(random_bytes(8));
        $this->temporaryFiles[] = $pidFile;
        $this->temporaryFiles[] = $gateFile;

        $grandchild = $this->temporaryScript(
            "<?php\n" .
            "\$pidFile = " . var_export($pidFile, true) . ";\n" .
            "\$gateFile = " . var_export($gateFile, true) . ";\n" .
            "file_put_contents(\$pidFile, (string) getmypid());\n" .
            "\$deadline = microtime(true) + 30.0; // bounded self-timeout: never lives forever\n" .
            "while (!is_file(\$gateFile) && microtime(true) < \$deadline) {\n" .
            "    usleep(20000);\n" .
            "}\n" .
            "if (!is_file(\$gateFile)) {\n" .
            "    exit(8);\n" .
            "}\n" .
            "fwrite(STDOUT, \"LATE:line\n\");\n" .
            "exit(0);\n"
        );
        $workerScript = $this->temporaryScript(
            "<?php\n" .
            "\$descriptors = [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDOUT];\n" .
            "\$process = @proc_open([PHP_BINARY, " . var_export($grandchild, true) . ", " . var_export($token, true) . "], \$descriptors, \$pipes);\n" .
            "exit(is_resource(\$process) ? 0 : 7);\n"
        );

        return ['workerScript' => $workerScript, 'pidFile' => $pidFile, 'gateFile' => $gateFile, 'token' => $token];
    }

    /**
     * Bounded poll for the descendant's readiness signal (the pid file).
     * Proxy assertion (subject to runner load); the descendant itself is
     * bounded by its own 30s self-timeout.
     */
    private function waitForDescendantPid(string $pidFile, float $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($pidFile)) {
                $raw = trim((string) file_get_contents($pidFile));
                if (ctype_digit($raw) && (int) $raw > 0) {
                    return (int) $raw;
                }
            }
            usleep(10000);
        }

        return null;
    }

    /**
     * Bounded wait until the child's /proc/<pid>/cmdline is READABLE and
     * carries the run-scoped token — i.e. until the child has exec'd and
     * its own argv is visible. A forked-but-not-yet-exec'd child shows the
     * launcher's argv instead (no token), which is why the allow-branch
     * assertions must be gated on this handshake; bounded poll, never a
     * fixed sleep.
     */
    private function waitForDescendantToken(int $pid, string $token, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->descendantIdentityMatches($pid, $token)) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }

    /**
     * Identity check for a descendant PID (ITEM A, ROUND10-FIX): the pid
     * must be an integer > 1 and /proc/<pid>/cmdline must be READABLE and
     * CONTAIN the run-scoped unique token passed to the fixture at launch.
     * On a missing fd, an unreadable/unavailable /proc, or a token mismatch
     * (e.g. PID was reused by an unrelated process — including its cmdline
     * being unreadable or empty, as in a zombie): returns false — the caller
     * MUST NOT signal the PID in that case. (Linux-only guard; on non-Linux
     * hosts /proc does not exist and every identity check returns false,
     * which is the refuse direction.)
     */
    private function descendantIdentityMatches(int $pid, string $token): bool
    {
        if ($pid <= 1 || $token === '') {
            return false;
        }
        $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (!is_string($raw)) {
            return false;
        }

        return strpos($raw, $token) !== false;
    }

    /**
     * Existence probe (signal 0 sends NO signal on any OS) AND identity
     * match against the run-scoped token. A PID without the token — for
     * example one reused by an unrelated process — counts as NOT alive.
     * A missing posix_kill turns into a loud false (the assert fails), never
     * a silent pass.
     */
    private function descendantIsAlive(int $pid, string $token): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        if (!@posix_kill($pid, 0)) {
            return false; // signal 0 sends nothing
        }

        return $this->descendantIdentityMatches($pid, $token);
    }

    /**
     * Bounded poll until the descendant pid is gone. On Linux, a missing
     * posix_kill turns into a loud false (the assert fails), never a skip.
     */
    private function descendantExited(int $pid, float $timeoutSeconds, string $token): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (!$this->descendantIsAlive($pid, $token)) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }

    /**
     * Identity-guarded signal for the descendant fixture (ITEM A, ROUND10-FIX):
     * verifies identity (PID integer > 1, /proc/<pid>/cmdline readable and
     * carrying the run-scoped token) BEFORE any posix_kill. A PID that fails
     * verification — invalid, /proc unreadable, or a reused PID without the
     * token — is NEVER signalled; the caller treats refusal as
     * gone/reused/unverifiable and relies on the gate file plus the
     * descendant's own 30s self-timeout. Returns true when the signal was
     * handed to the kernel, false when it was refused.
     */
    private function signalDescendant(int $pid, int $signal, string $token): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        if (!$this->descendantIdentityMatches($pid, $token)) {
            return false; // refused: do NOT signal an unverified PID
        }

        return @posix_kill($pid, $signal);
    }

    /**
     * Best-effort descendant cleanup: TERM, bounded poll, then KILL — both
     * signals pass the SAME identity guard (ITEM A: PID integer > 1,
     * /proc/<pid>/cmdline readable and carrying the token). Always bounded;
     * never throws (cleanup must not mask a test failure). When the PID is
     * not verified (gone, reused, /proc unreadable) NOTHING is signalled.
     */
    private function shutdownDescendant(?int $pid, ?string $token = null): void
    {
        if ($pid === null || $token === null || !function_exists('posix_kill')) {
            return; // no safely-signalable identity — rely on the 30s self-timeout
        }
        if (!$this->signalDescendant($pid, 15, $token)) { // SIGTERM — numeric POSIX constant, stable on Linux
            return; // already gone / invalid / reused / unverifiable: never signal
        }
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if (!$this->descendantIsAlive($pid, $token)) {
                return;
            }
            usleep(20000);
        }
        // KILL step: identity is re-verified inside signalDescendant before signalling.
        $this->signalDescendant($pid, 9, $token); // SIGKILL — numeric POSIX constant, stable on Linux
    }

    /**
     * STEP B mechanism (ROUND9-FIX): bounded observation of the direct
     * worker's exit WITHOUT closing the stdout read end. Each poll routes a
     * fresh proc_get_status() read through readStatus() (via
     * isConfirmedRunning()), which fills the one-shot final-status cache on
     * the FIRST not-running observation; NOTHING in that path tears a pipe
     * down (verified at the code paths: isConfirmedRunning() -> readStatus()
     * -> readRawStatus(); neither calls pump(), close(), nor terminate()).
     * Returns true once the cache is filled. Bounded by the deadline; no
     * wall-clock assertion is added.
     */
    private function waitForDirectWorkerExitObserved(ExternalRaceWorker $worker, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $worker->isConfirmedRunning();
            if ($worker->finalStatus() !== null) {
                return true;
            }
            usleep(2000);
        }

        return false;
    }

    /**
     * Launch a directly-constructed doubt-injection worker and REGISTER it
     * for tearDown() cleanup (it is real, and may hold a live process).
     */
    private function launchDoubtWorker(string $shape): MissingRunningWorker
    {
        $script = $this->exitsCleanlyScript();
        $worker = new MissingRunningWorker($script, $shape);
        $this->workers[] = $worker;

        return $worker;
    }

    private function blockingWithReadyScript(): string
    {
        return $this->temporaryScript("<?php\nfwrite(STDOUT, \"READY:stdin-blocked\\n\");\nfflush(STDOUT);\nfgets(STDIN);\n");
    }

    private function exitsCleanlyScript(): string
    {
        return $this->temporaryScript("<?php\nexit(0);\n");
    }

    private function killsSelfScript(): string
    {
        return $this->temporaryScript("<?php\nposix_kill(getmypid(), SIGKILL);\n");
    }

    private function noiseThenTerminalsScript(): string
    {
        return $this->temporaryScript(
            "<?php\nfwrite(STDOUT, \"RESULT:x\\n\");\nfor (\$i = 0; \$i < 40; \$i++) {\n    fwrite(STDOUT, \"noise-\" . \$i . \"\\n\");\n}\nfwrite(STDOUT, \"ERROR:y\\n\");\nexit(0);\n"
        );
    }

    private function unterminatedTerminalScript(): string
    {
        return $this->temporaryScript("<?php\nfwrite(STDOUT, \"RESULT:unterminated\");\nexit(0);\n");
    }

    private function overflowScript(): string
    {
        // 1.2 MB of noise: crosses the 1 MiB rolling-buffer ceiling.
        return $this->temporaryScript("<?php\nfwrite(STDOUT, str_repeat(\"noise-overflow-line\\n\", 66000));\nexit(0);\n");
    }

    private function temporaryScript(string $contents): string
    {
        $file = sys_get_temp_dir() . '/cpms-ext-worker-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}

/**
 * Test-only subclass: injects doubtful RAW status arrays through the raw
 * seam (readRawStatus) — NEVER readStatus(), so the real validation and
 * final-status cache run unchanged on top of the injected data. FAKE STATUS
 * IS NOT A FAKE CHILD: the process is real (and harmless; it has already
 * exited by the time any assertion runs).
 */
final class MissingRunningWorker extends ExternalRaceWorker
{
    public const SHAPE_MISSING = 'missing';

    public const SHAPE_NONBOOL = 'nonbool';

    public const SHAPE_DOUBT_THEN_VALID = 'doubtThenValid';

    public const SHAPE_EXIT_THEN_ALIVE = 'exitThenAlive';

    public const SHAPE_EXIT_THEN_DIFFERENT_EXIT = 'exitThenDifferentExit';

    private string $shape;

    private int $rawReads = 0;

    /** Raw-read counter for assertions on the real read-path mechanics. */
    public function rawReadCount(): int
    {
        return $this->rawReads;
    }

    public function __construct(string $script, string $shape)
    {
        $this->shape = $shape;
        // Minimal launch: stdio pipes only, no pinning — the doubt tests
        // drive every read through the injected arrays.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname($script, 3), []);
        parent::__construct($process, is_array($pipes) ? $pipes : []);
    }

    protected function readRawStatus()
    {
        $this->rawReads++;
        if ($this->shape === self::SHAPE_MISSING) {
            return ['pid' => 4242, 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
        }
        if ($this->shape === self::SHAPE_NONBOOL) {
            return ['pid' => 4242, 'running' => 'false', 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
        }
        if ($this->shape === self::SHAPE_DOUBT_THEN_VALID) {
            // Doubt for the first TWO raw reads: the parent constructor
            // consumes raw read #1 (its own readStatus() call), so the
            // doubt the TEST observes is read #2; the valid exit begins at
            // read #3.
            if ($this->rawReads <= 2) {
                return ['pid' => 4242, 'signaled' => false, 'stopped' => false, 'exitcode' => 0]; // doubt
            }

            return ['pid' => 4242, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 0]; // valid exit
        }
        if ($this->shape === self::SHAPE_EXIT_THEN_ALIVE) {
            // SHAPE_EXIT_THEN_ALIVE: decisive first read, then a later alive claim.
            if ($this->rawReads === 1) {
                return ['pid' => 4242, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 137];
            }

            return ['pid' => 4242, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
        }
        // SHAPE_EXIT_THEN_DIFFERENT_EXIT: decisive first read (137), then a
        // later VALID exit with a DIFFERENT code (42) — must not overwrite.
        if ($this->rawReads === 1) {
            return ['pid' => 4242, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 137];
        }

        return ['pid' => 4242, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 42];
    }
}

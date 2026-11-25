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
    }

    protected function tearDown(): void
    {
        $errors = [];
        foreach ($this->workers as $worker) {
            // Each worker gets its own try/finally so one throwing
            // terminate() cannot skip the remaining workers nor the unlink
            // of the temp scripts; exceptions are collected and rethrown
            // AFTER full cleanup — never swallowed.
            try {
                $worker->terminate();
            } catch (\Throwable $exception) {
                $errors[] = get_class($exception) . ': ' . $exception->getMessage();
            }
        }
        $this->workers = [];
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
        if ($errors !== []) {
            throw new \RuntimeException('tearDown could not terminate all registered workers: ' . implode(' | ', $errors));
        }
        parent::tearDown();
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
     * FIX 1: a descendant that INHERITS the worker's stdout (proc_open with
     * the STDOUT resource as descriptor spec) outlives the direct worker and
     * writes later. The drain must either capture the late line or return
     * complete=false — NEVER complete=true with the late line lost. The
     * descendant is bounded: it exits by itself right after writing, and is
     * never left running after the test.
     */
    public function testDrainNeverCompletesTrueWithALateLineLostFromADescendant(): void
    {
        $grandchild = $this->temporaryScript(
            "<?php\nusleep(300000);\nfwrite(STDOUT, \"LATE:line\n\");\nexit(0);\n"
        );
        $workerScript = $this->temporaryScript(
            "<?php\n\$g = " . var_export($grandchild, true) . ";\n\$descriptors = [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDOUT];\n\$process = @proc_open([PHP_BINARY, \$g], \$descriptors, \$pipes);\nexit(is_resource(\$process) ? 0 : 7);\n"
        );
        $worker = $this->launchWorker($workerScript);

        try {
            // No waitForExit() first: drainRemainingLines() does its own
            // bounded wait and never closes the pipes, so EOF stays observable.
            $drain = $worker->drainRemainingLines(3.0);
            if ($drain['complete']) {
                self::assertContains(
                    'LATE:line',
                    $drain['lines'],
                    'complete=true implies stdout reached EOF with every writer closed — the late line MUST have been captured, never lost'
                );
            }
            // complete=false is the fail-closed outcome and is also valid;
            // the forbidden state is only (complete=true && line missing).
            self::assertSame(0, $worker->waitForExit(5.0), 'the direct worker must exit 0 — exit 7 means the STDOUT resource descriptor spec is unsupported on this PHP build');
        } finally {
            $worker->terminate();
        }
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
     * ROUND7 FIX 2(d): the cache-once contract — a decisive running=false
     * first, then a later raw running=true, must NOT rewrite the cached
     * final status. (The code already guarantees cache-once; asserted.)
     */
    public function testCachedExitIsNotOverwrittenByALaterAliveRead(): void
    {
        $worker = $this->launchDoubtWorker('exitThenAlive');
        try {
            self::assertFalse($worker->isRunning(), 'a decisive valid exit read caches not-running');
            $snapshot = $worker->finalStatus();
            self::assertIsArray($snapshot);
            self::assertSame(137, $snapshot['exitCode']);
            $worker->isConfirmedRunning(); // raw read #2 claims alive; the cache may not change
            self::assertSame($snapshot, $worker->finalStatus(), 'a later alive raw read must NOT rewrite the cached final status');
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

    private string $shape;

    private int $rawReads = 0;

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
        // SHAPE_EXIT_THEN_ALIVE: decisive first read, then a later alive claim.
        if ($this->rawReads === 1) {
            return ['pid' => 4242, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 137];
        }

        return ['pid' => 4242, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
    }
}

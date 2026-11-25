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
        foreach ($this->workers as $worker) {
            $worker->terminate();
        }
        $this->workers = [];
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
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
                $status['signaled'] === true || $status['exitCode'] !== 0,
                'a blocked child must be observed as signaled or as a non-zero exit — a clean exit 0 means the child was NOT blocked or was never signaled'
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
            $worker->waitForExit(20.0); // bounded; must return far before the deadline
            $elapsed = microtime(true) - $started;

            self::assertLessThan(10.0, $elapsed, 'the child died by its own SIGKILL; waitForExit() must observe it promptly, not spin to the deadline');
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
     * B5: a status array WITHOUT a bool 'running' key — or with a non-bool —
     * is DOUBT. It must not be cached as a final status, must report
     * isRunning() true (doubt direction), and isConfirmedRunning() false.
     * Driven deterministically through a tiny test-only subclass of the
     * status-read seam; the public API is untouched.
     */
    public function testMissingOrNonBoolRunningKeyIsDoubtNeverExit(): void
    {
        foreach (['missing', 'nonbool'] as $shape) {
            $script = $this->exitsCleanlyScript();
            $worker = new MissingRunningWorker($script, $shape);
            try {
                self::assertTrue($worker->isRunning(), 'doubt must keep the fail-closed alive direction (' . $shape . ')');
                self::assertFalse($worker->isConfirmedRunning(), 'doubt can never confirm alive (' . $shape . ')');
                self::assertNull($worker->finalStatus(), 'doubt must never be cached as a final status (' . $shape . ')');
            } finally {
                $worker->terminate();
            }
        }
    }

    /** @param ExternalRaceWorker $worker */
    private function launchWorker(string $script): ExternalRaceWorker
    {
        $worker = ExternalRaceWorker::launch($script, []);
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
 * Test-only subclass: injects a doubtful status array (missing or non-bool
 * 'running') through the single status-read seam to prove the doubt rule
 * without any environment dependency. The child script is launched normally;
 * the injected array simply shadows what proc_get_status() would report.
 * FAKE STATUS IS NOT A FAKE CHILD: the process is real (and harmless; it has
 * already exited by the time any assertion runs).
 */
final class MissingRunningWorker extends ExternalRaceWorker
{
    private string $shape;

    public function __construct(string $script, string $shape)
    {
        $this->shape = $shape;
        // Minimal launch: stdio pipes only, no pinning — the test never
        // signals or reads this child through the real path.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname($script, 3), []);
        parent::__construct($process, is_array($pipes) ? $pipes : []);
    }

    protected function readStatus(): ?array
    {
        if ($this->shape === 'missing') {
            return ['pid' => 4242, 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
        }

        return ['pid' => 4242, 'running' => 'false', 'signaled' => false, 'stopped' => false, 'exitcode' => 0];
    }
}

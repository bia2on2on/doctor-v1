<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Tests\Support\ExternalRaceWorker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ExternalRaceWorker.php';

/**
 * Failure paths and reaping discipline of the external race-worker launcher.
 *
 * These tests never touch a database: every child is a bare `php -r` script
 * blocking on stdin (no sleeps). They exist because CI is the only PHP
 * executor available and the Integration race test relies on the launcher
 * to (a) always signal the child, (b) never block forever in proc_close(),
 * (c) remember when a child could not be reaped.
 */
final class ExternalRaceWorkerFailurePathsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('proc_open/proc_terminate are required to launch the external worker');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    /**
     * A stdin-blocked child must be killed by terminate() within its bounds
     * and must be confirmed reaped — never a silent leak and never a
     * blocking proc_close().
     */
    public function testTerminateKillsStdinBlockedWorkerAndConfirmsExit(): void
    {
        $script = $this->blockingScript();
        $worker = ExternalRaceWorker::launch($script, []);

        self::assertGreaterThan(0, $worker->pid(), 'the worker must be a real process');
        self::assertNotSame(getmypid(), $worker->pid(), 'the worker must never be this process');

        $started = microtime(true);
        $worker->terminate();
        $elapsed = microtime(true) - $started;

        self::assertLessThan(8.0, $elapsed, 'terminate() must be bounded (2s SIGTERM grace + 5s SIGKILL grace + poll overhead)');
        self::assertFalse($worker->isRunning(), 'a terminated worker may not report as running');
        self::assertFalse($worker->wasNotReaped(), 'a SIGTERMed php -r worker must be confirmably reaped');
        self::assertFalse($worker->signalFailed(), 'proc_terminate must not report failure for a live child');
        $status = $worker->finalStatus();
        self::assertIsArray($status, 'the decisive first not-running read must be cached and readable');
        self::assertTrue(
            $status['signaled'] || $status['exitCode'] >= 0,
            'final status must show termination by signal or an exit code'
        );
    }

    /**
     * A worker that already exited must be reaped by the normal close path
     * without any signal being sent, and must not be reported as blocked.
     */
    public function testTerminateAfterCleanExitIsConfirmedAndDoesNotNeedSignals(): void
    {
        $script = $this->exitsCleanlyScript();
        $worker = ExternalRaceWorker::launch($script, []);

        self::assertSame(0, $worker->waitForExit(20.0), 'a clean exit(0) must be observed as exit code 0');
        $worker->terminate();

        self::assertFalse($worker->wasNotReaped(), 'an already-exited worker must be confirmed reaped');
        self::assertFalse($worker->signalFailed(), 'no signal is needed for an exit-confirmed worker');
        $status = $worker->finalStatus();
        self::assertIsArray($status);
        self::assertSame(0, $status['exitCode']);
        self::assertFalse($status['signaled']);
        self::assertFalse($status['stopped']);
    }

    /**
     * A child killed by SIGKILL must be observed as signaled, not as an
     * ordinary exit — the strict final child check in the Integration race
     * test depends on this distinction being preserved in the cache.
     */
    public function testSigKillChildIsObservedAsSignaled(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL')) {
            self::markTestSkipped('posix_kill and SIGKILL are required for the self-SIGKILL child');
        }
        $script = $this->killsSelfScript();
        $worker = ExternalRaceWorker::launch($script, []);

        // Do not assert the returned code: the value pre-8.3 reports for a
        // signaled child is not trusted here; the cached 'signaled' flag is
        // the strict datum this test pins.
        $worker->waitForExit(20.0);
        $status = $worker->finalStatus();
        self::assertIsArray($status, 'the exit observation must be cached');
        self::assertTrue($status['signaled'], 'a SIGKILLed child must be cached as signaled');
        self::assertFalse($worker->wasNotReaped(), 'a SIGKILLed child must be confirmably reaped');
        $worker->terminate();
    }

    /**
     * After the worker has exited and been confirmed, the strict alive
     * check must return false (any doubt or not-running yields false).
     */
    public function testIsConfirmedRunningIsFalseAfterExit(): void
    {
        $script = $this->exitsCleanlyScript();
        $worker = ExternalRaceWorker::launch($script, []);

        self::assertSame(0, $worker->waitForExit(20.0));
        self::assertFalse($worker->isConfirmedRunning(), 'no exited worker may be reported as confirmed-alive');
        $worker->terminate();
    }

    private function blockingScript(): string
    {
        return $this->temporaryScript("<?php\nfgets(STDIN);\n");
    }

    private function exitsCleanlyScript(): string
    {
        return $this->temporaryScript("<?php\nexit(0);\n");
    }

    private function killsSelfScript(): string
    {
        return $this->temporaryScript("<?php\nposix_kill(getmypid(), SIGKILL);\n");
    }

    private function temporaryScript(string $contents): string
    {
        $file = sys_get_temp_dir() . '/cpms-ext-worker-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}

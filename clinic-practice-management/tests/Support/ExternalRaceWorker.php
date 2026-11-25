<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use RuntimeException;

/**
 * External race worker launcher — TEST-ONLY, never production.
 *
 * Why this exists (the mechanism the PR #16 lifetime guard works around):
 *  1) A pcntl_fork() child inherits the parent's *live* MySQL socket (the
 *     harness connection $wpdb). A plain exit(0) destroys it: mysqlnd's
 *     destructor emits COM_QUIT on the shared session and the server tears
 *     the parent's outer transaction down mid-flight (observed in CI run
 *     44992895013, 20 failing tests from one inherited-socket teardown).
 *  2) The fork-child lifetime guard SIGKILLs children instead, which is
 *     safe but indistinguishable from a genuine crash to the parent:
 *     pcntl_wifsignaled(...) is all the parent can ever see, so no strict
 *     normal-exit expectation can be pinned.
 *
 * proc_open() starts a fresh process with exactly three pipes as stdio and
 * — after the descriptor pinning below — every other inherited descriptor
 * of the launcher pinned to /dev/null (PHP never sets FD_CLOEXEC on the
 * mysqli socket, main/streams/xp_socket.c). The worker therefore holds no
 * descriptor of the launcher at all: no MySQL socket, no pipe, no temp
 * file. A graceful exit(0) in the worker is a plain, honest exit — the
 * launcher's session is not merely unobserved, it is not there.
 *
 * Communication is one-way by default: the task JSON travels in
 * CPMS_RACE_PAYLOAD (ids/paths/floats/bools/short strings only — never
 * credentials, never patient data), outcomes travel through mode-specific
 * temp FILES, and the launcher asserts on the exit code + file contents.
 * Protocol-pipe modes read/write the pipes directly and then call
 * waitForExit() exactly like the file modes.
 *
 * The worker scripts themselves are tests/bin/fork-race-*.php; they never
 * load the WordPress *test* bootstrap (install.php drops and recreates every
 * core table), only the configuration file + wp-settings.php like a real
 * installation (the same shape as tests/bin/custom-role-archival-race-worker.php).
 */
final class ExternalRaceWorker
{
    public const TASK_ENV = 'CPMS_RACE_PAYLOAD';

    /** Names the worker legitimately needs; nothing else is forwarded. */
    private const BASE_ENVIRONMENT = [
        'PATH',
        'TMPDIR',
        'SYSTEMROOT',
        'WP_TESTS_DIR',
        'WP_TESTS_CONFIG_FILE_PATH',
        'WP_CORE_DIR',
    ];

    /** @var resource */
    private $process;

    private int $pid = 0;

    private ?int $exitCode = null;

    /**
     * Cached snapshot captured at the single proc_get_status() read that first
     * observes the worker as not running. After that moment the final exit
     * status is served from this cache and never re-read (proc_get_status()
     * does not guarantee identical results on repeated calls).
     *
     * @var array{exitCode: int, signaled: bool, stopped: bool}|null
     */
    private ?array $finalStatus = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var list<int> fd numbers this launcher pinned to /dev/null in the child */
    private static array $pinnedDescriptors = [];

    private string $stdoutBuffer = '';

    private string $diagnostics = '';

    private bool $closed = false;

    /** Sticky: any read observed signaled === true (never reset). */
    private bool $signaledObserved = false;

    /** Sticky: any read observed stopped === true (never reset). */
    private bool $stoppedObserved = false;

    /** True once terminate() could NOT confirm the worker exited (SIGKILL grace also expired). */
    private bool $unreaped = false;

    /** True once proc_terminate() itself reported failure on any signal attempt. */
    private bool $signalFailure = false;

    /**
     * @param array<string, mixed> $task non-secret task parameters only
     */
    public static function launch(string $script, array $task): self
    {
        if (!is_file($script)) {
            throw new RuntimeException('external race worker script is missing');
        }
        $payload = json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('task payload is not JSON-encodable');
        }

        $environment = [self::TASK_ENV => $payload];
        foreach (self::BASE_ENVIRONMENT as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Descriptor isolation: a parent-held session socket would otherwise
        // survive execvp() into the worker as an inheritable descriptor.
        self::$pinnedDescriptors = self::pinDescriptorTargets(array_keys($descriptors));
        foreach (self::$pinnedDescriptors as $fd) {
            $descriptors[$fd] = ['null'];
        }

        $process = @proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname($script, 3), $environment);
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new RuntimeException('could not launch the external race worker');
        }
        // stdin stays open: fire-and-forget workers never read it, protocol
        // workers read commands (commit/rollback/RELEASE/…) from it.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes);
    }

    /** @param resource|null $pipe */
    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $status = $this->readStatus();
        if (is_array($status) && isset($status['pid']) && (int) $status['pid'] > 0) {
            $this->pid = (int) $status['pid'];
        }
        foreach ($pipes as $i => $pipe) {
            if (is_resource($pipe)) {
                $this->pipes[$i] = $pipe;
            }
        }
    }

    /** The worker's process id, or 0 when /proc or proc_get_status cannot report it. */
    public function pid(): int
    {
        return $this->pid;
    }

    /** Whether the worker may still be running (fail-closed readers treat any doubt as running). */
    public function isRunning(): bool
    {
        if ($this->closed || $this->finalStatus !== null) {
            return false;
        }
        $status = $this->readStatus();

        return !is_array($status) || ($status['running'] ?? true) === true;
    }

    /**
     * Strict alive check (ROUND4-STRICT-ALIVE, tightened in ROUND5): true ONLY
     * when a fresh proc_get_status() read — routed through readStatus(), the
     * single status-read site — returns an array with 'running', 'signaled'
     * AND 'stopped' all present and all of type bool, 'running' is exactly
     * true, and 'signaled'/'stopped' are exactly false. Any missing key,
     * non-bool key, read failure or doubt returns false — the opposite fail
     * direction of isRunning().
     *
     * Side effects are explicit, not "none": the read is routed through
     * readStatus(), which caches the final status on the FIRST not-running
     * observation from ANY path and sets the sticky signaled/stopped flags on
     * any read that observes them. A 'running === false' observation from
     * this method therefore DOES populate the final-status cache — this is
     * deliberate (so an exit first observed here is never lost; php-8.2's
     * proc_get_status uses waitpid(WNOHANG|WUNTRACED) and pre-8.3 consumes
     * the exit event on first delivery). The mid-test two assertions that
     * use this method call it while the child is expected alive, so they
     * never observe not-running in a passing test run.
     *
     * The exit code is never read by this method.
     */
    public function isConfirmedRunning(): bool
    {
        if ($this->closed || !is_resource($this->process)) {
            return false;
        }
        $status = $this->readStatus();
        if (!is_array($status)) {
            // Doubt (including proc_get_status failure → false): not confirmed.
            return false;
        }
        if (!array_key_exists('running', $status) || !is_bool($status['running'])) {
            return false;
        }
        if (!array_key_exists('signaled', $status) || !is_bool($status['signaled'])) {
            return false;
        }
        if (!array_key_exists('stopped', $status) || !is_bool($status['stopped'])) {
            return false;
        }

        return $status['running'] === true && $status['signaled'] === false && $status['stopped'] === false;
    }

    /**
     * The cached final status of the worker, or null before the first
     * not-running observation (e.g. before waitForExit() returns). Served from
     * the cache captured at the single read that observed the exit — never
     * re-read. Assertable by tests that require a strict plain-exit-0 finish.
     *
     * @return array{exitCode: int, signaled: bool, stopped: bool}|null
     */
    public function finalStatus(): ?array
    {
        return $this->finalStatus;
    }

    /**
     * Bounded read of one stdout line (protocol-pipe workers). Waits until a
     * newline-terminated line is buffered, the worker exits, or the deadline
     * passes — never blocks the suite indefinitely. Returns the line WITHOUT
     * the trailing newline, or null on timeout/EOF.
     */
    public function readLineWait(float $timeoutSeconds): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $pos = strpos($this->stdoutBuffer, "\n");
            if ($pos !== false) {
                $line = substr($this->stdoutBuffer, 0, $pos);
                $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);

                return rtrim($line, "\r");
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            $this->pump();
            if ($this->closed) {
                // Pipes are closed and the worker is already gone and reaped:
                // no further bytes can arrive. Drain what is buffered, then
                // finish instead of spinning to the deadline.
                $pos = strpos($this->stdoutBuffer, "\n");
                if ($pos !== false) {
                    continue;
                }

                return null;
            }
            $status = $this->readStatus();
            if (is_array($status) && ($status['running'] ?? true) === false) {
                $this->pump(); // final drain
                $pos = strpos($this->stdoutBuffer, "\n");
                if ($pos !== false) {
                    continue;
                }

                return null;
            }
            usleep(1000);
        }
    }

    /** Write one line of input to the worker's stdin (protocol-pipe workers). Returns the byte count (0 on failure). */
    public function writeCommand(string $command): int
    {
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            return 0;
        }
        $written = @fwrite($this->pipes[0], $command . "\n");

        return is_int($written) ? $written : 0;
    }

    /**
     * Bounded wait for the worker to finish; reaps it. Returns the exit
     * code, or -1 when the timeout (default 25s) expires (worker SIGKILLed).
     */
    public function waitForExit(float $timeoutSeconds = 25.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $this->pump();
            if ($this->closed) {
                return $this->exitCode ?? -1;
            }
            $status = $this->readStatus();
            if (is_array($status) && ($status['running'] ?? false) === false) {
                $exitCode = $this->exitCode ?? (int) ($status['exitcode'] ?? -1);
                $this->close();

                return $exitCode;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);

        // Bounded: never leaves an orphan, never blocks the suite.
        $this->terminate();

        return -1;
    }

    /**
     * Records whether terminate() could NOT confirm the worker's exit (after
     * SIGTERM with its 2s bound and SIGKILL with a further 5s bound) and had to
     * abandon proc_close() to avoid blocking. Read by the test AFTER all
     * fixture cleanup to turn the run red with a clear message.
     */
    public function wasNotReaped(): bool
    {
        return $this->unreaped;
    }

    /** True once any proc_terminate() call itself reported failure on a signal attempt. */
    public function signalFailed(): bool
    {
        return $this->signalFailure;
    }

    /**
     * The ONE and ONLY proc_get_status() call site in this class. Every
     * status read (constructor, isRunning, isConfirmedRunning, readStatus
     * callers in waitForExit/terminate) routes here.
     *
     * When running === false is observed for the first time, the status
     * array is captured in the final-status cache BEFORE returning (a first
     * exit observation on any path is never lost). The sticky signaled/stopped
     * flags are set on ANY read observing them; the cached snapshot merges
     * them, so a signalled/stopped observation from any path makes the final
     * strict child check fail.
     *
     * php-8.2 proc_open.c: proc_get_status() uses waitpid(WNOHANG|WUNTRACED);
     * the exit code of a normally exited child is only valid on the first
     * such read — hence the cache-once rule. Reference (not fetched from
     * this sandbox — outbound network here is GitHub/PyPI/npm only):
     * https://www.php.net/manual/en/function.proc-get-status.php — PHP manual.
     *
     * @return array<string, mixed>|null
     */
    private function readStatus(): ?array
    {
        if ($this->closed) {
            return null;
        }
        if (!is_resource($this->process)) {
            return $this->finalStatus !== null
                ? [
                    'running' => false,
                    'exitcode' => $this->finalStatus['exitCode'],
                    'signaled' => $this->finalStatus['signaled'],
                    'stopped' => $this->finalStatus['stopped'],
                ]
                : null;
        }
        $status = @proc_get_status($this->process);
        if (!is_array($status)) {
            return $this->finalStatus !== null
                ? [
                    'running' => false,
                    'exitcode' => $this->finalStatus['exitCode'],
                    'signaled' => $this->finalStatus['signaled'],
                    'stopped' => $this->finalStatus['stopped'],
                ]
                : null;
        }
        if (($status['signaled'] ?? false) === true) {
            $this->signaledObserved = true;
        }
        if (($status['stopped'] ?? false) === true) {
            $this->stoppedObserved = true;
        }
        if (($status['running'] ?? false) === false && $this->finalStatus === null) {
            // The one and only decisive read: cache it and never re-read.
            $this->finalStatus = [
                'exitCode' => (int) ($status['exitcode'] ?? -1),
                'signaled' => (($status['signaled'] ?? false) === true) || $this->signaledObserved,
                'stopped' => (($status['stopped'] ?? false) === true) || $this->stoppedObserved,
            ];
            $this->exitCode = $this->finalStatus['exitCode'];
        }

        return $status;
    }

    private function pump(): void
    {
        foreach ([1, 2] as $which) {
            if (!isset($this->pipes[$which]) || !is_resource($this->pipes[$which])) {
                continue;
            }
            $chunk = (string) stream_get_contents($this->pipes[$which]);
            if ($chunk === '') {
                continue;
            }
            if ($which === 1) {
                $this->stdoutBuffer .= $chunk;
                if (strlen($this->stdoutBuffer) > 1048576) {
                    $this->stdoutBuffer = substr($this->stdoutBuffer, -1048576);
                }
            } else {
                $this->diagnostics .= $chunk;
                if (strlen($this->diagnostics) > 4000) {
                    $this->diagnostics = substr($this->diagnostics, -4000);
                }
            }
        }
    }



    /**
     * Bounded termination, always reaping or refusing loudly:
     *   1. SIGTERM is sent unconditionally (proc_terminate needs no status
     *      read, so an unreadable status never skips the signal); a failed
     *      signal is recorded (signalFailed()).
     *   2. Status is polled up to a 2s bound (usleep poll style, as before).
     *   3. If the exit is not CONFIRMED (the once-only final-status cache was
     *      filled by the decisive running===false read), SIGKILL is sent and
     *      status is polled up to a further 5s bound.
     *   4. proc_close() — which BLOCKS until the child exits (php-8.2
     *      ext/standard/proc_open.c, proc_close waits) — is called ONLY after
     *      a confirmed exit. If the exit is still not confirmed, the pipes
     *      are closed, proc_close() is NEVER called, the worker is remembered
     *      as not reaped (wasNotReaped()), and the caller (the test) must
     *      fail AFTER all fixture/hook cleanup has run.
     * Never throws.
     */
    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }
        $this->pump();

        if ($this->finalStatus === null) {
            // Not confirmed exited: signal FIRST. The signal is sent
            // regardless of status readability.
            if (!@proc_terminate($this->process)) {
                $this->signalFailure = true;
            }
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $this->pump();
                $status = $this->readStatus();
                if (is_array($status) && ($status['running'] ?? true) === false) {
                    break;
                }
                usleep(10000);
            }

            if ($this->finalStatus === null) {
                // Not confirmed after SIGTERM: escalate to SIGKILL.
                if (!@proc_terminate($this->process, 9)) {
                    $this->signalFailure = true;
                }
                $deadline = microtime(true) + 5.0;
                while (microtime(true) < $deadline) {
                    $this->pump();
                    $status = $this->readStatus();
                    if (is_array($status) && ($status['running'] ?? true) === false) {
                        break;
                    }
                    usleep(10000);
                }
            }

            if ($this->finalStatus === null) {
                // Still not confirmed: never call the blocking proc_close();
                // close the pipes, remember "not reaped", and let the test
                // fail AFTER fixture cleanup has run.
                $this->closePipesOnly();
                $this->unreaped = true;
                $this->closed = true;

                return;
            }
        }

        $this->close();
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->pump();
        $this->closed = true;
        $this->closePipesOnly();
        if (is_resource($this->process) && $this->finalStatus !== null) {
            // Reaps only after a CONFIRMED exit; proc_close() blocks until
            // then, so it is never reached otherwise.
            @proc_close($this->process);
        }
    }

    private function closePipesOnly(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
    }

    /**
     * Descriptors this process currently holds beyond stdio, mapped to
     * targets. Read through /proc; no descriptor is opened, dup()ed or
     * closed by this method.
     *
     * @param list<int> $exclude
     * @return list<int>
     */
    private static function pinDescriptorTargets(array $exclude): array
    {
        $pinned = [];
        $dir = '/proc/self/fd';
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            if (PHP_OS_FAMILY === 'Linux') {
                // Fail closed: without /proc/self/fd no descriptor isolation
                // can be established and no worker may be launched.
                throw new RuntimeException('/proc/self/fd cannot be scanned on Linux; external race workers cannot be launched safely');
            }

            // Non-Linux hosts pin nothing (nothing is claimed about isolation).
            return $pinned;
        }
        foreach ($entries as $entry) {
            if (!is_string($entry) || !ctype_digit($entry)) {
                continue;
            }
            $fd = (int) $entry;
            if ($fd <= 2 || in_array($fd, $exclude, true)) {
                continue;
            }
            $target = @readlink($dir . '/' . $fd);
            if (is_string($target)) {
                $pinned[] = $fd;
            }
        }

        return $pinned;
    }
}

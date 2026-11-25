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
/**
 * NOTE: intentionally non-final — the Unit failure-path suite uses a tiny
 * test-only subclass to inject doubtful status arrays through the protected
 * readStatus() seam (B5). No production code instantiates a subclass.
 */
class ExternalRaceWorker
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

    /** Sticky: stdout buffer ever discarded bytes (1 MiB rolling window). */
    private bool $outputTruncated = false;

    /** Sticky: a stdout stream_get_contents() call returned false (never resets). */
    private bool $stdoutReadFailed = false;

    /** Sticky: the stdout read end was observed at EOF (every writer — direct child and any descendant inheriting the pipe — closed). */
    private bool $stdoutEofObserved = false;

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

    /**
     * PROTECTED on purpose (test-only seam): the Unit suite launches a real
     * but harmless child, then a subclass injects doubtful status arrays to
     * prove the doubt rule. Tests only; production-like callers use launch().
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    protected function __construct($process, array $pipes)
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

        return !is_array($status)
            || !array_key_exists('running', $status)
            || !is_bool($status['running'])
            || $status['running'] === true;
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

    /**
     * Bounded drain of everything the worker wrote that has not been
     * consumed yet. Waits (usleep polling, as everywhere in this class) until
     * the stdout READ END reaches EOF, then returns EVERY line still present —
     * including a final line without a trailing newline, which is returned as
     * `trailing` instead of being dropped. There is no line-count cap.
     *
     * `complete` is true ONLY when stdout really reached EOF before the
     * deadline — i.e. every writer (the direct child AND any descendant
     * that inherited the pipe) is closed. A direct worker that exited while
     * a descendant still holds the pipe open does NOT complete: EOF is not
     * observable, the deadline keeps polling, and the return is
     * complete=false. A launcher whose stdout was torn down WITHOUT the EOF
     * ever being observed (pipes closed by close() over an unobserved
     * transport) also returns complete=false — EOF is never inferred from a
     * closed resource or from the worker's exit alone.
     *
     * The caller MUST fail the test when `complete` is false (a later
     * terminal line then cannot be ruled out). The caller MUST also fail the
     * test when outputTruncated() or outputReadFailed() is true.
     *
     * @return array{lines: list<string>, trailing: ?string, complete: bool}
     */
    public function drainRemainingLines(float $timeoutSeconds): array
    {
        $lines = [];
        $trailing = null;
        $complete = false;

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $this->pump();
            if ($this->stdoutEofWasSeen() || $this->stdoutReachedEof()) {
                $this->pump(); // bytes may have arrived just before EOF was observed
                $complete = true;
                break;
            }
            if ($this->closed || !isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
                // The launcher tore stdout down before EOF was ever
                // observed: completeness is unknowable. Never claim it.
                break;
            }
            $this->readStatus(); // decisive read, if any, records the exit for terminate()
            usleep(1000);
        }

        while (($pos = strpos($this->stdoutBuffer, "\n")) !== false) {
            $line = substr($this->stdoutBuffer, 0, $pos);
            $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);
            $lines[] = rtrim($line, "\r");
        }
        if ($this->stdoutBuffer !== '') {
            $trailing = rtrim($this->stdoutBuffer, "\r");
            $this->stdoutBuffer = '';
        }

        return ['lines' => $lines, 'trailing' => $trailing, 'complete' => $complete];
    }

    /** True when the stdout rolling buffer ever discarded bytes (>1 MiB window). */
    public function outputTruncated(): bool
    {
        return $this->outputTruncated;
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
        if (!$this->waitForConfirmedExit($timeoutSeconds)) {
            // waitForConfirmedExit already ran terminate() on the deadline.
            return -1;
        }

        return $this->exitCode ?? -1;
    }

    /**
     * True ONLY when the worker's exit was OBSERVED (a decisive running==false
     * read, or a close with the final status cached) before the deadline.
     * False when the deadline expired — terminate() then runs, exactly as in
     * waitForExit(). Added in ROUND7 so tests can assert the observation
     * itself (e.g. self-SIGKILL observed promptly after the child's signal).
     */
    public function waitForConfirmedExit(float $timeoutSeconds = 25.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $this->pump();
            if ($this->closed) {
                // A close that cached the final status IS an observation; a
                // close without one (e.g. pipes torn down on doubt) is not.
                return $this->finalStatus !== null;
            }
            $status = $this->readStatus();
            if (is_array($status) && is_bool($status['running'] ?? null) && $status['running'] === false) {
                $this->close();

                return true;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);

        // Bounded: never leaves an orphan, never blocks the suite.
        $this->terminate();

        return false;
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
     * Every status read (constructor, isRunning, isConfirmedRunning,
     * waitForExit, terminate) routes here. NOT the proc_get_status() call
     * site itself — readRawStatus() is. PROTECTED on purpose (B5/ROUND7):
     * the Unit suite injects doubtful raw arrays through a tiny test-only
     * subclass overriding readRawStatus(); this validation and cache path
     * is exactly what production callers exercise.
     *
     * When running === false is observed for the first time, the status
     * array is captured in the final-status cache BEFORE returning (a first
     * exit observation on any path is never lost). A MISSING or non-bool
     * 'running' is DOUBT (B5): no cache write; callers see doubt. The sticky
     * signaled/stopped flags are set on ANY read observing them; the cached
     * snapshot merges them, so a signalled/stopped observation from any
     * path makes the final strict child check fail.
     *
     * php-8.2 proc_open.c: proc_get_status() uses waitpid(WNOHANG|WUNTRACED);
     * the exit code of a normally exited child is only valid on the first
     * such read — hence the cache-once rule. Reference (not fetched from
     * this sandbox — outbound network here is GitHub/PyPI/npm only):
     * https://www.php.net/manual/en/function.proc-get-status.php — PHP manual.
     *
     * @return array<string, mixed>|null
     */
    protected function readRawStatus()
    {
        // The ONE and ONLY proc_get_status() call site in this class.
        // PROTECTED test-only seam (ROUND7): the Unit failure-path suite
        // injects doubtful raw arrays through a tiny subclass overriding
        // THIS method only — never readStatus(). readStatus() (validation
        // and the final-status cache) always runs unchanged on top of
        // whatever this returns.
        if (!is_resource($this->process)) {
            // proc_get_status() cannot be called without a resource; this
            // synthesizes the cached decisive exit (or null) so closed
            // workers still report not-running through readStatus().
            return $this->synthesizedStatusFromCache();
        }

        return @proc_get_status($this->process);
    }

    /**
     * The cached final status re-shaped as a raw-looking status array, or
     * null before the first decisive read. Used when the raw seam cannot
     * consult proc_get_status (already closed) or when it returned false on
     * the first observation (never lost).
     *
     * @return array<string, mixed>|null
     */
    private function synthesizedStatusFromCache(): ?array
    {
        if ($this->finalStatus === null) {
            return null;
        }

        return [
            'running' => false,
            'exitcode' => $this->finalStatus['exitCode'],
            'signaled' => $this->finalStatus['signaled'],
            'stopped' => $this->finalStatus['stopped'],
        ];
    }

    protected function readStatus(): ?array
    {
        if ($this->closed) {
            return null;
        }
        $status = $this->readRawStatus();
        if (!is_array($status)) {
            return $this->synthesizedStatusFromCache();
        }
        if (($status['signaled'] ?? false) === true) {
            $this->signaledObserved = true;
        }
        if (($status['stopped'] ?? false) === true) {
            $this->stoppedObserved = true;
        }
        if (!array_key_exists('running', $status) || !is_bool($status['running'])) {
            // B5: a missing or non-bool 'running' key is DOUBT — it is never
            // treated as exit. No cache write; callers see doubt.
            return $status;
        }
        if ($status['running'] === false && $this->finalStatus === null) {
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
            $chunk = stream_get_contents($this->pipes[$which]);
            if ($chunk === false) {
                // A failed read must not silently pass for an empty one:
                // the drain's completeness accounting gets a sticky signal.
                if ($which === 1) {
                    $this->stdoutReadFailed = true;
                }
                continue;
            }
            if ($which === 1 && !$this->stdoutEofObserved && feof($this->pipes[1])) {
                // Once the read end hits EOF no further bytes can ever
                // arrive on this pipe (all writers — direct child and any
                // descendant inheriting it — are closed). Sticky.
                $this->stdoutEofObserved = true;
            }
            $chunk = (string) $chunk;
            if ($chunk === '') {
                continue;
            }
            if ($which === 1) {
                $this->stdoutBuffer .= $chunk;
                if (strlen($this->stdoutBuffer) > 1048576) {
                    $this->stdoutBuffer = substr($this->stdoutBuffer, -1048576);
                    $this->outputTruncated = true;
                }
            } else {
                $this->diagnostics .= $chunk;
                if (strlen($this->diagnostics) > 4000) {
                    $this->diagnostics = substr($this->diagnostics, -4000);
                }
            }
        }
    }



    /** True when any stdout stream_get_contents() call returned false (sticky). */
    public function outputReadFailed(): bool
    {
        return $this->stdoutReadFailed;
    }

    /** True when the stdout read end was observed at EOF (all writers closed). Internal poll helper. */
    private function stdoutEofWasSeen(): bool
    {
        return $this->stdoutEofObserved;
    }

    /** Live EOF probe: feof() returns true only after an actual read hit end-of-file. */
    private function stdoutReachedEof(): bool
    {
        if (!isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
            return $this->stdoutEofObserved; // pipes gone: only the sticky observation counts
        }

        return feof($this->pipes[1]);
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
        if (!is_resource($this->process)) {
            // Test-only injected workers may hold no process at all; there is
            // nothing to signal and nothing to wait for.
            $this->closed = true;
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
                if (is_array($status) && is_bool($status['running'] ?? null) && $status['running'] === false) {
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
                    if (is_array($status) && is_bool($status['running'] ?? null) && $status['running'] === false) {
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

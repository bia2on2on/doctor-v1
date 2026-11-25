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
        $status = @proc_get_status($this->process);
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
        $status = $this->refreshStatus();

        return !is_array($status) || ($status['running'] ?? true) === true;
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
            $status = $this->refreshStatus();
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
            $status = $this->refreshStatus();
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
     * @return array<string, mixed>|null
     */
    private function refreshStatus(): ?array
    {
        if ($this->closed || !is_resource($this->process)) {
            return null;
        }
        $status = @proc_get_status($this->process);
        if (!is_array($status)) {
            return $this->finalStatus !== null
                ? ['running' => false, 'exitcode' => $this->finalStatus['exitCode']]
                : null;
        }
        if (($status['running'] ?? false) === false && $this->finalStatus === null) {
            // The one and only decisive read: cache it and never re-read.
            $this->finalStatus = [
                'exitCode' => (int) ($status['exitcode'] ?? -1),
                'signaled' => (bool) ($status['signaled'] ?? false),
                'stopped' => (bool) ($status['stopped'] ?? false),
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
     * Bounded termination: SIGTERM then SIGKILL after a short grace.
     */
    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }
        $this->pump();
        $status = $this->refreshStatus();
        if (is_array($status) && ($status['running'] ?? false) === true) {
            @proc_terminate($this->process);
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline && is_resource($this->process)) {
                $status = $this->refreshStatus();
                if (!is_array($status) || ($status['running'] ?? false) !== true) {
                    break;
                }
                usleep(10000);
            }
            $status = $this->refreshStatus();
            if (is_array($status) && ($status['running'] ?? false) === true) {
                @proc_terminate($this->process, 9);
            }
        }
        $this->close();
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            @proc_close($this->process); // reap; no zombie survives the suite
        }
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
            return $pinned; // /proc-less host: nothing can be enumerated, so nothing is pinned
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

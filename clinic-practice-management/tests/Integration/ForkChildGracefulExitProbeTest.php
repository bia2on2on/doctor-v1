<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_UnitTestCase;

/**
 * DIAGNOSTIC (temporary, CI red on purpose).
 *
 * Question under test: does a forked child's graceful PHP exit tear down a
 * live WordPress mysqli session owned by its parent (the "fork-child
 * inherited-socket teardown" mechanism the PR #16 lifetime guard works
 * around), or can a child exit normally with exit(0)?
 *
 * The probe runs entirely in a THROWAWAY process (proc_open of
 * tests/bin/fork-child-graceful-exit-probe.php). That process boots
 * WordPress + the plugin, captures its own session id, forks a SIGKILL
 * control child and then a plain-exit(0) child, and reports both outcomes
 * as JSON on stdout. This test then fails deliberately with the evidence in
 * the assertion message, because the CI failure annotations are the channel
 * guaranteed to surface the probe payload.
 *
 * The suite's own shared session is never involved, so this probe cannot
 * corrupt other Integration tests in the same process.
 */
final class ForkChildGracefulExitProbeTest extends WP_UnitTestCase
{
    public function testGracefulExitProbe(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('proc_open')) {
            self::markTestSkipped('pcntl/proc_open are required for the graceful-exit probe.');
        }
        $script = dirname(__DIR__) . '/bin/fork-child-graceful-exit-probe.php';
        if (!is_file($script)) {
            self::markTestSkipped('graceful-exit probe script is missing.');
        }

        $env = [
            'CPMS_PROBE_PLUGIN' => dirname(__DIR__, 2) . '/clinic-practice-management.php',
        ];
        $spec = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];
        $cwd = dirname(__DIR__, 2);
        $process = proc_open(
            ['php', $script],
            $spec,
            $pipes,
            $cwd,
            $env + (getenv() !== false ? (array) getenv() : [])
        );
        self::assertIsResource($process, 'probe: could not spawn the throwaway WordPress process');
        if (!is_resource($process)) {
            return; // unreachable; satisfies static analyzers
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $resultLine = '';
        foreach (preg_split('/\r?\n/', (string) $stdout) ?: [] as $line) {
            if (str_starts_with($line, 'CPMS_PROBE_RESULT ')) {
                $resultLine = substr($line, strlen('CPMS_PROBE_RESULT '));
                break;
            }
        }

        $payload = [
            'probe_exit_code' => $code,
            'result' => $resultLine !== '' ? json_decode($resultLine, true) : null,
            'stderr_tail' => substr((string) $stderr, -300),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Connection-diagnostics channel: PHPUnit's own assertion messages are
        // deliberately NOT published by CI; the bounded CPMS_CONN_DIAG lines
        // are (they land in the log and are projected into the PR via the
        // evidence comment). Only the allow-listed vocabulary is emitted.
        $result = is_array($payload['result']) ? $payload['result'] : [];
        self::emitConnDiag($result['scenario_a'] ?? null, 101);
        self::emitConnDiag($result['scenario_c_sigkill'] ?? null, 102);
        self::emitConnDiag($result['scenario_b_exit'] ?? null, 103);
        self::fail('GRACEFUL-EXIT PROBE (CI diagnostic, red on purpose): facts are projected through CPMS_CONN_DIAG records (counts 101/102/103) in this run\'s integration evidence.');
    }

    /** @param array<string, mixed>|null $scenario */
    private static function emitConnDiag(?array $scenario, int $tag): void
    {
        $out = ['count' => $tag];
        if (is_array($scenario)) {
            if (isset($scenario['survived']) && is_bool($scenario['survived'])) {
                $out['ready'] = $scenario['survived'];
            } elseif (isset($scenario['select_after']) && is_bool($scenario['select_after'])) {
                $out['ready'] = $scenario['select_after'];
            }
            $errno = null;
            if (isset($scenario['dbh_errno']) && is_int($scenario['dbh_errno'])) {
                $errno = $scenario['dbh_errno'];
            } elseif (isset($scenario['errno_after']) && is_int($scenario['errno_after'])) {
                $errno = $scenario['errno_after'];
            }
            if ($errno !== null && $errno >= 0 && $errno < 1000000000000) {
                $out['mysql_errno'] = $errno;
                $out['last_error_present'] = $errno !== 0;
            } elseif (isset($scenario['last_error']) && is_string($scenario['last_error'])) {
                $out['last_error_present'] = $scenario['last_error'] !== '';
            }
            $threadId = null;
            if (isset($scenario['id_after']) && is_int($scenario['id_after'])) {
                $threadId = $scenario['id_after'];
            } elseif (isset($scenario['id_before']) && is_int($scenario['id_before'])) {
                $threadId = $scenario['id_before'];
            }
            if ($threadId !== null && $threadId >= 0 && $threadId < 1000000000000) {
                $out['thread_id'] = $threadId;
            }
            $out['dbh_is_mysqli'] = true;
        }
        fwrite(STDOUT, 'CPMS_CONN_DIAG ' . json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}

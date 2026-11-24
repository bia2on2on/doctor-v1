<?php
/**
 * DIAGNOSTIC PROBE (temporary) — measures what a forked child's graceful
 * PHP exit does to a live WordPress mysqli session in the PARENT process.
 *
 * This script is launched by ForkChildGracefulExitProbeTest via proc_open().
 * It boots WordPress + the CPMS plugin exactly the way the Integration
 * bootstrap does, then forks TWO children:
 *
 *   Scenario 1 (control): the child ends via SIGKILL (the PR #16 lifetime
 *     guard shape — expected to leave the parent session untouched).
 *   Scenario 2:           the child ends via plain exit(0) with the full
 *     WordPress shutdown path running (pre-#16 behavior).
 *
 * After each child is reaped, this process queries its own harness session
 * and reports the outcome as one `CPMS_PROBE_RESULT {json}` line on stdout.
 * The probe never touches the suite's shared session: this whole process is
 * throwaway, and it creates and checks its own session only.
 *
 * Diagnostic content only. Removed from the branch once the mechanism is
 * proven; never asserted on as a regression pin.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || $testsDir === '') {
    $testsDir = '/tmp/wordpress-test-lib';
}

$plugin = getenv('CPMS_PROBE_PLUGIN');
if (!is_string($plugin) || $plugin === '' || !is_file($plugin)) {
    $plugin = $root . '/clinic-practice-management.php';
}

require_once $testsDir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function () use ($plugin): void {
    require $plugin;
});
require_once $testsDir . '/includes/bootstrap.php';

$out = ['php' => PHP_VERSION];
$out['mysqlnd_version'] = function_exists('mysqlnd_get_client_version') ? (int) mysqlnd_get_client_version() : 0;

// Enumerate the callbacks registered on the WordPress `shutdown` action at
// this point (metadata only — names, never data). Whatever is listed here is
// what will run inside each forked child when it exits, before object
// destruction.
$ids = [];
$filters = isset($GLOBALS['wp_filter']['shutdown']) ? $GLOBALS['wp_filter']['shutdown'] : null;
if (is_object($filters) && isset($filters->callbacks) && is_array($filters->callbacks)) {
    foreach ($filters->callbacks as $priority => $callbacks) {
        foreach ((array) $callbacks as $cb) {
            if (is_string($cb)) {
                $ids[] = (string) $priority . ':' . $cb;
                continue;
            }
            if (is_array($cb) && count($cb) === 2) {
                [$target, $method] = $cb;
                if (is_object($target)) {
                    $target = get_class($target);
                }
                if (is_string($target) && is_string($method)) {
                    $ids[] = (string) $priority . ':' . $target . '::' . $method;
                    continue;
                }
            }
            if ($cb instanceof Closure) {
                $ids[] = (string) $priority . ':Closure';
                continue;
            }
            if (is_object($cb)) {
                $ids[] = (string) $priority . ':' . get_class($cb) . '::__invoke';
                continue;
            }
            $ids[] = (string) $priority . ':unknown';
        }
    }
}
sort($ids);
$out['shutdown_callbacks'] = $ids;

$s0 = (int) App::db()->wpdb()->get_var('SELECT CONNECTION_ID()');
$out['session_before'] = $s0;

// Scenario 1 — SIGKILL control (the lifetime-guard shape; expected: survives)
$pid = pcntl_fork();
if ($pid === 0) {
    posix_kill((int) getmypid(), SIGKILL);
    exit(127); // unreachable when the kill lands
}
pcntl_waitpid($pid, $status1);
$out['sigkill_control'] = [
    'wifsignaled' => (bool) pcntl_wifsignaled($status1),
    'wtermsig' => pcntl_wifsignaled($status1) ? (int) pcntl_wtermsig($status1) : null,
];
try {
    App::db()->wpdb()->last_error = '';
} catch (Throwable $ignored) {
    // wpdb property reset is best-effort; the measurement is the CONNECTION_ID query below.
}
$mid = App::db()->wpdb()->get_var('SELECT CONNECTION_ID()');
$out['session_after_sigkill'] = [
    'id' => ($mid === false || $mid === null) ? 0 : (int) $mid,
    'last_error' => (string) App::db()->wpdb()->last_error,
];
$out['sigkill_session_survived'] = ((int) $mid === $s0 && $s0 > 0);

// Scenario 2 — plain exit(0) with the full shutdown path (expected by the
// fork-child lifetime guard design to tear the shared session down)
$pid = pcntl_fork();
if ($pid === 0) {
    exit(0);
}
pcntl_waitpid($pid, $status2);
$out['plain_exit_control'] = [
    'wifexited' => (bool) pcntl_wifexited($status2),
    'wexitstatus' => pcntl_wifexited($status2) ? (int) pcntl_wexitstatus($status2) : null,
    'wifsignaled' => (bool) pcntl_wifsignaled($status2),
    'wtermsig' => pcntl_wifsignaled($status2) ? (int) pcntl_wtermsig($status2) : null,
];
try {
    App::db()->wpdb()->last_error = '';
} catch (Throwable $ignored) {
    // best-effort, as above
}
$afterRaw = App::db()->wpdb()->get_var('SELECT CONNECTION_ID()');
$out['session_after_exit'] = [
    'id' => ($afterRaw === false || $afterRaw === null) ? 0 : (int) $afterRaw,
    'last_error' => (string) App::db()->wpdb()->last_error,
];
$out['exit_session_survived'] = ((int) $afterRaw === $s0 && $s0 > 0);

echo 'CPMS_PROBE_RESULT ' . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);

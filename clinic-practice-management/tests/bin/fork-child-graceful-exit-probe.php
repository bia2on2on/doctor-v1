<?php
/**
 * DIAGNOSTIC PROBE v2 (temporary) — non-destructive.
 *
 * Measures what a forked child's graceful PHP exit does to a live mysqli
 * session owned by the forking process. Two scenarios, both executed in a
 * THROWAWAY process (ForkChildGracefulExitProbeTest spawns this script via
 * proc_open). No WordPress test bootstrap is ever loaded, so install.php is
 * never executed and the shared test database is never reinstalled.
 *
 *   Scenario A: no WordPress at all — a bare `new \mysqli()` connection to
 *     the test database. The child is a pcntl_fork() of this process and
 *     ends with plain exit(0). The parent then queries the inherited
 *     connection. This isolates the mysqlnd implicit-destruction behavior.
 *   Scenario B: WordPress loaded through wp-settings.php the way a real
 *     installation loads it (wp-tests-config.php + wp-settings.php; NEVER
 *     the WordPress test bootstrap). The child ends with plain exit(0),
 *     running WP's shutdown path. The parent then queries its own
 *     `$wpdb` session.
 *   Scenario C: control — SIGKILL child, WordPress loaded, parent session
 *     expected to survive (the PR #16 lifetime-guard shape).
 *
 * Output: one `CPMS_PROBE_RESULT {json}` line on stdout. Diagnostic content
 * only; the probe test fails with the payload so the CI annotations carry it.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || $testsDir === '') {
    $testsDir = '/tmp/wordpress-test-lib';
}
$configPath = $testsDir . '/wp-tests-config.php';
if (!is_file($configPath)) {
    echo 'CPMS_PROBE_RESULT ' . json_encode(['fatal' => 'config_not_found', 'tests_dir' => $testsDir]) . "\n";
    exit(1);
}

// The config defines ABSPATH, DB_NAME, DB_USER, DB_PASSWORD, DB_HOST,
// WP_TESTS_TITLE, WP_PHP_BINARY and $table_prefix. It never loads WP core.
require $configPath;

$out = ['php' => PHP_VERSION];
$out['mysqlnd_version'] = function_exists('mysqlnd_get_client_version') ? (int) mysqlnd_get_client_version() : 0;
$out['db_host_class'] = (defined('DB_HOST') && is_string(DB_HOST)) ? preg_replace('/:\d+$/', ':***', DB_HOST) : 'undefined';

// ---------------------------------------------------------------- scenario A
$host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
$port = 3306;
if (preg_match('/^([^:]+):(\d+)$/', $host, $m) === 1) {
    $host = $m[1];
    $port = (int) $m[2];
}
$d = @new \mysqli($host, defined('DB_USER') ? (string) DB_USER : 'root', defined('DB_PASSWORD') ? (string) DB_PASSWORD : 'root', defined('DB_NAME') ? (string) DB_NAME : 'wordpress_test', $port);
if ($d->connect_errno !== 0) {
    $out['scenario_a'] = ['fatal' => 'connect_failed', 'errno' => (int) $d->connect_errno];
    echo 'CPMS_PROBE_RESULT ' . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}
$aIdResult = $d->query('SELECT CONNECTION_ID()');
$aBefore = 0;
if ($aIdResult instanceof \mysqli_result) {
    $aRow = $aIdResult->fetch_row();
    $aBefore = (int) ($aRow[0] ?? 0);
    $aIdResult->free();
}
$pidA = pcntl_fork();
if ($pidA === 0) {
    exit(0); // the plain-exit child: inherits $d, no WP anywhere
}
pcntl_waitpid($pidA, $statusA);
$aAfter = @$d->query('SELECT 1');
$out['scenario_a'] = [
    'id_before' => $aBefore,
    'wifexited' => (bool) pcntl_wifexited($statusA),
    'wexitstatus' => pcntl_wifexited($statusA) ? (int) pcntl_wexitstatus($statusA) : null,
    'wifsignaled' => (bool) pcntl_wifsignaled($statusA),
    'select_after' => $aAfter !== false,
    'errno_after' => (int) $d->errno,
];
@$d->close();

// ------------------------------------------------- scenario C (SIGKILL ctl)
try {
    $core = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '/tmp/wordpress';
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    ob_start();
    require_once $core . '/wp-settings.php';
    ob_end_clean();
} catch (Throwable $bootstrap) {
    $out['scenario_bc'] = ['fatal' => 'wp_bootstrap_failed', 'class' => get_class($bootstrap)];
    echo 'CPMS_PROBE_RESULT ' . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

$s0 = (int) $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()');

// safe shutdown-callback inventory (names only, never values)
$ids = [];
$filters = isset($GLOBALS['wp_filter']['shutdown']) ? $GLOBALS['wp_filter']['shutdown'] : null;
if (is_object($filters) && isset($filters->callbacks) && is_array($filters->callbacks)) {
    foreach ($filters->callbacks as $priority => $callbacks) {
        foreach ((array) $callbacks as $cb) {
            if (is_string($cb)) {
                $ids[] = (string) $priority . ':' . $cb;
            } elseif (is_array($cb) && count($cb) === 2) {
                [$target, $method] = $cb;
                $target = is_object($target) ? get_class($target) : (is_string($target) ? $target : '?');
                $ids[] = (string) $priority . ':' . $target . '::' . (is_string($method) ? $method : '?');
            } elseif (is_object($cb)) {
                $ids[] = (string) $priority . ':' . get_class($cb);
            }
        }
    }
}
sort($ids);
$out['shutdown_callbacks'] = $ids;
$out['session_before'] = $s0;

$pidC = pcntl_fork();
if ($pidC === 0) {
    posix_kill((int) getmypid(), SIGKILL);
    exit(127); // unreachable when the kill lands
}
pcntl_waitpid($pidC, $statusC);
$midRaw = $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()');
$dbhC = $GLOBALS['wpdb']->dbh ?? null;
$out['scenario_c_sigkill'] = [
    'wifsignaled' => (bool) pcntl_wifsignaled($statusC),
    'wtermsig' => pcntl_wifsignaled($statusC) ? (int) pcntl_wtermsig($statusC) : null,
    'id_after' => ($midRaw === false || $midRaw === null) ? 0 : (int) $midRaw,
    'last_error' => (string) $GLOBALS['wpdb']->last_error,
    'survived' => ((int) $midRaw === $s0 && $s0 > 0),
    'dbh_errno' => $dbhC instanceof \mysqli ? (int) mysqli_errno($dbhC) : -1,
    's0' => $s0,
];

// ----------------------------------------------------- scenario B (exit 0)
$pidB = pcntl_fork();
if ($pidB === 0) {
    exit(0);
}
pcntl_waitpid($pidB, $statusB);
$bRaw = $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()');
$dbhB = $GLOBALS['wpdb']->dbh ?? null;
$out['scenario_b_exit'] = [
    'wifexited' => (bool) pcntl_wifexited($statusB),
    'wexitstatus' => pcntl_wifexited($statusB) ? (int) pcntl_wexitstatus($statusB) : null,
    'wifsignaled' => (bool) pcntl_wifsignaled($statusB),
    'id_after' => ($bRaw === false || $bRaw === null) ? 0 : (int) $bRaw,
    'last_error' => (string) $GLOBALS['wpdb']->last_error,
    'survived' => ((int) $bRaw === $s0 && $s0 > 0),
    'dbh_errno' => $dbhB instanceof \mysqli ? (int) mysqli_errno($dbhB) : -1,
    's0' => $s0,
];

echo 'CPMS_PROBE_RESULT ' . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);

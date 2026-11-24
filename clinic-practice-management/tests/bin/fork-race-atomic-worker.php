<?php

declare(strict_types=1);

/**
 * External fork-race worker — plain-MySQL modes (no WordPress framework).
 *
 * Launched via tests/Support/ExternalRaceWorker (proc_open): this process
 * starts with stdio pipes and /dev/null-pinned descriptors only, so it owns
 * no descriptor from the test runner. Every mode opens its OWN mysqli
 * connection to the (already COMMITTED) fixture data and reports by
 * writing ONE byte ('0'|'1' won|lost convention per mode) into the
 * outcome file the launcher passed in CPMS_RACE_PAYLOAD.
 *
 * NEVER loads the WordPress test bootstrap (install.php drops and
 * recreates every core table). DB constants and $table_prefix come from
 * the same wp-tests-config.php the CI job installs.
 *
 * Modes:
 *   conditional_update         — one prepared UPDATE (bind 'si', now + id);
 *                               writes '0' when affected_rows === 1 (won), else '1'.
 *   audit_append_replicated    — replicate of the inline AuditLogger algorithm
 *                               (guard FOR UPDATE → tail FOR UPDATE → canonical
 *                               hash → insert → guard bump); writes '0' on commit.
 *   (atomic_book lives in fork-race-wp-worker.php — it needs \wpdb.)
 *
 * Exit contract: 0 = worker did its job and wrote the outcome file
 * (whatever the race outcome); 10 = harness/environment fatal (missing
 * config/autoloader, connect failure, task rejected, unhandled error) —
 * the parent's strict normal-exit assertion fails loudly on 10.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || $testsDir === '') {
    $testsDir = '/tmp/wordpress-test-lib';
}
$configPath = getenv('WP_TESTS_CONFIG_FILE_PATH');
if (!is_string($configPath) || $configPath === '' || !is_file($configPath)) {
    $configPath = $testsDir . '/wp-tests-config.php';
}
if (!is_file($configPath)) {
    exit(10); // CONFIG_NOT_FOUND — exit code is a sanitized signal, never a message
}

require $configPath; // DB_NAME / DB_USER / DB_PASSWORD / DB_HOST / ABSPATH / $table_prefix

$raw = getenv('CPMS_RACE_PAYLOAD');
$task = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($task)) {
    exit(10); // TASK_UNREADABLE
}
$mode = isset($task['mode']) && is_string($task['mode']) ? $task['mode'] : '';
$outcome = isset($task['outcome']) && is_string($task['outcome']) ? $task['outcome'] : '';
$prefix = isset($table_prefix) && is_string($table_prefix) && $table_prefix !== '' ? $table_prefix : 'wptests_';

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    exit(10); // AUTOLOADER_MISSING
}
require_once $autoload;

$host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
$port = 3306;
if (preg_match('/^([^:]+):(\d+)$/', $host, $m) === 1) {
    $host = $m[1];
    $port = (int) $m[2];
}
$dbName = defined('DB_NAME') ? (string) DB_NAME : 'wordpress_test';
$dbUser = defined('DB_USER') ? (string) DB_USER : 'root';
$dbPass = defined('DB_PASSWORD') ? (string) DB_PASSWORD : 'root';

$mysqli = @new \mysqli($host, $dbUser, $dbPass, $dbName, $port);
if ($mysqli->connect_errno !== 0) {
    exit(10); // CONNECT_FAILED
}
$mysqli->set_charset('utf8mb4');

$exit = 10;

try {
    if ($mode === 'conditional_update') {
        $sqlB64 = isset($task['sql_b64']) && is_string($task['sql_b64']) ? $task['sql_b64'] : '';
        $id = isset($task['id']) && is_int($task['id']) ? $task['id'] : -1;
        $sql = base64_decode($sqlB64, true);
        if (!is_string($sql) || stripos(trim($sql), 'UPDATE ') !== 0 || $id <= 0) {
            throw new \RuntimeException('task rejected'); // outcome absent -> parent reports harness fatal
        }
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('prepare failed');
        }
        $now = gmdate('Y-m-d H:i:s') . '.000';
        $stmt->bind_param('si', $now, $id);
        $stmt->execute();
        $won = $stmt->affected_rows === 1;
        $stmt->close();
        if ($outcome !== '') {
            @file_put_contents($outcome, $won ? '0' : '1');
        }
        $exit = 0;
    } elseif ($mode === 'audit_append_replicated') {
        $workerIndex = isset($task['worker_index']) && is_int($task['worker_index']) ? $task['worker_index'] : -1;
        $clinicId = isset($task['clinic_id']) && is_int($task['clinic_id']) ? $task['clinic_id'] : -1;
        if ($workerIndex < 0 || $clinicId <= 0) {
            throw new \RuntimeException('task rejected');
        }
        $auditLogs = $prefix . 'cpms_audit_logs';
        $auditSeq = $prefix . 'cpms_audit_seq';
        $mysqli->begin_transaction();
        $guard = $mysqli->query('SELECT id FROM ' . $auditSeq . ' WHERE id = 1 FOR UPDATE');
        if ($guard === false || $guard->num_rows !== 1) {
            throw new \RuntimeException('guard lock failed');
        }
        $guard->free();
        $tail = $mysqli->query('SELECT row_hash FROM ' . $auditLogs . ' ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $prevHash = \ClinicCore\Infrastructure\Audit\HashChain::GENESIS;
        if ($tail !== false && $tail->num_rows === 1) {
            $row = $tail->fetch_assoc();
            $prevHash = (string) ($row['row_hash'] ?? $prevHash);
        }
        if ($tail instanceof \mysqli_result) {
            $tail->free();
        }
        $resourceId = $workerIndex + 1;
        $now = gmdate('Y-m-d H:i:s') . '.000';
        $requestId = 'test-req-' . getmypid() . '-' . $workerIndex;
        $fields = [
            'action' => 'CONCURRENT_TEST_' . $workerIndex,
            'actor_role' => 'system',
            'actor_wp_user_id' => null,
            'clinic_id' => $clinicId,
            'created_at' => $now,
            'ip_hash' => null,
            'patient_id' => null,
            'request_id' => $requestId,
            'resource_id' => $resourceId,
            'resource_type' => 'test',
            'session_id' => null,
        ];
        ksort($fields);
        $canonical = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $rowHash = hash('sha256', $prevHash . $canonical);
        $action = 'CONCURRENT_TEST_' . $workerIndex;
        $sql = sprintf(
            "INSERT INTO %s (clinic_id, actor_wp_user_id, actor_role, action, resource_type, resource_id, patient_id, ip_hash, session_id, request_id, prev_hash, row_hash, created_at) VALUES (%d, NULL, '%s', '%s', '%s', %d, NULL, NULL, NULL, '%s', '%s', '%s', '%s')",
            $auditLogs,
            $clinicId,
            $mysqli->real_escape_string('system'),
            $mysqli->real_escape_string($action),
            $mysqli->real_escape_string('test'),
            $resourceId,
            $mysqli->real_escape_string($requestId),
            $mysqli->real_escape_string($prevHash),
            $mysqli->real_escape_string($rowHash),
            $mysqli->real_escape_string($now)
        );
        if ($mysqli->query($sql) === false) {
            throw new \RuntimeException('insert failed');
        }
        $auditId = (int) $mysqli->insert_id;
        $mysqli->query(sprintf(
            "UPDATE %s SET last_audit_id = %d, updated_at = '%s' WHERE id = 1",
            $auditSeq,
            $auditId,
            $mysqli->real_escape_string($now)
        ));
        $mysqli->commit();
        if ($outcome !== '') {
            @file_put_contents($outcome, '0');
        }
        $exit = 0;
    } else {
        throw new \RuntimeException('unknown mode');
    }
} catch (\Throwable) {
    try {
        $mysqli->rollback();
    } catch (\Throwable) {
        // diagnostic noise, never a pass signal
    }
    if ($outcome !== '') {
        @file_put_contents($outcome, '2'); // distinct from the won/lost bytes and from absence
    }
    $exit = 10;
}
@$mysqli->close();
exit($exit);

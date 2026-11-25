<?php

declare(strict_types=1);

/**
 * External WP race worker — launched via tests/Support/ExternalRaceWorker.
 *
 * Single-mode scope cut (PR #27 ROUND3-RESCOPE, 2026-11-25): this worker
 * exists ONLY for CustomRoleLifecycleTest's external `custom_role_assignment`
 * race (the one converted suite ratified in review). Every other conversion
 * mode is deliberately NOT part of this PR.
 *
 * Boots WordPress through wp-tests-config.php + wp-settings.php the way a
 * real installation loads it (NEVER the WordPress test bootstrap, which
 * shells out to install.php and drops every core table), then runs the
 * assignment-attempt protocol on its own \wpdb session (set_prefix($table_prefix)).
 * The launcher pins every descriptor it holds to /dev/null in this child,
 * so this process owns nothing from the test runner.
 *
 * Task arrives in CPMS_RACE_PAYLOAD (JSON). Non-secret ids only.
 *
 * Exit contract: 0 = assignment attempt completed and protocol emitted as
 * specified; 10 = harness/environment fatal. A fatal never masquerades as
 * a race outcome.
 *
 * Mode:
 *   custom_role_assignment {manager_id, clinic_id, target_user_id, race_role_key,
 *                           definition_id, await_release, lock_wait_sec,
 *                           race_role_fp_select}                  (protocol on stdio)
 *
 * Protocol contract (custom_role_assignment):
 *   worker → stdout   one or more single-line JSON/tagged lines:
 *                     READY:<connection_id>
 *                     LOCK_QUERY:<connection_id>:<sha256>:<base64(sql)>
 *                     RESULT:<outcome> or RESULT:blocked:<errno>:<sha256>
 *                     ERROR:<class>
 *   launcher → stdin  optional command lines (\n-terminated; RELEASE when
 *                     await_release and the attempt blocks on the lock)
 *   stderr is never protocol; it is bounded diagnostics.
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
    exit(10);
}
require $configPath; // ABSPATH, DB_*, $table_prefix

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    exit(10);
}

// Load WP itself the way a real installation does (the race needs WP_Hook /
// remove_all_filters / \wpdb / product boot). No test bootstrap, no installer.
$coreDir = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
if ($coreDir === '' || !is_file($coreDir . '/wp-settings.php')) {
    $envCore = getenv('WP_CORE_DIR');
    if (is_string($envCore) && $envCore !== '') {
        $envCore = rtrim($envCore, '/');
        if (is_file($envCore . '/wp-settings.php')) {
            $coreDir = $envCore;
        }
    }
    if ($coreDir === '' || !is_file($coreDir . '/wp-settings.php')) {
        exit(10);
    }
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
try {
    ob_start();
    require_once $coreDir . '/wp-settings.php';
    require_once $autoload;
    ob_end_clean();
} catch (\Throwable) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    exit(10);
}

$raw = getenv('CPMS_RACE_PAYLOAD');
$task = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($task)) {
    exit(10);
}
$mode = isset($task['mode']) && is_string($task['mode']) ? $task['mode'] : '';
$prefix = isset($table_prefix) && is_string($table_prefix) && $table_prefix !== '' ? $table_prefix : 'wptests_';

$own = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$own->set_prefix($prefix);
// The product stack's SAVEPOINT remark applies only to the harness session:
// this worker needs REAL commits visible to other connections.
remove_all_filters('query');
if (property_exists($own, 'has_connected')) {
    $own->has_connected = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
}
@$own->init_charset();
if (!$own->check_connection(false)) {
    exit(10);
}

function cpms_race_emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

$exit = 10;

try {
    // --------------------------------------------------------------
    // custom_role_assignment — CustomRoleLifecycleTest waiter child
    // --------------------------------------------------------------
    if ($mode === 'custom_role_assignment') {
        $managerId = (int) ($task['manager_id'] ?? 0);
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        $targetUser = (int) ($task['target_user_id'] ?? 0);
        $raceRoleKey = (string) ($task['race_role_key'] ?? '');
        $definitionId = (int) ($task['definition_id'] ?? 0);
        $awaitRelease = (bool) ($task['await_release'] ?? false);
        $lockWaitSec = (int) ($task['lock_wait_sec'] ?? 5);
        if ($managerId <= 0 || $clinicId <= 0 || $targetUser <= 0 || $definitionId <= 0 || $raceRoleKey === '') {
            cpms_race_emit('ERROR:task_rejected');
            exit(10);
        }

        $connectionId = (int) $own->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($connectionId <= 0) {
            cpms_race_emit('ERROR:no_connection');
            exit(10);
        }
        if ($own->query('SET SESSION innodb_lock_wait_timeout = ' . max(1, $lockWaitSec)) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            cpms_race_emit('ERROR:lock_wait_timeout_set_failed');
            exit(10);
        }

        $db2 = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $memberships2 = new \ClinicCore\Infrastructure\Repository\MembershipRepository($db2);
        $roles2 = new \ClinicCore\Infrastructure\Repository\CustomRoleRepository($db2);
        $authorization2 = new \ClinicCore\Application\Authorization\AuthorizationService($memberships2, $roles2);
        $assignmentService = new \ClinicCore\Application\Roles\CustomRoleAssignmentService(
            new \ClinicCore\Application\Scope\TrustedClinicEstablisher($db2, $memberships2),
            $authorization2,
            $memberships2,
            $roles2,
            new \ClinicCore\Infrastructure\Audit\AuditLogger($db2, new \ClinicCore\Infrastructure\Logging\OpLogger($db2)),
            $db2
        );
        $roleDefinitionsTable = strtolower($db2->table('cpms_custom_role_defs'));
        $lockQuerySent = false;
        $lockFingerprint = '';

        // Signal only when the production target-row SELECT ... FOR UPDATE runs.
        add_filter(
            'query',
            static function ($query) use ($connectionId, $roleDefinitionsTable, $definitionId, $clinicId, &$lockQuerySent, &$lockFingerprint) {
                $sql = strtolower(trim((string) preg_replace('/\s+/', ' ', trim((string) $query))));
                $expectedPredicate = 'where id = ' . $definitionId . ' and clinic_id = ' . $clinicId;
                if (
                    !$lockQuerySent
                    && str_contains($sql, 'from ' . $roleDefinitionsTable . ' ')
                    && str_contains($sql, $expectedPredicate)
                    && preg_match('/\bfor\s+update\s*$/', $sql) === 1
                ) {
                    $lockQuerySent = true;
                    $fingerprint = hash('sha256', $sql);
                    $lockFingerprint = $fingerprint;
                    cpms_race_emit('LOCK_QUERY:' . $connectionId . ':' . $fingerprint . ':' . base64_encode($sql));
                }

                return $query;
            },
            0
        );

        $readySent = false;
        // Strict driver reporting: a failed production statement raises
        // mysqli_sql_exception carrying the server error code.
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        $attempt = static function () use (
            $db2,
            $assignmentService,
            $managerId,
            $clinicId,
            $targetUser,
            $raceRoleKey,
            $connectionId,
            &$readySent
        ): string {
            $result = 'assigned';
            try {
                $db2->transactional(function () use (
                    $db2,
                    $assignmentService,
                    $managerId,
                    $clinicId,
                    $targetUser,
                    $raceRoleKey,
                    $connectionId,
                    &$result,
                    &$readySent
                ): void {
                    if (!$readySent) {
                        $readySent = true;
                        cpms_race_emit('READY:' . $connectionId);
                    }
                    try {
                        $assignmentService->assign($managerId, $clinicId, $targetUser, $raceRoleKey);
                        $result = 'assigned';
                    } catch (\ClinicCore\Application\Roles\CustomRoleAssignmentException $exception) {
                        $result = 'denied:' . $exception->reason_code();
                    } catch (\Throwable $exception) {
                        $driverErrno = (int) $exception->getCode();
                        $handle = $db2->wpdb()->dbh;
                        if ($driverErrno === 0 && $handle instanceof \mysqli) {
                            $driverErrno = (int) $handle->errno;
                        }
                        if ($driverErrno === 0 && stripos((string) $db2->wpdb()->last_error, 'lock wait timeout') !== false) {
                            $driverErrno = 1205;
                        }
                        $result = 'lockerr:' . $driverErrno;
                        throw $exception;
                    }

                    throw new \RuntimeException('intentional race-fixture rollback');
                });
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() === 'intentional race-fixture rollback') {
                    return $result;
                }
                if (str_starts_with($result, 'lockerr:')) {
                    // A lock-wait timeout is the evidence this attempt exists
                    // to produce on MariaDB; report it, don't fatal.
                    return $result;
                }
                throw $exception;
            }

            return $result;
        };

        $outcome = $attempt();

        // MariaDB cannot surface this forked, still-blocked waiter through
        // INNODB_TRX/INNODB_LOCK_WAITS (measured runs 39041714039/39045431008).
        // The authoritative evidence is the production statement's own
        // ER_LOCK_WAIT_TIMEOUT, naming the exact statement by SHA-256. B waits
        // for A's explicit RELEASE before retrying.
        if ($awaitRelease && $lockQuerySent && str_starts_with($outcome, 'lockerr:')) {
            cpms_race_emit('RESULT:blocked:' . (int) substr($outcome, strlen('lockerr:')) . ':' . $lockFingerprint);
            stream_set_timeout(STDIN, 5);
            $released = false;
            $releaseDeadline = microtime(true) + 90;
            while (microtime(true) < $releaseDeadline) {
                $releaseLine = fgets(STDIN);
                if (is_string($releaseLine) && trim($releaseLine) === 'RELEASE') {
                    $released = true;
                    break;
                }
                $meta = stream_get_meta_data(STDIN);
                if (($meta['timed_out'] ?? false) === true) {
                    continue; // bounded by the absolute deadline
                }
                if (feof(STDIN)) {
                    break;
                }
            }
            if (!$released) {
                throw new \RuntimeException('parent never released the worker for the post-commit retry');
            }
            $outcome = $attempt();
        }

        if (!$lockQuerySent) {
            $outcome = 'missing-target-lock-query:' . $outcome;
        }
        cpms_race_emit('RESULT:' . $outcome);
        $exit = 0;
    } else {
        throw new \RuntimeException('unknown mode');
    }
} catch (\Throwable $e) {
    // custom_role_assignment is a stdio-protocol mode: the failure belongs on
    // the protocol channel, exactly like the pre-scope-cut worker emitted it.
    cpms_race_emit('ERROR:' . get_class($e));
    $exit = 10;
}
@$own->close();
exit($exit);

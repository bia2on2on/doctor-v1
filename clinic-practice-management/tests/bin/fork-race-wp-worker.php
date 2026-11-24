<?php

declare(strict_types=1);

/**
 * External WP race worker — launched via tests/Support/ExternalRaceWorker.
 *
 * Boots WordPress through wp-tests-config.php + wp-settings.php the way a
 * real installation loads it (NEVER the WordPress test bootstrap, which
 * shells out to install.php and drops every core table), then runs ONE
 * product call or protocol on its own \wpdb session (set_prefix($table_prefix)).
 * The launcher pins every descriptor it holds to /dev/null in this child,
 * so this process owns nothing from the test runner.
 *
 * Task arrives in CPMS_RACE_PAYLOAD (JSON). Non-secret ids/paths only.
 *
 * Exit contract: 0 = work done (outcome written or protocol emitted as
 * specified); 10 = harness/environment fatal. A fatal never masquerades as
 * a race outcome.
 *
 * Modes:
 *   atomic_book            {slot_id, outcome}
 *   schedule_worker        {clinic_id, professional_user_id, clinician_id, location_id,
 *                           fire_at, spec{kind,day?,start,end,id?}, outcome}
 *   active_visit_race      {role, offset, fire_at, appointment_id, patient_id,
 *                           patient_user_id, secretary_user_id, clinician_id,
 *                           clinic_id, dest_date?, dest_time?, dest_slot_id?,
 *                           idem_key?, outcome}
 *   noshow_race            {role, offset, barrier_file, child_deadline_sec, parent_probe,
 *                           appointment_id, patient_id, clinic_a, secretary_a,
 *                           ready_file, outcome, visit_now_anchor|null}
 *   location_deactivation  {kind, clinic_id, manager_user_id, secretary_user_id, patient_id,
 *                           clinician_id, target_location_id, slot_date,
 *                           slots[{time,id}…], ready_file, go_file, outcome_file}
 *   timezone_writer        {location_id, lock_wait_timeout_sec}   (protocol on stdio)
 *   staff_booking_actor    {clinic_id, staff_user_id, patient_id, clinician_id,
 *                           date, time, slot_id}                  (protocol on stdio)
 *   facility_setup_step   {actor, organization_id, task_kind(clinic|room|clinician),
 *                           request_key, name, site_key?, clinic_key?, room_key?,
 *                           user?, timezone?, ready_file, go_file, outcome_file}
 *   hold_competitor       {db_name, ready_file, go_file, hold_done_file, commit_file,
 *                           outcome_file, slot_id, date, user_id, clinician_id,
 *                           organization_id, clinic_id, token, session}
 *   hold_loser            {db_name, ready_file, outcome_file, slot_id, date, user_id,
 *                           clinician_id, token, session}
 *   hold_lock_holder      {db_name, ready_file, go_file, hold_id}
 *   hold_expiry_worker    {db_name, ready_file, outcome_file}
 *   custom_role_assignment {manager_id, clinic_id, target_user_id, race_role_key,
 *                           definition_id, await_release, lock_wait_sec,
 *                           race_role_fp_select}                  (protocol on stdio)
 *   lifetime_probe         {outcome_file, marker_file}  (inverted fork-child lifetime witness)
 *
 * Protocol contract (timezone_writer / staff_booking_actor / custom_role_assignment):
 *   worker → stdout   one or more single-line JSON messages or tagged lines
 *   launcher → stdin  optional command lines (\n-terminated)
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
$outcomePath = isset($task['outcome']) && is_string($task['outcome']) ? $task['outcome']
    : (isset($task['outcome_file']) && is_string($task['outcome_file']) ? $task['outcome_file'] : '');
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

function cpms_race_write_outcome(string $path, array $outcome): bool
{
    if ($path === '') {
        return false;
    }
    return @file_put_contents($path, json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

function cpms_race_trace(\Throwable $e): array
{
    $frames = [];
    foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
        $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
            . ' @ ' . basename((string) ($frame['file'] ?? '?')) . ':' . (string) ($frame['line'] ?? '?');
    }

    return $frames;
}

function cpms_race_wait_for_file(string $path, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            return;
        }
        usleep(10000);
    }

    throw new \RuntimeException('timed out waiting for file: ' . basename($path));
}

/**
 * Dedicated connection to an ISOLATED, DISPOSABLE schema (HoldCapacityTwoConnectionTest).
 * Mirrors IsolatedSchema::newDisposableConnection(): same host/credentials, the given
 * schema as DB, the harness prefix, errors silenced. Never touches the shared test DB.
 */
function cpms_race_disposable_conn(string $dbName, string $prefix = ''): \wpdb
{
    if ($prefix === '') {
        $prefix = isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix']) ? $GLOBALS['table_prefix'] : 'wptests_';
    }
    $conn = new \wpdb(DB_USER, DB_PASSWORD, $dbName, DB_HOST);
    $conn->set_prefix($prefix);
    $conn->show_errors(false);
    if (property_exists($conn, 'has_connected')) {
        $conn->has_connected = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
    }
    @$conn->init_charset();
    if (!$conn->check_connection(false)) {
        exit(10);
    }

    return $conn;
}

function cpms_race_query_hook_census(): array
{
    $probe = [
        'wp_loaded' => defined('ABSPATH'),
        'wp_hook_class' => class_exists('WP_Hook'),
        'wp_filter_global_type' => gettype($GLOBALS['wp_filter'] ?? null),
        'wp_filter_key_count' => is_array($GLOBALS['wp_filter'] ?? null) ? count($GLOBALS['wp_filter']) : -1,
        'query_hook_present' => is_array($GLOBALS['wp_filter'] ?? null) && isset($GLOBALS['wp_filter']['query']),
        'query_callbacks' => 0,
        'has_filter_query' => has_filter('query'),
    ];
    if ($probe['query_hook_present']) {
        $hook = $GLOBALS['wp_filter']['query'];
        if (is_object($hook) && isset($hook->callbacks)) {
            $probe['query_callbacks'] = (int) array_sum(array_map('count', (array) $hook->callbacks));
        }
    }

    return $probe;
}

$exit = 10;

try {
    // --------------------------------------------------------------
    // atomic_book — product SlotRepository::atomicBook on this connection
    // --------------------------------------------------------------
    if ($mode === 'atomic_book') {
        $slotId = isset($task['slot_id']) && is_int($task['slot_id']) ? $task['slot_id'] : 0;
        if ($slotId <= 0) {
            throw new \RuntimeException('task rejected');
        }
        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $won = (new \ClinicCore\Infrastructure\Repository\SlotRepository($cpms))->atomicBook($slotId);
        if ($outcomePath !== '') {
            @file_put_contents($outcomePath, $won ? 'won' : 'lost');
        }
        $exit = 0;
    }

    // --------------------------------------------------------------
    // schedule_worker — real ScheduleService create/update, barrier-aligned
    // --------------------------------------------------------------
    // --------------------------------------------------------------
    // lifetime_probe — inverted ForkChildLifetimeTest witness: the worker
    // opens its OWN session, reports it, registers a shutdown handler and
    // ends with a graceful exit(0). Its shutdown path runs (marker file is
    // written) and is harmless: the worker inherited no descriptor of the
    // launcher, so there is no shared session to tear down.
    // --------------------------------------------------------------
    elseif ($mode === 'lifetime_probe') {
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        $markerFile = (string) ($task['marker_file'] ?? '');
        if ($outcomeFile === '' || $markerFile === '') {
            throw new \RuntimeException('task rejected');
        }
        $connectionId = (int) $own->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($connectionId <= 0) {
            throw new \RuntimeException('no connection id');
        }
        $clinicCount = (int) $own->get_var('SELECT COUNT(*) FROM ' . $prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        register_shutdown_function(static function () use ($markerFile): void {
            @file_put_contents($markerFile, 'shutdown-ran');
        });
        @file_put_contents($outcomeFile, (string) json_encode([
            'connection_id' => $connectionId,
            'clinic_count' => $clinicCount,
        ]));
        $exit = 0;
    }

    elseif ($mode === 'schedule_worker') {
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        $professionalUserId = (int) ($task['professional_user_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $locationId = (int) ($task['location_id'] ?? 0);
        $fireAt = (float) ($task['fire_at'] ?? 0.0);
        $spec = isset($task['spec']) && is_array($task['spec']) ? $task['spec'] : [];
        $kind = isset($spec['kind']) && is_string($spec['kind']) ? $spec['kind'] : '';
        if ($clinicId <= 0 || $professionalUserId <= 0 || $clinicianId <= 0 || $locationId <= 0 || $fireAt <= 0.0 || $kind === '') {
            throw new \RuntimeException('task rejected');
        }

        $own->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        \ClinicCore\Application\Scope\ScopeContext::set(
            \ClinicCore\Application\Scope\ClinicScope::forClinic($clinicId)
        );
        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($cpms);
        $service = new \ClinicCore\Application\Booking\ScheduleService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\ScheduleRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\LocationRepository($cpms),
            new \ClinicCore\Infrastructure\Queue\JobQueue($cpms, $op),
            new \ClinicCore\Infrastructure\Audit\AuditLogger($cpms, $op),
            $op
        );
        while (microtime(true) < $fireAt) {
            usleep(250);
        }
        $outcome = ['result' => 'unknown'];
        try {
            if ($kind === 'create') {
                $view = $service->create($professionalUserId, [
                    'clinician_id' => $clinicianId,
                    'location_id' => $locationId,
                    'day_of_week' => (int) ($spec['day'] ?? 0),
                    'start_time' => (string) ($spec['start'] ?? ''),
                    'end_time' => (string) ($spec['end'] ?? ''),
                ]);
                $outcome = ['result' => 'ok', 'id' => (int) ($view['id'] ?? 0), 'kind' => 'create'];
            } elseif ($kind === 'update') {
                $view = $service->update($professionalUserId, (int) ($spec['id'] ?? 0), [
                    'start_time' => (string) ($spec['start'] ?? ''),
                    'end_time' => (string) ($spec['end'] ?? ''),
                ]);
                $outcome = ['result' => 'ok', 'id' => (int) ($view['id'] ?? 0), 'kind' => 'update'];
            } else {
                $outcome = ['result' => 'fatal', 'detail' => 'unknown worker kind'];
            }
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            $outcome = [
                'result' => 'error',
                'code' => $e->errorCode, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                'http' => $e->httpStatus, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                'errors' => (array) ($e->data['errors'] ?? []),
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Undefined array key') || str_contains($msg, 'Duplicate entry')) {
                $outcome = [
                    'result' => 'defect_raw_db_collision',
                    'detail' => get_class($e) . ': ' . $msg,
                ];
            } else {
                $outcome = ['result' => 'fatal', 'detail' => get_class($e) . ': ' . $msg];
            }
        }
        cpms_race_write_outcome($outcomePath, $outcome);
        $exit = 0;
    }

    // --------------------------------------------------------------
    // active_visit_race — real Phase 7 Slice 2 check_in / cancel / reschedule
    // --------------------------------------------------------------
    elseif ($mode === 'active_visit_race') {
        $role = (string) ($task['role'] ?? '');
        $offset = (float) ($task['offset'] ?? 0.0);
        $fireAt = (float) ($task['fire_at'] ?? 0.0);
        $appointmentId = (int) ($task['appointment_id'] ?? 0);
        $patientId = (int) ($task['patient_id'] ?? 0);
        $patientUserId = (int) ($task['patient_user_id'] ?? 0);
        $secretaryUserId = (int) ($task['secretary_user_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        if (!in_array($role, ['check_in', 'cancel', 'reschedule'], true) || $appointmentId <= 0 || $clinicId <= 0 || $fireAt <= 0.0) {
            throw new \RuntimeException('task rejected');
        }

        \ClinicCore\Application\Scope\ScopeContext::set(
            \ClinicCore\Application\Scope\ClinicScope::forClinic($clinicId)
        );
        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($cpms);
        $audit = new \ClinicCore\Infrastructure\Audit\AuditLogger($cpms, $op);
        $factory = new \ClinicCore\Settings\SettingsFactory($cpms, $audit);
        $gate = new \ClinicCore\Tests\Support\ForkRaceAllowAllLicenseGate();

        while (microtime(true) < $fireAt + $offset) {
            usleep(250);
        }

        $outcome = ['role' => $role, 'result' => 'unknown'];
        try {
            if ($role === 'check_in') {
                $visits = new \ClinicCore\Application\Visits\VisitService(
                    $cpms,
                    new \ClinicCore\Infrastructure\Repository\VisitRepository($cpms),
                    new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
                    $factory,
                    $audit,
                    $gate,
                    $op,
                    null,
                    new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms)
                );
                $row = $cpms->fetchRow(
                    'SELECT slot_date FROM ' . $cpms->table('cpms_appointments') . ' WHERE id = %d',
                    [$appointmentId]
                );
                \ClinicCore\Application\Visits\VisitService::setTestNowUtc(
                    new \DateTimeImmutable((string) ($row['slot_date'] ?? '1970-01-01') . ' 00:00:00', new \DateTimeZone('Asia/Tehran'))
                );
                try {
                    $visit = $visits->checkIn($secretaryUserId, $patientId, $appointmentId);
                } finally {
                    \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
                }
                $outcome = [
                    'result' => 'ok',
                    'role' => 'check_in',
                    'visit_id' => (int) ($visit['id'] ?? 0),
                    'visit_status' => (string) ($visit['status'] ?? ''),
                ];
            } else {
                $sms = new \ClinicCore\Application\Notifications\SmsService(
                    $cpms,
                    $factory,
                    new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry(),
                    new \ClinicCore\Infrastructure\Sms\CredentialVault(),
                    $audit,
                    $op,
                    new \ClinicCore\Infrastructure\Queue\JobQueue($cpms, $op),
                    fn (): int => $clinicId
                );
                $booking = new \ClinicCore\Application\Booking\BookingService(
                    $cpms,
                    new \ClinicCore\Infrastructure\Repository\SlotRepository($cpms),
                    new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
                    new \ClinicCore\Infrastructure\Repository\PatientRepository($cpms),
                    $factory,
                    $gate,
                    $audit,
                    $op,
                    new \ClinicCore\Infrastructure\Idempotency\Idempotency($cpms),
                    $sms,
                    null,
                    new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms)
                );
                if ($role === 'cancel') {
                    $result = $booking->cancelByPatient($patientUserId, $appointmentId, 'p7s2 race cancel');
                    $outcome = ['result' => 'ok', 'role' => 'cancel', 'appointment_status' => (string) ($result['status'] ?? '')];
                } else {
                    $result = $booking->reschedule(
                        $patientUserId,
                        $appointmentId,
                        $clinicianId,
                        (string) ($task['dest_date'] ?? ''),
                        (string) ($task['dest_time'] ?? ''),
                        (string) ($task['idem_key'] ?? ''),
                        (int) ($task['dest_slot_id'] ?? 0)
                    );
                    $outcome = ['result' => 'ok', 'role' => 'reschedule', 'appointment_id' => (int) ($result['appointment_id'] ?? 0)];
                }
            }
        } catch (\ClinicCore\Domain\Visits\VisitException $e) {
            $outcome = [
                'result' => 'error',
                'role' => $role,
                'code' => $e->errorCode,
                'http' => $e->httpStatus,
                'message' => $e->getMessage(),
            ];
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            $outcome = [
                'result' => 'error',
                'role' => $role,
                'code' => $e->errorCode,
                'http' => $e->httpStatus,
                'message' => $e->getMessage(),
                'trace' => cpms_race_trace($e),
            ];
        } catch (\Throwable $e) {
            $outcome = [
                'result' => 'fatal',
                'role' => $role,
                'detail' => get_class($e) . ': ' . $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
                'trace' => cpms_race_trace($e),
            ];
        }
        cpms_race_write_outcome($outcomePath, $outcome);
        $exit = 0;
    }

    // --------------------------------------------------------------
    // noshow_race — real Phase 7 Fr55 concurrent check_in vs manual no-show
    // --------------------------------------------------------------
    elseif ($mode === 'noshow_race') {
        $role = (string) ($task['role'] ?? '');
        $offset = (float) ($task['offset'] ?? 0.0);
        $barrierFile = (string) ($task['barrier_file'] ?? '');
        $readyFile = (string) ($task['ready_file'] ?? '');
        $childDeadline = (float) ($task['child_deadline_sec'] ?? 45.0);
        $appointmentId = (int) ($task['appointment_id'] ?? 0);
        $patientId = (int) ($task['patient_id'] ?? 0);
        $clinicA = (int) ($task['clinic_a'] ?? 0);
        $secretaryA = (int) ($task['secretary_a'] ?? 0);
        $parentProbe = isset($task['parent_probe']) && is_array($task['parent_probe']) ? $task['parent_probe'] : [];
        $visitAnchor = isset($task['visit_now_anchor']) && is_numeric($task['visit_now_anchor']) ? (float) $task['visit_now_anchor'] : null;
        if (!in_array($role, ['check_in', 'no_show'], true) || $appointmentId <= 0 || $clinicA <= 0 || $barrierFile === '' || $readyFile === '') {
            throw new \RuntimeException('task rejected');
        }
        // The barrier instant comes from the barrier file (written only after every
        // worker is ready); a child that never sees it must exit by itself.
        $probeBefore = cpms_race_query_hook_census();
        \ClinicCore\Application\Scope\ScopeContext::set(
            \ClinicCore\Application\Scope\ClinicScope::forClinic($clinicA)
        );
        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($cpms);
        $audit = new \ClinicCore\Infrastructure\Audit\AuditLogger($cpms, $op);
        $factory = new \ClinicCore\Settings\SettingsFactory($cpms, $audit);
        $gate = new \ClinicCore\Tests\Support\ForkRaceAllowAllLicenseGate();
        $visits = new \ClinicCore\Application\Visits\VisitService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\VisitRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
            $factory,
            $audit,
            $gate,
            $op,
            null,
            new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms)
        );
        $providers = new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry();
        $providers->register(new \ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider($op));
        $booking = new \ClinicCore\Application\Booking\BookingService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\SlotRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\PatientRepository($cpms),
            $factory,
            $gate,
            $audit,
            $op,
            new \ClinicCore\Infrastructure\Idempotency\Idempotency($cpms),
            new \ClinicCore\Application\Notifications\SmsService(
                $cpms,
                $factory,
                $providers,
                new \ClinicCore\Infrastructure\Sms\CredentialVault(),
                $audit,
                $op,
                new \ClinicCore\Infrastructure\Queue\JobQueue($cpms, $op, new \ClinicCore\Application\Jobs\JobWake()),
                fn (): int => $clinicA
            ),
            null,
            new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms)
        );

        // Tell the launcher this worker is fully bootstrapped (services built,
        // own connection open) before the barrier is released.
        @file_put_contents($readyFile, '1');

        if ($role === 'check_in' && $visitAnchor !== null && $visitAnchor > 0.0) {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(
                \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $visitAnchor)) ?: new \DateTimeImmutable('@' . (int) $visitAnchor)
            );
        }

        $fireAt = 0.0;
        $childDeadlineAt = microtime(true) + $childDeadline;
        while (true) {
            if ($fireAt === 0.0 && @is_file($barrierFile)) {
                $rawBarrier = (string) @file_get_contents($barrierFile);
                if ($rawBarrier !== '') {
                    $fireAt = (float) $rawBarrier;
                }
            }
            if ($fireAt > 0.0 && microtime(true) >= $fireAt + $offset) {
                break;
            }
            if ($fireAt === 0.0 && microtime(true) > $childDeadlineAt) {
                cpms_race_write_outcome($outcomePath, [
                    'role' => $role,
                    'appointment_id' => $appointmentId,
                    'result' => 'fatal',
                    'detail' => 'child never saw the barrier within ' . $childDeadline . ' s',
                ]);
                exit(10);
            }
            usleep(250);
        }

        $startedAt = microtime(true);
        $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => 'not reached'];
        try {
            if ($role === 'no_show') {
                $booking->markNoShowByStaff($secretaryA, $appointmentId, 'race: manual no-show');
                $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'ok'];
            } else {
                $v = $visits->checkIn($secretaryA, $patientId, $appointmentId);
                if (is_array($v) && !isset($v['id'])) {
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'rejected',
                        'error' => 'check-in rejected: ' . (string) ($v['error_code'] ?? 'unknown') . ' — ' . (string) ($v['message'] ?? ''),
                    ];
                } else {
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'ok',
                        'visit_id' => (int) ($v['id'] ?? 0),
                        'visit_status' => (string) ($v['status'] ?? ''),
                        'visit_source' => (string) ($v['source'] ?? ''),
                    ];
                }
            }
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            $outcome = [
                'role' => $role,
                'appointment_id' => $appointmentId,
                'result' => 'rejected',
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'error' => $e->getMessage(),
            ];
        } catch (\ClinicCore\Domain\Visits\VisitException $e) {
            $outcome = [
                'role' => $role,
                'appointment_id' => $appointmentId,
                'result' => 'rejected',
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $finishedAt = microtime(true);
        $probeAfter = cpms_race_query_hook_census();

        $outcome['pid'] = getmypid();
        $outcome['own_wpdb_connected'] = true;
        $outcome['query_filters_before'] = (int) $probeBefore['query_callbacks'];
        $outcome['query_filters_after'] = (int) $probeAfter['query_callbacks'];
        $outcome['probe_before'] = $probeBefore;
        $outcome['probe_after'] = $probeAfter;
        $outcome['parent_probe'] = $parentProbe;
        $outcome['call_started_at'] = $startedAt;
        $outcome['call_finished_at'] = $finishedAt;

        cpms_race_write_outcome($outcomePath, $outcome);
        $exit = 0;
    }

    // --------------------------------------------------------------
    // location_deactivation — real deactivation vs staff booking race
    // --------------------------------------------------------------
    elseif ($mode === 'location_deactivation') {
        $kind = (string) ($task['kind'] ?? '');
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        $managerUserId = (int) ($task['manager_user_id'] ?? 0);
        $secretaryUserId = (int) ($task['secretary_user_id'] ?? 0);
        $patientId = (int) ($task['patient_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $targetLocationId = (int) ($task['target_location_id'] ?? 0);
        $slotDate = (string) ($task['slot_date'] ?? '');
        $slots = isset($task['slots']) && is_array($task['slots']) ? $task['slots'] : [];
        $readyFile = (string) ($task['ready_file'] ?? '');
        $goFile = (string) ($task['go_file'] ?? '');
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        if (
            $clinicId <= 0 || $patientId <= 0 || $clinicianId <= 0 || $slotDate === ''
            || $readyFile === '' || $goFile === '' || $outcomeFile === '' || !in_array($kind, ['deactivate', 'book_control', 'book', 'book_after_deactivation'], true)
        ) {
            throw new \RuntimeException('task rejected');
        }
        \ClinicCore\Application\Scope\ScopeContext::set(
            \ClinicCore\Application\Scope\ClinicScope::forClinic($clinicId)
        );
        // Mirror the forked child's session: READ COMMITTED (no stale snapshot
        // after the counterpart commits) and a bounded lock-wait timeout.
        $own->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $own->query('SET SESSION innodb_lock_wait_timeout = 30'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($cpms);
        $audit = new \ClinicCore\Infrastructure\Audit\AuditLogger($cpms, $op);
        $settingsFactory = new \ClinicCore\Settings\SettingsFactory($cpms, $audit);
        $licenseGate = new \ClinicCore\Domain\Licensing\ActiveLicenseGate();
        $jobs = new \ClinicCore\Infrastructure\Queue\JobQueue($cpms, $op);
        $sms = new \ClinicCore\Application\Notifications\SmsService(
            $cpms,
            $settingsFactory,
            new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry(),
            new \ClinicCore\Infrastructure\Sms\CredentialVault(),
            $audit,
            $op,
            $jobs,
            static fn (): int => $clinicId,
            $licenseGate
        );
        $memberships = new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms);
        $booking = new \ClinicCore\Application\Booking\BookingService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\SlotRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\PatientRepository($cpms),
            $settingsFactory,
            $licenseGate,
            $audit,
            $op,
            new \ClinicCore\Infrastructure\Idempotency\Idempotency($cpms),
            $sms,
            null,
            $memberships,
            \ClinicCore\Application\Patients\PatientService::default_identity_activator($cpms)
        );
        $location = new \ClinicCore\Application\Location\LocationService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\LocationRepository($cpms),
            new \ClinicCore\Application\Authorization\AuthorizationService($memberships),
            $audit
        );
        $connectionId = (int) $own->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        @file_put_contents($readyFile, json_encode(['connection_id' => $connectionId]));

        $barrierDeadline = microtime(true) + 20.0;
        while (!is_file($goFile) && microtime(true) < $barrierDeadline) {
            usleep(1000);
        }
        if (!is_file($goFile)) {
            throw new \RuntimeException('worker barrier timed out');
        }

        try {
            $slotIndex = match ($kind) {
                'book_control' => 0,
                'book' => 1,
                'book_after_deactivation' => 2,
                default => -1,
            };
            if ($kind === 'deactivate') {
                $row = $location->set_active($managerUserId, $clinicId, $targetLocationId, false);
                $outcome = ['result' => 'ok', 'is_active' => (int) ($row['is_active'] ?? -1)];
            } else {
                $slot = isset($slots[$slotIndex]) && is_array($slots[$slotIndex]) ? $slots[$slotIndex] : null;
                if ($slot === null) {
                    throw new \RuntimeException('task rejected: slot spec missing');
                }
                $bookingResult = $booking->createByStaff(
                    $secretaryUserId,
                    $patientId,
                    $clinicianId,
                    $slotDate,
                    (string) ($slot['time'] ?? ''),
                    $kind === 'book_control' ? 'active-location control' : 'location lifecycle race',
                    (int) ($slot['id'] ?? 0)
                );
                $outcome = [
                    'result' => 'ok',
                    'appointment_id' => (int) ($bookingResult['appointment_id'] ?? 0),
                ];
            }
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            $outcome = ['result' => 'error', 'code' => $e->errorCode, 'http' => $e->httpStatus];
        } catch (\ClinicCore\Application\Location\LocationException $e) {
            $outcome = ['result' => 'error', 'code' => $e->getErrorCode(), 'http' => $e->getHttpStatus()];
        } catch (\Throwable $e) {
            // Untyped failure is still an honest, parseable outcome and an exit 0.
            $outcome = ['result' => 'fatal', 'class' => get_class($e), 'detail' => $e->getMessage()];
        }
        cpms_race_write_outcome($outcomeFile, $outcome);
        $exit = 0;
    }

    // --------------------------------------------------------------
    // timezone_writer — Scheduling boundary: Location row lock holder
    // --------------------------------------------------------------
    elseif ($mode === 'timezone_writer') {
        $locationId = (int) ($task['location_id'] ?? 0);
        $lockWait = (int) ($task['lock_wait_timeout_sec'] ?? 5);
        if ($locationId <= 0) {
            throw new \RuntimeException('task rejected');
        }
        $mysqliHost = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        $mysqliPort = 3306;
        if (preg_match('/^([^:]+):(\d+)$/', $mysqliHost, $mm) === 1) {
            $mysqliHost = $mm[1];
            $mysqliPort = (int) $mm[2];
        }
        $mysqli = @new \mysqli($mysqliHost, DB_USER, DB_PASSWORD, DB_NAME, $mysqliPort);
        if ($mysqli->connect_errno !== 0) {
            cpms_race_emit('writer_error');
            exit(10);
        }
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = ' . max(1, $lockWait)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $mysqli->begin_transaction();
        $locations = $prefix . 'cpms_locations';
        $locked = $mysqli->query('SELECT id FROM ' . $locations . ' WHERE id = ' . $locationId . ' FOR UPDATE');
        $updated = $locked !== false && $mysqli->query(
            "UPDATE {$locations} SET timezone = 'America/New_York' WHERE id = " . $locationId
        ) !== false;
        if (!$updated) {
            cpms_race_emit('writer_error');
            $mysqli->rollback();
            @$mysqli->close();
            exit(10);
        }
        cpms_race_emit('writer_locked:' . $mysqli->thread_id);
        stream_set_timeout(STDIN, (int) ($task['command_timeout_sec'] ?? 45));
        $command = fgets(STDIN);
        $command = is_string($command) ? trim($command) : '';
        if ($command === 'commit') {
            $mysqli->commit();
            @$mysqli->close();
            exit(0);
        }
        $mysqli->rollback();
        @$mysqli->close();
        exit(3);
    }

    // --------------------------------------------------------------
    // staff_booking_actor — Scheduling boundary: the rejected booking actor
    // --------------------------------------------------------------
    elseif ($mode === 'staff_booking_actor') {
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        $staffUserId = (int) ($task['staff_user_id'] ?? 0);
        $patientId = (int) ($task['patient_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $date = (string) ($task['date'] ?? '');
        $time = (string) ($task['time'] ?? '');
        $slotId = (int) ($task['slot_id'] ?? 0);
        if ($clinicId <= 0 || $staffUserId <= 0 || $patientId <= 0 || $clinicianId <= 0 || $slotId <= 0 || $date === '') {
            cpms_race_emit(json_encode(['result' => 'fatal', 'detail' => 'task rejected'], JSON_UNESCAPED_UNICODE));
            exit(10);
        }
        \ClinicCore\Application\Scope\ScopeContext::set(
            \ClinicCore\Application\Scope\ClinicScope::forClinic($clinicId)
        );
        $connectionId = (int) $own->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($connectionId <= 0) {
            throw new \RuntimeException('fresh booking wpdb connection was not established');
        }
        cpms_race_emit('booking_connected:' . $connectionId);

        $cpms = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($cpms);
        $audit = new \ClinicCore\Infrastructure\Audit\AuditLogger($cpms, $op);
        $factory = new \ClinicCore\Settings\SettingsFactory($cpms, $audit);
        $sms = new \ClinicCore\Application\Notifications\SmsService(
            $cpms,
            $factory,
            new \ClinicCore\Infrastructure\Sms\SmsProviderRegistry(),
            new \ClinicCore\Infrastructure\Sms\CredentialVault(),
            $audit,
            $op,
            new \ClinicCore\Infrastructure\Queue\JobQueue($cpms, $op),
            static fn (): int => $clinicId
        );
        $service = new \ClinicCore\Application\Booking\BookingService(
            $cpms,
            new \ClinicCore\Infrastructure\Repository\SlotRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\AppointmentRepository($cpms),
            new \ClinicCore\Infrastructure\Repository\PatientRepository($cpms),
            $factory,
            new \ClinicCore\Tests\Support\ForkRaceAllowAllLicenseGate(),
            $audit,
            $op,
            new \ClinicCore\Infrastructure\Idempotency\Idempotency($cpms),
            $sms,
            null,
            new \ClinicCore\Infrastructure\Repository\MembershipRepository($cpms)
        );
        try {
            $service->createByStaff($staffUserId, $patientId, $clinicianId, $date, $time, null, $slotId);
            $outcome = ['result' => 'unexpected_success'];
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            $outcome = ['result' => 'booking_exception', 'code' => $e->errorCode]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        }
        $outcome['connection_id'] = $connectionId;
        $outcome['own_wpdb_connected'] = true;
        cpms_race_emit(json_encode($outcome, JSON_UNESCAPED_UNICODE));
        $exit = 0;
    }

    // --------------------------------------------------------------
    // custom_role_assignment — CustomRoleLifecycleTest waiter child
    // --------------------------------------------------------------
    elseif ($mode === 'custom_role_assignment') {
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
    }

    // --------------------------------------------------------------
    // hold_competitor — coordinator A: prefix + Slot lock, hold() after GO, COMMIT after release
    // --------------------------------------------------------------
    elseif ($mode === 'hold_competitor') {
        $dbName = (string) ($task['db_name'] ?? '');
        $readyFile = (string) ($task['ready_file'] ?? '');
        $goFile = (string) ($task['go_file'] ?? '');
        $holdDoneFile = (string) ($task['hold_done_file'] ?? '');
        $commitFile = (string) ($task['commit_file'] ?? '');
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        $slotId = (int) ($task['slot_id'] ?? 0);
        $date = (string) ($task['date'] ?? '');
        $userId = (int) ($task['user_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $organizationId = (int) ($task['organization_id'] ?? 0);
        $clinicId = (int) ($task['clinic_id'] ?? 0);
        $token = (string) ($task['token'] ?? '');
        $session = isset($task['session']) && is_array($task['session']) ? $task['session'] : [];
        if ($dbName === '' || $readyFile === '' || $goFile === '' || $holdDoneFile === '' || $commitFile === '' || $outcomeFile === ''
            || $slotId <= 0 || $date === '' || $userId <= 0 || $clinicianId <= 0 || $organizationId <= 0 || $clinicId <= 0) {
            throw new \RuntimeException('task rejected');
        }

        $conn = cpms_race_disposable_conn($dbName);
        try {
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $orgLocked = (int) $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_organizations WHERE id = %d FOR UPDATE', $organizationId));
            if ($orgLocked !== $organizationId) {
                throw new \RuntimeException('coordinator could not lock the Organization row');
            }
            $clinicLocked = (int) $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_clinics WHERE id = %d FOR UPDATE', $clinicId));
            if ($clinicLocked !== $clinicId) {
                throw new \RuntimeException('coordinator could not lock the Clinic row');
            }
            $locked = $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_schedule_slots WHERE id = %d FOR UPDATE', $slotId));
            if ((int) $locked !== $slotId) {
                throw new \RuntimeException('coordinator could not lock the Slot row');
            }
            @file_put_contents($readyFile, (string) $connectionId);

            cpms_race_wait_for_file($goFile, 30.0);

            $stack = \ClinicCore\Tests\Support\DisposableSchemaChildStack::build($conn, $userId, $session, $token);
            $stack['service']->hold($userId, $clinicianId, $date, '09:00:00', $slotId);
            @file_put_contents($holdDoneFile, 'done');

            cpms_race_wait_for_file($commitFile, 30.0);
            $conn->query('COMMIT');
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok']));
        } catch (\Throwable $e) {
            try {
                $conn->query('ROLLBACK');
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            @file_put_contents($outcomeFile, (string) json_encode([
                'result' => 'error',
                'code' => $e instanceof \ClinicCore\Domain\Booking\BookingException ? $e->errorCode : get_class($e),
            ]));
        } finally {
            @$conn->close();
        }
        $exit = 0;
    }

    // --------------------------------------------------------------
    // hold_loser — competitor B: production hold() on its own connection; expected to lose
    // --------------------------------------------------------------
    elseif ($mode === 'hold_loser') {
        $dbName = (string) ($task['db_name'] ?? '');
        $readyFile = (string) ($task['ready_file'] ?? '');
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        $slotId = (int) ($task['slot_id'] ?? 0);
        $date = (string) ($task['date'] ?? '');
        $userId = (int) ($task['user_id'] ?? 0);
        $clinicianId = (int) ($task['clinician_id'] ?? 0);
        $token = (string) ($task['token'] ?? '');
        $session = isset($task['session']) && is_array($task['session']) ? $task['session'] : [];
        if ($dbName === '' || $readyFile === '' || $outcomeFile === ''
            || $slotId <= 0 || $date === '' || $userId <= 0 || $clinicianId <= 0) {
            throw new \RuntimeException('task rejected');
        }

        $conn = cpms_race_disposable_conn($dbName);
        try {
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            @file_put_contents($readyFile, (string) $connectionId);

            $stack = \ClinicCore\Tests\Support\DisposableSchemaChildStack::build($conn, $userId, $session, $token);
            $conn->query('START TRANSACTION');
            try {
                $stack['service']->hold($userId, $clinicianId, $date, '09:00:00', $slotId);
                $conn->query('COMMIT');
                @file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok']));
            } catch (\ClinicCore\Domain\Booking\BookingException $e) {
                $conn->query('ROLLBACK');
                @file_put_contents($outcomeFile, (string) json_encode(['result' => 'refused', 'code' => $e->errorCode]));
            }
        } catch (\Throwable $e) {
            try {
                if (isset($conn)) {
                    $conn->query('ROLLBACK');
                }
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'code' => get_class($e)]));
        } finally {
            @$conn->close();
        }
        $exit = 0;
    }

    // --------------------------------------------------------------
    // hold_lock_holder — scenario 2 coordinator: Hold-row lock where confirm() takes it
    // --------------------------------------------------------------
    elseif ($mode === 'hold_lock_holder') {
        $dbName = (string) ($task['db_name'] ?? '');
        $readyFile = (string) ($task['ready_file'] ?? '');
        $goFile = (string) ($task['go_file'] ?? '');
        $holdId = (int) ($task['hold_id'] ?? 0);
        if ($dbName === '' || $readyFile === '' || $goFile === '' || $holdId <= 0) {
            throw new \RuntimeException('task rejected');
        }

        $conn = cpms_race_disposable_conn($dbName);
        try {
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $locked = $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_slot_holds WHERE id = %d FOR UPDATE', $holdId));
            if ((int) $locked !== $holdId) {
                throw new \RuntimeException('coordinator could not lock the Hold row');
            }
            @file_put_contents($readyFile, (string) $connectionId);
            cpms_race_wait_for_file($goFile, 30.0);
            $conn->query('COMMIT');
        } catch (\Throwable $e) {
            try {
                $conn->query('ROLLBACK');
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            throw $e;
        } finally {
            @$conn->close();
        }
        $exit = 0;
    }

    // --------------------------------------------------------------
    // hold_expiry_worker — scenario 2: the production HoldsExpireHandler on its own connection
    // --------------------------------------------------------------
    elseif ($mode === 'hold_expiry_worker') {
        $dbName = (string) ($task['db_name'] ?? '');
        $readyFile = (string) ($task['ready_file'] ?? '');
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        if ($dbName === '' || $readyFile === '' || $outcomeFile === '') {
            throw new \RuntimeException('task rejected');
        }

        $conn = cpms_race_disposable_conn($dbName);
        try {
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $db = new \ClinicCore\Infrastructure\Db\CpmsDb($conn);
            @file_put_contents($readyFile, (string) $connectionId);
            $freed = (new \ClinicCore\Application\Jobs\HoldsExpireHandler($db))([]);
            $conn->query('COMMIT');
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok', 'freed' => (int) $freed]));
        } catch (\Throwable $e) {
            try {
                $conn->query('ROLLBACK');
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'code' => get_class($e)]));
        } finally {
            @$conn->close();
        }
        $exit = 0;
    }

    // --------------------------------------------------------------
    // facility_setup_step — FacilitySetupService::add_clinic/add_room/assign_clinician
    // --------------------------------------------------------------
    elseif ($mode === 'facility_setup_step') {
        $actor = (int) ($task['actor'] ?? 0);
        $organizationId = (int) ($task['organization_id'] ?? 0);
        $taskKind = (string) ($task['task_kind'] ?? '');
        $requestKey = (string) ($task['request_key'] ?? '');
        $name = (string) ($task['name'] ?? '');
        $siteKey = (string) ($task['site_key'] ?? '');
        $clinicKey = (string) ($task['clinic_key'] ?? '');
        $roomKey = (string) ($task['room_key'] ?? 'primary');
        $user = (int) ($task['user'] ?? 0);
        $readyFile = (string) ($task['ready_file'] ?? '');
        $goFile = (string) ($task['go_file'] ?? '');
        $outcomeFile = (string) ($task['outcome_file'] ?? '');
        if ($actor <= 0 || $organizationId <= 0 || $requestKey === '' || $readyFile === '' || $goFile === '' || $outcomeFile === ''
            || !in_array($taskKind, ['clinic', 'room', 'clinician'], true)) {
            throw new \RuntimeException('task rejected');
        }

        $workerDb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $workerDb->set_prefix($prefix);
        if (property_exists($workerDb, 'has_connected')) {
            $workerDb->has_connected = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        }
        $workerDb->init_charset();
        if (!$workerDb->check_connection(false)) {
            exit(10);
        }
        $workerDb->query('SET SESSION innodb_lock_wait_timeout = 30'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        wp_cache_flush();
        wp_set_current_user($actor);
        $connection = (int) $workerDb->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $db = new \ClinicCore\Infrastructure\Db\CpmsDb($workerDb);
        $op = new \ClinicCore\Infrastructure\Logging\OpLogger($db);
        $audit = new \ClinicCore\Infrastructure\Audit\AuditLogger($db, $op);
        $memberships = new \ClinicCore\Infrastructure\Repository\MembershipRepository($db);
        $locations = new \ClinicCore\Infrastructure\Repository\LocationRepository($db);
        $authz = new \ClinicCore\Application\Authorization\AuthorizationService($memberships, new \ClinicCore\Infrastructure\Repository\CustomRoleRepository($db));
        $service = new \ClinicCore\Application\Onboarding\FacilitySetupService(
            $db,
            new \ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService($db, $audit),
            new \ClinicCore\Application\Location\LocationService($db, $locations, $authz, $audit),
            $locations,
            $authz,
            new \ClinicCore\Application\Membership\MembershipService($db, $memberships),
            $memberships,
            new \ClinicCore\Infrastructure\Repository\ClinicianRepository($db),
            new \ClinicCore\Application\Booking\ScheduleService($db, new \ClinicCore\Infrastructure\Repository\ScheduleRepository($db), $memberships, $locations, new \ClinicCore\Infrastructure\Queue\JobQueue($db, $op), $audit, $op),
            new \ClinicCore\Settings\SettingsFactory($db, $audit),
            $audit
        );

        @file_put_contents($readyFile, (string) json_encode(['connection_id' => $connection]));

        cpms_race_wait_for_file($goFile, 30.0);

        try {
            $result = match ($taskKind) {
                'clinic' => (int) $service->add_clinic($actor, $organizationId, $requestKey, $name, $siteKey, (string) ($task['timezone'] ?? 'Asia/Tehran'))['clinic_id'],
                'room' => (int) $service->add_room($actor, $organizationId, $clinicKey, $requestKey, $name, $siteKey)['location_id'],
                'clinician' => (int) $service->assign_clinician($actor, $organizationId, $clinicKey, $user, $name, [$roomKey])['clinician_id'],
            };
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok', 'id' => $result]));
        } catch (\ClinicCore\Application\Onboarding\FacilitySetupException $e) {
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'refused', 'reason' => $e->reason()]));
        } catch (\Throwable $e) {
            @file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'class' => get_class($e), 'detail' => $e->getMessage()]));
        }
        $exit = 0;
    }

    else {
        throw new \RuntimeException('unknown mode');
    }
} catch (\Throwable $e) {
    $line = 'ERROR:' . get_class($e);
    if ($mode === 'staff_booking_actor' || $mode === 'timezone_writer') {
        cpms_race_emit($line);
    } elseif (in_array($mode, ['schedule_worker', 'active_visit_race', 'noshow_race', 'location_deactivation', 'atomic_book', 'hold_competitor', 'hold_loser', 'hold_lock_holder', 'hold_expiry_worker', 'facility_setup_step', 'lifetime_probe'], true)) {
        cpms_race_write_outcome($outcomePath, ['result' => 'fatal', 'detail' => $line]);
    } else {
        cpms_race_emit($line);
    }
    $exit = 10;
}
@$own->close();
exit($exit);

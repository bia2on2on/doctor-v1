<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Tests\Support\WpSqlFailureLedger;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use wpdb;

/**
 * NID-DB-DIAGNOSTICS: a failed NID-bearing query must leave the NID in NONE of
 * error_log, EZSQL_ERROR, last_query, last_error, func_call, $wpdb->queries,
 * an exception message, a REST response, the audit log or the operational log.
 *
 * T1 uses a `query` filter matching one EXACT interpolated SQL string, counts
 * the match once and rewrites it into a statement that still carries the NID
 * but fails in MySQL. All assertions live outside the callback.
 */
final class NationalIdDbDiagnosticsTest extends WP_UnitTestCase
{
    // Checksum-valid ten-digit NIDs (validated against NationalIdValidator::isValid).
    private const NID_A = '0499370899';
    private const NID_B = '0010000003';
    private const NID_C = '0084575948';

    private CpmsDb $db;
    private wpdb $wpdb;
    private int $clinic_id;
    private string $error_log_path;
    private string $previous_error_log;
    private bool $previous_suppress;
    private bool $previous_show;
    private bool $original_suppress;
    private bool $original_show;
    private ?Closure $fault = null;
    private int $fault_hits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->db       = App::db();
        $this->wpdb     = $this->db->wpdb();
        $this->clinic_id = 1;

        // A real PHP error log file, so T2 reads what error_log() actually wrote.
        $this->previous_error_log = (string) ini_get('error_log');
        $path                     = tempnam(sys_get_temp_dir(), 'cpms-nid-diag-');
        self::assertIsString($path);
        $this->error_log_path = $path;
        ini_set('error_log', $this->error_log_path);

        // Exercise restoration from a state where output is NOT suppressed.
        $this->original_suppress = (bool) $this->wpdb->suppress_errors;
        $this->original_show     = (bool) $this->wpdb->show_errors;
        $this->wpdb->suppress_errors(false);
        $this->wpdb->show_errors(false);
        $this->previous_suppress = (bool) $this->wpdb->suppress_errors;
        $this->previous_show     = (bool) $this->wpdb->show_errors;
    }

    protected function tearDown(): void
    {
        $this->removeFault();
        $this->wpdb->suppress_errors($this->original_suppress);
        $this->wpdb->show_errors($this->original_show);
        ini_set('error_log', $this->previous_error_log);
        @unlink($this->error_log_path);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- T1/T2

    /** Flow 1 — patient create uniqueness lookup (PatientRepository.php:73-78). */
    public function testFlow1UniquenessLookupFailureLeavesNoNid(): void
    {
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, self::NID_A]);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_A));

        try {
            $row = (new PatientRepository($this->db))->find_by_national_id($this->clinic_id, self::NID_A);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertNull($row, 'soft-mode failure stays a missing row');
        $this->assertNoNidInDiagnostics(self::NID_A);
    }

    /** Flow 2 — patient create INSERT (PatientRepository.php:397-402). */
    public function testFlow2PatientCreateInsertFailureLeavesNoNid(): void
    {
        $data     = $this->newPatientData(self::NID_B);
        $expected = $this->expectedInsertSql($this->db->table('cpms_patients'), $data);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_B));

        try {
            $ok = $this->db->insert('cpms_patients', $data);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertFalse($ok, 'soft-mode insert failure stays false');
        $this->assertNoNidInDiagnostics(self::NID_B);
    }

    /** Flow 3a — staff patient UPDATE (PatientRepository.php:408-415). */
    public function testFlow3aPatientUpdateFailureLeavesNoNid(): void
    {
        $patient_id = $this->seedPatient(self::NID_A);
        $expected   = $this->wpdb->prepare(
            'UPDATE `' . $this->db->table('cpms_patients') . '` SET `national_id` = %s WHERE `id` = %s',
            [self::NID_C, (string) $patient_id]
        );
        $this->faultOn($expected, $this->failingSelectSql(self::NID_C));

        try {
            $affected = $this->db->update('cpms_patients', ['national_id' => self::NID_C], ['id' => $patient_id]);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertSame(0, $affected, 'soft-mode update failure stays zero rows');
        $this->assertNoNidInDiagnostics(self::NID_C);
    }

    /** Flow 3b — collision recheck after that update (PatientService.php:229-249, 935-940). */
    public function testFlow3bCollisionRecheckFailureLeavesNoNid(): void
    {
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, self::NID_C]);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_C));

        try {
            $row = (new PatientRepository($this->db))->find_by_national_id($this->clinic_id, self::NID_C);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertNull($row);
        $this->assertNoNidInDiagnostics(self::NID_C);
    }

    /** Flow 4 — registration INSERT shape (PatientRegistrationService.php:323-337). */
    public function testFlow4RegistrationInsertFailureLeavesNoNid(): void
    {
        $now  = $this->db->nowUtcSql();
        $data = [
            'clinic_id'   => $this->clinic_id,
            'mrn'         => 'NIDDIAG-REG-' . strtoupper(bin2hex(random_bytes(4))),
            'first_name'  => 'Reg',
            'last_name'   => 'Nid',
            'mobile'      => '0913' . (string) random_int(10000000, 99999999),
            'national_id' => self::NID_B,
            'birth_date'  => '1990-01-01',
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $expected = $this->expectedInsertSql($this->db->table('cpms_patients'), $data);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_B));

        try {
            $ok = $this->db->insert('cpms_patients', $data);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertFalse($ok);
        $this->assertNoNidInDiagnostics(self::NID_B);
    }

    /** Flow 5 — staff/clinical patient search (PatientRepository.php:372-391). */
    public function testFlow5PatientSearchFailureLeavesNoNid(): void
    {
        $expected = $this->captureExactSql(
            self::NID_A,
            fn () => (new PatientRepository($this->db))->search($this->clinic_id, self::NID_A, 20)
        );
        $this->faultOn($expected, $this->failingSelectSql(self::NID_A));

        try {
            $rows = (new PatientRepository($this->db))->search($this->clinic_id, self::NID_A, 20);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertSame([], $rows);
        $this->assertNoNidInDiagnostics(self::NID_A);
    }

    /** Flow 5 over the real REST route: the response body must stay clean. */
    public function testFlow5RestSearchFailureDoesNotEchoNid(): void
    {
        $secretary = (int) wp_create_user('nid_diag_sec', 'pass-12345', 'nid_diag_sec@test.local');
        $user      = get_userdata($secretary);
        self::assertNotFalse($user);
        $user->set_role(RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $this->clinic_id, 'cpms_secretary');
        wp_set_current_user($secretary);

        $expected = $this->captureExactSql(
            self::NID_C,
            fn () => (new PatientRepository($this->db))->search($this->clinic_id, self::NID_C, 25)
        );
        $this->faultOn($expected, $this->failingSelectSql(self::NID_C));
        try {
            $request  = new WP_REST_Request('POST', '/clinic/v1/patients/search');
            $request->set_param('q', self::NID_C);
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            $response = rest_do_request($request);
        } finally {
            $this->removeFault();
            wp_set_current_user(0);
        }

        self::assertSame(1, $this->fault_hits, 'T1: exactly one exact-SQL match');
        self::assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertNoNidInDiagnostics(self::NID_C, null, (string) wp_json_encode($response->get_data()));
    }

    // -------------------------------------------------------------- T3/T4/T5

    /** T2 — strict mode still throws, but with a constant NID-free message. */
    public function testStrictNidFailureThrowsConstantMessage(): void
    {
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, self::NID_A]);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_A));

        $this->db->setStrict(true);
        $thrown = null;
        try {
            $this->db->fetchRow($sql, [$this->clinic_id, self::NID_A]);
        } catch (Throwable $error) {
            $thrown = $error;
        } finally {
            $this->db->setStrict(false);
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits);
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('Database error (national ID redacted).', $thrown->getMessage());
        $this->assertNoNidInDiagnostics(self::NID_A, $thrown);
    }

    /** T3 — real duplicate on UNIQUE (clinic_id, national_id), migration 0001:83. */
    public function testT3RealDuplicateKeyDriverTextIsRedacted(): void
    {
        $this->seedPatient(self::NID_A);
        $data = $this->newPatientData(self::NID_A);

        $ok = $this->db->insert('cpms_patients', $data);
        $dbh   = $this->wpdb->dbh;
        $errno = $dbh instanceof \mysqli ? mysqli_errno($dbh) : 0;

        self::assertFalse($ok, 'the real unique index rejects the duplicate');
        self::assertSame(1062, $errno, 'a real driver duplicate-key failure occurred');
        $this->assertNoNidInDiagnostics(self::NID_A);
    }

    /** T4 — a call with no NID-shaped value keeps the old soft/strict behavior. */
    public function testT4NonNidCallsAreUnchanged(): void
    {
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id]);
        $replacement = 'SELECT 1 AS probe FROM ' . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';
        $this->faultOn($expected, $replacement);

        $this->db->setStrict(false);
        try {
            $row = $this->db->fetchRow($sql, [$this->clinic_id]);
            self::assertNull($row, 'soft mode still returns null');
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits);
        $log = (string) @file_get_contents($this->error_log_path);
        self::assertStringContainsString('cpms_nid_diag_no_such_column', $log, 'unguarded error_log behavior unchanged');
        self::assertIsArray($GLOBALS['EZSQL_ERROR'] ?? null);
        $last_index = array_key_last($GLOBALS['EZSQL_ERROR']);
        self::assertIsInt($last_index);
        self::assertArrayHasKey('query', $GLOBALS['EZSQL_ERROR'][$last_index]);
        self::assertStringContainsString(
            'cpms_nid_diag_no_such_column',
            (string) $GLOBALS['EZSQL_ERROR'][$last_index]['query'],
            'unguarded EZSQL_ERROR behavior unchanged'
        );

        $this->faultOn($expected, $replacement);
        $this->db->setStrict(true);
        $thrown = null;
        try {
            $this->db->fetchRow($sql, [$this->clinic_id]);
        } catch (Throwable $error) {
            $thrown = $error;
        } finally {
            $this->db->setStrict(false);
            $this->removeFault();
        }
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertStringStartsWith('SQL error: ', $thrown->getMessage(), 'strict-mode message unchanged for non-NID calls');
        self::assertSame($this->previous_suppress, (bool) $this->wpdb->suppress_errors);
        self::assertSame($this->previous_show, (bool) $this->wpdb->show_errors);
    }

    /** T5 — SAVEQUERIES cannot be enabled at runtime; do not fake it. */
    public function testT5SavequeriesQueryLog(): void
    {
        if (!defined('SAVEQUERIES') || !SAVEQUERIES) {
            self::markTestSkipped(
                'SAVEQUERIES is not defined/enabled in this runtime; query-log scrubbing is INFERENCE from source, not faked.'
            );
        }

        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, self::NID_A]);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_A));
        try {
            $this->db->fetchRow($sql, [$this->clinic_id, self::NID_A]);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits);
        $queries = isset($this->wpdb->queries) && is_array($this->wpdb->queries) ? $this->wpdb->queries : [];
        foreach ($queries as $entry) {
            if (is_array($entry) && isset($entry[0]) && is_string($entry[0])) {
                self::assertStringNotContainsString(self::NID_A, $entry[0], '$wpdb->queries must not retain the NID');
            }
        }
    }

    /** Compatibility: the CI failure ledger still observes a guarded failure. */
    public function testCiFailureLedgerStillDetectsGuardedFailure(): void
    {
        $property = new ReflectionProperty(WpSqlFailureLedger::class, 'failures');
        $property->setAccessible(true);
        $before = (int) $property->getValue();

        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, self::NID_A]);
        $this->faultOn($expected, $this->failingSelectSql(self::NID_A));
        try {
            $this->db->fetchRow($sql, [$this->clinic_id, self::NID_A]);
        } finally {
            $this->removeFault();
        }

        // The ledger's query filter reads the PREVIOUS statement's last_error.
        $this->wpdb->query('SELECT 1');
        self::assertGreaterThan($before, (int) $property->getValue(), 'ledger failure count still increases');

        $entries = is_array($GLOBALS['EZSQL_ERROR'] ?? null) ? $GLOBALS['EZSQL_ERROR'] : [];
        $last    = $entries === [] ? [] : end($entries);
        self::assertIsArray($last, 'the redacted diagnostic row is kept, not dropped');
        self::assertArrayHasKey('query', $last);
        self::assertArrayHasKey('error_str', $last);
        self::assertStringNotContainsString(self::NID_A, (string) $last['query']);
        self::assertStringNotContainsString(self::NID_A, (string) $last['error_str']);
        $this->assertNoNidInDiagnostics(self::NID_A);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $data */
    private function expectedInsertSql(string $table, array $data): string
    {
        $fields  = '`' . implode('`, `', array_keys($data)) . '`';
        $formats = implode(', ', array_fill(0, count($data), '%s'));

        return (string) $this->wpdb->prepare("INSERT INTO `$table` ($fields) VALUES ($formats)", array_values($data));
    }

    /** Capture the exact interpolated SQL of a harmless NID-bearing read. */
    private function captureExactSql(string $nid, callable $operation): string
    {
        $captured = null;
        $capture  = function (string $query) use ($nid, &$captured): string {
            if ($captured === null && str_contains($query, $nid)) {
                $captured = $query;
            }

            return $query;
        };
        add_filter('query', $capture, 5);
        try {
            $operation();
        } finally {
            remove_filter('query', $capture, 5);
        }
        self::assertIsString($captured, 'the exact NID-bearing SQL was captured');

        return (string) $captured;
    }

    private function failingSelectSql(string $nid): string
    {
        return "SELECT '{$nid}' AS nid_probe FROM " . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';
    }

    /** @return array<string, mixed> */
    private function newPatientData(string $nid): array
    {
        $now = $this->db->nowUtcSql();

        return [
            'clinic_id'   => $this->clinic_id,
            'mrn'         => 'NIDDIAG-' . strtoupper(bin2hex(random_bytes(5))),
            'first_name'  => 'Nid',
            'last_name'   => 'Diag',
            'mobile'      => '0912' . (string) random_int(10000000, 99999999),
            'national_id' => $nid,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
    }

    private function seedPatient(string $nid): int
    {
        $data = $this->newPatientData($nid);
        self::assertTrue($this->db->insert('cpms_patients', $data));

        return $this->db->wpdb_last_insert_id();
    }

    private function faultOn(string $exactSql, string $replacementSql): void
    {
        $this->fault_hits = 0;
        $fault            = function (string $query) use ($exactSql, $replacementSql): string {
            if ($query !== $exactSql) {
                return $query;
            }
            ++$this->fault_hits;

            return $replacementSql;
        };
        $this->fault = $fault;
        add_filter('query', $fault, 5);
    }

    private function removeFault(): void
    {
        if ($this->fault !== null) {
            remove_filter('query', $this->fault, 5);
            $this->fault = null;
        }
    }

    private function assertNoNidInDiagnostics(string $nid, ?Throwable $error = null, ?string $response = null): void
    {
        $log = (string) @file_get_contents($this->error_log_path);
        self::assertStringNotContainsString($nid, $log, 'T2: error_log');

        $entries = is_array($GLOBALS['EZSQL_ERROR'] ?? null) ? $GLOBALS['EZSQL_ERROR'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (['query', 'error_str'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key])) {
                    self::assertStringNotContainsString($nid, $entry[$key], 'T2: EZSQL_ERROR');
                }
            }
        }

        self::assertStringNotContainsString($nid, (string) $this->wpdb->last_query, 'T2: last_query');
        self::assertStringNotContainsString($nid, (string) $this->wpdb->last_error, 'T2: last_error');
        self::assertTrue(property_exists($this->wpdb, 'func_call'), 'wpdb exposes func_call');
        self::assertStringNotContainsString($nid, (string) $this->wpdb->func_call, 'T2: func_call');
        $queries = isset($this->wpdb->queries) && is_array($this->wpdb->queries) ? $this->wpdb->queries : [];
        foreach ($queries as $entry) {
            if (is_array($entry) && isset($entry[0]) && is_string($entry[0])) {
                self::assertStringNotContainsString($nid, $entry[0], 'T2: $wpdb->queries');
            }
        }
        if ($error !== null) {
            self::assertStringNotContainsString($nid, $error->getMessage(), 'T2: exception message');
        }
        if ($response !== null) {
            self::assertStringNotContainsString($nid, $response, 'T2: REST response body');
        }

        $like  = '%' . $this->db->esc_like($nid) . '%';
        $audit = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_audit_logs')
            . ' WHERE before_json LIKE %s OR after_json LIKE %s OR meta_json LIKE %s',
            [$like, $like, $like]
        );
        self::assertSame(0, (int) $audit, 'T2: audit table');
        $op = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_operational_logs')
            . ' WHERE message LIKE %s OR context_json LIKE %s',
            [$like, $like]
        );
        self::assertSame(0, (int) $op, 'T2: operational log table');

        self::assertSame($this->previous_suppress, (bool) $this->wpdb->suppress_errors, 'T2: suppress_errors restored');
        self::assertSame($this->previous_show, (bool) $this->wpdb->show_errors, 'T2: show_errors restored');
    }

    // ------------------------------------------------------- ROUND 2 additions

    /** R2a — a hyphen-separated NID is detected and redacted on a failed lookup. */
    public function testSeparatedNidLookupFailureLeavesNoNid(): void
    {
        $nid      = '049-937-0899';
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, $nid]);
        $this->faultOn($expected, $this->failingSelectSql($nid));

        try {
            $row = (new PatientRepository($this->db))->find_by_national_id($this->clinic_id, $nid);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'R2a: one exact-SQL match');
        self::assertNull($row);
        $this->assertNoNidInDiagnostics($nid);
    }

    /** R2a — the same separated NID alongside a mobile on a failed insert. */
    public function testSeparatedNidWithMobileInsertFailureLeavesNoNid(): void
    {
        $nid      = '049 937 0899';
        $data     = $this->newPatientData($nid);
        $columns  = '`' . implode('`, `', array_keys($data)) . '`';
        $sql      = 'INSERT INTO `' . $this->db->table('cpms_patients') . '` (' . $columns . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '%s')) . ')';
        $expected = $this->db->prepare($sql, array_values($data));
        $this->faultOn($expected, $this->failingSelectSql($nid));

        try {
            $ok = $this->db->query($sql, array_values($data));
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'R2a: one exact-SQL match');
        self::assertFalse($ok);
        self::assertMatchesRegularExpression('/^0912[0-9]{8}$/', (string) $data['mobile']);
        $this->assertNoNidInDiagnostics($nid);
    }

    /** R2b — exactly one value-free log line is written for a failed guarded call. */
    public function testFailedGuardedCallWritesOneValueFreeLogLine(): void
    {
        $path     = (string) tempnam(sys_get_temp_dir(), 'cpms-nid-r2-log-');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $path);

        $nid            = '049.937.0899';
        $digits         = '0499370899';
        $data           = $this->newPatientData($nid);
        $data['mobile'] = '09123456789';
        $mobile         = (string) $data['mobile'];
        $columns        = '`' . implode('`, `', array_keys($data)) . '`';
        $sql            = 'INSERT INTO `' . $this->db->table('cpms_patients') . '` (' . $columns . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '%s')) . ')';
        $expected       = $this->db->prepare($sql, array_values($data));
        $this->faultOn($expected, $this->failingSelectSql($nid));

        try {
            $this->db->query($sql, array_values($data));
        } finally {
            $this->removeFault();
            ini_set('error_log', $previous);
        }

        $lines = array_values(array_filter(
            explode("\n", (string) @file_get_contents($path)),
            static fn (string $line): bool => str_contains($line, '[cpms-nid-db]')
        ));

        $all = (string) @file_get_contents($path);
        self::assertSame(1, $this->fault_hits, 'R2b: one exact-SQL match');
        self::assertCount(1, $lines, 'R2b: exactly one redacted diagnostic line');
        self::assertStringNotContainsString($nid, $all, 'R2b: no separated NID anywhere in the log');
        self::assertStringNotContainsString($digits, $all, 'R2b: no bare NID anywhere in the log');
        self::assertStringNotContainsString($mobile, $all, 'R2b: no mobile anywhere in the log');
        $line = (string) $lines[0];
        self::assertStringNotContainsString($nid, $line, 'R2b: no separated NID');
        self::assertStringNotContainsString($digits, $line, 'R2b: no bare NID');
        self::assertStringNotContainsString($mobile, $line, 'R2b: no mobile digits');
        @unlink($path);
    }

    /** R2c — show_errors=true survives a guarded failure; teardown restores its original value. */
    public function testShowErrorsTrueSurvivesGuardedFailure(): void
    {
        $this->wpdb->show_errors(true);
        self::assertTrue((bool) $this->wpdb->show_errors, 'R2c: precondition');

        $nid      = '049/937/0899';
        $sql      = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected = $this->db->prepare($sql, [$this->clinic_id, $nid]);
        $this->faultOn($expected, $this->failingSelectSql($nid));

        try {
            $this->db->fetchRow($sql, [$this->clinic_id, $nid]);
        } finally {
            $this->removeFault();
        }

        self::assertSame(1, $this->fault_hits, 'R2c: one exact-SQL match');
        self::assertTrue((bool) $this->wpdb->show_errors, 'R2c: show_errors after guarded failure');
    }

    /** R2d — strict mode: a second exact-SQL injection also counts once. */
    public function testStrictModeSecondInjectionFinalHitCount(): void
    {
        $nid         = self::NID_A;
        $sql         = 'SELECT id FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s LIMIT 1';
        $expected    = $this->db->prepare($sql, [$this->clinic_id, $nid]);
        $replacement = $this->failingSelectSql($nid);
        $hits        = 0;
        $fault       = static function (string $query) use ($expected, $replacement, &$hits): string {
            if ($query !== $expected) {
                return $query;
            }
            ++$hits;

            return $replacement;
        };

        add_filter('query', $fault, 5);
        $this->db->setStrict(true);
        $thrown = [];
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $this->db->fetchRow($sql, [$this->clinic_id, $nid]);
                } catch (Throwable $error) {
                    $thrown[] = $error;
                }
            }
        } finally {
            $this->db->setStrict(false);
            remove_filter('query', $fault, 5);
        }

        self::assertSame(2, $hits, 'R2d: both exact-SQL injections matched once each');
        self::assertCount(2, $thrown, 'R2d: both strict calls threw');
        self::assertSame('Database error (national ID redacted).', (string) $thrown[0]->getMessage());
        self::assertSame('Database error (national ID redacted).', (string) $thrown[1]->getMessage());
    }

    // ------------------------------------------------------- ROUND 3C additions

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function acceptedNidSpellings(): array
    {
        $digits = '0499370899';

        return [
            'tab filler' => ['tab filler', "049\t937\t0899", $digits],
            'underscore filler' => ['underscore filler', '049_937_0899', $digits],
            'comma filler' => ['comma filler', '049,937,0899', $digits],
            'letter filler' => ['letter filler', '049abc937xyz0899', $digits],
            'Persian digit filler' => ['Persian digit filler', "049\u{06F1}937\u{06F2}0899", $digits],
            'Arabic-Indic digit filler' => ['Arabic-Indic digit filler', "049\u{0661}937\u{0662}0899", $digits],
            'ZWNJ filler' => ['ZWNJ filler', "049\u{200C}937\u{200C}0899", $digits],
        ];
    }

    /** @dataProvider acceptedNidSpellings */
    public function testAcceptedNidSpellingDuplicateErrorIsValueFree(string $label, string $spelling, string $digits): void
    {
        $line = $this->runDuplicateSpellingCase($spelling, $digits);

        self::assertStringContainsString('[cpms-nid-db] failed guarded query', $line, $label);
    }

    /** R3c — the single redacted log line keeps useful value-free context. */
    public function testRedactedLogKeepsValueFreeContext(): void
    {
        $line = $this->runDuplicateSpellingCase('049-937-0899', '0499370899');

        self::assertStringContainsString('error=Duplicate entry', $line);
        self::assertStringContainsString('u_pat_mrn', $line);
        self::assertStringNotContainsString('049-937-0899', $line);
        self::assertStringNotContainsString('0499370899', $line);
    }

    /** R3c — invalid UTF-8 in diagnostic text returns the fixed placeholder. */
    public function testInvalidUtf8RedactionFailsClosed(): void
    {
        $spelling = "049\xFF937\xFF0899";
        $text     = "prefix {$spelling} suffix";
        $pattern = (new ReflectionClass(CpmsDb::class))->getConstant('NID_TOKEN_PATTERN');
        self::assertNotFalse($pattern, 'NID_TOKEN_PATTERN constant exists');
        self::assertIsString($pattern, 'NID_TOKEN_PATTERN is a string');

        self::assertIsInt(preg_match('/[0-9]{3}/', '049'));
        self::assertSame(PREG_NO_ERROR, preg_last_error(), 'PCRE state clean before the subject');

        $method = new ReflectionMethod(CpmsDb::class, 'redact_nid_text');
        $method->setAccessible(true);
        $result = $method->invoke($this->db, $text);

        self::assertSame('[nid-redacted]', $result);
        self::assertStringNotContainsString($spelling, (string) $result);
        self::assertStringNotContainsString('0499370899', (string) $result);

        self::assertIsInt(preg_match('/[0-9]{3}/', '049'));
        self::assertSame(PREG_NO_ERROR, preg_last_error(), 'PCRE state clean before the direct probe');
        self::assertNull(preg_replace_callback($pattern, static fn (): string => '', $text));
        self::assertSame(PREG_BAD_UTF8_ERROR, preg_last_error(), 'subject really errors under /u');
    }

    /** R3c — a forced PCRE error yields the fixed placeholder, never the subject. */
    public function testForcedPcreFailureFailsClosed(): void
    {
        $previous_limit = (string) ini_get('pcre.backtrack_limit');
        $previous_jit   = (string) ini_get('pcre.jit');

        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '1');
            $probe = preg_match('/(?:\D+|<\d+>)*[!?]/', str_repeat('foobar foobar foobar ', 16));
            if (false !== $probe && PREG_NO_ERROR === preg_last_error()) {
                self::fail('PCRE failure probe did not produce an error.');
            }

            $method = new ReflectionMethod(CpmsDb::class, 'redact_nid_text');
            $method->setAccessible(true);
            $result = $method->invoke($this->db, '0499370899');

            self::assertSame('[nid-redacted]', $result);
            self::assertStringNotContainsString('0499370899', (string) $result);
        } finally {
            ini_set('pcre.backtrack_limit', $previous_limit);
            ini_set('pcre.jit', $previous_jit);
        }
    }

    // ------------------------------------------------------- ROUND 3D additions

    /** R3d — a ten-digit integer bound with %d activates the guard. */
    public function testTenDigitIntegerBoundWithDIsValueFree(): void
    {
        $integer  = 1000000001;
        $sql      = 'SELECT %d AS probe FROM ' . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';
        $expected = $this->db->prepare($sql, [$integer]);

        $this->assertGuardedFailureValueFree(
            $expected,
            null,
            fn () => $this->db->fetchRow($sql, [$integer]),
            ['1000000001'],
            'integer %d'
        );
    }

    /** R3d — a ten-digit integer nested in an array bound value activates the guard. */
    public function testNestedIntegerBoundValueIsValueFree(): void
    {
        $integer = 1000000002;
        $sql     = 'SELECT ' . $integer . ' AS probe FROM ' . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';
        $guard   = new ReflectionMethod(CpmsDb::class, 'without_nid_diagnostics');
        $guard->setAccessible(true);
        $operation = function () use ($guard, $sql, $integer) {
            return $guard->invoke(
                $this->db,
                fn () => $this->db->fetchRow($sql),
                ['SELECT', ['nested' => $integer]]
            );
        };

        $this->assertGuardedFailureValueFree($sql, null, $operation, ['1000000002'], 'nested integer');
    }

    /** R3d — the wpdb-escaped LIKE spelling is redacted alongside the original. */
    public function testEscapedLikeTermIsValueFree(): void
    {
        $term         = '049%937%0899';
        $like         = '%' . $this->wpdb->esc_like($term) . '%';
        $escaped      = $this->wpdb->remove_placeholder_escape(
            $this->wpdb->_real_escape($this->wpdb->esc_like($term))
        );
        $like_escaped = $this->wpdb->remove_placeholder_escape($this->wpdb->_real_escape($like));

        // The query filter sees the wpdb-escaped spelling, not the raw term.
        $expected = $this->captureExactSql(
            $escaped,
            fn () => (new PatientRepository($this->db))->search($this->clinic_id, $term, 20)
        );
        $replacement = "SELECT '{$escaped}' AS nid_probe FROM " . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';

        $this->assertGuardedFailureValueFree(
            $expected,
            $replacement,
            fn () => (new PatientRepository($this->db))->search($this->clinic_id, $term, 20),
            [$term, '0499370899', $escaped, $like_escaped],
            'LIKE escaped'
        );
    }

    /** R3d — ten digits split across punctuation in the SQL template do NOT activate the guard. */
    public function testBenignTemplateDigitsDoNotActivateGuard(): void
    {
        $sql    = 'SELECT 12345, 67890 AS benign FROM ' . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';
        $marker = 'cpms_nid_diag_no_such_column';
        $hits   = 0;
        $fault  = static function (string $query) use ($sql, &$hits): string {
            if ($query !== $sql) {
                return $query;
            }
            ++$hits;

            return $query;
        };

        add_filter('query', $fault, 5);
        try {
            $row = $this->db->fetchRow($sql);
        } finally {
            remove_filter('query', $fault, 5);
        }

        self::assertSame(1, $hits, 'exact-SQL observer matched once');
        self::assertNull($row, 'soft failure stays a missing row');
        $log = (string) @file_get_contents($this->error_log_path);
        self::assertStringContainsString($marker, $log, 'normal wpdb diagnostics retained');
        self::assertStringNotContainsString('[cpms-nid-db]', $log, 'guard did not activate');
        $entries = is_array($GLOBALS['EZSQL_ERROR'] ?? null) ? $GLOBALS['EZSQL_ERROR'] : [];
        $last    = end($entries);
        self::assertIsArray($last);
        self::assertArrayHasKey('query', $last);
        self::assertStringContainsString($marker, (string) $last['query']);
    }

    /** R3d — a complete NID inline in the SQL template still activates the guard. */
    public function testInlineTemplateNidActivatesGuard(): void
    {
        $sql = 'SELECT \'0499370899\' AS probe FROM ' . $this->db->table('cpms_patients')
            . ' WHERE cpms_nid_diag_no_such_column = 1 LIMIT 1';

        $this->assertGuardedFailureValueFree($sql, null, fn () => $this->db->fetchRow($sql), ['0499370899'], 'inline template');
    }

    /** R3d — query-log scrubbing is reachable without SAVEQUERIES by reflection. */
    public function testScrubRemovesSyntheticQueryLogWithoutSavequeries(): void
    {
        self::assertTrue(property_exists($this->wpdb, 'queries'), 'wpdb exposes queries');
        $original = is_array($this->wpdb->queries) ? $this->wpdb->queries : [];
        $this->wpdb->queries = [['SELECT 0499370899', 0.1, []]];
        $scrub = new ReflectionMethod(CpmsDb::class, 'scrub_nid_diagnostics');
        $scrub->setAccessible(true);

        try {
            $scrub->invoke($this->db, ['ez_count' => 0, 'queries_count' => 0, 'values' => []]);
            self::assertSame([], $this->wpdb->queries, 'synthetic query log scrubbed');
        } finally {
            $this->wpdb->queries = $original;
        }
    }

    /**
     * @param list<string> $sensitive
     */
    private function assertGuardedFailureValueFree(
        string $expected,
        ?string $replacement,
        callable $operation,
        array $sensitive,
        string $label
    ): void {
        $hits  = 0;
        $fault = function (string $query) use ($expected, $replacement, &$hits): string {
            if ($query !== $expected) {
                return $query;
            }
            ++$hits;

            return null === $replacement ? $query : $replacement;
        };

        add_filter('query', $fault, 5);
        $this->db->setStrict(true);
        $thrown = null;
        try {
            try {
                $operation();
            } catch (Throwable $error) {
                $thrown = $error;
            }
        } finally {
            $this->db->setStrict(false);
            remove_filter('query', $fault, 5);
        }

        self::assertSame(1, $hits, $label . ': exact-SQL match once');
        self::assertInstanceOf(RuntimeException::class, $thrown, $label . ': strict failure thrown');
        self::assertSame('Database error (national ID redacted).', $thrown->getMessage(), $label . ': value-free message');
        foreach ($sensitive as $value) {
            self::assertStringNotContainsString($value, $thrown->getMessage(), $label . ': exception message');
        }
        $dbh   = $this->wpdb->dbh;
        $errno = $dbh instanceof \mysqli ? mysqli_errno($dbh) : 0;
        self::assertNotSame(0, $errno, $label . ': real DB failure');

        $entries = is_array($GLOBALS['EZSQL_ERROR'] ?? null) ? $GLOBALS['EZSQL_ERROR'] : [];
        $last    = end($entries);
        self::assertIsArray($last);
        self::assertArrayHasKey('query', $last);
        self::assertArrayHasKey('error_str', $last);
        foreach (['query', 'error_str'] as $key) {
            foreach ($sensitive as $value) {
                self::assertStringNotContainsString($value, (string) $last[$key], $label . ': EZSQL_ERROR ' . $key);
            }
        }

        $all_log = (string) @file_get_contents($this->error_log_path);
        $lines   = array_values(array_filter(
            explode("\n", $all_log),
            static fn (string $entry): bool => str_contains($entry, '[cpms-nid-db]')
        ));
        self::assertCount(1, $lines, $label . ': one redacted log line');
        foreach ($sensitive as $value) {
            self::assertStringNotContainsString($value, $all_log, $label . ': full error_log output');
        }
    }

    /** @return string The single redacted log line from the real duplicate failure. */
    private function runDuplicateSpellingCase(string $spelling, string $digits): string
    {
        $data        = $this->newPatientData($digits);
        $data['mrn'] = $spelling;
        self::assertTrue($this->db->insert('cpms_patients', $data), 'seed row with the NID-shaped MRN');

        $columns  = '`' . implode('`, `', array_keys($data)) . '`';
        $sql      = 'INSERT INTO `' . $this->db->table('cpms_patients') . '` (' . $columns . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '%s')) . ')';
        $expected = $this->db->prepare($sql, array_values($data));
        $hits     = 0;
        $probe    = static function (string $query) use ($expected, &$hits): string {
            if ($query === $expected) {
                ++$hits;
            }

            return $query;
        };

        add_filter('query', $probe, 5);
        $this->db->setStrict(true);
        $thrown = null;
        try {
            try {
                $this->db->query($sql, array_values($data));
            } catch (Throwable $error) {
                $thrown = $error;
            }
        } finally {
            $this->db->setStrict(false);
            remove_filter('query', $probe, 5);
        }

        self::assertSame(1, $hits, 'exact-SQL anchored filter matched once');
        self::assertInstanceOf(RuntimeException::class, $thrown, 'strict guarded call threw');
        self::assertSame('Database error (national ID redacted).', $thrown->getMessage());
        $dbh   = $this->wpdb->dbh;
        $errno = $dbh instanceof \mysqli ? mysqli_errno($dbh) : 0;
        self::assertNotSame(0, $errno, 'a real DB failure occurred');

        $entries = is_array($GLOBALS['EZSQL_ERROR'] ?? null) ? $GLOBALS['EZSQL_ERROR'] : [];
        $last    = end($entries);
        self::assertIsArray($last);
        self::assertArrayHasKey('query', $last);
        self::assertArrayHasKey('error_str', $last);
        foreach (['query', 'error_str'] as $key) {
            self::assertStringNotContainsString($spelling, (string) $last[$key], 'EZSQL_ERROR ' . $key);
            self::assertStringNotContainsString($digits, (string) $last[$key], 'EZSQL_ERROR ' . $key . ' digits');
        }

        $lines = array_values(array_filter(
            explode("\n", (string) @file_get_contents($this->error_log_path)),
            static fn (string $entry): bool => str_contains($entry, '[cpms-nid-db]')
        ));
        self::assertCount(1, $lines, 'exactly one redacted log line');
        $line = (string) $lines[0];
        self::assertStringNotContainsString($spelling, $line, 'redacted log spelling');
        self::assertStringNotContainsString($digits, $line, 'redacted log digits');

        $this->assertNoNidInDiagnostics($spelling, $thrown);
        $this->assertNoNidInDiagnostics($digits, $thrown);

        return $line;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Tests\Support\WpSqlFailureLedger;
use Closure;
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
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\RestoreTableLoader;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Backup\RestoreDumpReader;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use WP_UnitTestCase;

/**
 * RESTORE-LOADER-NO-DDL — بارگذاری بدون DDL در جدول‌های آمادهٔ خالی.
 *
 * الگوی سبزِ نزدیک: `tests/Integration/RestoreIdentityCleanupTest.php:44-77`
 * (fixture DB واقعی + فیلتر `query` روی شکلِ دقیق SQL، شمارش دقیقاً یک‌بار، و
 * restore کاملِ filter در finally) و `RestoreBarrierStoreTest.php:38-49`
 * (چیدمان و پاک‌سازی فایل موقت).
 *
 * dump در setUp با **خودِ** `BackupSqlDumper` از هدفِ پرشده ساخته می‌شود و بعد
 * هدف خالی می‌شود؛ پس قالب/escape/ترتیب همان قالبِ واقعی است و هیچ متنِ dump
 * دست‌نویس در تست نیست. شمارشِ انتظاری هم با برگرداندنِ همان dump از
 * `RestoreDumpReader` گرفته می‌شود (نه عددِ دستی). همهٔ assertionها بیرون از
 * callback هستند و هر ادعای DB-شکل با فیلتر `query` روی شکلِ دقیق SQL (شمارش
 * دقیقاً یک‌بار) یا با proofِ ترتیبِ واقعیِ insertها اثبات می‌شود.
 */
final class RestoreTableLoaderTest extends WP_UnitTestCase {
    private const SEEDED_ROWS = 8;

    private string $dump_file;
    private int $user_id = 0;
    private int $loaded_tables = 0;
    private int $loaded_rows = 0;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        $this->dump_file = sys_get_temp_dir() . '/cpms-restore-load-' . bin2hex(random_bytes(6)) . '.sql';
        $this->user_id   = self::factory()->user->create();

        $db = App::db();
        $this->empty_target();
        self::assertTrue($db->insert('cpms_organizations', ['id' => 1, 'name' => 'Org A', 'slug' => 'org-a', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']));
        self::assertTrue($db->insert('cpms_organizations', ['id' => 2, 'name' => 'Org B', 'slug' => 'org-b', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']));
        self::assertTrue($db->insert('cpms_clinics', ['id' => 1, 'organization_id' => 1, 'name' => 'Alpha Clinic', 'slug' => 'alpha', 'timezone' => 'UTC', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']));
        self::assertTrue($db->insert('cpms_clinics', ['id' => 2, 'organization_id' => 2, 'name' => 'Beta Clinic', 'slug' => 'beta', 'timezone' => 'UTC', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']));
        self::assertTrue($db->insert('cpms_patients', [
            'id' => 7, 'clinic_id' => 1, 'mrn' => 'A-1', 'first_name' => "O'Brien", 'last_name' => 'One',
            'mobile' => '09120000001', 'national_id' => '0012345678',
            'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
        ]));
        self::assertTrue($db->insert('cpms_patients', [
            'id' => 8, 'clinic_id' => 2, 'mrn' => 'B-1', 'first_name' => 'Bob', 'last_name' => 'Two',
            'mobile' => '09120000002', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
        ]));
        self::assertTrue($db->insert('cpms_patient_logins', [
            'id' => 1, 'kind' => 'binding', 'wp_user_id' => $this->user_id, 'state' => 'active',
            'clinic_id' => 1, 'patient_id' => 7, 'live_key' => '1:7',
            'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
        ]));
        self::assertTrue($db->insert('cpms_patient_logins', [
            'id' => 2, 'kind' => 'account', 'wp_user_id' => $this->user_id, 'state' => 'active', 'account_key' => $this->user_id,
            'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
        ]));

        (new BackupSqlDumper($db))->dumpToFile($this->dump_file);

        // The expected census is measured from the dump itself, never typed by hand.
        $skip   = $this->skip_tables();
        $tables = [];
        $rows   = 0;
        foreach ((new RestoreDumpReader($this->dump_file))->inserts() as $batch) {
            if (in_array($batch['table'], $skip, true)) {
                continue;
            }
            $tables[$batch['table']] = true;
            $rows += count($batch['rows']);
        }
        $this->loaded_tables = count($tables);
        $this->loaded_rows   = $rows;
        self::assertSame(self::SEEDED_ROWS, $rows, 'two Organizations, two Clinics, two patients and two logins');
        self::assertSame(4, $this->loaded_tables, 'exactly the four seeded tables carry rows');
        self::assertArrayHasKey($db->table('cpms_patients'), $tables);
        $this->empty_target();
    }

    protected function tearDown(): void {
        foreach ((array) glob($this->dump_file . '*') as $path) {
            if (is_file((string) $path)) {
                unlink((string) $path);
            }
        }
        parent::tearDown();
    }

    /** RB-19: empty prepared target loads with zero DDL, FK order, bindings closed. */
    public function testLoadsIntoEmptyPreparedTablesWithoutDdl(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $org     = $db->table('cpms_organizations');
        $clinic  = $db->table('cpms_clinics');
        $patient = $db->table('cpms_patients');
        $logins  = $db->table('cpms_patient_logins');
        $hash    = (string) get_userdata($this->user_id)->user_pass;

        $ddl            = [];
        $order          = [];
        $updates        = 0;
        $seen           = 0;
        $insert_pattern = '/\AINSERT INTO `([A-Za-z0-9_]+)` \(/';
        $update_pattern = '/\AUPDATE `' . preg_quote($logins, '/') . '` SET state = \'closed\', live_key = NULL, account_key = CASE WHEN kind = \'account\' THEN wp_user_id ELSE NULL END\z/';
        $filter         = static function (string $statement) use (&$ddl, &$order, &$updates, &$seen, $insert_pattern, $update_pattern): string {
            ++$seen;
            if (preg_match('/\A\s*(CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $ddl[] = $statement;
            }
            if (preg_match($insert_pattern, $statement, $match) === 1) {
                $order[] = $match[1];
            }
            if (preg_match($update_pattern, $statement) === 1) {
                ++$updates;
            }
            return $statement;
        };

        $loader = new RestoreTableLoader($db);
        add_filter('query', $filter);
        try {
            $plan   = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);
            $result = $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } finally {
            remove_filter('query', $filter);
        }

        self::assertArrayHasKey('source_version', $plan);
        self::assertArrayHasKey('order', $plan);
        self::assertSame($version, $plan['source_version']);
        self::assertGreaterThan(3, $seen, 'the query spy must observe the loader queries');
        self::assertSame([], $ddl, 'the loader must never issue DDL');
        self::assertSame(1, $updates, 'bindings are closed exactly once');
        self::assertSame(['tables' => $this->loaded_tables, 'rows' => $this->loaded_rows, 'bindings_closed' => 2], $result);

        // Insert order is the real statement order the spy observed.
        $attempted = array_values(array_unique($order));
        $position  = array_flip($attempted);
        self::assertCount(count($this->seeded_tables()), $attempted, 'every seeded table is inserted once');
        self::assertArrayHasKey($org, $position);
        self::assertArrayHasKey($clinic, $position);
        self::assertArrayHasKey($patient, $position);
        self::assertArrayHasKey($logins, $position);
        self::assertLessThan($position[$clinic], $position[$org], 'a referenced parent is inserted before its child');
        self::assertLessThan($position[$patient], $position[$clinic], 'patients follow their Clinic');
        self::assertSame(
            [
                ['kind' => 'binding', 'state' => 'closed', 'live_key' => null, 'account_key' => null],
                ['kind' => 'account', 'state' => 'closed', 'live_key' => null, 'account_key' => (string) $this->user_id],
            ],
            $db->fetchAll('SELECT kind, state, live_key, account_key FROM %i ORDER BY id', [$logins]),
            'every restored binding is closed and account keys are recomputed'
        );
        self::assertSame(
            [['id' => '7', 'first_name' => "O'Brien", 'national_id' => '0012345678']],
            $db->fetchAll('SELECT id, first_name, national_id FROM %i WHERE id = %d', [$patient, 7]),
            'escaped text and the national ID round-trip byte for byte'
        );
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$org]));
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$clinic]));
        self::assertSame($hash, (string) get_userdata($this->user_id)->user_pass, 'password hashes are kept');
        self::assertSame($version, (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]), 'the ledger is read, never rewritten');
    }

    /** RB-20: a non-empty target is refused before the first write. */
    public function testRefusesNonEmptyTargetWithZeroWrites(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $org     = $db->table('cpms_organizations');
        self::assertTrue($db->insert('cpms_organizations', [
            'id' => 99, 'name' => 'occupied', 'slug' => 'occupied', 'status' => 'active',
            'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
        ]));

        $writes = [];
        $reads  = [];
        $filter = static function (string $statement) use (&$writes, &$reads): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            if (preg_match('/\A\s*SELECT\b/i', $statement) === 1) {
                $reads[] = $statement;
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertNotEmpty($reads, 'the spy must see the preflight reads');
        self::assertSame('RESTORE_TARGET_NOT_EMPTY', $failure);
        self::assertSame([], $writes, 'a refusal performs zero writes');
        self::assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$org]), 'the occupied row is untouched');
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table('cpms_clinics')]), 'nothing was loaded');
    }

    /** RB-30: a non-InnoDB table the load writes is refused before the first write. */
    public function testRefusesNonTransactionalEngineWithZeroWrites(): void {
        $db         = App::db();
        $ledger     = $db->table('schema_migrations');
        $version    = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $writes     = [];
        $reads      = [];
        $engine     = '/\ASELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME IN \(/';
        $filter     = static function (string $statement) use (&$writes, &$reads, $engine): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            if (preg_match($engine, $statement) === 1) {
                $reads[] = $statement;
                preg_match_all("/'([^']+)'/", $statement, $names);
                $faked = [];
                foreach ($names[1] as $name) {
                    $faked[] = "SELECT '" . $name . "' AS table_name, 'MyISAM' AS engine";
                }
                return implode(' UNION ALL ', $faked);
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame(1, count($reads), 'the engine lookup is seen exactly once');
        self::assertStringContainsString('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES', $reads[0]);
        self::assertSame('RESTORE_ENGINE_UNSUPPORTED', $failure);
        self::assertSame([], $writes, 'an engine refusal performs zero writes');

        // Positive witness under the SAME fixture and spy: the real engines load.
        $loader = new RestoreTableLoader($db);
        $plan   = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);
        $result = $loader->load($plan, new RestoreDumpReader($this->dump_file));
        self::assertSame(['tables' => $this->loaded_tables, 'rows' => $this->loaded_rows, 'bindings_closed' => 2], $result);
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table('cpms_clinics')]));
    }

    /** RB-31: an engine lookup that answers for no real table refuses as not-prepared. */
    public function testRefusesEngineLookupThatCannotSeeTheTables(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $writes  = [];
        $reads   = [];
        $engine  = '/\ASELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME IN \(/';
        $filter  = static function (string $statement) use (&$writes, &$reads, $engine): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            if (preg_match($engine, $statement) === 1) {
                $reads[] = $statement;
                return 'SELECT \'x\' AS table_name, \'InnoDB\' AS engine';
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame(1, count($reads), 'the engine lookup is seen exactly once');
        self::assertSame('RESTORE_TARGET_NOT_PREPARED', $failure, 'a lookup that cannot see a table refuses as not-prepared');
        self::assertSame([], $writes, 'the not-prepared refusal performs zero writes');
    }

    /** RB-35: the engine rows are matched case-insensitively in BOTH columns. */
    public function testEngineLookupMatchesEitherServerLetterCase(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $writes  = [];
        $reads   = [];
        $engine  = '/\ASELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME IN \(/';
        // MySQL 8 information_schema answers in upper case; MariaDB 11.4 in lower case.
        $filter  = static function (string $statement) use (&$writes, &$reads, $engine): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            if (preg_match($engine, $statement) === 1) {
                $reads[] = $statement;
                preg_match_all("/'([^']+)'/", $statement, $names);
                $faked = [];
                foreach ($names[1] as $name) {
                    $faked[] = "SELECT '" . strtoupper($name) . "' AS TABLE_NAME, 'InnoDB' AS ENGINE";
                }
                return implode(' UNION ALL ', $faked);
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame(1, count($reads), 'the engine lookup is seen exactly once');
        self::assertNull($failure, 'upper-case TABLE_NAME/ENGINE columns still prove InnoDB');
        self::assertSame([], $writes, 'preflight writes nothing');
    }

    /** RB-38: a value-bearing failed insert leaves no NID in the exception, wpdb fields or the error log. */
    public function testFailedInsertLeavesNoNationalIdInDiagnostics(): void
    {
        $db     = App::db();
        $ledger = $db->table('schema_migrations');
        $nid    = '1234567890';
        $log    = sys_get_temp_dir() . '/cpms-nid-log-' . bin2hex(random_bytes(6)) . '.log';

        // Replace the seeded National ID BEFORE preflight, so the plan belongs to these bytes.
        $dump = (string) file_get_contents($this->dump_file);
        $bad  = str_replace("'0012345678'", "'" . $nid . "'", $dump);
        self::assertNotSame($dump, $bad, 'the fixture must actually contain the seeded National ID');
        self::assertTrue(file_put_contents($this->dump_file, $bad) > 0);

        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        $writes = 0;
        $filter = static function (string $statement) use (&$writes): string {
            if (preg_match('/\AINSERT INTO `([A-Za-z0-9_]+)`/', $statement, $match) === 1) {
                ++$writes;
                if (str_ends_with($match[1], 'cpms_patients')) {
                    return 'INSERT INTO `' . $match[1] . '` (`no_such_column`) VALUES (1)';
                }
            }
            return $statement;
        };

        // wpdb::show_errors()/suppress_errors() are SETTERS whose default argument is `true`,
        // so calling them bare would mutate the very setting meant to be snapshotted.
        $show_before     = $db->wpdb()->show_errors;
        $suppress_before = $db->wpdb()->suppress_errors;
        $message         = '';
        $code            = '';
        $ini             = (string) ini_get('error_log');
        add_filter('query', $filter);
        try {
            ini_set('error_log', $log);
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $code    = $exception->getErrorCode();
            $message = $exception->getMessage();
        } finally {
            remove_filter('query', $filter);
            ini_set('error_log', $ini);
        }

        // `$EZSQL_ERROR` is core-owned: `wpdb::print_error()` appends {query, error_str} BEFORE checking
        // `suppress_errors` (class-wpdb.php:1799-1813) and WPCS forbids overriding a WP global. So the
        // entry EXISTS and the loader cannot remove it — the honest assertion is on its content, and
        // the residual risk is stated in the report rather than papered over.
        $ezsql  = isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? $GLOBALS['EZSQL_ERROR'] : [];
        $logged = is_file($log) ? (string) file_get_contents($log) : '';
        if (is_file($log)) {
            unlink($log);
        }

        self::assertGreaterThan(0, $writes, 'the spy must see the INSERTs');
        self::assertSame('RESTORE_LOAD_FAILED', $code);
        self::assertSame('The restore load failed and was rolled back.', $message, 'the message is a constant, never driver text');
        self::assertStringNotContainsString($nid, $message, 'the exception message carries no value');
        self::assertStringNotContainsString($nid, (string) $db->wpdb()->last_query, 'last_query no longer holds the value-bearing SQL');
        self::assertStringNotContainsString($nid, (string) $db->wpdb()->last_error, 'last_error is cleared');
        self::assertNotEmpty($ezsql, 'core must record the failure, otherwise this assertion proves nothing');
        foreach ($ezsql as $entry) {
            self::assertArrayHasKey('error_str', $entry);
            self::assertStringNotContainsString($nid, (string) $entry['error_str'], 'no driver message carries the value');
        }
        self::assertStringNotContainsString($nid, $logged, 'the error log carries no value');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' holds no rows');
        }
        self::assertSame($show_before, $db->wpdb()->show_errors, 'the previous show_errors setting is restored');
        self::assertSame($suppress_before, $db->wpdb()->suppress_errors, 'the previous suppress_errors setting is restored');
    }

    /** RB-45: a REAL duplicate-key failure leaves no national ID in any wpdb diagnostic carrier. */
    public function testRealDuplicateKeyFailureLeavesNoNationalIdInCarriers(): void
    {
        $db     = App::db();
        $ledger = $db->table('schema_migrations');
        $nid    = '1234567890';
        $log    = sys_get_temp_dir() . '/cpms-nid-real-' . bin2hex(random_bytes(6)) . '.log';

        // Bring both patients into clinic 1 with the SAME national ID: the real
        // `u_pat_nid (clinic_id, national_id)` key (migration 0001:83) then refuses the second
        // INSERT with a genuine duplicate-key error. The seeded second patient has no national
        // ID at all (NULL), so it has to be given one before the move can collide.
        $dump = (string) file_get_contents($this->dump_file);
        self::assertSame(1, substr_count($dump, "'0012345678'"), 'the seeded national ID appears once');
        $bad = str_replace("'0012345678'", "'" . $nid . "'", $dump);

        // Every edit below is anchored INSIDE the second patient tuple only: a global replace of
        // `,NULL,` would rewrite unrelated nullable columns in other tables.
        $tuple = strpos($bad, "('8','2',");
        self::assertNotSame(false, $tuple, 'the second patient tuple must exist in the dump');
        $null_at = strpos($bad, ',NULL,', (int) $tuple);
        self::assertNotSame(false, $null_at, 'the second patient must have a NULL national ID to fill');
        $filled = substr_replace($bad, ",'" . $nid . "',", (int) $null_at, strlen(',NULL,'));
        self::assertSame(2, substr_count($filled, "'" . $nid . "'"), 'both patients now carry the same national ID');
        $clinic = substr_replace($filled, "('8','1',", (int) $tuple, strlen("('8','2',"));
        self::assertSame(2, substr_count($clinic, $nid), 'the rewrite touched only the intended bytes');
        self::assertTrue(file_put_contents($this->dump_file, $clinic) > 0);

        // SAVEQUERIES is a constant: it cannot be toggled once defined, so report which it is.
        $savequeries = defined('SAVEQUERIES') && SAVEQUERIES;
        $wpdb        = $db->wpdb();
        if ($savequeries) {
            $wpdb->queries = is_array($wpdb->queries) ? $wpdb->queries : [];
        }

        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        $ez_before      = isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? count($GLOBALS['EZSQL_ERROR']) : 0;
        $queries_before = is_array($wpdb->queries) ? count($wpdb->queries) : 0;
        $ini            = (string) ini_get('error_log');
        $code           = '';
        $message        = '';
        $previous_text  = '';
        $logged         = '';
        try {
            ini_set('error_log', $log);
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $code    = $exception->getErrorCode();
            $message = $exception->getMessage();
            // No value may survive in ANY chained exception, so every link is read, not just the head.
            // (Messages only: a trace's argument rendering depends on zend.exception_ignore_args.)
            for ($link = $exception->getPrevious(); null !== $link; $link = $link->getPrevious()) {
                $previous_text .= $link->getMessage();
            }
        } finally {
            ini_set('error_log', $ini);
            $logged = is_file($log) ? (string) file_get_contents($log) : '';
            if (is_file($log)) {
                unlink($log);
            }
        }

        $ezsql       = isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? $GLOBALS['EZSQL_ERROR'] : [];
        $new_ez      = array_slice($ezsql, $ez_before);
        self::assertNotSame([], $new_ez, 'the load really failed inside the driver (a real error was recorded)');
        $new_queries = is_array($wpdb->queries) ? array_slice($wpdb->queries, $queries_before) : [];

        self::assertSame('RESTORE_LOAD_FAILED', $code, 'a real duplicate key fails the load');
        self::assertSame('The restore load failed and was rolled back.', $message, 'the message stays a constant');
        // Positive witness: the real driver error WAS produced and recorded by core.
        self::assertGreaterThan($ez_before, count($ezsql), 'core recorded the real duplicate-key failure');
        self::assertNotEmpty($new_ez, 'the load added at least one EZSQL_ERROR row');
        foreach ($new_ez as $entry) {
            self::assertArrayHasKey('query', $entry);
            self::assertArrayHasKey('error_str', $entry);
            self::assertStringNotContainsString($nid, (string) $entry['query'], 'no diagnostic query carries the value');
            self::assertStringNotContainsString($nid, (string) $entry['error_str'], 'no driver message carries the value');
            self::assertDoesNotMatchRegularExpression('/\d{10}/', (string) $entry['query'], 'no 10-digit run survives in query');
            self::assertDoesNotMatchRegularExpression('/\d{10}/', (string) $entry['error_str'], 'no 10-digit run survives in error_str');
        }
        self::assertStringNotContainsString($nid, (string) $wpdb->last_query, 'last_query is scrubbed');
        self::assertStringNotContainsString($nid, (string) $wpdb->last_error, 'last_error is scrubbed');
        self::assertStringNotContainsString($nid, (string) $wpdb->func_call, 'func_call is cleared');
        self::assertStringNotContainsString($nid, $message, 'the exception message carries no value');
        self::assertStringNotContainsString($nid, $logged, 'the error log carries no value');
        self::assertStringNotContainsString($nid, (string) wp_json_encode($wpdb->last_result), 'last_result carries no value');
        self::assertStringNotContainsString($nid, $previous_text, 'no chained exception message carries the value');
        // Constant-gated: this branch inspects rows ONLY when the suite defines SAVEQUERIES; when
        // the constant is off there are no rows to inspect and the block is skipped, not vacuous.
        if ($savequeries) {
            self::assertIsArray($wpdb->queries, 'SAVEQUERIES is on, so the queries log is inspectable');
            foreach ($new_queries as $entry) {
                self::assertStringNotContainsString($nid, (string) $entry[0], 'no SAVEQUERIES row carries the value');
            }
        }
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' holds no rows');
        }
    }

    /**
     * RB-46: bytes changed during ONE parsing pass are refused by the PASS digest.
     *
     * Only a single PARSING open is tampered (RestoreDumpTamperingStream). Every path-fingerprint
     * open, and every other parsing pass, serves clean bytes, so `assert_same_file()` and the
     * leading `digest()` gate cannot be the ones that refuse. The refusal is pinned to the pass
     * gate by its exact message, because that message is the only one emitted from the
     * comparison of a streamed pass digest (RestoreTableLoader.php:180).
     */
    public function testRefusesBytesChangedWhileTheInsertingPassReads(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        // Tamper the SECOND row-bearing table's pass: a non-first pass must be checked too.
        RestoreDumpTamperingStream::reset($this->dump_file, 'comment', 1);
        $failure = '';
        $message = '';
        self::assertTrue(stream_wrapper_register('cpmstamper', RestoreDumpTamperingStream::class));
        try {
            $loader->load($plan, new RestoreDumpReader('cpmstamper://dump.sql'));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
            $message = $exception->getMessage();
        } finally {
            stream_wrapper_unregister('cpmstamper');
        }

        self::assertSame(1, RestoreDumpTamperingStream::$tampered, 'exactly one parsing open is tampered');
        self::assertGreaterThanOrEqual(1, RestoreDumpTamperingStream::$parses, 'a parsing open really happened');
        self::assertSame('RESTORE_DUMP_CHANGED', $failure, 'the changed pass is refused');
        self::assertSame('The dump changed while it was being read.', $message, 'the refusal comes from the PASS digest gate');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' is rolled back');
        }

        // Healthy control through the SAME wrapper class, registered again with a clean policy.
        RestoreDumpTamperingStream::reset($this->dump_file, 'clean', -1);
        try {
            self::assertTrue(stream_wrapper_register('cpmstamper', RestoreDumpTamperingStream::class));
            $result = $loader->load($plan, new RestoreDumpReader('cpmstamper://dump.sql'));
        } finally {
            if (in_array('cpmstamper', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('cpmstamper');
            }
        }
        self::assertSame(0, RestoreDumpTamperingStream::$tampered, 'the clean policy tampers nothing');
        self::assertSame(['tables' => $this->loaded_tables, 'rows' => $this->loaded_rows, 'bindings_closed' => 2], $result);
    }

    /**
     * RB-46b: the READER-2 case — only an EARLIER table's parsing pass is tampered.
     *
     * Every later open, including the LAST pass the old code compared, is clean, so the previous
     * "compare only the final pass" logic would have accepted this load. The per-pass gate
     * refuses it.
     */
    public function testRefusesChangeSeenOnlyByAnEarlierTablePass(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);
        self::assertGreaterThan(1, count($plan['order']), 'the plan must have more than one table to have an "earlier" pass');

        RestoreDumpTamperingStream::reset($this->dump_file, 'comment', 0);
        $failure = '';
        $message = '';
        self::assertTrue(stream_wrapper_register('cpmsearly', RestoreDumpTamperingStream::class));
        try {
            $loader->load($plan, new RestoreDumpReader('cpmsearly://dump.sql'));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
            $message = $exception->getMessage();
        } finally {
            stream_wrapper_unregister('cpmsearly');
        }

        self::assertSame(1, RestoreDumpTamperingStream::$tampered, 'exactly one earlier pass is tampered');
        self::assertSame('RESTORE_DUMP_CHANGED', $failure, 'an earlier changed pass is refused too');
        self::assertSame('The dump changed while it was being read.', $message, 'the per-pass gate is what refuses');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' is rolled back');
        }
    }

    /**
     * RB-47: a short read that reports a clean EOF is refused by the PASS digest.
     *
     * The cut lands exactly on a `;`, so every statement reaches the reader complete and no
     * syntax refusal can fire; the pass simply ends early with an orderly EOF.
     */
    public function testRefusesShortReadThatLooksLikeCleanEof(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        $file_size = (int) filesize($this->dump_file);
        RestoreDumpTamperingStream::reset($this->dump_file, 'cut', 0);
        $failure = '';
        $message = '';
        self::assertTrue(stream_wrapper_register('cpmsshort', RestoreDumpTamperingStream::class));
        try {
            $loader->load($plan, new RestoreDumpReader('cpmsshort://dump.sql'));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
            $message = $exception->getMessage();
        } finally {
            stream_wrapper_unregister('cpmsshort');
        }

        self::assertSame(1, RestoreDumpTamperingStream::$tampered, 'exactly one parsing open is cut');
        self::assertLessThan($file_size, RestoreDumpTamperingStream::$tampered_bytes, 'the wrapper really served fewer bytes than the file holds');
        self::assertSame('RESTORE_DUMP_CHANGED', $failure, 'an orderly short read is still a different dump');
        self::assertSame('The dump changed while it was being read.', $message, 'the PASS digest gate refuses, not a path or syntax gate');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' is rolled back');
        }

        // Healthy control through the SAME wrapper class, registered again with a clean policy.
        RestoreDumpTamperingStream::reset($this->dump_file, 'clean', -1);
        try {
            self::assertTrue(stream_wrapper_register('cpmsshort', RestoreDumpTamperingStream::class));
            $result = $loader->load($plan, new RestoreDumpReader('cpmsshort://dump.sql'));
        } finally {
            if (in_array('cpmsshort', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('cpmsshort');
            }
        }
        self::assertSame(['tables' => $this->loaded_tables, 'rows' => $this->loaded_rows, 'bindings_closed' => 2], $result);

        // Real-file control kept: the same plan and fixture load with no wrapper at all.
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertGreaterThan(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' holds the control rows');
        }
    }

    /** RB-39: a plan built from one dump refuses a different, stable dump with zero writes. */
    public function testRefusesPlanAppliedToADifferentDump(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        // A different but perfectly valid dump: one extra Organization row.
        $other  = $this->dump_file . '.other';
        $dump   = (string) file_get_contents($this->dump_file);
        $marker = "-- ---- table: " . $db->table('cpms_organizations') . " ----\n";
        self::assertStringContainsString($marker, $dump, 'the dump must carry the marker this test relies on');
        $rewritten = str_replace(
            $marker,
            $marker . "INSERT INTO `" . $db->table('cpms_organizations') . "` (`id`,`name`,`slug`,`status`,`created_at`,`updated_at`) VALUES ('9','Org C','org-c','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000');\n",
            $dump
        );
        self::assertNotSame($dump, $rewritten);
        self::assertTrue(file_put_contents($other, $rewritten) > 0);

        $writes = [];
        $filter = static function (string $statement) use (&$writes): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            $loader->load($plan, new RestoreDumpReader($other));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
            if (is_file($other)) {
                unlink($other);
            }
        }

        self::assertSame('RESTORE_DUMP_CHANGED', $failure, 'the plan is bound to the bytes it was built from');
        self::assertSame([], $writes, 'a mismatched dump performs zero writes');
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table('cpms_organizations')]), 'nothing was loaded');
    }

    /** RB-40: a dump that loses planned rows after preflight is refused by the digest, zero rows. */
    public function testRefusesWhenTheDumpLosesRowsAfterPreflight(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        // The dumper writes one tuple per line joined by ",\n": deleting one whole tuple shrinks the census.
        $dump  = (string) file_get_contents($this->dump_file);
        $lines = explode("\n", $dump);
        $keep  = [];
        $lost  = 0;
        foreach ($lines as $line) {
            if (0 === $lost && str_starts_with($line, "('8',")) {
                ++$lost;
                $keep[count($keep) - 1] = rtrim($keep[count($keep) - 1], ',');
                continue;
            }
            $keep[] = $line;
        }
        self::assertSame(1, $lost, 'exactly one planned patient tuple must be removable');
        $shrunk = implode("\n", $keep);
        self::assertNotSame($dump, $shrunk, 'the fixture must actually shrink by one planned row');
        self::assertTrue(file_put_contents($this->dump_file, $shrunk) > 0);

        $writes = [];
        $filter = static function (string $statement) use (&$writes): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame('RESTORE_DUMP_CHANGED', $failure, 'shrunk bytes are not the bytes the plan was built from');
        self::assertSame([], $writes, 'the refusal happens before the first write');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' holds no rows');
        }
    }

    /** RB-41: an INSERT for a table the dump never declares is refused as a syntax fault. */
    public function testRefusesInsertForATableTheDumpNeverDeclares(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);

        // The dumper writes a marker for every cpms table, so a real table is always declared:
        // the only honest "never declares" target is a name the dump cannot contain.
        $undeclared = $db->table('cpms_never_declared');
        self::assertNotContains($undeclared, (new RestoreDumpReader($this->dump_file))->tables(), 'the dump must not declare this table');

        $dump = (string) file_get_contents($this->dump_file);
        $tail = "\nINSERT INTO `" . $undeclared . "` (`id`) VALUES ('1');\n";
        self::assertTrue(file_put_contents($this->dump_file, $dump . $tail) > 0);

        $writes = [];
        $filter = static function (string $statement) use (&$writes): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            return $statement;
        };
        $failure = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame('RESTORE_DUMP_SYNTAX', $failure, 'an undeclared table insert is refused, never silently ignored');
        self::assertSame([], $writes, 'the refusal happens before any write');
    }

    /** RB-42: a plan whose census overstates the dump is rolled back, never committed short. */
    public function testRollsBackWhenTheLoadedCensusIsShort(): void
    {
        $db       = App::db();
        $ledger   = $db->table('schema_migrations');
        $version  = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $patients = $db->table('cpms_patients');
        $loader   = new RestoreTableLoader($db);
        $plan     = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        self::assertArrayHasKey($patients, $plan['rows'], 'preflight must census the patient table');
        ++$plan['rows'][$patients];

        $failure = null;
        try {
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        }

        self::assertSame('RESTORE_LOAD_INCOMPLETE', $failure, 'a short census refuses instead of committing');
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' is rolled back to zero rows');
        }
    }

    /** RB-43: a failed transaction control reports an UNKNOWN state, and the retry is refused as non-empty. */
    public function testReportsUnknownStateWhenRollbackFailsAndRefusesARetry(): void
    {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $logins  = $db->table('cpms_patient_logins');
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        $rollbacks = 0;
        $injected  = 0;
        // The exact statement the loader issues, anchored: one injection, counted once below.
        $close     = 'UPDATE `' . $logins . '` SET state = \'closed\', live_key = NULL, account_key = CASE WHEN kind = \'account\' THEN wp_user_id ELSE NULL END';
        $filter    = static function (string $statement) use (&$rollbacks, &$injected, $close, $logins): string {
            // The load must actually fail for the rollback path to be reached at all.
            if ($close === $statement) {
                ++$injected;
                return 'UPDATE `' . $logins . '` SET cpms_no_such_column = 1';
            }
            // This filter runs at priority 10, before the bootstrap's priority-20 savepoint
            // rewrite, so the undo still carries its cpms marker; the rewritten shape is
            // matched too, so the count cannot silently depend on that ordering.
            if ('/*cpms*/ ROLLBACK' === $statement || preg_match('/\AROLLBACK TO SAVEPOINT /', $statement) === 1) {
                ++$rollbacks;
                return 'ROLLBACK TO SAVEPOINT cpms_no_such_savepoint';
            }
            return $statement;
        };

        $failure = null;
        $message = '';
        add_filter('query', $filter);
        try {
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
            $message = $exception->getMessage();
        } finally {
            remove_filter('query', $filter);
        }

        self::assertSame(1, $injected, 'the injected UPDATE fault is issued exactly once');
        self::assertSame(1, $rollbacks, 'the failing ROLLBACK is issued exactly once');
        self::assertSame('RESTORE_LOAD_UNKNOWN_STATE', $failure, 'a failed rollback is never reported as a completed rollback');
        self::assertSame('The restore load failed and the transaction state is unknown; reconciliation is required.', $message, 'the message states the state is unknown');
        self::assertStringNotContainsString('was rolled back', $message, 'the message claims no rollback it cannot prove');

        // The injected failed rollback leaves rows visible in this test's still-open outer
        // transaction; the subsequent emptiness check rejects a retry on this connection. This
        // does not prove production durability or a retry from a fresh connection.
        $rows  = (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$logins]);
        $retry = null;
        self::assertGreaterThan(0, $rows, 'the unknown-state load leaves rows behind, so a retry must be refused');
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), $version);
        } catch (BackupException $exception) {
            $retry = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_TARGET_NOT_EMPTY', $retry, 'a retry after an unknown state is refused by the emptiness check');
    }

    /** RB-21: schema-version mismatches are refused on both sides, with zero writes. */
    public function testRefusesSchemaVersionMismatchWithZeroWrites(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);

        $writes = [];
        $reads  = [];
        $filter = static function (string $statement) use (&$writes, &$reads): string {
            if (preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
            if (preg_match('/\A\s*SELECT\b/i', $statement) === 1) {
                $reads[] = $statement;
            }
            return $statement;
        };

        // (a) The caller's expected backup version disagrees with the dump ledger.
        $declared = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file), '1999_01_01_0000');
        } catch (BackupException $exception) {
            $declared = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
        }

        // (b) The dump ledger disagrees with the target ledger (dump copied, bytes changed).
        $other  = $this->dump_file . '.other';
        $source = (string) file_get_contents($this->dump_file);
        self::assertNotSame('', $source);
        self::assertTrue(copy($this->dump_file, $other));
        self::assertNotFalse(file_put_contents($other, str_replace("'{$version}'", "'1999_01_01_0000'", $source)));
        $dump_side = null;
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($other));
        } catch (BackupException $exception) {
            $dump_side = $exception->getErrorCode();
        } finally {
            unlink($other);
        }

        // (c) The target ledger loses its head row while the dump keeps it.
        self::assertTrue($db->query('DELETE FROM %i WHERE version = %s', [$ledger, $version]));
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM %i WHERE version = %s', [$ledger, $version]),
            'the head ledger row is gone'
        );
        $target_side = null;
        add_filter('query', $filter);
        try {
            (new RestoreTableLoader($db))->preflight(new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $target_side = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
            // Fixture repair happens with the spy detached: it is not loader traffic.
            self::assertTrue($db->query('INSERT INTO %i (version, description, applied_at) VALUES (%s, %s, %s)', [$ledger, $version, 'fixture', '2026-01-01 00:00:00.000']));
        }

        self::assertNotEmpty($reads, 'the spy must see the preflight reads');
        self::assertSame('RESTORE_SCHEMA_MISMATCH', $declared, 'the expected backup version is enforced');
        self::assertSame('RESTORE_SCHEMA_MISMATCH', $dump_side, 'the dump ledger is compared with the target ledger');
        self::assertSame('RESTORE_SCHEMA_MISMATCH', $target_side, 'a moved target ledger refuses an unchanged dump');
        self::assertSame([], $writes, 'a mismatch performs zero writes');
    }

    /** RB-22: a failure midway leaves the documented state — zero rows, no DDL. */
    public function testFailureMidwayLeavesNoPartialRows(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $logins  = $db->table('cpms_patient_logins');
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);

        $ddl      = [];
        $seen     = 0;
        $injected = 0;
        $strict   = $db->is_strict();
        $close    = 'UPDATE `' . $logins . '` SET state = \'closed\', live_key = NULL, account_key = CASE WHEN kind = \'account\' THEN wp_user_id ELSE NULL END';
        $filter   = static function (string $statement) use (&$ddl, &$seen, &$injected, $logins, $close): string {
            ++$seen;
            if (preg_match('/\A\s*(CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $ddl[] = $statement;
            }
            // Exact anchored match: the fault is injected once and its hit count is asserted outside.
            if ($close === $statement) {
                ++$injected;
                return 'UPDATE `' . $logins . '` SET cpms_no_such_column = 1';
            }
            return $statement;
        };
        $failure    = null;
        $suppressed = $db->wpdb()->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $loader->load($plan, new RestoreDumpReader($this->dump_file));
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
            $db->wpdb()->suppress_errors($suppressed);
        }

        self::assertGreaterThan(3, $seen, 'the spy must see the load queries');
        self::assertSame(1, $injected, 'the injected UPDATE fault is issued exactly once');
        self::assertSame([], $ddl, 'a failing load still issues no DDL');
        self::assertSame('RESTORE_LOAD_FAILED', $failure);
        foreach (['cpms_clinics', 'cpms_organizations', 'cpms_patients', 'cpms_patient_logins'] as $short) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table($short)]), $short . ' holds no partial rows');
        }
        // The single-row audit guard seeded by migration 0031 lives in a table the
        // loader never inserts into: its presence is not a non-empty clinical target.
        self::assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$db->table('cpms_audit_seq')]));
        self::assertSame($strict, $db->is_strict(), 'strict mode is restored');
    }

    /** RB-23: multi-Clinic data keeps Clinic links and the Organization link. */
    public function testMultiClinicDataKeepsClinicAndOrganizationLinks(): void {
        $db      = App::db();
        $ledger  = $db->table('schema_migrations');
        $version = (string) $db->fetchValue('SELECT MAX(version) FROM %i', [$ledger]);
        $org     = $db->table('cpms_organizations');
        $clinic  = $db->table('cpms_clinics');
        $patient = $db->table('cpms_patients');
        $logins  = $db->table('cpms_patient_logins');
        $loader  = new RestoreTableLoader($db);
        $plan    = $loader->preflight(new RestoreDumpReader($this->dump_file), $version);
        $result  = $loader->load($plan, new RestoreDumpReader($this->dump_file));

        self::assertSame(['tables' => $this->loaded_tables, 'rows' => $this->loaded_rows, 'bindings_closed' => 2], $result);
        self::assertSame(
            [
                ['id' => '7', 'clinic_id' => '1'],
                ['id' => '8', 'clinic_id' => '2'],
            ],
            array_map(
                static fn (array $row): array => ['id' => $row['id'], 'clinic_id' => $row['clinic_id']],
                $db->fetchAll('SELECT id, clinic_id FROM %i ORDER BY id', [$patient])
            ),
            'each patient keeps its Clinic link'
        );
        self::assertSame(
            [
                ['id' => '1', 'organization_id' => '1'],
                ['id' => '2', 'organization_id' => '2'],
            ],
            array_map(
                static fn (array $row): array => ['id' => $row['id'], 'organization_id' => $row['organization_id']],
                $db->fetchAll('SELECT id, organization_id FROM %i ORDER BY id', [$clinic])
            ),
            'every Clinic keeps its own Organization link'
        );
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM %i', [$org]));
        self::assertSame(
            [['state' => 'closed', 'live_key' => null]],
            $db->fetchAll('SELECT state, live_key FROM %i WHERE kind = %s', [$logins, 'binding'])
        );
    }

    /** @return list<string> نام کامل جدول‌های ردیف‌دار که fixture می‌سازد */
    private function seeded_tables(): array {
        $db = App::db();

        return array_map(
            static fn (string $short): string => $db->table($short),
            ['cpms_organizations', 'cpms_clinics', 'cpms_patients', 'cpms_patient_logins']
        );
    }

    /** همهٔ جدول‌های CPMS هدف را (جز ledger و guard تک‌ردیفی) خالی می‌کند. */
    private function empty_target(): void {
        $db = App::db();
        self::assertTrue($db->query('SET FOREIGN_KEY_CHECKS = 0'));
        foreach ($this->target_tables() as $table) {
            if (in_array($table, $this->skip_tables(), true)) {
                continue;
            }
            self::assertTrue($db->query('DELETE FROM %i', [$table]));
        }
        self::assertTrue($db->query('SET FOREIGN_KEY_CHECKS = 1'));
    }

    /** @return list<string> نام کامل جدول‌های مستثنا (ledger + guard تک‌ردیفی) */
    private function skip_tables(): array {
        $db = App::db();

        return array_map(static fn (string $short): string => $db->table($short), RestoreTableLoader::SKIP_TABLES);
    }

    /** @return list<string> */
    private function target_tables(): array {
        $db     = App::db();
        $prefix = $db->table('');
        $rows   = $db->fetch_all_strict(
            'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            [$db->esc_like($prefix) . '%']
        );
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = (string) $row['table_name'];
        }
        sort($tables);

        return $tables;
    }
}

/**
 * Test-only stream wrapper that tampers with ONE chosen parsing open.
 *
 * `hash_file()` (the path fingerprint) and the reader's parsing `fopen()` both arrive here as
 * a plain `stream_open`, so an open counter alone cannot tell them apart. `open()` always
 * calls `is_file()`/`is_readable()` first, which hit `url_stat()`, and `hash_file()` never
 * does — so after a `url_stat()` the next-but-one open is the parsing open. That is the only
 * reliable discriminator from inside a wrapper (OBSERVED: RestoreDumpReader::open()).
 */
final class RestoreDumpTamperingStream {
    public static string $path = '';
    /** clean = serve the file, comment = append a legal comment line, cut = end on a `;`. */
    public static string $mode = 'clean';
    /** 0-based index of the PARSING open to tamper; -1 tampers nothing. */
    public static int $target_parse = -1;
    /** Bytes served by the ONE tampered open only, not the sum over every open. */
    public static int $tampered_bytes = 0;
    public static int $opens          = 0;
    public static int $parses         = 0;
    public static int $tampered       = 0;
    public static int $tampered_open  = -1;
    public static int $served         = 0;

    /** Static: PHP may build a NEW wrapper object per stream operation, so arming cannot be per instance. */
    public static int $armed = 0;
    private string $bytes    = '';
    private int $pos         = 0;

    public static function reset(string $path, string $mode, int $target_parse): void {
        self::$path           = $path;
        self::$mode           = $mode;
        self::$target_parse   = $target_parse;
        self::$opens          = 0;
        self::$parses         = 0;
        self::$tampered       = 0;
        self::$tampered_open  = -1;
        self::$tampered_bytes = 0;
        self::$served         = 0;
        self::$armed          = 0;
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array {
        $size = (int) filesize(self::$path);
        // `is_file()`/`is_readable()` stat the path; the decision already made by PHP 8.3's
        // `php_hash_do_hash()` (ext/hash/hash.c) means `hash_file()` opens WITHOUT a stat. So a
        // stat is followed by exactly one fingerprint open and one parsing open: arm for that.
        self::$armed = 2;

        return ['size' => $size, 'mode' => 0100644, 'mtime' => 0, 'atime' => 0, 'ctime' => 0];
    }

    /** @param array<string, mixed> $options */
    public function stream_open(string $path, string $mode, int $options, ?array &$opened_path): bool {
        $index = self::$opens;
        ++self::$opens;
        $is_parse = false;
        if (self::$armed > 0) {
            --self::$armed;
            if (0 === self::$armed) {
                $is_parse = true;
                ++self::$parses;
            }
        }
        $bytes = (string) file_get_contents(self::$path);
        if ($is_parse && self::$parses - 1 === self::$target_parse && 'clean' !== self::$mode) {
            ++self::$tampered;
            self::$tampered_open = $index;
            if ('comment' === self::$mode) {
                // The leading newline makes the comment's prefix blank, which is what the reader
                // accepts; without it the refusal would be RESTORE_DUMP_UNSUPPORTED_STATEMENT.
                $bytes .= "\n-- tampered mid-pass\n";
            } else {
                $marker = strpos($bytes, 'cpms_patients');
                $cut    = false === $marker ? false : strpos($bytes, ';', (int) strpos($bytes, "\n", (int) $marker));
                $bytes  = false === $cut ? '' : substr($bytes, 0, (int) $cut + 1);
            }
        }
        $this->bytes = $bytes;
        $this->pos   = 0;
        self::$served += strlen($bytes);
        if ($is_parse && self::$parses - 1 === self::$target_parse) {
            self::$tampered_bytes = strlen($bytes);
        }

        return true;
    }

    public function stream_read(int $count): string {
        // Position-based: never rely on a handle pointer the wrapper cannot rewind.
        $chunk       = (string) substr($this->bytes, $this->pos, $count);
        $this->pos  += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool {
        return $this->pos >= strlen($this->bytes);
    }

    public function stream_close(): void {
    }
}

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

        $ddl    = [];
        $seen   = 0;
        $strict = $db->is_strict();
        $filter = static function (string $statement) use (&$ddl, &$seen, $logins): string {
            ++$seen;
            if (preg_match('/\A\s*(CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                $ddl[] = $statement;
            }
            if (str_starts_with($statement, 'UPDATE `' . $logins . '` SET state = \'closed\'')) {
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

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Backup\RestoreDumpReader;
use ClinicCore\Infrastructure\Backup\BackupException;
use PHPUnit\Framework\TestCase;

/**
 * RESTORE-LOADER-NO-DDL — خوانندهٔ dump (بدون DB).
 *
 * الگوی سبزِ نزدیک: `tests/Unit/BackupManifestTest.php:14-40` (fixture خالص،
 * بدون WordPress، assertهای بیرون از callback).
 */
final class RestoreDumpReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/cpms-reader-' . bin2hex(random_bytes(6)) . '.sql';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /** RB-24: dumper-style escaping round-trips byte for byte; DDL is skipped. */
    public function testDecodesDumperEscapesAndSkipsDdl(): void
    {
        $sql = "-- CPMS consistent backup dump\n"
            . "-- Created: fixture\n"
            . "-- Tables: 1\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "\n"
            . "-- ---- table: cpms_patients ----\n"
            . "DROP TABLE IF EXISTS `cpms_patients`;\n"
            . "CREATE TABLE `cpms_patients` (\n  `id` BIGINT UNSIGNED NOT NULL\n);\n"
            . ";\n"
            . "\n"
            . "INSERT INTO `cpms_patients` (`id`,`note`,`national_id`) VALUES\n"
            . "(1,'it\\'s \\\\ fine','0012345678'),\n"
            . "(2,'line\\nbreak\\0end\\r\\Z',NULL);\n"
            . "\n"
            . "SET FOREIGN_KEY_CHECKS = 1;\n"
            . "-- end of cpms dump\n";
        self::assertTrue(file_put_contents($this->file, $sql) > 0);

        $reader = new RestoreDumpReader($this->file);
        self::assertSame(['cpms_patients'], $reader->tables());
        $batches = iterator_to_array($reader->inserts(), false);

        self::assertCount(1, $batches);
        self::assertArrayHasKey('rows', $batches[0]);
        self::assertSame(['id', 'note', 'national_id'], $batches[0]['columns']);
        self::assertCount(2, $batches[0]['rows']);
        self::assertSame("it's \\ fine", $batches[0]['rows'][0]['note']);
        self::assertSame('0012345678', $batches[0]['rows'][0]['national_id']);
        self::assertSame("line\nbreak\0end\r\x1a", $batches[0]['rows'][1]['note']);
        self::assertArrayHasKey('national_id', $batches[0]['rows'][1]);
        self::assertNull($batches[0]['rows'][1]['national_id']);
        self::assertSame(['id', 'note', 'national_id'], array_keys($batches[0]['rows'][0]), 'every declared column is present');
        self::assertSame('1', $batches[0]['rows'][0]['id'], 'numbers stay strings, as the dumper writes them');
        self::assertSame('cpms_patients', $batches[0]['table']);
    }

    /** RB-25: an unknown statement or escape refuses instead of guessing. */
    public function testRefusesUnsupportedStatementAndEscape(): void
    {
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nREPLACE INTO `cpms_patients` (`id`) VALUES (1);\n") > 0);
        $statement = null;
        try {
            iterator_to_array((new RestoreDumpReader($this->file))->inserts(), false);
        } catch (BackupException $exception) {
            $statement = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_UNSUPPORTED_STATEMENT', $statement);

        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`note`) VALUES ('a\\qb');\n") > 0);
        $escape = null;
        try {
            iterator_to_array((new RestoreDumpReader($this->file))->inserts(), false);
        } catch (BackupException $exception) {
            $escape = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_UNSUPPORTED_ESCAPE', $escape);

        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1 -- trailing\n);\n") > 0);
        $inline = null;
        try {
            iterator_to_array((new RestoreDumpReader($this->file))->inserts(), false);
        } catch (BackupException $exception) {
            $inline = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_UNSUPPORTED_STATEMENT', $inline);
    }
}

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

    /** RB-26: a single statement above the bound refuses BEFORE it is yielded. */
    public function testRefusesStatementAboveTheBoundBeforeYieldingIt(): void
    {
        $filler = str_repeat('a', 64);
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`note`) VALUES ('" . $filler . "');\n") > 0);
        $size = null;
        try {
            iterator_to_array((new RestoreDumpReader($this->file, 40))->inserts(), false);
        } catch (BackupException $exception) {
            $size = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_SIZE_EXCEEDED', $size);

        // Positive witness under the SAME fixture: a bound above the real size reads it.
        $reader = new RestoreDumpReader($this->file, 4096);
        self::assertSame(['cpms_patients'], $reader->tables());
        $batches = iterator_to_array($reader->inserts(), false);
        self::assertCount(1, $batches);
        self::assertSame($filler, $batches[0]['rows'][0]['note']);
    }

    /** RB-27: an EOF comment without a trailing newline terminates instead of rewinding. */
    public function testTerminatesOnEofCommentWithoutNewline(): void
    {
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\n#no trailing newline") > 0);
        $error  = null;
        $tables = [];
        try {
            $tables = (new RestoreDumpReader($this->file))->tables();
        } catch (BackupException $exception) {
            $error = $exception->getErrorCode();
        } catch (\Throwable $throwable) {
            $error = get_class($throwable);
        }
        self::assertSame(['cpms_patients'], $tables, 'the marker before the unterminated comment survives');
        self::assertNull($error, 'an EOF comment is not an error and must not loop');
    }

    /** RB-32: the dash-form EOF comment without a newline ends the read instead of rewinding. */
    public function testTerminatesOnDashEofCommentWithoutNewline(): void
    {
        $sql = "-- ---- table: cpms_patients ----\n-- eof comment";
        self::assertTrue(file_put_contents($this->file, $sql) > 0);
        $error   = null;
        $tables  = [];
        $batches = 0;
        try {
            $reader  = new RestoreDumpReader($this->file);
            $tables  = $reader->tables();
            $batches = count(iterator_to_array($reader->inserts(), false));
        } catch (BackupException $exception) {
            $error = $exception->getErrorCode();
        }
        self::assertSame(['cpms_patients'], $tables, 'the marker before the dash-form EOF comment survives');
        self::assertSame(0, $batches, 'a comment-only tail yields no batch');
        self::assertNull($error, 'a dash-form EOF comment is not an error');
    }

    /** RB-33: an empty dump is a legal zero-table, zero-row read, not a failure. */
    public function testEmptyDumpYieldsNothingInsteadOfFailing(): void
    {
        self::assertSame(0, file_put_contents($this->file, ''), 'the fixture is a zero-byte dump');
        $reader = new RestoreDumpReader($this->file);
        self::assertSame([], $reader->tables(), 'an empty dump declares no table');
        self::assertSame([], iterator_to_array($reader->inserts(), false), 'an empty dump yields no batch');
        // Positive witness at the same instant: a marker-only dump is still read.
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\n") > 0);
        self::assertSame(['cpms_patients'], (new RestoreDumpReader($this->file))->tables());
    }

    /** RB-34: fgets()=false with feof()=false is a read error, never a clean end of dump. */
    public function testRefusesReadErrorThatIsNotEof(): void
    {
        $sink    = sys_get_temp_dir() . '/cpms-reader-sink-' . bin2hex(random_bytes(6)) . '.sql';
        $line    = null;
        $failure = null;
        self::assertTrue(file_put_contents($this->file, "SET NAMES utf8mb4;\n") > 0);
        // Small bound (the helper's fgets buffer is bound + 1), so the test allocates 65 bytes.
        $reader = new RestoreDumpReader($this->file, 64);
        $method = new \ReflectionMethod(RestoreDumpReader::class, 'read_line');
        $method->setAccessible(true);

        $readable = fopen($this->file, 'rb');
        self::assertIsResource($readable);
        // Write-only handle on its own file: fgets() fails with EBADF while the stream is not at EOF.
        $write_only = fopen($sink, 'wb');
        self::assertIsResource($write_only);

        $previous = set_error_handler(static fn (): bool => true);
        try {
            $line = $method->invoke($reader, $readable);
            try {
                $method->invoke($reader, $write_only);
            } catch (BackupException $exception) {
                $failure = $exception->getErrorCode();
            }
        } finally {
            set_error_handler($previous);
            fclose($readable);
            fclose($write_only);
            if (is_file($sink)) {
                unlink($sink);
            }
        }

        self::assertSame("SET NAMES utf8mb4;\n", $line, 'the helper returns a real line unchanged');
        self::assertSame('RESTORE_DUMP_UNREADABLE', $failure, 'an unreadable stream refuses instead of ending the dump');
    }

    /** RB-36: an unterminated comment longer than the read bound refuses; the next line is never parsed. */
    public function testRefusesOverLongCommentInsteadOfParsingTheNextLine(): void
    {
        $long = '-- ' . str_repeat('x', 200);
        // Line 2 looks exactly like a loadable batch; it must never be reached.
        $sql = "-- ---- table: cpms_patients ----\n" . $long . "\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n";
        self::assertTrue(file_put_contents($this->file, $sql) > 0);

        $failure = null;
        $batches = [];
        try {
            $batches = iterator_to_array((new RestoreDumpReader($this->file, 64))->inserts(), false);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_SIZE_EXCEEDED', $failure, 'an over-long comment is refused, not cleared');
        self::assertSame([], $batches, 'the INSERT after the over-long comment is never parsed');

        // Positive witness under the SAME bound: the same shape with a short comment loads.
        $short = "-- ---- table: cpms_patients ----\n-- ok\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n";
        self::assertTrue(file_put_contents($this->file, $short) > 0);
        $loaded = iterator_to_array((new RestoreDumpReader($this->file, 64))->inserts(), false);
        self::assertCount(1, $loaded);
        self::assertSame('cpms_patients', $loaded[0]['table']);
    }

    /** RB-37: the digest is the content fingerprint the plan is bound to. */
    public function testDigestChangesWithTheBytesAndSurvivesReReads(): void
    {
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n") > 0);
        $first = (new RestoreDumpReader($this->file))->digest();
        self::assertSame(64, strlen($first), 'a hex SHA-256 digest');
        self::assertSame($first, (new RestoreDumpReader($this->file))->digest(), 'the same bytes give the same digest');

        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1),(2);\n") > 0);
        self::assertNotSame($first, (new RestoreDumpReader($this->file))->digest(), 'different bytes give a different digest');
    }

    /**
     * RB-44: a mid-dump read failure is refused, proven through the PUBLIC api.
     *
     * PHP instantiates the wrapper class itself (no constructor arguments are possible), so the
     * shared configuration is static — but every fopen() gets its own buffer and its own failure
     * state. The fingerprint pass is the first open and always succeeds; only a later open may
     * fail, so a refusal here can never be a digest failure wearing a read-failure mask.
     */
    public function testRefusesMidDumpReadFailureThroughPublicApi(): void
    {
        $wrapper = get_class(new class () {
            public static string $bytes     = '';
            public static bool $fail_reads  = false;
            public static int $opens        = 0;
            public static int $failures     = 0;
            public static int $serve        = 0;

            /** @var resource|null */
            private $handle = null;
            private bool $broken = false;
            private bool $served = false;

            /** @param array<string, mixed> $options */
            public function stream_open(string $path, string $mode, int $options, ?array &$opened_path): bool
            {
                $handle = fopen('php://memory', 'w+b');
                if (false === $handle) {
                    return false;
                }
                fwrite($handle, self::$bytes);
                rewind($handle);
                $this->handle = $handle;
                $this->broken = self::$fail_reads && self::$opens >= 1;
                ++self::$opens;

                return true;
            }

            /** @return string|false */
            public function stream_read(int $count)
            {
                // `false` is a read error; '' would be indistinguishable from "no data yet".
                if ($this->broken) {
                    if ($this->served) {
                        ++self::$failures;
                        return false;
                    }
                    // Serve only $serve bytes, so the failure lands with bytes still unread.
                    $this->served = true;
                    return substr(self::$bytes, 0, self::$serve);
                }

                return is_resource($this->handle) ? (string) fread($this->handle, $count) : false;
            }

            public function stream_eof(): bool
            {
                // The broken stream never claims EOF, so its failure cannot pass as the dump's end.
                return !$this->broken && is_resource($this->handle) && feof($this->handle);
            }

            /** @return array<string, int> */
            public function url_stat(string $path, int $flags): array
            {
                return ['size' => strlen(self::$bytes), 'mode' => 0100644, 'mtime' => 0, 'atime' => 0, 'ctime' => 0];
            }

            public function stream_close(): void
            {
                if (is_resource($this->handle)) {
                    fclose($this->handle);
                }
            }
        });

        $first  = "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n";
        $dump   = $first . "INSERT INTO `cpms_patients` (`id`) VALUES (2);\n";
        // The broken pass stops after the first whole statement: the failure is genuinely mid-dump.
        $served = $first;
        self::assertLessThan(strlen($dump), strlen($served), 'the broken pass leaves bytes unread');
        $read = static function (bool $fail) use ($wrapper, $dump, $served): array {
            $wrapper::$bytes      = $dump;
            $wrapper::$fail_reads = $fail;
            $wrapper::$opens      = 0;
            $wrapper::$failures   = 0;
            $wrapper::$serve      = strlen($served);
            self::assertTrue(stream_wrapper_register('cpmsbroken', $wrapper));
            try {
                return (new RestoreDumpReader('cpmsbroken://dump.sql'))->tables();
            } finally {
                stream_wrapper_unregister('cpmsbroken');
            }
        };

        // Positive witness: the same wrapper serves the whole dump when nothing fails.
        self::assertSame(['cpms_patients'], $read(false), 'the wrapper path works when the stream does not fail');

        // The broken pass serves its first chunk, then fails instead of reporting EOF.
        $failure = null;
        $seen    = [];
        try {
            $seen = $read(true);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        }
        self::assertGreaterThan(0, $wrapper::$failures, 'the wrapper really failed a read, so the refusal is caused by it');
        self::assertSame([], $seen, 'no table list is returned from a dump that could not be read to the end');
        self::assertSame('RESTORE_DUMP_UNREADABLE', $failure, 'the public api refuses a stream that fails mid-dump');
    }

    /** RB-28: a dump truncated mid-statement refuses instead of being read as a clean end. */
    public function testRefusesTruncatedDumpInsteadOfReadingItAsEof(): void
    {
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`note`) VALUES ('unterminated") > 0);
        $truncated = null;
        try {
            iterator_to_array((new RestoreDumpReader($this->file))->inserts(), false);
        } catch (BackupException $exception) {
            $truncated = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_SYNTAX', $truncated, 'a truncated final statement is refused, never completed');

        // Positive witness under the SAME fixture: closing the literal makes it readable.
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`note`) VALUES ('closed');\n") > 0);
        $batches = iterator_to_array((new RestoreDumpReader($this->file))->inserts(), false);
        self::assertCount(1, $batches);
        self::assertSame('closed', $batches[0]['rows'][0]['note']);
    }

    /** RB-29: a dump that changes between passes refuses instead of committing a partial read. */
    public function testRefusesDumpChangedBetweenPasses(): void
    {
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n") > 0);
        $reader = new RestoreDumpReader($this->file);
        self::assertSame(['cpms_patients'], $reader->tables());
        self::assertTrue(file_put_contents($this->file, "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1),(2);\n") > 0);
        $changed = null;
        try {
            iterator_to_array($reader->inserts(), false);
        } catch (BackupException $exception) {
            $changed = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_DUMP_CHANGED', $changed);
        // Positive witness: a fresh reader over the same (now stable) bytes succeeds.
        $rows = 0;
        foreach ((new RestoreDumpReader($this->file))->inserts() as $batch) {
            $rows += count($batch['rows']);
        }
        self::assertSame(2, $rows);
    }

    /** RB-48: the streamed pass digest equals a hash of the exact bytes the dump contains. */
    public function testPassDigestEqualsTheConsumedBytes(): void
    {
        $dump = "-- ---- table: cpms_patients ----\nINSERT INTO `cpms_patients` (`id`) VALUES (1);\n";
        self::assertTrue(file_put_contents($this->file, $dump) > 0);

        $reader = new RestoreDumpReader($this->file);
        self::assertNull($reader->last_pass_digest(), 'no pass has completed yet');
        self::assertSame(['cpms_patients'], $reader->tables());
        self::assertSame(hash('sha256', $dump), $reader->last_pass_digest(), 'the digest covers exactly the bytes read');

        // A different dump yields a different digest: the value tracks the bytes, not the path.
        self::assertTrue(file_put_contents($this->file, $dump . "-- trailing comment\n") > 0);
        $second = new RestoreDumpReader($this->file);
        self::assertSame(['cpms_patients'], $second->tables());
        self::assertNotSame($reader->last_pass_digest(), $second->last_pass_digest(), 'changed bytes change the digest');
    }

    /** RB-49: a pass abandoned mid-way never reports a digest, so it can never be read as completed. */
    public function testAnAbandonedPassReportsNoDigest(): void
    {
        $dump = "INSERT INTO `cpms_patients` (`id`) VALUES (1);\nINSERT INTO `cpms_patients` (`id`) VALUES (2);\n";
        self::assertTrue(file_put_contents($this->file, $dump) > 0);

        $reader  = new RestoreDumpReader($this->file);
        $batches = 0;
        foreach ($reader->inserts() as $batch) {
            self::assertSame('1', $batch['rows'][0]['id'], 'the first batch is the first statement');
            self::assertCount(1, $batch['rows'], 'the first batch holds only that statement');
            ++$batches;
            break; // stop after the first batch: the pass never reaches EOF.
        }
        self::assertSame(1, $batches, 'the pass really was abandoned after one batch');
        self::assertNull($reader->last_pass_digest(), 'an unfinished pass leaves no digest to compare');

        // A later FULL pass does report one, so the null above is not a permanent state.
        self::assertSame(['cpms_patients'], $reader->tables());
        self::assertSame(hash('sha256', $dump), $reader->last_pass_digest(), 'a completed pass covers the whole file');
    }
}

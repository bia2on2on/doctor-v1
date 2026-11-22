<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\RestoreBarrierStore;
use ClinicCore\Application\Backup\RestoreFileFence;
use ClinicCore\Infrastructure\Backup\BackupException;
use WP_UnitTestCase;

final class RestoreBarrierStoreTest extends WP_UnitTestCase {
    private const ATTEMPT = '0123456789abcdef0123456789abcdef';
    private const BACKUP = 'cpms-backup-20261120-000000-01234567';
    private string $directory;
    private string $lock;
    private RestoreFileFence $fence;
    private RestoreBarrierStore $store;

    protected function setUp(): void {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . '/cpms-rb-' . bin2hex(random_bytes(10));
        $this->lock = $this->directory . '/.cpms-restore-staging.lock';
        self::assertTrue(mkdir($this->directory, 0700));
        self::assertTrue(touch($this->lock));
        self::assertTrue(chmod($this->lock, 0600));
        $this->fence = new RestoreFileFence(static fn (): array => ['1 0 0:0 / / rw - ext4 /dev/test rw']);
        $this->store = new RestoreBarrierStore($this->directory);
    }

    protected function tearDown(): void {
        delete_option(RestoreBarrierStore::OPTION);
        foreach ([$this->lock, $this->lock . '.moved'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    /** RB-04: held lock, read-back, append, failure marker, and no clearance API. */
    public function testMarkerAndJournalUnderHeldFence(): void {
        self::assertFalse($this->store->is_set());
        $this->fence->run($this->directory, function (): void {
            $this->store->set($this->fence, self::ATTEMPT, self::BACKUP);
            self::assertTrue($this->store->is_set());
            $this->store->append($this->fence, self::ATTEMPT, 'RESTORE_IN_PROGRESS');
            $this->store->append($this->fence, self::ATTEMPT, 'RESTORE_FAILED_NEEDS_TEARDOWN');
        });
        $marker = get_option(RestoreBarrierStore::OPTION);
        self::assertSame(self::ATTEMPT, $marker['attempt_id']);
        self::assertSame(self::BACKUP, $marker['backup_id']);
        self::assertSame('RESTORE_FAILED_NEEDS_TEARDOWN', $marker['state']);
        self::assertSame(['RESTORE_IN_PROGRESS', 'RESTORE_FAILED_NEEDS_TEARDOWN'], array_column($marker['journal'], 'state'));
        self::assertArrayNotHasKey(RestoreBarrierStore::OPTION, wp_load_alloptions(true));
        self::assertFalse(method_exists($this->store, 'clear'));
        self::assertFalse(method_exists($this->store, 'delete'));
    }

    /** RB-05: independent handle cannot enter the worker's locked inode. */
    public function testIndependentHandleCannotAcquireLock(): void {
        $observed = $this->fence->run($this->directory, function (): array {
            $handle = fopen($this->lock, 'rb');
            self::assertIsResource($handle);
            try {
                $busy = 0;
                return [flock($handle, LOCK_EX | LOCK_NB, $busy), $busy];
            } finally {
                fclose($handle);
            }
        });
        self::assertSame([false, 1], $observed);
    }

    /** RB-06: a fence that has already exited cannot write anything. */
    public function testExpiredFenceWritesNothing(): void {
        $this->fence->run($this->directory, static fn (): bool => true);
        self::assertSame('RESTORE_FILE_LOCK_UNSUPPORTED', $this->attemptSet());
        self::assertFalse($this->store->is_set());
    }

    /** RB-07: option read-back mismatch is reported; persisted marker stays. */
    public function testMismatchedReadbackKeepsMarker(): void {
        $fake = static fn (): array => ['version' => -1];
        $arm = static function () use ($fake): void { add_filter('option_' . RestoreBarrierStore::OPTION, $fake); };
        add_action('add_option_' . RestoreBarrierStore::OPTION, $arm);
        try {
            $failure = $this->fence->run($this->directory, fn (): ?string => $this->attemptSet());
        } finally {
            remove_action('add_option_' . RestoreBarrierStore::OPTION, $arm);
            remove_filter('option_' . RestoreBarrierStore::OPTION, $fake);
        }
        self::assertSame('RESTORE_BARRIER_WRITE_AMBIGUOUS', $failure);
        self::assertSame(self::ATTEMPT, get_option(RestoreBarrierStore::OPTION)['attempt_id']);
    }

    /** RB-08: fence identity lost after the write is detected without clearing. */
    public function testFenceLostAfterWriteKeepsMarker(): void {
        $moved = false;
        $move = function () use (&$moved): void { $moved = rename($this->lock, $this->lock . '.moved'); };
        add_action('add_option_' . RestoreBarrierStore::OPTION, $move);
        try {
            $failure = $this->fence->run($this->directory, fn (): ?string => $this->attemptSet());
        } finally {
            remove_action('add_option_' . RestoreBarrierStore::OPTION, $move);
        }
        self::assertTrue($moved);
        self::assertSame('RESTORE_FILE_LOCK_UNSUPPORTED', $failure);
        self::assertTrue($this->store->is_set());
    }

    /** RB-11: production must refuse when the independent lock probe does not prove exclusion. */
    public function testInconclusiveProbePreventsWorker(): void {
        $mount = static fn (): array => ['1 0 0:0 / / rw - ext4 /dev/test rw'];
        $probes = 0;
        $runs = 0;
        $worker = static function () use (&$runs): void { ++$runs; };
        $failure = null;
        try {
            (new RestoreFileFence($mount, static function ($handle, &$busy) use (&$probes): bool {
                ++$probes;
                $busy = 0;
                return false;
            }))->run($this->directory, $worker);
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        }
        self::assertSame(1, $probes);
        self::assertSame(0, $runs);
        self::assertSame('RESTORE_FILE_LOCK_UNSUPPORTED', $failure);
        $this->fence->run($this->directory, $worker);
        self::assertSame(1, $runs);
    }

    /** RB-12: an existing attempt cannot be replaced even with a valid lock. */
    public function testSecondAttemptCannotReplaceMarker(): void {
        $this->fence->run($this->directory, function (): void {
            self::assertNull($this->attemptSet());
            $first = get_option(RestoreBarrierStore::OPTION);
            try {
                $this->store->set($this->fence, str_repeat('a', 32), self::BACKUP);
                self::fail('Second marker write was accepted');
            } catch (BackupException $exception) {
                self::assertSame('RESTORE_BARRIER_WRITE_AMBIGUOUS', $exception->getErrorCode());
            }
            self::assertSame($first, get_option(RestoreBarrierStore::OPTION));
        });
    }

    /** RB-13: a changed persisted value cannot be overwritten by a stale append. */
    public function testJournalCompareAndSwapRejectsStaleValue(): void {
        global $wpdb;
        $failure = null;
        $hits = 0;
        $changed = false;
        $this->fence->run($this->directory, function () use ($wpdb, &$failure, &$hits, &$changed): void {
            $this->store->set($this->fence, self::ATTEMPT, self::BACKUP);
            $replacement = get_option(RestoreBarrierStore::OPTION);
            // Establish a clean witness before installing the interleaving.
            $this->store->append($this->fence, self::ATTEMPT, 'RESTORE_IN_PROGRESS');
            $replacement['state'] = 'RESTORE_FAILED_NEEDS_TEARDOWN';
            $prefix = 'UPDATE `' . $wpdb->options . "` SET option_value = ";
            $where = " WHERE option_name = 'cpms_restore_barrier' AND BINARY option_value = ";
            $pattern = '/\A' . preg_quote($prefix, '/') . "'.*', autoload = 'no'" . preg_quote($where, '/') . "'.*'\z/s";
            $inject = static function (string $sql) use ($pattern, $replacement, &$hits, &$changed): string {
                if (preg_match($pattern, $sql) === 1) {
                    ++$hits;
                    $changed = update_option(RestoreBarrierStore::OPTION, $replacement, false);
                }
                return $sql;
            };
            add_filter('query', $inject);
            try {
                $this->store->append($this->fence, self::ATTEMPT, 'RESTORE_FAILED_NEEDS_TEARDOWN');
            } catch (BackupException $exception) {
                $failure = $exception->getErrorCode();
            } finally {
                remove_filter('query', $inject);
            }
        });
        self::assertSame(1, $hits);
        self::assertTrue($changed);
        self::assertSame('RESTORE_BARRIER_WRITE_AMBIGUOUS', $failure);
        $persisted = get_option(RestoreBarrierStore::OPTION);
        self::assertSame('RESTORE_FAILED_NEEDS_TEARDOWN', $persisted['state']);
        self::assertSame([], $persisted['journal']);
    }

    private function attemptSet(): ?string {
        try {
            $this->store->set($this->fence, self::ATTEMPT, self::BACKUP);
        } catch (BackupException $exception) {
            return $exception->getErrorCode();
        }
        return null;
    }
}

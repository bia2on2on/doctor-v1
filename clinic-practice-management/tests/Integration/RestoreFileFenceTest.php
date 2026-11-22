<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\RestoreFileFence;
use ClinicCore\Infrastructure\Backup\BackupException;
use WP_UnitTestCase;

final class RestoreFileFenceTest extends WP_UnitTestCase {
    /** RB-03: never run a worker or create the lock on unprovisioned storage. */
    public function testUnprovisionedLockRefusesBeforeWorkerRuns(): void {
        $directory = sys_get_temp_dir() . '/cpms-restore-fence-' . bin2hex(random_bytes(12));
        $runs = 0;
        $failure = null;
        try {
            (new RestoreFileFence())->run($directory, static function () use (&$runs): void {
                ++$runs;
            });
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        }
        self::assertSame('RESTORE_FILE_LOCK_UNSUPPORTED', $failure);
        self::assertSame(0, $runs);
        self::assertFileDoesNotExist($directory . '/.cpms-restore-staging.lock');
        self::assertDirectoryDoesNotExist($directory);
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use ClinicCore\Infrastructure\Backup\BackupException;

/** Lock a provisioned permanent file in staging controlled by the restore worker. */
final class RestoreFileFence {
    /** Run a worker only while an independently probed lock is held. */
    public function run( string $directory, callable $worker ): mixed {
        $path = $directory . '/.cpms-restore-staging.lock';
        if ( 'Linux' !== PHP_OS_FAMILY || PHP_ZTS || realpath( $directory ) !== $directory || ! $this->local_mount( $directory ) ) {
            throw $this->unsupported();
        }
        clearstatcache( true, $path );
        $directory_stat = @lstat( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing evidence refuses the lock.
        if ( false === $directory_stat || 0040700 !== ( $directory_stat['mode'] & 0177777 ) ) {
            throw $this->unsupported();
        }
        $before = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing evidence refuses the lock.
        if ( false === $before || 0100600 !== ( $before['mode'] & 0177777 ) || 1 !== $before['nlink'] || $before['dev'] !== $directory_stat['dev'] ) {
            throw $this->unsupported();
        }
        $handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- flock needs a native local handle.
        if ( false === $handle ) {
            throw $this->unsupported();
        }
        try {
            $this->assert_inode( $path, $handle, $before );
            $busy = 0;
            if ( ! flock( $handle, LOCK_EX | LOCK_NB, $busy ) ) {
                if ( 1 === $busy ) {
                    throw BackupException::of( 'RESTORE_FILE_LOCK_HELD', 'Another restore worker holds the staging lock.' );
                }
                throw $this->unsupported();
            }
            $probe = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- An independent native handle must be excluded.
            if ( false === $probe ) {
                throw $this->unsupported();
            }
            try {
                $this->assert_inode( $path, $probe, $before );
                $excluded = 0;
                if ( flock( $probe, LOCK_EX | LOCK_NB, $excluded ) || 1 !== $excluded ) {
                    throw $this->unsupported();
                }
            } finally {
                fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Native lock probe handle.
            }
            $this->assert_inode( $path, $handle, $before );

            return $worker();
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Release the native lock on every exit.
        }
    }

    private function assert_inode( string $path, mixed $handle, array $before ): void {
        clearstatcache( true, $path );
        $open    = fstat( $handle );
        $current = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A replaced or missing inode refuses the lock.
        foreach ( [ $open, $current ] as $stat ) {
            foreach ( [ 'dev', 'ino', 'mode', 'nlink' ] as $key ) {
                if ( false === $stat || $stat[ $key ] !== $before[ $key ] ) {
                    throw $this->unsupported();
                }
            }
        }
    }

    private function local_mount( string $directory ): bool {
        $mounts = @file( '/proc/self/mountinfo', FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Read kernel mount evidence, or refuse.
        if ( false === $mounts ) {
            return false;
        }
        $length = 0;
        $local  = false;
        foreach ( $mounts as $mount ) {
            $parts = explode( ' - ', $mount, 2 );
            $point = explode( ' ', $parts[0] )[4] ?? '';
            if ( '' === $point || str_contains( $point, '\\' ) ) {
                return false;
            }
            if ( $directory !== $point && ! str_starts_with( $directory, rtrim( $point, '/' ) . '/' ) ) {
                continue;
            }
            $size = strlen( $point );
            if ( $size === $length ) {
                $local = false;
            } elseif ( $size > $length ) {
                $length = $size;
                $local  = in_array( explode( ' ', $parts[1] ?? '' )[0], [ 'ext4', 'xfs' ], true );
            }
        }

        return $local;
    }

    private function unsupported(): BackupException {
        return BackupException::of( 'RESTORE_FILE_LOCK_UNSUPPORTED', 'Reliable local restore locking was not proven.' );
    }
}

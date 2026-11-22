<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;

/** Standalone marker and journal. Not an authorization to start importing. */
final class RestoreBarrierStore {
    public const OPTION       = 'cpms_restore_barrier';
    private const IN_PROGRESS = 'RESTORE_IN_PROGRESS';
    private const FAILED      = 'RESTORE_FAILED_NEEDS_TEARDOWN';
    private const MAX_EVENTS  = 256;

    /** Override staging only for an isolated component test. */
    public function __construct( private readonly ?string $test_directory = null ) {
    }

    public function is_set(): bool {
        global $wpdb;
        // Probe the core options table directly: cached defaults cannot distinguish a DB error from absence.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An uncached presence check is the access decision.
        $present = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION ) );

        return '' !== $wpdb->last_error || null !== $present;
    }

    public function set( RestoreFileFence $fence, string $attempt_id, string $backup_id ): void {
        if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $attempt_id ) || 1 !== preg_match( '/\Acpms-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\z/', $backup_id ) ) {
            throw BackupException::of( 'RESTORE_BARRIER_INVALID', 'Invalid restore attempt identifiers.' );
        }
        $payload = [
            'version'    => 1,
            'attempt_id' => $attempt_id,
            'backup_id'  => $backup_id,
            'started_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'state'      => self::IN_PROGRESS,
            'journal'    => [],
        ];
        global $wpdb;
        $this->assert_fence( $fence );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert must never replace an existing attempt.
        $written = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = option_name', $wpdb->options, self::OPTION, maybe_serialize( $payload ), 'no' ) );
        if ( 1 !== $written ) {
            throw $this->ambiguous();
        }
        $this->invalidate_option_cache();
        // Preserve the WordPress post-insert notification for this option.
        do_action( 'add_option_' . self::OPTION, self::OPTION, $payload ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Match the core option notification.
        $this->confirm( $fence, $payload );
    }

    /** Append an attempt state event without allowing a transition out of failure. */
    public function append( RestoreFileFence $fence, string $attempt_id, string $state ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare the exact persisted value, not a cache or filter result.
        $raw     = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
        $payload = maybe_unserialize( $raw );
        if ( ! is_array( $payload ) || 1 !== ( $payload['version'] ?? null ) || $attempt_id !== ( $payload['attempt_id'] ?? null ) || self::IN_PROGRESS !== ( $payload['state'] ?? null ) || ! isset( $payload['journal'] ) || ! is_array( $payload['journal'] ) || count( $payload['journal'] ) >= self::MAX_EVENTS || ! in_array( $state, [ self::IN_PROGRESS, self::FAILED ], true ) ) {
            throw BackupException::of( 'RESTORE_JOURNAL_REFUSED', 'Restore journal cannot accept this event.' );
        }
        $payload['journal'][] = [
            'attempt_id' => $attempt_id,
            'state'      => $state,
            'at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ];
        $payload['state']     = $state;
        $this->assert_fence( $fence );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS refuses a stale journal, including case-only changes.
        $written = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s', $wpdb->options, maybe_serialize( $payload ), 'no', self::OPTION, $raw ) );
        if ( 1 !== $written ) {
            throw $this->ambiguous();
        }
        $this->confirm( $fence, $payload );
    }

    private function confirm( RestoreFileFence $fence, array $expected ): void {
        $this->invalidate_option_cache();
        $observed = get_option( self::OPTION );
        $this->assert_fence( $fence );
        if ( $expected !== $observed ) {
            throw $this->ambiguous();
        }
    }

    private function assert_fence( RestoreFileFence $fence ): void {
        $fence->assert_held( $this->test_directory ?? PrivateStorageLocation::path( 'cpms-restore-staging' ) );
    }

    private function invalidate_option_cache(): void {
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    private function ambiguous(): BackupException {
        return BackupException::of( 'RESTORE_BARRIER_WRITE_AMBIGUOUS', 'Restore marker persistence could not be confirmed.' );
    }
}

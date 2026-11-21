<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use ClinicCore\Infrastructure\Backup\BackupException;

/** Standalone marker and journal. Not an authorization to start importing. */
final class RestoreBarrierStore {
    public const OPTION       = 'cpms_restore_barrier';
    private const IN_PROGRESS = 'RESTORE_IN_PROGRESS';
    private const FAILED      = 'RESTORE_FAILED_NEEDS_TEARDOWN';
    private const MAX_EVENTS  = 256;

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
        $fence->assert_held();
        if ( ! add_option( self::OPTION, $payload, '', false ) ) {
            throw $this->ambiguous();
        }
        $this->confirm( $fence, $payload );
    }

    /** Append an attempt state event without allowing a transition out of failure. */
    public function append( RestoreFileFence $fence, string $attempt_id, string $state ): void {
        $payload = get_option( self::OPTION );
        if ( ! is_array( $payload ) || 1 !== ( $payload['version'] ?? null ) || $attempt_id !== ( $payload['attempt_id'] ?? null ) || self::IN_PROGRESS !== ( $payload['state'] ?? null ) || ! isset( $payload['journal'] ) || ! is_array( $payload['journal'] ) || count( $payload['journal'] ) >= self::MAX_EVENTS || ! in_array( $state, [ self::IN_PROGRESS, self::FAILED ], true ) ) {
            throw BackupException::of( 'RESTORE_JOURNAL_REFUSED', 'Restore journal cannot accept this event.' );
        }
        $payload['journal'][] = [
            'attempt_id' => $attempt_id,
            'state'      => $state,
            'at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ];
        $payload['state']     = $state;
        $fence->assert_held();
        if ( ! update_option( self::OPTION, $payload, false ) ) {
            throw $this->ambiguous();
        }
        $this->confirm( $fence, $payload );
    }

    private function confirm( RestoreFileFence $fence, array $expected ): void {
        wp_cache_delete( self::OPTION, 'options' );
        $observed = get_option( self::OPTION );
        $fence->assert_held();
        if ( $expected !== $observed ) {
            throw $this->ambiguous();
        }
    }

    private function ambiguous(): BackupException {
        return BackupException::of( 'RESTORE_BARRIER_WRITE_AMBIGUOUS', 'Restore marker persistence could not be confirmed.' );
    }
}

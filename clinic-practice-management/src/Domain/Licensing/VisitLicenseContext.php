<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

use ClinicCore\Domain\Machine\VisitMachine;

/**
 * Server-derived license context for a persisted `cpms_visits` row.
 *
 * Shared by every license-gated write lane (clinical, handwriting-adjacent,
 * finance, files) so the "already-opened Visit" predicate and the
 * `created_at` parse exist exactly once. Built only from an actual database
 * row — never from request data. HandwritingService keeps its own private
 * copy of this shape (PR#11); this helper is the same contract for the rest.
 */
final class VisitLicenseContext
{
    /**
     * @param array<string, mixed>|null $visit Persisted cpms_visits row.
     *
     * @return array{visit_id: int, clinic_id: int, visit_opened: bool, visit_active: bool, visit_status: string, visit_created_at: int|null}
     */
    public static function from_row( ?array $visit ): array {
        $status = (string) ( $visit['status'] ?? '' );
        $active = 1 === (int) ( $visit['active'] ?? 0 );

        return [
            'visit_id'         => (int) ( $visit['id'] ?? 0 ),
            'clinic_id'        => (int) ( $visit['clinic_id'] ?? 0 ),
            'visit_opened'     => $active && in_array( $status, VisitMachine::ACTIVE_STATUSES, true ),
            'visit_active'     => $active,
            'visit_status'     => $status,
            'visit_created_at' => self::parse_utc_timestamp( (string) ( $visit['created_at'] ?? '' ) ),
        ];
    }

    /**
     * Parse a UTC SQL datetime string (with optional fractional seconds) into
     * a Unix timestamp. Returns null when unparseable (fail-closed upstream).
     */
    private static function parse_utc_timestamp( string $utc_sql ): ?int {
        if ( '' === $utc_sql ) {
            return null;
        }
        $clean = preg_replace( '/\.\d+$/', '', $utc_sql ) ?? $utc_sql;
        try {
            return ( new \DateTimeImmutable( $clean, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        } catch ( \Throwable ) {
            return null;
        }
    }
}

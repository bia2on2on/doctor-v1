<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Owner final three-stage license rule (design §6.1/§7, decision log 2026-11-17).
 *
 * One central, pure mapping from a signed ordinary-expiry term to the stage
 * boundaries and the per-operation answers. No I/O, no clock, no state: the
 * caller passes the signed T0 (`expires_at`) and an authoritative UTC `now`
 * (see §7.2 item 7 for the one-instant rule). Time-of-day is preserved and
 * T1 is computed as T0 + 1 Gregorian month, UTC, day-clamped, half-open
 * (PostExpirationPolicy::deadline()).
 *
 * Stage 1 — now < T0: ordinary RBAC governs everything.
 * Stage 2 — T0 <= now < T1: no new demand (bookings, patients, walk-ins);
 *           previously booked care stays fully followable.
 * Stage 3 — now >= T1: read-only except finishing an already-opened Visit
 *           (handwriting, invoice, payment of that visit) and the offline
 *           activation/renewal path. Records stay viewable forever.
 *
 * Fail-closed: an unusable T0 (missing, non-integer, non-positive) denies
 * every capability answer here; the gate never calls these rules for a
 * missing/invalid signed document at all (design §6.1/§7.4). Q4a: refunds
 * are denied at/after T1 with no exception; Q4b restore is out of scope.
 */
final class LicenseStagePolicy
{
    public const STAGE_VALID = 1;
    public const STAGE_CONTINUITY = 2;
    public const STAGE_READ_ONLY = 3;

    /**
     * T1 = T0 + 1 Gregorian month, UTC, day-clamped (design §6.1). Half-open:
     * Stage 3 begins exactly at T1.
     */
    public static function t1(int $expires_at): int
    {
        return PostExpirationPolicy::deadline($expires_at, 1);
    }

    /**
     * Stage for the signed term at $now (design §6.1; T0-1s → 1, T0 → 2,
     * T1-1s → 2, T1 → 3).
     */
    public static function stage(int $expires_at, int $now): int
    {
        if ($expires_at <= 0) {
            return self::STAGE_READ_ONLY;
        }
        if ($now < $expires_at) {
            return self::STAGE_VALID;
        }

        return $now < self::t1($expires_at) ? self::STAGE_CONTINUITY : self::STAGE_READ_ONLY;
    }

    public static function may_create_appointment(int $expires_at, int $now): bool
    {
        return $expires_at > 0 && $now < $expires_at;
    }

    public static function may_create_patient(int $expires_at, int $now): bool
    {
        return $expires_at > 0 && $now < $expires_at;
    }

    public static function may_start_walk_in(int $expires_at, int $now): bool
    {
        return $expires_at > 0 && $now < $expires_at;
    }

    /**
     * First admission of a booked visit is lawful only for a durable
     * pre-T0 `pending`/`confirmed` appointment row, and only while now < T1
     * (design §7.2 items 2 and 7; final Q3(a): at/after T1 every first
     * admission is denied, admitted_at or not). $booking_time is the server
     * `created_at` of the locked appointment row, never request data.
     */
    public static function may_admit_booked_visit(int $expires_at, int $now, int $booking_time): bool
    {
        return $expires_at > 0
            && $booking_time > 0
            && $booking_time < $expires_at
            && $now < self::t1($expires_at);
    }

    /**
     * Finishing an already-opened Visit. Every license state may finish a
     * Visit that is still active at the decision instant (design §7.2 item 4/5;
     * PR#11 owner rule for handwriting) — but from T1 onwards the Visit must
     * have been created before T1 and be active and non-terminal now.
     *
     * $visit_opened is the server-derived predicate
     * (active = 1 AND status IN VisitMachine::ACTIVE_STATUSES).
     */
    public static function may_finish_opened_visit(int $expires_at, int $now, int $visit_created_at, bool $visit_opened, string $visit_status): bool
    {
        if ($expires_at <= 0 || $visit_created_at <= 0 || ! $visit_opened) {
            return false;
        }
        if (! in_array($visit_status, \ClinicCore\Domain\Machine\VisitMachine::ACTIVE_STATUSES, true)) {
            return false;
        }
        if ($now < self::t1($expires_at)) {
            // Stages 1 and 2 — an opened Visit is existing care.
            return true;
        }

        // Stage 3 — qualifying already-opened Visit only (created_at < T1).
        return $visit_created_at < self::t1($expires_at);
    }

    /**
     * Refunds of an existing captured payment. Final Q4a(a): every historical
     * refund is denied at/after T1 — no exception, not even for an
     * already-opened Visit lane. $original_payment_time is retained for
     * auditability/fail-closed sanity and is never an allowance.
     */
    public static function may_refund(int $expires_at, int $now, int $original_payment_time): bool
    {
        if ($expires_at <= 0 || $original_payment_time <= 0 || $original_payment_time > $now) {
            return false;
        }

        return $now < self::t1($expires_at);
    }

    /**
     * Stage 3 is the read-only stage (now >= T1).
     */
    public static function is_read_only(int $expires_at, int $now): bool
    {
        if ($expires_at <= 0) {
            return true;
        }

        return $now >= self::t1($expires_at);
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Read-only preview of the final owner first-enrollment policy (2026-11-17, Q1(a)).
 *
 * This class deliberately mirrors the ordinary-expiration branch of
 * {@see SignedLicenseGate::assert()} so the Patient Portal can decide whether to
 * offer the enrollment form at all. It does not grant anything: the enrollment
 * endpoint remains the only authority and re-evaluates the same policy inside
 * its transaction. No state, history, T0 or Clinic record is returned here.
 */
final class FirstEnrollmentPolicy {
    /**
     * True exactly when SignedLicenseGate treats the license as an ordinary
     * cached signed document reached its T0 at $now (signed T0 reached; outage age
     * never moves it — B1/design §7.1). Grace/skew never move T0.
     */
    public static function is_post_expiration(
        string $status,
        string $reason,
        ?int $expires_at,
        int $now
    ): bool {
        if ( $expires_at === null || $expires_at <= 0 || $now < $expires_at ) {
            return false;
        }

        return match ( $status ) {
            LicenseStatus::ACTIVE => $reason === '',
            LicenseStatus::EXPIRING => $reason === 'near_expiry',
            LicenseStatus::GRACE => in_array( $reason, [ 'grace', 'unreachable_grace' ], true ),
            LicenseStatus::RESTRICTED => in_array( $reason, [ 'expired', 'expired_unreachable' ], true ),
            LicenseStatus::UNREACHABLE => $reason === 'cached_state_stale',
            default => false,
        };
    }

    /**
     * The same decision the gate will make for `patient.create` on this subject.
     * Final Q1(a) (2026-11-17): from T0 no self-enrollment is offered — no
     * returning-evidence window, no reception exception; only a pre-T0 license
     * (or a non-expiry new-business-capable state) can offer the form.
     */
    public static function allows_first_enrollment(
        string $status,
        string $reason,
        ?int $expires_at,
        int $now,
        bool $returning
    ): bool {
        unset( $returning ); // retained for call-site compatibility; evidence grants nothing from T0
        if ( self::is_post_expiration( $status, $reason, $expires_at, $now ) ) {
            return false;
        }

        return LicenseStatus::allowsNewBusiness( $status );
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\FirstEnrollmentPolicy;
use ClinicCore\Domain\Licensing\LicenseStatus;
use PHPUnit\Framework\TestCase;

/**
 * The Patient Portal must not offer a form the server will reject. Final
 * owner policy (2026-11-17, Q1(a)): no self-enrollment preview from T0 — no
 * returning-evidence window, no reception exception; the preview closes with
 * the signed ordinary-expiry T0 and stays closed through Stage 2 and Stage 3.
 */
final class FirstEnrollmentPolicyTest extends TestCase
{
    private const T0 = 1769862896; // 2026-01-31 12:34:56 UTC

    public function testOrdinaryExpirationBoundaryIsHalfOpenForReturningSubject(): void
    {
        // Final Q1(a) (2026-11-17): the one-/six-month returning windows are
        // retired — no self-enrollment preview from T0 at all. Boundary
        // points: T0−1s allowed (Stage 1), T0 denied, T1−1s denied, T1 denied.
        $t1 = \ClinicCore\Domain\Licensing\LicenseStagePolicy::t1( self::T0 );

        self::assertTrue( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0 - 1, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $t1 - 1, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $t1, true ) );
    }

    public function testPostExpirationNeverOffersTheReceptionOnlyNewPatientWindow(): void
    {
        $t1 = \ClinicCore\Domain\Licensing\LicenseStagePolicy::t1( self::T0 );

        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $t1 - 1, false ),
            'Final Q1(a): no new patient row of any shape — patient self or reception — is offered from T0.'
        );
        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $t1 - 1, true ),
            'Returning evidence grants nothing from T0 (final Q1(a)).'
        );
        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $t1, false )
        );
        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0, false )
        );
    }

    public function testPostExpirationDetectionMatchesTheGateOrdinaryCases(): void
    {
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::EXPIRING, 'near_expiry', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::GRACE, 'grace', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::GRACE, 'unreachable_grace', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::RESTRICTED, 'expired_unreachable', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::UNREACHABLE, 'cached_state_stale', self::T0, self::T0 ) );

        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', self::T0, self::T0 - 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::SUSPENDED, 'suspended', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::INVALID, 'expired', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::RESTRICTED, 'binding_mismatch', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', null, self::T0 + 1 ) );
    }

    public function testNewBusinessStatesRemainAllowedBeforeT0(): void
    {
        foreach ( [ LicenseStatus::ACTIVE, LicenseStatus::EXPIRING, LicenseStatus::DEVELOPMENT, LicenseStatus::ACTIVATION_PENDING ] as $status ) {
            self::assertTrue(
                FirstEnrollmentPolicy::allows_first_enrollment( $status, '', self::T0, self::T0 - 1, false ),
                $status . ' before T0 allows ordinary new business.'
            );
        }
        self::assertTrue(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::ACTIVE, '', self::T0, self::T0 - 1, false )
        );
    }

    public function testBlockingStatesNeverOfferEnrollmentEvenForReturningSubject(): void
    {
        foreach ( [ LicenseStatus::SUSPENDED, LicenseStatus::REVOKED, LicenseStatus::INVALID, LicenseStatus::UNREACHABLE ] as $status ) {
            self::assertFalse(
                FirstEnrollmentPolicy::allows_first_enrollment( $status, 'blocked', self::T0, self::T0 - 1, true ),
                $status . ' must not be softened by returning evidence or a pre-T0 clock.'
            );
        }
    }

    public function testActualSixGregorianMonthDeadlineUsesClampedGregorianMonths(): void
    {
        // T1 = T0 + 1 Gregorian month, UTC, day-clamped, half-open (design §6.1).
        $endOfMonth = strtotime( '2026-01-31 10:00:00 UTC' );
        $t1 = \ClinicCore\Domain\Licensing\LicenseStagePolicy::t1( $endOfMonth );

        self::assertSame( '2026-02-28 10:00:00', gmdate( 'Y-m-d H:i:s', $t1 ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', $endOfMonth, $t1 - 1, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', $endOfMonth, $t1, true ) );
    }
}

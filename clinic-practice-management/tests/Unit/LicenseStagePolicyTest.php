<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\LicenseStagePolicy;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use PHPUnit\Framework\TestCase;

/**
 * Owner final three-stage rule (design §6.1/§7, decisions 2026-11-17).
 *
 * Four comparison points everywhere: T0−1s → Stage 1, T0 → Stage 2,
 * T1−1s → Stage 2, T1 → Stage 3. T1 = T0 + 1 Gregorian month, UTC,
 * day-clamped, half-open (PostExpirationPolicy::deadline()).
 */
final class LicenseStagePolicyTest extends TestCase
{
    private const T0 = 1793448000; // 2026-11-20-ish; fixed instant is all that matters

    private function gate(int $expiresAt, int $now): SignedLicenseGate
    {
        $provider = new class($expiresAt, $now) implements LicenseStateProvider {
            public function __construct(public int $expires, public int $now) {}
            public function currentState(): array {
                return LicenseStateMachine::compute(['expires_at' => $this->expires], LicenseStateMachine::VERIFIED, $this->now);
            }
            public function entitlements(): EntitlementRegistry { return new EntitlementRegistry(); }
        };

        return new SignedLicenseGate($provider, null, static fn (): int => $now);
    }

    public function testT1IsOneGregorianMonthUtcDayClampedHalfOpen(): void
    {
        self::assertSame(strtotime('2026-02-28 12:34:56 UTC'), PostExpirationPolicy::deadline(strtotime('2026-01-31 12:34:56 UTC'), 1));
        self::assertSame(strtotime('2028-02-29 12:34:56 UTC'), PostExpirationPolicy::deadline(strtotime('2028-01-31 12:34:56 UTC'), 1));
        self::assertSame(LicenseStagePolicy::t1(self::T0), PostExpirationPolicy::deadline(self::T0, 1));
    }

    public function testStageMappingIsHalfOpenAtBothBoundaries(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);

        self::assertSame(LicenseStagePolicy::STAGE_VALID, LicenseStagePolicy::stage(self::T0, self::T0 - 1));
        self::assertSame(LicenseStagePolicy::STAGE_CONTINUITY, LicenseStagePolicy::stage(self::T0, self::T0));
        self::assertSame(LicenseStagePolicy::STAGE_CONTINUITY, LicenseStagePolicy::stage(self::T0, $t1 - 1));
        self::assertSame(LicenseStagePolicy::STAGE_READ_ONLY, LicenseStagePolicy::stage(self::T0, $t1));
        self::assertSame(LicenseStagePolicy::STAGE_READ_ONLY, LicenseStagePolicy::stage(self::T0, $t1 + 1));
    }

    public function testStageThreeDeniesEveryFirstAdmissionAtAndAfterT1Only(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);
        $context = ['appointment_created_at' => self::T0 - 1, 'appointment_status' => 'pending'];

        self::assertTrue($this->gate(self::T0, self::T0 - 1)->assert(LicenseGate::OP_VISIT_CHECKIN, $context)->allowed);
        self::assertTrue($this->gate(self::T0, self::T0)->assert(LicenseGate::OP_VISIT_CHECKIN, $context)->allowed);
        self::assertTrue($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_VISIT_CHECKIN, $context)->allowed);
        self::assertFalse($this->gate(self::T0, $t1)->assert(LicenseGate::OP_VISIT_CHECKIN, $context)->allowed);
        self::assertFalse($this->gate(self::T0, $t1 + 1)->assert(LicenseGate::OP_VISIT_CHECKIN, $context)->allowed);
    }

    public function testAdmissionRequiresPreT0PendingOrConfirmedAppointment(): void
    {
        foreach (['pending', 'confirmed'] as $status) {
            self::assertTrue(
                $this->gate(self::T0, self::T0 + 86400)->assert(LicenseGate::OP_VISIT_CHECKIN, ['appointment_created_at' => self::T0 - 1, 'appointment_status' => $status])->allowed,
                $status . ' pre-T0 row must be first-admissible in Stage 2'
            );
        }
        // Appointment created at/after T0 is never a continuity candidate.
        self::assertFalse(
            $this->gate(self::T0, self::T0 + 86400)->assert(LicenseGate::OP_VISIT_CHECKIN, ['appointment_created_at' => self::T0, 'appointment_status' => 'pending'])->allowed
        );
        // Terminal/other statuses are never first-admissible.
        foreach (['completed', 'cancelled_by_staff', 'no_show', 'rescheduled'] as $status) {
            self::assertFalse(
                $this->gate(self::T0, self::T0 + 86400)->assert(LicenseGate::OP_VISIT_CHECKIN, ['appointment_created_at' => self::T0 - 1, 'appointment_status' => $status])->allowed,
                $status . ' is not check-in admissible'
            );
        }
        // No appointment provenance at all: never admissible (walk-in / first-admission denial).
        self::assertFalse($this->gate(self::T0, self::T0 + 86400)->assert(LicenseGate::OP_VISIT_CHECKIN)->allowed);
    }

    public function testRefundDenialStartsExactlyAtT1(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);
        $payment = ['original_payment_time' => self::T0 - 3600];

        self::assertTrue($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_REFUND, $payment)->allowed);
        self::assertFalse($this->gate(self::T0, $t1)->assert(LicenseGate::OP_REFUND, $payment)->allowed);
        self::assertFalse($this->gate(self::T0, $t1 + 1)->assert(LicenseGate::OP_REFUND, $payment)->allowed);
        // Fail-closed without a trustworthy original payment instant.
        self::assertFalse($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_REFUND)->allowed);
        self::assertFalse($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_REFUND, ['original_payment_time' => 0])->allowed);
    }

    public function testOpenedVisitFinishLaneSurvivesEveryStageUntilT1(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);
        $openedBeforeT0 = ['visit_opened' => true, 'visit_created_at' => self::T0 - 60];

        foreach ([self::T0 - 1, self::T0, $t1 - 1, $t1, $t1 + 86400] as $now) {
            self::assertTrue($this->gate(self::T0, $now)->assert(LicenseGate::OP_VISIT_FINISH, $openedBeforeT0)->allowed, "opened visit must finish at {$now}");
            self::assertTrue($this->gate(self::T0, $now)->assert(LicenseGate::OP_INVOICE_CREATE, $openedBeforeT0)->allowed, "opened visit invoice must issue at {$now}");
            self::assertTrue($this->gate(self::T0, $now)->assert(LicenseGate::OP_PAYMENT_RECORD, $openedBeforeT0)->allowed, "opened visit payment must record at {$now}");
        }
        // A Visit first opened at/after T1 is never a safe-finish candidate.
        self::assertFalse(
            $this->gate(self::T0, $t1 + 1)->assert(LicenseGate::OP_VISIT_FINISH, ['visit_opened' => true, 'visit_created_at' => $t1])->allowed
        );
        // Stage 3: uncontextualized finance/finish lanes are closed.
        foreach ([LicenseGate::OP_VISIT_FINISH, LicenseGate::OP_INVOICE_CREATE, LicenseGate::OP_PAYMENT_RECORD, LicenseGate::OP_FILE_UPLOAD] as $op) {
            self::assertFalse($this->gate(self::T0, $t1)->assert($op)->allowed, $op . ' must be denied without an opened-visit context at T1');
        }
        // Stage 2: the same uncontextualized lanes stay open under ordinary RBAC.
        foreach ([LicenseGate::OP_VISIT_FINISH, LicenseGate::OP_INVOICE_CREATE, LicenseGate::OP_PAYMENT_RECORD, LicenseGate::OP_FILE_UPLOAD] as $op) {
            self::assertTrue($this->gate(self::T0, $t1 - 1)->assert($op)->allowed, $op . ' must stay open in Stage 2');
        }
    }

    public function testNewDemandDeniedFromT0ButNotBefore(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);
        foreach ([LicenseGate::OP_PATIENT_CREATE, LicenseGate::OP_APPOINTMENT_BOOK, LicenseGate::OP_APPOINTMENT_RESCHEDULE] as $op) {
            self::assertTrue($this->gate(self::T0, self::T0 - 1)->assert($op)->allowed, $op . ' must be allowed before T0');
            foreach ([self::T0, $t1 - 1, $t1] as $now) {
                self::assertFalse($this->gate(self::T0, $now)->assert($op)->allowed, $op . ' must be denied at/after T0');
            }
        }
        // New patients via the reception shape are denied identically.
        self::assertFalse($this->gate(self::T0, self::T0)->assert(LicenseGate::OP_PATIENT_CREATE, ['reception' => true])->allowed);
    }

    public function testStageThreeViewOnlyForSettingsAndProfileAndAppointmentState(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);
        foreach ([LicenseGate::OP_SETTINGS_WRITE, LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL, LicenseGate::OP_APPOINTMENT_NOSHOW] as $op) {
            self::assertTrue($this->gate(self::T0, $t1 - 1)->assert($op)->allowed, $op . ' Stage 2 existing-record maintenance under RBAC');
            self::assertFalse($this->gate(self::T0, $t1)->assert($op)->allowed, $op . ' Stage 3 is view-only');
        }
    }

    public function testSmsFollowsPreviouslyBookedAppointmentsOnlyUntilT1(): void
    {
        $t1 = LicenseStagePolicy::t1(self::T0);

        $care = ['care_linked' => true];
        self::assertTrue($this->gate(self::T0, self::T0 - 1)->assert(LicenseGate::OP_SMS_COMMERCIAL)->allowed);
        self::assertTrue($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_SMS_COMMERCIAL, $care)->allowed);
        self::assertFalse($this->gate(self::T0, $t1 - 1)->assert(LicenseGate::OP_SMS_COMMERCIAL)->allowed); // no persisted care link: closed even in Stage 2
        self::assertFalse($this->gate(self::T0, $t1)->assert(LicenseGate::OP_SMS_COMMERCIAL, $care)->allowed);
    }

    public function testStagePolicyFailsClosedOnUnusableT0(): void
    {
        self::assertSame(LicenseStagePolicy::STAGE_READ_ONLY, LicenseStagePolicy::stage(0, self::T0 - 1000));
        self::assertSame(LicenseStagePolicy::STAGE_READ_ONLY, LicenseStagePolicy::stage(-5, self::T0));
        self::assertTrue(LicenseStagePolicy::is_read_only(0, self::T0));
        self::assertFalse(LicenseStagePolicy::may_create_appointment(0, self::T0));
        self::assertFalse(LicenseStagePolicy::may_admit_booked_visit(0, self::T0, self::T0 - 1));
        self::assertFalse(LicenseStagePolicy::may_refund(0, self::T0, self::T0 - 10));
    }
}

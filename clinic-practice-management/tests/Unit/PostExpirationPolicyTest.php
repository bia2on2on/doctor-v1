<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Domain\Licensing\ReturningPatientEvidence;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use PHPUnit\Framework\TestCase;

final class PostExpirationPolicyTest extends TestCase
{
    private function gate(int $expires, int $now, bool $returning = false): SignedLicenseGate
    {
        $provider = new class($expires, $now) implements LicenseStateProvider {
            public function __construct(public int $expires, public int $now) {}
            public function currentState(): array {
                return LicenseStateMachine::compute(['expires_at' => $this->expires], LicenseStateMachine::VERIFIED, $this->now);
            }
            public function entitlements(): EntitlementRegistry { return new EntitlementRegistry(); }
        };
        $evidence = new class($returning) implements ReturningPatientEvidence {
            public function __construct(private bool $returning) {}
            public function qualifies(int $patient_id, int $clinic_id, int $expires_at): bool { return $this->returning; }
        };
        return new SignedLicenseGate($provider, $evidence, static fn (): int => $now);
    }

    public function testBookingAndCommercialFeaturesStopExactlyAtSignedT0DespiteGraceAndSkew(): void
    {
        $t0 = 1793448000;
        foreach ([LicenseGate::OP_APPOINTMENT_BOOK, LicenseGate::OP_APPOINTMENT_RESCHEDULE, LicenseGate::OP_HANDWRITING_NEW] as $op) {
            $this->assertTrue($this->gate($t0, $t0 - 1)->assert($op)->allowed);
            foreach ([$t0, $t0 + 1, $t0 + 300, $t0 + 7 * 86400, $t0 + 200 * 86400] as $now) {
                $this->assertFalse($this->gate($t0, $now, true)->assert($op, ['reception' => true])->allowed, $op . ' at ' . $now);
            }
        }
        // Final 2026-11-17: commercial SMS needed to follow previously booked care stays allowed in
        // Stage 2 (T0..T1-1s) ONLY with server-derived persisted care linkage (design §7.2 item 6;
        // T-R12-14) — synthetic/orphaned contexts stay denied. Stage 3 denies outright.
        $t1 = PostExpirationPolicy::deadline($t0, 1);
        $care = ['care_linked' => true];
        foreach ([$t0, $t0 + 1, $t0 + 300, $t1 - 1] as $now) {
            $this->assertTrue($this->gate($t0, $now, true)->assert(LicenseGate::OP_SMS_COMMERCIAL, $care)->allowed, 'care sms at ' . $now);
        }
        foreach ([$t0, $t0 + 1, $t1 - 1] as $now) {
            $this->assertFalse($this->gate($t0, $now, true)->assert(LicenseGate::OP_SMS_COMMERCIAL, ['reception' => true])->allowed, 'unlinked sms at ' . $now);
        }
        foreach ([$t1, $t1 + 1, $t1 + 200 * 86400] as $now) {
            $this->assertFalse($this->gate($t0, $now, true)->assert(LicenseGate::OP_SMS_COMMERCIAL, $care)->allowed, 'sms at ' . $now);
        }
    }

    public function testGregorianMonthsClampAndKeepUtcTime(): void
    {
        foreach ([
            ['2028-01-31 12:34:56', 1, '2028-02-29 12:34:56'],
            ['2027-01-31 12:34:56', 1, '2027-02-28 12:34:56'],
            ['2026-08-31 23:59:59', 6, '2027-02-28 23:59:59'],
            ['2027-12-31 12:34:56', 1, '2028-01-31 12:34:56'],
        ] as [$start, $months, $expected]) {
            $this->assertSame(strtotime($expected . ' UTC'), PostExpirationPolicy::deadline(strtotime($start . ' UTC'), $months));
        }
    }

    public function testNewAndVerifiedReturningPatientExactHalfOpenWindows(): void
    {
        // Final Q1(a)/Q2(a)/Q3(a) (2026-11-17): every new patient row, self-enrollment,
        // reception creation and appointment-free/first-admission is denied from T0;
        // returning/reception flags and pre-T0-passed evidence grant nothing from T0.
        // Boundary points: T0−1s allow (Stage 1), T0 deny, T1−1s deny, T1 deny, T1+1s deny.
        $t0 = strtotime('2028-01-31 12:34:56 UTC');
        $t1 = PostExpirationPolicy::deadline($t0, 1);
        $context = ['patient_id' => 42, 'clinic_id' => 7, 'reception' => true];
        foreach ([LicenseGate::OP_PATIENT_CREATE, LicenseGate::OP_VISIT_CHECKIN] as $op) {
            foreach ([false, true] as $returning) {
                $this->assertTrue($this->gate($t0, $t0 - 1, $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $t0, $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $t1 - 1, $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $t1, $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $t1 + 1, $returning)->assert($op, $context)->allowed);
            }
            $this->assertFalse($this->gate($t0, $t0)->assert($op)->allowed);
            $this->assertFalse($this->gate($t0, $t0, true)->assert($op)->allowed);
        }
    }

    public function testContinuityAndRenewal(): void
    {
        $t0 = strtotime('2027-01-31 UTC');
        $t1 = PostExpirationPolicy::deadline($t0, 1);
        // Stage 2 (T0..T1−1s): existing-record maintenance + finish-lane ops stay open under RBAC.
        foreach ([LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL, LicenseGate::OP_INVOICE_CREATE] as $op) {
            $this->assertTrue($this->gate($t0, $t1 - 1)->assert($op)->allowed);
        }
        // Stage 3 (now ≥ T1): view-only; even finish-lane ops without an opened-Visit context are closed;
        // historical refunds are denied exactly at T1 (final Q4a(a)); reads/hygiene outside the blocked list stay allowed.
        foreach ([LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL, LicenseGate::OP_INVOICE_CREATE, LicenseGate::OP_REFUND] as $op) {
            $this->assertFalse($this->gate($t0, $t1)->assert($op)->allowed);
        }
        foreach (['clinical.note', 'clinical.export', 'backup.restore'] as $op) {
            $this->assertTrue($this->gate($t0, $t1)->assert($op)->allowed);
        }
        $this->assertFalse($this->gate($t0, $t1)->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        // Q4a(a): a historical refund stays allowed through T1−1s under RBAC and is denied exactly at T1.
        $this->assertTrue($this->gate($t0, $t1 - 1)->assert(LicenseGate::OP_REFUND, ['original_payment_time' => $t1 - 2])->allowed);
        $this->assertFalse($this->gate($t0, $t1)->assert(LicenseGate::OP_REFUND, ['original_payment_time' => $t1 - 1])->allowed);
        // A freshly renewed term (its own Stage 1) restores new business.
        $this->assertTrue($this->gate($t1 + 86400, $t1)->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testHandwritingForOpenedVisitExactStage2AndStage3HalfOpenBoundaries(): void
    {
        $t0 = strtotime('2028-01-31 12:34:56 UTC');
        $t1 = PostExpirationPolicy::deadline($t0, 1);

        // Pre-T0 opened visit works in Stage 1, Stage 2 (T0 .. T1-1), and Stage 3 (T1 onward).
        foreach ([$t0 - 1, $t0, $t1 - 1, $t1, $t1 + 86400] as $now) {
            $this->assertTrue(
                $this->gate($t0, $now)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $t0 - 60,
                ])->allowed,
                "pre-T0 opened visit must allow handwriting at {$now}"
            );
        }

        // Visit opened in Stage 2 just before T1 remains eligible at/after T1.
        foreach ([$t1 - 1, $t1, $t1 + 86400] as $now) {
            $this->assertTrue(
                $this->gate($t0, $now)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $t1 - 1,
                ])->allowed,
                "Stage-2 opened visit must allow handwriting at {$now}"
            );
        }

        // Visit created at or after T1 is not an already-opened pre-T1 visit in Stage 3.
        foreach ([$t1, $t1 + 1] as $now) {
            $this->assertFalse(
                $this->gate($t0, $now)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $t1,
                ])->allowed,
                "post-T1 visit must be denied handwriting in Stage 3 at {$now}"
            );
        }

        // Closed/terminal visit (visit_opened=false) is denied in Stage 2 and Stage 3.
        foreach ([$t0, $t1 - 1, $t1] as $now) {
            $this->assertFalse(
                $this->gate($t0, $now)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => false,
                    'visit_created_at' => $t0 - 60,
                ])->allowed,
                "closed visit must be denied handwriting after T0 at {$now}"
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use WP_UnitTestCase;

/**
 * سیاست لایسنس صف/مراجعه (§18 دستور F4 — ADR-0023 Seam):
 *
 *  - Read-Only (Expired/Restricted): «ویزیت مستقل جدید» = Walk-in ممنوع
 *    → CLINIC_LICENSE_BLOCKED / 503 (بدون Network Call داخل request).
 *  - Check-in نوبت از پیش موجود (pre-existing Appointment workflow) = مجاز.
 *  - Transitionهای ویزیت در جریان (in-progress Visit) = مجاز تا اتمام ایمن.
 *
 * Gate از طریق Fake تزریق می‌شود (ActiveLicenseGate فعلاً همیشه allow است تا F10)؛
 * تست‌ها VisitService را مستقیم می‌سازند تا سیم‌کشی App تحت تأثیر نباشد.
 */
final class VisitLicenseGateTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.max_recalls', 2);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr License Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "License", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-LIC-0001',
                '09123330999',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('lic_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('lic_doctor', 'cpms_doctor');

        // F9 (ADR-0027): گارد مالکیت ویزیت پزشکها را با wp_user_id تطبیق می‌دهد —
        // بدون پیوند، هر transition پزشک به‌اشتباه 403 می‌شود.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d',
                $this->doctorUserId,
                $this->clinicianId
            )
        );
    }

    // ================= §18 — Read-Only Blocks New Independent Visit =================

    public function testWalkInIsBlockedInReadOnlyMode(): void
    {
        try {
            $this->service(new FakeLicenseGate(false))->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Expected CLINIC_LICENSE_BLOCKED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        // هیچ Visitای نباید ساخته شده باشد (رد قبل از هر نوشتن)
        $count = App::db()->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$this->patientId]
        );
        $this->assertSame(0, (int) $count['n']);
    }

    public function testWalkInSucceedsWithActiveLicense(): void
    {
        // کنترل — همان فراخوانی با Gate فعال موفق است (تفاوت فقط تصمیم Gate است)
        $visit = $this->service(new FakeLicenseGate(true))->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        $this->assertSame('waiting', $visit['status']);
        $this->assertSame('walk_in', $visit['source']);
    }

    // ================= §18 — Pre-existing Appointment Workflow Allowed =================

    public function testCheckInOfPreExistingAppointmentIsAllowedInReadOnlyMode(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        // Three-stage license — Stage 2 (T0 ≤ now < T1): a durable pre-T0
        // pending/confirmed appointment may first-admit while now < T1
        // (design §7.2 items 2 and 7; final Q3(a)). T0 = arrival − 7 days.
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        $apptId = $this->makeAppointment($dt->format('Y-m-d'), $dt->format('H:i:s'), $t0 - 86400);

        $visit = $this->service(new FakeLicenseGate(false, $t0, $t1, $arrival_utc))->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        $this->assertSame('waiting', $visit['status']);
        $this->assertSame('scheduled', $visit['source']);
        $this->assertSame($apptId, (int) $visit['appointment_id']);
    }

    // ================= §18 — In-progress Visit Completes Safely =================

    public function testInProgressVisitTransitionsAreAllowedInReadOnlyMode(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        // Stage 2 fixture (see the check-in test above): pre-T0 appointment, now < T1.
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        $apptId = $this->makeAppointment($dt->format('Y-m-d'), $dt->format('H:i:s'), $t0 - 86400);
        $svc = $this->service(new FakeLicenseGate(false, $t0, $t1, $arrival_utc));

        $visit = $svc->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        // ویزیت در جریان — Call/Start پزشک نباید توسط Gate متوقف شود
        $called = $svc->transition($this->doctorUserId, (int) $visit['id'], 'call');
        $this->assertSame('called', $called['status']);

        $started = $svc->transition($this->doctorUserId, (int) $visit['id'], 'start');
        $this->assertSame('in_consultation', $started['status']);

        $completed = $svc->transition($this->doctorUserId, (int) $visit['id'], 'complete');
        $this->assertSame('consultation_completed', $completed['status']);
    }

    // ================= §19 — Continuity-stage boundary witnesses (design §7.2 item 7; Q3(a)) =================

    public function testCheckInOfAppointmentCreatedAfterT0IsDeniedInContinuityStage(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        // Appointment created INSIDE stage 2 (after T0) — not grandfathered by continuity.
        $apptId = $this->makeAppointment($dt->format('Y-m-d'), $dt->format('H:i:s'), $t0 + 86400);

        try {
            $this->service(new FakeLicenseGate(false, $t0, $t1, $arrival_utc))->checkIn($this->secretaryUserId, $this->patientId, $apptId);
            $this->fail('Expected CLINIC_LICENSE_BLOCKED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        $count = App::db()->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$this->patientId]
        );
        $this->assertSame(0, (int) $count['n']);
    }

    public function testCheckInOfPreExistingAppointmentIsDeniedAtT1Boundary(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        // Q3(a): exactly at T1 (and after) every first admission is denied — half-open boundary.
        $t1_utc = (new \DateTimeImmutable('@' . $t1))->setTimezone(new \DateTimeZone('UTC'));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($t1_utc);
        $apptId = $this->makeAppointment($dt->format('Y-m-d'), $dt->format('H:i:s'), $t0 - 86400);

        try {
            $this->service(new FakeLicenseGate(false, $t0, $t1, $t1))->checkIn($this->secretaryUserId, $this->patientId, $apptId);
            $this->fail('Expected CLINIC_LICENSE_BLOCKED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        $count = App::db()->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$this->patientId]
        );
        $this->assertSame(0, (int) $count['n']);
    }

    public function testNewDemandStaysDeniedInContinuityStage(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        $gate = new FakeLicenseGate(false, $t0, $t1, $arrival_utc);

        // (i) Gate-level: booking/new demand is denied from T0 (final Q1(a)/Q2(a)).
        $book = $gate->assert(LicenseGate::OP_APPOINTMENT_BOOK, []);
        $this->assertFalse($book->allowed);
        $reschedule = $gate->assert(LicenseGate::OP_APPOINTMENT_RESCHEDULE, []);
        $this->assertFalse($reschedule->allowed);

        // (ii) Service-level: walk-in stays blocked through the whole stack; no Visit row.
        try {
            $this->service($gate)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Expected CLINIC_LICENSE_BLOCKED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        $count = App::db()->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$this->patientId]
        );
        $this->assertSame(0, (int) $count['n']);
    }

    public function testAllowedCheckInProvesLicenseGateWasConsulted(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $t0 = $arrival_utc - 7 * 86400;
        $t1 = $arrival_utc + 30 * 86400;
        $apptId = $this->makeAppointment($dt->format('Y-m-d'), $dt->format('H:i:s'), $t0 - 86400);

        $gate = new FakeLicenseGate(false, $t0, $t1, $arrival_utc);
        $this->service($gate)->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        $this->assertArrayHasKey(0, $gate->calls);
        $first = $gate->calls[0];
        $this->assertArrayHasKey('operation', $first);
        $this->assertArrayHasKey('context', $first);
        $this->assertArrayHasKey('appointment_created_at', $first['context']);
        $ops = array_column($gate->calls, 'operation');
        $this->assertContains(LicenseGate::OP_VISIT_CHECKIN, $ops);
        $this->assertSame(1, count(array_filter($ops, static fn ($op) => LicenseGate::OP_VISIT_CHECKIN === $op)));
    }

    protected function tearDown(): void
    {
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        parent::tearDown();
    }

    // ================= Helpers =================

    private function service(LicenseGate $gate): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settingsFactory(),
            App::audit(),
            $gate,
            null,
            null
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function makeAppointment(string $slotDate, string $slotTime, ?int $createdAtUtc = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // created_at pre-T0 for continuity-stage fixtures (design §7.2 item 2); default = now.
        $created_at = null === $createdAtUtc ? $now : gmdate('Y-m-d H:i:s', $createdAtUtc) . '.000';

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $slotDate,
                $slotTime,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'LG-' . bin2hex(random_bytes(6)),
                $this->patientId,
                $this->clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                (new \DateTimeImmutable($slotDate . ' ' . $slotTime, new \DateTimeZone('Asia/Tehran')))->add(new \DateInterval('PT20M'))->format('H:i:s'),
                $created_at,
                $created_at,
                $created_at
            )
        );

        return (int) $wpdb->insert_id;
    }
}

/**
 * Gate تزریقی تست — حداقل قوانین صریحِ سه‌مرحله‌ای؛ پیش‌فرض انکار است و
 * هیچ منطق تولید را آینه نمی‌کند (design §7.2; final Q3(a)):
 *   - OP_VISIT_CHECKIN: فقط در Stage 2 (T0 ≤ now < T1) و فقط برای نوبتِ
 *     pre-T0 (appointment_created_at < T0) با وضعیت pending/confirmed.
 *   - OP_VISIT_FINISH: فقط برای ویزیت بازِ پیشین (visit_opened === true)؛
 *     در Stage 3 (now ≥ T1) فقط اگر ویزیت پیش از T1 باز شده باشد.
 *   - هر عملیات دیگر (از جمله OP_APPOINTMENT_BOOK و walk-in) همیشه رد است.
 * بدون تزریق صریح T0/T1/now همه‌چیز رد می‌شود (fail-closed).
 */
final class FakeLicenseGate implements LicenseGate
{
    /** @var list<array{operation: string, context: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $allowed,
        private readonly ?int $expiresAtUtc = null,
        private readonly ?int $t1Utc = null,
        private readonly ?int $nowUtc = null
    ) {
    }

    public function assert(string $operation, array $context = []): LicenseDecision
    {
        $this->calls[] = [ 'operation' => $operation, 'context' => $context ];
        if ( $this->allowed ) {
            return LicenseDecision::allow();
        }
        if ( LicenseGate::OP_VISIT_FINISH === $operation && true === ( $context['visit_opened'] ?? false ) ) {
            // Safe-finish lane: an already-opened Visit stays finishable; from T1 only if created_at < T1.
            if ( null !== $this->t1Utc && null !== $this->nowUtc && $this->nowUtc >= $this->t1Utc ) {
                $visit_created = $context['visit_created_at'] ?? null;
                if ( ! is_int( $visit_created ) || $visit_created <= 0 || $visit_created >= $this->t1Utc ) {
                    return LicenseDecision::deny( 'license:expired' );
                }
            }
            return LicenseDecision::allow();
        }
        if ( null !== $this->expiresAtUtc && null !== $this->t1Utc && null !== $this->nowUtc
            && $this->nowUtc >= $this->expiresAtUtc && $this->nowUtc < $this->t1Utc
            && LicenseGate::OP_VISIT_CHECKIN === $operation ) {
            // Stage 2 (design §7.2 items 2 and 7): pre-T0 pending/confirmed only, and only while now < T1.
            $created = $context['appointment_created_at'] ?? null;
            $status  = (string) ( $context['appointment_status'] ?? '' );
            if ( is_int( $created ) && $created > 0 && $created < $this->expiresAtUtc
                && in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
                return LicenseDecision::allow();
            }
        }
        return LicenseDecision::deny( 'license:expired' );
    }

    public function state(): string
    {
        return $this->allowed ? 'active' : 'expired';
    }

    public function isReadOnly(): bool
    {
        return ! $this->allowed;
    }
}

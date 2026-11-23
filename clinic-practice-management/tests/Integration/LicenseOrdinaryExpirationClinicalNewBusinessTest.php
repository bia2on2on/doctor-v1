<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use WP_UnitTestCase;

/** Real signed-document/service regressions for the Owner post-expiration policy. */
final class LicenseOrdinaryExpirationClinicalNewBusinessTest extends WP_UnitTestCase
{
    /** @var string|null کلید امضا برای سندِ منقضیِ جعلی */
    public ?string $keypair = null;
    public ?int $expiresAt = null;

    private int $clinicId = 0;
    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->clinicId = $this->migratedDefaultClinicId();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signature tests need real sodium');
        }
        $this->keypair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($this->keypair));
        add_filter('cpms_license_public_key', static fn (): string => $pub);

        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (%d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Dr Ordinary Expiry',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, national_id, birth_date, status, created_at, updated_at)
                 VALUES (%d, %s, "Expiry", "Patient", %s, %s, "1990-01-01", "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'MR-EXP-0001',
                '09127770001',
                PatientTwoProofTestHelper::next_valid_national_id(),
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = (int) wp_create_user('expiry_secretary', 'pass-12345', 'expiry_sec@test.local');
        $secretary = get_userdata($this->secretaryUserId);
        if ($secretary !== false) {
            $secretary->set_role('cpms_secretary');
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        remove_all_filters('cpms_license_dev_mode');
        PatientTwoProofTestHelper::reset();
        parent::tearDown();
    }

    // ================= Fixtures =================

    /**
     * Gateway جعلی با یک رفتار: سندِ امضاشدهٔ منقضیِ خارج از grace
     * (expires_at = now - 10d؛ expiry_grace پیش‌فرض ۷ روز).
     */

    /**
     * Resolve the Clinic created by the initial migration (seed slug `default`)
     * so every fixture references the suite's real Clinic ID.
     */
    private function migratedDefaultClinicId(): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'default'
            )
        );
        $this->assertGreaterThan(0, $id, 'The migrated default Clinic must exist.');

        return $id;
    }

    /**
     * owner decision A (2026-11-09): an @otp.cpms.local email is no longer an
     * identity basis — link the fixture's OTP user to a Patient in the booking
     * Clinic so the personal-identity path applies.
     */
    private function linkBridgePatient(int $userId, string $mobile, string $mrnPrefix): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, national_id, birth_date, status, created_at, updated_at)
                 VALUES (%d, %s, "Bridge", "Patient", %s, %s, "1990-01-01", "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $mrnPrefix . '-' . bin2hex(random_bytes(3)),
                $mobile,
                PatientTwoProofTestHelper::next_valid_national_id(),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $patientId, 'fixture: bridge patient created');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $patientId,
                $userId,
                $mobile,
                $now
            )
        );
        PatientTwoProofTestHelper::seed_patient_proof(
            $userId,
            $this->clinicId,
            $patientId,
            ['mobile' => $mobile, 'seed_session_scope' => true]
        );
    }

    private function expiredGateway(): VendorGateway
    {
        return new class($this) implements VendorGateway {
            public function __construct(private readonly LicenseOrdinaryExpirationClinicalNewBusinessTest $t)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                return $this->doc($request);
            }

            public function refresh(array $request): array
            {
                return $this->doc($request);
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array{payload: array<string, mixed>, signature_b64: string}
             */
            private function doc(array $request): array
            {
                $expires = $this->t->expiresAt ?? time() - 10 * 86400;
                $payload = [
                    'product' => 'cpms',
                    'license_id' => 'lic-ordinary-expired',
                    'install_id' => (string) ($request['install_id'] ?? ''),
                    'issued_at' => $expires - 365 * 86400,
                    'expires_at' => $expires,
                    'revoked' => false,
                    'suspended' => false,
                    'plan' => 'annual',
                    'entitlements' => ['features' => ['updates' => false]],
                ];
                $sig = sodium_crypto_sign_detached(
                    LicenseSignature::canonicalJson($payload),
                    sodium_crypto_sign_secretkey($this->t->keypair)
                );

                return ['payload' => $payload, 'signature_b64' => base64_encode($sig)];
            }
        };
    }

    private function licenseService(): LicenseService
    {
        return new LicenseService(
            new LicenseRepository(App::db()),
            $this->expiredGateway(),
            App::db()
        );
    }

    /**
     * نصبِ واقعیِ سندِ منقضی (اعتبارسنجی امضا + ذخیره) — مسیر تولید.
     */
    private function installOrdinaryExpiredLicense(): LicenseService
    {
        $service = $this->licenseService();
        $service->activateWithKey('key-ordinary-expired');

        return $service;
    }

    /**
     * VisitService واقعی با Gate واقعی (SignedLicenseGate روی LicenseService
     * واقعی) — همان سیم‌کشی `VisitLicenseGateTest` با Gate واقعی به‌جای Fake.
     */
    private function visitService(LicenseService $licenses, ?\Closure $clock = null): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settingsFactory(),
            App::audit(),
            new SignedLicenseGate($licenses, new \ClinicCore\Infrastructure\Repository\ReturningPatientRepository($db), $clock),
            null,
            null
        );
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(int $dayOffset, string $time, int $capacity = 1): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (%d, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1), %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->clinicId,
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time];
    }

    public function testBookingRechecksT0AfterCapacityAndSlotLockWaits(): void
    {
        $this->expiresAt = time() + 86400;
        $licenses = $this->installOrdinaryExpiredLicense();
        $now = $this->expiresAt - 1;
        $clock = static function () use (&$now): int { return $now; };
        $db = App::db();
        $service = new \ClinicCore\Application\Booking\BookingService(
            $db, new \ClinicCore\Infrastructure\Repository\SlotRepository($db),
            new AppointmentRepository($db), new \ClinicCore\Infrastructure\Repository\PatientRepository($db),
            App::settingsFactory(), new SignedLicenseGate($licenses, null, $clock),
            App::audit(), App::op(), new \ClinicCore\Infrastructure\Security\Idempotency($db), App::smsService()
        );
        $slot = $this->makeSlot(2, '13:20');
        $user = (int) wp_create_user('expiry_lock_public', 'pass-12345', '09127770888@otp.cpms.local');
        // owner decision A (2026-11-09): shared mobile is never an ownership basis —
        // the booking actor must carry an active Patient link in the booking Clinic.
        $this->linkBridgePatient($user, '09127770888', 'MR-EXP-LOCK');
        // First demonstrate a hold capacity lock returning after T0.
        $cross = function (string $sql) use (&$now): string {
            if (str_contains($sql, 'cpms_schedule_slots') && (str_contains($sql, 'FOR UPDATE') || str_contains($sql, 'UPDATE '))) {
                $now = $this->expiresAt;
            }
            return $sql;
        };
        add_filter('query', $cross);
        try {
            $service->hold($user, $this->clinicianId, $slot['date'], '13:20');
            $this->fail('Hold must recheck after capacity lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $this->assertSame(0, (int) $db->fetchValue('SELECT held_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d', [$slot['id']]));
        $now = $this->expiresAt - 1;
        $hold = $service->hold($user, $this->clinicianId, $slot['date'], '13:20');
        add_filter('query', $cross);
        try {
            $service->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'New', 'After lock');
            $this->fail('Confirm must recheck after slot lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $now = $this->expiresAt - 1;
        add_filter('query', $cross);
        try {
            $service->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '13:20', null);
            $this->fail('Staff booking must recheck after slot lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments')));
    }

    public function testRestStaffBookingCannotBypassT0(): void
    {
        $this->expiresAt = time() - 1;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '12:20');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        wp_set_current_user($this->secretaryUserId);
        $request = new \WP_REST_Request('POST', '/clinic/v1/appointments');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $this->clinicId);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'patient_id' => $this->patientId, 'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'], 'slot_time' => '12:20', 'slot_id' => $slot['id'],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(503, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertStringContainsString('CLINIC_LICENSE_BLOCKED', wp_json_encode($response->get_data()));
    }

    public function testPublicHoldAndPreT0HoldConfirmationCannotBookAfterT0(): void
    {
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '11:20');
        $user = (int) wp_create_user('expiry_public', 'pass-12345', '09127770999@otp.cpms.local');
        (new \WP_User($user))->set_role('cpms_patient');
        // owner decision A (2026-11-09): shared mobile is never an ownership basis —
        // the booking actor must carry an active Patient link in the booking Clinic.
        $this->linkBridgePatient($user, '09127770999', 'MR-EXP-PUB');
        $hold = App::bookingService()->hold($user, $this->clinicianId, $slot['date'], '11:20');
        $this->assertNotEmpty($hold['hold_token']);
        $this->expiresAt = time() - 1; // Grace and skew must NOT postpone shutdown.
        $this->installOrdinaryExpiredLicense();
        foreach (['hold', 'confirm'] as $operation) {
            try {
                if ($operation === 'hold') {
                    App::bookingService()->hold($user, $this->clinicianId, $slot['date'], '11:20');
                } else {
                    App::bookingService()->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'New', 'Patient');
                }
                $this->fail('Booking must stop at T0');
            } catch (BookingException $e) {
                $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            }
        }
        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $confirmed = App::bookingService()->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'Renewed', 'Patient');
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    public function testExistingAppointmentRemainsActionableAfterAllNewServiceWindows(): void
    {
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '14:20');
        $appointment = App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '14:20', null);
        $this->expiresAt = time() - 86400; // Stage 2 (T0+1d < T1) — previously booked appointments remain followable
        $licenses = $this->installOrdinaryExpiredLicense();
        $id = (int) $appointment['appointment_id'];
        // Fixture an appointment booked before the now-expired signed term.
        App::db()->update('cpms_appointments', ['created_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1), 'booked_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1)], ['id' => $id]);
        // Simulate arrival on the booked Location-local calendar date; license
        // continuity is independent of the separate same-day check-in rule.
        $timezone = (string) App::db()->fetchValue(
            'SELECT l.timezone FROM ' . App::db()->table('cpms_locations') . ' l JOIN '
            . App::db()->table('cpms_appointments') . ' a ON a.location_id = l.id WHERE a.id = %d',
            [$id]
        );
        $this->assertNotEmpty($timezone);
        $arrival = new \DateTimeImmutable($slot['date'] . ' 00:00:00', new \DateTimeZone($timezone));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        try {
            $visit = $this->visitService($licenses)->checkIn($this->secretaryUserId, $this->patientId, $id);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }
        $this->assertSame('waiting', $visit['status']);
        $this->assertSame($id, (int) $visit['appointment_id']);
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);

        // Final Q3(a) (2026-11-17): at/after T1 no first admission of any kind — not even a
        // persisted pre-T0 pending/confirmed appointment — is admitted.
        $this->expiresAt = time() - 45 * 86400; // signed T0 45d ago; T1 ≈ 15d ago
        $late = $this->installOrdinaryExpiredLicense();
        try {
            $this->visitService($late)->checkIn($this->secretaryUserId, $this->patientId, $id);
            $this->fail('Q3(a): first admission at/after T1 must be denied');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
    }

    /**
     * PR16-ROUND10 (reader fix): the Q3 tail of
     * testExistingAppointmentRemainsActionableAfterAllNewServiceWindows reuses the
     * already-admitted appointment and moves T0 behind admission, so its denial is
     * attributable to post-T0 provenance as well as T1. This test keeps ONE fixed
     * late signed term and three UNADMITTED pre-T0 appointments; every denial fires
     * inside the license gate before any mutation (design §7.2 items 2 and 7;
     * final Q3(a)).
     */
    public function testQ3FirstAdmissionUnadmittedPreT0AppointmentsDenyExactlyAtT1AndAfter(): void
    {
        // ONE fixed late signed term — T0 is never moved after admission (§7.2 item 7).
        $t0 = time() - 10 * 86400; // signed 10 days ago (fixed)
        $t1 = \ClinicCore\Domain\Licensing\LicenseStagePolicy::t1($t0); // half-open T1

        // Fixtures are created while an ACTIVE license is installed; installing the
        // expired term before creation would deny staff booking (new demand).
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();

        $slot_a = $this->makeSlot(2, '14:20');
        $slot_b = $this->makeSlot(2, '15:20');
        $slot_c = $this->makeSlot(2, '16:20');
        $appt_a = App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot_a['date'], '14:20', null);
        $appt_b = App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot_b['date'], '15:20', null);
        $appt_c = App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot_c['date'], '16:20', null);
        $ids = [ (int) $appt_a['appointment_id'], (int) $appt_b['appointment_id'], (int) $appt_c['appointment_id'] ];

        // Backdate created_at/booked_at to BEFORE the fixed late T0 — the only
        // provenance the gate reads (design §7.2 item 2).
        $created = gmdate('Y-m-d H:i:s', $t0 - 10 * 86400);
        foreach ( $ids as $id ) {
            App::db()->update('cpms_appointments', ['created_at' => $created, 'booked_at' => $created], ['id' => $id]);
        }

        // The ONE fixed late signed term used by every call below.
        $this->expiresAt = $t0;
        $late = $this->installOrdinaryExpiredLicense();

        // (1) Pre-T1 control via the REAL gate: an UNADMITTED pre-T0 pending/confirmed
        //     appointment is first-admitted while now < T1.
        $timezone = (string) App::db()->fetchValue(
            'SELECT l.timezone FROM ' . App::db()->table('cpms_locations') . ' l JOIN '
            . App::db()->table('cpms_appointments') . ' a ON a.location_id = l.id WHERE a.id = %d',
            [$ids[0]]
        );
        $this->assertNotEmpty($timezone);
        $arrival = new \DateTimeImmutable($slot_a['date'] . ' 00:00:00', new \DateTimeZone($timezone));
        $arrival_utc = $arrival->setTimezone(new \DateTimeZone('UTC'));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival_utc);
        try {
            $visit = $this->visitService($late, static fn (): int => $arrival_utc->getTimestamp())->checkIn($this->secretaryUserId, $this->patientId, $ids[0]);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }
        $this->assertSame('waiting', $visit['status']);
        $this->assertSame($ids[0], (int) $visit['appointment_id']);

        // (2) Exactly at T1 — same fixed term, UNADMITTED pre-T0 appointment: denied.
        //     created_at < T0 and status qualify; the ONLY failing conjunct of
        //     may_admit_booked_visit is `now < t1` (half-open boundary).
        $t1_utc = (new \DateTimeImmutable('@' . $t1))->setTimezone(new \DateTimeZone('UTC'));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($t1_utc);
        try {
            $this->visitService($late, static fn (): int => $t1)->checkIn($this->secretaryUserId, $this->patientId, $ids[1]);
            $this->fail('Q3(a): first admission exactly at T1 must be denied');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }

        // (3) One hour after T1 — same fixed term, UNADMITTED pre-T0 appointment.
        $late_utc = (new \DateTimeImmutable('@' . ( $t1 + 3600 )))->setTimezone(new \DateTimeZone('UTC'));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($late_utc);
        try {
            $this->visitService($late, static fn (): int => $t1 + 3600)->checkIn($this->secretaryUserId, $this->patientId, $ids[2]);
            $this->fail('Q3(a): first admission after T1 must be denied');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }

        // Denials happened before any persistent mutation: only the pre-T1
        // control created a Visit.
        $visits = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d', [$this->patientId]);
        $this->assertSame(1, $visits);
    }

    public function testNewPatientAndWalkInDeniedFromT0ReturningGrantsNothing(): void
    {
        $this->expiresAt = time() - 60 * 86400;
        $licenses = $this->installOrdinaryExpiredLicense();
        try {
            App::patientService()->create(['first_name' => 'New', 'last_name' => 'Late', 'mobile' => '09127770103'], $this->secretaryUserId);
            $this->fail('New patient window ended');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        try {
            $this->visitService($licenses)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId, ['returning' => true, 'identity_id' => 1]);
            $this->fail('Request metadata cannot prove returning identity');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $db = App::db();
        $org = (int) $db->fetchValue('SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicId]);
        $identity = App::patient_identity_service()->create_identity($org);
        App::patient_identity_service()->link_clinical_record($org, $identity, $this->clinicId, $this->patientId);
        App::patient_identity_service()->link_user($org, $identity, $this->secretaryUserId, '09127770001');
        (new VisitRepository($db))->insert(1, [
            'patient_id' => $this->patientId, 'clinician_id' => $this->clinicianId,
            'visit_date' => gmdate('Y-m-d', $this->expiresAt - 86400),
            'check_in_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 86400),
            'consultation_completed_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1),
            'status' => 'checked_out', 'active' => 0,
        ]);
        // Final Q2(a) (2026-11-17): no walk-in Visit opens from T0 — not even with a
        // server-verified returning identity linked to an existing checked-out record.
        try {
            $this->visitService($licenses)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Final Q2(a): walk-in must be denied from T0');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $deadline = \ClinicCore\Domain\Licensing\LicenseStagePolicy::t1($this->expiresAt);
        try {
            $this->visitService($licenses, static fn (): int => $deadline)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Even verified returning status ends at the exact T1 boundary');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    // ================= قرارداد =================

    public function testExpiredBeyondGraceStaysRepresentedAsExpiredRestricted(): void
    {
        $service = $this->installOrdinaryExpiredLicense();

        // نمایش وضعیت/تمدید بدون تغییر می‌ماند — انقضا پنهان/عادی‌سازی نمی‌شود.
        $state = $service->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('expired', $state['reason']);
        $this->assertTrue($state['needs_renewal']);

        $meta = $service->statusMeta();
        $this->assertTrue($meta['configured']);
        $this->assertSame(LicenseStatus::RESTRICTED, $meta['status']);
        $this->assertSame('expired', $meta['reason']);
        $this->assertTrue($meta['needs_renewal']);

        $gate = new SignedLicenseGate($service);
        $this->assertSame(LicenseStatus::RESTRICTED, $gate->state());
        // Stage 2 is not read-only; read-only begins at T1 (final three-stage rule, 2026-11-17).
        $this->assertFalse($gate->isReadOnly());
    }

    public function testRealGateBlocksDemandButKeepsStage2FinanceOpen(): void
    {
        $gate = new SignedLicenseGate($this->installOrdinaryExpiredLicense());
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_PATIENT_CREATE)->allowed);
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_PATIENT_CREATE, ['reception' => true])->allowed); // Final Q1(a)
        $this->assertTrue($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_INVOICE_CREATE)->allowed); // Stage 2: finish-lane open until T1
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_APPOINTMENT_RESCHEDULE)->allowed); // Stage 2: no new demand
        $this->assertTrue($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_REFUND, ['original_payment_time' => time() - 86400])->allowed); // Q4a: allowed through T1−1s
    }

    public function testReceptionCannotCreateNewPatientAtOrAfterT0(): void
    {
        $this->installOrdinaryExpiredLicense();

        // Final Q1(a) (2026-11-17): no new patient row from T0 — the reception exception is retired.
        try {
            App::patientService()->create([
                'first_name' => 'مریم',
                'last_name' => 'انقضای عادی',
                'mobile' => '09127770102',
            ], $this->secretaryUserId);
            $this->fail('انقضای عادی تجاری از T0 ثبت بیمار جدید را مسدود می‌کند (Q1(a))');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }

        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE mobile = %s', ['09127770102']));
    }

    public function testOrdinaryExpirationBlocksStaffAppointmentBooking(): void
    {
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '10:20');
        try {
            App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '10:20', null);
            $this->fail('Expected license block');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
    }

    public function testReceptionCannotStartWalkInAtOrAfterT0(): void
    {
        $service = $this->installOrdinaryExpiredLicense();

        // Final Q2(a) (2026-11-17): appointment-free walk-ins are denied from T0.
        try {
            $this->visitService($service)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('انقضای عادی تجاری از T0 ثبت مراجعهٔ بدون نوبت را مسدود می‌کند (Q2(a))');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }

        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . " WHERE source = 'walk_in'"));
        $gate = new SignedLicenseGate($service);
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_VISIT_CHECKIN)->allowed); // Stage 2: no admission without pre-T0 provenance
    }
}

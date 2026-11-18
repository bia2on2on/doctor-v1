<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * RED 1 — Production wiring must be scope-neutral.
 *
 * Proves visits.no_show handler construction via App::visitService() throws
 * CLINIC_SCOPE_REQUIRED in multi-Clinic no-Scope worker.
 *
 * Expected RED: job fails with CLINIC_SCOPE_REQUIRED.
 * After fix: job succeeds (no CLINIC_SCOPE_REQUIRED).
 */
final class VisitNoShowM2WiringRedTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62300;
    private const FX_CLINIC_A_ID = 62301;
    private const FX_CLINIC_B_ID = 62302;
    private const FX_LOC_A_ID = 62310;
    private const FX_LOC_B_ID = 62311;
    private const FX_ID_FLOOR = 62300;

    private function resetAppCaches(): void
    {
        // Reset class-level private static properties — use project's safe convention
        // Includes visitService which is now class-level (was function-static) to allow test reset
        $refClass = new \ReflectionClass(App::class);
        foreach (['db','op','audit','jobs','rate','loginRateLimiter','idem','settingsFactory','migrations','dispatcher','providers','vault','smsService','licenseGate','visitService'] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (\Throwable $e) {
            }
        }
    }

    private function assertVisitServiceCacheIsFresh(): void
    {
        $refClass = new \ReflectionClass(App::class);
        if ($refClass->hasProperty('visitService')) {
            $prop = $refClass->getProperty('visitService');
            $prop->setAccessible(true);
            $value = $prop->getValue();
            self::assertNull($value, 'App::$visitService cache must be null/fresh before tick');
        }
        if ($refClass->hasProperty('dispatcher')) {
            $prop = $refClass->getProperty('dispatcher');
            $prop->setAccessible(true);
            $value = $prop->getValue();
            self::assertNull($value, 'App::$dispatcher cache must be null/fresh before tick');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        // Ensure no WP user
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        // Assert no leftover
        $leftover = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertSame(0, $leftover, 'precondition: no leftover fixture rows');

        // Org
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, "active", %s, %s)',
            self::FX_ORG_ID,
            'Wiring Org',
            'wiring-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));

        // Clinic A
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_A_ID,
            self::FX_ORG_ID,
            'Wiring Clinic A',
            'wiring-clinic-a-' . bin2hex(random_bytes(3)),
            'Europe/Berlin',
            $now,
            $now
        ));

        // Clinic B
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_ORG_ID,
            'Wiring Clinic B',
            'wiring-clinic-b-' . bin2hex(random_bytes(3)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Locations
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_A_ID,
            self::FX_CLINIC_A_ID,
            'Wiring Loc A',
            'wiring-loc-a-' . bin2hex(random_bytes(2)),
            'Europe/Berlin',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_B_ID,
            self::FX_CLINIC_B_ID,
            'Wiring Loc B',
            'wiring-loc-b-' . bin2hex(random_bytes(2)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Minimal settings per clinic to avoid default ambiguity
        \ClinicCore\Settings\Settings::flushCache();
        $settingsA = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_A_ID, App::audit());
        $settingsA->set('queue.no_show_grace_minutes', 30);
        $settingsB = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_B_ID, App::audit());
        $settingsB->set('queue.no_show_grace_minutes', 30);
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        // Settings::set() writes audit rows that reference the Clinic, so they have to
        // go before the Clinic row or the FK blocks it and the fixture is left behind.
        $wpdb->query('DELETE FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR . ')');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        if (method_exists(App::class, 'settingsFactory')) {
            App::settingsFactory()->reset();
        }
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN ("visits.no_show","slots.generate","holds.expire","cleanup.otp","cleanup.rate_limits","cleanup.idem","cleanup.oplog","handwriting.gc","notif.dispatch","appt.reminder","fu.reminder","license.refresh","backup.run","report.export","sms.send")');
    }

    /**
     * Restored production-wiring evidence (reader finding T1/A3), adapted to R4.
     *
     * Unchanged from main: the multi-Clinic fixture, the "no ScopeContext / no WP
     * user / fresh container caches" preconditions, the CLINIC_SCOPE_REQUIRED proof
     * that the worker really has no Clinic, the legacy `[]` payload enqueued through
     * `App::jobs()` and drained through the production path (`App::runTick()` →
     * dispatcher → container-built `VisitsNoShowHandler`), and the "job ends as
     * success with max_attempts=1" contract.
     *
     * Adapted (R4 policy, not a weakening): success now means "the inert handler
     * completed", so the run must also leave both Clinics untouched — no status
     * change, no Visit, no pointer, no audit row, no counter change and no retry or
     * continuation job. Those are strictly more assertions than main had.
     */
    public function testProductionWiringMustBeScopeNeutral(): void
    {
        global $wpdb;
        $db = App::db();

        // R4: the wiring is registered; the schedule entry is not.
        self::assertArrayNotHasKey(
            'visits.no_show',
            App::RECURRING_JOBS,
            'R4: the recurring no-show sweep must not be scheduled'
        );

        // ---- Precondition: >=2 real Clinics (fixture + total) ----
        $clinicCountFixture = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertGreaterThanOrEqual(2, $clinicCountFixture, 'fixture must have at least two real clinics');

        $clinicAExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_A_ID));
        $clinicBExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_B_ID));
        self::assertNotEmpty($clinicAExists, 'Clinic A must exist');
        self::assertNotEmpty($clinicBExists, 'Clinic B must exist');

        $countAll = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countAll, 'total clinic count must be >1 to trigger CLINIC_SCOPE_REQUIRED, found ' . $countAll);

        // ---- Precondition: no ScopeContext, no current WP user, fresh caches ----
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must be set for this test');
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current WP user');
        $this->assertVisitServiceCacheIsFresh();

        // ---- Precondition: App::scope() throws with multiple Clinics and no explicit scope ----
        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics exist and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'scope must throw CLINIC_SCOPE_REQUIRED');
        }

        // ---- Legacy sweep candidates in BOTH Clinics (past grace, still confirmed) ----
        $candidateA = $this->seedPastCandidate(self::FX_CLINIC_A_ID, self::FX_LOC_A_ID, '09:00:00', 'A');
        $candidateB = $this->seedPastCandidate(self::FX_CLINIC_B_ID, self::FX_LOC_B_ID, '09:30:00', 'B');

        // ---- Enqueue the LEGACY root payload and drain it through the REAL path ----
        $this->purgeJobs();
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('visits.no_show', [], $now, 5, 1);
        self::assertGreaterThan(0, $jobId, 'job enqueued');

        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobBefore, 'job row exists before tick');
        self::assertSame('queued', $jobBefore['status'], 'job initially queued');
        self::assertSame(1, (int) $jobBefore['max_attempts'], 'maxAttempts must be 1 so a wiring failure cannot be retried');

        // ---- Real path: App::runTick -> production dispatcher -> container-built handler ----
        $tickResult = App::runTick(5);

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist after tick');

        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        self::assertSame(
            'success',
            $status,
            'the legacy payload must drain to SUCCESS through the production path (R4: the handler is inert). '
            . 'Found status=' . $status . ' last_error=' . $lastError . ' tickResult=' . var_export($tickResult, true)
        );
        self::assertSame(1, (int) ($jobAfter['attempts'] ?? 0), 'the inert handler must not trigger a retry');

        // ---- Nothing mutated, in either Clinic ----
        foreach (['clinic A' => $candidateA, 'clinic B' => $candidateB] as $label => $candidate) {
            $row = $this->appointmentRow((int) $candidate['appointment_id']);
            self::assertSame('confirmed', (string) $row['status'], $label . ': R4 never marks a no-show automatically');
            self::assertNull($row['no_show_at'], $label . ': no_show_at must stay NULL');
            self::assertNull($row['active_visit_id'], $label . ': no Visit pointer may be written');
            self::assertSame(0, $this->visitCount((int) $candidate['appointment_id']), $label . ': no Visit may be created');
            $slot = $this->slotRow((int) $candidate['slot_id']);
            self::assertSame(1, (int) $slot['booked_count'], $label . ': booked counter unchanged');
            self::assertSame(0, (int) $slot['held_count'], $label . ': held counter unchanged');
            self::assertSame(0, $this->noShowAuditCount((int) $candidate['appointment_id']), $label . ': no APPOINTMENT_NO_SHOW audit row');
        }

        // ---- No continuation / retry job may be left behind by the drained legacy job ----
        $pending = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . " WHERE type = 'visits.no_show' AND status IN ('queued','processing','failed')"
        );
        self::assertSame(0, $pending, 'the inert handler must not leave a continuation or a retry job');
    }

    /**
     * A confirmed appointment three days in the past (well past any grace) plus its
     * own Clinician, Patient and slot, in the given Clinic/Location.
     *
     * @return array{appointment_id: int, slot_id: int}
     */
    private function seedPastCandidate(int $clinicId, int $locationId, string $time, string $tag): array
    {
        global $wpdb;
        $db      = App::db();
        $now     = $db->nowUtcSql();
        $date    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-3 days')->format('Y-m-d');
        $suffix  = bin2hex(random_bytes(3));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $clinicId,
            'Dr Wiring ' . $tag . ' ' . $suffix,
            $now,
            $now
        ));
        $clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicianId, 'fixture: clinician inserted in ' . $tag);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
            . ' VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-WIRING-' . $suffix,
            'Wiring',
            'Patient',
            '09' . random_int(10000000, 99999999),
            $now,
            $now
        ));
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId, 'fixture: patient inserted in ' . $tag);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_schedule_slots')
            . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $date,
            $time,
            $now,
            $now
        ));
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'fixture: slot inserted in ' . $tag);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_appointments')
            . ' (clinic_id, location_id, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, status, reference_code, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, %d, %s, %s, 20, "confirmed", %s, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            $slotId,
            $date,
            $time,
            'WIRE-' . $suffix,
            $now,
            $now
        ));
        $appointmentId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $appointmentId, 'fixture: appointment inserted in ' . $tag);

        return ['appointment_id' => $appointmentId, 'slot_id' => $slotId];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(int $appointmentId): array
    {
        $row = App::db()->fetchRow(
            'SELECT status, no_show_at, active_visit_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
        self::assertIsArray($row, 'the appointment row must exist: id=' . $appointmentId);
        self::assertArrayHasKey('status', $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRow(int $slotId): array
    {
        $row = App::db()->fetchRow(
            'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
        self::assertIsArray($row, 'the slot row must exist: id=' . $slotId);

        return $row;
    }

    private function visitCount(int $appointmentId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d',
            [$appointmentId]
        );
    }

    private function noShowAuditCount(int $appointmentId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE action = 'APPOINTMENT_NO_SHOW' AND resource_type = 'appointment' AND resource_id = %d",
            [$appointmentId]
        );
    }
}

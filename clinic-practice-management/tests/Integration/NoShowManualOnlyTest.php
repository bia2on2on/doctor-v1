<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\VisitsNoShowHandler;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * R4 (1.0) — no-show is MANUAL-ONLY.
 *
 * Replaces the pre-R4 automatic-sweep assertions (equal-or-stronger): the old
 * tests proved "the sweep marks late rows"; these prove the opposite contract and
 * the protections around it:
 *  1. `App::RECURRING_JOBS` no longer schedules `visits.no_show` (no automatic run).
 *  2. `VisitService::processNoShows()` is inert at ANY time, including long past
 *     the historical grace window: zero processed, no status change, no
 *     `no_show_at`, no audit row, no continuation cursor.
 *  3. An already-queued `visits.no_show` job (legacy payloads included) completes
 *     as a harmless no-op through the real `App::runTick()` path — no failure, no
 *     retry/continuation storm, no mutation.
 *  4. The manual path still works end-to-end (reason + actor + audit) and stays
 *     least-privilege: roles without `cpms_appt_no_show` (patient, administrator)
 *     are denied.
 */
final class NoShowManualOnlyTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    private const NS = '/clinic/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMultiLocationTemporalFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        ScopeContext::clear();
        App::resetScope();
        parent::tearDown();
    }

    public function testRecurringJobsNoLongerScheduleTheAutomaticSweep(): void
    {
        self::assertArrayNotHasKey(
            'visits.no_show',
            App::RECURRING_JOBS,
            'R4: the recurring no-show sweep must not be scheduled in 1.0'
        );

        // The recurring map must still schedule the other lanes — this is not an
        // empty-map false pass.
        self::assertArrayHasKey('notif.dispatch', App::RECURRING_JOBS);
        self::assertArrayHasKey('holds.expire', App::RECURRING_JOBS);
    }

    public function testSweepIsInertLongPastTheHistoricalGraceWindow(): void
    {
        $pastDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-3 days')
            ->format('Y-m-d');
        $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $pastDate, '09:00:00');
        $appointmentId = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $pastDate, '09:00:00', 'confirmed');

        $before = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $before['status']);

        $root = App::visitService()->processNoShows(null, 0);
        self::assertSame(0, (int) $root['processed'], 'R4: the sweep must process nothing');
        self::assertSame(0, (int) $root['scanned'], 'R4: the sweep must not even scan');
        self::assertNull($root['next_cursor'], 'R4: no continuation cursor may be produced');
        self::assertFalse((bool) $root['has_more'], 'R4: no continuation work may be requested');

        // Legacy wrapper and a cursor continuation are inert too.
        self::assertSame(0, App::visitService()->processNoShowsLegacy());
        $cursorRes = App::visitService()->processNoShows(
            ['slot_date' => $pastDate, 'slot_time' => '09:00:00', 'id' => $appointmentId],
            1
        );
        self::assertSame(0, (int) $cursorRes['processed']);

        $after = $this->appointmentRow($appointmentId);
        self::assertSame((string) $before['status'], (string) $after['status'], 'status must not change');
        self::assertNull($after['no_show_at'], 'no_show_at must stay NULL without a manual action');
        self::assertSame(
            0,
            $this->auditCount('APPOINTMENT_NO_SHOW', $appointmentId),
            'the inert sweep must not write an APPOINTMENT_NO_SHOW audit row'
        );
    }

    public function testQueuedLegacyJobCompletesAsHarmlessNoOpWithoutRetryStorm(): void
    {
        $pastDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-3 days')
            ->format('Y-m-d');
        $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $pastDate, '10:00:00');
        $appointmentId = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $pastDate, '10:00:00', 'confirmed');

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = App::jobs()->enqueue('visits.no_show', [], $nowUtc, 5, 3);
        self::assertGreaterThan(0, $jobId, 'the legacy job must exist before the tick');

        App::runTick(20);

        $job = App::db()->fetchRow(
            'SELECT status, attempts FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertIsArray($job, 'the queued job must still exist (audit trail)');
        self::assertSame('success', (string) $job['status'], 'the legacy job must complete cleanly, not fail');
        self::assertLessThanOrEqual(1, (int) $job['attempts'], 'no retry storm: at most one attempt');

        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . " WHERE type = 'visits.no_show'"
            ) - 1,
            'the handler must not enqueue a continuation visits.no_show job'
        );

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status'], 'a queued job must not mutate the appointment');
        self::assertNull($row['no_show_at']);
        self::assertSame(0, $this->auditCount('APPOINTMENT_NO_SHOW', $appointmentId));
    }

    public function testDirectHandlerInvocationIsInertForEveryLegacyPayload(): void
    {
        $pastDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-3 days')
            ->format('Y-m-d');
        $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $pastDate, '11:00:00');
        $appointmentId = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $pastDate, '11:00:00', 'confirmed');

        $handler = new VisitsNoShowHandler(App::visitService(), App::jobs(), App::db(), App::op());

        self::assertSame(0, $handler([]), 'root payload must be an inert no-op');
        self::assertSame(0, $handler([
            'cursor' => ['slot_date' => $pastDate, 'slot_time' => '11:00:00', 'id' => $appointmentId],
            'continuation' => true,
            'depth' => 1,
        ]), 'legacy continuation payload must be an inert no-op');

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status']);
        self::assertNull($row['no_show_at']);
    }

    public function testManualNoShowStillWorksWithReasonActorAndAudit(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $today, '12:00:00');
        $appointmentId = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $today, '12:00:00', 'confirmed');

        $secretaryId = $this->makeUser('r4_sec');
        $membership  = cpms_test_seed_membership($secretaryId, self::FX_T_CLINIC_ID, 'cpms_secretary');
        self::assertGreaterThan(0, $membership);
        wp_set_current_user($secretaryId);

        $reason = 'عدم حضور در ساعت مقرر';
        $res    = $this->dispatchNoShow($appointmentId, $reason);
        self::assertSame(200, $res->get_status(), 'the manual no-show path must stay available in 1.0');

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('no_show', (string) $row['status'], 'a manual action is the ONLY way to no_show');
        self::assertNotNull($row['no_show_at'], 'the manual action stamps no_show_at');

        $audit = App::db()->fetchRow(
            'SELECT actor_wp_user_id, actor_role, meta_json FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            ['APPOINTMENT_NO_SHOW', $appointmentId]
        );
        self::assertIsArray($audit, 'a manual no-show must be audited');
        self::assertSame($secretaryId, (int) $audit['actor_wp_user_id'], 'the actor must be recorded');
        self::assertSame('staff', (string) $audit['actor_role'], 'the actor role must be recorded');
        self::assertStringContainsString($reason, (string) $audit['meta_json'], 'the reason must be recorded');
    }

    public function testManualNoShowStaysDeniedWithoutAMembershipOrCapability(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $today, '13:00:00');
        $appointmentId = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $today, '13:00:00', 'confirmed');

        // A WP administrator is NOT automatically clinic staff: no membership, no scope.
        foreach (['administrator', 'subscriber'] as $wpRole) {
            $userId = $this->makeUser('r4_deny_' . $wpRole);
            $user   = get_userdata($userId);
            self::assertNotFalse($user);
            $user->set_role($wpRole);
            wp_set_current_user($userId);

            $res = $this->dispatchNoShow($appointmentId, 'عدم مراجعه');
            self::assertContains(
                $res->get_status(),
                [401, 403, 404],
                $wpRole . ' without a clinic membership must not mark a no-show'
            );
            self::assertSame(
                'confirmed',
                (string) $this->appointmentRow($appointmentId)['status'],
                'a denied caller must not mutate the appointment'
            );
            self::assertSame(0, $this->auditCount('APPOINTMENT_NO_SHOW', $appointmentId));
        }
    }

    // ================= helpers =================

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(int $appointmentId): array
    {
        $row = App::db()->fetchRow(
            'SELECT status, no_show_at FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );

        return is_array($row) ? $row : [];
    }

    private function auditCount(string $action, int $resourceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d',
            [$action, $resourceId]
        );
    }

    private function makeUser(string $login): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $id     = (int) wp_create_user($unique, 'pass-not-used-123', $unique . '@test.local');
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function dispatchNoShow(int $appointmentId, string $reason): WP_REST_Response
    {
        return $this->dispatch(
            'POST',
            '/clinic/v1/staff/portal/reception/appointments/' . $appointmentId . '/no-show',
            ['reason' => $reason],
            [
                'X-CPMS-Clinic-Id' => (string) self::FX_T_CLINIC_ID,
                'X-CPMS-Location-Id' => (string) self::FX_T_LOC_A_ID,
            ]
        );
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
        $req = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $key => $value) {
            $req->set_header($key, $value);
        }

        return rest_do_request($req);
    }
}

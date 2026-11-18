<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — the queue can no longer create a no-show root of its own.
 *
 * Pre-R4 this suite protected root suppression while a chain was processing and
 * fresh roots afterwards. With the recurring schedule removed, the equal-or-stronger
 * contract is that a tick creates NO `visits.no_show` job, keeps an already-queued
 * legacy job from failing, and never enqueues a continuation.
 */
final class VisitNoShowQueueHardeningTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMultiLocationTemporalFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        parent::tearDown();
    }

    public function testSchedulerSuppressesRootWhileProcessing(): void
    {
        self::assertSame(0, $this->noShowJobCount(), 'precondition: no legacy job rows');
        App::runTick(20);
        self::assertSame(0, $this->noShowJobCount(), 'R4: a tick must not create a no-show root job');
    }

    public function testSchedulerCreatesFreshRootAfterChainFinishes(): void
    {
        App::runTick(20);
        App::runTick(20);
        self::assertSame(0, $this->noShowJobCount(), 'no root is ever re-created: the schedule entry is gone');
        self::assertArrayNotHasKey('visits.no_show', App::RECURRING_JOBS);
    }

    /**
     * A4 — the GENERIC queue-hardening rule ("an existing PROCESSING recurring job
     * suppresses a duplicate root") lost its home when the no-show schedule entry
     * was removed. It is re-homed here on an ACTIVE recurring lane
     * (`notif.dispatch`), so the protection stays asserted somewhere real.
     */
    public function testSchedulerSuppressesDuplicateRootWhileProcessingOnAnActiveLane(): void
    {
        global $wpdb;
        $db      = App::db();
        $nowSql  = $db->nowUtcSql();
        $lockExp = gmdate('Y-m-d H:i:s', time() + 600) . '.000';

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = %s', 'notif.dispatch'));

        $ok = $wpdb->insert(
            $db->table('cpms_jobs'),
            [
                'type'            => 'notif.dispatch',
                'payload_json'    => '[]',
                'status'          => 'processing',
                'priority'        => 6,
                'attempts'        => 1,
                'max_attempts'    => 3,
                'run_after'       => $nowSql,
                'locked_by'       => 'test-worker',
                'lock_expires_at' => $lockExp,
                'created_at'      => $nowSql,
            ]
        );
        self::assertTrue((bool) $ok, 'fixture: PROCESSING notif.dispatch job inserted — ' . $wpdb->last_error);
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'fixture: PROCESSING job id');

        App::scheduleRecurringJobs();

        $queued = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . " WHERE type = 'notif.dispatch' AND status = 'queued'"
        );
        $total = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . " WHERE type = 'notif.dispatch' AND status IN ('queued','processing')"
        );

        self::assertSame(0, $queued, 'a PROCESSING job must suppress a duplicate root on an active lane — queued=' . $queued);
        self::assertSame(1, $total, 'only the original PROCESSING job may remain — total=' . $total);

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = %s', 'notif.dispatch'));
    }

    public function testContinuationDedupPreventsDuplicateSameCursor(): void
    {
        $appointmentId = $this->seedPastAppointment('10:00:00', self::FX_T_LOC_A_ID);
        $jobId = App::jobs()->enqueue('visits.no_show', [], null, 5, 3);
        App::runTick(20);

        $job = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertIsArray($job);
        self::assertSame('success', (string) $job['status'], 'the legacy job drains cleanly');
        self::assertSame(1, $this->noShowJobCount(), 'no continuation row is added');
        self::assertSame('confirmed', (string) $this->appointmentRow($appointmentId)['status']);
    }

    private function noShowJobCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . " WHERE type = 'visits.no_show'"
        );
    }

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

    private function pastDate(int $days = 3): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d');
    }

    private function seedPastAppointment(string $time, int $locationId = self::FX_T_LOC_A_ID): int
    {
        $date = $this->pastDate();
        // The slot must be the appointment's own slot: `fxTInsertSlot()` enforces the
        // (location, clinician, date, time) unique key, so a second implicit slot at the
        // same wall-clock would fail and leave the appointment unlinked.
        $slotId = $this->fxTInsertSlot($locationId, $date, $time);

        return $this->fxTInsertAppointment($locationId, $date, $time, 'confirmed', $slotId);
    }
}

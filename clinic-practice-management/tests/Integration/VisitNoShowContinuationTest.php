<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — the legacy no-show continuation chain is inert.
 *
 * Pre-R4 this suite protected cursor ordering, depth ceilings and duplicate-retry
 * idempotency of the automatic sweep. With MANUAL-ONLY no-show the whole chain is
 * gone by design; the equal-or-stronger contract is that no payload shape — root,
 * valid cursor, malformed cursor, deep depth — produces work, a cursor or a new job.
 */
final class VisitNoShowContinuationTest extends WP_UnitTestCase
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

    public function testEmptyPayloadIsValidRoot(): void
    {
        $appointmentId = $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID);
        $res = App::visitService()->processNoShows(null, 0);

        self::assertSame(0, (int) $res['processed']);
        self::assertNull($res['next_cursor']);
        self::assertFalse((bool) $res['has_more']);
        self::assertSame('confirmed', (string) $this->appointmentRow($appointmentId)['status']);
    }

    public function testValidCursorContinuationAdvances(): void
    {
        // Pre-R4: a cursor advanced the sweep. R4: a cursor is inert.
        $appointmentId = $this->seedPastAppointment('10:00:00', self::FX_T_LOC_A_ID);
        $res = App::visitService()->processNoShows(
            ['slot_date' => $this->pastDate(), 'slot_time' => '10:00:00', 'id' => $appointmentId],
            1
        );

        self::assertSame(0, (int) $res['processed']);
        self::assertNull($res['next_cursor']);
        self::assertSame('confirmed', (string) $this->appointmentRow($appointmentId)['status']);
    }

    public function testMalformedCursorFailsClosedNotRoot(): void
    {
        $res = App::visitService()->processNoShows(['slot_date' => 'not-a-date', 'slot_time' => 'x', 'id' => 0], 4);

        self::assertSame(0, (int) $res['processed'], 'a malformed cursor is still a harmless no-op');
        self::assertSame(0, $this->noShowJobCount(), 'no continuation job may be enqueued');
    }

    public function testDuplicateRetryDoesNotDuplicateNoShow(): void
    {
        $appointmentId = $this->seedPastAppointment('11:00:00', self::FX_T_LOC_A_ID);
        App::visitService()->processNoShows(null, 0);
        App::visitService()->processNoShows(null, 0);

        self::assertSame('confirmed', (string) $this->appointmentRow($appointmentId)['status']);
        self::assertSame(0, $this->noShowJobCount());
    }

    public function testNoFixedDepthProgressBeyondOldCeiling(): void
    {
        foreach ([0, 1, 5, 50] as $depth) {
            $res = App::visitService()->processNoShows(null, $depth);
            self::assertSame(0, (int) $res['processed'], 'depth ' . $depth . ' must stay inert');
            self::assertSame($depth, (int) $res['depth'], 'depth is echoed, never advanced');
        }
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

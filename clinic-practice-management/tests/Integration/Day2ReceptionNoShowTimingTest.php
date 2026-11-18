<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — no-show is MANUAL-ONLY (replaces the pre-R4 automatic-sweep timing suite).
 *
 * The pre-R4 suite protected WHEN the sweep marked a row (grace boundary, wall-clock
 * independence, board eviction). That timing no longer exists; the equal-or-stronger
 * contract is that NO wall-clock position, past the old grace window included, ever
 * marks or evicts a row: the reception board keeps showing the appointment until a
 * human decides.
 */
final class Day2ReceptionNoShowTimingTest extends WP_UnitTestCase
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

    public function testRealSweepMarksNothingEvenPastSlotPlusGrace(): void
    {
        $appointmentId = $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertSame(0, (int) $res['processed'], 'R4: nothing may be marked automatically');
        self::assertNull($res['next_cursor'], 'R4: no continuation');

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status']);
        self::assertNull($row['no_show_at'], 'no_show_at stays NULL without a manual decision');
    }

    public function testAnchoredReceptionRowsSurviveTheRealSweepAndStayOnTheBoard(): void
    {
        $appointmentId = $this->seedPastAppointment('10:00:00', self::FX_T_LOC_A_ID);

        App::visitService()->processNoShows(null, 0);

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status'], 'the row must stay on the operational-day board');
        self::assertNull($row['no_show_at']);
    }

    public function testFutureAndPastRowsAreBothUntouched(): void
    {
        $pastId   = $this->seedPastAppointment('11:00:00', self::FX_T_LOC_A_ID);
        $futureId = $this->seedPastAppointment('23:30:00', self::FX_T_LOC_B_ID);

        App::visitService()->processNoShows(null, 0);

        foreach (['past' => $pastId, 'future' => $futureId] as $label => $id) {
            self::assertSame(
                'confirmed',
                (string) $this->appointmentRow($id)['status'],
                $label . ' row must never be touched by an automatic path'
            );
        }
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
        $this->fxTInsertSlot($locationId, $date, $time);

        return $this->fxTInsertAppointment($locationId, $date, $time, 'confirmed');
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
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

    /**
     * The real reception-board query, not a status check standing in for it:
     * `list_for_reception_operational_day()` only returns pending/confirmed rows,
     * so this is exactly what the reception screen renders.
     *
     * @return int[]
     */
    private function boardIds(string $date): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            (new AppointmentRepository(App::db()))->list_for_reception_operational_day(
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                $date
            )
        );
    }

    public function testAnchoredReceptionRowsSurviveTheRealSweepAndStayOnTheBoard(): void
    {
        $appointmentId = $this->seedPastAppointment('10:00:00', self::FX_T_LOC_A_ID);

        $result = App::visitService()->processNoShows(null, 0);
        self::assertSame(0, (int) $result['processed'], 'R4: the sweep processes nothing');

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status'], 'the row must stay confirmed without a manual decision');
        self::assertNull($row['no_show_at']);
        self::assertContains(
            $appointmentId,
            $this->boardIds($this->pastDate()),
            'the real reception-board query must still return the row (only pending/confirmed reach the board)'
        );
    }

    public function testFutureAndPastRowsAreBothUntouchedAndStayOnTheirBoard(): void
    {
        $pastId = $this->seedPastAppointment('11:00:00', self::FX_T_LOC_A_ID);
        $future = $this->seedFutureAppointment(90, self::FX_T_LOC_A_ID);

        $result = App::visitService()->processNoShows(null, 0);
        self::assertSame(0, (int) $result['processed'], 'R4: the sweep processes nothing');

        foreach (['past' => $pastId, 'future' => $future['id']] as $label => $id) {
            $row = $this->appointmentRow($id);
            self::assertSame(
                'confirmed',
                (string) $row['status'],
                $label . ' row must never be touched by an automatic path'
            );
            self::assertNull($row['no_show_at'], $label . ' row keeps no_show_at NULL');
        }

        self::assertContains($pastId, $this->boardIds($this->pastDate()), 'the past row stays on its operational-day board');
        self::assertContains(
            $future['id'],
            $this->boardIds($future['date']),
            'a genuinely future appointment stays on its operational-day board'
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

        self::assertIsArray($row, 'the appointment row must exist');
        self::assertArrayHasKey('status', $row);

        return $row;
    }

    /**
     * A genuinely future slot (real wall clock + `$minutesAhead`), so the
     * "future row" case cannot silently degrade into another past row.
     *
     * @return array{id: int, date: string, time: string}
     */
    private function seedFutureAppointment(int $minutesAhead = 90, int $locationId = self::FX_T_LOC_A_ID): array
    {
        // P1: the slot columns are CLINIC-LOCAL (Location timezone Asia/Tehran), so the
        // value must be built in that timezone. Formatting a UTC instant here produced a
        // slot ~3.5 h EARLIER in local time — i.e. a "future" row that was already past.
        $clinicTz = new \DateTimeZone(self::FX_T_TZ_A);
        $slot = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone($clinicTz)
            ->modify('+' . $minutesAhead . ' minutes');
        $date = $slot->format('Y-m-d');
        $time = $slot->format('H:i:s');

        // Fixture invariant, asserted here so a wrong timezone fails loudly instead of
        // silently producing a past row.
        self::assertGreaterThan(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            new \DateTimeImmutable($date . ' ' . $time, $clinicTz),
            'fixture invariant: the "future" slot must be strictly in the future in the clinic timezone '
            . self::FX_T_TZ_A . ' — slot ' . $date . ' ' . $time . ' local'
        );

        $slotId = $this->fxTInsertSlot($locationId, $date, $time);

        return [
            'id'   => $this->fxTInsertAppointment($locationId, $date, $time, 'confirmed', $slotId),
            'date' => $date,
            'time' => $time,
        ];
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

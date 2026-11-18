<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — the no-show lane cannot starve because it does no work at all.
 *
 * Pre-R4 this suite proved progress across repeated sweeps over a fixed candidate
 * window. R4 removes the sweep, so the equal-or-stronger property is that repeated
 * calls stay at zero forever and never leave rows behind in a mutated state.
 */
final class VisitNoShowStarvationRedTest extends WP_UnitTestCase
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

    public function testStarvationProgressAcrossRepeatedTicks(): void
    {
        $appointmentId = $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID);

        for ($tick = 0; $tick < 5; $tick++) {
            $res = App::visitService()->processNoShows(null, 0);
            self::assertSame(0, (int) $res['processed'], 'tick ' . $tick . ' must process nothing');
            self::assertNull($res['next_cursor'], 'no cursor is ever produced');
        }

        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status']);
        self::assertNull($row['no_show_at']);
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

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — no automatic marking, so no cross-clinic fallback can exist.
 *
 * Pre-R4 this suite proved the sweep's per-clinic grace fallback failed closed
 * across clinics. R4 removes the sweep; the equal-or-stronger property is that a
 * past appointment in every fixture location is left untouched by any automatic
 * entry point, and that scope state plays no role in the outcome.
 */
final class VisitNoShowCrossClinicFallbackRedTest extends WP_UnitTestCase
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

    public function testCrossClinicGraceFallbackMustFailClosed(): void
    {
        $ids = [
            'Location A' => $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID),
            'Location B' => $this->seedPastAppointment('10:00:00', self::FX_T_LOC_B_ID),
            'Location C' => $this->seedPastAppointment('11:00:00', self::FX_T_LOC_C_ID),
        ];

        $res = App::visitService()->processNoShows(null, 0);
        self::assertSame(0, (int) $res['processed']);

        foreach ($ids as $loc => $id) {
            $row = $this->appointmentRow($id);
            self::assertSame(
                'confirmed',
                (string) $row['status'],
                $loc . ' must stay untouched (no fallback path exists)'
            );
            self::assertNull($row['no_show_at']);
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
        // The slot must be the appointment's own slot: `fxTInsertSlot()` enforces the
        // (location, clinician, date, time) unique key, so a second implicit slot at the
        // same wall-clock would fail and leave the appointment unlinked.
        $slotId = $this->fxTInsertSlot($locationId, $date, $time);

        return $this->fxTInsertAppointment($locationId, $date, $time, 'confirmed', $slotId);
    }
}

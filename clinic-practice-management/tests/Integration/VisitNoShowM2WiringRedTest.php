<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — M-2 wiring stays registered but is unreachable from the scheduler.
 *
 * Pre-R4 this suite proved the production wiring ran the sweep scope-neutrally.
 * R4 keeps the wiring (so the full line can re-enable it) while removing the
 * schedule; the equal-or-stronger property is: the recurring map has no entry, the
 * handler runs scope-neutrally, and the run mutates nothing.
 */
final class VisitNoShowM2WiringRedTest extends WP_UnitTestCase
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

    public function testProductionWiringMustBeScopeNeutral(): void
    {
        self::assertArrayNotHasKey(
            'visits.no_show',
            App::RECURRING_JOBS,
            'R4: wiring may stay registered, but the schedule must not exist'
        );

        $appointmentId = $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID);
        $handler = new \ClinicCore\Application\Jobs\VisitsNoShowHandler(
            App::visitService(),
            App::jobs(),
            App::db(),
            App::op()
        );

        // M-2 protection retained (stronger now): with NO ambient scope and NO
        // current user the handler must not need a Clinic at all — it cannot throw
        // CLINIC_SCOPE_REQUIRED because the inert path never touches scoped data.
        \ClinicCore\Application\Scope\ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        self::assertSame(0, $handler([]), 'empty payload must be an inert, scope-neutral no-op');
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
        $this->fxTInsertSlot($locationId, $date, $time);

        return $this->fxTInsertAppointment($locationId, $date, $time, 'confirmed');
    }
}

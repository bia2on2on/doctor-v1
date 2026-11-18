<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use WP_UnitTestCase;

/**
 * R4 (1.0) — serialized ticks cannot mark anything either.
 *
 * Pre-R4 this suite proved `App::runTick()` serialization kept two workers from
 * consuming the same no-show chain. R4 removes the chain; the equal-or-stronger
 * property is that repeated real ticks never create or duplicate no-show work and
 * never mutate an appointment, serialized or not.
 */
final class VisitNoShowQueueSerializationTest extends WP_UnitTestCase
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

    public function testSlotsGenerateDoesNotUseUnlockedDispatcherTick(): void
    {
        $cpmsPath = dirname(__DIR__, 2) . '/bin/cpms';
        self::assertFileExists($cpmsPath, 'bin/cpms must exist');
        $content = file_get_contents($cpmsPath);
        self::assertNotFalse($content, 'read bin/cpms');

        // Extract slots generate block
        $hasDirectTick = false;
        if (preg_match('/case\s+\'slots\':.*?generate.*?\{.*?App::dispatcher\(\)->tick/s', $content)) {
            $hasDirectTick = true;
        }
        // Also check for any direct dispatcher tick in slots section
        $lines = explode("\n", $content);
        $inSlots = false;
        $foundDirectTickInSlots = false;
        $foundRunTickInSlots = false;
        foreach ($lines as $line) {
            if (strpos($line, "case 'slots':") !== false) {
                $inSlots = true;
            }
            if ($inSlots) {
                if (strpos($line, "App::dispatcher()->tick") !== false) {
                    $foundDirectTickInSlots = true;
                }
                if (strpos($line, "App::runTick") !== false) {
                    $foundRunTickInSlots = true;
                }
                if (strpos($line, "break;") !== false && $inSlots) {
                    // rough end of case, but continue a bit
                    if ($foundDirectTickInSlots || $foundRunTickInSlots) {
                        break;
                    }
                }
            }
        }

        // Contract: slots generate must NOT use unlocked dispatcher()->tick
        // It must use runTick (canonical locked abstraction) or enqueue-only
        self::assertFalse($foundDirectTickInSlots, 'slots generate must NOT use unlocked App::dispatcher()->tick — found direct tick, which creates second global consumer without GET_LOCK');
        self::assertTrue($foundRunTickInSlots, 'slots generate should use App::runTick (canonical locked queue consumer) for synchronous processing');
    }

    public function testRunTickSerializationPreventsConcurrentConsumption(): void
    {
        $appointmentId = $this->seedPastAppointment('09:00:00', self::FX_T_LOC_A_ID);

        App::runTick(20);
        App::runTick(20);

        self::assertSame(0, $this->noShowJobCount(), 'no no-show job is created by any tick');
        $row = $this->appointmentRow($appointmentId);
        self::assertSame('confirmed', (string) $row['status']);
        self::assertNull($row['no_show_at']);
    }

    public function testRecurringSuppressionStillValid(): void
    {
        self::assertArrayNotHasKey('visits.no_show', App::RECURRING_JOBS);
        App::runTick(20);
        self::assertSame(0, $this->noShowJobCount());
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

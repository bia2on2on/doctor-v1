<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\ExternalRaceWorker;
use WP_UnitTestCase;

/**
 * F10 §27/§28 — آزمون پذیرش اجباری: ۱۰۰ راهِ هم‌زمان روی «همان Slot با
 * ظرفیت ۱» روی **مسیر واقعی Production** (SlotRepository::atomicBook —
 * همان Conditional UPDATE + قفل ردیف InnoDB که Booking در Production اجرا
 * می‌کند) با **اتصال‌های مستقل MySQL** (هر child یک اتصال تازه).
 *
 * موفقیت = دقیقاً ۱ برنده (و برای ظرفیت ۳ = دقیقاً ۳) — بدون Overbooking.
 *
 * نکات فنی:
 *  - External proc_open workers (fork-race-wp-worker.php, atomic_book): هیچ descriptorی از این حلقه به ارث نمی‌رود؛ هر worker مسیر واقعی atomicBook را روی اتصال خودش اجرا می‌کند و با exit ۰ بیرون می‌آید.
 *  - Fixture COMMIT می‌شود تا children ببینند؛ Cleanup دستی (ROLLBACK تست
 *    بعد از COMMIT بی‌اثر است).
 *  - در صورت نبود proc_open در محیط اجرا نمی‌شود — CI اجرا می‌کند.
 */
final class SlotCapacityOneHundredWayTest extends WP_UnitTestCase
{
    private int $clinicianId = 0;
    private int $slotId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testOneHundredWaySameSlotCapacityOneAllowsExactlyOneWinner(): void
    {
        $this->makeSlotFixture(capacity: 1);
        $winners = $this->runOneHundredContenders();

        $this->assertSame(1, $winners, 'فقط یک درخواست باید روی ظرفیت ۱ برنده شود');
        $this->assertSame(1, $this->readBookedCount(), 'booked_count نهایی باید ۱ باشد');
        $this->cleanupFixture();
    }

    public function testOneHundredWayCapacityThreeAllowsExactlyThreeWinners(): void
    {
        $this->makeSlotFixture(capacity: 3);
        $winners = $this->runOneHundredContenders();

        $this->assertSame(3, $winners, 'با ظرفیت ۳ فقط ۳ درخواست باید برنده شوند');
        $this->assertSame(3, $this->readBookedCount(), 'booked_count نهایی باید ۳ باشد');
        $this->cleanupFixture();
    }

    // ================= fixture / workers =================

    private function makeSlotFixture(int $capacity): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)',
                'Dr 100-Way F10',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, %s, %s, 15, %d, 0, 0, 1, %s, %s, %s)',
                $this->clinicianId,
                '2099-01-01',
                '09:00:00',
                $capacity,
                'manual',
                $now,
                $now
            )
        );
        $this->slotId = (int) $wpdb->insert_id;

        // COMMIT تا children (اتصال‌های مستقل) ردیف را ببینند
        $wpdb->query('COMMIT');
    }

    private function runOneHundredContenders(): int
    {
        // External proc_open workers: no descriptor inherited from this
        // harness process, so a graceful exit(0) in a worker cannot run
        // mysqlnd on the shared session. Exit 0 = clean job done
        // ('won'|'lost' outcome file carries the race result).
        $script = dirname(__DIR__) . '/bin/fork-race-wp-worker.php';
        $children = [];
        $outcome_files = [];
        $tag = bin2hex(random_bytes(8));
        for ($i = 0; $i < 100; $i++) {
            $outcome_files[$i] = sys_get_temp_dir() . '/cpms-slotcap-' . $tag . '-' . $i . '.out';
            @unlink($outcome_files[$i]);
            $children[$i] = ExternalRaceWorker::launch($script, [
                'mode' => 'atomic_book',
                'slot_id' => $this->slotId,
                'outcome' => $outcome_files[$i],
            ]);
        }

        $winners = 0;
        foreach ($children as $i => $worker) {
            $code = $worker->waitForExit(45.0);
            self::assertSame(0, $code, 'external slot-cap worker ' . $i . ' must exit with status 0 (task ' . $tag . ')');
            $raw = @file_get_contents($outcome_files[$i]);
            @unlink($outcome_files[$i]);
            if ($raw === 'won') {
                $winners++;
            }
        }

        return $winners;
    }

    private function readBookedCount(): int
    {
        global $wpdb;
        $v = $wpdb->get_var(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $this->slotId)
        );

        return (int) $v;
    }

    private function cleanupFixture(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $this->slotId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', $this->clinicianId));
        $wpdb->query('COMMIT');
    }
}

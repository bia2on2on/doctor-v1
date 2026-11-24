<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\HashChain;
use ClinicCore\Tests\Support\ExternalRaceWorker;
use WP_UnitTestCase;

/**
 * Synthetic concurrent SQL checks, not production booking acceptance.
 *
 * Workers use independent mysqli connections and hand-written audit inserts or
 * slot-counter updates. There is no simultaneous-launch barrier, no OTP, hold
 * record, confirmation, idempotency, clinical, finance or SMS service call, and
 * no independent verifier or lock-wait/deadlock/latency measurement. The
 * production verifier is checked against the resulting synthetic rows. A pass
 * here must not be described as the requested end-to-end concurrency proof.
 */
final class AuditAppendConcurrencyTest extends WP_UnitTestCase
{
    private int $organizationId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $clinicianId = 0;
    private int $clinicianUserId = 0;
    private int $slotId = 0;
    private int $auditBaselineId = 0;
    private int $auditBaselineCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        // Ensure guard exists (migration 0031).
        $guard = App::db()->fetchRow('SELECT id FROM ' . App::db()->table('cpms_audit_seq') . ' WHERE id = 1');
        if ($guard === null) {
            $this->fail('audit guard row missing — migration 0031 not applied');
        }
        $this->auditBaselineId = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_audit_logs')
        );
        $this->auditBaselineCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
        );
        self::assertTrue(App::audit()->verifyFullChain()['ok'], 'precondition: global audit chain is valid before this test');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        // Independent worker connections required committed fixtures and wrote
        // committed suffix rows. Clean up only this test's isolated tenant and
        // verified suffix, then COMMIT the removal so later suites continue to
        // see the untouched seed Clinic and prefix chain. Never run assertions
        // inside tearDown so cleanup runs completely even if a test failed.
        try {
            if ($this->clinicId > 0) {
                $auditTable = App::db()->table('cpms_audit_logs');
                App::db()->query(
                    'DELETE FROM ' . $auditTable . ' WHERE id > %d AND clinic_id = %d',
                    [$this->auditBaselineId, $this->clinicId]
                );
                $newTail = (int) App::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $auditTable);
                App::db()->query(
                    'UPDATE ' . App::db()->table('cpms_audit_seq') . ' SET last_audit_id = %d, updated_at = %s WHERE id = 1',
                    [$newTail, App::db()->nowUtcSql()]
                );
            }
            if ($this->slotId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d', [$this->slotId]);
            }
            if ($this->clinicianId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', [$this->clinicianId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$this->clinicianId]);
            }
            if ($this->clinicId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [$this->clinicId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d', [$this->clinicId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicId]);
            }
            if ($this->organizationId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d', [$this->organizationId]);
            }
            if ($this->clinicianUserId > 0) {
                $wpdb->delete($wpdb->usermeta, ['user_id' => $this->clinicianUserId], ['%d']);
                $wpdb->delete($wpdb->users, ['ID' => $this->clinicianUserId], ['%d']);
            }
            $wpdb->query('COMMIT');
        } finally {
            App::resetScope();
            parent::tearDown();
        }
    }

    public function testGuardSerializesConcurrentAuditAppendsAndChainRemainsIntact(): void
    {
        $this->prepareSlot(capacity: 50);
        $initialCount = $this->lockedAuditCount();

        // 10-way
        $this->assertConcurrentAuditInsertsPreserveChain(10, $initialCount);
        $after10 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 10, $after10, '10-way: exactly 10 audit rows must be added');

        // 25-way
        $this->assertConcurrentAuditInsertsPreserveChain(25, $after10);
        $after25 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 35, $after25, '25-way: exactly 25 more rows');

        // 50-way
        $this->assertConcurrentAuditInsertsPreserveChain(50, $after25);
        $after50 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 85, $after50, '50-way: total 85 concurrent rows added');
    }

    public function testConcurrentSqlCapacityUpdatesLeaveProductionVerifierIntact(): void
    {
        // This is only a direct conditional slot-counter UPDATE: no hold row,
        // production BookingService, audit append or deadlock observation.
        $this->prepareSlot(capacity: 1);

        $successes = $this->forkBookingHolds(25, $this->slotId);
        $this->assertSame(1, $successes, 'capacity 1 with 25-way hold: exactly one must succeed');
        App::db()->wpdb()->query('COMMIT');

        $after = $this->lockedAuditCount();
        $this->assertIsInt($after);
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'audit chain must be intact after 25-way booking race: ' . wp_json_encode($chain));
        // No unexpected genesis: only the first row in the entire history may have GENESIS.
        if ($after > 0) {
            $this->assertNoUnexpectedGenesis();
        }
        // Suffix verification (last 10) must also pass via predecessor anchor.
        $suffix = App::audit()->verifyChain(10);
        $this->assertTrue($suffix['ok'], 'suffix verification must pass: ' . wp_json_encode($suffix));
    }

    public function testSyntheticAuditRowsLeaveProductionVerifierIntact(): void
    {
        $this->prepareTenantOnly();
        $initial = $this->lockedAuditCount();
        // Five hand-written audit rows; this does not invoke any clinical,
        // financial or SMS production writer.
        $this->assertConcurrentAuditInsertsPreserveChain(5, $initial);

        // Check these synthetic rows with the production verifier only.
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'mixed operations must not break chain');
    }

    // ------------------------------------------------------------------
    // Helpers: isolated committed tenant + slot fixture
    // ------------------------------------------------------------------

    private function prepareTenantOnly(): void
    {
        global $wpdb;
        if ($this->clinicId > 0) {
            return;
        }
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        App::db()->insert('cpms_organizations', [
            'name' => 'Audit Race Org ' . $unique,
            'slug' => 'audit-race-org-' . $unique,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->organizationId = (int) App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $this->organizationId);

        App::db()->insert('cpms_clinics', [
            'organization_id' => $this->organizationId,
            'name' => 'Audit Race Clinic ' . $unique,
            'slug' => 'audit-race-clinic-' . $unique,
            'timezone' => 'Asia/Tehran',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->clinicId = (int) App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $this->clinicId);
        // Commit so independent mysqli workers can reference clinic_id via FK.
        $wpdb->query('COMMIT');
    }

    private function prepareSlot(int $capacity): void
    {
        global $wpdb;
        $this->prepareTenantOnly();
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        App::db()->insert('cpms_locations', [
            'clinic_id' => $this->clinicId,
            'name' => 'Audit Test Location',
            'slug' => 'audit-test-loc-' . $unique,
            'is_active' => 1,
            'is_primary' => 1,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->locationId = (int) App::db()->wpdb_last_insert_id();

        $userId = wp_create_user('audit_clin_' . $unique, wp_generate_password(12), 'audit_' . $unique . '@example.com');
        $this->assertNotWPError($userId);
        $this->clinicianUserId = (int) $userId;

        App::db()->insert('cpms_clinicians', [
            'clinic_id' => $this->clinicId,
            'wp_user_id' => $this->clinicianUserId,
            'full_name' => 'Dr Audit Concurrency',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->clinicianId = (int) App::db()->wpdb_last_insert_id();

        App::db()->insert('cpms_clinician_locations', [
            'clinician_id' => $this->clinicianId,
            'location_id' => $this->locationId,
            'is_primary' => 1,
            'created_at' => $now,
        ]);
        App::db()->insert('cpms_clinic_memberships', [
            'clinic_id' => $this->clinicId,
            'wp_user_id' => $this->clinicianUserId,
            'role_key' => 'cpms_doctor',
            'status' => 'active',
            'scope_mode' => 'clinic',
            'is_primary' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Create a future slot with given capacity.
        $date = gmdate('Y-m-d', time() + 86400 * 2);
        $time = '09:00:00';
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)',
                $this->clinicId,
                $this->locationId,
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );
        $this->slotId = (int) $wpdb->insert_id;
        // Commit fixture so independent mysqli workers can see it.
        $wpdb->query('COMMIT');
    }

    private function lockedAuditCount(): int
    {
        return count(App::db()->fetchAll('SELECT 1 AS hit FROM ' . App::db()->table('cpms_audit_logs') . ' FOR UPDATE'));
    }

    // ------------------------------------------------------------------
    // Concurrent audit inserts via independent mysqli (real InnoDB locks)
    // ------------------------------------------------------------------

    /**
     * Launch $count external workers, each appending one audit row through the guard.
     * Each worker uses its own mysqli connection and replicates the AuditLogger
     * logic (guard FOR UPDATE → tail FOR UPDATE → compute hash → insert → update guard).
     *
     * @return void
     */
    private function assertConcurrentAuditInsertsPreserveChain(int $count, int $beforeCount): void
    {
        $outcome_files = [];
        $tag = bin2hex(random_bytes(8));
        // Ensure parent session holds no open transaction lock on cpms_audit_logs
        // before external workers acquire FOR UPDATE on tail + guard.
        App::db()->wpdb()->query('COMMIT');

        $script = dirname(__DIR__) . '/bin/fork-race-atomic-worker.php';
        $workers = [];
        for ($i = 0; $i < $count; $i++) {
            $outcome_files[$i] = sys_get_temp_dir() . '/cpms-audit-ins-' . $tag . '-' . $i . '.out';
            @unlink($outcome_files[$i]);
            $workers[$i] = ExternalRaceWorker::launch($script, [
                'mode' => 'audit_append_replicated',
                'worker_index' => $i,
                'clinic_id' => $this->clinicId,
                'outcome' => $outcome_files[$i],
            ]);
        }

        $success = 0;
        $failed = 0;
        foreach ($workers as $i => $worker) {
            $code = $worker->waitForExit(20.0);
            $raw = @file_get_contents($outcome_files[$i]);
            @unlink($outcome_files[$i]);
            if ($code === 0 && $raw === '0') {
                $success++;
            } else {
                $failed++;
            }
        }
        self::assertSame(0, $failed, $count . '-way audit appends must exit with status 0 (no harness fatal): failed=' . $failed);

        $this->assertSame($count, $success, $count . '-way concurrent audit inserts must all succeed (failed=' . $failed . ')');
        $this->assertSame(0, $failed);
        // End any open REPEATABLE READ snapshot before non-locking verifier reads
        // so rows committed by the external workers are visible here.
        App::db()->wpdb()->query('COMMIT');

        // Verify chain integrity after this batch.
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'full chain must be intact after ' . $count . '-way: ' . wp_json_encode($chain));
        $this->assertSame($beforeCount + $count, $chain['checked'], 'checked count must match');
        $this->assertNoUnexpectedGenesis();
        // Also verify suffix (last 10) via predecessor anchor.
        $suffix = App::audit()->verifyChain(10);
        $this->assertTrue($suffix['ok'], 'suffix must be intact: ' . wp_json_encode($suffix));
    }

    private function forkBookingHolds(int $count, int $slotId): int
    {
        $outcome_files = [];
        $tag = bin2hex(random_bytes(8));
        // Direct conditional UPDATE only; neither BookingService nor AuditLogger runs.
        $holdSql = 'UPDATE ' . $this->table('cpms_schedule_slots')
            . ' SET held_count = held_count + 1, updated_at = ? WHERE id = ? AND is_open = 1 AND capacity - booked_count - held_count > 0';
        $script = dirname(__DIR__) . '/bin/fork-race-atomic-worker.php';
        $workers = [];
        for ($i = 0; $i < $count; $i++) {
            $outcome_files[$i] = sys_get_temp_dir() . '/cpms-audit-hold-' . $tag . '-' . $i . '.out';
            @unlink($outcome_files[$i]);
            $workers[$i] = ExternalRaceWorker::launch($script, [
                'mode' => 'conditional_update',
                'sql_b64' => base64_encode($holdSql),
                'id' => $slotId,
                'outcome' => $outcome_files[$i],
            ]);
        }

        $successes = 0;
        foreach ($workers as $i => $worker) {
            $code = $worker->waitForExit(20.0);
            self::assertSame(0, $code, 'external hold worker ' . $i . ' must exit with status 0 (task ' . $tag . ')');
            $raw = @file_get_contents($outcome_files[$i]);
            @unlink($outcome_files[$i]);
            if ($raw === '0') {
                $successes++;
            }
        }
        return $successes;
    }

    private function assertNoUnexpectedGenesis(): void
    {
        $rows = App::db()->fetchAll('SELECT id, prev_hash FROM ' . App::db()->table('cpms_audit_logs') . ' ORDER BY id ASC');
        $genesis = HashChain::GENESIS;
        $genesisCount = 0;
        $unexpected = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((string) ($row['prev_hash'] ?? '') === $genesis) {
                $genesisCount++;
                if ((int) ($row['id'] ?? 0) !== 1 && $genesisCount > 1) {
                    $unexpected[] = (int) $row['id'];
                }
            }
        }
        $this->assertSame(1, $genesisCount, 'only the first audit row may have GENESIS prev_hash; unexpected genesis at IDs: ' . implode(',', $unexpected));
        $this->assertSame([], $unexpected);
    }

    private function table(string $short): string
    {
        global $wpdb;
        $prefix = $wpdb->prefix ?? 'wp_';
        $short = preg_replace('/^cpms_/', '', $short) ?? $short;
        return $prefix . 'cpms_' . $short;
    }
}

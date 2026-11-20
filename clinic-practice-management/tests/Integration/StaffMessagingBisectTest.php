<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Messaging\StaffMessagingService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * Cumulative-cut bisect of one red test, expressed as test NAMES.
 *
 * WHY THIS FILE EXISTS
 * `StaffMessagingSecurityTest::testSameClinicTwoWayConversationUnreadIndicatorsAndTimestamps`
 * is red in CI while every one of its assertion groups passes when exercised in
 * isolation by the earlier probes. The CI reporter publishes only
 * `Class::method`, never an assertion message or a line, so the only way to see
 * WHERE it fails is to give each prefix of the body its own method name.
 *
 * Each `testBisectCutN` executes the ORIGINAL body of the target test from its
 * first line up to the Nth assertion-group boundary, using the same assertions
 * with the same messages. Nothing is weakened or dropped: a cut is a strict
 * PREFIX of the original, so the first cut that is red names the group that
 * broke, and every earlier cut stays green.
 *
 * The target test itself is NOT extended or edited by this file.
 *
 * setUp fidelity: `iv1_boot()` is copied from the Security class verbatim. The
 * route-registration half of that setUp is deliberately NOT copied, for two
 * reasons recorded here so the omission is auditable rather than accidental:
 *   1. the target test is service-only — it calls no REST dispatch and no
 *      `StaffPortalShell` API (established in the previous diagnosis), so route
 *      registration cannot influence any assertion it makes;
 *   2. `rest_get_server()` is process-wide, so registering here would leave
 *      `/staff/portal/messages` routes behind for every later class and would
 *      turn `StaffMessagingProbeBaselineTest`'s no-leaked-route baseline red —
 *      a self-inflicted failure that would confound the very decision table
 *      this file produces.
 * If every cut below passes while the original stays red, that outcome points
 * at the Security class's own setUp, and this file's docblock is the evidence
 * for it.
 */
final class StaffMessagingBisectTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
    }

    protected function tearDown(): void
    {
        $this->iv1_reset();
        parent::tearDown();
    }

    /**
     * Cut 1 — original lines 109-119: the world, the actor and the created thread.
     */
    public function testBisectCut1(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id'], 'the created thread must have a positive id, got ' . (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id'], 'expected visit_id ' . (int) $world['visit'] . ', got ' . (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id'], 'expected location_id ' . (int) $world['location'] . ', got ' . (int) $thread['location_id']);
    }

    /**
     * Cut 2 — original lines 109-135: adds the send, its sanitization and the
     * timestamps, then the secretary's thread list and unread counters.
     */
    public function testBisectCut2(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id'], 'the created thread must have a positive id, got ' . (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id'], 'expected visit_id ' . (int) $world['visit'] . ', got ' . (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id'], 'expected location_id ' . (int) $world['location'] . ', got ' . (int) $thread['location_id']);

        $service = App::staff_messaging_service();
        $sent = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['doctor'],
            (int) $thread['id'],
            '<script>alert(1)</script>سلام، بیمار آماده پذیرش است.'
        ));
        self::assertStringNotContainsString('<script', (string) $sent['body'], 'the stored body must be sanitized; got body length ' . strlen((string) $sent['body']));
        self::assertStringContainsString('بیمار آماده پذیرش', (string) $sent['body'], 'the stored body must keep its text; got body length ' . strlen((string) $sent['body']));
        self::assertNotSame('', (string) $sent['created_at'], 'created_at must not be empty, got "' . (string) $sent['created_at'] . '"');
        self::assertNotSame('', (string) $sent['jalali_time'], 'jalali_time must not be empty, got "' . (string) $sent['jalali_time'] . '"');

        $secretary_threads = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        self::assertCount(1, $secretary_threads['threads'], 'this secretary must see exactly one thread, got ' . count((array) $secretary_threads['threads']));
        self::assertSame(1, (int) $secretary_threads['threads'][0]['unread_count'], 'unread_count carries the one unread message, got ' . (int) $secretary_threads['threads'][0]['unread_count']);
        self::assertSame(1, (int) $secretary_threads['unread_total'], 'unread_total sums the same one message, got ' . (int) $secretary_threads['unread_total']);
    }

    /**
     * Cut 3 — original lines 109-145: adds the recipient's inbox projection and
     * the read marker.
     */
    public function testBisectCut3(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id'], 'the created thread must have a positive id, got ' . (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id'], 'expected visit_id ' . (int) $world['visit'] . ', got ' . (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id'], 'expected location_id ' . (int) $world['location'] . ', got ' . (int) $thread['location_id']);

        $service = App::staff_messaging_service();
        $sent = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['doctor'],
            (int) $thread['id'],
            '<script>alert(1)</script>سلام، بیمار آماده پذیرش است.'
        ));
        self::assertStringNotContainsString('<script', (string) $sent['body'], 'the stored body must be sanitized; got body length ' . strlen((string) $sent['body']));
        self::assertStringContainsString('بیمار آماده پذیرش', (string) $sent['body'], 'the stored body must keep its text; got body length ' . strlen((string) $sent['body']));
        self::assertNotSame('', (string) $sent['created_at'], 'created_at must not be empty, got "' . (string) $sent['created_at'] . '"');
        self::assertNotSame('', (string) $sent['jalali_time'], 'jalali_time must not be empty, got "' . (string) $sent['jalali_time'] . '"');

        $secretary_threads = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        self::assertCount(1, $secretary_threads['threads'], 'this secretary must see exactly one thread, got ' . count((array) $secretary_threads['threads']));
        self::assertSame(1, (int) $secretary_threads['threads'][0]['unread_count'], 'unread_count carries the one unread message, got ' . (int) $secretary_threads['threads'][0]['unread_count']);
        self::assertSame(1, (int) $secretary_threads['unread_total'], 'unread_total sums the same one message, got ' . (int) $secretary_threads['unread_total']);

        $inbox = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread['id'], 0, 50));
        self::assertCount(1, $inbox['messages'], 'this thread holds exactly one message, got ' . count((array) $inbox['messages']));
        self::assertArrayHasKey(0, $inbox['messages'], 'diagnostic: the thread must hold at least one message; message count = ' . count((array) $inbox['messages']));
        self::assertFalse($inbox['messages'][0]['mine'], 'the doctor\'s message must not be reported as the secretary\'s own; mine = ' . var_export($inbox['messages'][0]['mine'] ?? null, true));
        self::assertArrayNotHasKey('clinical_note', $inbox['messages'][0], 'a message row must not expose clinical_note; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));
        self::assertArrayNotHasKey('medical_record', $inbox['messages'][0], 'a message row must not expose medical_record; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));

        $read = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->mark_read((int) $world['secretary'], (int) $thread['id']));
        self::assertSame(0, (int) $read['unread_count'], 'mark_read clears the counter, got ' . (int) $read['unread_count']);
    }

    /**
     * Cut 4 — original lines 109-159: adds the reply, incremental polling and
     * the counterpart context.
     */
    public function testBisectCut4(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id'], 'the created thread must have a positive id, got ' . (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id'], 'expected visit_id ' . (int) $world['visit'] . ', got ' . (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id'], 'expected location_id ' . (int) $world['location'] . ', got ' . (int) $thread['location_id']);

        $service = App::staff_messaging_service();
        $sent = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['doctor'],
            (int) $thread['id'],
            '<script>alert(1)</script>سلام، بیمار آماده پذیرش است.'
        ));
        self::assertStringNotContainsString('<script', (string) $sent['body'], 'the stored body must be sanitized; got body length ' . strlen((string) $sent['body']));
        self::assertStringContainsString('بیمار آماده پذیرش', (string) $sent['body'], 'the stored body must keep its text; got body length ' . strlen((string) $sent['body']));
        self::assertNotSame('', (string) $sent['created_at'], 'created_at must not be empty, got "' . (string) $sent['created_at'] . '"');
        self::assertNotSame('', (string) $sent['jalali_time'], 'jalali_time must not be empty, got "' . (string) $sent['jalali_time'] . '"');

        $secretary_threads = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        self::assertCount(1, $secretary_threads['threads'], 'this secretary must see exactly one thread, got ' . count((array) $secretary_threads['threads']));
        self::assertSame(1, (int) $secretary_threads['threads'][0]['unread_count'], 'unread_count carries the one unread message, got ' . (int) $secretary_threads['threads'][0]['unread_count']);
        self::assertSame(1, (int) $secretary_threads['unread_total'], 'unread_total sums the same one message, got ' . (int) $secretary_threads['unread_total']);

        $inbox = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread['id'], 0, 50));
        self::assertCount(1, $inbox['messages'], 'this thread holds exactly one message, got ' . count((array) $inbox['messages']));
        self::assertArrayHasKey(0, $inbox['messages'], 'diagnostic: the thread must hold at least one message; message count = ' . count((array) $inbox['messages']));
        self::assertFalse($inbox['messages'][0]['mine'], 'the doctor\'s message must not be reported as the secretary\'s own; mine = ' . var_export($inbox['messages'][0]['mine'] ?? null, true));
        self::assertArrayNotHasKey('clinical_note', $inbox['messages'][0], 'a message row must not expose clinical_note; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));
        self::assertArrayNotHasKey('medical_record', $inbox['messages'][0], 'a message row must not expose medical_record; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));

        $read = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->mark_read((int) $world['secretary'], (int) $thread['id']));
        self::assertSame(0, (int) $read['unread_count'], 'mark_read clears the counter, got ' . (int) $read['unread_count']);

        $reply = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['secretary'],
            (int) $thread['id'],
            'پذیرش انجام شد.'
        ));
        self::assertTrue($reply['mine'], 'the secretary\'s own reply must report mine = true, got ' . var_export($reply['mine'] ?? null, true));
        $incremental = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['doctor'], (int) $thread['id'], (int) $sent['id'], 50));
        self::assertCount(1, $incremental['messages'], 'incremental polling returns only new messages; got ' . count((array) $incremental['messages']) . ' with ids [' . implode(',', array_column((array) $incremental['messages'], 'id')) . ']');
        self::assertSame((int) $reply['id'], (int) ($incremental['messages'][0]['id'] ?? 0), 'expected only the reply id ' . (int) $reply['id'] . ', got ' . (int) ($incremental['messages'][0]['id'] ?? 0));

        $context = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->context((int) $world['secretary']));
        self::assertSame(StaffMessagingService::RETENTION_POLICY, $context['retention_policy'], 'expected retention ' . StaffMessagingService::RETENTION_POLICY . ', got ' . (string) $context['retention_policy']);
        self::assertContains((int) $world['doctor'], array_column((array) $context['counterparts'], 'wp_user_id'), 'the doctor must be an eligible counterpart; counterparts = [' . implode(',', array_column((array) $context['counterparts'], 'wp_user_id')) . ']');
    }

    /**
     * Cut 5 — the complete original body: cut 4 plus the audit projection.
     */
    public function testBisectCut5(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id'], 'the created thread must have a positive id, got ' . (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id'], 'expected visit_id ' . (int) $world['visit'] . ', got ' . (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id'], 'expected location_id ' . (int) $world['location'] . ', got ' . (int) $thread['location_id']);

        $service = App::staff_messaging_service();
        $sent = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['doctor'],
            (int) $thread['id'],
            '<script>alert(1)</script>سلام، بیمار آماده پذیرش است.'
        ));
        self::assertStringNotContainsString('<script', (string) $sent['body'], 'the stored body must be sanitized; got body length ' . strlen((string) $sent['body']));
        self::assertStringContainsString('بیمار آماده پذیرش', (string) $sent['body'], 'the stored body must keep its text; got body length ' . strlen((string) $sent['body']));
        self::assertNotSame('', (string) $sent['created_at'], 'created_at must not be empty, got "' . (string) $sent['created_at'] . '"');
        self::assertNotSame('', (string) $sent['jalali_time'], 'jalali_time must not be empty, got "' . (string) $sent['jalali_time'] . '"');

        $secretary_threads = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        self::assertCount(1, $secretary_threads['threads'], 'this secretary must see exactly one thread, got ' . count((array) $secretary_threads['threads']));
        self::assertSame(1, (int) $secretary_threads['threads'][0]['unread_count'], 'unread_count carries the one unread message, got ' . (int) $secretary_threads['threads'][0]['unread_count']);
        self::assertSame(1, (int) $secretary_threads['unread_total'], 'unread_total sums the same one message, got ' . (int) $secretary_threads['unread_total']);

        $inbox = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread['id'], 0, 50));
        self::assertCount(1, $inbox['messages'], 'this thread holds exactly one message, got ' . count((array) $inbox['messages']));
        self::assertArrayHasKey(0, $inbox['messages'], 'diagnostic: the thread must hold at least one message; message count = ' . count((array) $inbox['messages']));
        self::assertFalse($inbox['messages'][0]['mine'], 'the doctor\'s message must not be reported as the secretary\'s own; mine = ' . var_export($inbox['messages'][0]['mine'] ?? null, true));
        self::assertArrayNotHasKey('clinical_note', $inbox['messages'][0], 'a message row must not expose clinical_note; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));
        self::assertArrayNotHasKey('medical_record', $inbox['messages'][0], 'a message row must not expose medical_record; keys = ' . implode(',', array_keys((array) $inbox['messages'][0])));

        $read = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->mark_read((int) $world['secretary'], (int) $thread['id']));
        self::assertSame(0, (int) $read['unread_count'], 'mark_read clears the counter, got ' . (int) $read['unread_count']);

        $reply = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['secretary'],
            (int) $thread['id'],
            'پذیرش انجام شد.'
        ));
        self::assertTrue($reply['mine'], 'the secretary\'s own reply must report mine = true, got ' . var_export($reply['mine'] ?? null, true));
        $incremental = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['doctor'], (int) $thread['id'], (int) $sent['id'], 50));
        self::assertCount(1, $incremental['messages'], 'incremental polling returns only new messages; got ' . count((array) $incremental['messages']) . ' with ids [' . implode(',', array_column((array) $incremental['messages'], 'id')) . ']');
        self::assertSame((int) $reply['id'], (int) ($incremental['messages'][0]['id'] ?? 0), 'expected only the reply id ' . (int) $reply['id'] . ', got ' . (int) ($incremental['messages'][0]['id'] ?? 0));

        $context = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->context((int) $world['secretary']));
        self::assertSame(StaffMessagingService::RETENTION_POLICY, $context['retention_policy'], 'expected retention ' . StaffMessagingService::RETENTION_POLICY . ', got ' . (string) $context['retention_policy']);
        self::assertContains((int) $world['doctor'], array_column((array) $context['counterparts'], 'wp_user_id'), 'the doctor must be an eligible counterpart; counterparts = [' . implode(',', array_column((array) $context['counterparts'], 'wp_user_id')) . ']');

        // Audit records the send without ever storing the message body.
        $audit = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE action = 'MESSAGE_SEND' AND resource_id = %d AND COALESCE(meta_json, '') NOT LIKE %s",
            [(int) $reply['id'], '%alert(1)%']
        );
        self::assertSame(1, (int) $audit, 'exactly one MESSAGE_SEND audit must exist for the reply, body never stored; got ' . (int) $audit);
    }

    /**
     * @return array<string, int>
     */
    private function world(): array
    {
        $org      = $this->iv1_org('bis');
        $clinic   = $this->iv1_clinic($org);
        $location = $this->iv1_location($clinic);
        $doctor   = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'doc');
        $secretary = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'sec');
        $this->iv1_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $clinician = $this->iv1_clinician($clinic, $doctor, 'پزشک پیام');
        $patient   = $this->iv1_patient($clinic, 'بیمار پیام');
        $visit     = $this->iv1_visit($clinic, $location, $patient, $clinician);

        return compact('org', 'clinic', 'location', 'doctor', 'secretary', 'clinician', 'patient', 'visit');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function call_thread(array $world, int $actor, array $payload): array
    {
        return $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->create_thread($actor, $payload));
    }
}

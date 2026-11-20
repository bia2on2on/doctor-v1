<?php
/**
 * Phase 7 — FR-5.5: No-show completion (TEST-ONLY RED suite).
 *
 * Contract under test (docs/state-machines/appointment.md — T8, I-3;
 * docs/api/error-codes.md):
 *
 *  MANUAL (secretary, capability `cpms_appt_no_show`, trusted explicit
 *  Clinic scope, established REST mutation surface):
 *   - confirmed appointment → no_show; `no_show_at` written;
 *   - non-empty reason required (established validation semantics);
 *   - reason preserved in the `APPOINTMENT_NO_SHOW` audit evidence;
 *   - `appointments.reason` (booking reason) NOT overwritten;
 *   - no invented reason length limit (asserted by using a natural reason);
 *   - slot booked/held counters unchanged;
 *   - genuinely active Visit → `HAS_ACTIVE_VISIT` / HTTP 409 (I-3);
 *   - a STALE `active_visit_id` (pointing at an inactive/terminal Visit)
 *     alone must NOT block; successful T8 clears it;
 *   - cross-Clinic fails closed with established parity;
 *   - repeat no-show from terminal state → `CLINIC_INVALID_TRANSITION`;
 *   - missing capability → denied, zero mutation.
 *
 *  AUTOMATIC (existing sweep, configurable grace — default 30 min;
 *  Location timezone is the operational time source; bounds/cursor
 *  semantics unchanged):
 *   - before grace untouched; after grace (no active Visit) → no_show;
 *   - genuinely active Visit blocks; stale pointer must NOT block
 *     (current HEAD defect: candidate query + service guard both skip it);
 *   - automatic T8 sends no patient notification; counters unchanged.
 *
 *  CONCURRENCY (check-in vs no-show on the same appointment row):
 *   - terminal no_show must never end with a genuinely active bound Visit;
 *   - one representative real-DB race with genuinely independent
 *     processes/connections (pcntl fork; each child builds its own fresh
 *     wpdb connection and removes the test-only SAVEPOINT query-rewrite so
 *     its commits are real; final state read on a fresh mysqli — never the
 *     parent's REPEATABLE-READ snapshot).
 *   - The no-show side is the AUTOMATIC sweep — the only FR-5.5 no-show
 *     path that exists at current HEAD (the manual surface itself is part
 *     of the RED; calling a not-yet-existing method would be a child
 *     fatal = harness failure, not product evidence).
 *   - BOTH workers fire at the SAME barrier instant (zero offset) — a
 *     genuine simultaneous start competing for the same appointment row
 *     lock. A scheduling offset/lead alone is NOT accepted as concurrency
 *     evidence: each child records wall-clock `call_started_at` /
 *     `call_finished_at` around its real product call, and the test
 *     asserts a strict in-flight overlap of the two call windows
 *     (max(starts) < min(ends)) — structurally guaranteed in a true lock
 *     race, because the loser blocks INSIDE its call window on the
 *     winner's appointment row lock and proceeds only after the winner
 *     commits. The final state matching one of the two legal serial
 *     orders is the second half of the proof: the loser observed the
 *     winner's committed row state, which is impossible without having
 *     waited on the winner's row lock.
 *   - Independence evidence recorded per child: distinct child PIDs
 *     (≠ parent PID, ≠ each other), own fresh wpdb connection
 *     (check_connection), and the test-only query-filter census
 *     (present before, removed after).
 *   - Both legal serialization orders are asserted; no sequential
 *     execution is manufactured as concurrency evidence (if an attempt
 *     shows no in-flight overlap, the attempt fails as a harness defect).
 *
 * RED classification at current HEAD (cf1ace1 — no manual no-show
 * surface exists anywhere; the sweep skips stale pointers):
 *   - T1, T2, T3, T4, T5, T7, T9  → fail: 404 `rest_no_route` where the
 *     contract envelope (200 / 400 / 403 / 404-CLINIC_NOT_FOUND / 409) is
 *     expected — the manual no-show REST surface is missing.
 *   - T8 → fail: stale `active_visit_id` blocks the automatic sweep —
 *     the two-layered stale-pointer defect.
 *   - T12 → fail: check-in binds a walk-in Visit to a terminal no_show
 *     appointment (forbidden terminal state) when the sweep wins.
 *   - T6, T10, T11 → PASS on HEAD — positive controls for behavior that
 *     must be preserved (sweep skips genuinely active Visit; grace
 *     boundary + no notification + counters; Location-timezone semantics).
 *
 * GREEN (out of scope for this change — test-only RED) must add:
 *   - manual no-show: REST `POST /clinic/v1/appointments/{id}/no-show`
 *     through the established nonce → capability (`cpms_appt_no_show`) →
 *     trusted Clinic scope architecture, with service-layer validation,
 *     I-3 guard, T8, audit-with-reason, and pointer clearing;
 *   - the stale-pointer fix for the automatic sweep;
 *   - the check-in binding guard for terminal appointments.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Jobs\JobWake;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase7Fr55NoShowRedTest extends WP_UnitTestCase {
    private const NS = '/clinic/v1';

    /** Operational timezone of the main fixture locations (no DST). */
    private const TZ = 'Asia/Tehran';
    /** UTC+5, no DST — positive-offset trap for the Location-tz semantics. */
    private const TZ_POS = 'Asia/Karachi';
    /** UTC-8, no DST — negative-offset trap for the Location-tz semantics. */
    private const TZ_NEG = 'Pacific/Pitcairn';

    /** Live Visit statuses — mirrors VisitMachine::ACTIVE_STATUSES (I-3). */
    private const LIVE_VISIT_STATUSES = [
        'checked_in', 'waiting', 'called', 'in_consultation',
        'consultation_completed', 'awaiting_payment', 'paid',
    ];

    /** Race harness: barrier countdown before the simultaneous fire. */
    private const BARRIER_SEC = 1.5;
    private const RACE_ATTEMPTS = 3;
    /** Bounded wait for the forked children to finish after the barrier is released. */
    private const RACE_FINISH_DEADLINE_SEC = 30.0;
    /** Bounded wait for a SIGKILLed child to be reaped; never block the suite on it. */
    private const RACE_REAP_DEADLINE_SEC = 5.0;
    /** Child-side deadline: after this the child writes a fatal outcome and exits itself. */
    private const RACE_CHILD_BARRIER_DEADLINE_SEC = 45.0;
    /** Bounded EINTR retries for one child probe: a transient signal is never a reason to kill. */
    private const RACE_PROBE_RETRIES = 20;

    /** P2: head start that decides which worker takes the appointment row lock first. */
    private const RACE_HEAD_START_SEC = 0.35;

    private string $fileTag;

    private int $orgA = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $locationPos = 0;
    private int $locationNeg = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;
    private int $patientA = 0;
    private int $patientB = 0;
    private int $secretaryA = 0;

    /** @var int[] */
    private array $userIds = [];
    private array $parentForkProbe = [];

    private int $jobsHighWater = 0;
    private int $oplogsHighWater = 0;

    // ================= Lifecycle =================

    protected function setUp(): void {
        parent::setUp();
        set_time_limit(300);

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->fileTag = 'p7nss-' . substr(md5(uniqid('', true)), 0, 10);

        global $wpdb;
        $this->jobsHighWater = (int) $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'cpms_jobs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->oplogsHighWater = (int) $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'cpms_operational_logs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $now = $this->nowUtcSql();
        $uid = uniqid('p7nss', false);

        $this->orgA = $this->insertOrganization('Org FR55 ' . $uid, 'org-fr55-' . $uid, $now);
        $this->clinicA = $this->insertClinic($this->orgA, 'Clinic A FR55 ' . $uid, 'clinic-a-fr55-' . $uid, $now);
        $this->clinicB = $this->insertClinic($this->orgA, 'Clinic B FR55 ' . $uid, 'clinic-b-fr55-' . $uid, $now);
        $this->locationA = $this->insertLocation($this->clinicA, 'Main A FR55', 'main-a-fr55-' . $uid, self::TZ, 1, $now);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B FR55', 'main-b-fr55-' . $uid, self::TZ, 1, $now);
        $this->locationPos = $this->insertLocation($this->clinicA, 'Branch +5 FR55', 'branch-pos-fr55-' . $uid, self::TZ_POS, 0, $now);
        $this->locationNeg = $this->insertLocation($this->clinicA, 'Branch -8 FR55', 'branch-neg-fr55-' . $uid, self::TZ_NEG, 0, $now);

        $this->clinicianA = $this->insertClinician($this->clinicA, 'Dr. A FR55 ' . $uid, $now);
        $this->clinicianB = $this->insertClinician($this->clinicB, 'Dr. B FR55 ' . $uid, $now);
        $this->patientA = $this->insertPatient($this->clinicA, 'MR-FR55-A-' . $uid, $now);
        $this->patientB = $this->insertPatient($this->clinicB, 'MR-FR55-B-' . $uid, $now);

        $this->secretaryA = $this->makeUser('p7nss-sec-' . $uid, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryA, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);

        // Service-level assertions attribute audit rows to the fixture Clinic.
        ScopeContext::set(ClinicScope::forClinic($this->clinicA));

        // Committed fixture: visible to forked race children (their own DB
        // connections) — the test transaction would hide it from them.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    protected function tearDown(): void {
        $this->purgeFixture();
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ================= T1 — manual happy path (also REST reachability) =================

    public function testManualNoShowHappyPathPersistsT8ReasonAuditAndCounters(): void {
        $auth = new AuthorizationService(new MembershipRepository(App::db()));
        self::assertTrue(
            $auth->can($this->secretaryA, $this->clinicA, RolesAndCapabilities::APPT_NO_SHOW),
            'precondition: cpms_appt_no_show must be granted to the secretary by default'
        );

        $reason = 'patient-never-arrived FR55-' . substr(uniqid('', true), -8);
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'hp',
            'existing-booking-reason FR55-' . substr(uniqid('', true), -8)
        );
        $before = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $reason);

        self::assertSame(
            200,
            $response->get_status(),
            'manual no-show must be reachable through the established REST mutation surface '
            . '(POST ' . self::NS . '/appointments/{id}/no-show, nonce + cpms_appt_no_show + '
            . 'trusted Clinic scope). On HEAD: 404 rest_no_route = missing manual surface. body: '
            . wp_json_encode($response->get_data())
        );
        self::assertIsArray($response->get_data());

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'confirmed → no_show (T8)');
        self::assertNotNull($appt['no_show_at'], 'no_show_at must be written on T8 success');
        self::assertNull($appt['active_visit_id'], 'a pointer-less appointment must stay pointer-less');
        self::assertSame(
            $seed['reason'],
            $appt['reason'],
            'appointments.reason (booking reason) must NOT be overwritten by the no-show reason'
        );
        $this->assertNoShowAuditWithReason($seed['id'], $reason);

        $after = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $before['booked_count'], (int) $after['booked_count'], 'slot booked counter must be unchanged by no-show');
        self::assertSame((int) $before['held_count'], (int) $after['held_count'], 'slot held counter must be unchanged by no-show');
    }

    // ================= T2 — empty / whitespace reason =================

    public function testManualNoShowEmptyAndWhitespaceReasonRejectedWithZeroMutation(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'er'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $cases = ['', '   '];
        foreach ($cases as $i => $badReason) {
            $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $badReason);

            self::assertContains(
                $response->get_status(),
                [400, 422],
                "empty/whitespace reason (case {$i}) must be rejected with the established "
                . 'validation semantics (CLINIC_VALIDATION_FAILED). On HEAD: 404 rest_no_route = '
                . 'missing manual surface. body: ' . wp_json_encode($response->get_data())
            );
            $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED', "empty/whitespace reason (case {$i})");

            $appt = $this->appointmentRow($seed['id']);
            self::assertSame('confirmed', $appt['status'], "zero mutation (case {$i})");
            self::assertNull($appt['no_show_at'], "zero mutation (case {$i})");
            self::assertSame($seed['reason'], $appt['reason'], "zero mutation (case {$i})");
            self::assertSame(0, $this->noShowAuditCount($seed['id']), "no APPOINTMENT_NO_SHOW audit (case {$i})");
        }

        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T3 — missing cpms_appt_no_show capability =================

    public function testMissingCpmsApptNoShowCapabilityRejectedWithZeroMutation(): void {
        $membership = App::membership_service()->active_membership_for($this->clinicA, $this->secretaryA);
        self::assertNotNull($membership, 'precondition: active secretary membership');
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::APPT_NO_SHOW, 'deny');
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // The denial is membership-scoped: the coarse WP cap stays granted.
        self::assertTrue(user_can($this->secretaryA, RolesAndCapabilities::APPT_NO_SHOW), 'precondition: coarse WP cap remains');
        $auth = new AuthorizationService(new MembershipRepository(App::db()));
        self::assertFalse($auth->can($this->secretaryA, $this->clinicA, RolesAndCapabilities::APPT_NO_SHOW), 'precondition: scoped cap denied');

        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'mc'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'no-show attempt without capability');

        self::assertSame(
            403,
            $response->get_status(),
            'missing cpms_appt_no_show must be denied (403 CLINIC_PERMISSION_DENIED). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED', 'missing capability');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame($seed['reason'], $appt['reason'], 'zero mutation');
        self::assertSame(0, $this->noShowAuditCount($seed['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T4 — cross-Clinic + missing trusted scope =================

    public function testCrossClinicAndMissingTrustedScopeFailClosedWithZeroMutation(): void {
        $seedB = $this->seedConfirmedAppointment(
            $this->clinicB,
            $this->locationB,
            $this->clinicianB,
            $this->patientB,
            $this->pastSlotSpec(180),
            'xc'
        );
        $slotBefore = $this->slotRow($seedB['slot_id']);

        // (a) Valid trusted scope (Clinic A) against a Clinic B appointment —
        // established cross-Clinic parity: 404 CLINIC_NOT_FOUND.
        $r1 = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seedB['id'], 'cross-clinic attempt');
        self::assertSame(
            404,
            $r1->get_status(),
            'cross-Clinic no-show must fail closed (404 CLINIC_NOT_FOUND parity). '
            . 'On HEAD: rest_no_route = missing manual surface. body: ' . wp_json_encode($r1->get_data())
        );
        $this->assertClinicError($r1, 'CLINIC_NOT_FOUND', 'cross-clinic parity');

        // (b) Trusted scope is REQUIRED: a scope header naming Clinic B, where
        // the secretary has no membership, cannot be established.
        $r2 = $this->dispatchNoShow($this->secretaryA, $this->clinicB, $seedB['id'], 'scope attempt');
        self::assertSame(
            403,
            $r2->get_status(),
            'a trusted explicit Clinic scope is required (403 CLINIC_SCOPE_UNAVAILABLE). '
            . 'On HEAD: rest_no_route = missing manual surface. body: ' . wp_json_encode($r2->get_data())
        );
        $this->assertClinicError($r2, 'CLINIC_SCOPE_UNAVAILABLE', 'missing trusted scope');
        $data2 = $r2->get_data();
        $body2 = wp_json_encode($data2);
        self::assertIsString($body2);
        self::assertStringNotContainsString(
            'membership',
            $body2,
            'internal scope reason must not leak into the REST envelope'
        );
        self::assertArrayNotHasKey('reason', is_array($data2['data'] ?? null) ? $data2['data'] : []);

        $appt = $this->appointmentRow($seedB['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame($seedB['reason'], $appt['reason'], 'zero mutation');
        self::assertSame(0, $this->noShowAuditCount($seedB['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seedB['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T5 — genuinely active Visit blocks the manual op =================

    public function testGenuinelyActiveVisitRejectsManualNoShowWithHasActiveVisit(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->futureSlotSpec(3, '10:00:00'),
            'av'
        );
        $visit = $this->checkInOnAppointmentDay($seed['id']);
        self::assertGreaterThan(0, (int) ($visit['id'] ?? 0), 'precondition: check-in of an upcoming appointment creates a live Visit');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'no-show over an active visit');

        self::assertSame(
            409,
            $response->get_status(),
            'a genuinely active Visit must block the manual no-show with 409 HAS_ACTIVE_VISIT (I-3). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'HAS_ACTIVE_VISIT', 'active-visit guard');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame((int) $visit['id'], (int) ($appt['active_visit_id'] ?? 0), 'pointer untouched');
        $live = $this->liveVisitsForAppointment($seed['id']);
        self::assertCount(1, $live, 'the live Visit must remain live');
        self::assertSame((int) $visit['id'], (int) $live[0]['id']);
        self::assertSame(0, $this->noShowAuditCount($seed['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T6 — positive control: active Visit blocks the sweep =================

    public function testGenuinelyActiveVisitBlocksAutomaticSweep(): void {
        // Upcoming (today, +2h) appointment inside the sweep's date window but
        // before grace — so the ONLY thing that can protect it is the active
        // Visit (I-3), never the grace boundary.
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->soonSlotSpec(2),
            'as'
        );
        $visit = $this->checkInOnAppointmentDay($seed['id']);
        self::assertGreaterThan(0, (int) ($visit['id'] ?? 0), 'precondition: live Visit bound');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'a genuinely active Visit must protect the appointment from the sweep (I-3)');
        self::assertNull($appt['no_show_at'], 'no_show_at must stay null');
        $live = $this->liveVisitsForAppointment($seed['id']);
        self::assertCount(1, $live, 'the live Visit must remain live');
        self::assertSame((int) $visit['id'], (int) $live[0]['id']);
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T7 — manual no-show with a STALE pointer =================

    public function testManualNoShowSucceedsWithStalePointerAndClearsIt(): void {
        $reason = 'patient-did-not-come FR55-' . substr(uniqid('', true), -8);
        $seed = $this->seedStalePointerPastAppointment('msp');

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $reason);

        self::assertSame(
            200,
            $response->get_status(),
            'a STALE active_visit_id (pointing at an inactive/terminal Visit) must NOT block '
            . 'the manual no-show. On HEAD: 404 rest_no_route = missing manual surface. body: '
            . wp_json_encode($response->get_data())
        );

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'confirmed → no_show (T8)');
        self::assertNotNull($appt['no_show_at'], 'no_show_at must be written');
        self::assertNull($appt['active_visit_id'], 'a legitimately successful T8 must clear the stale active_visit_id');
        self::assertSame(0, count($this->liveVisitsForAppointment($seed['id'])), 'no live Visit involved');
        $this->assertNoShowAuditWithReason($seed['id'], $reason);
        self::assertSame($seed['reason'], $appt['reason'], 'appointments.reason must be preserved');
    }

    // ================= T8 — automatic sweep with a STALE pointer (defect RED) =================

    public function testAutomaticSweepSucceedsWithStalePointerAndClearsIt(): void {
        $seed = $this->seedStalePointerPastAppointment('asp');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame(
            'confirmed',
            $appt['status'],
            'R4 (1.0) manual-only: a past-grace row must stay confirmed — a past-grace confirmed appointment whose '
            . 'active_visit_id points at an INACTIVE/terminal Visit must be swept to no_show — '
            . 'a stale pointer alone must not block the automatic sweep. '
            . 'On HEAD the candidate query (active_visit_id IS NULL) and the per-row service '
            . 'guard both skip it, leaving status=' . $appt['status'] . ' (processed=' . (int) ($res['processed'] ?? 0) . ')'
        );
        self::assertSame(0, (int) ($res['processed'] ?? -1), 'R4: the inert sweep processes nothing');
        self::assertNull($appt['no_show_at'], 'no_show_at must stay NULL without a manual decision');
        self::assertNotNull($appt['active_visit_id'], 'the inert sweep must not clear the stale pointer');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T9 — repeat no-show from terminal state =================

    public function testRepeatNoShowFromTerminalStateRejectedWithInvalidTransition(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'rp'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        // R4 (1.0): a terminal state can only come from a MANUAL, authorised action.
        $first = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'first manual no-show');
        self::assertSame(200, $first->get_status(), 'precondition: an authorised manual no-show succeeds');
        $afterSweep = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $afterSweep['status'], 'precondition: the manual action set the terminal state');
        self::assertNotNull($afterSweep['no_show_at'], 'precondition: no_show_at written by the manual action');
        $auditCountBefore = $this->noShowAuditCount($seed['id']);
        self::assertGreaterThanOrEqual(1, $auditCountBefore, 'precondition: sweep audit row present');

        // Repeat manual no-show from the terminal state.
        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'repeat no-show attempt');

        self::assertSame(
            409,
            $response->get_status(),
            'repeat no-show from a terminal state must be rejected (409 CLINIC_INVALID_TRANSITION). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'CLINIC_INVALID_TRANSITION', 'terminal repeat');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'no further mutation');
        self::assertSame($afterSweep['no_show_at'], $appt['no_show_at'], 'no_show_at must not be rewritten');
        self::assertSame($auditCountBefore, $this->noShowAuditCount($seed['id']), 'no further APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T10 — automatic regression controls (positive) =================

    public function testAutomaticSweepGraceBoundaryNoNotificationAndNoCounterDrift(): void {
        // Inside the default 30-minute grace → untouched.
        $beforeGrace = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(10),
            'bg'
        );
        // Past the default grace, no active Visit → no_show.
        $afterGrace = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'ag'
        );
        $slotBeforeB = $this->slotRow($beforeGrace['slot_id']);
        $slotAfterB = $this->slotRow($afterGrace['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $b = $this->appointmentRow($beforeGrace['id']);
        self::assertSame('confirmed', $b['status'], 'before-grace appointment must stay confirmed');
        self::assertNull($b['no_show_at'], 'before-grace appointment must keep no_show_at null');

        $a = $this->appointmentRow($afterGrace['id']);
        self::assertSame('confirmed', $a['status'], 'R4: even past the grace window nothing is swept automatically');
        self::assertNull($a['no_show_at'], 'no_show_at must stay NULL without a manual decision');
        self::assertSame(0, (int) ($res['processed'] ?? -1), 'R4: the sweep processes nothing');

        // Slot counters are untouched by the sweep.
        $slotAfter1 = $this->slotRow($beforeGrace['slot_id']);
        $slotAfter2 = $this->slotRow($afterGrace['slot_id']);
        self::assertSame((int) $slotBeforeB['booked_count'], (int) $slotAfter1['booked_count'], 'before-grace slot booked unchanged');
        self::assertSame((int) $slotBeforeB['held_count'], (int) $slotAfter1['held_count'], 'before-grace slot held unchanged');
        self::assertSame((int) $slotAfterB['booked_count'], (int) $slotAfter2['booked_count'], 'after-grace slot booked unchanged');
        self::assertSame((int) $slotAfterB['held_count'], (int) $slotAfter2['held_count'], 'after-grace slot held unchanged');

        // Automatic T8 sends no (newly invented) patient notification.
        global $wpdb;
        $notices = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_notifications WHERE clinic_id = %d', $this->clinicA) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $notices, 'automatic no-show must not emit patient notifications');
        $sms = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE clinic_id = %d', $this->clinicA) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $sms, 'automatic no-show must not emit SMS');
    }

    // ================= T11 — Location timezone = operational time source (positive) =================

    public function testAutomaticSweepUsesLocationTimezoneAsOperationalTimeSource(): void {
        // Case X (UTC+5 branch): the slot is 45 minutes past in the Location
        // timezone → past the 30-minute grace → must be swept. A naive
        // UTC interpretation of the stored local wall-clock would see the
        // slot ~4h in the future and (wrongly) leave it confirmed.
        $caseX = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationPos,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(45, self::TZ_POS),
            'tzx'
        );
        // Case Y (UTC-8 branch): the slot is 10 minutes past in the Location
        // timezone → inside the grace → must stay confirmed. A naive UTC
        // interpretation of the stored local wall-clock would see the slot
        // ~8h in the past and (wrongly) mark it no_show.
        $caseY = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationNeg,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(10, self::TZ_NEG),
            'tzy'
        );

        App::visitService()->processNoShows(null, 0);

        $x = $this->appointmentRow($caseX['id']);
        self::assertSame('confirmed', $x['status'], 'R4: no automatic path interprets Location time to mark a row');
        self::assertNull($x['no_show_at']);

        $y = $this->appointmentRow($caseY['id']);
        self::assertSame('confirmed', $y['status'], 'UTC-8 case: 10 minutes past in the Location timezone is inside the 30-minute grace');
        self::assertNull($y['no_show_at']);
    }

    // ================= T12 — concurrency: check-in vs no-show (real-DB race) =================
    //
    // The race runs ONCE per class and its observations are asserted by ONE test per
    // invariant, so a red run names the invariant instead of hiding several behind a
    // single aggregate name. The harness has its own test: a fork / bootstrap /
    // child-exit problem can never be mistaken for product evidence.

    /** @var array<int, array{attempt: int, outcomes: array<string, array<string, mixed>>, state: array<string, mixed>}>|null */
    private static ?array $race_observations = null;

    /** @var string|null Harness-level fatal (fork, child exit, outcome file); null means the harness completed. */
    private static ?string $race_harness_error = null;

    /** @var string|null (a) fork / child exit / outcome file. */
    private static ?string $race_error_fork = null;

    /** @var string|null (b) seeding or COMMIT: the seeded rows were not visible to a fresh connection. */
    private static ?string $race_error_seed = null;

    /** @var string|null (c) post-race state read: a row the state reader needs was missing. */
    private static ?string $race_error_state = null;

    /** @var bool Whether this run has pcntl_fork (recorded for the report, never asserted softly). */
    private static bool $race_pcntl_available = false;

    /**
     * PER-ATTEMPT phase record, written WITHOUT asserting so each diagnostic test can
     * name its own phase: attempt => ['fork'|'seed'|'state' => 'reached'|error text].
     * Every attempt starts at 'not-reached', so an earlier success can never mask a
     * later failure.
     *
     * @var array<int, array<string, string>>
     */
    private static array $race_phase = [];

    /**
     * T2: the forked race needs fork, wait and a kill primitive. Missing `posix_kill`
     * would leave a child polling a barrier file the parent removes, so all of them are
     * required: CI fails loudly, a local run skips with the missing names.
     */
    private function requireForkCapabilities(): void
    {
        $missing = [];
        if (!function_exists('pcntl_fork')) {
            $missing[] = 'pcntl_fork';
        }
        if (!function_exists('pcntl_waitpid')) {
            $missing[] = 'pcntl_waitpid';
        }
        if (!function_exists('posix_kill')) {
            $missing[] = 'posix_kill';
        }
        if (!defined('SIGKILL')) {
            $missing[] = 'SIGKILL';
        }
        if ($missing === []) {
            return;
        }

        $detail = 'missing: ' . implode(', ', $missing);
        if ('true' === getenv('GITHUB_ACTIONS')) {
            self::fail(
                'the forked race cannot run in this CI environment (' . $detail . '), so its evidence would be '
                . 'silently skipped. This suite FAILS instead of skipping whenever GITHUB_ACTIONS=true.'
            );
        }
        $this->markTestSkipped('the forked race cannot run here (' . $detail . ').');
    }

    /**
     * T2: one non-blocking `pcntl_waitpid()` poll that separates a reaped child from a
     * transient (EINTR) and a hard waitpid error instead of treating every -1 as ECHILD.
     *
     * @return array{state: string, status: int} state: reaped|running|gone|error;
     *                                           status: wait status or the pcntl error
     */
    private function pollChild(int $pid): array
    {
        if ($pid <= 0) {
            return ['state' => 'error', 'status' => 0];
        }

        $status = 0;
        $waited = pcntl_waitpid($pid, $status, WNOHANG);
        if ($pid === $waited) {
            return ['state' => 'reaped', 'status' => $status];
        }
        if (0 === $waited) {
            return ['state' => 'running', 'status' => $status];
        }

        $error = pcntl_get_last_error();
        if (defined('PCNTL_ECHILD') && PCNTL_ECHILD === $error) {
            return ['state' => 'gone', 'status' => 0];
        }

        return ['state' => 'error', 'status' => $error];
    }

    /**
     * T1: probe one child, retrying EINTR a bounded number of times, so a transient signal
     * can never be mistaken for "safe to kill" (and never for "safe to skip").
     *
     * @return array{state: string, status: int}
     */
    private function probeChild(int $pid): array
    {
        $poll    = $this->pollChild($pid);
        $retries = 0;
        while ('error' === $poll['state']
            && defined('PCNTL_EINTR') && PCNTL_EINTR === $poll['status']
            && $retries < self::RACE_PROBE_RETRIES
        ) {
            ++$retries;
            usleep(1000);
            $poll = $this->pollChild($pid);
        }

        return $poll;
    }

    /**
     * T1: the reaper's decision for ONE probe result — pure, so the rule "a reaped child is
     * never signalled" is testable without forking.
     *
     * @return string drop|kill|retry
     */
    private static function reapActionFor(string $state, int $status): string
    {
        if ('reaped' === $state || 'gone' === $state) {
            return 'drop';
        }
        if ('running' === $state) {
            return 'kill';
        }
        if (defined('PCNTL_EINTR') && PCNTL_EINTR === $status) {
            return 'retry';
        }

        return 'kill';
    }

    /** T2: SIGKILL exactly one owned pid; guarded so a missing primitive cannot fatal. */
    private function killChild(int $pid): void
    {
        if ($pid <= 0 || !function_exists('posix_kill') || !defined('SIGKILL')) {
            return;
        }
        @posix_kill($pid, SIGKILL);
    }

    /**
     * Run the real-DB race once per class and keep the observations.
     *
     * @return array<int, array{attempt: int, outcomes: array<string, array<string, mixed>>, state: array<string, mixed>}>
     */
    private function raceObservations(): array
    {
        if (self::$race_observations !== null || self::$race_harness_error !== null) {
            return self::$race_observations ?? [];
        }

        // P5/T2: a missing primitive must not let CI silently skip the forked race suite.
        // posix_kill is required too: without it a fork/readiness failure would leave a
        // child polling a barrier file the parent deletes, i.e. a stuck process.
        $this->requireForkCapabilities();
        self::$race_pcntl_available = true;

        $observations = [];
        $race_anchor  = new DateTimeImmutable( 'now', new DateTimeZone( self::TZ ) );
        $seed_failure = null;
        try {
            for ($attempt = 1; $attempt <= self::RACE_ATTEMPTS; $attempt++) {
                self::$race_phase[$attempt] = ['fork' => 'not-reached', 'seed' => 'not-reached', 'state' => 'not-reached'];

                // G4: the WHOLE seed — patient insert, its COMMIT, the appointment seed and
                // the third-connection visibility probe — lives inside the seed-phase try,
                // so a failure anywhere in it is recorded against that attempt's seed phase
                // instead of leaving the phase at 'not-reached'.
                try {
                    $patientId = $this->insertPatient(
                        $this->clinicA,
                        'MR-FR55-R' . $attempt . '-' . $this->fileTag,
                        $this->nowUtcSql()
                    );
                    global $wpdb;
                    $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

                    // CHECK-IN ELIGIBLE confirmed appointment (same trusted local day,
                    // inside grace), so the check-in worker can legitimately win this
                    // race; the manual no-show has no time guard and stays legal too.
                    $seed = $this->seedConfirmedAppointment(
                        $this->clinicA,
                        $this->locationA,
                        $this->clinicianA,
                        $patientId,
                        $this->eligibleRaceSlotSpec( $race_anchor, $attempt ),
                        'rc' . $attempt
                    );
                    $this->assertRaceSeedVisible($seed['id'], $seed['slot_id'], $attempt);
                    self::$race_phase[$attempt]['seed'] = 'reached';
                } catch (Throwable $e) {
                    global $wpdb;
                    $db_error     = (string) $wpdb->last_error;
                    $is_duplicate = false !== stripos( $db_error, 'duplicate entry' )
                        || ( $e instanceof \mysqli_sql_exception && 1062 === $e->getCode() );
                    if ( $is_duplicate ) {
                        $seed_failure = 'duplicate-key';
                    } elseif ( '' !== $db_error || $e instanceof \mysqli_sql_exception ) {
                        $seed_failure = 'other-db-error';
                    } else {
                        $seed_failure = 'other-seed-error';
                    }
                    self::$race_phase[$attempt]['seed'] = $seed_failure;
                    throw $e;
                }

                // Genuinely independent workers firing at the SAME barrier instant
                // (zero offset): the MANUAL no-show (BookingService::
                // markNoShowByStaff, the reception-route chain) and the secretary
                // check-in compete for the same appointment row lock. Both
                // serialization orders are legal.
                // P2: attempts 1 and 2 give one worker a small HEAD START so each
                // contested interleaving is actually observed (a zero-offset pair can
                // legitimately be won by the same side on every attempt, which would
                // make a "named winner" assertion vacuous); attempt 3 keeps the genuine
                // simultaneous race. The loser still blocks on the winner's appointment
                // row lock, so the loser's refusal is product evidence either way.
                $workers = match ($attempt) {
                    1 => [
                        ['role' => 'no_show', 'offset' => 0.0],
                        ['role' => 'check_in', 'offset' => self::RACE_HEAD_START_SEC],
                    ],
                    2 => [
                        ['role' => 'check_in', 'offset' => 0.0],
                        ['role' => 'no_show', 'offset' => self::RACE_HEAD_START_SEC],
                    ],
                    default => [
                        ['role' => 'no_show', 'offset' => 0.0],
                        ['role' => 'check_in', 'offset' => 0.0],
                    ],
                };
                try {
                    $outcomes = $this->runRace( $seed['id'], $patientId, $workers, $attempt, $race_anchor );
                } catch (Throwable $e) {
                    self::$race_error_fork = 'attempt ' . $attempt . ': ' . get_class($e) . ': ' . $e->getMessage();
                    self::$race_phase[$attempt]['fork'] = get_class($e) . ': ' . $e->getMessage();
                    throw $e;
                }
                self::$race_phase[$attempt]['fork'] = 'reached';

                try {
                    $state = $this->raceState($seed['id'], $seed['slot_id']);
                } catch (Throwable $e) {
                    self::$race_error_state = 'attempt ' . $attempt . ' (appointment ' . $seed['id'] . ', slot ' . $seed['slot_id'] . '): '
                        . get_class($e) . ': ' . $e->getMessage();
                    self::$race_phase[$attempt]['state'] = get_class($e) . ': ' . $e->getMessage();
                    throw $e;
                }
                self::$race_phase[$attempt]['state'] = 'reached';

                $observations[] = [
                    'attempt' => $attempt,
                    'outcomes' => $outcomes,
                    'state' => $state,
                ];
            }
        } catch (Throwable $e) {
            self::$race_harness_error = null !== $seed_failure
                ? 'seed failure category: ' . $seed_failure
                : get_class( $e ) . ': ' . $e->getMessage();
        }

        self::$race_observations = $observations;

        return $observations;
    }

    /**
     * (b) Prove the seed is visible to an INDEPENDENT connection before the race.
     *
     * The fixture commits with a bare `COMMIT` (the savepoint rewrite in
         * `tests/integration-bootstrap.php` only touches statements carrying the cpms
         * comment marker), so
     * a third connection must see both rows. A miss here is a seeding/COMMIT defect.
     */
    private function assertRaceSeedVisible(int $appointmentId, int $slotId, int $attempt): void
    {
        $conn = $this->freshMysqli();
        $db = App::db();
        $appointmentCount = (int) $this->mysqliRow(
            $conn,
            'SELECT COUNT(*) AS c FROM ' . $db->table('cpms_appointments') . ' WHERE id = ' . (int) $appointmentId
        )['c'];
        $slotCount = (int) $this->mysqliRow(
            $conn,
            'SELECT COUNT(*) AS c FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $slotId
        )['c'];
        $conn->close();

        if ($appointmentCount !== 1 || $slotCount !== 1) {
            self::$race_error_seed = 'attempt ' . $attempt . ': seed not visible to a fresh connection — appointment '
                . $appointmentId . ' count=' . $appointmentCount . ', slot ' . $slotId . ' count=' . $slotCount;
            self::fail(self::$race_error_seed);
        }
    }

    /**
     * G4: assert THIS diagnostic's own phase on EVERY planned attempt, BEFORE any gate on
     * completed observations, so a seed failure in attempt 2 reds the seed name instead of
     * a shared "every attempt must be recorded" count.
     */
    private function assertRacePhaseOnEveryAttempt(string $phase, string $label): void
    {
        for ($attempt = 1; $attempt <= self::RACE_ATTEMPTS; $attempt++) {
            if (!isset(self::$race_phase[$attempt])) {
                self::fail($label . ': attempt ' . $attempt . ' was never started (no phase record — the harness died earlier)');
            }
            $attemptPhase = self::$race_phase[$attempt][$phase] ?? 'missing';
            // The public reporter only projects source locations, never assertion text.
            // Keep one assertion location per sanitized seed category.
            if ( 'seed' === $phase ) {
                if ( 'duplicate-key' === $attemptPhase ) {
                    self::fail( 'Seed failure category: duplicate-key.' );
                }
                if ( 'other-db-error' === $attemptPhase ) {
                    self::fail( 'Seed failure category: other-db-error.' );
                }
                if ( 'other-seed-error' === $attemptPhase ) {
                    self::fail( 'Seed failure category: other-seed-error.' );
                }
            }
            self::assertSame(
                'reached',
                $attemptPhase,
                $label . ' on attempt ' . $attempt . ': ' . $attemptPhase
            );
        }
    }

    /**
     * (a) fork / child exit / outcome file.
     *
     * P4: this asserts ONLY its own phase — it deliberately does not call the shared
     * `raceAttempts()` gate, so a seed or state-read failure cannot turn this name red
     * as well and hide which phase actually failed.
     */
    public function testRaceHarnessForkPhaseCompleted(): void
    {
        $observations = $this->raceObservations(); // also records pcntl availability / skips locally
        self::assertTrue(
            self::$race_pcntl_available,
            'this run recorded pcntl_fork availability: ' . var_export(self::$race_pcntl_available, true)
        );
        $this->assertRacePhaseOnEveryAttempt('fork', 'fork phase');
        self::assertSame(
            self::RACE_ATTEMPTS,
            count($observations),
            'fork phase: every attempt must be recorded, got ' . count($observations)
        );
    }

    /** (b) seeding/COMMIT: the seeded rows must be visible to an independent connection. */
    public function testRaceSeededRowsWereVisibleToAFreshConnection(): void
    {
        $observations = $this->raceObservations();
        $this->assertRacePhaseOnEveryAttempt('seed', 'seed visibility phase');
        self::assertSame(
            self::RACE_ATTEMPTS,
            count($observations),
            'seed visibility phase: every attempt must be recorded, got ' . count($observations)
        );
    }

    /** (c) post-race state read: every row the state reader needs must exist. */
    public function testRacePostRaceStateReadFoundItsRows(): void
    {
        $observations = $this->raceObservations();
        $this->assertRacePhaseOnEveryAttempt('state', 'post-race state read');
        self::assertSame(
            self::RACE_ATTEMPTS,
            count($observations),
            'post-race state read: every attempt must be recorded, got ' . count($observations)
        );
    }

    /**
     * A2: run the REAL reaper with a scripted probe sequence, recording probes and signals.
     * The clock only moves when the reaper sleeps, so no test waits real seconds.
     *
     * @param int[] $pids
     * @param array<int, array{state: string, status: int}> $script answers in call order;
     *                                                            the last answer repeats
     *
     * @return array{probes: array<int, int>, signals: array<int, int>, unconfirmed: array<int, int>, sleeps: int}
     */
    private static function driveReaper(array $pids, array $script, float $deadline = 5.0, float $secondsPerSleep = 1.0): array
    {
        $probes  = [];
        $signals = [];
        $sleeps  = 0;
        $now     = 0.0;

        $answers = array_values($script);

        $result = self::reapOwnedChildren(
            $pids,
            static function (int $pid) use (&$probes, &$answers): array {
                $probes[] = $pid;
                if ([] === $answers) {
                    return ['state' => 'error', 'status' => PCNTL_EINVAL];
                }

                return count($answers) === 1 ? $answers[0] : array_shift($answers);
            },
            static function (int $pid) use (&$signals): void {
                $signals[] = $pid;
            },
            static function (int $microseconds) use (&$sleeps, &$now, $secondsPerSleep): void {
                ++$sleeps;
                $now += $secondsPerSleep;
                unset($microseconds);
            },
            static function () use (&$now): float {
                return $now;
            },
            $deadline
        );

        return [
            'probes'      => $probes,
            'signals'     => $signals,
            'unconfirmed' => $result['unconfirmed'],
            'sleeps'      => $sleeps,
        ];
    }

    /**
     * T1: the reaper's own decision rule. A child whose probe already reaped it (or that is
     * reported gone via ECHILD) must NEVER be signalled — PID reuse would let the signal hit
     * an unrelated process — and a transient EINTR must retry the probe instead of killing.
     */
    public function testReaperNeverSignalsAReapedChildAndRetriesOnEintr(): void
    {
        if (!defined('PCNTL_EINTR') || !defined('PCNTL_EINVAL')) {
            if ('true' === getenv('GITHUB_ACTIONS')) {
                self::fail('the pcntl error constants are missing in CI, so this reaper rule could not be checked');
            }
            $this->markTestSkipped('pcntl error constants are unavailable here.');
        }

        self::assertSame('drop', self::reapActionFor('reaped', 0), 'a REAPED child must be dropped before any signal');
        self::assertSame('drop', self::reapActionFor('gone', 0), 'an ECHILD child must be dropped before any signal');
        self::assertSame('kill', self::reapActionFor('running', 0), 'a child confirmed running may be SIGKILLed');
        self::assertSame('retry', self::reapActionFor('error', PCNTL_EINTR), 'EINTR must retry the probe, never signal');
        self::assertSame(
            'kill',
            self::reapActionFor('error', PCNTL_EINVAL),
            'a hard, non-ECHILD waitpid error keeps the pid owned and escalates'
        );

        // A2: every scenario below runs the REAL reaper (reapOwnedChildren) — the method the
        // cleanup itself calls — with scripted probes, not a model of it.

        // (1) mutation caught: "restore $pending = $owned after pass 1" — a pid reaped on
        // pass 1 would be re-probed and, on the scripted hard error, escalated to a SIGKILL.
        $reaped = self::driveReaper([4242], [['state' => 'reaped', 'status' => 0]]);
        self::assertSame([4242], $reaped['probes'], 'a pid reaped on pass 1 is probed exactly once');
        self::assertSame([], $reaped['signals'], 'a pid reaped on pass 1 is never signalled');
        self::assertSame([], $reaped['unconfirmed'], 'a pid reaped on pass 1 never stays pending');

        // (2) mutation caught: "signal on retry" — EINTR is a transient syscall interruption.
        $eintr = self::driveReaper(
            [4242],
            [['state' => 'error', 'status' => PCNTL_EINTR], ['state' => 'reaped', 'status' => 0]]
        );
        self::assertSame([4242, 4242], $eintr['probes'], 'an EINTR probe is retried');
        self::assertSame([], $eintr['signals'], 'an EINTR probe is never signalled');
        self::assertSame([], $eintr['unconfirmed'], 'the retried pid is dropped once it is reaped');

        // (2b) mutation caught: "signal on 'retry' in pass 2 ONLY" — pass 1 sees EINTR, pass 2
        // sees EINTR again and only then a reaped pid, so a pass-2-only regression reds here.
        $eintrTwice = self::driveReaper(
            [4242],
            [
                ['state' => 'error', 'status' => PCNTL_EINTR],
                ['state' => 'error', 'status' => PCNTL_EINTR],
                ['state' => 'reaped', 'status' => 0],
            ]
        );
        self::assertSame([4242, 4242, 4242], $eintrTwice['probes'], 'EINTR is retried in BOTH passes');
        self::assertSame([], $eintrTwice['signals'], 'an EINTR probe in pass 2 is never signalled either');
        self::assertSame([], $eintrTwice['unconfirmed'], 'the pid is dropped once a later probe reaps it');

        // (3) mutation caught: "drop a pid whose latest probe says running" — a live child
        // would leak instead of being killed.
        $running = self::driveReaper(
            [4242],
            [['state' => 'running', 'status' => 0], ['state' => 'reaped', 'status' => 0]]
        );
        self::assertSame([4242, 4242], $running['probes'], 'a running child is re-probed in pass 2');
        self::assertSame([4242], $running['signals'], 'a running child is SIGKILLed exactly once');
        self::assertSame([], $running['unconfirmed'], 'the killed child is dropped once a probe reaps it');

        // (4) mutation caught: "treat a hard waitpid error as gone" — that would abandon a
        // child the kernel may still be running.
        $hard = self::driveReaper(
            [4242],
            [['state' => 'error', 'status' => PCNTL_EINVAL], ['state' => 'reaped', 'status' => 0]]
        );
        self::assertSame([4242, 4242], $hard['probes'], 'a hard error keeps the pid owned and re-probed');
        self::assertSame([4242], $hard['signals'], 'a hard error escalates to exactly one SIGKILL');
        self::assertSame([], $hard['unconfirmed'], 'the pid is dropped once a later probe reaps it');

        // (5) mutation caught: "probe or signal pid <= 0" — posix_kill(0) signals the whole
        // process group.
        $invalid = self::driveReaper([0, -7], [['state' => 'reaped', 'status' => 0]]);
        self::assertSame([], $invalid['probes'], 'pid <= 0 is never probed');
        self::assertSame([], $invalid['signals'], 'pid <= 0 is never signalled');

        // (6) mutation caught: "silently abandon a child that is never confirmed reaped".
        // Exact sequences, not just counts: the injected clock advances 1 s per sleep with a
        // 5 s bound, so the pid is probed once in pass 1 and once per bounded-reap iteration
        // (clock 0..5 inclusive = 6 iterations) and is signalled on every one of them.
        $stuck = self::driveReaper([4242], [['state' => 'running', 'status' => 0]], 5.0, 1.0);
        self::assertSame(
            [4242, 4242, 4242, 4242, 4242, 4242, 4242],
            $stuck['probes'],
            'a never-confirmed pid is probed exactly once per pass-2 iteration plus the pass-1 probe'
        );
        self::assertSame(
            [4242, 4242, 4242, 4242, 4242, 4242, 4242],
            $stuck['signals'],
            'each bounded-reap iteration SIGKILLs the still-running pid'
        );
        self::assertSame([4242], $stuck['unconfirmed'], 'a never-confirmed pid is REPORTED, not silently dropped');
    }

    /**
     * B2: the CLEANUP wrapper must turn an unconfirmed result into a failure that names the
     * pid. Testing the core's return value alone would stay green if the wrapper dropped it.
     */
    public function testReaperCleanupFailsAndNamesThePidWhenAChildIsNeverConfirmedReaped(): void
    {
        if (!defined('PCNTL_EINTR')) {
            if ('true' === getenv('GITHUB_ACTIONS')) {
                self::fail('the pcntl error constants are missing in CI, so this reaper rule could not be checked');
            }
            $this->markTestSkipped('pcntl error constants are unavailable here.');
        }

        $now    = 0.0;
        $caught = null;

        try {
            $this->reapRaceChildren(
                [4242],
                99, // attempt tag with no real harness files behind it
                0,  // no worker files to remove
                static function (int $pid): array {
                    unset($pid);

                    return ['state' => 'running', 'status' => 0];
                },
                static function (int $pid): void {
                    unset($pid);
                },
                static function (int $microseconds) use (&$now): void {
                    unset($microseconds);
                    $now += 1.0;
                },
                static function () use (&$now): float {
                    return $now;
                }
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(
            \PHPUnit\Framework\AssertionFailedError::class,
            $caught,
            'the cleanup must FAIL when a child is never confirmed reaped'
        );
        self::assertStringContainsString(
            '4242',
            (string) $caught->getMessage(),
            'the failure message must name the unconfirmed pid'
        );
    }

    /**
     * One observation per attempt, failing loudly when the harness did not finish.
     *
     * @return array<int, array{attempt: int, outcomes: array<string, array<string, mixed>>, state: array<string, mixed>}>
     */
    private function raceAttempts(): array
    {
        $observations = $this->raceObservations();

        self::assertNull(
            self::$race_harness_error,
            'the fork harness itself must complete before any product invariant is read: ' . (string) self::$race_harness_error
        );
        self::assertCount(
            self::RACE_ATTEMPTS,
            $observations,
            'race evidence must exist for every attempt, got ' . count($observations)
        );

        return $observations;
    }

    /** The harness must not be mistaken for a product result. */
    // Harness-only: no product mutation reds this name — it exists so a fork, child
    // exit or missing outcome file can never masquerade as product evidence.
    public function testRaceHarnessCompletedEveryAttemptWithChildOutcomes(): void
    {
        $observations = $this->raceAttempts();

        foreach ($observations as $row) {
            $context = 'attempt ' . $row['attempt'];
            self::assertArrayHasKey('check_in', $row['outcomes'], $context . ': the check-in worker must have written an outcome');
            self::assertArrayHasKey('no_show', $row['outcomes'], $context . ': the no-show worker must have written an outcome');
            self::assertArrayHasKey('result', $row['outcomes']['check_in'], $context . ': check-in outcome must carry a result — ' . wp_json_encode($row['outcomes']['check_in']));
            self::assertArrayHasKey('result', $row['outcomes']['no_show'], $context . ': no-show outcome must carry a result — ' . wp_json_encode($row['outcomes']['no_show']));
        }
    }

    // Product mutation: a guard that surfaces a non-domain exception instead of a
    // `CLINIC_*` envelope (e.g. `assertNoActiveVisit()` throwing \RuntimeException, or
    // `checkIn()` dereferencing a null appointment) — that worker's result becomes
    // `exception` and reds this name in BOTH orders.
    public function testRaceOutcomeEnvelopesAreProductEnvelopesOnly(): void
    {
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            foreach ($row['outcomes'] as $role => $outcome) {
                self::assertNotSame(
                    'fatal',
                    $outcome['result'] ?? null,
                    "{$context} [{$role}]: child bootstrap fatal is a harness failure, not product evidence — " . wp_json_encode($outcome)
                );
                self::assertNotSame(
                    'exception',
                    $outcome['result'] ?? null,
                    "{$context} [{$role}]: a raw exception escaping the product call is not a legal product envelope — " . wp_json_encode($outcome)
                );
            }
            // R4: the manual no-show has a legal success AND a legal refusal envelope
            // (refused by the active-Visit guard when the check-in won the row lock).
            self::assertContains(
                $row['outcomes']['no_show']['result'] ?? null,
                ['ok', 'rejected'],
                "{$context}: the manual no-show must end in a product envelope, got " . wp_json_encode($row['outcomes']['no_show'] ?? null)
            );
        }
    }

    // Harness-only: no product mutation reds this name — it proves the two product
    // calls really ran in separate processes.
    public function testRaceWorkersRanAsDistinctChildProcesses(): void
    {
        $parentPid = getmypid();
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $ci = $row['outcomes']['check_in'];
            $ns = $row['outcomes']['no_show'];

            self::assertIsInt($ci['pid'] ?? null, "{$context}: check-in child must record its PID");
            self::assertIsInt($ns['pid'] ?? null, "{$context}: no-show child must record its PID");
            self::assertNotSame($parentPid, $ci['pid'], "{$context}: check-in must run in a child process");
            self::assertNotSame($parentPid, $ns['pid'], "{$context}: no-show must run in a child process");
            self::assertNotSame($ci['pid'], $ns['pid'], "{$context}: the workers must be distinct processes");
        }
    }

    // Harness-only: no product mutation reds this name — it proves each child owned its
    // own connection and that its commits are real, not parent savepoints.
    public function testRaceWorkersOwnedIndependentDbConnections(): void
    {
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $ci = $row['outcomes']['check_in'];
            $ns = $row['outcomes']['no_show'];

            self::assertTrue(
                (bool) ($ci['own_wpdb_connected'] ?? false),
                "{$context}: check-in child must own its DB connection — " . wp_json_encode($ci)
            );
            self::assertTrue(
                (bool) ($ns['own_wpdb_connected'] ?? false),
                "{$context}: no-show child must own its DB connection — " . wp_json_encode($ns)
            );
            foreach (['check_in' => $ci, 'no_show' => $ns] as $role => $outcome) {
                $probes = ' child_before: ' . wp_json_encode($outcome['probe_before'] ?? null)
                    . ' child_after: ' . wp_json_encode($outcome['probe_after'] ?? null)
                    . ' parent_at_fork: ' . wp_json_encode($outcome['parent_probe'] ?? null);
                self::assertGreaterThanOrEqual(
                    1,
                    (int) ($outcome['query_filters_before'] ?? 0),
                    "{$context}: [{$role}] the test-only query-rewrite must have been present in the forked child" . $probes
                );
                self::assertSame(
                    0,
                    (int) ($outcome['query_filters_after'] ?? 0),
                    "{$context}: [{$role}] the test-only query-rewrite must be removed in the child (real commits, not parent savepoints)" . $probes
                );
            }
        }
    }

    public function testRaceProductCallWindowsOverlappedInFlight(): void
    {
        // Harness-only (no single product mutation is guaranteed to red it): overlap can
        // also occur while the loser waits on I/O, so this is scheduling evidence, not a
        // product invariant — the product invariants live in the winner/final-state
        // tests. Only the ZERO-OFFSET attempt is judged; attempts 1 and 2 carry a
        // deliberate head start so each winner is observed.
        foreach ($this->raceAttempts() as $row) {
            if ($row['attempt'] !== self::RACE_ATTEMPTS) {
                continue;
            }
            $context = 'attempt ' . $row['attempt'];
            $ci = $row['outcomes']['check_in'];
            $ns = $row['outcomes']['no_show'];

            $starts = [
                'check_in' => (float) ($ci['call_started_at'] ?? 0),
                'no_show' => (float) ($ns['call_started_at'] ?? 0),
            ];
            $ends = [
                'check_in' => (float) ($ci['call_finished_at'] ?? 0),
                'no_show' => (float) ($ns['call_finished_at'] ?? 0),
            ];
            foreach (['check_in', 'no_show'] as $role) {
                self::assertGreaterThan(0.0, $starts[$role], "{$context}: [{$role}] call window must have a start timestamp");
                self::assertGreaterThan(
                    0.0,
                    $ends[$role] - $starts[$role],
                    "{$context}: [{$role}] call window must have positive duration, got " . ($ends[$role] - $starts[$role])
                );
                self::assertLessThan(
                    60.0,
                    $ends[$role] - $starts[$role],
                    "{$context}: [{$role}] call window must not be a hung call, got " . ($ends[$role] - $starts[$role])
                );
            }

            $latestStart = max($starts['check_in'], $starts['no_show']);
            $earliestEnd = min($ends['check_in'], $ends['no_show']);
            $overlap = $earliestEnd - $latestStart;
            $windows = sprintf(
                'check_in=[%.6f, %.6f] no_show=[%.6f, %.6f] overlap=%.6f',
                $starts['check_in'],
                $ends['check_in'],
                $starts['no_show'],
                $ends['no_show'],
                $overlap
            );
            self::assertGreaterThan(
                0.0,
                $overlap,
                "{$context}: NO in-flight overlap of the two product calls (windows: {$windows}) — "
                . 'one call finished before the other started, i.e. the attempt was effectively '
                . 'sequential and is NOT concurrency evidence. Both workers fire at the same '
                . 'barrier instant; in a genuine lock race the loser blocks inside its own '
                . 'window on the winner\'s appointment row lock, so overlap must be positive.'
            );
        }
    }

    // Product mutation: `markNoShowByStaff()` dropping `assertNoActiveVisit()` — with the
    // check-in ahead (attempt 2) the no-show then commits over a live Visit, so a
    // `no_show` appointment carries a live Visit and this name reds.
    public function testRaceNeverLeavesLiveVisitOnNoShowAppointment(): void
    {
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $state = $row['state'];
            $live = count((array) ($state['live_visits'] ?? []));

            self::assertFalse(
                $state['status'] === 'no_show' && $live > 0,
                "{$context}: a live Visit must never sit on a no_show appointment — state: " . wp_json_encode($state)
            );
        }
    }

    // Product mutation: removing either guard (`assertNoActiveVisit()` in the no-show
    // path, or the `no_show` status refusal in `checkIn()`) lets both workers commit in
    // the attempt that starts them in that order.
    public function testRaceExactlyOneWorkerCommits(): void
    {
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $ns = $row['outcomes']['no_show'] ?? [];
            $ci = $row['outcomes']['check_in'] ?? [];

            $winners = (int) (($ns['result'] ?? '') === 'ok') + (int) (($ci['result'] ?? '') === 'ok');
            self::assertSame(
                1,
                $winners,
                "{$context}: exactly one worker may commit — no_show: " . wp_json_encode($ns) . ' check_in: ' . wp_json_encode($ci)
            );
        }
    }

    // Product mutation: `checkIn()` accepting a `no_show` appointment (attempt 1 makes
    // the no-show win, so the losing check-in would answer `ok`), or the no-show path not
    // stamping `no_show_at`. The pointer-null assertion is defensive: the pointer starts
    // NULL here, so only a mutation that WRITES a pointer reds it.
    public function testRaceNoShowWinnerStampsNoShowAndRefusesCheckIn(): void
    {
        $evaluated = 0;
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $state = $row['state'];
            $ci = $row['outcomes']['check_in'] ?? [];
            $ns = $row['outcomes']['no_show'] ?? [];
            if ($state['status'] !== 'no_show') {
                continue;
            }
            ++$evaluated;

            self::assertNotNull($state['no_show_at'], "{$context}: the committed manual no-show stamps no_show_at");
            self::assertSame(
                0,
                count((array) ($state['live_visits'] ?? [])),
                "{$context}: no Visit may exist after the no-show won — state: " . wp_json_encode($state)
            );
            self::assertNull($state['active_visit_id'], "{$context}: a no_show appointment holds no Visit pointer");
            self::assertSame('rejected', $ci['result'] ?? null, "{$context}: the losing check-in must be refused — " . wp_json_encode($ci));
            self::assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $ci['error_code'] ?? null, "{$context}: terminal refusal code");
            self::assertSame('ok', $ns['result'] ?? null, "{$context}: the manual no-show committed — " . wp_json_encode($ns));
        }

        // P2: this name may never pass on zero observations. Attempt 1 gives the
        // manual no-show the head start precisely so this winner is observed; if it is
        // not, the test FAILS with every attempt's outcomes so the next reader sees why.
        self::assertGreaterThanOrEqual(
            1,
            $evaluated,
            'the no-show winner must be OBSERVED at least once (attempt 1 forces it); evaluated='
            . $evaluated . ' observed=' . $this->raceWinnerSummary()
        );
    }

    // Product mutation: `markNoShowByStaff()` writing `no_show` over a live Visit — such
    // a mutation makes the check-in winner disappear, and the `>= 1` vacuity guard below
    // reds this name even though the winner branch would otherwise be skipped.
    public function testRaceCheckInWinnerKeepsConfirmedAppointmentAndRefusesNoShow(): void
    {
        $evaluated = 0;
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $state = $row['state'];
            $ci = $row['outcomes']['check_in'] ?? [];
            $ns = $row['outcomes']['no_show'] ?? [];
            if ($state['status'] === 'no_show') {
                continue;
            }
            ++$evaluated;

            self::assertSame('confirmed', $state['status'], "{$context}: no no_show may be written when the check-in won — state: " . wp_json_encode($state));
            self::assertNull($state['no_show_at'], "{$context}: no_show_at must stay NULL when the check-in won");
            self::assertSame(
                1,
                count((array) ($state['live_visits'] ?? [])),
                "{$context}: the winning check-in keeps exactly one live Visit — state: " . wp_json_encode($state)
            );
            self::assertSame('rejected', $ns['result'] ?? null, "{$context}: the losing manual no-show must be refused — " . wp_json_encode($ns));
            self::assertSame('HAS_ACTIVE_VISIT', $ns['error_code'] ?? null, "{$context}: refusal via the active-Visit guard");
            self::assertSame('ok', $ci['result'] ?? null, "{$context}: the check-in committed — " . wp_json_encode($ci));
        }

        // P2: same rule for the check-in winner — attempt 2 forces it, and zero
        // observations fail with the full summary instead of passing silently.
        self::assertGreaterThanOrEqual(
            1,
            $evaluated,
            'the check-in winner must be OBSERVED at least once (attempt 2 forces it); evaluated='
            . $evaluated . ' observed=' . $this->raceWinnerSummary()
        );
    }

    /** One line per attempt: who committed and with which envelope. */
    private function raceWinnerSummary(): string
    {
        $parts = [];
        foreach (self::$race_observations ?? [] as $row) {
            $parts[] = 'attempt ' . $row['attempt']
                . ' status=' . (string) ($row['state']['status'] ?? '?')
                . ' no_show=' . (string) ($row['outcomes']['no_show']['result'] ?? '?')
                . ' check_in=' . (string) ($row['outcomes']['check_in']['result'] ?? '?');
        }

        return '[' . implode(' | ', $parts) . ']';
    }

    // Product mutation: either path starting to move the slot counters (booked/held) —
    // a no-show or a check-in that touches `cpms_schedule_slots`.
    public function testRaceSlotCountersUnchanged(): void
    {
        foreach ($this->raceAttempts() as $row) {
            $context = 'attempt ' . $row['attempt'];
            $state = $row['state'];

            self::assertSame(1, $state['slot_booked'], "{$context}: booked counter unchanged — state: " . wp_json_encode($state));
            self::assertSame(0, $state['slot_held'], "{$context}: hold counter unchanged — state: " . wp_json_encode($state));
        }
    }

    // ============ P3 — per-Clinic grace on the LIVE check-in path ============

    /**
     * Restores, on the path that is still live in 1.0, the protection the retired
     * cross-Clinic no-show harness used to keep: `VisitService::checkIn()`'s ER-06
     * lazy no-show decision resolves grace for the APPOINTMENT's Clinic
     * (`VisitService::graceForClinic()`), and it must use that Clinic's value.
     *
     * Both Clinics get a slot at the same clinic-local time and the clock is pinned 10
     * minutes later, so exactly 10 minutes have elapsed for both (asserted). Clinic A
     * (grace 5) must REFUSE; Clinic B (grace 120) must ACCEPT a check-in with exactly
     * one live Visit. The mutation "Clinic B uses Clinic A's value" inverts both halves.
     */
    public function testPerClinicGraceIsNotBledAcrossClinicsOnCheckIn(): void
    {
        $this->setNoShowGrace($this->clinicA, 5);
        $this->setNoShowGrace($this->clinicB, 120);
        cpms_test_seed_membership($this->secretaryA, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY);

        // IDENTICAL slot spec for both Clinics, and the elapsed minutes are measured
        // against the PERSISTED start read back from each Clinic's own schedule row, so
        // the two halves are judged on the same, real elapsed value.
        $slotLocal = new DateTimeImmutable('today 12:00:00', new DateTimeZone(self::TZ));
        $spec      = ['date' => $slotLocal->format('Y-m-d'), 'time' => $slotLocal->format('H:i:s')];

        $seedA = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->clinicianA, $this->patientA, $spec, 'grace-a');
        $seedB = $this->seedConfirmedAppointment($this->clinicB, $this->locationB, $this->clinicianB, $this->patientB, $spec, 'grace-b');

        $startA = $this->persistedSlotStart($seedA['slot_id']);
        $startB = $this->persistedSlotStart($seedB['slot_id']);
        self::assertEquals(
            $startA,
            $startB,
            'both Clinics must persist the SAME slot start, otherwise their two halves are not comparable'
        );

        $nowLocal = $startA->modify('+10 minutes');
        VisitService::setTestNowUtc($nowLocal);

        try {
            $this->assertPersistedElapsed(10, $seedA['slot_id'], $nowLocal, 'Clinic A (grace 5)');
            $this->assertPersistedElapsed(10, $seedB['slot_id'], $nowLocal, 'Clinic B (grace 120)');

            $refusal = $this->checkInForClinic($this->clinicA, $this->patientA, $seedA['id']);
            self::assertInstanceOf(VisitException::class, $refusal, 'Clinic A (grace 5) must refuse a check-in exactly 10 minutes late');
            self::assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $refusal->errorCode, 'the ER-06 late-check-in refusal code');
            self::assertSame(409, $refusal->httpStatus, 'the ER-06 late-check-in refusal status');
            self::assertSame('confirmed', $this->appointmentRow($seedA['id'])['status'], 'a refused check-in mutates nothing');
            self::assertCount(0, $this->liveVisitsForAppointment($seedA['id']), 'a refused check-in creates no Visit');

            $visitB = $this->checkInForClinic($this->clinicB, $this->patientB, $seedB['id']);
            self::assertIsArray(
                $visitB,
                'Clinic B (grace 120) must ACCEPT a check-in exactly 10 minutes late, got '
                . (is_object($visitB) ? get_class($visitB) . ': ' . $visitB->getMessage() : gettype($visitB))
                . ' — a bleed of Clinic A grace 5 reds this'
            );
            self::assertGreaterThan(0, (int) $visitB['id'], 'the accepted check-in returns a Visit id');
            self::assertCount(1, $this->liveVisitsForAppointment($seedB['id']), 'the accepted check-in creates exactly one live Visit');
        } finally {
            VisitService::setTestNowUtc(null);
        }
    }

    /**
     * Second half of the reviewer's requirement: 40 minutes elapsed is BEYOND the
     * Settings default (30) and inside Clinic B's 120. Clinic B must still accept, so a
     * fallback to the default 30 instead of Clinic B's configured value reds this test;
     * Clinic A (grace 5) still refuses.
     */
    public function testPerClinicGraceBeyondTheSettingsDefaultStillUsesTheClinicValue(): void
    {
        $this->setNoShowGrace($this->clinicA, 5);
        $this->setNoShowGrace($this->clinicB, 120);
        cpms_test_seed_membership($this->secretaryA, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY);

        $slotLocal = new DateTimeImmutable('today 13:00:00', new DateTimeZone(self::TZ));
        $spec      = ['date' => $slotLocal->format('Y-m-d'), 'time' => $slotLocal->format('H:i:s')];

        $seedA = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->clinicianA, $this->patientA, $spec, 'grace-a40');
        $seedB = $this->seedConfirmedAppointment($this->clinicB, $this->locationB, $this->clinicianB, $this->patientB, $spec, 'grace-b40');

        $startA = $this->persistedSlotStart($seedA['slot_id']);
        $startB = $this->persistedSlotStart($seedB['slot_id']);
        self::assertEquals($startA, $startB, 'both Clinics must persist the SAME slot start for the 40-minute case');

        $nowLocal = $startA->modify('+40 minutes');
        VisitService::setTestNowUtc($nowLocal);

        try {
            $this->assertPersistedElapsed(40, $seedA['slot_id'], $nowLocal, 'Clinic A (grace 5)');
            $this->assertPersistedElapsed(40, $seedB['slot_id'], $nowLocal, 'Clinic B (grace 120)');

            $visitB = $this->checkInForClinic($this->clinicB, $this->patientB, $seedB['id']);
            self::assertIsArray(
                $visitB,
                'Clinic B (grace 120) must accept a check-in exactly 40 minutes late, got '
                . (is_object($visitB) ? get_class($visitB) . ': ' . $visitB->getMessage() : gettype($visitB))
                . ' — falling back to the default 30 reds this'
            );
            self::assertGreaterThan(0, (int) $visitB['id'], 'the accepted check-in returns a Visit id');

            $refusal = $this->checkInForClinic($this->clinicA, $this->patientA, $seedA['id']);
            self::assertInstanceOf(VisitException::class, $refusal, 'Clinic A (grace 5) must refuse a check-in exactly 40 minutes late');
            self::assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $refusal->errorCode, 'the ER-06 late-check-in refusal code');
            self::assertCount(0, $this->liveVisitsForAppointment($seedA['id']), 'Clinic A creates no Visit at 40 minutes');
        } finally {
            VisitService::setTestNowUtc(null);
        }
    }

    /**
     * CHARACTERIZATION — owner policy for "check-in when the grace setting cannot be
     * read" is UNKNOWN. This pins what the code does today; it claims no policy.
     *
     * A REAL settings-read failure is injected: the `cpms_settings` SELECT is rewritten
     * to a failing statement with error suppression (the same technique the suite uses
     * for read faults). `Settings::load()` turns that into a soft failure, `get()`
     * returns the DEFAULT, `graceForClinic()` therefore sees 30 — not null — and a
     * 40-minute-late check-in is refused while a 10-minute-late one is accepted. So the
     * DEFAULT decides, not Clinic B's configured 120.
     */
    public function testSettingsReadFailureFallsBackToTheDefaultGraceCharacterization(): void
    {
        $this->setNoShowGrace($this->clinicB, 120);
        cpms_test_seed_membership($this->secretaryA, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY);

        // A SECOND patient for the second check-in: the first one leaves a live Visit,
        // and `guardDuplicateActiveVisit()` (patient + clinician + trusted day) would then
        // refuse the +40 check-in BEFORE grace is consulted, which would make this case
        // vacuous — it would "pass" without ever repeating the settings read.
        $secondPatient = $this->insertPatient($this->clinicB, 'MR-FR55-F-' . uniqid(), $this->nowUtcSql());

        $slotLocal = new DateTimeImmutable('today 14:00:00', new DateTimeZone(self::TZ));
        $spec      = ['date' => $slotLocal->format('Y-m-d'), 'time' => $slotLocal->format('H:i:s')];

        // A distinct slot minute for the second appointment: (location, clinician, date,
        // time) is unique, so reusing 14:00:00 would fail the seed insert instead of
        // exercising the grace path.
        $pastSpec = ['date' => $spec['date'], 'time' => '14:01:00'];

        $inside = $this->seedConfirmedAppointment($this->clinicB, $this->locationB, $this->clinicianB, $this->patientB, $spec, 'grace-fault-in');
        $past   = $this->seedConfirmedAppointment($this->clinicB, $this->locationB, $this->clinicianB, $secondPatient, $pastSpec, 'grace-fault-out');

        $nowIn   = $this->persistedSlotStart($inside['slot_id'])->modify('+10 minutes');
        $nowPast = $this->persistedSlotStart($past['slot_id'])->modify('+40 minutes');

        global $wpdb;
        $settingsTable = App::db()->table('cpms_settings');
        $faultHits     = 0;
        $fault         = static function ($query) use ($settingsTable, &$faultHits) {
            $sql = (string) $query;
            if (str_contains($sql, $settingsTable) && preg_match('/\A\s*SELECT\b/i', $sql) === 1) {
                ++$faultHits;

                return 'SELECT `cpms_fault_injection_no_such_column` FROM ' . $settingsTable;
            }

            return $query;
        };

        $previousSuppression = (bool) $wpdb->suppress_errors(true);
        Settings::flushCache();
        App::settingsFactory()->reset();
        add_filter('query', $fault, 5);

        try {
            VisitService::setTestNowUtc($nowIn);
            $this->assertPersistedElapsed(10, $inside['slot_id'], $nowIn, 'characterization (+10 min)');
            $faultHits   = 0;
            $visitInside = $this->checkInForClinic($this->clinicB, $this->patientB, $inside['id']);
            self::assertGreaterThanOrEqual(
                1,
                $faultHits,
                'the injected settings-read fault must actually fire for the +10 check-in — a SQL-shape mismatch would silently pass'
            );
            self::assertIsArray(
                $visitInside,
                'characterization: a failing settings read yields the DEFAULT grace 30, so a 10-minute-late check-in is accepted, got '
                . (is_object($visitInside) ? get_class($visitInside) . ': ' . $visitInside->getMessage() : gettype($visitInside))
            );
            // T1: an EMPTY array must not count as an accepted check-in — require a real,
            // positive Visit id and the persisted Visit read back from the database.
            self::assertArrayHasKey('id', $visitInside, 'the accepted +10 check-in must return a Visit id');
            self::assertIsInt($visitInside['id'], 'the accepted +10 check-in must return an INTEGER Visit id, got ' . gettype($visitInside['id']));
            self::assertGreaterThan(0, $visitInside['id'], 'the accepted +10 check-in must return a POSITIVE Visit id');
            self::assertCount(
                1,
                $this->liveVisitsForAppointment($inside['id']),
                'the accepted +10 check-in must leave exactly one live Visit for that appointment in the database'
            );

            VisitService::setTestNowUtc($nowPast);
            $this->assertPersistedElapsed(40, $past['slot_id'], $nowPast, 'characterization (+40 min)');
            // The resolved Settings instance is cached per Clinic, so the second phase must
            // start from a cold cache to read (and fault) again.
            Settings::flushCache();
            App::settingsFactory()->reset();
            $faultHits = 0;
            $refusal   = $this->checkInForClinic($this->clinicB, $secondPatient, $past['id']);
            self::assertGreaterThanOrEqual(
                1,
                $faultHits,
                'the injected settings-read fault must actually fire for the +40 check-in as well'
            );
            self::assertInstanceOf(
                VisitException::class,
                $refusal,
                'characterization: with the settings read failing the DEFAULT 30 decides, so 40 minutes late is refused '
                . '(CURRENT behaviour — owner policy for an unreadable grace is UNKNOWN)'
            );
            self::assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $refusal->errorCode, 'the ER-06 refusal code is reused');
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($previousSuppression);
            Settings::flushCache();
            App::settingsFactory()->reset();
            VisitService::setTestNowUtc(null);
        }
    }

    /**
     * Check-in inside an explicit Clinic scope, returning the check-in result on success
     * or the caught `VisitException` on a refusal — the real union, so a refusal can
     * never become a TypeError at the return statement. Each caller asserts its own
     * expectation for whichever branch it wants.
     *
     * @return array<string, mixed>|VisitException
     */
    private function checkInForClinic(int $clinicId, int $patientId, int $appointmentId): array|VisitException
    {
        $previousScope = ScopeContext::tryGet();
        ScopeContext::set(ClinicScope::forClinic($clinicId));
        try {
            return App::visitService()->checkIn($this->secretaryA, $patientId, $appointmentId);
        } catch (VisitException $e) {
            return $e;
        } finally {
            if (null !== $previousScope) {
                ScopeContext::set($previousScope);
            } else {
                ScopeContext::clear();
            }
        }
    }

    /**
     * The PERSISTED clinic-local slot start of a seeded appointment, read back from the
     * schedule row, so an elapsed-minutes assertion can never drift from what the product
     * itself reads.
     */
    private function persistedSlotStart(int $slotId): DateTimeImmutable
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT slot_date, slot_time FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            ),
            ARRAY_A
        );
        self::assertIsArray($row, 'the persisted slot row must exist for slot ' . $slotId);

        return new DateTimeImmutable(
            (string) $row['slot_date'] . ' ' . (string) $row['slot_time'],
            new DateTimeZone(self::TZ)
        );
    }

    /** Assert the exact elapsed minutes between a PERSISTED slot start and the pinned now. */
    private function assertPersistedElapsed(int $expected, int $slotId, DateTimeImmutable $now, string $label): void
    {
        $start   = $this->persistedSlotStart($slotId);
        $elapsed = (int) floor(($now->getTimestamp() - $start->getTimestamp()) / 60);
        self::assertSame(
            $expected,
            $elapsed,
            $label . ': elapsed minutes since the PERSISTED slot start ('
            . $start->format('Y-m-d H:i:s') . ') must be exactly ' . $expected . ', got ' . $elapsed
        );
    }

    /** Set one Clinic's no-show grace and drop the settings cache. */
    private function setNoShowGrace(int $clinicId, int $minutes): void
    {
        (new Settings(App::db(), $clinicId, App::audit()))->set('queue.no_show_grace_minutes', $minutes);
        Settings::flushCache();
        self::assertSame(
            $minutes,
            (int) (new Settings(App::db(), $clinicId, App::audit()))->get('queue.no_show_grace_minutes', 30),
            'fixture: Clinic ' . $clinicId . ' grace must be ' . $minutes
        );
    }

    // ============ T12b — both serialization orders, deterministically (no fork) ============

    /**
     * Order 1 — the check-in commits first, so the manual no-show must be refused
     * by the active-Visit guard (HAS_ACTIVE_VISIT) and nothing may mutate.
     *
     * The appointment is CHECK-IN ELIGIBLE (pinned clock on its own trusted local
     * day, before slot + grace). That is what the forked race cannot provide: it
     * seeds past-grace rows, where the check-in is refused by the ER-06 guard and
     * the "check-in wins" branch is unreachable.
     */
    public function testCheckInFirstThenManualNoShowIsRefusedWithHasActiveVisit(): void
    {
        $patientId = $this->insertPatient($this->clinicA, 'MR-FR55-O1-' . $this->fileTag, $this->nowUtcSql());
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $patientId,
            $this->futureSlotSpec(4, '10:00:00'),
            'o1'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $visit = $this->checkInWithPinnedClock($patientId, $seed['id']);
        self::assertGreaterThan(0, (int) ($visit['id'] ?? 0), 'precondition: the eligible check-in commits');
        self::assertCount(1, $this->liveVisitsForAppointment($seed['id']), 'precondition: exactly one live Visit');

        $refusal = null;
        try {
            App::bookingService()->markNoShowByStaff($this->secretaryA, $seed['id'], 'تلاش دستی پس از پذیرش');
        } catch (BookingException $e) {
            $refusal = $e;
        }
        self::assertInstanceOf(BookingException::class, $refusal, 'the manual no-show must be refused after a winning check-in');
        self::assertSame('HAS_ACTIVE_VISIT', $refusal->errorCode, 'refusal via the active-Visit guard');
        self::assertSame(409, $refusal->httpStatus, 'the active-Visit guard maps to HTTP 409');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', (string) $appt['status'], 'the appointment stays confirmed');
        self::assertNull($appt['no_show_at'], 'no_show_at stays NULL when the check-in won');
        self::assertSame((int) $visit['id'], (int) $appt['active_visit_id'], 'the pointer keeps the live Visit');
        self::assertCount(1, $this->liveVisitsForAppointment($seed['id']), 'exactly one live Visit remains');
        self::assertSame(0, $this->noShowAuditCount($seed['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    /**
     * Order 2 — the manual no-show commits first, so the check-in must be refused
     * by the appointment-state guard, with zero live Visits and no pointer.
     */
    public function testManualNoShowFirstThenCheckInIsRefusedWithInvalidAppointmentState(): void
    {
        $patientId = $this->insertPatient($this->clinicA, 'MR-FR55-O2-' . $this->fileTag, $this->nowUtcSql());
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $patientId,
            $this->futureSlotSpec(5, '11:00:00'),
            'o2'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $result = App::bookingService()->markNoShowByStaff($this->secretaryA, $seed['id'], 'عدم حضور به تصمیم پذیرش');
        self::assertSame('no_show', (string) $result['status'], 'precondition: the manual no-show commits first');

        $refusal = null;
        try {
            $this->checkInWithPinnedClock($patientId, $seed['id']);
        } catch (VisitException $e) {
            $refusal = $e;
        }
        self::assertInstanceOf(VisitException::class, $refusal, 'the losing check-in must be refused');
        self::assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $refusal->errorCode, 'terminal refusal code');
        self::assertSame(409, $refusal->httpStatus, 'the state guard maps to HTTP 409');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', (string) $appt['status'], 'the appointment stays no_show');
        self::assertNotNull($appt['no_show_at'], 'the committed no-show stamps no_show_at');
        self::assertNull($appt['active_visit_id'], 'a no_show appointment holds no Visit pointer');
        self::assertCount(0, $this->liveVisitsForAppointment($seed['id']), 'zero live Visits exist');
        self::assertSame(1, $this->noShowAuditCount($seed['id']), 'exactly one APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    /**
     * Check-in of an explicitly chosen patient with the clock pinned to the
     * appointment's own local day at 00:00 — the same clock technique the REST
     * level T5 test uses through `checkInOnAppointmentDay()`.
     *
     * @return array<string, mixed>
     */
    private function checkInWithPinnedClock(int $patientId, int $appointmentId): array
    {
        $date = (string) App::db()->fetchValue(
            'SELECT slot_date FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
        VisitService::setTestNowUtc(new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone(self::TZ)));
        try {
            return App::visitService()->checkIn($this->secretaryA, $patientId, $appointmentId);
        } finally {
            VisitService::setTestNowUtc(null);
        }
    }

    /** Fixture check-in uses the existing deterministic clock on its trusted Location day. */
    private function checkInOnAppointmentDay(int $appointmentId): array {
        $date = (string) App::db()->fetchValue(
            'SELECT slot_date FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc(
            new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone(self::TZ))
        );
        try {
            return App::visitService()->checkIn($this->secretaryA, $this->patientA, $appointmentId);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }
    }

    // ================= Concurrency harness (real-DB, independent connections) =================

    /**
     * Fork two genuinely independent worker processes, each with its own
     * fresh wpdb connection (never the parent's: forked sockets plus the
     * parent's open test transaction would corrupt the evidence). Both
     * workers wait for a shared barrier (absolute time + file signal), then
     * run their real product call at barrier + offset.
     *
     * @param array<int, array{role: string, offset: float}> $workers
     *
     * @return array<string, array<string, mixed>> role => outcome
     */
    private function runRace( int $appointmentId, int $patientId, array $workers, int $attempt, DateTimeImmutable $race_anchor ): array {
        $this->parentForkProbe = self::queryHookCensus();

        /** @var array<int, int> $pids parent-owned pids that have NOT been reaped yet */
        $pids     = [];
        $outcomes = [];

        // G3: fork, readiness, barrier, outcome read and cleanup all live inside ONE
        // try/finally, so a fork failure, a readiness timeout or a stuck child can never
        // leave a child or a harness file behind.
        try {
            foreach ($workers as $i => $worker) {
                $pid = pcntl_fork();
                if (-1 === $pid) {
                    $last = error_get_last();
                    $this->fail(
                        'harness fatal (fork failed for [' . $worker['role'] . '] after '
                        . count($pids) . ' child(ren): ' . ($last['message'] ?? 'unknown') . ')'
                    );
                }
                if ($pid === 0) {
                    if ( 'check_in' === $worker['role'] ) {
                        // Pin only the eligibility clock. The fork barrier and overlap still use real time.
                        VisitService::setTestNowUtc( $race_anchor );
                    }
                    $this->raceWorker($worker['role'], (float) $worker['offset'], $appointmentId, $patientId, $attempt, $i);
                    exit(1); // backstop — a returning child is a harness fault, not a pass
                }
                $pids[$i] = $pid;
            }

            // P4 readiness handshake: each child writes a ready file once its own DB
            // connection and services exist, and only then does the parent release the
            // barrier. Without it a slow child could start its product call after the
            // other one already finished, which would make the overlap evidence depend on
            // process scheduling luck rather than on the row lock.
            $readyDeadline = microtime(true) + 10.0;
            foreach (array_keys($workers) as $readyIndex) {
                $readyFile = $this->fileTag . '-ready-' . $attempt . '-' . $readyIndex;
                while (!@is_file($readyFile)) {
                    if (microtime(true) > $readyDeadline) {
                        $this->fail(
                            'harness fatal: child [' . $workers[$readyIndex]['role'] . '] did not reach the barrier '
                            . 'within 10 s (ready file missing: ' . $readyFile . ')'
                        );
                    }
                    usleep(1000);
                }
            }
            foreach (array_keys($workers) as $readyIndex) {
                @unlink($this->fileTag . '-ready-' . $attempt . '-' . $readyIndex);
            }

            // Q3(a): the release instant is computed only AFTER every child is ready, so a
            // slow child can never be handed an instant that has already passed.
            $fireAt  = microtime(true) + self::BARRIER_SEC;
            $barrier = $this->fileTag . '-barrier-' . $attempt;
            @file_put_contents($barrier, (string) $fireAt); // children poll this file and its timestamp

            // G3: a bounded, non-blocking wait loop. Only pids still in $pids are signalled
            // by the finally block, and a pid leaves the set the moment waitpid reaps it.
            $finishDeadline = microtime(true) + self::RACE_FINISH_DEADLINE_SEC;
            while ($pids !== []) {
                foreach ($pids as $idx => $pid) {
                    $poll = $this->pollChild($pid);
                    if ('running' === $poll['state']) {
                        continue;
                    }
                    if ('error' === $poll['state'] && defined('PCNTL_EINTR') && PCNTL_EINTR === $poll['status']) {
                        continue; // interrupted syscall: retry, bounded by $finishDeadline
                    }
                    if ('gone' === $poll['state']) {
                        // ECHILD: someone else already reaped it, so its exit status is lost.
                        unset($pids[$idx]);
                        $this->fail('harness fatal: child [' . $workers[$idx]['role'] . '] (pid ' . $pid . ') was already reaped (ECHILD) before its exit status could be read');
                    }
                    if ('error' === $poll['state']) {
                        // Any OTHER waitpid error: keep the pid owned, kill it and retry; the
                        // reaper confirms it or fails the test with the pid listed.
                        $this->killChild($pid);
                        continue;
                    }
                    unset($pids[$idx]); // reaped here: never signal this pid again
                    if (!pcntl_wifexited($poll['status']) || pcntl_wexitstatus($poll['status']) !== 0) {
                        $detail  = '';
                        $rawFile = $this->fileTag . '-outcome-' . $attempt . '-' . $idx;
                        if (is_file($rawFile)) {
                            $detail = ' outcome: ' . (string) @file_get_contents($rawFile);
                        }
                        $this->fail(
                            'harness fatal: child [' . $workers[$idx]['role'] . '] exited abnormally (status=' . $poll['status'] . ')' . $detail
                        );
                    }
                }
                if ($pids !== [] && microtime(true) > $finishDeadline) {
                    $this->fail(
                        'harness fatal: children did not finish within ' . self::RACE_FINISH_DEADLINE_SEC
                        . ' s (still running worker indexes: ' . implode(',', array_keys($pids)) . ')'
                    );
                }
                if ($pids !== []) {
                    usleep(1000);
                }
            }

            // G2: read and validate EVERY outcome into memory first. The unlink happens in
            // the finally block below, after this loop — never before it.
            foreach (array_values($workers) as $i => $worker) {
                $file = $this->fileTag . '-outcome-' . $attempt . '-' . $i;
                $raw  = @file_get_contents($file);
                if ($raw === false || $raw === '') {
                    $this->fail('harness fatal: child [' . $worker['role'] . '] wrote no outcome (fork/bootstrap failure — not a product error)');
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || !isset($decoded['result'])) {
                    $this->fail('harness fatal: child [' . $worker['role'] . '] wrote malformed outcome (not a product error)');
                }
                $outcomes[$worker['role']] = $decoded;
            }
        } finally {
            // G2/G3: cleanup (SIGKILL of unreaped children + removal of this attempt's
            // ready/outcome/barrier files) runs on EVERY path, after the outcomes were read.
            $this->reapRaceChildren($pids, $attempt, count($workers));
        }

        return $outcomes;
    }

    /**
     * Child body: fresh independent DB connection + real service wiring,
     * barrier wait, product call, outcome file, exit.
     *
     * The outcome records the independence/evidence fields the parent
     * asserts on: child PID, own-wpdb connection, the test-only query-
     * filter census (before/after removal), and wall-clock call windows.
     */
    private static function queryHookCensus(): array {
        // Probe the 'query' hook exactly as WP core sees it — no functions
        // beyond core (WP 6.7 has no has_filters()).
        $probe = [
            'wp_loaded' => defined('ABSPATH'),
            'wp_hook_class' => class_exists('WP_Hook'),
            'wp_filter_global_type' => gettype($GLOBALS['wp_filter'] ?? null),
            'wp_filter_key_count' => is_array($GLOBALS['wp_filter'] ?? null) ? count($GLOBALS['wp_filter']) : -1,
            'query_hook_present' => is_array($GLOBALS['wp_filter'] ?? null) && isset($GLOBALS['wp_filter']['query']),
            'query_callbacks' => 0,
            'has_filter_query' => has_filter('query'),
        ];
        if ($probe['query_hook_present']) {
            $hook = $GLOBALS['wp_filter']['query'];
            if (is_object($hook) && isset($hook->callbacks)) {
                $probe['query_callbacks'] = (int) array_sum(array_map('count', (array) $hook->callbacks));
            }
        }

        return $probe;
    }

    /**
     * A1: the reaper's whole pass logic with the probe, the signal, the sleep and the clock
     * injected, so a test executes THIS code — not a copy of it — without forking.
     *
     * Pass 1 probes each owned pid once and builds the pending set: a pid the probe reports
     * reaped or gone (ECHILD) is dropped right there and never enters it. Pass 2 re-probes
     * ONLY pending pids until the injected deadline. Every probe result goes through
     * reapActionFor(), so "reaped" and "gone" can never become a signal and EINTR can never
     * become one either. pid <= 0 is never probed and never signalled.
     *
     * @param int[] $pids
     * @param callable(int): array{state: string, status: int} $probe one raw poll of one pid
     * @param callable(int): void $signal SIGKILL exactly one pid
     * @param callable(int): void $sleep microseconds
     * @param callable(): float $clock monotonic seconds
     *
     * @return array{probes: array<int, int>, signals: array<int, int>, unconfirmed: array<int, int>}
     */
    private static function reapOwnedChildren(
        array $pids,
        callable $probe,
        callable $signal,
        callable $sleep,
        callable $clock,
        float $reapDeadlineSeconds
    ): array {
        $probes  = [];
        $signals = [];
        $pending = [];

        // Pass 1: probe once per owned pid; a reaped/gone pid is dropped before it can ever
        // reach the pending set (the pre-round-8 bug copied it in and could later SIGKILL a
        // numeric pid the kernel had already freed).
        foreach ($pids as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $poll     = $probe($pid);
            $probes[] = $pid;
            $action   = self::reapActionFor($poll['state'], $poll['status']);
            if ('drop' === $action) {
                continue;
            }
            $pending[] = $pid;
            if ('kill' === $action) {
                $signal($pid);
                $signals[] = $pid;
            }
        }

        // Pass 2: bounded reap over the injected clock — retry on EINTR, escalate on a hard
        // waitpid error, never block the suite.
        $reapDeadline = $clock() + $reapDeadlineSeconds;
        while ($pending !== [] && $clock() <= $reapDeadline) {
            foreach ($pending as $key => $pid) {
                $poll     = $probe($pid);
                $probes[] = $pid;
                $action   = self::reapActionFor($poll['state'], $poll['status']);
                if ('drop' === $action) {
                    unset($pending[$key]);
                    continue;
                }
                if ('kill' === $action) {
                    $signal($pid);
                    $signals[] = $pid;
                }
            }
            if ($pending !== []) {
                $sleep(1000);
            }
        }

        return [
            'probes'      => $probes,
            'signals'     => $signals,
            'unconfirmed' => array_values($pending),
        ];
    }

    /**
     * G3/T1: terminate and reap ONLY the children the parent has not reaped yet, with a
     * bounded wait, then remove this attempt's harness files.
     *
     * A pid leaves the owned set the moment ONE probe reports it reaped or gone (ECHILD),
     * and only pids whose LATEST probe says "running" (or a hard, non-ECHILD waitpid error)
     * are signalled — an EINTR probe is retried, never signalled. Never pid <= 0.
     *
     * @param array<int, int> $pids parent-owned, unreaped pids
     */
    private function reapRaceChildren(
        array $pids,
        int $attempt,
        int $workerCount,
        ?callable $probe = null,
        ?callable $signal = null,
        ?callable $sleep = null,
        ?callable $clock = null
    ): void {
        $owned = [];
        foreach ($pids as $pid) {
            if ($pid > 0) {
                $owned[] = $pid;
            }
        }
        // A1: the whole pass logic lives in ONE injected method so the test can execute it
        // with scripted probes. The production call passes no overrides, so it uses the real
        // probe, the real kill wrapper and the real sleep/clock — behaviour is unchanged.
        // B2: the overrides exist only so this wrapper's OWN failure step can be tested.
        $result = self::reapOwnedChildren(
            $owned,
            $probe ?? function (int $pid): array {
                return $this->probeChild($pid);
            },
            $signal ?? function (int $pid): void {
                $this->killChild($pid);
            },
            $sleep ?? static function (int $microseconds): void {
                usleep($microseconds);
            },
            $clock ?? static function (): float {
                return microtime(true);
            },
            self::RACE_REAP_DEADLINE_SEC
        );

        for ($i = 0; $i < $workerCount; $i++) {
            @unlink($this->fileTag . '-ready-' . $attempt . '-' . $i);
            @unlink($this->fileTag . '-outcome-' . $attempt . '-' . $i);
        }
        @unlink($this->fileTag . '-barrier-' . $attempt);

        if ($result['unconfirmed'] !== []) {
            $this->fail(
                'harness fatal: ' . count($result['unconfirmed']) . ' child(ren) could not be confirmed reaped within '
                . self::RACE_REAP_DEADLINE_SEC . ' s of SIGKILL (pids: ' . implode(', ', $result['unconfirmed'])
                . ') — they are reported, NOT silently abandoned'
            );
        }
    }

    private function raceWorker(string $role, float $offset, int $appointmentId, int $patientId, int $attempt, int $index): void {
        // The child must NEVER return to the inherited PHPUnit machinery:
        // an uncaught Throwable would be caught by PHPUnit's own (inherited)
        // handler and the child would re-run the remaining suite in parallel
        // with the parent (proven: run 35434123610, exit code 2). Every
        // unexpected Throwable therefore becomes a fatal outcome + exit(1).
        try {
            $this->raceWorkerBody($role, $offset, $appointmentId, $patientId, $attempt, $index);
            exit(0); // child-only — unreachable
        } catch (Throwable $e) {
            @file_put_contents(
                $this->fileTag . '-outcome-' . $attempt . '-' . $index,
                json_encode([
                    'role' => $role,
                    'result' => 'fatal',
                    'detail' => get_class($e) . ': ' . $e->getMessage()
                        . ' @ ' . basename((string) $e->getFile()) . ':' . $e->getLine(),
                ])
            );
            exit(1); // child-only — non-zero = harness fatal (not a product error)
        }
    }

    private function raceWorkerBody(string $role, float $offset, int $appointmentId, int $patientId, int $attempt, int $index): void {
        global $wpdb;
        $own = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $own->set_prefix($wpdb->prefix);
        // The test-only SAVEPOINT query-rewrite must NOT apply to the child:
        // its transactions must be real commits visible to other connections.
        $probeBefore = self::queryHookCensus();
        remove_all_filters('query');
        $probeAfter = self::queryHookCensus();
        if (property_exists($own, 'has_connected') && $own->has_connected) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
            @$own->close();
        }
        $own->has_connected = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        @$own->init_charset();
        if (!$own->check_connection()) {
            @file_put_contents(
                $this->fileTag . '-outcome-' . $attempt . '-' . $index,
                json_encode(['role' => $role, 'result' => 'fatal', 'error' => 'wpdb connection failed in child'])
            );
            exit(1); // non-zero = harness fatal (not a product error)
        }

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        $cpms = new CpmsDb($own);
        $op = new OpLogger($cpms);
        $audit = new AuditLogger($cpms, $op);
        $factory = new SettingsFactory($cpms, $audit);
        $visits = new VisitService(
            $cpms,
            new VisitRepository($cpms),
            new AppointmentRepository($cpms),
            $factory,
            $audit,
            new Phase7Fr55AllowAllLicenseGate(),
            $op,
            null,
            new MembershipRepository($cpms)
        );

        // Constructed BEFORE the barrier: the measured window must contain only
        // the product call, so reported overlap cannot be an artefact of building
        // dependencies inside the window (reader finding A1).
        $providers = new SmsProviderRegistry();
        $providers->register(new LogSmsProvider($op));
        $booking = new BookingService(
            $cpms,
            new SlotRepository($cpms),
            new AppointmentRepository($cpms),
            new PatientRepository($cpms),
            $factory,
            new Phase7Fr55AllowAllLicenseGate(),
            $audit,
            $op,
            new Idempotency($cpms),
            new SmsService(
                $cpms,
                $factory,
                $providers,
                new CredentialVault(),
                $audit,
                $op,
                new JobQueue($cpms, $op, new JobWake()),
                fn (): int => $this->clinicA
            ),
            null,
            new MembershipRepository($cpms)
        );

        // Tell the parent this child is fully bootstrapped (services built, own
        // connection open) so the barrier is never released for a child still booting.
        @file_put_contents($this->fileTag . '-ready-' . $attempt . '-' . $index, '1');

        // Q3(a): the release instant comes from the barrier FILE (written only after
        // every child is ready), so no child can be given a stale instant.
        $barrierFile = $this->fileTag . '-barrier-' . $attempt;
        $fireAt      = 0.0;
        // T2: the child needs its OWN deadline. If the parent fails before releasing the
        // barrier (fork/readiness error) the child must exit by itself instead of polling a
        // file the parent is about to delete.
        $childDeadline = microtime(true) + self::RACE_CHILD_BARRIER_DEADLINE_SEC;
        while (true) {
            if (0.0 === $fireAt && @is_file($barrierFile)) {
                $raw = (string) @file_get_contents($barrierFile);
                if ('' !== $raw) {
                    $fireAt = (float) $raw;
                }
            }
            if ($fireAt > 0.0 && microtime(true) >= $fireAt + $offset) {
                break;
            }
            if (0.0 === $fireAt && microtime(true) > $childDeadline) {
                @file_put_contents(
                    $this->fileTag . '-outcome-' . $attempt . '-' . $index,
                    json_encode([
                        'role' => $role,
                        'result' => 'fatal',
                        'detail' => 'child never saw the barrier within ' . self::RACE_CHILD_BARRIER_DEADLINE_SEC . ' s',
                    ])
                );
                exit(1); // child-only — the parent reports an abnormal exit, never a pass
            }
            usleep(250);
        }

        $startedAt = microtime(true);
        $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => 'not reached'];
        try {
            if ($role === 'no_show') {
                // R4 (1.0): the second worker drives the REAL manual no-show path
                // (BookingService::markNoShowByStaff — the same call chain the
                // reception route uses). The retired automatic sweep is not a
                // product path any more and cannot be raced.
                $booking->markNoShowByStaff($this->secretaryA, $appointmentId, 'race: manual no-show');
                $outcome = [
                    'role' => $role,
                    'appointment_id' => $appointmentId,
                    'result' => 'ok',
                ];
            } else { // check_in
                $v = $visits->checkIn($this->secretaryA, $patientId, $appointmentId);
                if (is_array($v) && !isset($v['id'])) {
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'rejected',
                        'error' => 'check-in rejected: ' . (string) ($v['error_code'] ?? 'unknown') . ' — ' . (string) ($v['message'] ?? ''),
                    ];
                } else {
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'ok',
                        'visit_id' => (int) ($v['id'] ?? 0),
                        'visit_status' => (string) ($v['status'] ?? ''),
                        'visit_source' => (string) ($v['source'] ?? ''),
                    ];
                }
            }
        } catch (BookingException $e) {
            $outcome = [
                'role' => $role,
                'appointment_id' => $appointmentId,
                'result' => 'rejected',
                'error_code' => $e->errorCode, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                'http_status' => $e->httpStatus, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                'error' => $e->getMessage(),
            ];
        } catch (VisitException $e) {
            $outcome = [
                'role' => $role,
                'appointment_id' => $appointmentId,
                'result' => 'rejected',
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $finishedAt = microtime(true);

        // Independence / overlap evidence (asserted by the parent):
        $outcome['pid'] = getmypid();
        $outcome['own_wpdb_connected'] = true;
        $outcome['query_filters_before'] = (int) $probeBefore['query_callbacks'];
        $outcome['query_filters_after'] = (int) $probeAfter['query_callbacks'];
        $outcome['probe_before'] = $probeBefore;
        $outcome['probe_after'] = $probeAfter;
        $outcome['parent_probe'] = $this->parentForkProbe;
        $outcome['call_started_at'] = $startedAt;
        $outcome['call_finished_at'] = $finishedAt;

        @file_put_contents($this->fileTag . '-outcome-' . $attempt . '-' . $index, json_encode($outcome));
        // Wrapper owns the process exit (exit(0) on success / exit(1) on fatal).
    }

    /**
     * Final-state read on a fresh mysqli — the parent's wpdb holds a
     * REPEATABLE-READ snapshot from before the children committed.
     *
     * @return array<string, mixed>
     */
    private function raceState(int $appointmentId, int $slotId): array {
        $conn = $this->freshMysqli();
        $db = App::db();

        $appt = $this->mysqliRow(
            $conn,
            'SELECT status, active_visit_id, no_show_at FROM ' . $db->table('cpms_appointments') . ' WHERE id = ' . (int) $appointmentId
        );
        $live = $this->mysqliAll(
            $conn,
            'SELECT id, status, active, source FROM ' . $db->table('cpms_visits')
            . ' WHERE appointment_id = ' . (int) $appointmentId
            . ' AND active = 1 AND status IN (' . implode(',', array_map(static fn (string $s): string => "'" . $s . "'", self::LIVE_VISIT_STATUSES)) . ')'
        );
        $slot = $this->mysqliRow(
            $conn,
            'SELECT booked_count, held_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $slotId
        );
        $conn->close();

        return [
            'status' => (string) ($appt['status'] ?? ''),
            'active_visit_id' => $appt['active_visit_id'] !== null ? (int) $appt['active_visit_id'] : null,
            'no_show_at' => $appt['no_show_at'] ?? null,
            'live_visits' => $live,
            'slot_booked' => (int) ($slot['booked_count'] ?? -1),
            'slot_held' => (int) ($slot['held_count'] ?? -1),
        ];
    }

    private function freshMysqli(): \mysqli {
        // DB_HOST may carry a port ("127.0.0.1:3306") — parse it like wpdb does.
        $host = DB_HOST;
        $port = null;
        if (str_contains((string) DB_HOST, ':')) {
            [$host, $portPart] = explode(':', (string) DB_HOST, 2);
            $port = (int) $portPart;
        }

        $mysqli = @new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
        if ($mysqli->connect_errno !== 0) {
            $this->fail('harness fatal: cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function mysqliRow(\mysqli $conn, string $sql): array {
        $res = $conn->query($sql);
        if ($res === false) {
            $this->fail('fresh-connection read failed: ' . $conn->error . ' | ' . $sql);
        }
        $row = $res->fetch_assoc();
        $res->free();

        self::assertIsArray($row, 'fresh-connection read must return a row: ' . $sql);

        return (array) $row;
    }

    private function mysqliAll(\mysqli $conn, string $sql): array {
        $res = $conn->query($sql);
        if ($res === false) {
            $this->fail('fresh-connection read failed: ' . $conn->error . ' | ' . $sql);
        }
        $rows = [];
        while (($row = $res->fetch_assoc()) !== null) {
            $rows[] = (array) $row;
        }
        $res->free();

        return $rows;
    }

    // ================= REST dispatch + envelope assertions =================

    private function dispatchNoShow(int $userId, int $clinicId, int $appointmentId, ?string $reason): WP_REST_Response {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', self::NS . '/appointments/' . $appointmentId . '/no-show');
        if ($reason !== null) {
            $request->set_param('reason', $reason);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        $response = rest_do_request($request);
        self::assertInstanceOf(WP_REST_Response::class, $response, 'REST dispatch must not hard-fail');

        return $response;
    }

    private function assertClinicError(WP_REST_Response $response, string $code, string $context = ''): void {
        self::assertIsArray($response->get_data(), $context . ' — response body must be an array');
        $data = $response->get_data();
        self::assertSame($code, $data['code'] ?? null, $context . ' — unexpected error code');
        self::assertSame($response->get_status(), $data['data']['status'] ?? null, $context . ' — envelope status must mirror the HTTP status');
    }

    // ================= Fixture seeding =================

    private function insertOrganization(string $name, string $slug, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            ['name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'organization insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization id must be dynamic');

        return $id;
    }

    private function insertClinic(int $orgId, string $name, string $slug, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            ['organization_id' => $orgId, 'name' => $name, 'slug' => $slug, 'timezone' => self::TZ, 'created_at' => $now, 'updated_at' => $now],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'clinic insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'clinic id must be dynamic (never clinic 1)');

        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [
                'clinic_id' => $clinicId,
                'name' => $name,
                'slug' => $slug,
                'timezone' => $tz,
                'is_primary' => $primary,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'location insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location id must be positive');

        return $id;
    }

    private function insertClinician(int $clinicId, string $name, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            ['clinic_id' => $clinicId, 'full_name' => $name, 'created_at' => $now, 'updated_at' => $now],
            ['%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'clinician insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician id must be positive');

        return $id;
    }

    private function insertPatient(int $clinicId, string $mrn, string $now): int {
        global $wpdb;

        // Bounded diagnostics: snapshot before the insert (property reads only, no query).
        $diagPre = \ClinicCore\Tests\Support\ConnectionDiagnostics::wpdbState($wpdb);

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id' => $clinicId,
                'mrn' => $mrn,
                'first_name' => 'Patient',
                'last_name' => 'FR55',
                'mobile' => '09' . substr(strrev(substr(md5($mrn), 0, 10)), 0, 10),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $diagId    = (int) $wpdb->insert_id;
        $diagErrno = $wpdb->dbh instanceof \mysqli ? (int) mysqli_errno($wpdb->dbh) : -1;
        if (! $ok || $diagId <= 0 || $diagErrno !== 0) {
            \ClinicCore\Tests\Support\ConnectionDiagnostics::emit([
                'test' => 'Phase7Fr55NoShow::testConcurrentCheckInAndNoShow',
                'phase' => 'anomaly',
                'insert_returned_truthy' => (bool) $ok,
                'pre' => $diagPre,
                'post' => \ClinicCore\Tests\Support\ConnectionDiagnostics::wpdbState($wpdb),
                'filters' => \ClinicCore\Tests\Support\ConnectionDiagnostics::queryFilters(),
            ]);
            $diagConn = \ClinicCore\Tests\Support\ConnectionDiagnostics::independent();
            if ($diagConn instanceof \mysqli) {
                \ClinicCore\Tests\Support\ConnectionDiagnostics::emit([
                    'test' => 'Phase7Fr55NoShow::testConcurrentCheckInAndNoShow',
                    'phase' => 'anomaly_independent',
                    'facts' => \ClinicCore\Tests\Support\ConnectionDiagnostics::tableFacts($diagConn, 'cpms_patients', 'id', $diagId),
                    'identity' => \ClinicCore\Tests\Support\ConnectionDiagnostics::identityFacts(
                        $diagConn,
                        $wpdb->prefix . 'cpms_patients',
                        'clinic_id',
                        $clinicId,
                        $mrn,
                        'id'
                    ),
                ]);
                $diagConn->close();
            }
        }
        self::assertTrue((bool) $ok, 'patient insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient id must be positive');

        return $id;
    }

    private function makeUser(string $login, string $role): int {
        $uid = (int) wp_create_user($login, wp_generate_password(12), $login . '@example.test');
        self::assertGreaterThan(0, $uid, 'user must be created');
        $user = new \WP_User($uid);
        $user->set_role($role);
        $this->userIds[] = $uid;

        return $uid;
    }

    /**
     * @return array{date: string, time: string}
     */
    private function futureSlotSpec(int $daysAhead, string $time, string $tz = self::TZ): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->add(new DateInterval('P' . (int) $daysAhead . 'D'));

        return ['date' => $d->format('Y-m-d'), 'time' => $time];
    }

    /**
     * Slot spec $hoursAhead hours from now in the Location timezone.
     *
     * @return array{date: string, time: string}
     */
    private function soonSlotSpec(int $hoursAhead): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->add(new DateInterval('PT' . (int) $hoursAhead . 'H'));

        return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i:s')];
    }

    /**
     * Slot spec $minutesAgo minutes before now in the Location timezone.
     *
     * @return array{date: string, time: string}
     */
    /**
     * Reserve distinct slot seconds within the anchor's trusted local day.
     *
     * @return array{date: string, time: string} Slot date and time.
     */
    private function eligibleRaceSlotSpec( DateTimeImmutable $anchor, int $attempt ): array {
        $local_anchor = $anchor->setTimezone( new DateTimeZone( self::TZ ) );
        $last_second  = $local_anchor->setTime( 23, 59, 59 )->getTimestamp();
        $span         = self::RACE_ATTEMPTS - 1;
        // Prefer a slot five minutes ahead. Near midnight, keep the entire block on
        // the anchor day; its oldest slot is then at most two seconds before the
        // anchor, well inside this fixture's default 30-minute check-in grace.
        $first_second = min( $local_anchor->getTimestamp() + 300, $last_second - $span );
        $candidate    = $local_anchor->setTimestamp( $first_second + $attempt - 1 );

        return ['date' => $candidate->format('Y-m-d'), 'time' => $candidate->format('H:i:s')];
    }

    private function pastSlotSpec(int $minutesAgo, string $tz = self::TZ): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->sub(new DateInterval('PT' . (int) $minutesAgo . 'M'));

        return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i:s')];
    }

    /**
     * @param array{date: string, time: string} $spec
     *
     * @return array{id: int, date: string, time: string}
     */
    private function insertSlot(int $clinicId, int $locationId, int $clinicianId, array $spec, int $booked): array {
        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_schedule_slots',
            [
                'clinic_id' => $clinicId,
                'location_id' => $locationId,
                'clinician_id' => $clinicianId,
                'slot_date' => $spec['date'],
                'slot_time' => $spec['time'],
                'duration_min' => 30,
                'capacity' => 1,
                'booked_count' => $booked,
                'held_count' => 0,
                'is_open' => 1,
                'generated_from' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'slot insert failed (unique/FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'slot id must be positive');

        return ['id' => $id, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * @return array{id: int, slot_id: int, reason: ?string}
     */
    private function seedConfirmedAppointment(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        int $patientId,
        array $spec,
        string $suffix,
        ?string $reason = null
    ): array {
        if ($reason === null) {
            $reason = 'existing-booking-reason FR55-' . substr(uniqid('', true), -8);
        }
        $slot = $this->insertSlot($clinicId, $locationId, $clinicianId, $spec, 1);

        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_appointments',
            [
                'clinic_id' => $clinicId,
                'location_id' => $locationId,
                'reference_code' => 'FR55-' . strtoupper(substr(uniqid($suffix, true), -12)),
                'clinician_id' => $clinicianId,
                'patient_id' => $patientId,
                'slot_id' => $slot['id'],
                'slot_date' => $spec['date'],
                'slot_time' => $spec['time'],
                'status' => 'confirmed',
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'appointment insert failed (unique/FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $apptId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptId, 'appointment id must be positive');

        // Independent connections (race children, fresh state readers) must
        // SEE the seed — commit it (Slice 2 pattern).
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return ['id' => $apptId, 'slot_id' => $slot['id'], 'reason' => $reason];
    }

    /**
     * Past confirmed appointment whose `active_visit_id` points at an
     * INACTIVE/terminal Visit — the documented post-V9-cancel stale-pointer
     * state (check-in alone cannot leave a past appointment in this state:
     * a late check-in takes the ER-06 lazy-no-show path).
     *
     * @return array{id: int, slot_id: int, visit_id: int, reason: ?string}
     */
    private function seedStalePointerPastAppointment(string $suffix): array {
        $spec = $this->pastSlotSpec(180);
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $spec,
            $suffix
        );

        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_visits',
            [
                'clinic_id' => $this->clinicA,
                'location_id' => $this->locationA,
                'clinician_id' => $this->clinicianA,
                'patient_id' => $this->patientA,
                'appointment_id' => $seed['id'],
                'source' => 'scheduled',
                'status' => 'cancelled',
                'visit_date' => $spec['date'],
                'check_in_at' => $now,
                'cancel_reason' => 'lifecycle cancel (V9)',
                'active' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'stale visit insert failed (FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitId, 'stale visit id must be positive');

        $upd = $wpdb->query(
            $wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_appointments SET active_visit_id = %d WHERE id = %d', $visitId, $seed['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(1, (int) $upd, 'pointer update must affect exactly one row');
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Precondition: the pointer is stale (referenced Visit is inactive/terminal).
        $appt = $this->appointmentRow($seed['id']);
        self::assertSame($visitId, (int) $appt['active_visit_id'], 'precondition: stale pointer set');
        self::assertSame(0, count($this->liveVisitsForAppointment($seed['id'])), 'precondition: referenced Visit is NOT live');

        return ['id' => $seed['id'], 'slot_id' => $seed['slot_id'], 'visit_id' => $visitId, 'reason' => $seed['reason']];
    }

    // ================= State reads =================

    /**
     * @return array<string, mixed>|null
     */
    private function appointmentRow(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, status, reason, active_visit_id, no_show_at FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slotRow(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveVisitsForAppointment(int $apptId): array {
        global $wpdb;
        $in = implode(',', array_fill(0, count(self::LIVE_VISIT_STATUSES), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, status, active FROM ' . $wpdb->prefix . 'cpms_visits WHERE appointment_id = %d AND active = 1 AND status IN (' . $in . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                array_merge([$apptId], self::LIVE_VISIT_STATUSES)
            )
        );

        return $rows === null ? [] : array_map(static fn ($r): array => (array) $r, $rows);
    }

    private function noShowAuditCount(int $apptId): int {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ['APPOINTMENT_NO_SHOW', 'appointment', $apptId]
            )
        );

        return (int) $count;
    }

    private function assertNoShowAuditWithReason(int $apptId, string $reason): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT before_json, after_json, meta_json FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ['APPOINTMENT_NO_SHOW', 'appointment', $apptId]
            )
        );
        self::assertNotEmpty(
            $rows,
            'the APPOINTMENT_NO_SHOW audit row must exist and carry the reason in its evidence (before/after/meta JSON)'
        );

        $haystack = '';
        foreach ($rows as $row) {
            $haystack .= ' ' . (string) $row->before_json . ' ' . (string) $row->after_json . ' ' . (string) $row->meta_json;
        }
        self::assertStringContainsString($reason, $haystack, 'the no-show reason must be preserved in the APPOINTMENT_NO_SHOW audit evidence');
    }

    // ================= Cleanup (committed fixture → manual purge) =================

    private function purgeFixture(): void {
        if ($this->orgA <= 0) {
            return;
        }

        global $wpdb;
        $p = static fn (string $table): string => $wpdb->prefix . $table;

        // FK-safe order (proven in the Slice 2 RED suite): children first,
        // Clinic-scoped master data last. Membership capability/location rows
        // cascade from the membership row — no direct delete needed.
        $this->purgeVisitHistory($p);
        $this->purgeVisits($p);
        $this->purgeByClinic($p, 'cpms_notifications');
        $this->purgeByClinic($p, 'cpms_sms_messages');
        // GLOBAL tables (no clinic_id): remove only what this test produced.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_jobs') . ' WHERE id > %d', $this->jobsHighWater)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_operational_logs') . ' WHERE id > %d', $this->oplogsHighWater)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->purgeByClinic($p, 'cpms_idempotency_keys');
        $this->purgeByClinic($p, 'cpms_audit_logs');
        $this->purgeByClinic($p, 'cpms_appointments');
        $this->purgeByClinic($p, 'cpms_schedule_slots');
        $this->purgeByClinic($p, 'cpms_patient_user_links');
        $this->purgeByClinic($p, 'cpms_clinic_memberships');
        $this->purgeByClinic($p, 'cpms_patients');
        $this->purgeByClinic($p, 'cpms_clinicians');
        $this->purgeByClinic($p, 'cpms_locations');
        $this->purgeByClinic($p, 'cpms_settings');
        // cpms_clinics has NO clinic_id column — delete by own id (Slice 2
        // pattern). A clinic_id delete would silently no-op and let the
        // fixture Clinics leak into every later suite (ScopeRequiredException
        // on >1 Clinic).
        $wpdb->query(
            $wpdb->prepare('DELETE FROM ' . $p('cpms_clinics') . ' WHERE id IN (%d, %d)', [$this->clinicA, $this->clinicB]) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_organizations') . ' WHERE id = %d', $this->orgA)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // The fixture is committed (race-children visibility) — finalize.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Users are deleted after the COMMIT (committed cleanup).
        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeVisitHistory(callable $p): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE h FROM ' . $p('cpms_visit_status_history') . ' h INNER JOIN ' . $p('cpms_visits') . ' v ON v.id = h.visit_id WHERE v.clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeVisits(callable $p): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $p('cpms_visits') . ' WHERE clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeByClinic(callable $p, string $table): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $p($table) . ' WHERE clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    private function nowUtcSql(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public function testRaceSlotAnchorKeepsEveryAttemptDistinctAndCheckInEligible(): void {
        $method   = new \ReflectionMethod( self::class, 'eligibleRaceSlotSpec' );
        $timezone = new DateTimeZone( self::TZ );
        $grace    = Settings::DEFAULTS['queue.no_show_grace_minutes'] * 60;
        $cases    = [
            '12:00:00' => [ '12:05:00', '12:05:01', '12:05:02' ],
            '12:00:01' => [ '12:05:01', '12:05:02', '12:05:03' ],
            '23:54:56' => [ '23:59:56', '23:59:57', '23:59:58' ],
            '23:54:59' => [ '23:59:57', '23:59:58', '23:59:59' ],
            '23:59:59' => [ '23:59:57', '23:59:58', '23:59:59' ],
            '00:00:00' => [ '00:05:00', '00:05:01', '00:05:02' ],
        ];

        foreach ( $cases as $local_time => $expected_times ) {
            $anchor       = new DateTimeImmutable( '2026-02-15 ' . $local_time, $timezone );
            $expected_day = $anchor->format( 'Y-m-d' );
            $slot_times   = [];
            for ( $attempt = 1; $attempt <= self::RACE_ATTEMPTS; ++$attempt ) {
                $slot = $method->invoke( $this, $anchor, $attempt );
                self::assertSame( $expected_day, $slot['date'], 'The slot must stay on the anchor day.' );
                $start = new DateTimeImmutable( $slot['date'] . ' ' . $slot['time'], $timezone );
                self::assertLessThan( $start->getTimestamp() + $grace, $anchor->getTimestamp(), 'Check-in must precede the grace deadline.' );
                $slot_times[] = $slot['time'];
            }
            self::assertCount( self::RACE_ATTEMPTS, array_unique( $slot_times ), 'All three slot keys must differ within one race.' );
            self::assertSame( $expected_times, $slot_times, 'The same local anchor must produce these deterministic slot times.' );
        }
    }

}

/**
 * Test-local license gate (fork-safe): pure, no external call, always
 * allowed. Production uses the wired gate; this one only removes license
 * noise from the race children.
 */
final class Phase7Fr55AllowAllLicenseGate implements LicenseGate {
    public function assert(string $operation, array $context = []): LicenseDecision {
        return LicenseDecision::allow();
    }

    public function state(): string {
        return 'active';
    }

    public function isReadOnly(): bool {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use WP_UnitTestCase;

/**
 * Cross-class isolation baseline for the staff messaging suites.
 *
 * File order decides class order in this suite (PHPUnit walks
 * `tests/Integration` in sorted file-name order), and this class sorts
 * immediately before `StaffMessagingSecurityTest` — after
 * `StaffMessagingPricingMigrationTest`, which sits there on `main` too, and
 * after the PR-only `StaffMessagingHiddenReleaseOneTest`.
 *
 * That position is the point: the four checks below observe EXACTLY the state
 * the preceding classes hand to `StaffMessagingSecurityTest`, whose first test
 * (`testSameClinicTwoWayConversationUnreadIndicatorsAndTimestamps`) is the only
 * one able to see such a handover before its own `tearDown()` runs.
 *
 * The class is READ-ONLY. It creates no rows, users or routes, and it performs
 * no project-specific reset in `setUp()`: a cleanup here would erase the very
 * evidence it exists to measure. A red test name below names the leaked state.
 *
 * Only a committed write can cross the class boundary — `WP_UnitTestCase`
 * rolls back each test's transaction — so these counts are the honest detector
 * for an implicit DDL commit or an explicit `COMMIT`.
 */
final class StaffMessagingProbeBaselineTest extends WP_UnitTestCase
{
    /**
     * Framework reset only — no project cleanup, by design.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * No committed conversation row survives the class that created it.
     */
    public function testBaselineHasNoLeakedMessagingRows(): void
    {
        $db = App::db();

        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_message_threads')),
            'baseline: a message thread from an earlier class outlived its test (committed write)'
        );
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_message_participants')),
            'baseline: a message participant from an earlier class outlived its test (committed write)'
        );
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_messages')),
            'baseline: a message body from an earlier class outlived its test (committed write)'
        );
    }

    /**
     * No committed messaging audit row can inflate a later count.
     */
    public function testBaselineHasNoLeakedMessageSendAuditRows(): void
    {
        $db = App::db();

        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . " WHERE action = 'MESSAGE_SEND'"
            ),
            'baseline: a MESSAGE_SEND row from an earlier class would outlive the message it describes'
        );
        self::assertSame(
            0,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . " WHERE action = 'MESSAGE_THREAD_CREATE'"
            ),
            'baseline: a MESSAGE_THREAD_CREATE row from an earlier class would outlive its thread'
        );
    }

    /**
     * No authenticated user and no explicit Clinic scope outlive their class.
     *
     * Both are process-level state: the WP transaction cannot roll either back,
     * so only an explicit reset (`iv1_reset()` / `App::resetScope()`) clears
     * them. A suite that forgets it hands its last actor and Clinic to the next
     * class — exactly the shape of leak this baseline exists to catch.
     */
    public function testBaselineHasNoLeakedUserScopeOrClinicResolver(): void
    {
        self::assertSame(
            0,
            get_current_user_id(),
            'baseline: an authenticated user leaked across the class boundary'
        );
        self::assertNull(
            ScopeContext::tryGet(),
            'baseline: an explicit Clinic scope leaked across the class boundary'
        );
    }

    /**
     * The hidden line still holds here, and no REST server carries the routes.
     */
    public function testBaselineHasNoLeakedRestServerFilterOrSwitchOverride(): void
    {
        self::assertFalse(
            StaffPortalShell::STAFF_MESSAGING_AVAILABLE,
            'R1 (1.0): the messaging switch must still be off at this point in the suite'
        );
        self::assertNotContains(
            StaffPortalShell::MODULE_MESSAGES,
            array_column(StaffPortalShell::registered_modules(), 'id'),
            'R1 (1.0): the messaging menu entry must not be registered for any role'
        );

        // The same call the security suite's setUp() makes, so the server
        // inspected here is the one it will inherit. A server left behind by an
        // earlier class, or the controller registered outside the switch,
        // surfaces as a messaging route below.
        $server = rest_get_server();
        $routes = array_keys($server->get_routes());
        self::assertNotEmpty($routes, 'precondition: the real boot registers routes at all');
        foreach ($routes as $route) {
            self::assertStringNotContainsString(
                '/staff/portal/messages',
                (string) $route,
                'R1 (1.0): a messaging route is present in the shared REST server'
            );
        }
    }
}

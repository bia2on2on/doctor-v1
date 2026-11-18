<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_REST_Request;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * R1 (1.0) — staff messaging is hidden for the 1.0 line, for EVERY role.
 *
 * ONE central switch decides the three surfaces together:
 * `StaffPortalShell::STAFF_MESSAGING_AVAILABLE`. This file proves they are all
 * closed at once — the menu entry, module selection/mount and the four
 * MessagingController REST routes — that no other staff-portal module changed,
 * and that the service, its tables and its template are untouched, so flipping
 * the constant restores the full line.
 */
final class StaffMessagingHiddenReleaseOneTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    /** The four route paths served by MessagingController. */
    private const MESSAGE_PATHS = array(
        '/clinic/v1/staff/portal/messages/context',
        '/clinic/v1/staff/portal/messages/threads',
        '/clinic/v1/staff/portal/messages/threads/1/messages',
        '/clinic/v1/staff/portal/messages/threads/1/read',
    );

    /** @var array<string, int>|null Per-test world cache (PHPUnit builds a fresh instance per test). */
    private ?array $world_cache = null;

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

    public function testTheSwitchIsOffForTheReleaseOneLine(): void
    {
        self::assertFalse(
            StaffPortalShell::STAFF_MESSAGING_AVAILABLE,
            'R1 (1.0): staff messaging must stay hidden until the owner flips this constant'
        );
    }

    public function testTheMenuNeverOffersTheMessagingModuleForAnyRole(): void
    {
        $identities = $this->identities();

        self::assertNotContains(
            StaffPortalShell::MODULE_MESSAGES,
            array_column(StaffPortalShell::registered_modules(), 'id'),
            'the module registry (the menu source) must not carry the hidden module'
        );

        foreach ($identities as $label => $user_id) {
            self::assertNotContains(
                StaffPortalShell::MODULE_MESSAGES,
                array_column(StaffPortalShell::eligible_modules($user_id), 'id'),
                $label . ' must not see a messaging menu entry'
            );
        }
    }

    public function testRequestedModuleMessagesFallsBackExactlyLikeAnUnknownModule(): void
    {
        $doctor = $this->doctorId();
        $previous_get = $_GET;

        try {
            $_GET[ StaffPortalShell::MODULE_PARAM ] = StaffPortalShell::MODULE_MESSAGES;
            $hidden_requested = StaffPortalShell::requested_module();
            $hidden_selected  = StaffPortalShell::select_module($doctor);

            $_GET[ StaffPortalShell::MODULE_PARAM ] = 'definitely-not-a-module';
            $unknown_selected = StaffPortalShell::select_module($doctor);
        } finally {
            $_GET = $previous_get;
        }

        self::assertSame('', $hidden_requested, 'a hidden module id is not a valid view selector');
        self::assertSame($unknown_selected, $hidden_selected, 'hidden and unknown ids must fall back identically');
        self::assertSame(StaffPortalShell::MODULE_DOCTOR, $hidden_selected, 'the doctor default view is mounted instead');
    }

    public function testModuleSelectorNeverSelectsTheMessagingModuleForAnyRole(): void
    {
        $previous_get = $_GET;

        try {
            foreach ($this->identities() as $label => $user_id) {
                $_GET[ StaffPortalShell::MODULE_PARAM ] = StaffPortalShell::MODULE_MESSAGES;
                self::assertNotSame(
                    StaffPortalShell::MODULE_MESSAGES,
                    StaffPortalShell::select_module($user_id),
                    $label . ' must never mount the messaging module'
                );
            }
        } finally {
            $_GET = $previous_get;
        }
    }

    public function testThePortalDoesNotMountTheMessagingModule(): void
    {
        $doctor    = $this->doctorId();
        $page_id   = StaffPortalShell::ensure_portal_page();
        self::assertGreaterThan(0, $page_id);

        $html = $this->render_staff_portal(
            $doctor,
            home_url('/?page_id=' . $page_id),
            array( StaffPortalShell::MODULE_PARAM => StaffPortalShell::MODULE_MESSAGES )
        );

        self::assertStringNotContainsString('data-cpms-staff-module="messages"', $html, 'the module must not be mounted');
        self::assertStringNotContainsString('staff-messages', $html, 'no messaging markup may reach the page');
        self::assertStringContainsString(
            'data-cpms-staff-module="' . StaffPortalShell::MODULE_DOCTOR . '"',
            $html,
            'the default eligible module is mounted instead'
        );
    }

    public function testTheFourMessagingRoutesAreAbsentAndDispatchReturnsRestNoRoute(): void
    {
        foreach (array_keys(rest_get_server()->get_routes()) as $registered) {
            self::assertStringNotContainsString(
                '/staff/portal/messages',
                (string) $registered,
                'R1: no messaging route may exist in the real app boot'
            );
        }

        $identities = $this->identities();
        foreach ($identities as $label => $user_id) {
            wp_set_current_user($user_id);
            foreach (self::MESSAGE_PATHS as $path) {
                foreach (array( 'GET', 'POST' ) as $method) {
                    $response = $this->dispatch($method, $path);
                    self::assertSame(
                        404,
                        $response->get_status(),
                        $label . ' ' . $method . ' ' . $path . ' must fail closed'
                    );
                    $body = $response->get_data();
                    self::assertIsArray($body);
                    self::assertSame(
                        'rest_no_route',
                        (string) ( $body['code'] ?? '' ),
                        $label . ' ' . $method . ' ' . $path . ' must report rest_no_route'
                    );
                }
            }
        }
        wp_set_current_user(0);
    }

    public function testOtherStaffPortalModulesAreUnaffected(): void
    {
        $registered = array_column(StaffPortalShell::registered_modules(), 'id');
        foreach (array( StaffPortalShell::MODULE_DOCTOR, StaffPortalShell::MODULE_RECEPTION, StaffPortalShell::MODULE_FINANCE, StaffPortalShell::MODULE_REPORTS, StaffPortalShell::MODULE_PRICING ) as $module) {
            self::assertContains($module, $registered, $module . ' must stay registered');
        }

        $secretary = $this->secretaryId();
        self::assertSame(
            StaffPortalShell::MODULE_RECEPTION,
            StaffPortalShell::select_module($secretary),
            'the reception journey is unchanged'
        );
        self::assertTrue(
            StaffPortalShell::module_eligible(StaffPortalShell::MODULE_RECEPTION, $secretary),
            'reception eligibility is unchanged'
        );
        self::assertFalse(
            StaffPortalShell::messages_module_eligible($secretary),
            'only the messaging module is hidden'
        );
    }

    public function testServiceTablesAndTemplatesAreUntouched(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileExists($root . '/src/Application/Messaging/StaffMessagingService.php');
        self::assertFileExists($root . '/src/Rest/MessagingController.php');
        self::assertFileExists(StaffPortalShell::messages_module_template_path());

        $world     = $this->worldIds();
        $clinic    = $world['clinic'];
        $doctor    = $world['doctor'];
        $secretary = $world['secretary'];

        $result = $this->iv1_scope($clinic, function () use ($doctor, $secretary): array {
            $service = App::staff_messaging_service();
            $thread  = $service->create_thread($doctor, array( 'recipient_wp_user_id' => $secretary ));
            $sent    = $service->send($doctor, (int) $thread['id'], 'پیام آزمایشی R1');

            return array( 'thread' => $thread, 'sent' => $sent );
        });

        self::assertGreaterThan(0, (int) $result['thread']['id'], 'the messaging service must still work end to end');
        self::assertGreaterThan(0, (int) $result['sent']['id'], 'messages must still persist');

        // The tables are untouched: the rows above exist in the same schema.
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_messages') . ' WHERE thread_id = %d',
                array( (int) $result['thread']['id'] )
            ),
            'messaging data survives for the day the switch is flipped on'
        );
    }

    // ================= helpers =================

    /**
     * Every role the hidden module must stay closed for, including a WP
     * administrator and an anonymous visitor.
     *
     * @return array<string, int>
     */
    private function identities(): array
    {
        $world = $this->worldIds();

        $admin = (int) self::factory()->user->create(array( 'role' => 'administrator' ));
        self::assertGreaterThan(0, $admin);

        return array(
            'administrator' => $admin,
            'manager'       => $world['manager'],
            'secretary'     => $world['secretary'],
            'doctor'        => $world['doctor'],
            'accountant'    => $world['accountant'],
            'patient'       => $world['patient_user'],
            'anonymous'     => 0,
        );
    }

    /**
     * @return array{org: int, clinic: int, location: int, doctor: int, secretary: int, manager: int, accountant: int, patient_user: int}
     */
    private function worldIds(): array
    {
        if (is_array($this->world_cache)) {
            return $this->world_cache;
        }

        $org        = $this->iv1_org('r1');
        $clinic     = $this->iv1_clinic($org);
        $location   = $this->iv1_location($clinic);
        $doctor     = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'r1doc');
        $secretary  = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'r1sec');
        $manager    = $this->iv1_user(RolesAndCapabilities::ROLE_MANAGER, 'r1mgr');
        $accountant = $this->iv1_user(RolesAndCapabilities::ROLE_ACCOUNTANT, 'r1acc');
        $patient_wp = $this->iv1_user(RolesAndCapabilities::ROLE_PATIENT, 'r1pat');

        $this->iv1_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $this->iv1_membership($manager, $clinic, RolesAndCapabilities::ROLE_MANAGER);
        $this->iv1_membership($accountant, $clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->iv1_membership($patient_wp, $clinic, RolesAndCapabilities::ROLE_PATIENT);

        $this->world_cache = array(
            'org'          => $org,
            'clinic'       => $clinic,
            'location'     => $location,
            'doctor'       => $doctor,
            'secretary'    => $secretary,
            'manager'      => $manager,
            'accountant'   => $accountant,
            'patient_user' => $patient_wp,
        );

        return $this->world_cache;
    }

    private function doctorId(): int
    {
        return $this->worldIds()['doctor'];
    }

    private function secretaryId(): int
    {
        return $this->worldIds()['secretary'];
    }

    private function dispatch(string $method, string $route): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    /**
     * Render the staff portal shell through `template_include` exactly as the
     * portal does, so the mount decision is the real one.
     *
     * @param int                   $user_id User id.
     * @param string                $url     Portal permalink.
     * @param array<string, string> $query   Extra GET parameters.
     */
    private function render_staff_portal(int $user_id, string $url, array $query = array()): string
    {
        wp_set_current_user($user_id);
        $target       = array() !== $query ? add_query_arg($query, $url) : $url;
        $path         = (string) ( wp_parse_url($target, PHP_URL_PATH) ?? '/' );
        $qs           = (string) ( wp_parse_url($target, PHP_URL_QUERY) ?? '' );
        $request_uri  = ( '' !== $path ? $path : '/' ) . ( '' !== $qs ? '?' . $qs : '' );
        $previous_get = $_GET;

        try {
            $this->go_to($request_uri);
            foreach ($query as $key => $value) {
                $_GET[ $key ] = $value;
            }
            $baseline = get_stylesheet_directory() . '/page.php';
            if (!is_readable($baseline)) {
                $baseline = get_stylesheet_directory() . '/index.php';
            }
            $template = (string) apply_filters('template_include', $baseline);
            self::assertNotSame($baseline, $template, 'template_include must intercept the portal request');
            self::assertFileExists($template);

            ob_start();
            include $template;

            return (string) ob_get_clean();
        } finally {
            $_GET = $previous_get;
        }
    }
}

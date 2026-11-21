<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\RestoreBarrierGate;
use ClinicCore\Application\Backup\RestoreBarrierStore;
use RuntimeException;
use WP_Error;
use WP_UnitTestCase;

final class RestoreBarrierGateTest extends WP_UnitTestCase {
    /** RB-09: registered REST, shortcode and authentication filters block corrupt markers too. */
    public function testRegisteredFiltersRespectAbsentAndCorruptMarker(): void {
        $rest = [RestoreBarrierGate::class, 'guard_rest'];
        $login = [RestoreBarrierGate::class, 'guard_login'];
        $shortcode = [RestoreBarrierGate::class, 'guard_shortcode'];
        self::assertSame(PHP_INT_MAX, has_filter('rest_authentication_errors', $rest));
        self::assertSame(PHP_INT_MAX, has_filter('rest_pre_dispatch', $rest));
        self::assertSame(PHP_INT_MAX, has_filter('authenticate', $login));
        self::assertSame(PHP_INT_MAX, has_filter('pre_do_shortcode_tag', $shortcode));
        self::assertNull($rest(null));
        self::assertFalse($shortcode(false));
        $patient = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $staff = self::factory()->user->create_and_get(['role' => 'editor']);
        $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
        $admin->add_cap('cpms_config');
        self::assertSame($patient, $login($patient));
        self::assertSame($staff, $login($staff));
        self::assertTrue(add_option(RestoreBarrierStore::OPTION, 'undecodable', '', false));
        try {
            self::assertInstanceOf(WP_Error::class, $rest(null));
            self::assertSame('', $shortcode(false));
            self::assertSame('cpms_restore_blocked', $login($patient)->get_error_code());
            self::assertSame('cpms_restore_blocked', $login($staff)->get_error_code());
            self::assertSame($admin, $login($admin));
        } finally {
            delete_option(RestoreBarrierStore::OPTION);
        }
    }

    /** RB-10: normal admin screen and admin-post deny; recovery GET stays reachable. */
    public function testScreenAndWorkerGuards(): void {
        global $pagenow;
        $old_page = $pagenow;
        $old_get = $_GET;
        $old_server = $_SERVER;
        $old_user = get_current_user_id();
        $guard = [RestoreBarrierGate::class, 'guard_admin'];
        $frontend = [RestoreBarrierGate::class, 'guard_frontend'];
        $worker = [RestoreBarrierGate::class, 'guard_worker'];
        self::assertSame(PHP_INT_MIN, has_action('admin_init', $guard));
        self::assertSame(PHP_INT_MIN, has_action('template_redirect', $frontend));
        self::assertSame(PHP_INT_MIN, has_action('init', $worker));
        $handler = static fn (): callable => static function (): void { throw new RuntimeException('restore denied'); };
        add_filter('wp_die_handler', $handler, PHP_INT_MAX);
        try {
            $pagenow = 'admin-post.php';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            self::assertFalse($this->denied($guard));
            self::assertFalse($this->denied($frontend));
            self::assertTrue(add_option(RestoreBarrierStore::OPTION, ['version' => 999], '', false));
            self::assertTrue($this->denied($guard));
            self::assertTrue($this->denied($frontend));
            $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/bin/cpms';
            self::assertTrue($this->denied($worker));
            unset($_SERVER['SCRIPT_FILENAME']);
            $cron = static fn (): bool => true;
            add_filter('wp_doing_cron', $cron);
            try {
                self::assertTrue($this->denied($worker));
            } finally {
                remove_filter('wp_doing_cron', $cron);
            }
            $pagenow = 'admin.php';
            $_GET['page'] = 'cpms-system';
            wp_set_current_user(0);
            self::assertTrue($this->denied($guard));
            $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
            $admin->add_cap('cpms_config');
            wp_set_current_user($admin->ID);
            self::assertFalse($this->denied($guard));
            $_GET['page'] = 'other';
            self::assertTrue($this->denied($guard));
        } finally {
            delete_option(RestoreBarrierStore::OPTION);
            remove_filter('wp_die_handler', $handler, PHP_INT_MAX);
            wp_set_current_user($old_user);
            $pagenow = $old_page;
            $_GET = $old_get;
            $_SERVER = $old_server;
        }
    }

    private function denied(callable $guard): bool {
        try {
            $guard();
        } catch (RuntimeException $exception) {
            self::assertSame('restore denied', $exception->getMessage());
            return true;
        }
        return false;
    }
}

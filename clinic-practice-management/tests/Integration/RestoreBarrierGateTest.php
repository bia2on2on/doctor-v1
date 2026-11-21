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

    /** RB-14: the core login dispatcher guard denies recovery mutations, not logout. */
    public function testPasswordRecoveryActionsRespectMarker(): void {
        global $action;
        $previous = $action;
        $account = self::factory()->user->create_and_get();
        $hash = $account->user_pass;
        $key = get_password_reset_key($account);
        self::assertIsString($key);
        self::assertInstanceOf(\WP_User::class, check_password_reset_key($key, $account->user_login));
        self::assertSame(PHP_INT_MIN, has_action('login_init', [RestoreBarrierGate::class, 'guard_login_action']));
        $handler = static fn (): callable => static function (): void { throw new RuntimeException('restore denied'); };
        add_filter('wp_die_handler', $handler, PHP_INT_MAX);
        $dispatch = [RestoreBarrierGate::class, 'guard_login_action'];
        try {
            self::assertTrue(add_option(RestoreBarrierStore::OPTION, 'test marker', '', false));
            foreach (['lostpassword', 'retrievepassword', 'rp', 'resetpass', 'register'] as $action) {
                self::assertTrue($this->denied($dispatch));
            }
            self::assertSame($hash, get_userdata($account->ID)->user_pass);
            self::assertInstanceOf(\WP_User::class, check_password_reset_key($key, $account->user_login));
            $action = 'logout';
            self::assertFalse($this->denied($dispatch));
            delete_option(RestoreBarrierStore::OPTION);
            foreach (['lostpassword', 'resetpass'] as $action) {
                self::assertFalse($this->denied($dispatch));
            }
            $validated = check_password_reset_key($key, $account->user_login);
            self::assertInstanceOf(\WP_User::class, $validated);
            reset_password($validated, 'synthetic-new-password');
            self::assertNotSame($hash, get_userdata($account->ID)->user_pass);
        } finally {
            delete_option(RestoreBarrierStore::OPTION);
            remove_filter('wp_die_handler', $handler, PHP_INT_MAX);
            $action = $previous;
        }
    }

    /** RB-15: the registered portal provisioner cannot repair its option while blocked. */
    public function testPortalBootstrapWriteHasAbsentMarkerWitness(): void {
        global $wpdb;
        $portal = \ClinicCore\Frontend\DoctorPortalShell::class;
        $provision = [$portal, 'ensure_portal_page'];
        self::assertSame(20, has_action('init', $provision));
        $page = $provision();
        self::assertGreaterThan(0, $page);
        $option = $portal::PAGE_OPTION;
        $old = get_option($option);
        delete_option($option);
        $hits = 0;
        $pattern = '/\AINSERT INTO `?' . preg_quote($wpdb->options, '/') . '`? .*VALUES \(\'' . preg_quote($option, '/') . '\',/s';
        $watch = static function (string $sql) use ($pattern, &$hits): string {
            if (preg_match($pattern, $sql) === 1) {
                ++$hits;
            }
            return $sql;
        };
        add_filter('query', $watch);
        try {
            self::assertTrue(add_option(RestoreBarrierStore::OPTION, 'test marker', '', false));
            $blocked = $provision();
            $while_blocked = $hits;
            $blocked_option = get_option($option, 0);
            delete_option(RestoreBarrierStore::OPTION);
            $allowed = $provision();
        } finally {
            remove_filter('query', $watch);
            delete_option(RestoreBarrierStore::OPTION);
            update_option($option, $old, false);
        }
        self::assertSame(0, $blocked);
        self::assertSame(0, $blocked_option);
        self::assertSame(0, $while_blocked);
        self::assertSame(1, $hits);
        self::assertSame($page, $allowed);
    }

    /** RB-16: the boot scheduling path writes cron only without the marker. */
    public function testBootCronWriteHasAbsentMarkerWitness(): void {
        global $wpdb;
        $hook = \ClinicCore\Application\System\CronScheduleRepair::HOOK;
        $boot = new \ReflectionMethod(\ClinicCore\Bootstrap\App::class, 'ensure_boot_cron');
        $old_cron = _get_cron_array();
        wp_clear_scheduled_hook($hook);
        self::assertFalse(wp_next_scheduled($hook));
        $pattern = '/\AUPDATE `' . preg_quote($wpdb->options, '/') . "` SET `option_value` = '.*'(?:, `autoload` = '[^']+')? WHERE `option_name` = 'cron'\z/s";
        $hits = 0;
        $watch = static function (string $sql) use ($pattern, &$hits): string {
            if (preg_match($pattern, $sql) === 1) {
                ++$hits;
            }
            return $sql;
        };
        add_filter('query', $watch);
        try {
            self::assertTrue(add_option(RestoreBarrierStore::OPTION, 'test marker', '', false));
            $boot->invoke(null);
            $blocked_hits = $hits;
            $blocked = wp_next_scheduled($hook);
            delete_option(RestoreBarrierStore::OPTION);
            $boot->invoke(null);
            $scheduled = wp_next_scheduled($hook);
        } finally {
            remove_filter('query', $watch);
            delete_option(RestoreBarrierStore::OPTION);
            _set_cron_array($old_cron);
        }
        self::assertFalse($blocked);
        self::assertSame(0, $blocked_hits);
        self::assertIsInt($scheduled);
        self::assertSame(1, $hits);
    }

    /** RB-17: real core authentication cannot rehash or account for a barrier denial. */
    public function testCoreAuthenticationDoesNotWriteForDeniedUser(): void {
        global $wpdb;
        $db = \ClinicCore\Bootstrap\App::db();
        $password = 'synthetic-restore-auth-witness';
        $old_hash = md5($password);
        $patient = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $manager = self::factory()->user->create_and_get(['role' => 'administrator']);
        $manager->add_cap('cpms_config');
        foreach ([$patient, $manager] as $user) {
            self::assertSame(1, $wpdb->update($wpdb->users, ['user_pass' => $old_hash], ['ID' => $user->ID]));
            clean_user_cache($user);
        }
        $snapshot = static fn (): array => [
            $db->fetch_all_strict('SELECT * FROM %i ORDER BY window_key, window_id', [$db->table('rate_limits')]),
            $db->fetch_all_strict('SELECT * FROM %i ORDER BY id', [$db->table('operational_logs')]),
        ];
        $before = $snapshot();
        $pattern = '/\AUPDATE `' . preg_quote($wpdb->users, '/') . "` SET `user_pass` = '[^']+', `user_activation_key` = '' WHERE `ID` = " . $patient->ID . '\z/';
        $hits = 0;
        $watch = static function (string $sql) use ($pattern, &$hits): string {
            if (preg_match($pattern, $sql) === 1) {
                ++$hits;
            }
            return $sql;
        };
        add_filter('query', $watch);
        try {
            self::assertTrue(add_option(RestoreBarrierStore::OPTION, 'test marker', '', false));
            $denied_login = wp_authenticate($patient->user_login, $password);
            $denied_email = wp_authenticate($patient->user_email, $password);
            $after = $snapshot();
            $blocked_hash = $wpdb->get_var($wpdb->prepare('SELECT user_pass FROM %i WHERE ID = %d', $wpdb->users, $patient->ID));
            $blocked_hits = $hits;
            $app_password_allowed = apply_filters('wp_is_application_passwords_available_for_user', true, $patient);
            $exempt = wp_authenticate($manager->user_login, $password);
            delete_option(RestoreBarrierStore::OPTION);
            $normal = wp_authenticate($patient->user_login, $password);
            $normal_hash = $wpdb->get_var($wpdb->prepare('SELECT user_pass FROM %i WHERE ID = %d', $wpdb->users, $patient->ID));
        } finally {
            remove_filter('query', $watch);
            delete_option(RestoreBarrierStore::OPTION);
        }
        self::assertInstanceOf(WP_Error::class, $denied_login);
        self::assertInstanceOf(WP_Error::class, $denied_email);
        self::assertSame(RestoreBarrierGate::ERROR_CODE, $denied_login->get_error_code());
        self::assertSame(RestoreBarrierGate::ERROR_CODE, $denied_email->get_error_code());
        self::assertSame($before, $after);
        self::assertSame($old_hash, $blocked_hash);
        self::assertSame(0, $blocked_hits);
        self::assertFalse($app_password_allowed);
        self::assertInstanceOf(\WP_User::class, $exempt);
        self::assertSame($manager->ID, $exempt->ID);
        self::assertInstanceOf(\WP_User::class, $normal);
        self::assertSame($patient->ID, $normal->ID);
        self::assertNotSame($old_hash, $normal_hash);
        self::assertSame(1, $hits);
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

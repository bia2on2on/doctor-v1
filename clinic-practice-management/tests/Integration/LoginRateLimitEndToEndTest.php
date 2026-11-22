<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Security\LoginRateLimiter;
use WP_Error;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * LOGIN-THROTTLE-FIX (T4): end-to-end coverage of the LoginRateLimiter
 * ceiling, driven through the REAL wp_authenticate()/wp_signon() chain.
 *
 * Every denial assertion in this file goes through the core dispatch — the
 * ceiling is proven to block a known account with the correct password, an
 * email-authenticated account, a spray across many usernames from one IP,
 * and an unknown account without an existence oracle. The throttle logic is
 * never called directly here (the existing LoginRateLimitTest covers the
 * method-level contract).
 *
 * Each test uses a unique username and IP, and $_SERVER is restored in tearDown.
 */
final class LoginRateLimitEndToEndTest extends WP_UnitTestCase
{
    private ?string $old_remote = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->old_remote = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 250);
    }

    protected function tearDown(): void
    {
        if ($this->old_remote === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->old_remote;
        }
        parent::tearDown();
    }

    /** (1) Username login just below the ceiling with the correct password succeeds. */
    public function testUsernameJustBelowCeilingWithCorrectPasswordSucceeds(): void
    {
        $username = 'lrl_e2e_below_' . bin2hex(random_bytes(4));
        $password = 'e2e-password-123';
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME - 1; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        $result = wp_authenticate($username, $password);

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(\WP_User::class, $result);
        self::assertSame($user_id, $result->ID);
    }

    /** (2) The same username at the ceiling with the correct password is denied, with no cookie/session. */
    public function testUsernameAtCeilingWithCorrectPasswordIsDeniedAndCreatesNoSession(): void
    {
        $username = 'lrl_e2e_ceiling_' . bin2hex(random_bytes(4));
        $password = 'e2e-password-123';
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        $result = wp_authenticate($username, $password);
        $signon = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => false,
        ], false);

        wp_set_current_user(0);

        $db   = App::db();
        $rows = $db->fetchAll(
            'SELECT window_key, hits FROM ' . $db->table('cpms_rate_limits') . " WHERE window_key LIKE 'login-user:%'",
            []
        );

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status'] ?? 0);
        self::assertInstanceOf(WP_Error::class, $signon);
        self::assertSame('CLINIC_RATE_LIMITED', $signon->get_error_code());
        self::assertSame(0, get_current_user_id());
        self::assertSame([], WP_Session_Tokens::get_instance($user_id)->get_all());
        self::assertCount(1, $rows);
        self::assertSame(LoginRateLimiter::MAX_PER_USERNAME, (int) $rows[0]['hits']);
    }

    /** (3) Email login at the ceiling is denied. */
    public function testEmailAtCeilingIsDenied(): void
    {
        $username = 'lrl_e2e_email_' . bin2hex(random_bytes(4));
        $password = 'e2e-password-123';
        $email    = $username . '@e2e.test';

        wp_create_user($username, $password, $email);

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME; $i++) {
            wp_authenticate($email, 'wrong-password-' . $i);
        }

        $result = wp_authenticate($email, $password);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
    }

    /** (4) IP ceiling: many different usernames from one IP; the correct password is denied at the ceiling. */
    public function testIpCeilingDeniesCorrectPasswordSprayedFromOneIp(): void
    {
        $password = 'e2e-password-123';
        $login    = 'lrl_e2e_ip_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($login, $password, $login . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_IP; $i++) {
            wp_authenticate('lrl_spray_' . $i . '_' . bin2hex(random_bytes(4)), 'wrong-password-' . $i);
        }

        $result = wp_authenticate($login, $password);

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
    }

    /** (5) Unknown account at the ceiling gets the identical error as a known account — no oracle. */
    public function testUnknownAccountAtCeilingSeesIdenticalErrorAsKnownAccount(): void
    {
        $password = 'e2e-password-123';
        $login    = 'lrl_e2e_oracle_' . bin2hex(random_bytes(4));
        $email    = $login . '@e2e.test';

        wp_create_user($login, $password, $email);

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_IP; $i++) {
            wp_authenticate('lrl_oracle_spray_' . $i . '_' . bin2hex(random_bytes(4)), 'wrong-password-' . $i);
        }

        $known   = wp_authenticate($login, $password);
        $unknown = wp_authenticate('lrl_e2e_definitely_unknown_' . bin2hex(random_bytes(4)), $password);

        self::assertInstanceOf(WP_Error::class, $known);
        self::assertInstanceOf(WP_Error::class, $unknown);
        self::assertSame($known->get_error_code(), $unknown->get_error_code());
        self::assertSame($known->get_error_message(), $unknown->get_error_message());
        self::assertSame($known->get_error_data(), $unknown->get_error_data());
    }

    /** (6) Just below the ceiling, a wrong password still returns the normal failure error. */
    public function testJustBelowCeilingWrongPasswordReturnsNormalFailureError(): void
    {
        $username = 'lrl_e2e_wrong_' . bin2hex(random_bytes(4));
        $password = 'e2e-password-123';
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME - 2; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        $result = wp_authenticate($username, 'wrong-password-final');

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('incorrect_password', $result->get_error_code());
        self::assertNotSame('CLINIC_RATE_LIMITED', $result->get_error_code());
    }

    /** (7) Empty-credential requests pass through the throttle untouched. */
    public function testEmptyCredentialPathPassesThrough(): void
    {
        $result = wp_authenticate('', '');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertNotSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        self::assertNotSame('CLINIC_RATE_LIMITED', wp_authenticate('', 'some-password')->get_error_code());
    }

    /** (8) PortalLogin/ManagementPortalController go through wp_authenticate/wp_signon — covered by (1), (2), (3). */
    public function testPortalLoginPathsUseTheSharedAuthenticateChain(): void
    {
        $username = 'lrl_e2e_portal_' . bin2hex(random_bytes(4));
        $password = 'e2e-password-123';
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        // Same ceiling, same response contract as PortalLogin::handle() and
        // ManagementPortalController::handle_login() (both via wp_authenticate/wp_signon).
        $result = wp_authenticate($username, $password);
        $signon = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => false,
        ], false);

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        self::assertInstanceOf(WP_Error::class, $signon);
        self::assertSame('CLINIC_RATE_LIMITED', $signon->get_error_code());
    }

    /** (9) Restore barrier + clean throttle: the restore barrier still denies a correct password. */
    public function testRestoreBarrierStillDeniesCorrectPasswordWhenThrottleIsClean(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_barrier_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        $added = add_option(\ClinicCore\Application\Backup\RestoreBarrierStore::OPTION, 'test marker', '', 'no');
        try {
            $result = wp_authenticate($username, $password);
        } finally {
            if ($added) {
                delete_option(\ClinicCore\Application\Backup\RestoreBarrierStore::OPTION);
            }
        }

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(\ClinicCore\Application\Backup\RestoreBarrierGate::ERROR_CODE, $result->get_error_code());
        self::assertNotSame('CLINIC_RATE_LIMITED', $result->get_error_code());
    }

    /** (10) Positive witnesses: another user and another IP on the same ceiling are not blocked. */
    public function testAnotherUserAndAnotherIpAreNotBlocked(): void
    {
        $password = 'e2e-password-123';
        $victim_login = 'lrl_e2e_victim_' . bin2hex(random_bytes(4));
        $same_login   = 'lrl_e2e_same_' . bin2hex(random_bytes(4));
        $diff_login   = 'lrl_e2e_diff_' . bin2hex(random_bytes(4));

        wp_create_user($victim_login, $password, $victim_login . '@e2e.test');
        wp_create_user($same_login, $password, $same_login . '@e2e.test');
        wp_create_user($diff_login, $password, $diff_login . '@e2e.test');

        $old_remote = $_SERVER['REMOTE_ADDR'];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
        try {
            for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME; $i++) {
                wp_authenticate($victim_login, 'wrong-password-' . $i);
            }

            $victim_result = wp_authenticate($victim_login, $password);
            $same_result   = wp_authenticate($same_login, $password);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
            $diff_result = wp_authenticate($diff_login, $password);
        } finally {
            $_SERVER['REMOTE_ADDR'] = $old_remote;
        }

        self::assertInstanceOf(WP_Error::class, $victim_result);
        self::assertSame('CLINIC_RATE_LIMITED', $victim_result->get_error_code());
        self::assertInstanceOf(\WP_User::class, $same_result);
        self::assertSame(get_user_by('login', $same_login)->ID, $same_result->ID);
        self::assertInstanceOf(\WP_User::class, $diff_result);
        self::assertSame(get_user_by('login', $diff_login)->ID, $diff_result->ID);
    }

    /** (11) Counters never store the raw username (and keys never leak it). */
    public function testStoredKeysNeverContainRawUsername(): void
    {
        $username = 'lrl_e2e_secret_' . bin2hex(random_bytes(4)) . '_secret_username';
        $password = 'e2e-password-123';
        wp_create_user($username, $password, $username . '@e2e.test');

        wp_authenticate($username, 'wrong-password-1');
        wp_authenticate($username, 'wrong-password-2');

        $other = 'lrl_e2e_other_' . bin2hex(random_bytes(4));
        wp_authenticate($other, 'wrong-password-1');

        $db   = App::db();
        $rows = $db->fetchAll(
            'SELECT window_key, hits FROM ' . $db->table('cpms_rate_limits') . " WHERE window_key LIKE 'login-user:%'",
            []
        );

        self::assertCount(2, $rows);
        $keys = [];
        foreach ($rows as $row) {
            $key = (string) $row['window_key'];
            self::assertStringNotContainsString($username, $key);
            self::assertStringNotContainsString($other, $key);
            self::assertStringStartsWith('login-user:', $key);
            self::assertSame(43, strlen($key));
            self::assertMatchesRegularExpression('/^login-user:[0-9a-f]{32}$/', $key);
            $keys[] = $key;
        }
        self::assertCount(2, array_unique($keys), 'Distinct usernames map to distinct keys.');
    }
}

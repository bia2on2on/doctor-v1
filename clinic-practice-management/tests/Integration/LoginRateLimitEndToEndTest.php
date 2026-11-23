<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientNidHmac;
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

        // COOKIE WITNESS: count the core actions set_auth_cookie and
        // set_logged_in_cookie fired by the DENIED wp_signon. These actions are
        // do_action()-driven inside wp_set_auth_cookie (wp-includes/pluggable.php,
        // 7.1.3: set_auth_cookie at :1154, set_logged_in_cookie at :1171).
        // The send_auth_cookies filter (pluggable.php:1189) suppresses only the
        // setcookie() calls — the actions fire BEFORE that filter runs, so the
        // spy stays live while real header output is suppressed.
        $authCookieCount = 0;
        $loggedInCookieCount = 0;
        $cookieSpy = function () use (&$authCookieCount): void {
            $authCookieCount++;
        };
        $loggedInCookieSpy = function () use (&$loggedInCookieCount): void {
            $loggedInCookieCount++;
        };
        $suppressCookies = static function (): bool {
            return false;
        };
        add_action('set_auth_cookie', $cookieSpy, 10, 6);
        add_action('set_logged_in_cookie', $loggedInCookieSpy, 10, 6);
        add_filter('send_auth_cookies', $suppressCookies, 10, 1);
        try {
            $signon = wp_signon([
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => false,
            ], false);
        } finally {
            remove_action('set_auth_cookie', $cookieSpy, 10);
            remove_action('set_logged_in_cookie', $loggedInCookieSpy, 10);
            remove_filter('send_auth_cookies', $suppressCookies, 10);
        }

        wp_set_current_user(0);

        $db   = App::db();
        $rows = $db->fetchAll(
            'SELECT window_key, hits FROM ' . $db->table('cpms_rate_limits') . " WHERE window_key LIKE 'login-user:%'",
            []
        );

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        $resultData = $result->get_error_data();
        self::assertArrayHasKey('status', $resultData);
        self::assertSame(429, $resultData['status']);
        self::assertInstanceOf(WP_Error::class, $signon);
        self::assertSame('CLINIC_RATE_LIMITED', $signon->get_error_code());
        self::assertSame(0, get_current_user_id());
        self::assertSame(0, $authCookieCount, 'set_auth_cookie must not fire on a denied sign-on.');
        self::assertSame(0, $loggedInCookieCount, 'set_logged_in_cookie must not fire on a denied sign-on.');
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

    /** (12) Cookie-action spy — positive witness: a SUCCESSFUL sign-on below the ceiling fires both core actions. */
    public function testSuccessfulSignonBelowCeilingFiresBothCookieActionsAndASessionToken(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_cookie_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME - 1; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        $authCookieCount = 0;
        $loggedInCookieCount = 0;
        $cookieSpy = function () use (&$authCookieCount): void {
            $authCookieCount++;
        };
        $loggedInCookieSpy = function () use (&$loggedInCookieCount): void {
            $loggedInCookieCount++;
        };
        $suppressCookies = static function (): bool {
            return false;
        };
        add_action('set_auth_cookie', $cookieSpy, 10, 6);
        add_action('set_logged_in_cookie', $loggedInCookieSpy, 10, 6);
        add_filter('send_auth_cookies', $suppressCookies, 10, 1);
        try {
            $signon = wp_signon([
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => false,
            ], false);
        } finally {
            remove_action('set_auth_cookie', $cookieSpy, 10);
            remove_action('set_logged_in_cookie', $loggedInCookieSpy, 10);
            remove_filter('send_auth_cookies', $suppressCookies, 10);
        }

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(\WP_User::class, $signon);
        self::assertSame($user_id, $signon->ID);
        self::assertGreaterThanOrEqual(1, $authCookieCount, 'spy alive: set_auth_cookie must fire on a successful sign-on.');
        self::assertGreaterThanOrEqual(1, $loggedInCookieCount, 'spy alive: set_logged_in_cookie must fire on a successful sign-on.');
        self::assertNotEmpty(
            WP_Session_Tokens::get_instance($user_id)->get_all(),
            'a successful sign-on issues a session token.'
        );
    }

    /** (13) Counters: a SUCCESSFUL sign-on below the ceiling must leave BOTH buckets EXACTLY unchanged. */
    public function testSuccessfulSignonBelowCeilingLeavesBothBucketsUnchanged(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_ctr_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        // One failure establishes a non-zero baseline: IP bucket 1, username bucket 1.
        wp_authenticate($username, 'wrong-password-only');

        $db    = App::db();
        $table = $db->table('cpms_rate_limits');
        $rowsBefore = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%' OR window_key LIKE 'login-user:%'", []);
        self::assertSame(2, count($rowsBefore), 'expected one IP and one username row after one failure');

        $insertCount = 0;
        $selectCount = 0;
        $insertPattern = '/\AINSERT INTO .*' . preg_quote($table, '/') . '/';
        $selectPattern = '/\ASELECT .*FROM .*' . preg_quote($table, '/i') . '/';
        $watch = static function (string $sql) use ($insertPattern, $selectPattern, &$insertCount, &$selectCount): string {
            if (preg_match($insertPattern, $sql) === 1) {
                $insertCount++;
            }
            if (preg_match($selectPattern, $sql) === 1) {
                $selectCount++;
            }
            return $sql;
        };

        add_filter('query', $watch);
        try {
            $signon = wp_signon([
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => false,
            ], false);
        } finally {
            remove_filter('query', $watch);
        }

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(\WP_User::class, $signon);
        self::assertSame($user_id, $signon->ID);
        self::assertSame(0, $insertCount, 'a successful sign-on must not grow the rate-limit buckets.');
        self::assertGreaterThanOrEqual(1, $selectCount, 'spy must observe the peek reads of the successful sign-on.');

        $rowsAfter = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%' OR window_key LIKE 'login-user:%'", []);
        self::assertSame($rowsBefore, $rowsAfter, 'a successful sign-on must leave both buckets exactly unchanged.');

        // Positive witness (same spy shape): driving a failure grows BOTH rows by exactly one.
        $insertCount = 0;
        $selectCount = 0;
        add_filter('query', $watch);
        try {
            $failing = wp_signon([
                'user_login'    => $username,
                'user_password' => 'wrong-password-only',
                'remember'      => false,
            ], false);
        } finally {
            remove_filter('query', $watch);
        }

        self::assertInstanceOf(WP_Error::class, $failing);
        self::assertSame('incorrect_password', $failing->get_error_code());
        self::assertGreaterThanOrEqual(1, $insertCount, 'spy must observe bucket growth on a failure.');
        self::assertGreaterThanOrEqual(1, $selectCount, 'spy must observe reads on a failure.');
        $failedRows = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%' OR window_key LIKE 'login-user:%'", []);
        self::assertCount(2, $failedRows);
        $ipRow = null;
        $usernameRow = null;
        foreach ($failedRows as $row) {
            if (strpos((string) $row['window_key'], 'login-ip:') === 0) {
                $ipRow = $row;
            } else {
                $usernameRow = $row;
            }
        }
        self::assertIsArray($ipRow);
        self::assertIsArray($usernameRow);
        self::assertSame(2, (int) $ipRow['hits']);
        self::assertSame(2, (int) $usernameRow['hits']);
    }

    /** (14) THROTTLED IP + both credentials empty: input passes through, no counter growth. */
    public function testThrottledIpWithBothCredentialsEmptyPassesThroughAndDoesNotGrow(): void
    {
        for ($i = 0; $i < LoginRateLimiter::MAX_PER_IP; $i++) {
            wp_authenticate('lrl_e2e_empty_' . $i . '_' . bin2hex(random_bytes(4)), 'wrong-password-' . $i);
        }

        $db    = App::db();
        $table = $db->table('cpms_rate_limits');
        $rowsBefore = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%'", []);
        self::assertCount(1, $rowsBefore, 'expected one IP row at the ceiling');
        self::assertSame(LoginRateLimiter::MAX_PER_IP, (int) $rowsBefore[0]['hits']);

        $result = wp_authenticate('', '');

        $rowsAfter = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%'", []);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertNotSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        self::assertSame('empty_username', $result->get_error_code());
        self::assertSame($rowsBefore, $rowsAfter, 'empty-credential requests must not grow the IP bucket.');
    }

    /** (15) Username present + password empty + username bucket at ceiling: throttled, username bucket does not grow. */
    public function testUsernamePresentEmptyPasswordAtUsernameCeilingIsRateLimitedAndDoesNotGrow(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_single_' . bin2hex(random_bytes(4));
        wp_create_user($username, $password, $username . '@e2e.test');

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME; $i++) {
            wp_authenticate($username, 'wrong-password-' . $i);
        }

        $db    = App::db();
        $table = $db->table('cpms_rate_limits');
        $usernameRowsBefore = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-user:%'", []);
        self::assertCount(1, $usernameRowsBefore);

        $result = wp_authenticate($username, '');

        $usernameRowsAfter = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-user:%'", []);
        $ipRowsAfter = $db->fetchAll('SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%'", []);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_RATE_LIMITED', $result->get_error_code());
        self::assertSame($usernameRowsBefore, $usernameRowsAfter, 'the username bucket is at ceiling and must not grow.');
        self::assertCount(1, $ipRowsAfter);
        self::assertSame(LoginRateLimiter::MAX_PER_USERNAME + 1, (int) $ipRowsAfter[0]['hits'], 'the IP bucket grows by exactly one failure.');
    }

    /** (16) Missing option row: bootstrapped on the first call; below-ceiling correct password succeeds. */
    public function testMissingOptionBootstrapsOnFirstCallAndSucceeds(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_bootstrap_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        $prevOption = get_option(PatientNidHmac::OPTION_KEY, null);
        try {
            // State: the option ROW does not exist at all.
            delete_option(PatientNidHmac::OPTION_KEY);
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');
            self::assertNull(get_option(PatientNidHmac::OPTION_KEY, null));

            // Call 1: correct password, below the ceiling — must succeed.
            $result = wp_authenticate($username, $password);
            self::assertInstanceOf(\WP_User::class, $result);
            self::assertSame($user_id, $result->ID);

            // The option is now non-empty (bootstrapped on the first call).
            $storedAfterFirstCall = (string) get_option(PatientNidHmac::OPTION_KEY, '');
            self::assertNotSame('', $storedAfterFirstCall);

            // Call 2: below-ceiling failure — counted under the bootstrapped key.
            wp_authenticate($username, 'wrong-password-1');

            $db    = App::db();
            $table = $db->table('cpms_rate_limits');
            $rowsAfterSecondCall = $db->fetchAll(
                'SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-user:%'",
                []
            );
            self::assertCount(1, $rowsAfterSecondCall);
            self::assertSame(1, (int) $rowsAfterSecondCall[0]['hits']);

            // Call 3: the same key — the bucket and the option stay unchanged.
            wp_authenticate($username, 'wrong-password-2');

            $storedAfterSecondCall = (string) get_option(PatientNidHmac::OPTION_KEY, '');
            $rowsAfterThirdCall = $db->fetchAll(
                'SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-user:%'",
                []
            );

            self::assertSame($storedAfterFirstCall, $storedAfterSecondCall, 'the bootstrapped key must stay stable across calls.');
            self::assertCount(1, $rowsAfterThirdCall);
            self::assertSame(2, (int) $rowsAfterThirdCall[0]['hits']);
            self::assertSame($rowsAfterSecondCall[0]['window_key'], $rowsAfterThirdCall[0]['window_key'], 'call 2 must reuse the bootstrapped key.');
        } finally {
            delete_option(PatientNidHmac::OPTION_KEY);
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');
            if ($prevOption !== null) {
                update_option(PatientNidHmac::OPTION_KEY, $prevOption, 'no');
            }
        }
    }

    /** (17) Option empty-but-present: correct password is rejected with the controlled error — never self-repaired, never counted. */
    public function testEmptyOptionRowFailsClosedWithoutSelfRepair(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_empty_row_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        $prevOption = get_option(PatientNidHmac::OPTION_KEY, null);
        try {
            // State: the option ROW exists with an EMPTY value (manager scenario).
            delete_option(PatientNidHmac::OPTION_KEY);
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');
            add_option(PatientNidHmac::OPTION_KEY, '', '', 'no');
            self::assertSame('', get_option(PatientNidHmac::OPTION_KEY, null));

            // Call 1: correct password — the row is present but unusable, so
            // fail-closed: controlled 503, never a repair write.
            $result = wp_authenticate($username, $password);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('cpms_login_throttle_unavailable', $result->get_error_code());
            $data = $result->get_error_data();
            self::assertArrayHasKey('status', $data);
            self::assertSame(503, $data['status']);

            // The empty row must NOT be self-repaired — the stored value
            // remains empty after the authenticate chain ran.
            self::assertSame('', get_option(PatientNidHmac::OPTION_KEY, null), 'an unusable row must not be self-repaired.');

            // Wrong password: identical controlled error, never counted.
            $wrong = wp_authenticate($username, 'wrong-password-1');
            self::assertInstanceOf(WP_Error::class, $wrong);
            self::assertSame($result->get_error_code(), $wrong->get_error_code());
            self::assertSame($result->get_error_message(), $wrong->get_error_message());
            self::assertSame($result->get_error_data(), $wrong->get_error_data());

            // Known and unknown accounts get the identical response — no oracle.
            $unknown = wp_authenticate('lrl_e2e_empty_row_unknown_' . bin2hex(random_bytes(4)), $password);
            self::assertInstanceOf(WP_Error::class, $unknown);
            self::assertSame($result->get_error_code(), $unknown->get_error_code());
            self::assertSame($result->get_error_message(), $unknown->get_error_message());
            self::assertSame($result->get_error_data(), $unknown->get_error_data());

            // Counted in no bucket — neither key nor IP row was created.
            $db    = App::db();
            $table = $db->table('cpms_rate_limits');
            $users = $db->fetchAll(
                'SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-user:%'",
                []
            );
            $ips = $db->fetchAll(
                'SELECT window_key, hits FROM ' . $table . " WHERE window_key LIKE 'login-ip:%'",
                []
            );
            self::assertSame([], $users, 'the unusable-row path must never count a username bucket.');
            self::assertSame([], $ips, 'the unusable-row path must never count an IP bucket.');

            self::assertGreaterThan(0, $user_id);
        } finally {
            delete_option(PatientNidHmac::OPTION_KEY);
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');
            if ($prevOption !== null) {
                update_option(PatientNidHmac::OPTION_KEY, $prevOption, 'no');
            }
        }
    }

    /** (18) Persistence failure: authenticate returns the controlled error — never an exception, never counted, never cookie/token. */
    public function testUnavailableKeyReturnsControlledErrorWithoutCookieTokensOrBuckets(): void
    {
        // Simulate the persist failure with the narrowest official filter:
        // 'pre_option' masks cpms_nid_hmac_key with an unusable value, so the
        // resolver can never see a usable key even though the stored option row
        // is perfectly readable. Restored in finally (strict).
        $prevOption = get_option(PatientNidHmac::OPTION_KEY, null);
        $intercepted = static function ($value, string $option, $default) {
            if ($option === PatientNidHmac::OPTION_KEY) {
                return '';
            }
            return $value;
        };
        add_filter('pre_option', $intercepted, 10, 3);

        $password = 'e2e-password-123';
        $knownUsername = 'lrl_e2e_unavail_' . bin2hex(random_bytes(4));
        $user_id = wp_create_user($knownUsername, $password, $knownUsername . '@e2e.test');

        try {
            $known = wp_authenticate($knownUsername, $password);
            $unknown = wp_authenticate('lrl_e2e_unavail_unknown_' . bin2hex(random_bytes(4)), $password);
            $wrong   = wp_authenticate($knownUsername, 'wrong-password-1');

            $authCookieCount = 0;
            $loggedInCookieCount = 0;
            $cookieSpy = function () use (&$authCookieCount): void {
                $authCookieCount++;
            };
            $loggedInCookieSpy = function () use (&$loggedInCookieCount): void {
                $loggedInCookieCount++;
            };
            $suppressCookies = static function (): bool {
                return false;
            };
            add_action('set_auth_cookie', $cookieSpy, 10, 6);
            add_action('set_logged_in_cookie', $loggedInCookieSpy, 10, 6);
            add_filter('send_auth_cookies', $suppressCookies, 10, 1);
            try {
                $signon = wp_signon([
                    'user_login'    => $knownUsername,
                    'user_password' => $password,
                    'remember'      => false,
                ], false);
            } finally {
                remove_action('set_auth_cookie', $cookieSpy, 10);
                remove_action('set_logged_in_cookie', $loggedInCookieSpy, 10);
                remove_filter('send_auth_cookies', $suppressCookies, 10);
            }
        } finally {
            remove_filter('pre_option', $intercepted, 10);
        }

        // The option row must be exactly as it was before the masked calls —
        // the unusable-value path must never perform a write (no self-repair).
        self::assertSame($prevOption, get_option(PatientNidHmac::OPTION_KEY, null), 'the masked path must never write the key option.');

        self::assertGreaterThan(0, $user_id);
        self::assertInstanceOf(WP_Error::class, $known);
        self::assertSame('cpms_login_throttle_unavailable', $known->get_error_code());
        $knownData = $known->get_error_data();
        self::assertArrayHasKey('status', $knownData);
        self::assertSame(503, $knownData['status']);

        // Known and unknown accounts get the identical error — no existence oracle.
        self::assertInstanceOf(WP_Error::class, $unknown);
        self::assertInstanceOf(WP_Error::class, $wrong);
        self::assertSame($known->get_error_code(), $unknown->get_error_code());
        self::assertSame($known->get_error_code(), $wrong->get_error_code());
        self::assertSame($known->get_error_message(), $unknown->get_error_message());
        self::assertSame($known->get_error_message(), $wrong->get_error_message());
        self::assertSame($knownData, $unknown->get_error_data());
        self::assertSame($knownData, $wrong->get_error_data());

        // The sign-on is the controlled error — never a session, never a cookie.
        self::assertInstanceOf(WP_Error::class, $signon);
        self::assertSame('cpms_login_throttle_unavailable', $signon->get_error_code());
        self::assertSame(0, $authCookieCount, 'set_auth_cookie must not fire on the controlled error.');
        self::assertSame(0, $loggedInCookieCount, 'set_logged_in_cookie must not fire on the controlled error.');
        self::assertSame([], WP_Session_Tokens::get_instance($user_id)->get_all());

        // Counted in no bucket — neither key nor IP row was created.
        $db    = App::db();
        $users = $db->fetchAll(
            'SELECT window_key, hits FROM ' . $db->table('cpms_rate_limits') . " WHERE window_key LIKE 'login-user:%'",
            []
        );
        $ips = $db->fetchAll(
            'SELECT window_key, hits FROM ' . $db->table('cpms_rate_limits') . " WHERE window_key LIKE 'login-ip:%'",
            []
        );
        self::assertSame([], $users, 'the controlled error path must never count a username bucket.');
        self::assertSame([], $ips, 'the controlled error path must never count an IP bucket.');
    }

    /** (19) Positive witness: with a healthy key the same spy sees the cookie actions (>= 1). */
    public function testHealthyKeySignonFiresBothCookieActionsAndASessionToken(): void
    {
        $password = 'e2e-password-123';
        $username = 'lrl_e2e_healthy_' . bin2hex(random_bytes(4));
        $user_id  = wp_create_user($username, $password, $username . '@e2e.test');

        $prevOption = get_option(PatientNidHmac::OPTION_KEY, null);
        try {
            // Healthy option — resolvable without any repair.
            update_option(PatientNidHmac::OPTION_KEY, 'healthy-test-key-' . bin2hex(random_bytes(16)), 'no');
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');

            for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME - 1; $i++) {
                wp_authenticate($username, 'wrong-password-' . $i);
            }

            $authCookieCount = 0;
            $loggedInCookieCount = 0;
            $cookieSpy = function () use (&$authCookieCount): void {
                $authCookieCount++;
            };
            $loggedInCookieSpy = function () use (&$loggedInCookieCount): void {
                $loggedInCookieCount++;
            };
            $suppressCookies = static function (): bool {
                return false;
            };
            add_action('set_auth_cookie', $cookieSpy, 10, 6);
            add_action('set_logged_in_cookie', $loggedInCookieSpy, 10, 6);
            add_filter('send_auth_cookies', $suppressCookies, 10, 1);
            try {
                $signon = wp_signon([
                    'user_login'    => $username,
                    'user_password' => $password,
                    'remember'      => false,
                ], false);
            } finally {
                remove_action('set_auth_cookie', $cookieSpy, 10);
                remove_action('set_logged_in_cookie', $loggedInCookieSpy, 10);
                remove_filter('send_auth_cookies', $suppressCookies, 10);
            }

            self::assertGreaterThan(0, $user_id);
            self::assertInstanceOf(\WP_User::class, $signon);
            self::assertSame($user_id, $signon->ID);
            self::assertGreaterThanOrEqual(1, $authCookieCount, 'spy alive: set_auth_cookie must fire on a healthy-key sign-on.');
            self::assertGreaterThanOrEqual(1, $loggedInCookieCount, 'spy alive: set_logged_in_cookie must fire on a healthy-key sign-on.');
            self::assertNotEmpty(
                WP_Session_Tokens::get_instance($user_id)->get_all(),
                'a healthy-key sign-on issues a session token.'
            );
        } finally {
            delete_option(PatientNidHmac::OPTION_KEY);
            wp_cache_delete(PatientNidHmac::OPTION_KEY, 'options');
            if ($prevOption !== null) {
                update_option(PatientNidHmac::OPTION_KEY, $prevOption, 'no');
            }
        }
    }
}

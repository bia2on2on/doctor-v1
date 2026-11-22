<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\RestoreIdentityCleanup;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Backup\BackupException;
use WP_Session_Tokens;
use WP_UnitTestCase;

final class RestoreIdentityCleanupTest extends WP_UnitTestCase {
    /** RB-01: global revocation, hash preservation and repeat execution. */
    public function testGlobalRevocationIsIdempotent(): void {
        $db = App::db();
        $table = $db->table('patient_logins');
        $users = [];
        foreach (['active', 'pending', 'closed'] as $index => $state) {
            $user = self::factory()->user->create();
            $sessions = WP_Session_Tokens::get_instance($user);
            $token = $sessions->create(time() + 3600);
            self::assertTrue($sessions->verify($token));
            $users[$user] = [get_userdata($user)->user_pass, $token];
            self::assertTrue($db->insert('patient_logins', [
                'kind' => 'binding', 'state' => $state, 'wp_user_id' => $user,
                'clinic_id' => 1000 + $index, 'patient_id' => 2000 + $index,
                'live_key' => $state === 'closed' ? null : 'restore-fixture-' . $index,
                'created_at' => '2026-11-20 00:00:00', 'updated_at' => '2026-11-20 00:00:00',
            ]));
        }
        $cleanup = new RestoreIdentityCleanup($db);
        for ($run = 0; $run < 2; ++$run) {
            $cleanup->revoke();
            foreach ($users as $user => [$hash, $token]) {
                $row = $db->fetchRow('SELECT state, live_key FROM %i WHERE wp_user_id = %d', [$table, $user]);
                self::assertSame(['state' => 'closed', 'live_key' => null], $row);
                self::assertFalse(WP_Session_Tokens::get_instance($user)->verify($token));
                self::assertSame($hash, $db->fetchValue('SELECT user_pass FROM %i WHERE ID = %d', [$db->wpdb()->users, $user]));
            }
        }
    }

    /** RB-02: the exact closure statement reaches the DB, and a DB error is not success. */
    public function testClosureDatabaseFailureStopsBeforeSessionDeletion(): void {
        $db = App::db();
        $wpdb = $db->wpdb();
        $user = self::factory()->user->create();
        $sessions = WP_Session_Tokens::get_instance($user);
        $token = $sessions->create(time() + 3600);
        $sql = 'UPDATE `' . $db->table('patient_logins') . "` SET state = 'closed', live_key = NULL WHERE state <> 'closed'";
        $pattern = '/\A' . preg_quote($sql, '/') . '\z/';
        $hits = 0;
        $filter = static function (string $statement) use ($pattern, &$hits): string {
            if (preg_match($pattern, $statement) !== 1) {
                return $statement;
            }
            ++$hits;
            return 'INVALID SQL FOR RESTORE TEST';
        };
        $strict = $db->is_strict();
        $suppressed = $wpdb->suppress_errors(true);
        $failure = null;
        $db->setStrict(false);
        add_filter('query', $filter);
        try {
            (new RestoreIdentityCleanup($db))->revoke();
        } catch (BackupException $exception) {
            $failure = $exception->getErrorCode();
        } finally {
            remove_filter('query', $filter);
            $db->setStrict($strict);
            $wpdb->suppress_errors($suppressed);
        }
        self::assertSame(1, $hits);
        self::assertSame('RESTORE_BINDING_CLOSURE_FAILED', $failure);
        self::assertSame($strict, $db->is_strict());
        self::assertTrue($sessions->verify($token));
        (new RestoreIdentityCleanup($db))->revoke();
        self::assertFalse($sessions->verify($token));
    }
}

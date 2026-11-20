<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Migration 0032 — `cpms_patient_logins` table creation and upgrade safety.
 *
 * Additive-only: creates `{wp_prefix}cpms_patient_logins` with the exact
 * columns, keys, uniqueness constraints and InnoDB/utf8mb4 engine from
 * `docs/architecture/patient-identity-login-design.md` §1.
 */
final class PatientLoginsMigrationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const VERSION      = '2026_11_20_0032';
    private const HEAD_VERSION = '2026_11_20_0032';
    private const TABLE        = 'cpms_patient_logins';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables( static fn (): array => App::migrations()->migrate() );
    }

    protected function tearDown(): void
    {
        try {
            $this->withRealTables( static fn (): array => App::migrations()->migrate() );
        } finally {
            parent::tearDown();
        }
    }

    public function testPatientLoginsTableExistsWithDesignedColumnsAndKeys(): void
    {
        $db    = App::db();
        $table = $db->table( self::TABLE );

        self::assertSame( self::HEAD_VERSION, App::migrations()->currentVersion() );
        self::assertContains( self::VERSION, App::migrations()->applied() );
        self::assertSame( [], $this->withRealTables( static fn (): array => App::migrations()->migrate() ) );

        $info = $db->fetchRow(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [ $table ]
        );
        self::assertNotNull( $info, 'cpms_patient_logins must exist as a real table' );
        self::assertSame( 'InnoDB', (string) $info['ENGINE'] );
        self::assertStringStartsWith( 'utf8mb4_', (string) $info['TABLE_COLLATION'] );

        $columns = array_column(
            $db->fetchAll(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
                [ $table ]
            ),
            'COLUMN_NAME'
        );
        self::assertSame(
            [
                'id',
                'kind',
                'wp_user_id',
                'mobile',
                'state',
                'clinic_id',
                'patient_id',
                'verified_at',
                'verification_method',
                'live_key',
                'account_key',
                'given_name',
                'family_name',
                'created_at',
                'updated_at',
            ],
            $columns
        );
        self::assertNotContains( 'national_id', $columns, 'cpms_patient_logins must never store national_id' );

        $stats   = $db->fetchAll(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [ $table ]
        );
        $indexes = [];
        foreach ( $stats as $row ) {
            $name               = (string) $row['INDEX_NAME'];
            $indexes[ $name ][] = [
                'non_unique' => (int) $row['NON_UNIQUE'],
                'column'     => (string) $row['COLUMN_NAME'],
            ];
        }

        self::assertArrayHasKey( 'PRIMARY', $indexes );
        self::assertSame( [ [ 'non_unique' => 0, 'column' => 'id' ] ], $indexes['PRIMARY'] );

        self::assertArrayHasKey( 'u_pl_mobile', $indexes );
        self::assertSame( [ [ 'non_unique' => 0, 'column' => 'mobile' ] ], $indexes['u_pl_mobile'] );

        self::assertArrayHasKey( 'u_pl_bind', $indexes );
        self::assertSame(
            [
                [ 'non_unique' => 0, 'column' => 'wp_user_id' ],
                [ 'non_unique' => 0, 'column' => 'clinic_id' ],
                [ 'non_unique' => 0, 'column' => 'patient_id' ],
            ],
            $indexes['u_pl_bind']
        );

        self::assertArrayHasKey( 'u_pl_live', $indexes );
        self::assertSame( [ [ 'non_unique' => 0, 'column' => 'live_key' ] ], $indexes['u_pl_live'] );

        self::assertArrayHasKey( 'u_pl_account', $indexes );
        self::assertSame( [ [ 'non_unique' => 0, 'column' => 'account_key' ] ], $indexes['u_pl_account'] );

        self::assertArrayHasKey( 'idx_pl_kind_state_user', $indexes );
        self::assertSame(
            [
                [ 'non_unique' => 1, 'column' => 'kind' ],
                [ 'non_unique' => 1, 'column' => 'state' ],
                [ 'non_unique' => 1, 'column' => 'wp_user_id' ],
            ],
            $indexes['idx_pl_kind_state_user']
        );

        $fkCount = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [ $table ]
        );
        self::assertSame( 0, $fkCount );
    }

    public function testUpgradeFrom0031PreservesExistingTablesAndRecordsLedger(): void
    {
        $db = App::db();

        $clinicsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' ) );

        $applied = $this->withRealTables(
            static function () use ( $db ): array {
                $db->query( 'DROP TABLE IF EXISTS ' . $db->table( self::TABLE ) );
                $db->query(
                    'DELETE FROM ' . $db->table( 'cpms_schema_migrations' ) . ' WHERE version = %s',
                    [ self::VERSION ]
                );
                self::assertTrue( App::migrations()->has_pending_migrations() );

                return App::migrations()->migrate();
            }
        );

        self::assertSame( [ self::VERSION ], $applied );
        self::assertFalse( App::migrations()->has_pending_migrations() );
        self::assertSame( self::HEAD_VERSION, App::migrations()->currentVersion() );
        self::assertSame( $clinicsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' ) ) );
    }

    public function testUniqueKeysRejectDuplicatesAndAllowDistinctRows(): void
    {
        global $wpdb;

        $table = App::db()->table( self::TABLE );
        $now   = '2026-11-20 00:00:00.000';

        $countRows = static function () use ( $wpdb, $table ): int {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        };

        $assertDuplicateRejected = static function ( array $row, string $label ) use ( $wpdb, $table, $countRows ): void {
            $before = $countRows();
            $prev   = $wpdb->suppress_errors( true );
            try {
                $res = $wpdb->insert( $table, $row );
                self::assertFalse( $res, $label . ': duplicate insert must fail' );
                self::assertNotSame( '', (string) $wpdb->last_error, $label . ': DB error must be recorded' );
            } finally {
                $wpdb->suppress_errors( $prev );
            }
            self::assertSame( $before, $countRows(), $label . ': row count must be unchanged' );
        };

        // 1) u_pl_mobile (mobile) & u_pl_account (account_key) on account rows.
        $baseAccount = [
            'kind'                => 'account',
            'wp_user_id'          => 910001,
            'mobile'              => '09000000001',
            'state'               => 'active',
            'clinic_id'           => null,
            'patient_id'          => null,
            'verified_at'         => null,
            'verification_method' => null,
            'live_key'            => null,
            'account_key'         => 910001,
            'given_name'          => null,
            'family_name'         => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        self::assertSame( 1, $wpdb->insert( $table, $baseAccount ) );

        // Duplicate u_pl_mobile only (different account_key).
        $dupMobile                = $baseAccount;
        $dupMobile['wp_user_id']  = 910002;
        $dupMobile['account_key'] = 910002;
        $assertDuplicateRejected( $dupMobile, 'u_pl_mobile' );

        // Duplicate u_pl_account only (different mobile).
        $dupAccount           = $baseAccount;
        $dupAccount['mobile'] = '09000000002';
        $assertDuplicateRejected( $dupAccount, 'u_pl_account' );

        // Positive control: distinct mobile and distinct account_key succeeds.
        $okAccount                = $baseAccount;
        $okAccount['wp_user_id']  = 910002;
        $okAccount['mobile']      = '09000000002';
        $okAccount['account_key'] = 910002;
        self::assertSame( 1, $wpdb->insert( $table, $okAccount ) );

        // 2) u_pl_bind (wp_user_id, clinic_id, patient_id) & u_pl_live (live_key) on binding rows.
        $baseBinding = [
            'kind'                => 'binding',
            'wp_user_id'          => 910001,
            'mobile'              => null,
            'state'               => 'active',
            'clinic_id'           => 1,
            'patient_id'          => 70001,
            'verified_at'         => $now,
            'verification_method' => 'two_factor',
            'live_key'            => '1:70001',
            'account_key'         => null,
            'given_name'          => null,
            'family_name'         => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        self::assertSame( 1, $wpdb->insert( $table, $baseBinding ) );

        // Duplicate u_pl_bind only (closed row with live_key = NULL).
        $dupBind             = $baseBinding;
        $dupBind['state']    = 'closed';
        $dupBind['live_key'] = null;
        $assertDuplicateRejected( $dupBind, 'u_pl_bind' );

        // Duplicate u_pl_live only (different wp_user_id, same clinic_id:patient_id).
        $dupLive               = $baseBinding;
        $dupLive['wp_user_id'] = 910002;
        $assertDuplicateRejected( $dupLive, 'u_pl_live' );

        // Positive control: distinct (wp_user_id, clinic_id, patient_id) and live_key succeeds.
        $okBinding               = $baseBinding;
        $okBinding['wp_user_id'] = 910002;
        $okBinding['patient_id'] = 70002;
        $okBinding['live_key']   = '1:70002';
        self::assertSame( 1, $wpdb->insert( $table, $okBinding ) );
    }
}

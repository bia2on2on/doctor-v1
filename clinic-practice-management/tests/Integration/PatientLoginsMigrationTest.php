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
}

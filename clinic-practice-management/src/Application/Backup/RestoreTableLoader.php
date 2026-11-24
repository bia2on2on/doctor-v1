<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Backup;

use ClinicCore\Domain\Backup\RestoreDumpReader;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * بارگذاری بدون DDLِ dump در جدول‌های آمادهٔ خالی (design §5 سطر :101).
 *
 * هیچ DDLای اجرا نمی‌شود؛ تنها نوشتن‌ها `INSERT INTO` و یک `UPDATE` بستنِ
 * bindingها هستند. پیش‌شرط‌ها پیش از نخستین نوشتن اثبات می‌شوند (نسخهٔ schema،
 * حضور و خالی‌بودن جدول‌های دو طرف، ستون‌ها، sql_mode) و ترتیب از گراف FK
 * واقعی می‌آید (cycle = Refuse). بدون DDL هیچ implicit commit رخ نمی‌دهد
 * (https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html)، پس شکست
 * میانی با ROLLBACK به «صفر ردیف» برمی‌گردد. به مسیر تولیدی وصل نیست.
 */
final class RestoreTableLoader {
    /** درج نمی‌شوند و از قاعدهٔ خالی مستثنا هستند (نام کوتاه). */
    public const SKIP_TABLES = [ 'schema_migrations', 'audit_seq' ];

    private const CHUNK_ROWS = 200;

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * پاس فقط‌خواندنی؛ نسخهٔ schema از ledgerِ خودِ dump.
     *
     * @return array{source_version: string, tables: list<string>, columns: array<string, list<string>>, rows: array<string, int>, digest: string, order: list<string>}
     */
    public function preflight( RestoreDumpReader $reader, ?string $expected_version = null ): array {
        $digest      = $reader->digest();
        $dump_tables = $reader->tables();
        $columns     = [];
        $rows        = [];
        $source      = '';
        foreach ( $reader->inserts() as $batch ) {
            $table = $batch['table'];
            if ( ! in_array( $table, $dump_tables, true ) ) {
                throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'The dump inserts into a table it never declares.' );
            }
            if ( $this->short_name( $table ) === 'schema_migrations' ) {
                $source = $this->max_version( $source, $batch );
                continue;
            }
            // Census of loaded rows only: the ledger is read, never inserted.
            $rows[ $table ] = ( $rows[ $table ] ?? 0 ) + count( $batch['rows'] );
            if ( isset( $columns[ $table ] ) && $columns[ $table ] !== $batch['columns'] ) {
                throw BackupException::of( 'RESTORE_COLUMN_MISMATCH', 'The dump declares two different column sets for one table.' );
            }
            $columns[ $table ] = $batch['columns'];
        }

        return [
            'source_version' => $source,
            'tables'         => $dump_tables,
            'columns'        => $columns,
            'rows'           => $rows,
            'digest'         => $digest,
            'order'          => $this->assert_target_ready( $dump_tables, $source, $columns, $expected_version ),
        ];
    }

    /**
     * بارگذاری در یک تراکنش (پیش‌شرط‌ها دوباره پیش از نخستین نوشتن بررسی می‌شوند).
     * @param array{source_version: string, tables: list<string>, columns: array<string, list<string>>, rows: array<string, int>, digest: string, order: list<string>} $plan
     * @return array{tables: int, rows: int, bindings_closed: int}
     */
    public function load( array $plan, RestoreDumpReader $reader ): array {
        $counts          = [];
        $rows            = 0;
        $bindings_closed = 0;
        $strict          = $this->db->is_strict();
        $this->db->setStrict( true );
        // Value-bearing SQL never reaches display/log: suppression is restored in finally.
        $show_errors     = $this->db->wpdb()->show_errors( false );
        $suppress_errors = $this->db->wpdb()->suppress_errors( true );
        try {
            // The plan belongs to specific bytes: a different dump with the same columns must not load.
            if ( ( $plan['digest'] ?? '' ) === '' || $reader->digest() !== $plan['digest'] ) {
                throw BackupException::of( 'RESTORE_DUMP_CHANGED', 'The dump is not the one this plan was built from.' );
            }
            if ( $this->assert_target_ready( $plan['tables'], $plan['source_version'], $plan['columns'] ) !== $plan['order'] ) {
                throw BackupException::of( 'RESTORE_FK_ORDER_CHANGED', 'The target foreign-key graph changed after preflight.' );
            }
            $this->db->transactional(
                function () use ( $reader, $plan, &$counts, &$rows, &$bindings_closed ): void {
                    // Parent tables first: the order comes from the live FK graph.
                    foreach ( $plan['order'] as $table ) {
                        if ( ! isset( $plan['columns'][ $table ] ) ) {
                            continue; // Declared but empty: no INSERT, no extra dump pass.
                        }
                        foreach ( $reader->inserts() as $batch ) {
                            if ( $batch['table'] !== $table ) {
                                continue;
                            }
                            if ( ( $plan['columns'][ $table ] ?? null ) !== $batch['columns'] ) {
                                throw BackupException::of( 'RESTORE_COLUMN_MISMATCH', 'The dump no longer matches the preflight column census.' );
                            }
                            $rows            += $this->insert_batch( $table, $batch['columns'], $batch['rows'] );
                            $counts[ $table ] = ( $counts[ $table ] ?? 0 ) + count( $batch['rows'] );
                        }
                    }
                    // Every planned table must deliver exactly the rows preflight counted.
                    foreach ( $plan['rows'] ?? [] as $table => $expected ) {
                        if ( ( $counts[ $table ] ?? 0 ) !== $expected ) {
                            throw BackupException::of( 'RESTORE_LOAD_INCOMPLETE', 'The dump lost rows between preflight and load.' );
                        }
                    }
                    $bindings_closed = $this->close_bindings();
                }
            );
        } catch ( BackupException $error ) {
            $this->clear_last_query();

            throw $error;
        } catch ( \Throwable $error ) {
            $this->clear_last_query();

            // Never surface driver text: the barrier/UI boundary owns messaging. A failed
            // transaction control leaves the rows' fate unproven, so no rollback is claimed.
            if ( str_starts_with( $error->getMessage(), 'transaction ' ) ) {
                throw BackupException::of( 'RESTORE_LOAD_UNKNOWN_STATE', 'The restore load failed and the transaction state is unknown; reconciliation is required.' );
            }

            throw BackupException::of( 'RESTORE_LOAD_FAILED', 'The restore load failed and was rolled back.' );
        } finally {
            $this->db->setStrict( $strict );
            $this->db->wpdb()->show_errors( $show_errors );
            $this->db->wpdb()->suppress_errors( $suppress_errors );
        }

        return [ 'tables' => count( $counts ), 'rows' => $rows, 'bindings_closed' => $bindings_closed ];
    }

    /** آخرین کوئری/خطای wpdb (که می‌تواند مقدارِ ردیف را در خود داشته باشد) پاک می‌شود. */
    private function clear_last_query(): void {
        $this->db->wpdb()->last_query = '';
        $this->db->wpdb()->last_error = '';
    }

    /** @param list<array<string, string|null>> $rows */
    private function insert_batch( string $table, array $columns, array $rows ): int {
        foreach ( array_chunk( $rows, self::CHUNK_ROWS ) as $chunk ) {
            $values       = [];
            $placeholders = [];
            foreach ( $chunk as $row ) {
                $tuple = [];
                foreach ( $columns as $column ) {
                    if ( null === $row[ $column ] ) {
                        $tuple[] = 'NULL'; // Literal NULL; every value stays a %s placeholder.
                        continue;
                    }
                    $tuple[]  = '%s';
                    $values[] = $row[ $column ];
                }
                $placeholders[] = '(' . implode( ',', $tuple ) . ')';
            }
            $sql    = 'INSERT INTO %i (' . implode( ',', array_fill( 0, count( $columns ), '%i' ) ) . ') VALUES ' . implode( ',', $placeholders );
            $params = array_merge( [ $this->db->table( $this->short_name( $table ) ) ], $columns, $values );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from %i identifiers and %s values only.
            if ( ! $this->db->query( $sql, $params ) ) {
                throw BackupException::of( 'RESTORE_LOAD_FAILED', 'A restore INSERT was refused.' );
            }
        }

        return count( $rows );
    }

    /** بستنِ همهٔ bindingها با حفظ account و hash (`patient-identity-login-design.md:65-66,79`). */
    private function close_bindings(): int {
        return $this->db->execute(
            'UPDATE %i SET state = %s, live_key = NULL, account_key = CASE WHEN kind = %s THEN wp_user_id ELSE NULL END',
            [ $this->db->table( 'patient_logins' ), 'closed', 'account' ]
        );
    }

    /**
     * @param list<string>                $dump_tables
     * @param array<string, list<string>> $tables ستون‌های dump هر جدول
     * @return list<string> ترتیب بارگذاری (والد قبل از فرزند)
     */
    private function assert_target_ready( array $dump_tables, string $source_version, array $tables, ?string $expected_version = null ): array {
        if ( '' === $source_version || [] === $dump_tables ) {
            throw BackupException::of( 'RESTORE_SCHEMA_MISMATCH', 'The dump declares no usable DB schema version or tables.' );
        }
        $target = $this->target_tables();
        if ( $source_version !== $this->ledger_version( $target ) ) {
            throw BackupException::of( 'RESTORE_SCHEMA_MISMATCH', 'The backup and target DB migration versions differ.' );
        }
        // Proven after the whole target census, so no refusal depends on the caller.
        if ( null !== $expected_version && $expected_version !== $source_version ) {
            throw BackupException::of( 'RESTORE_SCHEMA_MISMATCH', 'The dump ledger does not match the expected backup schema version.' );
        }
        // The dump escapes with backslashes; a server ignoring them stores other bytes.
        if ( str_contains( strtoupper( (string) $this->db->fetchValue( 'SELECT @@SESSION.sql_mode' ) ), 'NO_BACKSLASH_ESCAPES' ) ) {
            throw BackupException::of( 'RESTORE_SQL_MODE_UNSUPPORTED', 'NO_BACKSLASH_ESCAPES cannot restore this dump.' );
        }

        $loadable = [];
        foreach ( $dump_tables as $table ) {
            if ( $this->skipped( $table ) ) {
                continue;
            }
            if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) || ! in_array( $table, $target, true ) ) {
                throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'A dumped table is unsupported or missing in the target install.' );
            }
            $loadable[] = $table;
        }
        foreach ( $target as $table ) {
            if ( ! $this->skipped( $table ) && ! in_array( $table, $dump_tables, true ) ) {
                throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'A target table is missing from the dump.' );
            }
        }
        foreach ( $tables as $table => $columns ) {
            $known = $this->target_columns( $table );
            foreach ( $columns as $column ) {
                if ( ! in_array( $column, $known, true ) ) {
                    throw BackupException::of( 'RESTORE_COLUMN_MISMATCH', 'A dumped column does not exist in the target schema.' );
                }
            }
        }
        foreach ( $loadable as $table ) {
            if ( [] !== $this->db->fetch_all_strict( 'SELECT 1 FROM %i LIMIT 1', [ $this->db->table( $this->short_name( $table ) ) ] ) ) {
                throw BackupException::of( 'RESTORE_TARGET_NOT_EMPTY', 'Restore requires a prepared empty target.' );
            }
        }

        $this->assert_engines_transactional( $loadable );

        return $this->foreign_key_order( $loadable );
    }

    /**
     * هر جدولی که load() در آن می‌نویسد باید InnoDB باشد؛ در غیر این صورت rollback
     * ناقص می‌شود و «صفر ردیف» دروغ است (nontransactional-tables).
     *
     * @param list<string> $loadable
     */
    private function assert_engines_transactional( array $loadable ): void {
        $tables   = $loadable;
        $tables[] = $this->db->table( 'patient_logins' );
        $rows     = $this->db->fetch_all_strict(
            'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')',
            $tables
        );
        $this->verify_engines( $tables, $rows );
    }

    /**
     * غایب = Refuse؛ هر موتور جز InnoDB (بی‌توجه به بزرگی/کوچکی حروف) = Refuse.
     *
     * @param list<string>               $tables
     * @param list<array<string, mixed>> $rows
     */
    private function verify_engines( array $tables, array $rows ): void {
        $engines = [];
        foreach ( $rows as $row ) {
            // Fold the row's own keys: a server may answer TABLE_NAME/ENGINE whatever the alias case.
            $folded = array_change_key_case( $row, CASE_LOWER );
            $engines[ strtolower( (string) ( $folded['table_name'] ?? '' ) ) ] = $folded['engine'] ?? null;
        }
        foreach ( $tables as $table ) {
            $key = strtolower( $table );
            if ( ! array_key_exists( $key, $engines ) ) {
                throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'A table the load writes is missing from the target schema.' );
            }
            if ( ! is_string( $engines[ $key ] ) || 0 !== strcasecmp( $engines[ $key ], 'InnoDB' ) ) {
                throw BackupException::of( 'RESTORE_ENGINE_UNSUPPORTED', 'Only InnoDB targets can be restored atomically.' );
            }
        }
    }

    /** @return list<string> ستون‌های واقعی یک جدول هدف. */
    private function target_columns( string $table ): array {
        $rows    = $this->db->fetch_all_strict(
            'SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s',
            [ $table ]
        );
        $columns = [];
        foreach ( $rows as $row ) {
            $columns[] = (string) $row['column_name'];
        }

        return $columns;
    }

    /**
     * @return list<string> نام کامل جدول‌های CPMS در هدف (شامل ledger)
     */
    private function target_tables(): array {
        $rows   = $this->db->fetch_all_strict(
            'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            [ $this->db->esc_like( $this->db->table( '' ) ) . '%' ]
        );
        $tables = [];
        foreach ( $rows as $row ) {
            $name = (string) $row['table_name'];
            if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) || null === $this->short_or_null( $name ) ) {
                throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'Unsupported target table name.' );
            }
            $tables[] = $name;
        }
        sort( $tables );

        return $tables;
    }

    /**
     * @param list<string> $target
     */
    private function ledger_version( array $target ): string {
        $ledger = $this->db->table( 'schema_migrations' );
        if ( ! in_array( $ledger, $target, true ) ) {
            throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'The target has no migration ledger.' );
        }
        $row = $this->db->fetch_all_strict( 'SELECT MAX(version) AS version FROM %i', [ $ledger ] )[0] ?? null;

        return is_array( $row ) && is_string( $row['version'] ?? null ) ? (string) $row['version'] : '';
    }

    /** @param array{table: string, columns: list<string>, rows: list<array<string, string|null>>} $batch */
    private function max_version( string $source, array $batch ): string {
        if ( ! in_array( 'version', $batch['columns'], true ) ) {
            return $source;
        }
        foreach ( $batch['rows'] as $row ) {
            $version = (string) ( $row['version'] ?? '' );
            if ( '' !== $version && strcmp( $version, $source ) > 0 ) {
                $source = $version;
            }
        }

        return $source;
    }

    /**
     * ترتیب توپولوژیکِ قطعی از گراف FK؛ cycleِ بین‌جدولی = Refuse.
     * @param list<string> $loadable
     * @return list<string>
     */
    private function foreign_key_order( array $loadable ): array {
        $rows  = $this->db->fetch_all_strict(
            'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $edges = [];
        foreach ( $rows as $row ) {
            $child  = (string) $row['child'];
            $parent = (string) $row['parent'];
            if ( $child !== $parent && in_array( $child, $loadable, true ) && in_array( $parent, $loadable, true ) ) {
                $edges[] = [ $child, $parent ];
            }
        }

        $order    = [];
        $resolved = [];
        $pending  = $loadable;
        sort( $pending );
        while ( [] !== $pending ) {
            $progress = false;
            foreach ( $pending as $index => $table ) {
                foreach ( $edges as $edge ) {
                    if ( $edge[0] === $table && ! isset( $resolved[ $edge[1] ] ) ) {
                        continue 2;
                    }
                }
                $order[]            = $table;
                $resolved[ $table ] = true;
                unset( $pending[ $index ] );
                $progress = true;
            }
            if ( ! $progress ) {
                throw BackupException::of( 'RESTORE_FK_ORDER_UNRESOLVED', 'The target foreign-key graph has a cycle.' );
            }
        }

        return $order;
    }

    /** نام کوتاه `cpms_x` (ورودی `CpmsDb::table()`)، یا null بیرون از پیشوند. */
    private function short_or_null( string $table ): ?string {
        $prefix = $this->db->table( '' );

        return str_starts_with( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : null;
    }

    private function short_name( string $table ): string {
        $short = $this->short_or_null( $table );
        if ( null === $short ) {
            throw BackupException::of( 'RESTORE_TARGET_NOT_PREPARED', 'A table name is outside the CPMS prefix.' );
        }

        return $short;
    }

    private function skipped( string $table ): bool {
        return in_array( $this->short_or_null( $table ), self::SKIP_TABLES, true );
    }
}

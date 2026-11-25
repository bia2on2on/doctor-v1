<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Db;

use ClinicCore\Infrastructure\Profiling\RequestProfiler;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * wrapper نازک روی $wpdb — فقط نقطه‌ی واحد DB Access (NFR-MAINT-2).
 *
 * - همه Queryها با prepare (تثبیت NFR-SEC-3 — بدون Interpolation مقدار).
 * - transactional() برای عملیات حیاتی (رزرو، پرداخت، complete/checkout).
 *
 * Strict mode (F1-2): به‌طور پیش‌فرض خاموش (رفتار soft — خطای SQL →
 * false/0/NULL، مناسب مسیرهای عملیاتی که شکستشان نباید request را
 * بکِشد). با setStrict(true) هر خطای SQL بلافاصله RuntimeException
 * می‌دهد — MigrationRunner دورِ هر Migration آن را روشن می‌کند تا
 * Migration شکست‌خورده هرگز «موفق + version ثبت‌شده» به نظر نرسد.
 */
final class CpmsDb
{
    private bool $strict = false;

    /**
     * عمق تراکنش‌های بازِ همین اتصال (فقط برای transactional()). صفر = هیچ
     * تراکنش CpmsDb فعال نیست. مقدار همیشه در finally کاهش می‌یابد.
     */
    private int $tx_depth = 0;

    /**
     * Rollback generation of this connection: incremented whenever a
     * transaction (or nested savepoint) is rolled back, including the
     * rollback attempted after a failed COMMIT. Consumers caching
     * snapshots read inside a transaction (Settings) compare against
     * this value: a snapshot taken before the latest rollback may hold
     * values that were never committed and must not be trusted.
     */
    private int $rollback_epoch = 0;

    /**
     * Constant exception text for failures inside an NID guard: never contains
     * the driver message (which can embed the duplicate key value).
     */
    private const NID_SAFE_ERROR = 'Database error (national ID redacted).';

    /**
     * One NID-shaped token: ten or more digits joined only by the separators
     * the product validator accepts (hyphen, space, dot, slash, ZWNJ).
     */
    private const NID_TOKEN_PATTERN = '/[0-9۰-۹٠-٩](?:[0-9۰-۹٠-٩]|[-\/.\x{200C} ])*[0-9۰-۹٠-٩]/u';

    /**
     * Depth of active NID-diagnostic guards on this connection.
     *
     * @var int
     */
    private int $nid_guard_depth = 0;

    public function rollback_epoch(): int {
        return $this->rollback_epoch;
    }

    public function __construct(private readonly wpdb $wpdb, private string $prefix = 'cpms_')
    {
    }

    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    public function is_strict(): bool {
        return $this->strict;
    }

    /**
     * فقط در Strict mode: هر خطای SQLِ فراخوانیِ آخر باید Exception شود
     * (wpdb خطا را در last_error نگه می‌دارد و در موفقیت پاک می‌کند).
     */
    private function ensureNoSqlError(): void
    {
        if ($this->strict && $this->wpdb->last_error !== '') {
            throw new RuntimeException(
                $this->nid_guard_depth > 0 ? self::NID_SAFE_ERROR : 'SQL error: ' . $this->wpdb->last_error
            );
        }
    }

    /**
     * Run a DB operation carrying an NID-shaped value without publishing that
     * value through error_log, EZSQL_ERROR, wpdb's last/func_call/queries
     * diagnostics, or an exception message. Calls with no NID-shaped value are
     * passed through untouched.
     *
     * @template T
     *
     * @param callable(): T $operation DB operation to run.
     * @param array<mixed>  $values    SQL template and bound values to inspect.
     *
     * @return T
     */
    private function without_nid_diagnostics( callable $operation, array $values ) {
        if ( ! $this->has_nid_value( $values ) ) {
            return $operation();
        }

        global $EZSQL_ERROR;

        $has_suppress = method_exists( $this->wpdb, 'suppress_errors' );
        $has_show     = property_exists( $this->wpdb, 'show_errors' );
        $state        = [
            'suppress'      => $has_suppress ? $this->wpdb->suppress_errors( true ) : null,
            'show'          => $has_show ? (bool) $this->wpdb->show_errors : null,
            'ez_count'      => is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0,
            'queries_count' => is_array( $this->wpdb->queries ?? null ) ? count( $this->wpdb->queries ) : 0,
            'values'        => $values,
        ];

        if ( $has_show ) {
            $this->wpdb->show_errors = false;
        }
        ++$this->nid_guard_depth;
        $failed = false;
        try {
            return $operation();
        } catch ( Throwable $error ) {
            $failed = true;
            throw $error;
        } finally {
            --$this->nid_guard_depth;
            try {
                $new_error = is_array( $EZSQL_ERROR ) && count( $EZSQL_ERROR ) > (int) $state['ez_count'];
                if ( 0 === $this->nid_guard_depth && ( $failed || $new_error ) ) {
                    $this->log_redacted_nid_failure( $state );
                }
                $this->scrub_nid_diagnostics( $state );
            } finally {
                if ( 0 === $this->nid_guard_depth ) {
                    if ( $has_suppress ) {
                        $this->wpdb->suppress_errors( (bool) $state['suppress'] );
                    }
                    if ( $has_show ) {
                        $this->wpdb->show_errors = (bool) $state['show'];
                    }
                }
            }
        }
    }

    /**
     * Emit ONE line for a FAILED guarded call, in the spirit of
     * wpdb::print_error(): caller, query and error, each already redacted by
     * the same rule as the guard. Nothing is logged for a successful call.
     *
     * @param array<string, mixed> $state Guard baseline.
     */
    private function log_redacted_nid_failure( array $state ): void {
        $query  = property_exists( $this->wpdb, 'last_query' ) ? (string) $this->wpdb->last_query : '';
        $error  = property_exists( $this->wpdb, 'last_error' ) ? (string) $this->wpdb->last_error : '';
        $caller = property_exists( $this->wpdb, 'func_call' ) ? (string) $this->wpdb->func_call : '';
        $parts  = [];

        foreach ( [ 'caller' => $caller, 'query' => $query, 'error' => $error ] as $label => $text ) {
            if ( '' === $text ) {
                continue;
            }
            $clean   = $this->redact_nid( $text, $state['values'] );
            $parts[] = $label . '=' . str_replace( [ "\r", "\n" ], ' ', $clean );
        }

        error_log( '[cpms-nid-db] failed guarded query ' . implode( ' ', $parts ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one redacted line replaces wpdb's value-bearing log.
    }

    /**
     * ASCII digits left after removing every non-digit, or null on PCRE error.
     */
    private function nid_digits( string $value ): ?string {
        $digits = preg_replace( '/\D/', '', $value );

        return null === $digits || PREG_NO_ERROR !== preg_last_error() ? null : $digits;
    }

    /**
     * Diagnostic spellings wpdb can emit for one bound string (WP 7.1.3).
     * Includes values already escaped by esc_like() (as LIKE terms are) and
     * raw values going through prepare() directly.
     *
     * @param string $value Bound candidate value.
     *
     * @return list<string>
     */
    private function wpdb_escaped_values( string $value ): array {
        if ( ! method_exists( $this->wpdb, '_real_escape' ) ) {
            return [];
        }

        $candidates = [ $value ];
        if ( method_exists( $this->wpdb, 'esc_like' ) ) {
            $candidates[] = $this->wpdb->esc_like( $value );
        }

        $spellings = [];
        foreach ( $candidates as $candidate ) {
            $escaped = $this->wpdb->_real_escape( $candidate );
            if ( method_exists( $this->wpdb, 'remove_placeholder_escape' ) ) {
                $escaped = $this->wpdb->remove_placeholder_escape( $escaped );
            }
            if ( is_string( $escaped ) && '' !== $escaped ) {
                $spellings[] = $escaped;
            }
        }

        return array_values( array_unique( $spellings ) );
    }

    /**
     * Whether any bound value carries ten or more digits (the NID shape).
     * The first top-level slot is the SQL template/table and is scanned only
     * with the contiguous-token pattern.
     *
     * @param array<mixed> $values       Values to inspect.
     * @param bool         $template_slot Whether the first slot is a template.
     */
    private function has_nid_value( array $values, bool $template_slot = true ): bool {
        $first = true;
        foreach ( $values as $value ) {
            $is_template = $first && $template_slot;
            $first       = false;
            if ( is_array( $value ) ) {
                if ( $this->has_nid_value( $value, false ) ) {
                    return true;
                }
                continue;
            }
            if ( is_string( $value ) ) {
                if ( ! $is_template ) {
                    $digits = $this->nid_digits( $value );
                    if ( null === $digits || strlen( $digits ) >= 10 ) {
                        return true;
                    }
                }
                if ( $this->redact_nid_text( $value ) !== $value ) {
                    return true;
                }
            } elseif ( is_int( $value ) && ! $is_template && strlen( (string) $value ) >= 10 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the exact candidate values first, then every NID-shaped digit run.
     *
     * @param string       $text   Diagnostic text to clean.
     * @param array<mixed> $values Candidate NID-bearing values.
     */
    private function redact_nid( string $text, array $values, bool $template_slot = true ): string {
        $first = true;
        foreach ( $values as $value ) {
            $is_template = $first && $template_slot;
            $first       = false;
            if ( is_array( $value ) ) {
                $text = $this->redact_nid( $text, $value, false );
                continue;
            }
            if ( is_string( $value ) && $value !== '' && ! $is_template ) {
                $digits = $this->nid_digits( $value );
                if ( null === $digits ) {
                    return '[nid-redacted]';
                }
                if ( strlen( $digits ) >= 10 ) {
                    $needles = array_merge( [ $value, $digits ], $this->wpdb_escaped_values( $value ) );
                    usort(
                        $needles,
                        static function ( string $left, string $right ): int {
                            return strlen( $right ) <=> strlen( $left );
                        }
                    );
                    $text = str_replace( $needles, '[nid-redacted]', $text );
                }
            } elseif ( is_int( $value ) && ! $is_template && strlen( (string) $value ) >= 10 ) {
                $text = str_replace( (string) $value, '[nid-redacted]', $text );
            }
        }

        return $this->redact_nid_text( $text );
    }

    /**
     * Replace only NID-shaped tokens: digits with the separator forms the
     * validator can accept, or ten or more raw ASCII digits. Used by both the
     * guard trigger and the diagnostic redactor so they cannot disagree.
     */
    private function redact_nid_text( string $text ): string {
        if ( PREG_NO_ERROR !== preg_last_error() ) {
            return '[nid-redacted]';
        }

        $redacted = preg_replace_callback(
            self::NID_TOKEN_PATTERN,
            static function ( array $token ): string {
                $count = preg_match_all( '/[0-9۰-۹٠-٩]/u', $token[0] );

                return false === $count || PREG_NO_ERROR !== preg_last_error() || $count >= 10
                    ? '[nid-redacted]'
                    : $token[0];
            },
            $text
        );

        return null === $redacted || PREG_NO_ERROR !== preg_last_error() ? '[nid-redacted]' : $redacted;
    }

    /**
     * Clean only the diagnostics written by the guarded call. The EZSQL_ERROR
     * row is kept (redacted), not dropped: the test-only SQL failure ledger
     * reads its last row plus a non-empty last_error at the next query.
     *
     * @param array<string, mixed> $state Guard baseline.
     */
    private function scrub_nid_diagnostics( array $state ): void {
        global $EZSQL_ERROR;

        if ( is_array( $EZSQL_ERROR ) ) {
            $count = count( $EZSQL_ERROR );
            for ( $i = (int) $state['ez_count']; $i < $count; ++$i ) {
                if ( ! is_array( $EZSQL_ERROR[ $i ] ) ) {
                    continue;
                }
                foreach ( [ 'query', 'error_str' ] as $key ) {
                    if ( isset( $EZSQL_ERROR[ $i ][ $key ] ) && is_string( $EZSQL_ERROR[ $i ][ $key ] ) ) {
                        $EZSQL_ERROR[ $i ][ $key ] = $this->redact_nid( $EZSQL_ERROR[ $i ][ $key ], $state['values'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- redact one core diagnostic row in place; the global itself is never replaced.
                    }
                }
            }
        }

        foreach ( [ 'last_query', 'last_error', 'func_call' ] as $property ) {
            if ( property_exists( $this->wpdb, $property ) ) {
                $this->wpdb->{$property} = $this->redact_nid( (string) $this->wpdb->{$property}, $state['values'] );
            }
        }
        if ( property_exists( $this->wpdb, 'last_result' ) ) {
            $this->wpdb->last_result = [];
        }
        if ( property_exists( $this->wpdb, 'queries' ) && is_array( $this->wpdb->queries ) ) {
            array_splice( $this->wpdb->queries, (int) $state['queries_count'] );
        }
    }

    /**
     * نام فیزیکی جدول: `{wp_prefix}cpms_{name}`.
     *
     * پیشوند `cpms_` بخشی از قرارداد مستند (ERD/SRS/دیتادیکشنری) است و همیشه
     * در نام نهایی حفظ می‌شود؛ str_replace فقط تحمل فراخوانی با/بدون پیشوند
     * را می‌دهد (table('cpms_patients') === table('patients')).
     */
    public function table(string $short): string
    {
        $name = preg_replace('/^' . preg_quote($this->prefix, '/') . '/', '', $short);

        return $this->wpdb->prefix . $this->prefix . $name;
    }

    public function dbPrefix(): string
    {
        return $this->wpdb->prefix;
    }

    /**
     * Escape LIKE metacharacters (`\\`, `%`, `_`) using WordPress core, so
     * request input is matched literally inside a LIKE pattern. LIKE operands
     * built from request input must pass through this; server-generated
     * constant prefixes need not.
     */
    public function esc_like( string $text ): string {
        return $this->wpdb->esc_like( $text );
    }

    /** Count CPMS tables using the active WordPress database prefix. */
    public function count_cpms_tables(): int {
        $prefix_pattern = $this->wpdb->esc_like( $this->wpdb->prefix . $this->prefix ) . '%';

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            [ $prefix_pattern ]
        );
    }

    public function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }

        return $this->wpdb->prepare($sql, ...array_values($params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function query( string $sql, array $params = [] ): bool {
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ): bool {
                return $this->query_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function query_unguarded( string $sql, array $params = [] ): bool {
        // Phase 17 profiling: observed only when the server-side profiler is armed
        // (one memoized check otherwise); counted even on SQL error, matching
        // `$wpdb->num_queries` semantics.
        $profile_start = RequestProfiler::query_start();
        $ok = $this->wpdb->query($this->prepare($sql, $params)) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $ok;
    }

    /**
     * اجرای نوشتاری با برگرداندن تعداد سطرهای اثرگرفته (برخلاف query که bool است).
     * خطای SQL → 0 (رفتار soft؛ برای لاگ/پاک‌سازی‌های دوره‌ای کافی است) —
     * مگر در Strict mode که Exception می‌دهد.
     */
    public function execute( string $sql, array $params = [] ): int {
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ): int {
                return $this->execute_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function execute_unguarded( string $sql, array $params = [] ): int {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->query($this->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return is_int($result) ? $result : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchRow( string $sql, array $params = [] ): ?array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established public API; renaming would break callers.
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ): ?array {
                return $this->fetch_row_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function fetch_row_unguarded( string $sql, array $params = [] ): ?array {
        $profile_start = RequestProfiler::query_start();
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll( string $sql, array $params = [] ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established public API; renaming would break callers.
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ): array {
                return $this->fetch_all_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function fetch_all_unguarded( string $sql, array $params = [] ): array {
        $profile_start = RequestProfiler::query_start();
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return is_array($rows) ? array_map(static fn ($r) => (array) $r, $rows) : [];
    }

    /**
     * Fetch rows while distinguishing an empty result from a failed query.
     * Unlike the ordinary soft-mode reader, this method always throws on a
     * database error so callers can fail closed without treating it as absence.
     *
     * @return list<array<string, mixed>>
     */
    public function fetch_all_strict( string $sql, array $params = [] ): array {
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ): array {
                return $this->fetch_all_strict_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function fetch_all_strict_unguarded( string $sql, array $params = [] ): array {
        $profile_start = RequestProfiler::query_start();
        $rows          = $this->wpdb->get_results( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );

        if ( $this->wpdb->last_error !== '' || ! is_array( $rows ) ) {
            throw new RuntimeException( 'Database read failed.' );
        }

        return array_map( static fn ( $row ) => (array) $row, $rows );
    }

    public function fetchValue( string $sql, array $params = [] ): mixed { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established public API; renaming would break callers.
        return $this->without_nid_diagnostics(
            function () use ( $sql, $params ) {
                return $this->fetch_value_unguarded( $sql, $params );
            },
            array_merge( [ $sql ], $params )
        );
    }

    private function fetch_value_unguarded( string $sql, array $params = [] ): mixed {
        $profile_start = RequestProfiler::query_start();
        $value = $this->wpdb->get_var($this->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert( string $table, array $data ): bool {
        return $this->without_nid_diagnostics(
            function () use ( $table, $data ): bool {
                return $this->insert_unguarded( $table, $data );
            },
            array_merge( [ $table ], array_values( $data ) )
        );
    }

    private function insert_unguarded( string $table, array $data ): bool {
        // wpdb::insert در موفقیت int (تعداد ردیف) و در خطا false برمی‌گرداند —
        // نه bool؛ بدون این نرمال‌سازی هر insert موفق TypeError می‌داد.
        $profile_start = RequestProfiler::query_start();
        $ok = $this->wpdb->insert($this->table($table), $data) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $ok;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update( string $table, array $data, array $where, bool $throw_on_error = false ): int {
        return $this->without_nid_diagnostics(
            function () use ( $table, $data, $where, $throw_on_error ): int {
                return $this->update_unguarded( $table, $data, $where, $throw_on_error );
            },
            array_merge( [ $table ], array_values( $data ), array_values( $where ) )
        );
    }

    private function update_unguarded( string $table, array $data, array $where, bool $throw_on_error = false ): int {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->update(
            $this->table($table),
            $data,
            $where,
            array_fill(0, count($data), '%s'),
            array_fill(0, count($where), '%s')
        );
        RequestProfiler::query_end( $profile_start );
        if ( false === $result && $throw_on_error ) {
            if ( $this->nid_guard_depth > 0 ) {
                throw new RuntimeException( self::NID_SAFE_ERROR );
            }
            $error = '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown error';
            throw new RuntimeException( 'Database update failed: ' . $error );
        }
        $this->ensureNoSqlError();

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete( string $table, array $where ): int {
        return $this->without_nid_diagnostics(
            function () use ( $table, $where ): int {
                return $this->delete_unguarded( $table, $where );
            },
            array_merge( [ $table ], array_values( $where ) )
        );
    }

    private function delete_unguarded( string $table, array $where ): int {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->delete(
            $this->table($table),
            $where,
            array_fill(0, count($where), '%s')
        );
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return (int) $result;
    }

    private function tx_control( string $sql ): void {
        $profile_start = RequestProfiler::query_start();
        $result        = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        if ( false === $result || '' !== $this->wpdb->last_error ) {
            $error = '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown error';
            throw new RuntimeException( 'transaction control failed (' . $sql . '): ' . $error );
        }
    }

    /**
     * Transaction + Row Lock انتخابی.
     *
     * در Test-Bootstrap، افعال تراکنش (با نشانگر cpms در ابتدای SQL) به
     * SAVEPOINT/RELEASE/ROLLBACK-TO بازنویسی می‌شوند تا داخل تراکنش بازِ
     * WP Test Suite بدون COMMIT ضمنی اجرا شوند (نشت Fixture بین تست‌ها).
     * رفتار Production بدون تغییر است — MySQL comment را نادیده می‌گیرد.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional( callable $callback ) {
        // F2-1 (atomicidade): فراخوانی تودرتو نباید COMMIT ضمنی روی تراکنش
        // بیرونی بزند. MySQL با START TRANSACTION تراکنش باز را COMMIT
        // می‌کند، پس هر فراخوانیِ تودرتو به SAVEPOINT تبدیل می‌شود: مرزِ
        // تراکنشِ سطحِ صفر همان بیرونی می‌ماند و شکستِ داخلی فقط تا همان
        // Savepoint عقب می‌رود. نامِ Savepoint با «nested_» از نام‌های
        // bootstrap تست جدا نگه داشته می‌شود.
        $savepoint = $this->tx_depth > 0 ? 'cpms_sp_nested_' . $this->tx_depth : null;

        if ( $savepoint === null ) {
            $this->tx_control( '/*cpms*/ START TRANSACTION' );
        } else {
            $this->tx_control( '/*cpms*/ SAVEPOINT ' . $savepoint );
        }
        ++$this->tx_depth;

        try {
            try {
                $result = $callback();
            } catch ( \Throwable $e ) {
                try {
                    $this->undo( $savepoint );
                } catch ( \Throwable $rollback_ex ) {
                    throw new RuntimeException( 'transaction rollback failed: ' . $rollback_ex->getMessage(), 0, $e );
                }
                throw $e;
            }
            try {
                if ( $savepoint === null ) {
                    $this->tx_control( '/*cpms*/ COMMIT' );
                } else {
                    $this->tx_control( '/*cpms*/ RELEASE SAVEPOINT ' . $savepoint );
                }
            } catch ( \Throwable $commit_ex ) {
                try {
                    $this->undo( $savepoint );
                } catch ( \Throwable $rollback_ex ) {
                    throw new RuntimeException( 'transaction commit failed and rollback also failed: ' . $rollback_ex->getMessage(), 0, $commit_ex );
                }
                throw $commit_ex;
            }

            return $result;
        } finally {
            --$this->tx_depth;
        }
    }

    /**
     * بازگردانی دقیق: در تراکنش سطح صفر ROLLBACK کامل، در تودرتو صرفاً
     * ROLLBACK TO SAVEPOINT + آزادسازی آن (تراکنش بیرونی دست‌نخورده می‌ماند).
     */
    private function undo( ?string $savepoint ): void {
        // Bump FIRST: even if the rollback statement itself fails, cached
        // snapshots from this transaction are untrustworthy from now on.
        ++$this->rollback_epoch;
        if ( $savepoint === null ) {
            $this->tx_control( '/*cpms*/ ROLLBACK' );

            return;
        }

        $this->tx_control( '/*cpms*/ ROLLBACK TO SAVEPOINT ' . $savepoint );
        $this->tx_control( '/*cpms*/ RELEASE SAVEPOINT ' . $savepoint );
    }

    /**
     * SELECT ... FOR UPDATE — برای Claim (Slot)، Invoice، Visit (Anti Double-Execute).
     *
     * @return array<string, mixed>|null
     */
    public function fetchRowForUpdate(string $sql, array $params = []): ?array
    {
        $sql = rtrim($sql, ';') . ' FOR UPDATE';

        return $this->fetchRow($sql, $params);
    }

    /**
     * اجرای یک SELECT به همراه FOR UPDATE برای قفل‌گذاری روی چندین ردیف.
     *
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAllForUpdate(string $sql, array $params = []): array
    {
        $sql = rtrim($sql, ';') . ' FOR UPDATE';

        return $this->fetchAll($sql, $params);
    }

    public function wpdb_last_insert_id(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    /**
     * Read-only transaction nesting depth (0 outside any `transactional()` boundary).
     */
    public function tx_depth(): int {
        return $this->tx_depth;
    }

    /**
     * NOW(3) UTC — مقدار را در PHP می‌سازیم تا مستقل از TZ سرور MySQL باشد (ADR-0013).
     */
    public function nowUtc(int $microSeconds = 0): string
    {
        $ts = microtime(true) + $microSeconds / 1_000_000;
        $dt = (new \DateTimeImmutable('@' . (string) (int) $ts))->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s') . sprintf('.%03d', (int) round(microtime(true) * 1000) % 1000);
    }

    public function nowUtcSql(): string
    {
        return gmdate('Y-m-d H:i:s', time()) . '.000';
    }
}

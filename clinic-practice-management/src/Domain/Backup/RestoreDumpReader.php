<?php

declare( strict_types=1 );

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- raw read-only stream I/O (fopen/fgets), as `LocalBackupVerifier.php:24-27`.

namespace ClinicCore\Domain\Backup;

use ClinicCore\Infrastructure\Backup\BackupException;
use Generator;

/**
 * خوانندهٔ فقط‌خواندنیِ dump تولیدشدهٔ `BackupSqlDumper`: Statement با «;» بیرون از
 * literal/comment تمام می‌شود و کامنت فقط در آغاز Statement مجاز است. تنها
 * `INSERT INTO` بستهٔ داده می‌شود و هر Statement یا escape ناشناخته = Refuse.
 */
final class RestoreDumpReader {
    private const MAX_STATEMENT_BYTES = 67108864;
    private const MARKER_PATTERN      = '/\A-- ---- table: ([A-Za-z0-9_]+) ----\z/';
    private const IGNORED_PREFIXES    = [ 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'SET', 'LOCK', 'UNLOCK', 'USE', 'START', 'BEGIN', 'COMMIT', 'ROLLBACK' ];

    private string $quote        = '';
    private bool $pending_escape = false;
    private int $scanned         = 0;
    private string $fingerprint  = '';

    public function __construct( private readonly string $path, private readonly int $max_statement_bytes = self::MAX_STATEMENT_BYTES ) {
    }

    /** @return list<string> نام جدول‌های اعلام‌شده (شامل جدول‌های بدون ردیف). */
    public function tables(): array {
        $tables = [];
        foreach ( $this->statements() as $statement ) {
            if ( ! str_starts_with( ltrim( $statement ), 'INSERT' ) && 1 === preg_match( self::MARKER_PATTERN, rtrim( $statement, "\r\n" ), $match ) ) {
                $tables[ $match[1] ] = true;
            }
        }

        return array_keys( $tables );
    }

    /**
     * @return Generator<int, array{table: string, columns: list<string>, rows: list<array<string, string|null>>}>
     */
    public function inserts(): Generator {
        foreach ( $this->statements() as $statement ) {
            $batch = $this->batch_of( $statement );
            if ( null !== $batch ) {
                yield $batch;
            }
        }
    }

    /** @return Generator<int, string> Statementهای کامل (یک Statement در بافر). */
    private function statements(): Generator {
        $handle = $this->open();
        $buffer = '';
        try {
            while ( true ) {
                $truncated = false;
                $line      = $this->read_line( $handle, $truncated );
                if ( null === $line ) {
                    break;
                }
                $buffer .= $line;
                foreach ( $this->take_statements( $buffer, $truncated ) as $statement ) {
                    if ( strlen( $statement ) > $this->max_statement_bytes ) {
                        throw BackupException::of( 'RESTORE_DUMP_SIZE_EXCEEDED', 'A dump statement exceeds the bounded reader size.' );
                    }
                    yield $statement;
                }
                if ( strlen( $buffer ) > $this->max_statement_bytes ) {
                    throw BackupException::of( 'RESTORE_DUMP_SIZE_EXCEEDED', 'A dump statement exceeds the bounded reader size.' );
                }
            }
            if ( '' !== trim( $buffer ) ) {
                throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'The dump ends inside an unterminated statement.' );
            }
        } finally {
            fclose( $handle );
        }
    }

    /**
     * یک خط با سقفِ مجاز؛ falseِ fgets با feof=false خطای خواندن است، نه پایان فایل.
     *
     * @param resource $handle
     */
    private function read_line( $handle, bool &$truncated = false ): ?string {
        $line      = fgets( $handle, $this->max_statement_bytes + 1 );
        $truncated = false !== $line && strlen( $line ) === $this->max_statement_bytes && ! str_ends_with( $line, "\n" );
        if ( false === $line && ! feof( $handle ) ) {
            throw BackupException::of( 'RESTORE_DUMP_UNREADABLE', 'The restore dump could not be read to the end.' );
        }

        return false === $line ? null : $line;
    }

    /** اثرانگشتِ فقط‌خواندنیِ محتوا (همان SHA-256 مسیرِ assert_same_file) — پیش از نخستین پاس. */
    public function digest(): string {
        $current = hash_file( 'sha256', $this->path );
        if ( false === $current ) {
            throw BackupException::of( 'RESTORE_DUMP_UNREADABLE', 'The restore dump is not readable.' );
        }

        return $current;
    }

    private function assert_same_file(): void {
        $current = hash_file( 'sha256', $this->path );
        if ( false === $current ) {
            throw BackupException::of( 'RESTORE_DUMP_UNREADABLE', 'The restore dump is not readable.' );
        }
        if ( '' !== $this->fingerprint && $current !== $this->fingerprint ) {
            throw BackupException::of( 'RESTORE_DUMP_CHANGED', 'The dump changed between read passes.' );
        }
        $this->fingerprint = $current;
    }

    /** @return resource */
    private function open() {
        $this->quote          = '';
        $this->pending_escape = false;
        $this->scanned        = 0;
        if ( ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
            throw BackupException::of( 'RESTORE_DUMP_UNREADABLE', 'The restore dump is not readable.' );
        }
        $this->assert_same_file();
        $handle = @fopen( $this->path, 'rb' );
        if ( false === $handle ) {
            throw BackupException::of( 'RESTORE_DUMP_UNREADABLE', 'The restore dump is not readable.' );
        }

        return $handle;
    }

    /**
     * بایت‌های ناتمام در بافر می‌مانند؛ بافر به‌مرجع کوتاه می‌شود و اسکن از همان‌جا ادامه می‌یابد.
     *
     * @param string $buffer باقی‌ماندهٔ Statement ناتمام
     *
     * @return list<string>
     */
    private function take_statements( string &$buffer, bool $truncated = false ): array {
        $statements = [];
        $start      = 0;
        $length     = strlen( $buffer );
        $to_eof     = 0;
        for ( $index = $this->scanned; $index < $length; ++$index ) {
            if ( '' !== $this->quote ) {
                if ( $this->pending_escape ) {
                    $this->pending_escape = false;
                } elseif ( '\\' === $buffer[ $index ] ) {
                    $this->pending_escape = true;
                } elseif ( $buffer[ $index ] === $this->quote ) {
                    $this->quote = '';
                }
                continue;
            }
            [ $kind, $end ] = $this->boundary_at( $buffer, $index );
            if ( 'quote' === $kind ) {
                $this->quote = $buffer[ $index ];
                continue;
            }
            if ( 'none' === $kind ) {
                continue;
            }
            $statements[] = substr( $buffer, $start, $index - $start );
            if ( 'comment' === $kind ) {
                $statements[] = substr( $buffer, $index, $end - $index - 1 );
            }
            if ( 'comment_eof' === $kind ) {
                // A line cut by the read bound is not an EOF comment: refuse instead of clearing it.
                if ( $truncated ) {
                    throw BackupException::of( 'RESTORE_DUMP_SIZE_EXCEEDED', 'A dump comment exceeds the bounded reader size.' );
                }
                $statements[] = substr( $buffer, $index );
                $buffer       = '';
                $to_eof       = $end;
                break;
            }
            $start = $end;
            $index = $end - 1;
        }
        if ( 0 !== $to_eof ) {
            $this->scanned = 0;

            return array_values( array_filter( array_map( 'trim', $statements ), static fn ( string $sql ): bool => '' !== $sql ) );
        }

        // Every byte was classified, so the next pass resumes at the remainder
        // (fgets yields whole lines: no rescan, no quadratic reread).
        $this->scanned = $length - $start;

        $buffer = substr( $buffer, $start );

        return array_values( array_filter( array_map( 'trim', $statements ), static fn ( string $sql ): bool => '' !== $sql ) );
    }

    /** @return array{0: string, 1: int} مرز این بایت: terminator/quote/comment/none. */
    private function boundary_at( string $buffer, int $index ): array {
        $char = $buffer[ $index ];
        if ( ';' === $char ) {
            return [ 'terminator', $index + 1 ];
        }
        if ( "'" === $char || '"' === $char ) {
            return [ 'quote', $index ];
        }
        $comment = '#' === $char
            || ( '-' === $char && '-' === ( $buffer[ $index + 1 ] ?? '' ) && ( '' === ( $buffer[ $index + 2 ] ?? '' ) || ctype_space( $buffer[ $index + 2 ] ) ) );
        if ( ! $comment ) {
            return [ 'none', $index ];
        }
        if ( ! $this->prefix_is_blank( $buffer, $index ) ) {
            throw BackupException::of( 'RESTORE_DUMP_UNSUPPORTED_STATEMENT', 'A comment appears inside a statement.' );
        }
        $newline = strpos( $buffer, "\n", $index );

        return false === $newline ? [ 'comment_eof', strlen( $buffer ) ] : [ 'comment', $newline + 1 ];
    }

    private function prefix_is_blank( string $buffer, int $index ): bool {
        for ( $cursor = $index - 1; $cursor >= 0; --$cursor ) {
            if ( "\n" === $buffer[ $cursor ] ) {
                return true;
            }
            if ( ! ctype_space( $buffer[ $cursor ] ) ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{table: string, columns: list<string>, rows: list<array<string, string|null>>}|null */
    private function batch_of( string $statement ): ?array {
        $sql = trim( $statement );
        if ( '' === $sql || str_starts_with( $sql, '--' ) || str_starts_with( $sql, '#' ) ) {
            return null;
        }
        if ( 1 === preg_match( '/\AINSERT INTO `([A-Za-z0-9_]+)` \(([^()]*)\) VALUES\s*(.+)\z/s', $sql, $match ) ) {
            $columns = $this->columns_of( $match[2] );

            return [ 'table' => $match[1], 'columns' => $columns, 'rows' => $this->rows_of( $match[3], $columns ) ];
        }
        $keyword = strtoupper( (string) strtok( $sql, " \t\r\n(" ) );
        if ( in_array( $keyword, self::IGNORED_PREFIXES, true ) ) {
            return null;
        }

        throw BackupException::of( 'RESTORE_DUMP_UNSUPPORTED_STATEMENT', 'The dump contains an unsupported statement kind.' );
    }

    /** @return list<string> */
    private function columns_of( string $columns_sql ): array {
        $found = preg_match_all( '/`([A-Za-z0-9_]+)`/', $columns_sql, $matches );
        if ( false === $found || 0 === $found ) {
            throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump INSERT has no usable column list.' );
        }

        return array_values( $matches[1] );
    }

    /** @param list<string> $columns @return list<array<string, string|null>> */
    private function rows_of( string $values, array $columns ): array {
        $rows  = [];
        $index = 0;
        $size  = strlen( $values );
        while ( $index < $size ) {
            if ( '(' !== $values[ $index ] ) {
                if ( ',' === $values[ $index ] || ctype_space( $values[ $index ] ) ) {
                    ++$index;
                    continue;
                }

                throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump VALUES section is malformed.' );
            }
            [ $row, $index ] = $this->tuple_at( $values, $index + 1, $columns );
            $rows[]          = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $columns
     * @return array{0: array<string, string|null>, 1: int}
     */
    private function tuple_at( string $values, int $index, array $columns ): array {
        $row   = [];
        $count = count( $columns );
        $size  = strlen( $values );
        for ( $position = 0; $position < $count; ++$position ) {
            $index = $this->skip_space( $values, $index );
            if ( $index >= $size ) {
                throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump row is truncated.' );
            }
            [ $row[ $columns[ $position ] ], $index ] = $this->value_at( $values, $index );
            $index                                    = $this->skip_space( $values, $index );
            if ( $index >= $size || ( ',' !== $values[ $index ] && ')' !== $values[ $index ] ) ) {
                throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump row separator is malformed.' );
            }
            ++$index;
        }

        return [ $row, $index ];
    }

    /** @return array{0: string|null, 1: int} */
    private function value_at( string $values, int $index ): array {
        $size = strlen( $values );
        if ( "'" === $values[ $index ] ) {
            $decoded = '';
            ++$index;
            while ( true ) {
                if ( $index >= $size ) {
                    throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump string literal is unterminated.' );
                }
                if ( '\\' === $values[ $index ] ) {
                    if ( $index + 1 >= $size ) {
                        throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump string literal ends inside an escape.' );
                    }
                    $decoded .= $this->decode_escape( $values[ $index + 1 ] );
                    $index   += 2;
                    continue;
                }
                if ( "'" === $values[ $index ] ) {
                    if ( "'" === ( $values[ $index + 1 ] ?? '' ) ) {
                        $decoded .= "'";
                        $index   += 2;
                        continue;
                    }
                    ++$index;
                    break;
                }
                $decoded .= $values[ $index ];
                ++$index;
            }

            return [ $decoded, $index ];
        }
        $start = $index;
        while ( $index < $size && ',' !== $values[ $index ] && ')' !== $values[ $index ] ) {
            ++$index;
        }
        $token = trim( substr( $values, $start, $index - $start ) );
        if ( 'NULL' === strtoupper( $token ) ) {
            return [ null, $index ];
        }
        if ( 1 !== preg_match( '/\A-?[0-9]+(?:\.[0-9]+)?\z/', $token ) ) {
            throw BackupException::of( 'RESTORE_DUMP_SYNTAX', 'A dump value token is not a supported literal.' );
        }

        return [ $token, $index ];
    }

    private function decode_escape( string $char ): string {
        return match ( $char ) {
            '0'     => "\0",
            'n'     => "\n",
            'r'     => "\r",
            'Z'     => "\x1a",
            '\\'    => '\\',
            "'"     => "'",
            '"'     => '"',
            default => throw BackupException::of( 'RESTORE_DUMP_UNSUPPORTED_ESCAPE', 'The dump uses an escape this reader cannot prove.' ),
        };
    }

    private function skip_space( string $values, int $index ): int {
        $size = strlen( $values );
        while ( $index < $size && ctype_space( $values[ $index ] ) ) {
            ++$index;
        }

        return $index;
    }
}

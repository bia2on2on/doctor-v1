<?php

declare( strict_types=1 );

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * Persistence boundary for `{prefix}cpms_patient_logins` (Migration 0032).
 *
 * Uses strictly the existing schema:
 *  - `kind`: `account` | `binding`
 *  - `state`: `pending` | `active` | `closed`
 *  - `verification_method`: `two_factor` (bindings only)
 *  - `live_key`: `"{clinic_id}:{patient_id}"` when `kind='binding' AND state='active'`, else NULL
 *  - `account_key`: `wp_user_id` when `kind='account'`, else NULL
 *  - No `national_id` column and no DELETE operations.
 *
 * All reads fail closed: SQL errors throw `RuntimeException` rather than
 * masquerading as "no row".
 */
final class PatientLoginRepository {

    public const KIND_ACCOUNT = 'account';
    public const KIND_BINDING = 'binding';

    public const STATE_PENDING = 'pending';
    public const STATE_ACTIVE  = 'active';
    public const STATE_CLOSED  = 'closed';

    public const METHOD_TWO_FACTOR = 'two_factor';

    public const MAX_PENDING_APPOINTMENTS_PER_ACCOUNT = 2;

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * Checks whether the `cpms_patient_logins` table exists (fail closed on DB errors).
     */
    public function table_exists(): bool {
        $table = $this->db->table( 'cpms_patient_logins' );
        $rows  = $this->db->fetch_all_strict( 'SHOW TABLES LIKE %s', [ $table ] );

        return [] !== $rows;
    }

    /**
     * Fetches the `kind='account'` row for `$wp_user_id`.
     *
     * @return array<string, mixed>|null
     */
    public function find_account_by_user_id( int $wp_user_id, bool $for_update = false ): ?array {
        if ( $wp_user_id <= 0 ) {
            return null;
        }

        $sql = 'SELECT id, kind, wp_user_id, mobile, state, clinic_id, patient_id, verified_at, verification_method, live_key, account_key, given_name, family_name, created_at, updated_at'
            . ' FROM ' . $this->db->table( 'cpms_patient_logins' )
            . ' WHERE kind = %s AND wp_user_id = %d'
            . ' LIMIT 1';
        if ( $for_update ) {
            $sql .= ' FOR UPDATE';
        }

        $rows = $this->db->fetch_all_strict( $sql, [ self::KIND_ACCOUNT, $wp_user_id ] );

        return $rows[0] ?? null;
    }

    /**
     * Fetches the `kind='account'` row for `$mobile`.
     *
     * @return array<string, mixed>|null
     */
    public function find_account_by_mobile( string $mobile, bool $for_update = false ): ?array {
        if ( '' === $mobile ) {
            return null;
        }

        $sql = 'SELECT id, kind, wp_user_id, mobile, state, clinic_id, patient_id, verified_at, verification_method, live_key, account_key, given_name, family_name, created_at, updated_at'
            . ' FROM ' . $this->db->table( 'cpms_patient_logins' )
            . ' WHERE kind = %s AND mobile = %s'
            . ' LIMIT 1';
        if ( $for_update ) {
            $sql .= ' FOR UPDATE';
        }

        $rows = $this->db->fetch_all_strict( $sql, [ self::KIND_ACCOUNT, $mobile ] );

        return $rows[0] ?? null;
    }

    /**
     * Fetches a binding row (`kind='binding'`) for `(wp_user_id, clinic_id, patient_id)`,
     * optionally filtering to active verified rows only.
     *
     * @return array<string, mixed>|null
     */
    public function find_binding(
        int $wp_user_id,
        int $clinic_id,
        int $patient_id,
        bool $active_only = true,
        bool $for_update = false
    ): ?array {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            return null;
        }

        $sql    = 'SELECT id, kind, wp_user_id, mobile, state, clinic_id, patient_id, verified_at, verification_method, live_key, account_key, created_at, updated_at'
            . ' FROM ' . $this->db->table( 'cpms_patient_logins' )
            . ' WHERE kind = %s AND wp_user_id = %d AND clinic_id = %d AND patient_id = %d';
        $params = [ self::KIND_BINDING, $wp_user_id, $clinic_id, $patient_id ];

        if ( $active_only ) {
            $sql     .= ' AND state = %s AND verified_at IS NOT NULL AND verification_method = %s';
            $params[] = self::STATE_ACTIVE;
            $params[] = self::METHOD_TWO_FACTOR;
        }

        $sql .= ' LIMIT 1';
        if ( $for_update ) {
            $sql .= ' FOR UPDATE';
        }

        $rows = $this->db->fetch_all_strict( $sql, $params );

        return $rows[0] ?? null;
    }

    /**
     * Fetches all active verified `two_factor` bindings for `$wp_user_id`, joined
     * with the active patient/clinic/organization rows to obtain `mobile` and
     * confirm tenant liveness.
     *
     * @return list<array<string, mixed>>
     */
    public function find_active_bindings_for_user( int $wp_user_id, ?int $clinic_id = null ): array {
        if ( $wp_user_id <= 0 ) {
            return [];
        }

        $sql    = 'SELECT b.id AS binding_id, b.wp_user_id, b.clinic_id, b.patient_id, b.verified_at, b.verification_method, b.live_key,'
            . ' p.mobile, p.identity_id'
            . ' FROM ' . $this->db->table( 'cpms_patient_logins' ) . ' b'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patient_logins' ) . ' a'
            . ' ON a.kind = %s AND a.wp_user_id = b.wp_user_id AND a.state = %s'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = b.patient_id AND p.clinic_id = b.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c ON c.id = b.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
            . ' WHERE b.kind = %s AND b.wp_user_id = %d AND b.state = %s'
            . ' AND b.verified_at IS NOT NULL AND b.verification_method = %s'
            . " AND p.status = 'active' AND c.status = 'active' AND o.status = 'active'";
        $params = [
            self::KIND_ACCOUNT,
            self::STATE_ACTIVE,
            self::KIND_BINDING,
            $wp_user_id,
            self::STATE_ACTIVE,
            self::METHOD_TWO_FACTOR,
        ];

        if ( null !== $clinic_id && $clinic_id > 0 ) {
            $sql     .= ' AND b.clinic_id = %d';
            $params[] = $clinic_id;
        }

        $sql .= ' ORDER BY b.id ASC';

        return $this->db->fetch_all_strict( $sql, $params );
    }

    /**
     * Counts active future (pending/confirmed, non-rescheduled) appointments
     * across the supplied proved patient IDs (§8).
     *
     * @param list<int> $patient_ids
     */
    public function count_active_future_appointments_for_patients( array $patient_ids, string $today_date ): int {
        $clean_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', $patient_ids ),
                    static fn ( int $id ): bool => $id > 0
                )
            )
        );
        if ( [] === $clean_ids ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
        $params       = array_merge( $clean_ids, [ $today_date ] );
        $rows         = $this->db->fetch_all_strict(
            'SELECT COUNT(*) AS cnt FROM ' . $this->db->table( 'cpms_appointments' )
            . " WHERE patient_id IN ({$placeholders})"
            . " AND status IN ('pending','confirmed')"
            . ' AND rescheduled_to IS NULL'
            . ' AND slot_date >= %s',
            $params
        );

        return (int) ( $rows[0]['cnt'] ?? 0 );
    }

    /**
     * Creates a `pending` account row (`kind='account'`, `state='pending'`).
     * Must be invoked inside a strict/transactional boundary.
     */
    public function insert_pending_account(
        int $wp_user_id,
        string $mobile,
        ?string $given_name = null,
        ?string $family_name = null
    ): int {
        if ( $wp_user_id <= 0 || '' === $mobile ) {
            throw new RuntimeException( 'Invalid account parameters' );
        }

        $now        = $this->db->nowUtcSql();
        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $ok = $this->db->insert(
                'cpms_patient_logins',
                [
                    'kind'                => self::KIND_ACCOUNT,
                    'wp_user_id'          => $wp_user_id,
                    'mobile'              => $mobile,
                    'state'               => self::STATE_PENDING,
                    'clinic_id'           => null,
                    'patient_id'          => null,
                    'verified_at'         => null,
                    'verification_method' => null,
                    'live_key'            => null,
                    'account_key'         => $wp_user_id,
                    'given_name'          => $given_name,
                    'family_name'         => $family_name,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );
            if ( ! $ok ) {
                throw new RuntimeException( 'Failed to insert pending account' );
            }

            return $this->db->wpdb_last_insert_id();
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Promotes a `pending` account row to `active` (`closed` accounts remain closed).
     */
    public function activate_account_from_pending( int $account_id, int $wp_user_id ): void {
        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, updated_at = %s'
                . ' WHERE id = %d AND kind = %s AND wp_user_id = %d AND state = %s',
                [
                    self::STATE_ACTIVE,
                    $this->db->nowUtcSql(),
                    $account_id,
                    self::KIND_ACCOUNT,
                    $wp_user_id,
                    self::STATE_PENDING,
                ]
            );

            $row = $this->find_account_by_user_id( $wp_user_id );
            if ( null === $row || self::STATE_ACTIVE !== (string) ( $row['state'] ?? '' ) ) {
                throw new RuntimeException( 'Account activation postcondition failed' );
            }
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Inserts or reactivates a `two_factor` binding row (`kind='binding'`, `state='active'`).
     * Enforces single live binding across accounts (`live_key = "{clinic_id}:{patient_id}"`).
     *
     * @return array{id: int, verified_at: string}
     */
    public function upsert_active_binding(
        int $wp_user_id,
        int $clinic_id,
        int $patient_id,
        string $verified_at
    ): array {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 || '' === $verified_at ) {
            throw new RuntimeException( 'Invalid binding parameters' );
        }

        $live_key   = self::live_key( $clinic_id, $patient_id );
        $now        = $this->db->nowUtcSql();
        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            // Check whether another account already holds an active binding on this record.
            $competing = $this->db->fetch_all_strict(
                'SELECT id, wp_user_id FROM ' . $this->db->table( 'cpms_patient_logins' )
                . ' WHERE live_key = %s AND wp_user_id <> %d LIMIT 1 FOR UPDATE',
                [ $live_key, $wp_user_id ]
            );
            if ( [] !== $competing ) {
                throw new RuntimeException( 'Competing live binding exists' );
            }

            $existing = $this->find_binding( $wp_user_id, $clinic_id, $patient_id, false, true );
            if ( null !== $existing ) {
                $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                    . ' SET state = %s, verified_at = %s, verification_method = %s, live_key = %s, account_key = NULL, updated_at = %s'
                    . ' WHERE id = %d AND kind = %s AND wp_user_id = %d',
                    [
                        self::STATE_ACTIVE,
                        $verified_at,
                        self::METHOD_TWO_FACTOR,
                        $live_key,
                        $now,
                        (int) $existing['id'],
                        self::KIND_BINDING,
                        $wp_user_id,
                    ]
                );
                $binding_id = (int) $existing['id'];
            } else {
                $ok = $this->db->insert(
                    'cpms_patient_logins',
                    [
                        'kind'                => self::KIND_BINDING,
                        'wp_user_id'          => $wp_user_id,
                        'mobile'              => null,
                        'state'               => self::STATE_ACTIVE,
                        'clinic_id'           => $clinic_id,
                        'patient_id'          => $patient_id,
                        'verified_at'         => $verified_at,
                        'verification_method' => self::METHOD_TWO_FACTOR,
                        'live_key'            => $live_key,
                        'account_key'         => null,
                        'given_name'          => null,
                        'family_name'         => null,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]
                );
                if ( ! $ok ) {
                    throw new RuntimeException( 'Binding insert failed' );
                }
                $binding_id = $this->db->wpdb_last_insert_id();
            }

            $persisted = $this->find_binding( $wp_user_id, $clinic_id, $patient_id, true );
            if ( null === $persisted || (int) $persisted['id'] !== $binding_id ) {
                throw new RuntimeException( 'Binding persistence postcondition failed' );
            }

            return [
                'id'          => $binding_id,
                'verified_at' => (string) $persisted['verified_at'],
            ];
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Severs a specific binding (`state='closed'`, `live_key=NULL`), never deleting
     * rows and never modifying the account row or `wp_users`.
     */
    public function sever_binding( int $wp_user_id, int $clinic_id, int $patient_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            return false;
        }

        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $affected = $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, live_key = NULL, updated_at = %s'
                . ' WHERE kind = %s AND wp_user_id = %d AND clinic_id = %d AND patient_id = %d AND state = %s',
                [
                    self::STATE_CLOSED,
                    $this->db->nowUtcSql(),
                    self::KIND_BINDING,
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    self::STATE_ACTIVE,
                ]
            );

            return $affected > 0;
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Closes every active binding for `(clinic_id, patient_id)` (e.g. NID correction
     * or deactivation in §7.6) and returns the affected `wp_user_id` values so the
     * caller can destroy their sessions post-commit.
     *
     * @return list<int>
     */
    public function close_bindings_for_patient( int $clinic_id, int $patient_id ): array {
        if ( $clinic_id <= 0 || $patient_id <= 0 ) {
            return [];
        }

        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $rows = $this->db->fetch_all_strict(
                'SELECT id, wp_user_id FROM ' . $this->db->table( 'cpms_patient_logins' )
                . ' WHERE kind = %s AND clinic_id = %d AND patient_id = %d AND state = %s'
                . ' FOR UPDATE',
                [ self::KIND_BINDING, $clinic_id, $patient_id, self::STATE_ACTIVE ]
            );
            if ( [] === $rows ) {
                return [];
            }

            $user_ids = [];
            foreach ( $rows as $row ) {
                $uid = (int) ( $row['wp_user_id'] ?? 0 );
                if ( $uid > 0 ) {
                    $user_ids[ $uid ] = $uid;
                }
            }

            $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, live_key = NULL, updated_at = %s'
                . ' WHERE kind = %s AND clinic_id = %d AND patient_id = %d AND state = %s',
                [
                    self::STATE_CLOSED,
                    $this->db->nowUtcSql(),
                    self::KIND_BINDING,
                    $clinic_id,
                    $patient_id,
                    self::STATE_ACTIVE,
                ]
            );

            return array_values( $user_ids );
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Closes a patient account (`state='closed'`) and all its active bindings
     * (`live_key=NULL`), preserving `mobile` (D-A: never null or free numbers)
     * and preserving `wp_users`.
     */
    public function close_account( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) {
            return false;
        }

        $now        = $this->db->nowUtcSql();
        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, live_key = NULL, updated_at = %s'
                . ' WHERE kind = %s AND wp_user_id = %d AND state = %s',
                [
                    self::STATE_CLOSED,
                    $now,
                    self::KIND_BINDING,
                    $wp_user_id,
                    self::STATE_ACTIVE,
                ]
            );

            $affected = $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, updated_at = %s'
                . ' WHERE kind = %s AND wp_user_id = %d AND state <> %s',
                [
                    self::STATE_CLOSED,
                    $now,
                    self::KIND_ACCOUNT,
                    $wp_user_id,
                    self::STATE_CLOSED,
                ]
            );

            return $affected > 0;
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Restore cleanup (§7.3): closes all bindings (`state='closed', live_key=NULL`)
     * and recomputes `account_key = wp_user_id` on account rows.
     */
    public function recompute_keys_and_close_on_restore(): int {
        $now        = $this->db->nowUtcSql();
        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $closed = $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET state = %s, live_key = NULL, updated_at = %s'
                . ' WHERE kind = %s',
                [
                    self::STATE_CLOSED,
                    $now,
                    self::KIND_BINDING,
                ]
            );
            $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_patient_logins' )
                . ' SET account_key = wp_user_id, live_key = NULL, updated_at = %s'
                . ' WHERE kind = %s',
                [
                    $now,
                    self::KIND_ACCOUNT,
                ]
            );

            return $closed;
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    public static function live_key( int $clinic_id, int $patient_id ): string {
        return $clinic_id . ':' . $patient_id;
    }
}

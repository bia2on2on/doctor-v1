<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Domain\Validators\MobileValidator;
use DateTimeImmutable;
use DateTimeZone;
use WP_Session_Tokens;

/**
 * Per-session patient proof store backed by WordPress `WP_Session_Tokens` (§2.7).
 *
 * Stores server-side proof entries inside the live WordPress session record:
 *  - `cpms_mobile_proofs`: `<mobile> => { at, token_hash }`
 *  - `cpms_proofs`: `<clinic_id>:<patient_id> => { binding_id, verified_at, at, token_hash }`
 *
 * Security invariants (§2.7, §3, A3):
 *  - Raw national ID is NEVER stored in session metadata.
 *  - `token_hash` is computed via `hash( 'sha256', $token )` on public APIs
 *    (never calling private `WP_Session_Tokens::hash_token()`).
 *  - Every read re-validates live session existence (`WP_Session_Tokens::get()`),
 *    `token_hash` (`hash_equals()`), and the absolute 60-minute proof window.
 *  - Writes re-read the live session and fail closed if the session was destroyed.
 *  - Patient auth cookies use 8-hour lifetime (`SESSION_TTL_SECONDS`) with
 *    `remember = false` (non-persistent cookie).
 */
final class PatientSessionProofStore {

    public const SESSION_TTL_SECONDS     = 28800; // 8 hours, non-persistent.
    public const PROOF_TTL_SECONDS       = 3600;  // 60 minutes absolute window.
    public const KEY_MOBILE_PROOFS       = 'cpms_mobile_proofs';
    public const KEY_BINDING_PROOFS      = 'cpms_proofs';
    public const KEY_REGISTRATION_PROOFS = 'cpms_reg_proofs';

    /** @var (callable(int): WP_Session_Tokens)|null */
    private $manager_resolver;

    /** @var (callable(): string)|null */
    private $token_resolver;

    /**
     * @param (callable(int): WP_Session_Tokens)|null $manager_resolver Optional session manager resolver (unit tests).
     * @param (callable(): string)|null               $token_resolver   Optional current session token resolver (unit tests).
     */
    public function __construct(
        ?callable $manager_resolver = null,
        ?callable $token_resolver = null
    ) {
        $this->manager_resolver = $manager_resolver;
        $this->token_resolver   = $token_resolver;
    }

    /**
     * Records a mobile OTP proof inside an existing live session.
     * Fails closed (returns false) if the session is missing, expired, or destroyed.
     */
    public function record_mobile_proof(
        int $wp_user_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        $proofs = isset( $session[ self::KEY_MOBILE_PROOFS ] ) && is_array( $session[ self::KEY_MOBILE_PROOFS ] )
            ? $session[ self::KEY_MOBILE_PROOFS ]
            : [];

        $proofs[ $normalized_mobile ]       = [
            'at'         => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
            'token_hash' => hash( 'sha256', $session_token ),
        ];
        $session[ self::KEY_MOBILE_PROOFS ] = $proofs;

        // Re-verify session is still live immediately before mutating.
        if ( null === $this->get_live_session( $manager, $session_token, $now_ts ) ) {
            return false;
        }

        $manager->update( $session_token, $session );

        // Confirm persistence did not race against session destruction.
        return null !== $this->get_live_session( $manager, $session_token, $now_ts );
    }

    /**
     * Records a two-factor binding proof inside an existing live session.
     * Only binding metadata (`binding_id`, `verified_at`, `at`, `token_hash`) is stored;
     * raw national ID is never written.
     */
    public function record_binding_proof(
        int $wp_user_id,
        int $clinic_id,
        int $patient_id,
        int $binding_id,
        string $verified_at,
        ?int $now = null,
        ?string $token = null
    ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 || $binding_id <= 0 || '' === trim( $verified_at ) ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        $binding_key = self::binding_key( $clinic_id, $patient_id );
        $proofs      = isset( $session[ self::KEY_BINDING_PROOFS ] ) && is_array( $session[ self::KEY_BINDING_PROOFS ] )
            ? $session[ self::KEY_BINDING_PROOFS ]
            : [];

        $proofs[ $binding_key ]              = [
            'binding_id'  => $binding_id,
            'verified_at' => $verified_at,
            'at'          => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
            'token_hash'  => hash( 'sha256', $session_token ),
        ];
        $session[ self::KEY_BINDING_PROOFS ] = $proofs;

        if ( null === $this->get_live_session( $manager, $session_token, $now_ts ) ) {
            return false;
        }

        $manager->update( $session_token, $session );

        return null !== $this->get_live_session( $manager, $session_token, $now_ts );
    }

    /**
     * Checks whether the current session holds a live, non-expired mobile proof
     * bound to this exact session token.
     */
    public function has_valid_mobile_proof(
        int $wp_user_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        return $this->is_mobile_entry_valid( $session, $normalized_mobile, $session_token, $now_ts );
    }

    /**
     * Checks whether the current session holds BOTH a valid mobile proof for
     * `$record_mobile` AND a valid binding proof matching the live DB binding row
     * (`binding_id` and `verified_at`).
     */
    public function has_valid_binding_proof(
        int $wp_user_id,
        int $clinic_id,
        int $patient_id,
        int $binding_id,
        string $verified_at,
        string $record_mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 || $binding_id <= 0 || '' === trim( $verified_at ) ) {
            return false;
        }

        $normalized_mobile = MobileValidator::normalize( $record_mobile );
        if ( null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        if ( ! $this->is_mobile_entry_valid( $session, $normalized_mobile, $session_token, $now_ts ) ) {
            return false;
        }

        $proofs      = $session[ self::KEY_BINDING_PROOFS ] ?? null;
        $binding_key = self::binding_key( $clinic_id, $patient_id );
        if ( ! is_array( $proofs ) || ! isset( $proofs[ $binding_key ] ) || ! is_array( $proofs[ $binding_key ] ) ) {
            return false;
        }

        $entry = $proofs[ $binding_key ];
        if ( (int) ( $entry['binding_id'] ?? 0 ) !== $binding_id ) {
            return false;
        }
        if ( ! hash_equals( $verified_at, (string) ( $entry['verified_at'] ?? '' ) ) ) {
            return false;
        }

        return $this->is_entry_token_and_ttl_valid( $entry, $session_token, $now_ts );
    }

    /**
     * Records a clinic-bound mobile OTP registration proof inside an existing live session.
     * Fails closed (returns false) if the session is missing, expired, or destroyed.
     */
    public function record_registration_mobile_proof(
        int $wp_user_id,
        int $clinic_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        $proofs = isset( $session[ self::KEY_REGISTRATION_PROOFS ] ) && is_array( $session[ self::KEY_REGISTRATION_PROOFS ] )
            ? $session[ self::KEY_REGISTRATION_PROOFS ]
            : [];

        $reg_key            = self::registration_key( $clinic_id, $normalized_mobile );
        $proofs[ $reg_key ] = [
            'clinic_id'  => $clinic_id,
            'mobile'     => $normalized_mobile,
            'at'         => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
            'token_hash' => hash( 'sha256', $session_token ),
        ];

        $session[ self::KEY_REGISTRATION_PROOFS ] = $proofs;

        if ( null === $this->get_live_session( $manager, $session_token, $now_ts ) ) {
            return false;
        }

        $manager->update( $session_token, $session );

        return null !== $this->get_live_session( $manager, $session_token, $now_ts );
    }

    /**
     * Checks whether the current session holds a live, non-expired registration
     * mobile proof bound to BOTH `$clinic_id` and `$mobile` on this exact session token.
     */
    public function has_valid_registration_mobile_proof(
        int $wp_user_id,
        int $clinic_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        $proofs  = $session[ self::KEY_REGISTRATION_PROOFS ] ?? null;
        $reg_key = self::registration_key( $clinic_id, $normalized_mobile );
        if ( ! is_array( $proofs ) || ! isset( $proofs[ $reg_key ] ) || ! is_array( $proofs[ $reg_key ] ) ) {
            return false;
        }

        $entry = $proofs[ $reg_key ];
        if ( (int) ( $entry['clinic_id'] ?? 0 ) !== $clinic_id ) {
            return false;
        }
        if ( ! hash_equals( $normalized_mobile, (string) ( $entry['mobile'] ?? '' ) ) ) {
            return false;
        }

        return $this->is_entry_token_and_ttl_valid( $entry, $session_token, $now_ts );
    }

    /**
     * Finds a live, non-expired registration mobile proof for `$mobile` in the
     * current session and returns its `['clinic_id' => int, 'mobile' => string]`,
     * or null if none is valid.
     *
     * @return array{clinic_id: int, mobile: string}|null
     */
    public function find_valid_registration_mobile_proof(
        int $wp_user_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): ?array {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( $wp_user_id <= 0 || null === $normalized_mobile ) {
            return null;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return null;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return null;
        }

        $proofs = $session[ self::KEY_REGISTRATION_PROOFS ] ?? null;
        if ( ! is_array( $proofs ) ) {
            return null;
        }

        foreach ( $proofs as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $entry_clinic = (int) ( $entry['clinic_id'] ?? 0 );
            $entry_mobile = (string) ( $entry['mobile'] ?? '' );
            if ( $entry_clinic <= 0 || ! hash_equals( $normalized_mobile, $entry_mobile ) ) {
                continue;
            }
            if ( $this->is_entry_token_and_ttl_valid( $entry, $session_token, $now_ts ) ) {
                return [
                    'clinic_id' => $entry_clinic,
                    'mobile'    => $normalized_mobile,
                ];
            }
        }

        return null;
    }

    /**
     * Consumes (removes) the registration mobile proof for `($clinic_id, $mobile)`
     * after Step 2 registration completes so it cannot be replayed.
     */
    public function consume_registration_mobile_proof(
        int $wp_user_id,
        int $clinic_id,
        string $mobile,
        ?int $now = null,
        ?string $token = null
    ): bool {
        $normalized_mobile = MobileValidator::normalize( $mobile );
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || null === $normalized_mobile ) {
            return false;
        }

        $now_ts        = $now ?? time();
        $session_token = $this->resolve_token( $token );
        $manager       = $this->resolve_manager( $wp_user_id );
        if ( null === $manager || '' === $session_token ) {
            return false;
        }

        $session = $this->get_live_session( $manager, $session_token, $now_ts );
        if ( null === $session ) {
            return false;
        }

        $proofs = $session[ self::KEY_REGISTRATION_PROOFS ] ?? null;
        if ( ! is_array( $proofs ) ) {
            return true;
        }

        $reg_key = self::registration_key( $clinic_id, $normalized_mobile );
        if ( ! array_key_exists( $reg_key, $proofs ) ) {
            return true;
        }

        unset( $proofs[ $reg_key ] );
        $session[ self::KEY_REGISTRATION_PROOFS ] = $proofs;

        $manager->update( $session_token, $session );

        return true;
    }

    /**
     * Computes a `wp_rest` nonce bound to `$wp_user_id` and `$session_token`
     * immediately after issuing a fresh auth cookie (when `$_COOKIE` is not yet populated).
     */
    public function create_rest_nonce_for_token( int $wp_user_id, string $session_token ): string {
        if ( $wp_user_id <= 0 || '' === $session_token || ! function_exists( 'wp_nonce_tick' ) || ! function_exists( 'wp_hash' ) ) {
            return '';
        }

        $tick = wp_nonce_tick( 'wp_rest' );

        return substr( wp_hash( $tick . '|wp_rest|' . $wp_user_id . '|' . $session_token, 'nonce' ), -12, 10 );
    }

    /**
     * Computes the intersection of active DB bindings with live session proofs (§2.8).
     *
     * @param list<array{binding_id: int, clinic_id: int, patient_id: int, verified_at: string, mobile: string}> $active_bindings
     * @return list<int> Unique proved patient IDs.
     */
    public function proved_patient_ids(
        int $wp_user_id,
        array $active_bindings,
        ?int $now = null,
        ?string $token = null
    ): array {
        $proved = [];
        foreach ( $active_bindings as $binding ) {
            $clinic_id   = (int) ( $binding['clinic_id'] ?? 0 );
            $patient_id  = (int) ( $binding['patient_id'] ?? 0 );
            $binding_id  = (int) ( $binding['binding_id'] ?? 0 );
            $verified_at = (string) ( $binding['verified_at'] ?? '' );
            $mobile      = (string) ( $binding['mobile'] ?? '' );
            if ( $this->has_valid_binding_proof(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $binding_id,
                $verified_at,
                $mobile,
                $now,
                $token
            ) ) {
                $proved[ $patient_id ] = $patient_id;
            }
        }

        return array_values( $proved );
    }

    /**
     * Issues a non-persistent WordPress auth cookie (`remember = false`, 8h TTL)
     * and returns the issued raw session token when available.
     */
    public function issue_non_persistent_auth_cookie( int $wp_user_id ): string {
        if ( $wp_user_id <= 0 || ! function_exists( 'wp_set_auth_cookie' ) ) {
            return '';
        }

        $issued_token = '';
        $ttl_filter   = static fn (): int => self::SESSION_TTL_SECONDS;
        $capture      = static function (
            string $cookie,
            int $expire = 0,
            int $expiration = 0,
            int $user_id = 0,
            string $scheme = '',
            string $token = ''
        ) use ( &$issued_token ): void {
            if ( '' !== $issued_token ) {
                return;
            }
            if ( '' !== $token ) {
                $issued_token = $token;
                return;
            }
            if ( function_exists( 'wp_parse_auth_cookie' ) ) {
                $parsed = wp_parse_auth_cookie( $cookie, '' !== $scheme ? $scheme : 'logged_in' );
                if ( is_array( $parsed ) && is_string( $parsed['token'] ?? null ) ) {
                    $issued_token = (string) $parsed['token'];
                }
            }
        };

        add_filter( 'auth_cookie_expiration', $ttl_filter, 999 );
        add_action( 'set_logged_in_cookie', $capture, 10, 6 );
        add_action( 'set_auth_cookie', $capture, 10, 6 );
        try {
            wp_set_auth_cookie( $wp_user_id, false );
        } finally {
            remove_filter( 'auth_cookie_expiration', $ttl_filter, 999 );
            remove_action( 'set_logged_in_cookie', $capture, 10 );
            remove_action( 'set_auth_cookie', $capture, 10 );
        }

        return $issued_token;
    }

    /**
     * Destroys all WordPress sessions for `$wp_user_id` via `WP_Session_Tokens`.
     */
    public function destroy_all_sessions( int $wp_user_id ): void {
        if ( $wp_user_id <= 0 ) {
            return;
        }
        $manager = $this->resolve_manager( $wp_user_id );
        if ( null !== $manager ) {
            $manager->destroy_all();
        }
    }

    public static function binding_key( int $clinic_id, int $patient_id ): string {
        return $clinic_id . ':' . $patient_id;
    }

    public static function registration_key( int $clinic_id, string $normalized_mobile ): string {
        return $clinic_id . ':' . $normalized_mobile;
    }

    private function is_mobile_entry_valid( array $session, string $normalized_mobile, string $session_token, int $now_ts ): bool {
        $proofs = $session[ self::KEY_MOBILE_PROOFS ] ?? null;
        if ( ! is_array( $proofs ) || ! isset( $proofs[ $normalized_mobile ] ) || ! is_array( $proofs[ $normalized_mobile ] ) ) {
            return false;
        }

        return $this->is_entry_token_and_ttl_valid( $proofs[ $normalized_mobile ], $session_token, $now_ts );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function is_entry_token_and_ttl_valid( array $entry, string $session_token, int $now_ts ): bool {
        $entry_hash = $entry['token_hash'] ?? null;
        if ( ! is_string( $entry_hash ) || '' === $entry_hash ) {
            return false;
        }
        if ( ! hash_equals( hash( 'sha256', $session_token ), $entry_hash ) ) {
            return false;
        }

        $at_raw = $entry['at'] ?? null;
        if ( ! is_string( $at_raw ) || '' === $at_raw ) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $at_raw, new DateTimeZone( 'UTC' ) );
        if ( false === $parsed || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $at_raw ) {
            return false;
        }

        $issued_ts = $parsed->getTimestamp();

        return $issued_ts <= $now_ts && ( $now_ts - $issued_ts ) <= self::PROOF_TTL_SECONDS;
    }

    /**
     * @param WP_Session_Tokens $manager
     * @return array<string, mixed>|null
     */
    private function get_live_session( object $manager, string $session_token, int $now_ts ): ?array {
        if ( ! method_exists( $manager, 'get' ) ) {
            return null;
        }

        $session = $manager->get( $session_token );
        if ( ! is_array( $session ) || [] === $session ) {
            return null;
        }

        if ( isset( $session['expiration'] ) && (int) $session['expiration'] <= $now_ts ) {
            return null;
        }

        return $session;
    }

    /**
     * @return WP_Session_Tokens|null
     */
    private function resolve_manager( int $wp_user_id ): ?object {
        if ( $wp_user_id <= 0 ) {
            return null;
        }
        if ( null !== $this->manager_resolver ) {
            $manager = ( $this->manager_resolver )( $wp_user_id );

            return is_object( $manager ) ? $manager : null;
        }
        if ( class_exists( WP_Session_Tokens::class ) ) {
            return WP_Session_Tokens::get_instance( $wp_user_id );
        }

        return null;
    }

    private function resolve_token( ?string $explicit_token ): string {
        if ( null !== $explicit_token ) {
            return trim( $explicit_token );
        }
        if ( null !== $this->token_resolver ) {
            return trim( (string) ( $this->token_resolver )() );
        }
        if ( function_exists( 'wp_get_session_token' ) ) {
            return trim( (string) wp_get_session_token() );
        }

        return '';
    }
}

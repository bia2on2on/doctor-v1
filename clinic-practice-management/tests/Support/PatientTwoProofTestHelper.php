<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Support;

use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use RuntimeException;
use WP_Session_Tokens;

/**
 * Shared D2 test helper for seeding active `cpms_patient_logins` bindings,
 * Organization identity edges, `WP_Session_Tokens` session proofs, and
 * switching `$_COOKIE[LOGGED_IN_COOKIE]` between seeded patient users.
 */
final class PatientTwoProofTestHelper {

    /** @var array<int, string> */
    private static array $tokens_by_user = [];

    /** @var array<int, string> */
    private static array $cookies_by_user = [];

    /**
     * Seeds a live two-proof patient session + active binding (+ optional identity edges).
     *
     * Options:
     *  - `mobile` (string): fallback when patient row has no valid mobile.
     *  - `full_identity` (bool, default true): seed `identity_id` + `cpms_patient_identity_links`.
     *  - `link_user` (bool, default true): ensure `cpms_patient_user_links` row exists.
     *  - `is_primary` (bool, default true): `is_primary` when inserting `cpms_patient_user_links`.
     *  - `set_current_user` (bool, default true): call `wp_set_current_user()` and set cookie.
     *  - `seed_session_scope` (bool, default false): also merge `PatientSessionScope` clinic marker.
     *  - `session_token` (?string): explicit token to reuse.
     *  - `now` (?int): reference unix timestamp.
     *
     * @param array<string, mixed> $options
     * @return array{token: string, binding_id: int, verified_at: string, mobile: string, cookie: string}
     */
    public static function seed_patient_proof(
        int $wp_user_id,
        int $clinic_id,
        int $patient_id,
        array $options = []
    ): array {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            throw new \InvalidArgumentException( 'seed_patient_proof requires positive user, clinic, and patient IDs.' );
        }

        $ensure_patient_role = (bool) ( $options['ensure_patient_role'] ?? true );
        if ( $ensure_patient_role ) {
            $user = get_userdata( $wp_user_id );
            if ( $user instanceof \WP_User && ( [] === $user->roles || [ 'subscriber' ] === array_values( $user->roles ) ) ) {
                $user->set_role( RolesAndCapabilities::ROLE_PATIENT );
            }
        }

        $db          = App::db();
        $logins      = new PatientLoginRepository( $db );
        $proofs      = new PatientSessionProofStore();
        $ident_repo  = new PatientIdentityRepository( $db );
        $now_ts      = isset( $options['now'] ) && is_int( $options['now'] ) ? $options['now'] : time();
        $verified_at = $db->nowUtcSql();

        $patient = $db->fetchRow(
            'SELECT id, clinic_id, mobile, identity_id FROM ' . $db->table( 'cpms_patients' )
            . ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [ $patient_id, $clinic_id ]
        );
        if ( null === $patient ) {
            throw new RuntimeException( "seed_patient_proof: patient {$patient_id} not found in clinic {$clinic_id}." );
        }

        $raw_mobile = is_string( $options['mobile'] ?? null ) && '' !== (string) $options['mobile']
            ? (string) $options['mobile']
            : (string) ( $patient['mobile'] ?? '' );
        $mobile     = MobileValidator::normalize( $raw_mobile );
        if ( null === $mobile ) {
            $mobile = sprintf( '0912%07d', $wp_user_id % 10000000 );
        }

        $link_user  = (bool) ( $options['link_user'] ?? true );
        $is_primary = (bool) ( $options['is_primary'] ?? true );
        if ( $link_user ) {
            $existing_link = $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
                . ' WHERE wp_user_id = %d AND clinic_id = %d AND patient_id = %d LIMIT 1',
                [ $wp_user_id, $clinic_id, $patient_id ]
            );
            if ( null === $existing_link ) {
                $db->insert(
                    'cpms_patient_user_links',
                    [
                        'clinic_id'      => $clinic_id,
                        'patient_id'     => $patient_id,
                        'wp_user_id'     => $wp_user_id,
                        'mobile_at_link' => $mobile,
                        'is_primary'     => $is_primary ? 1 : 0,
                        'linked_at'      => $verified_at,
                    ]
                );
            }
        }

        $full_identity = (bool) ( $options['full_identity'] ?? true );
        if ( $full_identity ) {
            $org_id = (int) $db->fetchValue(
                'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                [ $clinic_id ]
            );
            if ( $org_id <= 0 ) {
                throw new RuntimeException( "seed_patient_proof: clinic {$clinic_id} organization not found." );
            }
            $identity_id = App::patient_identity_activator()->activate_for_new_patient(
                $clinic_id,
                $patient_id,
                $mobile,
                $wp_user_id
            );
            if ( ! $ident_repo->user_link_exists( $identity_id, $wp_user_id ) ) {
                App::patient_identity_service()->link_user( $org_id, $identity_id, $wp_user_id, $mobile );
            }
        }

        $account = $logins->find_account_by_user_id( $wp_user_id );
        if ( null === $account ) {
            $account_id = $logins->insert_pending_account( $wp_user_id, $mobile );
            $logins->activate_account_from_pending( $account_id, $wp_user_id );
        } elseif ( PatientLoginRepository::STATE_PENDING === (string) ( $account['state'] ?? '' ) ) {
            $logins->activate_account_from_pending( (int) $account['id'], $wp_user_id );
        } elseif ( PatientLoginRepository::STATE_ACTIVE !== (string) ( $account['state'] ?? '' ) ) {
            $db->execute(
                'UPDATE ' . $db->table( 'cpms_patient_logins' )
                . ' SET state = %s, updated_at = %s WHERE id = %d',
                [ PatientLoginRepository::STATE_ACTIVE, $verified_at, (int) $account['id'] ]
            );
        }

        $binding    = $logins->upsert_active_binding( $wp_user_id, $clinic_id, $patient_id, $verified_at );
        $binding_id = (int) $binding['id'];

        $manager       = WP_Session_Tokens::get_instance( $wp_user_id );
        $session_token = is_string( $options['session_token'] ?? null ) ? (string) $options['session_token'] : '';

        if ( '' === $session_token ) {
            if ( (int) wp_validate_auth_cookie( '', 'logged_in' ) === $wp_user_id ) {
                $cookie_token = wp_get_session_token();
                if ( '' !== $cookie_token && is_array( $manager->get( $cookie_token ) ) ) {
                    $session_token = $cookie_token;
                }
            }
        }
        if ( '' === $session_token && isset( self::$tokens_by_user[ $wp_user_id ] ) ) {
            $cached_token = self::$tokens_by_user[ $wp_user_id ];
            if ( is_array( $manager->get( $cached_token ) ) ) {
                $session_token = $cached_token;
                if ( isset( self::$cookies_by_user[ $wp_user_id ] ) ) {
                    $_COOKIE[ LOGGED_IN_COOKIE ] = self::$cookies_by_user[ $wp_user_id ];
                }
            }
        }
        if ( '' === $session_token ) {
            $session_token = $proofs->issue_non_persistent_auth_cookie( $wp_user_id );
        }

        if ( '' === $session_token ) {
            throw new RuntimeException( "seed_patient_proof: failed to issue live session token for user {$wp_user_id}." );
        }

        if ( (bool) ( $options['seed_session_scope'] ?? false ) ) {
            $session = $manager->get( $session_token );
            if ( is_array( $session ) ) {
                $session = array_merge( $session, App::patient_session_scope()->session_marker_for( $wp_user_id, $clinic_id ) );
                $manager->update( $session_token, $session );
            }
        }

        if ( ! $proofs->record_mobile_proof( $wp_user_id, $mobile, $now_ts, $session_token ) ) {
            throw new RuntimeException( "seed_patient_proof: failed to record mobile proof for user {$wp_user_id}." );
        }
        if ( ! $proofs->record_binding_proof( $wp_user_id, $clinic_id, $patient_id, $binding_id, (string) $binding['verified_at'], $now_ts, $session_token ) ) {
            throw new RuntimeException( "seed_patient_proof: failed to record binding proof for user {$wp_user_id}." );
        }

        $cookie = (string) ( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '' );
        if ( '' === $cookie || (int) wp_validate_auth_cookie( $cookie, 'logged_in' ) !== $wp_user_id ) {
            $exp    = $now_ts + PatientSessionProofStore::SESSION_TTL_SECONDS;
            $cookie = wp_generate_auth_cookie( $wp_user_id, $exp, 'logged_in', $session_token );
        }

        self::$tokens_by_user[ $wp_user_id ]  = $session_token;
        self::$cookies_by_user[ $wp_user_id ] = $cookie;

        if ( (bool) ( $options['set_current_user'] ?? true ) ) {
            self::acting_as( $wp_user_id );
        }

        return [
            'token'       => $session_token,
            'binding_id'  => $binding_id,
            'verified_at' => (string) $binding['verified_at'],
            'mobile'      => $mobile,
            'cookie'      => $cookie,
        ];
    }

    /**
     * Switches `wp_set_current_user()` AND restores the user's `$_COOKIE[LOGGED_IN_COOKIE]`
     * + `PatientSessionScope` in-process token (or clears both for `0`).
     */
    public static function acting_as( int $wp_user_id ): void {
        if ( $wp_user_id <= 0 ) {
            wp_set_current_user( 0 );
            unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            App::patient_session_scope()->set_in_process_session_token( 0, null );

            return;
        }

        wp_set_current_user( $wp_user_id );
        if ( isset( self::$cookies_by_user[ $wp_user_id ] ) ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = self::$cookies_by_user[ $wp_user_id ];
        }
        if ( isset( self::$tokens_by_user[ $wp_user_id ] ) ) {
            App::patient_session_scope()->set_in_process_session_token( $wp_user_id, self::$tokens_by_user[ $wp_user_id ] );
        }
    }

    public static function reset(): void {
        self::$tokens_by_user  = [];
        self::$cookies_by_user = [];
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        App::patient_session_scope()->clear_in_process_session_tokens();
        wp_set_current_user( 0 );
    }
}

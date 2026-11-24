<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use Throwable;
use WP_Error;
use WP_User;

/**
 * §4.4 initial-credential guard for OTP-registered / OTP-bound patient accounts.
 *
 * Until a future password-lifecycle release introduces verified method-B
 * credentials, any user with a `cpms_patient_logins` account row (`kind='account'`)
 * must authenticate solely via OTP and must never sign in via WordPress password,
 * reset a password via WP reset keys, or use WordPress Application Passwords.
 *
 * Legacy/unit-test `ROLE_PATIENT` fixtures without a `cpms_patient_logins`
 * account row are left untouched so existing harness tests remain valid.
 */
final class PatientCredentialGuard {

    public const ERROR_CODE = 'cpms_patient_otp_required';

    public function __construct( private readonly CpmsDb $db ) {
    }

    public function register_hooks(): void {
        if ( ! function_exists( 'add_filter' ) ) {
            return;
        }

        if ( ! function_exists( 'has_filter' ) || false === has_filter( 'authenticate', [ $this, 'filter_authenticate' ] ) ) {
            add_filter( 'authenticate', [ $this, 'filter_authenticate' ], 15, 3 );
        }
        if ( ! function_exists( 'has_filter' ) || false === has_filter( 'wp_authenticate_user', [ $this, 'filter_wp_authenticate_user' ] ) ) {
            add_filter( 'wp_authenticate_user', [ $this, 'filter_wp_authenticate_user' ], 99, 2 );
        }
        if ( ! function_exists( 'has_filter' ) || false === has_filter( 'allow_password_reset', [ $this, 'filter_allow_password_reset' ] ) ) {
            add_filter( 'allow_password_reset', [ $this, 'filter_allow_password_reset' ], 10, 2 );
        }
        if ( ! function_exists( 'has_filter' ) || false === has_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'filter_app_passwords_available' ] ) ) {
            add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'filter_app_passwords_available' ], 10, 2 );
        }
    }

    /**
     * Blocks password/app-password authentication before `wp_check_password()`
     * runs (priority 15 < core's priority 20) so right and wrong passwords
     * return the same error (no password oracle).
     *
     * @param mixed $user
     * @return mixed
     */
    public function filter_authenticate( mixed $user, string $username, string $password ): mixed {
        unset( $password );

        if ( $user instanceof WP_User ) {
            if ( $this->should_block_auth( $user ) ) {
                return new WP_Error( self::ERROR_CODE, PatientAccess::PROOF_FAILED_MESSAGE );
            }

            return $user;
        }

        $clean = trim( $username );
        if ( '' === $clean || ! function_exists( 'get_user_by' ) ) {
            return $user;
        }

        $candidate = get_user_by( 'login', $clean );
        if ( ! $candidate instanceof WP_User && str_contains( $clean, '@' ) ) {
            $candidate = get_user_by( 'email', $clean );
        }

        if ( $candidate instanceof WP_User && $this->should_block_auth( $candidate ) ) {
            return new WP_Error( self::ERROR_CODE, PatientAccess::PROOF_FAILED_MESSAGE );
        }

        return $user;
    }

    /**
     * Defense-in-depth block on `wp_authenticate_user` when called directly.
     *
     * @param mixed $user
     * @return mixed
     */
    public function filter_wp_authenticate_user( mixed $user, string $password ): mixed {
        unset( $password );

        if ( $user instanceof WP_User && $this->should_block_auth( $user ) ) {
            return new WP_Error( self::ERROR_CODE, PatientAccess::PROOF_FAILED_MESSAGE );
        }

        return $user;
    }

    /**
     * Blocks WordPress password reset for OTP-only patient accounts.
     */
    public function filter_allow_password_reset( bool $allow, int $user_id ): bool {
        if ( $user_id > 0 && $this->has_patient_login_account( $user_id ) ) {
            return false;
        }

        return $allow;
    }

    /**
     * Blocks WordPress Application Passwords for OTP-only patient accounts.
     */
    public function filter_app_passwords_available( bool $available, WP_User $user ): bool {
        if ( $this->has_patient_login_account( (int) $user->ID ) ) {
            return false;
        }

        return $available;
    }

    private function has_patient_login_account( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) {
            return false;
        }

        try {
            $logins = new PatientLoginRepository( $this->db );
            if ( ! $logins->table_exists() ) {
                return false;
            }

            return null !== $logins->find_account_by_user_id( $wp_user_id );
        } catch ( Throwable ) {
            if ( function_exists( 'get_userdata' ) ) {
                $user = get_userdata( $wp_user_id );
                if ( $user instanceof WP_User && in_array( RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true ) ) {
                    return true;
                }
            }

            return false;
        }
    }

    private function should_block_auth( WP_User $user ): bool {
        if ( $this->has_patient_login_account( (int) $user->ID ) ) {
            return true;
        }
        if ( ! $this->is_xmlrpc_context() ) {
            return false;
        }
        $roles = (array) ( $user->roles ?? [] );

        return in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true )
            && ! in_array( RolesAndCapabilities::ROLE_DOCTOR, $roles, true )
            && ! in_array( RolesAndCapabilities::ROLE_SECRETARY, $roles, true )
            && ! in_array( RolesAndCapabilities::ROLE_ACCOUNTANT, $roles, true )
            && ! in_array( RolesAndCapabilities::ROLE_MANAGER, $roles, true )
            && ( ! function_exists( 'user_can' ) || ! user_can( $user, 'manage_options' ) );
    }

    private function is_xmlrpc_context(): bool {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return true;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Detects direct `wp_xmlrpc_server::login()` calls in tests where `XMLRPC_REQUEST` is undefined.
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
            if ( isset( $frame['class'] ) && 'wp_xmlrpc_server' === $frame['class'] ) {
                return true;
            }
        }

        return false;
    }
}

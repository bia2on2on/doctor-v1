<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * §4.4 initial-credential guard for OTP-registered / OTP-bound patient accounts.
 *
 * Delegates patient-target classification to the single shared predicate
 * `PatientAccess::patient_target_state()`. Any patient target (`ROLE_PATIENT`
 * or unclosed `cpms_patient_logins` account row without staff authority, or
 * `UNKNOWN` on lookup failure) is blocked on WP password resets, Application
 * Passwords, and XML-RPC auth, while OTP-registered (`cpms_patient_logins`)
 * accounts are blocked on all WP password auth (`authenticate` / `wp_authenticate_user`).
 * Non-XML-RPC legacy `wp_authenticate()` fixtures without `cpms_patient_logins`
 * rows remain untouched for harness compatibility.
 */
final class PatientCredentialGuard {

    public const ERROR_CODE = 'cpms_patient_otp_required';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ?PatientAccess $patient_access = null
    ) {
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
        if ( function_exists( 'add_action' ) && ( ! function_exists( 'has_action' ) || false === has_action( 'validate_password_reset', [ $this, 'action_validate_password_reset' ] ) ) ) {
            add_action( 'validate_password_reset', [ $this, 'action_validate_password_reset' ], 10, 2 );
        }
        if ( function_exists( 'add_action' ) && ( ! function_exists( 'has_action' ) || false === has_action( 'password_reset', [ $this, 'action_password_reset' ] ) ) ) {
            add_action( 'password_reset', [ $this, 'action_password_reset' ], 1, 2 );
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
     * Blocks WordPress password reset key generation for patient targets.
     */
    public function filter_allow_password_reset( bool $allow, int $user_id ): bool {
        if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
            return $allow;
        }

        $user = get_userdata( $user_id );
        if ( $user instanceof WP_User && 'NOT_PATIENT' !== $this->access()->patient_target_state( $user ) ) {
            return false;
        }

        return $allow;
    }

    /**
     * Blocks `wp-login.php?action=rp` / `resetpass` even with an already-valid reset key.
     */
    public function action_validate_password_reset( WP_Error $errors, mixed $user ): void {
        if ( $user instanceof WP_User && 'NOT_PATIENT' !== $this->access()->patient_target_state( $user ) ) {
            $errors->add( self::ERROR_CODE, PatientAccess::PROOF_FAILED_MESSAGE );
        }
    }

    /**
     * Defense-in-depth block on `reset_password()` before `wp_set_password()` runs.
     */
    public function action_password_reset( mixed $user, string $new_pass ): void {
        unset( $new_pass );

        if ( ! $user instanceof WP_User || 'NOT_PATIENT' === $this->access()->patient_target_state( $user ) ) {
            return;
        }

        if ( function_exists( 'wp_die' ) && function_exists( 'esc_html' ) ) {
            wp_die( esc_html( PatientAccess::PROOF_FAILED_MESSAGE ), '', [ 'response' => 403 ] );
        }

        throw new RuntimeException( PatientAccess::PROOF_FAILED_MESSAGE );
    }

    /**
     * Blocks WordPress Application Passwords for patient targets.
     */
    public function filter_app_passwords_available( bool $available, WP_User $user ): bool {
        if ( 'NOT_PATIENT' !== $this->access()->patient_target_state( $user ) ) {
            return false;
        }

        return $available;
    }

    private function access(): PatientAccess {
        if ( null !== $this->patient_access ) {
            return $this->patient_access;
        }
        if ( function_exists( 'get_userdata' ) && class_exists( App::class ) ) {
            return App::patient_access();
        }

        return new PatientAccess( $this->db, null, logins: new PatientLoginRepository( $this->db ) );
    }

    private function should_block_auth( WP_User $user ): bool {
        $access = $this->access();
        if ( 'NOT_PATIENT' === $access->patient_target_state( $user ) ) {
            return false;
        }
        if ( $this->is_xmlrpc_context() ) {
            return true;
        }

        $login_state = $access->has_live_patient_login_account( (int) $user->ID );
        if ( null === $login_state ) {
            return true;
        }

        return $login_state;
    }

    private function is_xmlrpc_context(): bool {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return true;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Detects direct `wp_xmlrpc_server::login()` calls in tests where `XMLRPC_REQUEST` is undefined.
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
            if ( isset( $frame['class'] ) && 'wp_xmlrpc_server' === $frame['class'] ) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use WP_Error;
use WP_User;

/** Request-bound restrictions for a durable restore marker. */
final class RestoreBarrierGate {
    public static function register(): void {
        add_action( 'init', [ self::class, 'guard_worker' ], PHP_INT_MIN );
        add_action( 'admin_init', [ self::class, 'guard_admin' ], PHP_INT_MIN );
        add_action( 'template_redirect', [ self::class, 'guard_frontend' ], PHP_INT_MIN );
        add_filter( 'authenticate', [ self::class, 'guard_login' ], PHP_INT_MAX );
        add_filter( 'rest_authentication_errors', [ self::class, 'guard_rest' ], PHP_INT_MAX );
        add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest' ], PHP_INT_MAX );
        add_filter( 'pre_do_shortcode_tag', [ self::class, 'guard_shortcode' ], PHP_INT_MAX );
    }

    public static function guard_rest( mixed $result ): mixed {
        return self::blocked() ? self::error() : $result;
    }

    public static function guard_login( mixed $result ): mixed {
        if ( ! self::blocked() ) {
            return $result;
        }

        return $result instanceof WP_User && user_can( $result, 'manage_options' ) && user_can( $result, 'cpms_config' ) ? $result : self::error();
    }

    public static function guard_shortcode( mixed $result ): mixed {
        return self::blocked() ? '' : $result;
    }

    public static function guard_frontend(): void {
        if ( self::blocked() ) {
            self::deny();
        }
    }

    public static function guard_worker(): void {
        if ( ! self::blocked() ) {
            return;
        }
        $script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : false;
        if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || dirname( __DIR__, 3 ) . '/bin/cpms' === $script ) {
            self::deny();
        }
    }

    public static function guard_admin(): void {
        if ( ! self::blocked() ) {
            return;
        }
        global $pagenow;
        // Only the read-only recovery screen; no admin-post action is exempted.
        $page   = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Classify a read-only screen, not a mutation.
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
        if ( 'admin.php' === $pagenow && 'GET' === $method && 'cpms-system' === $page && current_user_can( 'manage_options' ) && current_user_can( 'cpms_config' ) ) {
            return;
        }
        self::deny();
    }

    private static function blocked(): bool {
        return ( new RestoreBarrierStore() )->is_set();
    }

    private static function error(): WP_Error {
        return new WP_Error( 'cpms_restore_blocked', 'Site access is temporarily unavailable during restore.', [ 'status' => 503 ] );
    }

    private static function deny(): void {
        wp_die( 'Site access is temporarily unavailable during restore.', '', [ 'response' => 503 ] );
    }
}

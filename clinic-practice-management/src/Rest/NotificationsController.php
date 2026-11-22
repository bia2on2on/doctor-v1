<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * G6 + R2 (F8 — اعلان‌ها):
 *  - GET  /notifications        — Inbox نقش‌خود (منشی/پزشک/بیمار) + شمار خوانده‌نشده
 *  - POST /notifications/read   — علامت‌گذاری خوانده‌شده (ids یا all)
 *  - GET  /rt/notifications     — Real-time Badge (ADR-0007: ETag/304 + since)
 *
 * G6 «نقش خود» سرور-side تضمین می‌شود: Inbox فقط با recipient خود Actor
 * کوئری می‌شود (هیچ پارامتری گیرنده را عوض نمی‌کند).
 */
final class NotificationsController extends RestBase
{
    protected const NS = 'clinic/v1';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ?PatientAccess $patient_access = null
    ) {
    }

    protected function patient_access(): PatientAccess {
        return $this->patient_access ?? parent::patient_access();
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, fn (): array => $this->notifications->inbox(
                    $this->userId($r),
                    (bool) ($r['unread'] ?? false),
                    (int) ($r['limit'] ?? 50)
                )),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->notifications_permission( $r ),
                'args' => [
                    'unread' => ['required' => false, 'type' => 'boolean', 'default' => false],
                    'limit' => ['required' => false, 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/notifications/read', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, function () use ($r): array {
                    $ids = is_array($r['ids'] ?? null) ? array_map('intval', (array) $r['ids']) : [];
                    $marked = $this->notifications->markRead(
                        $this->userId($r),
                        $ids,
                        (bool) ($r['all'] ?? false)
                    );

                    return ['marked' => $marked];
                }),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->notifications_permission( $r ),
                'args' => [
                    'ids' => ['required' => false, 'type' => 'array', 'items' => ['type' => 'integer']],
                    'all' => ['required' => false, 'type' => 'boolean', 'default' => false],
                ],
            ],
        ]);

        // R2 — Real-time Badge (ADR-0007 — Controlled Polling)
        register_rest_route(self::NS, '/rt/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->rtNotifications($r),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->notifications_permission( $r ),
                'args' => [
                    'since' => ['required' => false, 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);
    }

    /**
     * Shared notification permission gate: staff callers across WP roles,
     * global capabilities, Clinic/organization memberships, custom roles,
     * explicit grants, or active clinician identities keep staff behavior;
     * patient requests pass through the central `PatientAccess` gate.
     */
    private function notifications_permission( WP_REST_Request $request ): bool|WP_Error {
        $nonce_error = $this->requireNonce( $request );
        if ( $nonce_error instanceof WP_Error ) {
            return $nonce_error;
        }

        $user = wp_get_current_user();
        if ( ! $user->exists() ) {
            return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید' );
        }

        if ( $this->is_staff_notification_actor( $user ) ) {
            return true;
        }

        return $this->require_patient_access( $request, [ 'mode' => PatientAccess::MODE_RECORDS_LIST ] );
    }

    /**
     * Identifies non-patient callers authorized through a staff branch
     * (WP staff role, global staff capability, Clinic membership/custom role/grant,
     * active clinician identity, or active organization membership).
     */
    private function is_staff_notification_actor( WP_User $user ): bool {
        $user_id = (int) $user->ID;
        $roles   = (array) $user->roles;
        if ( $user_id <= 0 || in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true ) ) {
            return false;
        }

        $staff_roles = [
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_MANAGER,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            'administrator',
        ];
        if ( [] !== array_intersect( $staff_roles, $roles ) ) {
            return true;
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }
        foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
            if ( user_can( $user, $capability ) ) {
                return true;
            }
        }

        try {
            $db          = App::db();
            $memberships = ( new MembershipRepository( $db ) )->active_for_user( $user_id );
            foreach ( $memberships as $membership ) {
                if ( RolesAndCapabilities::ROLE_PATIENT !== (string) ( $membership['role_key'] ?? '' ) ) {
                    return true;
                }
                $clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
                if ( $clinic_id > 0 ) {
                    foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
                        if ( App::authorization_service()->can( $user_id, $clinic_id, $capability ) ) {
                            return true;
                        }
                    }
                }
            }

            $clinician_id = $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant table name + prepared placeholder.
                [ $user_id ]
            );
            if ( null !== $clinician_id ) {
                return true;
            }

            $org_member_id = $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_organization_memberships' ) . " WHERE wp_user_id = %d AND status = 'active' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant table name + prepared placeholder.
                [ $user_id ]
            );
            if ( null !== $org_member_id ) {
                return true;
            }
        } catch ( \Throwable ) {
            return false;
        }

        return false;
    }

    // ================= R2 — ETag/304 =================

    private function rtNotifications(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $guard = $this->guard($r, null);
        if ($guard instanceof WP_Error) {
            return $guard;
        }

        // Polling Guard (ADR-0007) — Badge سبک‌تر از R1: سقف مشابه
        $rl = $this->rateLimit($r, 'rt_notif_' . $this->userId($r), 60, MINUTE_IN_SECONDS);
        if ($rl instanceof WP_Error) {
            return $rl;
        }

        $user_id = $this->userId( $r );
        try {
            $etag          = '"' . $this->notifications->lastId( $user_id ) . '"';
            $if_none_match = trim( (string) $r->get_header( 'If-None-Match' ) );
            if ( $if_none_match === $etag ) {
                $not_modified = new WP_REST_Response( null, 304 );
                $not_modified->header( 'ETag', $etag );

                return $not_modified;
            }

            $payload  = $this->notifications->since( $user_id, (int) ( $r['since'] ?? 0 ) );
            $response = $this->success( $payload );
            $response->header( 'ETag', $etag );

            return $response;
        } catch ( ScopeRequiredException $e ) {
            return $this->error( $e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData() );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational boundary log without leaking raw exception details to client.
            error_log( '[CPMS][NotificationsController] unexpected: ' . get_class( $e ) . ': ' . $e->getMessage() );

            return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
        }
    }

    // ================= Helpers =================

    private function userId(WP_REST_Request $r): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }

    /**
     * Guard استاندارد: Nonce → نقش CPMS (staff یا بیمار متصل) → اجرا.
     *
     * @param callable(): mixed|null $fn
     */
    private function guard(WP_REST_Request $r, ?callable $fn): WP_REST_Response|WP_Error|null
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        $user       = wp_get_current_user();
        $roles      = (array) ( $user->roles ?? [] );
        $is_allowed = in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true )
            || ( $user instanceof WP_User && $this->is_staff_notification_actor( $user ) );
        if ( ! $is_allowed ) {
            return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی به اعلان‌ها برای نقش شما مجاز نیست' );
        }

        if ($fn === null) {
            return null;
        }

        try {
            return $this->success($fn());
        } catch ( ScopeRequiredException $e ) {
            return $this->error( $e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData() );
        } catch (\Throwable $e) {
            error_log('[CPMS][NotificationsController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }
}

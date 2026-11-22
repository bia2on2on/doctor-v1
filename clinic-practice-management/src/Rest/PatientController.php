<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientBindingPromoter;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Security\ClientIp;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای پروفایل بیمار (F3) — API Contract C0–C2 + D2–D5.
 *
 * C*: بیمار (Nonce + نقش) — فقط Data خود.
 * D*: منشی (Nonce + Capability) — Data-Access در Service.
 *
 * Phase 3 Slice 6B: مسیرهای D2–D5 (search/get/update/create کارکنی) علاوه بر
 * لایه‌های قبلی، مجوز Clinic-scoped متناظر را از RestBase::requireClinicPermission
 * می‌گیرند (عضویت فعال پایدار + دقیقاً همان مجوز؛ cap سراسری فقط
 * defense-in-depth). مسیرهای C1/C2 بیمار (me/updateMe) عمداً از مجوزسازی
 * کارکنی Clinic عبور نمی‌کنند — patient-self جدا از عضویت کارکنی است.
 * C0–C2 فقط از user احرازشده + پیوند پایدار + Patient فعال + Clinic ذخیره‌شده
 * resolve می‌شوند؛ مسیرهای D همچنان با App::scope() Clinic-scoped هستند.
 */
final class PatientController extends RestBase
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly ?PatientAccess $patient_access = null,
        private readonly ?PatientBindingPromoter $promoter = null
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NS,
            '/patient/link-record',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => fn ( WP_REST_Request $request ) => $this->require_patient_link_record_access( $request ),
                'callback'            => fn ( WP_REST_Request $request ) => $this->link_record( $request ),
                'args'                => [
                    'clinic_id'   => [ 'required' => false, 'type' => 'integer' ],
                    'mobile'      => [ 'required' => false, 'type' => 'string' ],
                    'code'        => [ 'required' => false, 'type' => 'string' ],
                    'national_id' => [ 'required' => false, 'type' => 'string' ],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/patient/enroll',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => fn ( WP_REST_Request $request ) => $this->requirePatient( $request, PatientAccess::MODE_ENROLL ),
                'callback'            => fn ( WP_REST_Request $request ) => $this->wrap(
                    fn () => $this->patients->enroll_current_patient( (int) $request->get_param( 'clinic_id' ), $this->body( $request ) )
                ),
                'args'                => [ 'clinic_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ] ],
            ]
        );

        // ---------- Patient (C0–C2) ----------
        register_rest_route(
            self::NS,
            '/patient/my-records',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn ( WP_REST_Request $request ) => $this->my_records( $request ),
                    'permission_callback' => fn ( WP_REST_Request $request ) => $this->requirePatient( $request, PatientAccess::MODE_RECORDS_LIST ),
                ],
            ]
        );

        register_rest_route(self::NS, '/patient/me', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->me($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request),
                'args'                => [
                    'link_id' => [ 'required' => false, 'type' => 'integer' ],
                ],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->updateMe($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request),
                'args' => [
                    'link_id'                 => [ 'required' => false, 'type' => 'integer' ],
                    // Owner decision (option B, 2026-11-07): explicit null for a name field is a name
                    // submission, not an absent field — it must reach updateMe() and fail with the clear
                    // CLINIC_VALIDATION_FAILED message. Widening to null is additive for API clients.
                    'first_name'              => ['required' => false, 'type' => [ 'string', 'null' ]],
                    'last_name'               => ['required' => false, 'type' => [ 'string', 'null' ]],
                    'birth_date' => ['required' => false, 'type' => 'string'],
                    'gender' => ['required' => false, 'type' => 'string'],
                    'address' => ['required' => false, 'type' => 'string'],
                    'phone' => ['required' => false, 'type' => 'string'],
                    'emergency_contact_name' => ['required' => false, 'type' => 'string'],
                    'emergency_contact_phone' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- Secretary (D2–D5) ----------
        // Owner-approved API contract change (B0-PR1): the search term is accepted
        // ONLY in the POST body. A patient identifier (e.g. national ID) must never
        // travel in the URL/query string, where it would be written to web-server
        // access logs, browser history and Referer headers.
        register_rest_route(self::NS, '/patients/search', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => fn ( WP_REST_Request $request ) => $this->search( $request ),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::PATIENT_READ),
                'args' => [
                    'q' => ['required' => true, 'type' => 'string'],
                    'limit' => ['required' => false, 'type' => 'integer', 'default' => 25],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/patients/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->get($request),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::PATIENT_READ),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->update($request),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::PATIENT_UPDATE),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/patients', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->create($request),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::PATIENT_CREATE),
                'args' => [
                    'first_name' => ['required' => true, 'type' => 'string'],
                    'last_name' => ['required' => true, 'type' => 'string'],
                    'mobile' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);
    }

    // ---------- Handlers ----------

    private function my_records( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user = wp_get_current_user();

        return $this->wrap( fn () => $this->patients->linked_records( (int) $user->ID ) );
    }

    private function me(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap( fn () => $this->patients->me( (int) $user->ID, $this->link_id( $request ) ) );
    }

    private function updateMe(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user    = wp_get_current_user();
        $link_id = $this->link_id( $request );
        $fields  = $this->body( $request );
        unset( $fields['link_id'], $fields['clinic_id'] );

        return $this->wrap( fn () => $this->patients->updateMe( (int) $user->ID, $fields, $link_id ) );
    }

    private function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Phase 3 Slice 6B — D2 مسیر کارکنی: مجوز Clinic-scoped cpms_patient_read.
        $denied = $this->requireClinicPermission(RolesAndCapabilities::PATIENT_READ);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        // B0-PR1 body-only contract: a term supplied through the URL is refused
        // outright — it is never searched, never logged and never echoed back.
        if ( array_key_exists( 'q', $request->get_query_params() ) ) {
            return $this->error( 'CLINIC_VALIDATION_FAILED', 400, 'عبارت جستجو باید در بدنهٔ درخواست ارسال شود' );
        }

        $body  = $this->body_only( $request );
        $q     = $body['q'] ?? null;
        $limit = $body['limit'] ?? 25;
        if ( ! is_string( $q ) ) {
            return $this->error( 'CLINIC_VALIDATION_FAILED', 400, 'عبارت جستجو باید رشته باشد' );
        }

        return $this->wrap( fn () => $this->patients->search( $q, is_numeric( $limit ) ? (int) $limit : 25 ) );
    }

    private function get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Phase 3 Slice 6B — D3 مسیر کارکنی: مجوز Clinic-scoped cpms_patient_read.
        $denied = $this->requireClinicPermission(RolesAndCapabilities::PATIENT_READ);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        return $this->wrap(fn () => $this->patients->get((int) $request->get_param('id')));
    }

    private function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Phase 3 Slice 6B — D5 مسیر کارکنی: مجوز Clinic-scoped cpms_patient_update.
        $denied = $this->requireClinicPermission(RolesAndCapabilities::PATIENT_UPDATE);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();
        $fields = $this->body($request);

        return $this->wrap(fn () => $this->patients->update(
            (int) $request->get_param('id'),
            $fields,
            (int) $user->ID
        ));
    }

    /**
     * Two-factor promotion of a target Clinic patient record into an active
     * durable binding + live session proof (§6.2).
     */
    private function link_record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $query = $request->get_query_params();
        if (
            is_array( $query )
            && (
                array_key_exists( 'national_id', $query )
                || array_key_exists( 'code', $query )
                || array_key_exists( 'mobile', $query )
            )
        ) {
            return $this->error( PatientAccess::PROOF_FAILED_CODE, 403, PatientAccess::PROOF_FAILED_MESSAGE );
        }

        $body       = $this->body_only( $request );
        $clinic_raw = $body['clinic_id'] ?? $request->get_header( 'X-CPMS-Clinic-Id' );
        $clinic_id  = is_numeric( $clinic_raw ) ? (int) $clinic_raw : 0;
        $raw_mobile = is_string( $body['mobile'] ?? null ) ? (string) $body['mobile'] : '';
        $raw_nid    = is_string( $body['national_id'] ?? null ) ? (string) $body['national_id'] : '';
        $raw_code   = is_string( $body['code'] ?? null ) ? (string) $body['code'] : null;
        $user_id    = (int) get_current_user_id();
        if ( '' === trim( $raw_mobile ) && ( null === $raw_code || '' === trim( $raw_code ) ) && $user_id > 0 ) {
            try {
                $login_repo = new PatientLoginRepository( App::db() );
                if ( $login_repo->table_exists() ) {
                    $account = $login_repo->find_account_by_user_id( $user_id );
                    if (
                        is_array( $account )
                        && PatientLoginRepository::STATE_CLOSED !== (string) ( $account['state'] ?? '' )
                    ) {
                        $raw_mobile = (string) ( $account['mobile'] ?? '' );
                    }
                }
            } catch ( \Throwable ) {
                $raw_mobile = '';
            }
        }
        $ip         = ClientIp::resolve() ?? '0.0.0.0';
        $promoter   = $this->promoter ?? App::patient_binding_promoter();

        if ( $clinic_id <= 0 ) {
            return $this->error( PatientAccess::PROOF_FAILED_CODE, 403, PatientAccess::PROOF_FAILED_MESSAGE );
        }

        return $this->wrap(
            static fn (): array => $promoter->promote(
                $user_id,
                $clinic_id,
                $raw_mobile,
                $raw_nid,
                $raw_code,
                $ip
            )
        );
    }

    private function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Phase 3 Slice 6B — D4 مسیر کارکنی: مجوز Clinic-scoped cpms_patient_create.
        $denied = $this->requireClinicPermission(RolesAndCapabilities::PATIENT_CREATE);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();
        $fields = $this->body($request);

        return $this->wrap(fn () => $this->patients->create($fields, (int) $user->ID));
    }

    // ---------- Helpers ----------

    protected function patient_access(): PatientAccess {
        return $this->patient_access ?? parent::patient_access();
    }

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Established private helper name.
    private function requirePatient( WP_REST_Request $request, string $mode = PatientAccess::MODE_CLINICAL ): bool|WP_Error {
        return $this->require_patient_access( $request, [ 'mode' => $mode ] );
    }

    private function link_id( WP_REST_Request $request ): ?int {
        $link_id = $request->get_param( 'link_id' );

        return $link_id === null || $link_id === '' ? null : (int) $link_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    /**
     * Body-only parameters (JSON body first, then form body). URL/query params are
     * deliberately excluded so an identifier can never be read out of the URL.
     *
     * @return array<string, mixed>
     */
    private function body_only( WP_REST_Request $request ): array {
        $json = $request->get_json_params();
        if ( is_array( $json ) ) {
            return $json;
        }
        $form = $request->get_body_params();

        return is_array( $form ) ? $form : [];
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return WP_REST_Response|WP_Error
     */
    private function wrap(callable $fn): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), 200);
        } catch (BookingException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        }
    }
}

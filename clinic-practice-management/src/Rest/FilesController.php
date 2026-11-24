<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Clinical\MedicalFileService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Endpointهای فایل پزشکی (F5) — E16/E17 (کارکنان) + C3/C4 (بیمار).
 *
 * **F-1 (file-storage.md):** خروجی فایل فقط از `/files/{id}/stream` با
 * Permission Check — هیچ URL عمومی وجود ندارد؛ پاسخ این Endpoint باینری
 * است (نه Envelope JSON) و خودش Audit/Authorization را از Service می‌گیرد.
 *
 * RateLimit آپلود: 10/hr (file-storage.md §3).
 */
final class FilesController extends RestBase
{
    private const UPLOAD_RATE_MAX = 10;
    private const UPLOAD_RATE_WINDOW = 3600;

    public function __construct(
        private readonly MedicalFileService $files,
        private readonly ?PatientAccess $patient_access = null
    ) {
    }

    protected function patient_access(): PatientAccess {
        return $this->patient_access ?? parent::patient_access();
    }

    public function register_routes(): void
    {
        // ---------- E16 — آپلود کارکنان ----------
        register_rest_route(self::NS, '/files', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::FILE_UPLOAD,
                    fn () => $this->files->upload(
                        $this->userId($r),
                        $this->uploadedFile($r),
                        (int) $r['patient_id'],
                        isset($r['visit_id']) ? (int) $r['visit_id'] : null,
                        (string) ($r['category'] ?? 'other'),
                        (string) ($r['visibility'] ?? 'patient_visible')
                    )),
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::FILE_UPLOAD),
                'args' => [
                    'patient_id' => ['required' => true, 'type' => 'integer'],
                    'visit_id' => ['required' => false, 'type' => 'integer'],
                    'category' => ['required' => false, 'type' => 'string', 'default' => 'other'],
                    'visibility' => ['required' => false, 'type' => 'string', 'default' => 'patient_visible'],
                ],
            ],
        ]);

        // ---------- E17 — Stream مجوزیافته ----------
        register_rest_route(self::NS, '/files/(?P<id>\d+)/stream', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->stream($r),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->stream_permission( $r ),
            ],
        ]);

        // ---------- C3 — آپلود بیمار ----------
        // `link_id` (اختیاری، integer): انتخاب رکورد بیمار در حالت چندپرونده‌ای —
        // همان سیاستِ پروفایل/ویزیت/نسخه (بدون `link_id` و با بیش از یک رکورد فعال:
        // 422). هیچ کلیدک کلاینت دیگری (clinic_id/organization_id/role) مجوز نمی‌سازد.
        register_rest_route(
            self::NS,
            '/patients/(?P<patient_id>\d+)/files',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn ( WP_REST_Request $r ) => $this->patient( $r, fn () => $this->patient_upload( $r ), true ),
                    'permission_callback' => fn ( WP_REST_Request $r ) => $this->require_patient_access(
                        $r,
                        [
                            'mode'       => PatientAccess::MODE_PATIENT_RESOURCE,
                            'clinic_id'  => null,
                            'patient_id' => $this->path_patient_id( $r ),
                        ]
                    ),
                    'args'                => [
                        'category' => [ 'required' => false, 'type' => 'string', 'default' => 'other' ],
                        'link_id'  => [ 'required' => false, 'type' => 'integer' ],
                    ],
                ],
                // ---------- C4 — فهرست فایل‌های بیمار ----------
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn ( WP_REST_Request $r ) => $this->patient( $r, fn () => $this->patient_files( $r ) ),
                    'permission_callback' => fn ( WP_REST_Request $r ) => $this->require_patient_access(
                        $r,
                        [
                            'mode'       => PatientAccess::MODE_PATIENT_RESOURCE,
                            'clinic_id'  => null,
                            'patient_id' => $this->path_patient_id( $r ),
                        ]
                    ),
                    'args'                => [
                        'link_id' => [ 'required' => false, 'type' => 'integer' ],
                    ],
                ],
            ]
        );
    }

    // ================= Handlers =================

    /**
     * E17 — پاسخ باینری با هدرهای صحیح (نه Envelope).
     */
    private function stream(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        // احراز هویت (لایه 1) — Stream Capability واحد ندارد؛ مجوز در Service
        // سطح Resource بررسی می‌شود، ولی کاربر ناشناس قبل از آن 401 می‌گیرد.
        if (!wp_get_current_user()->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }

        try {
            $file = $this->files->stream($this->userId($r), (int) $r['id']);
        } catch (ClinicalException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][FilesController] unexpected: ' . get_class($e) . ': ' . NationalIdValidator::redact_digits( $e->getMessage() ));

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }

        $response = new WP_REST_Response($file['content'], 200);
        $response->header('Content-Type', $file['mime_type']);
        $response->header('Content-Length', (string) $file['size']);
        $response->header('Content-Disposition', 'attachment; filename="' . rawurlencode($file['original_filename']) . '"');
        $response->header('Cache-Control', 'private, max-age=0, no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    // ================= Guards =================

    /**
     * Shared stream gate: keeps staff branches identical to `main` while
     * routing patient requests through the central `PatientAccess` gate.
     */
    private function stream_permission( WP_REST_Request $request ): bool|WP_Error {
        $nonce_error = $this->requireNonce( $request );
        if ( $nonce_error instanceof WP_Error ) {
            return $nonce_error;
        }

        $user = wp_get_current_user();
        if ( ! $user->exists() ) {
            return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید' );
        }

        if ( $this->is_stream_staff_actor( $user ) ) {
            return true;
        }

        return $this->require_patient_access(
            $request,
            [
                'mode'       => PatientAccess::MODE_CLINICAL,
                'clinic_id'  => null,
                'patient_id' => null,
                'link_id'    => null,
            ]
        );
    }

    /**
     * Identifies non-patient callers authorized through a staff branch
     * (WP staff role, global staff capability, Clinic membership/custom role/grant,
     * active clinician identity, or active organization membership).
     */
    private function is_stream_staff_actor( WP_User $user ): bool {
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
            }

            $clinician_id = $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
                [ $user_id ]
            );
            if ( null !== $clinician_id ) {
                return true;
            }

            $org_member_id = $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_organization_memberships' ) . " WHERE wp_user_id = %d AND status = 'active' LIMIT 1",
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

    /**
     * @template T
     *
     * @param callable(): T $fn
     */
    private function staff(WP_REST_Request $r, string $cap, callable $fn): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }
        $limited = $this->guardUploadRate($r);
        if ($limited instanceof WP_Error) {
            return $limited;
        }

        return $this->wrap($fn, 201);
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     */
    private function patient(WP_REST_Request $r, callable $fn, bool $rateLimitUpload = false): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }
        if (!in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true)) {
            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }
        if ($rateLimitUpload) {
            $limited = $this->guardUploadRate($r);
            if ($limited instanceof WP_Error) {
                return $limited;
            }
        }

        return $this->wrap($fn, $rateLimitUpload ? 201 : 200);
    }

    /**
     * C3 — آپلود روی رکوردِ انتخاب‌شده (Slice 7): `link_id` اختیاری فقط
     * سلکتورِ رکورد است؛ مجوز و مقصد را سرور از لینک پایدار حل می‌کند.
     *
     * @return array<string, mixed>
     */
    private function patient_upload( WP_REST_Request $r ): array {
        return $this->files->patientUpload(
            $this->userId( $r ),
            $this->uploadedFile( $r ),
            $this->path_patient_id( $r ),
            (string) ( $r['category'] ?? 'other' ),
            isset( $r['link_id'] ) ? (int) $r['link_id'] : null
        );
    }

    /**
     * C4 — فهرست فایل‌های رکوردِ انتخاب‌شده (Slice 7).
     *
     * @return array{files: list<array<string, mixed>>}
     */
    private function patient_files( WP_REST_Request $r ): array {
        return [
            'files' => $this->files->patientFiles(
                $this->userId( $r ),
                $this->path_patient_id( $r ),
                isset( $r['link_id'] ) ? (int) $r['link_id'] : null
            ),
        ];
    }

    private function guardUploadRate(WP_REST_Request $r): ?WP_Error
    {
        $userId = $this->userId($r);
        $limited = $this->rateLimit($r, 'files:upload:' . $userId, self::UPLOAD_RATE_MAX, self::UPLOAD_RATE_WINDOW);
        if ($limited instanceof WP_Error) {
            return $limited;
        }

        return null;
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     */
    private function wrap(callable $fn, int $status = 200): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), $status);
        } catch (ClinicalException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][FilesController] unexpected: ' . get_class($e) . ': ' . NationalIdValidator::redact_digits( $e->getMessage() ));

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    // ================= Helpers =================

    private function userId(WP_REST_Request $r): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }

    /**
     * `patient_id` مسیر فقط هویتِ شیء/سلکتور است — همیشه از پارامترهای خودِ مسیر
     * خوانده می‌شود (نه `$r[...]` که پارامترهای query می‌توانند بر آن سایه
     * بیندازند). مجوز هرگز از اینجا نمی‌آید؛ سرور آن را از لینک پایدار حل می‌کند.
     */
    private function path_patient_id( WP_REST_Request $r ): int {
        $url = $r->get_url_params();

        return isset( $url['patient_id'] ) ? (int) $url['patient_id'] : 0;
    }

    /**
     * فایل آپلودشده از multipart — shape استاندارد $_FILES.
     *
     * @return array{name?: string, tmp_name?: string, size?: int, error?: int}
     */
    private function uploadedFile(WP_REST_Request $r): array
    {
        $files = $r->get_file_params();
        if (!is_array($files) || !isset($files['file']) || !is_array($files['file'])) {
            return [];
        }

        return $files['file'];
    }
}

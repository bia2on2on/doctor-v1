<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use Throwable;
use WP_User;

/**
 * Central, fail-closed authorization gate for patient-role access to Clinic
 * and Patient records.
 *
 * Architectural boundary (Slice A): this gate evaluates existing durable
 * patient-user bindings (`cpms_patient_user_links`, active `cpms_patients`,
 * `cpms_clinics`, and `cpms_patient_identity_links` / `cpms_organizations`)
 * through injectable resolver seams. Enforcement against `cpms_patient_logins`
 * and per-session OTP/`national_id` two-factor proofs is deferred to follow-up
 * slices without changing controller callers.
 */
final class PatientAccess {

    public const MODE_CLINICAL            = 'clinical';
    public const MODE_PATIENT_RESOURCE    = 'patient_resource';
    public const MODE_RECORDS_LIST        = 'records_list';
    public const MODE_BOOKING_APPOINTMENT = 'booking_appointment';
    public const MODE_ENROLL              = 'enroll';

    public const UNAUTHORIZED_CODE    = 'CLINIC_UNAUTHORIZED';
    public const UNAUTHORIZED_MESSAGE = 'وارد نشده‌اید';

    public const FORBIDDEN_CODE    = 'CLINIC_PERMISSION_DENIED';
    public const FORBIDDEN_MESSAGE = 'دسترسی ندارید';

    public const NOT_FOUND_CODE    = 'CLINIC_NOT_FOUND';
    public const NOT_FOUND_MESSAGE = 'بیماری به این حساب متصل نیست';

    public const LEVEL_NONE    = 'none';
    public const LEVEL_BOOKING = 'booking';
    public const LEVEL_FULL    = 'full';

    public const PROOF_FAILED_CODE    = 'CLINIC_PATIENT_PROOF_FAILED';
    public const PROOF_FAILED_MESSAGE = 'احراز هویت دو مرحله‌ای بیمار انجام نشد؛ لطفاً کد پیامکی معتبر و کد ملی منطبق را در همین نشست بررسی کنید.';

    private readonly ?CpmsDb $db;
    private readonly ?PatientRepository $patients;
    private readonly ?MembershipRepository $memberships;
    private readonly ?AuthorizationService $authorization;
    private readonly ?PatientLoginRepository $logins;
    private readonly PatientSessionProofStore $proofs;

    /**
     * In-process per-REST-dispatch cache of WP user IDs already proved to hold
     * no clinic-scoped staff authority during the current `WP_REST_Server::dispatch()`.
     * Cleared at `rest_pre_dispatch`, `rest_post_dispatch`, and `App::resetScope()`.
     *
     * @var array<int, true>
     */
    private static array $rest_verified_non_staff_users = [];

    /**
     * Marks `$wp_user_id` as verified non-staff for the remainder of the current
     * REST dispatch so service-layer defense-in-depth (`proved_patient_ids()`)
     * does not re-run `MembershipRepository::active_clinic_ids_for_user()`.
     */
    public static function mark_rest_request_verified( int $wp_user_id ): void {
        if ( $wp_user_id > 0 ) {
            self::$rest_verified_non_staff_users[ $wp_user_id ] = true;
        }
    }

    /**
     * Clears per-REST-dispatch non-staff verification state.
     */
    public static function clear_rest_request_verifications(): void {
        self::$rest_verified_non_staff_users = [];
    }

    /**
     * Optional user resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): ?array{exists: bool, roles: list<string>, has_staff_cap?: bool, caps?: array<string, mixed>})|null
     */
    private $user_reader;

    /**
     * Optional link-target resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): ?array{clinic_id: int, patient_id: int})|null
     */
    private $link_resolver;

    /**
     * Optional enrollment-eligibility resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): bool)|null
     */
    private $enroll_checker;

    /**
     * Optional internal observer callback invoked on every authorize_request()
     * evaluation for gate-completeness verification.
     *
     * @var (callable(array<string, mixed>): void)|null
     */
    private $decision_observer;

    /**
     * Optional clinic-scoped staff authority resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): bool)|null
     */
    private $scoped_staff_checker;

    /**
     * Optional booking link-target resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): ?array{clinic_id: int, patient_id: int})|null
     */
    private $booking_link_resolver;

    /**
     * Optional two-proof bindings reader seam for pure-PHP unit tests.
     *
     * @var (callable(int, ?int): list<array{binding_id: int, clinic_id: int, patient_id: int, verified_at: string, mobile: string, full_identity?: bool}>)|null
     */
    private $two_proof_bindings_reader;

    /**
     * Construct the central patient access gate.
     *
     * @param CpmsDb|null                                                                                                                                      $db                         Database adapter.
     * @param PatientRepository|null                                                                                                                           $patients                   Patient repository.
     * @param (callable(int): ?array{exists: bool, roles: list<string>, has_staff_cap?: bool, caps?: array<string, mixed>})|null                              $user_reader                Optional user reader seam.
     * @param (callable(int, int): ?array{clinic_id: int, patient_id: int})|null                                                                               $link_resolver              Optional link resolver seam.
     * @param (callable(int, int): bool)|null                                                                                                                  $enroll_checker             Optional enroll eligibility seam.
     * @param (callable(array<string, mixed>): void)|null                                                                                                      $decision_observer          Optional decision observer seam.
     * @param (callable(int): bool)|null                                                                                                                       $scoped_staff_checker       Optional scoped staff authority seam.
     * @param MembershipRepository|null                                                                                                                        $memberships                Membership repository.
     * @param AuthorizationService|null                                                                                                                        $authorization              Clinic-scoped authorization service.
     * @param (callable(int, int): ?array{clinic_id: int, patient_id: int})|null                                                                               $booking_link_resolver      Optional booking link resolver seam.
     * @param PatientLoginRepository|null                                                                                                                      $logins                     Patient login repository.
     * @param PatientSessionProofStore|null                                                                                                                    $proofs                     Session proof store.
     * @param (callable(int, ?int): list<array{binding_id: int, clinic_id: int, patient_id: int, verified_at: string, mobile: string, full_identity?: bool}>)|null $two_proof_bindings_reader  Optional pure-PHP unit test seam.
     */
    public function __construct(
        ?CpmsDb $db = null,
        ?PatientRepository $patients = null,
        ?callable $user_reader = null,
        ?callable $link_resolver = null,
        ?callable $enroll_checker = null,
        ?callable $decision_observer = null,
        ?callable $scoped_staff_checker = null,
        ?MembershipRepository $memberships = null,
        ?AuthorizationService $authorization = null,
        ?callable $booking_link_resolver = null,
        ?PatientLoginRepository $logins = null,
        ?PatientSessionProofStore $proofs = null,
        ?callable $two_proof_bindings_reader = null
    ) {
        $this->db                        = $db;
        $this->patients                  = $patients ?? ( null !== $db ? new PatientRepository( $db ) : null );
        $this->memberships               = $memberships ?? ( null !== $db ? new MembershipRepository( $db ) : null );
        $this->authorization             = $authorization ?? ( null !== $db && null !== $this->memberships ? new AuthorizationService( $this->memberships, new CustomRoleRepository( $db ) ) : null );
        $this->logins                    = $logins ?? ( null !== $db ? new PatientLoginRepository( $db ) : null );
        $this->proofs                    = $proofs ?? new PatientSessionProofStore();
        $this->user_reader               = $user_reader;
        $this->link_resolver             = $link_resolver;
        $this->enroll_checker            = $enroll_checker;
        $this->decision_observer         = $decision_observer;
        $this->scoped_staff_checker      = $scoped_staff_checker;
        $this->booking_link_resolver     = $booking_link_resolver;
        $this->two_proof_bindings_reader = $two_proof_bindings_reader;
    }

    /**
     * May this authenticated patient-role user act inside `$clinic_id` with full identity proof?
     */
    public function can_access_clinic( int $wp_user_id, int $clinic_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }

        return self::LEVEL_FULL === $this->level( $wp_user_id, null, $clinic_id );
    }

    /**
     * May this authenticated patient-role user act on `$patient_id` in `$clinic_id` with full identity proof?
     */
    public function can_access_patient( int $wp_user_id, int $clinic_id, int $patient_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            return false;
        }

        return self::LEVEL_FULL === $this->level( $wp_user_id, $patient_id, $clinic_id );
    }

    /**
     * May this authenticated patient-role user self-enroll inside `$clinic_id`?
     */
    public function can_enroll_in_clinic( int $wp_user_id, int $clinic_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }
        if ( self::LEVEL_FULL !== $this->level( $wp_user_id, null, null ) ) {
            return false;
        }

        return $this->has_enrollment_eligibility( $wp_user_id, $clinic_id );
    }

    /**
     * Checks whether `$wp_user_id` is an authenticated pure-patient actor
     * (no staff WP roles, capabilities, clinic memberships, or organization memberships).
     */
    public function is_pure_patient_actor( int $wp_user_id ): bool {
        return $this->is_authenticated_patient( $wp_user_id );
    }

    /**
     * Checks whether `$wp_user_id` holds any Clinic-scoped or Organization-scoped
     * staff authority (fail-closed on lookup failure).
     */
    public function is_scoped_staff_actor( int $wp_user_id ): bool {
        return $this->has_scoped_staff_authority( $wp_user_id );
    }

    /**
     * Computes the two-factor patient access level (`none` | `booking` | `full`)
     * for `$wp_user_id`, failing closed on any missing proof, inactive account,
     * inactive/unverified binding, or DB error (§2.1, §2.7).
     */
    public function level(
        int $wp_user_id,
        ?int $patient_id = null,
        ?int $clinic_id = null,
        ?int $now = null,
        ?string $token = null
    ): string {
        if ( $wp_user_id <= 0 ) {
            return self::LEVEL_NONE;
        }
        if ( null !== $patient_id && $patient_id <= 0 ) {
            return self::LEVEL_NONE;
        }
        if ( null !== $clinic_id && $clinic_id <= 0 ) {
            return self::LEVEL_NONE;
        }
        if ( ! $this->is_authenticated_patient( $wp_user_id ) ) {
            return self::LEVEL_NONE;
        }

        return $this->level_for_verified_patient( $wp_user_id, $patient_id, $clinic_id, $now, $token );
    }

    /**
     * Computes the two-factor patient access level after the caller has already
     * verified pure-patient actor separation so `has_scoped_staff_authority()`
     * runs at most once per authorization call.
     */
    private function level_for_verified_patient(
        int $wp_user_id,
        ?int $patient_id = null,
        ?int $clinic_id = null,
        ?int $now = null,
        ?string $token = null
    ): string {
        $use_unit_seam = null === $this->logins && null === $this->db && null !== $this->two_proof_bindings_reader;
        if ( ! $use_unit_seam && ( null === $this->logins || null === $this->db || null === $this->patients ) ) {
            return self::LEVEL_NONE;
        }

        try {
            if ( $use_unit_seam ) {
                $bindings = ( $this->two_proof_bindings_reader )( $wp_user_id, $clinic_id );
            } else {
                $account = $this->logins->find_account_by_user_id( $wp_user_id );
                if ( null === $account || PatientLoginRepository::STATE_ACTIVE !== (string) ( $account['state'] ?? '' ) ) {
                    return self::LEVEL_NONE;
                }

                $bindings = $this->logins->find_active_bindings_for_user( $wp_user_id, $clinic_id );
            }

            if ( [] === $bindings ) {
                return self::LEVEL_NONE;
            }

            $proved_bindings = [];
            foreach ( $bindings as $binding ) {
                $b_clinic_id  = (int) ( $binding['clinic_id'] ?? 0 );
                $b_patient_id = (int) ( $binding['patient_id'] ?? 0 );
                if ( null !== $clinic_id && $b_clinic_id !== $clinic_id ) {
                    continue;
                }
                if ( null !== $patient_id && $b_patient_id !== $patient_id ) {
                    continue;
                }
                if ( $this->proofs->has_valid_binding_proof(
                    $wp_user_id,
                    $b_clinic_id,
                    $b_patient_id,
                    (int) ( $binding['binding_id'] ?? 0 ),
                    (string) ( $binding['verified_at'] ?? '' ),
                    (string) ( $binding['mobile'] ?? '' ),
                    $now,
                    $token
                ) ) {
                    $proved_bindings[] = $binding;
                }
            }

            if ( [] === $proved_bindings ) {
                return self::LEVEL_NONE;
            }

            foreach ( $proved_bindings as $proved ) {
                $full_identity = $use_unit_seam
                    ? (bool) ( $proved['full_identity'] ?? false )
                    : $this->has_full_identity_edges(
                        $wp_user_id,
                        (int) ( $proved['clinic_id'] ?? 0 ),
                        (int) ( $proved['patient_id'] ?? 0 )
                    );
                if ( $full_identity ) {
                    return self::LEVEL_FULL;
                }
            }

            return self::LEVEL_BOOKING;
        } catch ( Throwable ) {
            return self::LEVEL_NONE;
        }
    }

    /**
     * Returns the intersection of active bindings and session-proved patient IDs (§2.8).
     *
     * @return list<int>
     */
    public function proved_patient_ids(
        int $wp_user_id,
        ?int $clinic_id = null,
        ?int $now = null,
        ?string $token = null
    ): array {
        if ( $wp_user_id <= 0 || ! $this->is_authenticated_patient_for_proved_lookup( $wp_user_id ) ) {
            return [];
        }

        $use_unit_seam = null === $this->logins && null === $this->db && null !== $this->two_proof_bindings_reader;
        if ( ! $use_unit_seam && null === $this->logins ) {
            return [];
        }

        try {
            $bindings = $use_unit_seam
                ? ( $this->two_proof_bindings_reader )( $wp_user_id, $clinic_id )
                : $this->logins->find_active_bindings_for_user( $wp_user_id, $clinic_id );

            return $this->proofs->proved_patient_ids( $wp_user_id, $bindings, $now, $token );
        } catch ( Throwable ) {
            return [];
        }
    }

    /**
     * Fail-closed two-proof authorization check returning the uniform §6.10
     * envelope (`403 CLINIC_PATIENT_PROOF_FAILED`) for every denial cause.
     *
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    public function authorize_two_proof_request(
        int $wp_user_id,
        ?int $clinic_id = null,
        ?int $patient_id = null,
        ?int $link_id = null,
        string $mode = self::MODE_CLINICAL,
        bool $selector_invalid = false,
        ?int $now = null,
        ?string $token = null
    ): array {
        if ( $wp_user_id <= 0 ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'unauthenticated', 'anonymous' )
            );
        }

        $user         = $this->read_user( $wp_user_id );
        $primary_role = ( null !== $user && [] !== $user['roles'] ) ? $user['roles'][0] : 'unknown';

        if ( null === $user || ! $user['exists'] ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'unauthenticated', 'anonymous' )
            );
        }

        if ( ! $this->is_pure_patient_user( $user ) || $this->has_scoped_staff_authority( $wp_user_id ) ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'patient_role_required', $primary_role )
            );
        }

        if (
            $selector_invalid
            || ( null !== $clinic_id && $clinic_id <= 0 )
            || ( null !== $patient_id && $patient_id <= 0 )
            || ( null !== $link_id && $link_id <= 0 )
        ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'invalid_selector', $primary_role )
            );
        }

        if ( ! in_array(
            $mode,
            [
                self::MODE_CLINICAL,
                self::MODE_PATIENT_RESOURCE,
                self::MODE_RECORDS_LIST,
                self::MODE_BOOKING_APPOINTMENT,
                self::MODE_ENROLL,
            ],
            true
        ) ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'invalid_mode', $primary_role )
            );
        }

        if ( self::MODE_PATIENT_RESOURCE === $mode && ( null === $patient_id || $patient_id <= 0 ) ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'patient_required', $primary_role )
            );
        }

        if ( null !== $link_id ) {
            $target = self::MODE_BOOKING_APPOINTMENT === $mode
                ? $this->resolve_booking_link_target( $wp_user_id, $link_id )
                : $this->resolve_link_target( $wp_user_id, $link_id );
            if ( null === $target ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->proof_failed_decision( 'link_not_found', $primary_role )
                );
            }
            if ( null !== $clinic_id && $clinic_id !== $target['clinic_id'] ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->proof_failed_decision( 'link_clinic_mismatch', $primary_role )
                );
            }
            if ( null !== $patient_id && $patient_id !== $target['patient_id'] ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->proof_failed_decision( 'link_patient_mismatch', $primary_role )
                );
            }
            $clinic_id  = $target['clinic_id'];
            $patient_id = $target['patient_id'];
        }

        if ( self::MODE_ENROLL === $mode ) {
            if ( null === $clinic_id || null !== $patient_id || null !== $link_id ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->proof_failed_decision( 'enroll_ineligible', $primary_role )
                );
            }
            $has_full_session = self::LEVEL_FULL === $this->level_for_verified_patient( $wp_user_id, null, null, $now, $token );
            $ok               = $has_full_session && $this->has_enrollment_eligibility( $wp_user_id, $clinic_id );
            $decision         = $ok
                ? $this->allow_decision( $primary_role )
                : $this->proof_failed_decision( 'enroll_ineligible', $primary_role );

            return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
        }

        if ( self::MODE_CLINICAL === $mode && null === $clinic_id && null !== $patient_id && null === $link_id ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->proof_failed_decision( 'patient_without_clinic', $primary_role )
            );
        }

        $level = $this->level_for_verified_patient( $wp_user_id, $patient_id, $clinic_id, $now, $token );
        $ok    = match ( $mode ) {
            self::MODE_CLINICAL, self::MODE_PATIENT_RESOURCE => self::LEVEL_FULL === $level,
            self::MODE_BOOKING_APPOINTMENT, self::MODE_RECORDS_LIST => in_array( $level, [ self::LEVEL_BOOKING, self::LEVEL_FULL ], true ),
            default => false,
        };

        $decision = $ok
            ? $this->allow_decision( $primary_role )
            : $this->proof_failed_decision( 'two_proof_required', $primary_role );

        return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
    }

    /**
     * Evaluates an actor/selector gate for `POST /clinic/v1/patient/link-record`
     * without requiring a pre-existing target-clinic binding (§6.2, §6.10).
     *
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    public function authorize_link_record_request(
        int $wp_user_id,
        ?int $clinic_id = null,
        bool $selector_invalid = false
    ): array {
        if ( $wp_user_id <= 0 ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                null,
                null,
                'link_record',
                $this->proof_failed_decision( 'unauthenticated', 'anonymous' )
            );
        }

        $user         = $this->read_user( $wp_user_id );
        $primary_role = ( null !== $user && [] !== $user['roles'] ) ? $user['roles'][0] : 'unknown';

        if ( null === $user || ! $user['exists'] ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                null,
                null,
                'link_record',
                $this->proof_failed_decision( 'unauthenticated', 'anonymous' )
            );
        }

        if ( ! $this->is_pure_patient_user( $user ) || $this->has_scoped_staff_authority( $wp_user_id ) ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                null,
                null,
                'link_record',
                $this->proof_failed_decision( 'patient_role_required', $primary_role )
            );
        }

        if ( $selector_invalid || null === $clinic_id || $clinic_id <= 0 ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                null,
                null,
                'link_record',
                $this->proof_failed_decision( 'invalid_selector', $primary_role )
            );
        }

        return $this->observe_decision(
            $wp_user_id,
            $clinic_id,
            null,
            null,
            'link_record',
            $this->allow_decision( $primary_role )
        );
    }

    /**
     * Evaluates an actor/selector gate for `POST /clinic/v1/patient/register`.
     *
     * Only unauthenticated guests (`$wp_user_id === 0`) with a valid positive
     * `clinic_id` selector may enter registration; any authenticated user (staff,
     * multi-role, or already-authenticated patient) is denied with the uniform
     * §6.10 failure envelope.
     *
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    public function authorize_register_request(
        int $wp_user_id,
        ?int $clinic_id = null,
        bool $selector_invalid = false
    ): array {
        if ( $wp_user_id > 0 ) {
            $user         = $this->read_user( $wp_user_id );
            $primary_role = ( null !== $user && [] !== $user['roles'] ) ? $user['roles'][0] : 'unknown';

            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                null,
                null,
                'register',
                $this->proof_failed_decision( 'authenticated_not_allowed', $primary_role )
            );
        }

        if ( $selector_invalid || null === $clinic_id || $clinic_id <= 0 ) {
            return $this->observe_decision(
                0,
                $clinic_id,
                null,
                null,
                'register',
                $this->proof_failed_decision( 'invalid_selector', 'anonymous' )
            );
        }

        return $this->observe_decision(
            0,
            $clinic_id,
            null,
            null,
            'register',
            $this->allow_decision( 'anonymous' )
        );
    }

    /**
     * Evaluates a patient REST request against authentication, patient-role
     * separation, two-factor session proofs, active bindings, and selectors (D1).
     *
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    public function authorize_request(
        int $wp_user_id,
        ?int $clinic_id = null,
        ?int $patient_id = null,
        ?int $link_id = null,
        string $mode = self::MODE_CLINICAL,
        bool $selector_invalid = false
    ): array {
        return $this->authorize_two_proof_request(
            $wp_user_id,
            $clinic_id,
            $patient_id,
            $link_id,
            $mode,
            $selector_invalid
        );
    }

    private function is_authenticated_patient( int $wp_user_id ): bool {
        $user = $this->read_user( $wp_user_id );
        if ( null === $user ) {
            return false;
        }

        return $this->is_pure_patient_user( $user ) && ! $this->has_scoped_staff_authority( $wp_user_id );
    }

    private function is_authenticated_patient_for_proved_lookup( int $wp_user_id ): bool {
        $user = $this->read_user( $wp_user_id );
        if ( null === $user || ! $this->is_pure_patient_user( $user ) ) {
            return false;
        }

        if ( isset( self::$rest_verified_non_staff_users[ $wp_user_id ] ) ) {
            return true;
        }

        return ! $this->has_scoped_staff_authority( $wp_user_id );
    }

    /**
     * Checks whether `$wp_user_id` holds ANY active Clinic-scoped staff authority
     * (built-in role membership, Clinic-local custom role, or explicit grant)
     * in ANY Clinic, failing closed on missing dependencies or lookup errors.
     */
    private function has_scoped_staff_authority( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) {
            return true;
        }
        if ( null !== $this->scoped_staff_checker ) {
            try {
                return (bool) ( $this->scoped_staff_checker )( $wp_user_id );
            } catch ( Throwable ) {
                return true;
            }
        }
        if ( null === $this->db || null === $this->memberships || null === $this->authorization ) {
            return true;
        }

        $was_strict = $this->db->is_strict();
        $this->db->setStrict( true );
        try {
            $org_rows = $this->db->fetch_all_strict(
                'SELECT id FROM ' . $this->db->table( 'cpms_organization_memberships' )
                . " WHERE wp_user_id = %d AND status = 'active' LIMIT 1",
                [ $wp_user_id ]
            );
            if ( [] !== $org_rows ) {
                return true;
            }

            $clinic_ids = $this->memberships->active_clinic_ids_for_user( $wp_user_id );
            foreach ( $clinic_ids as $clinic_id ) {
                if ( $clinic_id <= 0 ) {
                    return true;
                }
                foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
                    if ( $this->authorization->can( $wp_user_id, $clinic_id, $capability ) ) {
                        return true;
                    }
                }
            }

            return false;
        } catch ( Throwable ) {
            return true;
        } finally {
            $this->db->setStrict( $was_strict );
        }
    }

    /**
     * Verifies both durable identity edges required for `LEVEL_FULL` (§2.4):
     * `cpms_patients.identity_id IS NOT NULL`, an Organization-scoped row in
     * `cpms_patient_identity_links`, and a Clinic row in `cpms_patient_user_links`.
     */
    private function has_full_identity_edges( int $wp_user_id, int $clinic_id, int $patient_id ): bool {
        if ( null === $this->db || $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            return false;
        }

        $rows = $this->db->fetch_all_strict(
            'SELECT 1 FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c ON c.id = l.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patient_identity_links' ) . ' pil'
            . ' ON pil.identity_id = p.identity_id AND pil.wp_user_id = l.wp_user_id AND pil.organization_id = o.id'
            . ' WHERE l.wp_user_id = %d AND l.clinic_id = %d AND l.patient_id = %d'
            . " AND p.status = 'active' AND c.status = 'active' AND o.status = 'active'"
            . ' AND p.identity_id IS NOT NULL'
            . ' LIMIT 1',
            [ $wp_user_id, $clinic_id, $patient_id ]
        );

        return [] !== $rows;
    }

    /**
     * Pure-patient check matching PatientPortalPage::isPatientOnly() and the
     * identity design: reject multi-role users (staff + patient) and any user
     * holding `manage_options` or a CPMS staff capability.
     *
     * @param array{exists: bool, roles: list<string>, has_staff_cap: bool} $user
     */
    private function is_pure_patient_user( array $user ): bool {
        if ( ! $user['exists'] || $user['has_staff_cap'] ) {
            return false;
        }
        $roles = $user['roles'];

        return in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true ) && 1 === count( $roles );
    }

    /**
     * @return array{exists: bool, roles: list<string>, has_staff_cap: bool}|null
     */
    private function read_user( int $wp_user_id ): ?array {
        if ( $wp_user_id <= 0 ) {
            return null;
        }
        if ( null !== $this->user_reader ) {
            $resolved = ( $this->user_reader )( $wp_user_id );
            if ( ! is_array( $resolved ) ) {
                return null;
            }
            $roles         = array_values( array_filter( (array) ( $resolved['roles'] ?? [] ), 'is_string' ) );
            $has_staff_cap = (bool) ( $resolved['has_staff_cap'] ?? false );
            if ( ! $has_staff_cap && isset( $resolved['caps'] ) && is_array( $resolved['caps'] ) ) {
                if ( ! empty( $resolved['caps']['manage_options'] ) ) {
                    $has_staff_cap = true;
                } else {
                    foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
                        if ( ! empty( $resolved['caps'][ $capability ] ) ) {
                            $has_staff_cap = true;
                            break;
                        }
                    }
                }
            }

            return [
                'exists'        => (bool) ( $resolved['exists'] ?? false ),
                'roles'         => $roles,
                'has_staff_cap' => $has_staff_cap,
            ];
        }
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $wp_user_id );
            if ( ! $user instanceof WP_User || ! $user->exists() ) {
                return null;
            }

            return [
                'exists'        => true,
                'roles'         => array_values( array_filter( (array) $user->roles, 'is_string' ) ),
                'has_staff_cap' => self::wp_user_has_staff_capability( $user ),
            ];
        }
        if ( function_exists( 'wp_get_current_user' ) ) {
            $user = wp_get_current_user();
            if ( ! $user->exists() || (int) $user->ID !== $wp_user_id ) {
                return null;
            }

            return [
                'exists'        => true,
                'roles'         => array_values( array_filter( (array) $user->roles, 'is_string' ) ),
                'has_staff_cap' => self::wp_user_has_staff_capability( $user ),
            ];
        }

        return null;
    }

    private static function wp_user_has_staff_capability( WP_User $user ): bool {
        if ( ! function_exists( 'user_can' ) ) {
            return false;
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }
        foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
            if ( user_can( $user, $capability ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{clinic_id: int, patient_id: int}|null
     */
    private function resolve_link_target( int $wp_user_id, int $link_id ): ?array {
        if ( null !== $this->link_resolver ) {
            $target = ( $this->link_resolver )( $wp_user_id, $link_id );
            if ( ! is_array( $target ) ) {
                return null;
            }
            $clinic_id  = (int) ( $target['clinic_id'] ?? 0 );
            $patient_id = (int) ( $target['patient_id'] ?? 0 );
            if ( $clinic_id <= 0 || $patient_id <= 0 ) {
                return null;
            }

            return [
                'clinic_id'  => $clinic_id,
                'patient_id' => $patient_id,
            ];
        }
        if ( null === $this->patients ) {
            return null;
        }
        $row = $this->patients->find_active_linked_patient_by_link( $wp_user_id, $link_id );
        if ( null === $row ) {
            return null;
        }
        $clinic_id  = (int) ( $row['clinic_id'] ?? 0 );
        $patient_id = (int) ( $row['id'] ?? 0 );
        if ( $clinic_id <= 0 || $patient_id <= 0 ) {
            return null;
        }

        return [
            'clinic_id'  => $clinic_id,
            'patient_id' => $patient_id,
        ];
    }

    /**
     * @return array{clinic_id: int, patient_id: int}|null
     */
    private function resolve_booking_link_target( int $wp_user_id, int $link_id ): ?array {
        if ( null !== $this->booking_link_resolver ) {
            $target = ( $this->booking_link_resolver )( $wp_user_id, $link_id );
            if ( ! is_array( $target ) ) {
                return null;
            }
            $clinic_id  = (int) ( $target['clinic_id'] ?? 0 );
            $patient_id = (int) ( $target['patient_id'] ?? 0 );
            if ( $clinic_id <= 0 || $patient_id <= 0 ) {
                return null;
            }

            return [
                'clinic_id'  => $clinic_id,
                'patient_id' => $patient_id,
            ];
        }
        if ( null === $this->patients ) {
            return null;
        }
        $row = $this->patients->find_active_booking_linked_patient_by_link( $wp_user_id, $link_id );
        if ( null === $row ) {
            return null;
        }
        $clinic_id  = (int) ( $row['clinic_id'] ?? 0 );
        $patient_id = (int) ( $row['id'] ?? 0 );
        if ( $clinic_id <= 0 || $patient_id <= 0 ) {
            return null;
        }

        return [
            'clinic_id'  => $clinic_id,
            'patient_id' => $patient_id,
        ];
    }

    private function has_enrollment_eligibility( int $wp_user_id, int $clinic_id ): bool {
        if ( null !== $this->enroll_checker ) {
            return (bool) ( $this->enroll_checker )( $wp_user_id, $clinic_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_enrollment_eligibility_in_clinic( $wp_user_id, $clinic_id );
    }

    /**
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    private function allow_decision( string $role ): array {
        return [
            'allowed'     => true,
            'http_status' => 200,
            'code'        => '',
            'message'     => '',
            'reason'      => 'allowed',
            'role'        => $role,
        ];
    }

    /**
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    private function proof_failed_decision( string $reason, string $role ): array {
        return [
            'allowed'     => false,
            'http_status' => 403,
            'code'        => self::PROOF_FAILED_CODE,
            'message'     => self::PROOF_FAILED_MESSAGE,
            'reason'      => $reason,
            'role'        => $role,
        ];
    }

    /**
     * @param array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * } $decision Decision payload.
     * @return array{
     *     allowed: bool,
     *     http_status: int,
     *     code: string,
     *     message: string,
     *     reason: string,
     *     role: string
     * }
     */
    private function observe_decision(
        int $wp_user_id,
        ?int $clinic_id,
        ?int $patient_id,
        ?int $link_id,
        string $mode,
        array $decision
    ): array {
        if ( null !== $this->decision_observer ) {
            ( $this->decision_observer )(
                [
                    'wp_user_id'  => $wp_user_id,
                    'clinic_id'   => $clinic_id,
                    'patient_id'  => $patient_id,
                    'link_id'     => $link_id,
                    'mode'        => $mode,
                    'allowed'     => $decision['allowed'],
                    'http_status' => $decision['http_status'],
                    'code'        => $decision['code'],
                    'reason'      => $decision['reason'],
                ]
            );
        }

        return $decision;
    }
}

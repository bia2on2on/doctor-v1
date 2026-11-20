<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
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

    private readonly ?CpmsDb $db;
    private readonly ?PatientRepository $patients;
    private readonly ?MembershipRepository $memberships;
    private readonly ?AuthorizationService $authorization;

    /**
     * Optional user resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): ?array{exists: bool, roles: list<string>, has_staff_cap?: bool, caps?: array<string, mixed>})|null
     */
    private $user_reader;

    /**
     * Optional clinic-binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): bool)|null
     */
    private $clinic_binding_checker;

    /**
     * Optional patient-binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int, int): bool)|null
     */
    private $patient_binding_checker;

    /**
     * Optional any-binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): bool)|null
     */
    private $any_binding_checker;

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
     * Optional any-clinic patient-binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): bool)|null
     */
    private $any_patient_binding_checker;

    /**
     * Optional booking-clinic binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): bool)|null
     */
    private $booking_clinic_binding_checker;

    /**
     * Optional any-booking binding resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): bool)|null
     */
    private $any_booking_binding_checker;

    /**
     * Optional booking link-target resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int, int): ?array{clinic_id: int, patient_id: int})|null
     */
    private $booking_link_resolver;

    /**
     * Construct the central patient access gate.
     *
     * @param CpmsDb|null                                                                                                         $db                             Database adapter.
     * @param PatientRepository|null                                                                                              $patients                       Patient repository.
     * @param (callable(int): ?array{exists: bool, roles: list<string>, has_staff_cap?: bool, caps?: array<string, mixed>})|null $user_reader                    Optional user reader seam.
     * @param (callable(int, int): bool)|null                                                                                     $clinic_binding_checker         Optional clinic binding seam.
     * @param (callable(int, int, int): bool)|null                                                                                $patient_binding_checker        Optional patient binding seam.
     * @param (callable(int): bool)|null                                                                                          $any_binding_checker            Optional any-binding seam.
     * @param (callable(int, int): ?array{clinic_id: int, patient_id: int})|null                                                  $link_resolver                  Optional link resolver seam.
     * @param (callable(int, int): bool)|null                                                                                     $enroll_checker                 Optional enroll eligibility seam.
     * @param (callable(array<string, mixed>): void)|null                                                                         $decision_observer              Optional decision observer seam.
     * @param (callable(int): bool)|null                                                                                          $scoped_staff_checker           Optional scoped staff authority seam.
     * @param MembershipRepository|null                                                                                           $memberships                    Membership repository.
     * @param AuthorizationService|null                                                                                           $authorization                  Clinic-scoped authorization service.
     * @param (callable(int, int): bool)|null                                                                                     $any_patient_binding_checker    Optional any-clinic patient seam.
     * @param (callable(int, int): bool)|null                                                                                     $booking_clinic_binding_checker Optional booking clinic seam.
     * @param (callable(int): bool)|null                                                                                          $any_booking_binding_checker    Optional any-booking seam.
     * @param (callable(int, int): ?array{clinic_id: int, patient_id: int})|null                                                  $booking_link_resolver          Optional booking link resolver seam.
     */
    public function __construct(
        ?CpmsDb $db = null,
        ?PatientRepository $patients = null,
        ?callable $user_reader = null,
        ?callable $clinic_binding_checker = null,
        ?callable $patient_binding_checker = null,
        ?callable $any_binding_checker = null,
        ?callable $link_resolver = null,
        ?callable $enroll_checker = null,
        ?callable $decision_observer = null,
        ?callable $scoped_staff_checker = null,
        ?MembershipRepository $memberships = null,
        ?AuthorizationService $authorization = null,
        ?callable $any_patient_binding_checker = null,
        ?callable $booking_clinic_binding_checker = null,
        ?callable $any_booking_binding_checker = null,
        ?callable $booking_link_resolver = null
    ) {
        $this->db                             = $db;
        $this->patients                       = $patients ?? ( null !== $db ? new PatientRepository( $db ) : null );
        $this->memberships                    = $memberships ?? ( null !== $db ? new MembershipRepository( $db ) : null );
        $this->authorization                  = $authorization ?? ( null !== $db && null !== $this->memberships ? new AuthorizationService( $this->memberships, new CustomRoleRepository( $db ) ) : null );
        $this->user_reader                    = $user_reader;
        $this->clinic_binding_checker         = $clinic_binding_checker;
        $this->patient_binding_checker        = $patient_binding_checker;
        $this->any_binding_checker            = $any_binding_checker;
        $this->link_resolver                  = $link_resolver;
        $this->enroll_checker                 = $enroll_checker;
        $this->decision_observer              = $decision_observer;
        $this->scoped_staff_checker           = $scoped_staff_checker;
        $this->any_patient_binding_checker    = $any_patient_binding_checker;
        $this->booking_clinic_binding_checker = $booking_clinic_binding_checker;
        $this->any_booking_binding_checker    = $any_booking_binding_checker;
        $this->booking_link_resolver          = $booking_link_resolver;
    }

    /**
     * May this authenticated patient-role user act inside `$clinic_id`?
     */
    public function can_access_clinic( int $wp_user_id, int $clinic_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }
        if ( ! $this->is_authenticated_patient( $wp_user_id ) ) {
            return false;
        }

        return $this->has_clinic_binding( $wp_user_id, $clinic_id );
    }

    /**
     * May this authenticated patient-role user act on `$patient_id` in `$clinic_id`?
     */
    public function can_access_patient( int $wp_user_id, int $clinic_id, int $patient_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 || $patient_id <= 0 ) {
            return false;
        }
        if ( ! $this->is_authenticated_patient( $wp_user_id ) ) {
            return false;
        }

        return $this->has_patient_binding( $wp_user_id, $clinic_id, $patient_id );
    }

    /**
     * May this authenticated patient-role user self-enroll inside `$clinic_id`?
     */
    public function can_enroll_in_clinic( int $wp_user_id, int $clinic_id ): bool {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }
        if ( ! $this->is_authenticated_patient( $wp_user_id ) ) {
            return false;
        }

        return $this->has_enrollment_eligibility( $wp_user_id, $clinic_id );
    }

    /**
     * Evaluates a patient REST request against authentication, patient-role
     * separation, clinic binding, and optional patient/link selectors.
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
        $user = $this->read_user( $wp_user_id );
        if ( null === $user || ! $user['exists'] ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                [
                    'allowed'     => false,
                    'http_status' => 401,
                    'code'        => self::UNAUTHORIZED_CODE,
                    'message'     => self::UNAUTHORIZED_MESSAGE,
                    'reason'      => 'unauthenticated',
                    'role'        => 'anonymous',
                ]
            );
        }

        $roles        = $user['roles'];
        $primary_role = isset( $roles[0] ) && is_string( $roles[0] ) ? $roles[0] : 'unknown';
        if ( ! $this->is_pure_patient_user( $user ) || $this->has_scoped_staff_authority( $wp_user_id ) ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                [
                    'allowed'     => false,
                    'http_status' => 403,
                    'code'        => self::FORBIDDEN_CODE,
                    'message'     => self::FORBIDDEN_MESSAGE,
                    'reason'      => 'patient_role_required',
                    'role'        => $primary_role,
                ]
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
                $this->not_found_decision( 'invalid_selector', $primary_role )
            );
        }

        if ( self::MODE_ENROLL === $mode ) {
            if ( null === $clinic_id || ! $this->has_enrollment_eligibility( $wp_user_id, $clinic_id ) ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'enroll_ineligible', $primary_role )
                );
            }

            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->allow_decision( $primary_role )
            );
        }

        if ( self::MODE_RECORDS_LIST === $mode ) {
            if ( null !== $link_id ) {
                $target = $this->resolve_link_target( $wp_user_id, $link_id );
                if ( null === $target || ( null !== $clinic_id && $target['clinic_id'] !== $clinic_id ) ) {
                    return $this->observe_decision(
                        $wp_user_id,
                        $clinic_id,
                        $patient_id,
                        $link_id,
                        $mode,
                        $this->not_found_decision( 'link_not_bound', $primary_role )
                    );
                }
            }
            if ( null !== $clinic_id && ! $this->has_clinic_binding( $wp_user_id, $clinic_id ) ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'clinic_not_bound', $primary_role )
                );
            }

            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->allow_decision( $primary_role )
            );
        }

        if ( self::MODE_BOOKING_APPOINTMENT === $mode ) {
            if ( null !== $link_id ) {
                $target = $this->resolve_booking_link_target( $wp_user_id, $link_id );
                if (
                    null === $target
                    || ( null !== $clinic_id && $target['clinic_id'] !== $clinic_id )
                    || ( null !== $patient_id && $target['patient_id'] !== $patient_id )
                    || ! $this->has_booking_clinic_binding( $wp_user_id, $target['clinic_id'] )
                ) {
                    return $this->observe_decision(
                        $wp_user_id,
                        $clinic_id,
                        $patient_id,
                        $link_id,
                        $mode,
                        $this->forbidden_decision( 'booking_link_required', $primary_role )
                    );
                }

                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->allow_decision( $primary_role )
                );
            }

            if ( null !== $clinic_id ) {
                $decision = $this->has_booking_clinic_binding( $wp_user_id, $clinic_id )
                    ? $this->allow_decision( $primary_role )
                    : $this->forbidden_decision( 'booking_link_required', $primary_role );

                return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
            }

            $decision = $this->has_any_booking_binding( $wp_user_id )
                ? $this->allow_decision( $primary_role )
                : $this->forbidden_decision( 'booking_link_required', $primary_role );

            return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
        }

        if ( self::MODE_PATIENT_RESOURCE === $mode ) {
            if ( null === $patient_id || $patient_id <= 0 ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'patient_required', $primary_role )
                );
            }

            if ( null !== $link_id ) {
                $target = $this->resolve_link_target( $wp_user_id, $link_id );
                if (
                    null === $target
                    || ( null !== $clinic_id && $target['clinic_id'] !== $clinic_id )
                    || $target['patient_id'] !== $patient_id
                    || ! $this->has_patient_binding( $wp_user_id, $target['clinic_id'], $patient_id )
                ) {
                    return $this->observe_decision(
                        $wp_user_id,
                        $clinic_id,
                        $patient_id,
                        $link_id,
                        $mode,
                        $this->not_found_decision( 'patient_not_bound', $primary_role )
                    );
                }

                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->allow_decision( $primary_role )
                );
            }

            $allowed_resource = null !== $clinic_id
                ? $this->has_patient_binding( $wp_user_id, $clinic_id, $patient_id )
                : $this->has_any_patient_binding( $wp_user_id, $patient_id );

            $decision = $allowed_resource
                ? $this->allow_decision( $primary_role )
                : $this->not_found_decision( 'patient_not_bound', $primary_role );

            return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
        }

        if ( self::MODE_CLINICAL !== $mode ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->not_found_decision( 'unsupported_mode', $primary_role )
            );
        }

        if ( null !== $link_id ) {
            $target = $this->resolve_link_target( $wp_user_id, $link_id );
            if ( null === $target ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'link_not_bound', $primary_role )
                );
            }
            if ( null !== $clinic_id && $target['clinic_id'] !== $clinic_id ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'link_clinic_mismatch', $primary_role )
                );
            }
            if ( null !== $patient_id && $target['patient_id'] !== $patient_id ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'link_patient_mismatch', $primary_role )
                );
            }
            if ( ! $this->has_patient_binding( $wp_user_id, $target['clinic_id'], $target['patient_id'] ) ) {
                return $this->observe_decision(
                    $wp_user_id,
                    $clinic_id,
                    $patient_id,
                    $link_id,
                    $mode,
                    $this->not_found_decision( 'patient_not_bound', $primary_role )
                );
            }

            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->allow_decision( $primary_role )
            );
        }

        if ( null !== $clinic_id && null !== $patient_id ) {
            $decision = $this->has_patient_binding( $wp_user_id, $clinic_id, $patient_id )
                ? $this->allow_decision( $primary_role )
                : $this->not_found_decision( 'patient_not_bound', $primary_role );

            return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
        }

        if ( null !== $clinic_id ) {
            $decision = $this->has_clinic_binding( $wp_user_id, $clinic_id )
                ? $this->allow_decision( $primary_role )
                : $this->not_found_decision( 'clinic_not_bound', $primary_role );

            return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
        }

        if ( null !== $patient_id ) {
            return $this->observe_decision(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $link_id,
                $mode,
                $this->not_found_decision( 'patient_without_clinic', $primary_role )
            );
        }

        $decision = $this->has_any_clinical_binding( $wp_user_id )
            ? $this->allow_decision( $primary_role )
            : $this->not_found_decision( 'no_clinical_binding', $primary_role );

        return $this->observe_decision( $wp_user_id, $clinic_id, $patient_id, $link_id, $mode, $decision );
    }

    private function is_authenticated_patient( int $wp_user_id ): bool {
        $user = $this->read_user( $wp_user_id );
        if ( null === $user ) {
            return false;
        }

        return $this->is_pure_patient_user( $user ) && ! $this->has_scoped_staff_authority( $wp_user_id );
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

    private function has_clinic_binding( int $wp_user_id, int $clinic_id ): bool {
        if ( null !== $this->clinic_binding_checker ) {
            return (bool) ( $this->clinic_binding_checker )( $wp_user_id, $clinic_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_clinical_access_to_clinic( $wp_user_id, $clinic_id );
    }

    private function has_patient_binding( int $wp_user_id, int $clinic_id, int $patient_id ): bool {
        if ( null !== $this->patient_binding_checker ) {
            return (bool) ( $this->patient_binding_checker )( $wp_user_id, $clinic_id, $patient_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_clinical_access_to_patient( $wp_user_id, $patient_id, $clinic_id );
    }

    private function has_any_clinical_binding( int $wp_user_id ): bool {
        if ( null !== $this->any_binding_checker ) {
            return (bool) ( $this->any_binding_checker )( $wp_user_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_any_clinical_access( $wp_user_id );
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

    private function has_any_patient_binding( int $wp_user_id, int $patient_id ): bool {
        if ( null !== $this->any_patient_binding_checker ) {
            return (bool) ( $this->any_patient_binding_checker )( $wp_user_id, $patient_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_clinical_access_to_patient_any_clinic( $wp_user_id, $patient_id );
    }

    private function has_booking_clinic_binding( int $wp_user_id, int $clinic_id ): bool {
        if ( null !== $this->booking_clinic_binding_checker ) {
            return (bool) ( $this->booking_clinic_binding_checker )( $wp_user_id, $clinic_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_booking_access_to_clinic( $wp_user_id, $clinic_id );
    }

    private function has_any_booking_binding( int $wp_user_id ): bool {
        if ( null !== $this->any_booking_binding_checker ) {
            return (bool) ( $this->any_booking_binding_checker )( $wp_user_id );
        }
        if ( null === $this->patients ) {
            return false;
        }

        return $this->patients->user_has_any_booking_access( $wp_user_id );
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
    private function forbidden_decision( string $reason, string $role ): array {
        return [
            'allowed'     => false,
            'http_status' => 403,
            'code'        => self::FORBIDDEN_CODE,
            'message'     => self::FORBIDDEN_MESSAGE,
            'reason'      => $reason,
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
    private function not_found_decision( string $reason, string $role ): array {
        return [
            'allowed'     => false,
            'http_status' => 404,
            'code'        => self::NOT_FOUND_CODE,
            'message'     => self::NOT_FOUND_MESSAGE,
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

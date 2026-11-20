<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientRepository;

/**
 * Central, fail-closed authorization gate for patient-role access to Clinic
 * and Patient records.
 *
 * Architectural limitation (Slice A): `cpms_patient_logins` from unmerged PR #10
 * does not exist on `main` yet. This gate therefore evaluates existing durable
 * patient-user bindings (`cpms_patient_user_links`, active `cpms_patients`,
 * `cpms_clinics`, and `cpms_patient_identity_links` / `cpms_organizations`)
 * through injectable resolver seams so `cpms_patient_logins` can be integrated
 * here without changing controller callers.
 */
final class PatientAccess {

    public const MODE_CLINICAL     = 'clinical';
    public const MODE_RECORDS_LIST = 'records_list';
    public const MODE_ENROLL       = 'enroll';

    public const UNAUTHORIZED_CODE    = 'CLINIC_UNAUTHORIZED';
    public const UNAUTHORIZED_MESSAGE = 'وارد نشده‌اید';

    public const FORBIDDEN_CODE    = 'CLINIC_PERMISSION_DENIED';
    public const FORBIDDEN_MESSAGE = 'دسترسی ندارید';

    public const NOT_FOUND_CODE    = 'CLINIC_NOT_FOUND';
    public const NOT_FOUND_MESSAGE = 'بیماری به این حساب متصل نیست';

    private readonly ?PatientRepository $patients;

    /**
     * Optional user resolver seam for pure-PHP unit tests.
     *
     * @var (callable(int): ?array{exists: bool, roles: list<string>})|null
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
     * Construct the central patient access gate.
     *
     * @param CpmsDb|null                                                       $db                      Database adapter.
     * @param PatientRepository|null                                            $patients                Patient repository.
     * @param (callable(int): ?array{exists: bool, roles: list<string>})|null  $user_reader             Optional user reader seam.
     * @param (callable(int, int): bool)|null                                   $clinic_binding_checker  Optional clinic binding seam.
     * @param (callable(int, int, int): bool)|null                              $patient_binding_checker Optional patient binding seam.
     * @param (callable(int): bool)|null                                        $any_binding_checker     Optional any-binding seam.
     * @param (callable(int, int): ?array{clinic_id: int, patient_id: int})|null $link_resolver          Optional link resolver seam.
     * @param (callable(int, int): bool)|null                                   $enroll_checker          Optional enroll eligibility seam.
     * @param (callable(array<string, mixed>): void)|null                       $decision_observer       Optional decision observer seam.
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
        ?callable $decision_observer = null
    ) {
        $this->patients                = $patients ?? ( null !== $db ? new PatientRepository( $db ) : null );
        $this->user_reader             = $user_reader;
        $this->clinic_binding_checker  = $clinic_binding_checker;
        $this->patient_binding_checker = $patient_binding_checker;
        $this->any_binding_checker     = $any_binding_checker;
        $this->link_resolver           = $link_resolver;
        $this->enroll_checker          = $enroll_checker;
        $this->decision_observer       = $decision_observer;
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
        if ( ! in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true ) ) {
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
        if ( null === $user || ! $user['exists'] ) {
            return false;
        }

        return in_array( RolesAndCapabilities::ROLE_PATIENT, $user['roles'], true );
    }

    /**
     * @return array{exists: bool, roles: list<string>}|null
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
            $roles = array_values( array_filter( (array) ( $resolved['roles'] ?? [] ), 'is_string' ) );

            return [
                'exists' => (bool) ( $resolved['exists'] ?? false ),
                'roles'  => $roles,
            ];
        }
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $wp_user_id );
            if ( false === $user || ! $user->exists() ) {
                return null;
            }

            return [
                'exists' => true,
                'roles'  => array_values( array_filter( (array) $user->roles, 'is_string' ) ),
            ];
        }
        if ( function_exists( 'wp_get_current_user' ) ) {
            $user = wp_get_current_user();
            if ( ! $user->exists() || (int) $user->ID !== $wp_user_id ) {
                return null;
            }

            return [
                'exists' => true,
                'roles'  => array_values( array_filter( (array) $user->roles, 'is_string' ) ),
            ];
        }

        return null;
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

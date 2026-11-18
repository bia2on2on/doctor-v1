<?php

declare(strict_types=1);

namespace ClinicCore\Application\Patients;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_User;

/**
 * B0-PR2 — patient credential boundary for plugin staff paths.
 *
 * Policy (owner-approved): no staff-facing path — Classic staff screen or Clinic
 * management portal, for any actor including Administrator — may create, enroll,
 * set, reset or change the password/credential of a PATIENT account, or hand out
 * patient binding/access. Staff account passwords created by an administrator
 * keep working exactly as before; only patient targets are refused here.
 *
 * Patient identity is never inferred from ONE WordPress role alone. A target is
 * a patient account when ANY of the following positive, durable facts holds:
 *   1. ANY current WordPress role on the account is `cpms_patient` (all roles are
 *      inspected, not only the first one);
 *   2. a Clinic membership row (any status) carries the `cpms_patient` role key;
 *   3. a durable `cpms_patient_user_links` row binds the account to a patient;
 *   4. the parked-role meta `cpms_previous_role` records `cpms_patient` — a
 *      suspension parks the role there and downgrades the live role, so without
 *      this the parked account would lose its only durable patient evidence.
 * Mixed accounts (a staff role plus any of the above) therefore fail closed: a
 * person who is both patient and staff must use two separate accounts.
 *
 * Every evidence read is STRICT: it distinguishes "no row" from "the read
 * failed". `CpmsDb` is soft by default (a failed query returns null/0/false
 * without throwing), so these reads use the error-checked reader and treat any
 * failure — and any unresolvable target — as PATIENT EVIDENCE (deny). The denial
 * string is stable and reveals nothing about which fact matched. Callers must
 * evaluate this BEFORE any side effect: user row, password write, membership,
 * e-mail or audit row.
 */
final class PatientAccountPolicy {
    /**
     * Mirrors StaffManagementPage::META_PREV_ROLE (private there): the meta key a
     * staff suspension writes for the parked WordPress role.
     */
    private const META_PREV_ROLE = 'cpms_previous_role';

    /**
     * Stable, non-revealing denial. Deliberately does not say WHY the target is
     * a patient (role vs membership vs link) and does not echo any identifier.
     */
    public const DENIAL_MESSAGE = 'این عملیات برای حساب بیمار مجاز نیست؛ '
        . 'حساب بیمار فقط از مسیر خود بیمار مدیریت می‌شود.';

    /**
     * Whether a role key is the patient role. Used for the created/assigned role
     * (there is no target account to inspect when a new account would be made).
     */
    public static function is_patient_role_key( string $role_key ): bool {
        return $role_key === RolesAndCapabilities::ROLE_PATIENT;
    }

    /**
     * Whether a target account is a patient account (fail-closed on any doubt).
     */
    public static function is_patient_account( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) {
            return true; // no resolvable target: never treat it as staff
        }

        $user = get_userdata( $wp_user_id );
        if ( ! $user instanceof WP_User ) {
            return true;
        }

        foreach ( (array) $user->roles as $role ) {
            if ( (string) $role === RolesAndCapabilities::ROLE_PATIENT ) {
                return true;
            }
        }

        try {
            return self::has_patient_membership( $wp_user_id )
                || self::has_patient_link( $wp_user_id )
                || self::has_parked_patient_role( $wp_user_id );
        } catch ( \Throwable $e ) {
            return true; // a failed evidence read is never a staff grant
        }
    }

    /**
     * One guard for every staff write path.
     *
     * @param int    $target_wp_user_id Existing target (0 when an account would be created).
     * @param string $role_key          Role that would be created or assigned ('' when none).
     *
     * @return string '' when allowed, otherwise the stable denial message.
     */
    public static function guard( int $target_wp_user_id, string $role_key = '' ): string {
        if ( self::is_patient_role_key( $role_key ) ) {
            return self::DENIAL_MESSAGE;
        }

        if ( $target_wp_user_id > 0 && self::is_patient_account( $target_wp_user_id ) ) {
            return self::DENIAL_MESSAGE;
        }

        return '';
    }

    /**
     * Any membership row (active OR suspended) carrying the patient role key.
     * Strict read: a failed query throws (and the caller denies), so a database
     * error can never look like "no membership".
     */
    private static function has_patient_membership( int $wp_user_id ): bool {
        $db = App::db();

        $rows = $db->fetch_all_strict(
            'SELECT id FROM ' . $db->table( 'cpms_clinic_memberships' )
            . ' WHERE wp_user_id = %d AND role_key = %s LIMIT 1',
            array( $wp_user_id, RolesAndCapabilities::ROLE_PATIENT )
        );

        return $rows !== array();
    }

    /**
     * A durable patient↔account link (any status) is patient evidence.
     * Strict read (see has_patient_membership()).
     */
    private static function has_patient_link( int $wp_user_id ): bool {
        $db = App::db();

        $rows = $db->fetch_all_strict(
            'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' )
            . ' WHERE wp_user_id = %d LIMIT 1',
            array( $wp_user_id )
        );

        return $rows !== array();
    }

    /**
     * The parked role written by a staff suspension, when it records the patient
     * role. Strict read against the WordPress usermeta table, so a failed read
     * denies instead of silently erasing the account's only patient evidence.
     */
    private static function has_parked_patient_role( int $wp_user_id ): bool {
        $db = App::db();

        $rows = $db->fetch_all_strict(
            'SELECT umeta_id FROM ' . $db->dbPrefix() . 'usermeta'
            . ' WHERE user_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1',
            array( $wp_user_id, self::META_PREV_ROLE, RolesAndCapabilities::ROLE_PATIENT )
        );

        return $rows !== array();
    }
}

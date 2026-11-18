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
 *   1. the WordPress role `cpms_patient` is on the account;
 *   2. a Clinic membership row (any status) carries the `cpms_patient` role key;
 *   3. a durable `cpms_patient_user_links` row binds the account to a patient.
 * Mixed accounts (a staff role plus any of the above) therefore fail closed: a
 * person who is both patient and staff must use two separate accounts.
 *
 * Every read failure fails closed (the target is treated as a patient), and the
 * denial string is stable and reveals nothing about which fact matched. Callers
 * must evaluate this BEFORE any side effect: user row, password write,
 * membership, e-mail or audit row.
 */
final class PatientAccountPolicy
{
    /**
     * Stable, non-revealing denial. Deliberately does not say WHY the target is
     * a patient (role vs membership vs link) and does not echo any identifier.
     */
    public const DENIAL_MESSAGE = 'این عملیات برای حساب بیمار مجاز نیست؛ حساب بیمار فقط از مسیر خود بیمار مدیریت می‌شود.';

    /**
     * Whether a role key is the patient role. Used for the created/assigned role
     * (there is no target account to inspect when a new account would be made).
     */
    public static function is_patient_role_key( string $role_key ): bool
    {
        return $role_key === RolesAndCapabilities::ROLE_PATIENT;
    }

    /**
     * Whether a target account is a patient account (fail-closed on any doubt).
     */
    public static function is_patient_account( int $wp_user_id ): bool
    {
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
            return self::has_patient_membership( $wp_user_id ) || self::has_patient_link( $wp_user_id );
        } catch ( \Throwable $e ) {
            return true; // unreadable evidence is never a staff grant
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
    public static function guard( int $target_wp_user_id, string $role_key = '' ): string
    {
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
     */
    private static function has_patient_membership( int $wp_user_id ): bool
    {
        $db = App::db();

        $count = $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinic_memberships' )
            . ' WHERE wp_user_id = %d AND role_key = %s',
            array( $wp_user_id, RolesAndCapabilities::ROLE_PATIENT )
        );

        return (int) $count > 0;
    }

    /**
     * A durable patient↔account link (any status) is patient evidence.
     */
    private static function has_patient_link( int $wp_user_id ): bool
    {
        $db = App::db();

        $count = $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table( 'cpms_patient_user_links' ) . ' WHERE wp_user_id = %d',
            array( $wp_user_id )
        );

        return (int) $count > 0;
    }
}

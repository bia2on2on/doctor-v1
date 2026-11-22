<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Application\Patients\PatientIdentityActivator;
use ClinicCore\Application\Patients\PatientIdentityService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Domain\Validators\VisibleText;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Infrastructure\UserCacheRollback;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Public self-registration service for patients (§4, Slice 2).
 *
 * All registration requests flow through the single public entry method
 * `register()`. Consumes a `verify_mobile` OTP for the body's mobile and
 * executes one depth-0 atomic transaction across:
 *   1) `cpms_patients` (insert new record or link matching-NID active record);
 *   2) WordPress `wp_users` (pure-patient role, opaque credentials);
 *   3) `cpms_patient_logins` (`kind='account', state='active'` + `kind='binding', state='active'`);
 *   4) `cpms_patients.identity_id` + `cpms_patient_identity_links` + `cpms_patient_user_links`;
 *   5) Atomic audit log (`PATIENT_REGISTERED` / `PATIENT_REGISTRATION_LINKED`, never raw/masked NID).
 *
 * Every rejection returns the uniform §6.10 failure envelope (`403 CLINIC_PATIENT_PROOF_FAILED`).
 */
final class PatientRegistrationService {

    public const RATE_WINDOW_SEC = 900;
    public const RATE_IP_MAX     = 20;
    public const RATE_MOBILE_MAX = 8;
    public const RATE_NID_MAX    = 8;

    private const MAX_NAME_LEN = 120;

    /** @var (callable(int, string, int): void)|null */
    private $sms_notifier;

    /**
     * @param (callable(int, string, int): void)|null $sms_notifier Optional post-commit SMS notice callback.
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly PatientLoginRepository $logins,
        private readonly PatientSessionProofStore $proofs,
        private readonly PatientSessionScope $session_scope,
        private readonly OtpService $otp,
        private readonly RateLimiter $rate,
        private readonly PatientNidHmac $hmac,
        private readonly PatientIdentityRepository $identity_repo,
        private readonly PatientIdentityService $identity_service,
        private readonly PatientIdentityActivator $identity_activator,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        ?callable $sms_notifier = null
    ) {
        $this->sms_notifier = $sms_notifier;
    }

    /**
     * Registers a new patient account (creating a new clinic record or linking
     * an existing matching-NID active clinic record) after OTP verification.
     *
     * Single public registration seam (future license-expiry enforcement hooks here).
     *
     * @return array{
     *     registered: bool,
     *     user_id: int,
     *     patient_id: int,
     *     clinic_id: int,
     *     binding_id: int,
     *     session_issued: bool
     * }
     *
     * @throws BookingException Always `403 CLINIC_PATIENT_PROOF_FAILED` on any rejection.
     */
    public function register(
        int $clinic_id,
        string $raw_mobile,
        string $otp_code,
        string $raw_given_name,
        string $raw_family_name,
        string $raw_national_id,
        string $raw_birth_date,
        string $ip = '0.0.0.0',
        ?int $now = null,
        bool $issue_session = true
    ): array {
        $now_ts = $now ?? time();

        // Depth-0 guard (§6.5): never run registration inside a caller transaction.
        if ( $this->db->tx_depth() !== 0 ) {
            $this->log_failure( 'nested_transaction', $clinic_id );
            throw $this->uniform_failure();
        }

        $mobile         = MobileValidator::normalize( $raw_mobile );
        $normalized_nid = PatientNidHmac::normalize( $raw_national_id );

        // Enforce anti-enumeration rate limits BEFORE field rejections (§9, §9.1).
        $this->enforce_pre_limits( $mobile, $normalized_nid, $ip );

        // Normalize and validate all required inputs; every failure yields the uniform envelope.
        if ( $clinic_id <= 0 || null === $mobile ) {
            $this->log_failure( 'invalid_clinic_or_mobile', $clinic_id );
            throw $this->uniform_failure();
        }

        $clean_otp = self::fold_ascii_digits( trim( $otp_code ) );
        if ( 1 !== preg_match( '/^\d{6}$/', $clean_otp ) ) {
            $this->log_failure( 'invalid_otp_format', $clinic_id );
            throw $this->uniform_failure();
        }

        if ( null === $normalized_nid || ! NationalIdValidator::isValid( $normalized_nid ) ) {
            $this->log_failure( 'invalid_national_id', $clinic_id );
            throw $this->uniform_failure();
        }

        $given_name  = $this->clean_name( $raw_given_name );
        $family_name = $this->clean_name( $raw_family_name );
        if ( null === $given_name || null === $family_name ) {
            $this->log_failure( 'invalid_name', $clinic_id );
            throw $this->uniform_failure();
        }

        $birth_date = $this->clean_birth_date( $raw_birth_date, $now_ts );
        if ( null === $birth_date ) {
            $this->log_failure( 'invalid_birth_date', $clinic_id );
            throw $this->uniform_failure();
        }

        // Verify clinic is trusted (public opt-in directory or sole active clinic) before consuming OTP.
        if ( ! $this->is_trusted_clinic_unlocked( $clinic_id ) ) {
            $this->log_failure( 'untrusted_clinic', $clinic_id );
            throw $this->uniform_failure();
        }

        // Step 1: Consume a `verify_mobile` OTP for the body's mobile (bound to clinic if stamped).
        try {
            $this->otp->verify( $mobile, $clean_otp, OtpService::PURPOSE_VERIFY_MOBILE, $ip, $clinic_id );
        } catch ( Throwable ) {
            $this->log_failure( 'otp_verify_failed', $clinic_id );
            throw $this->uniform_failure();
        }

        // Step 2: One depth-0 atomic transaction across account, clinic record, WP user, identity, link, binding, audit.
        $created_user_descriptor = null;

        try {
            $outcome = $this->db->transactional(
                function () use (
                    $clinic_id,
                    $mobile,
                    $given_name,
                    $family_name,
                    $normalized_nid,
                    $birth_date,
                    &$created_user_descriptor
                ): array {
                    $was_strict = $this->db->is_strict();
                    $this->db->setStrict( true );
                    try {
                        // Lock order: Account (mobile) -> Organization -> Clinic -> Patient.
                        $existing_account = $this->logins->find_account_by_mobile( $mobile, true );
                        if ( null !== $existing_account ) {
                            throw new RuntimeException( 'account_already_exists' );
                        }

                        $candidate_org_id = (int) $this->db->fetchValue(
                            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                            [ $clinic_id ]
                        );
                        if ( $candidate_org_id <= 0 ) {
                            throw new RuntimeException( 'clinic_not_found' );
                        }

                        $organization = $this->db->fetchRowForUpdate(
                            'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                            [ $candidate_org_id ]
                        );
                        if ( null === $organization || 'active' !== (string) ( $organization['status'] ?? '' ) ) {
                            throw new RuntimeException( 'organization_inactive' );
                        }

                        $clinic = $this->db->fetchRowForUpdate(
                            'SELECT id, organization_id, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d AND status = %s LIMIT 1',
                            [ $clinic_id, 'active' ]
                        );
                        if ( null === $clinic || (int) ( $clinic['organization_id'] ?? 0 ) !== (int) $organization['id'] ) {
                            throw new RuntimeException( 'clinic_inactive' );
                        }

                        if ( ! $this->is_trusted_clinic_unlocked( $clinic_id ) ) {
                            throw new RuntimeException( 'clinic_not_trusted' );
                        }

                        $organization_id = (int) $organization['id'];

                        // Lookup candidate record by (clinic_id, OTP-verified mobile) ONLY — never NID in SQL WHERE.
                        // Intentionally omits `status='active'` so archived records keep their mobile reserved (D-A).
                        $patient_rows = $this->db->fetch_all_strict(
                            'SELECT id, clinic_id, mobile, national_id, first_name, last_name, birth_date, status, identity_id'
                            . ' FROM ' . $this->db->table( 'cpms_patients' )
                            . ' WHERE clinic_id = %d AND mobile = %s'
                            . ' LIMIT 1 FOR UPDATE',
                            [ $clinic_id, $mobile ]
                        );

                        $now_sql     = $this->db->nowUtcSql();
                        $is_new_row  = false;
                        $patient_id  = 0;

                        if ( [] === $patient_rows ) {
                            // Case A: Create new clinical record in this clinic.
                            $is_new_row = true;
                            $mrn        = $this->generate_mrn( $clinic_id );
                            $wpdb       = $this->db->wpdb();
                            $prev_supp  = $wpdb->suppress_errors( true );
                            try {
                                $inserted = $this->db->insert(
                                    'cpms_patients',
                                    [
                                        'clinic_id'   => $clinic_id,
                                        'mrn'         => $mrn,
                                        'first_name'  => $given_name,
                                        'last_name'   => $family_name,
                                        'mobile'      => $mobile,
                                        'national_id' => $normalized_nid,
                                        'birth_date'  => $birth_date,
                                        'status'      => 'active',
                                        'created_at'  => $now_sql,
                                        'updated_at'  => $now_sql,
                                    ]
                                );
                            } finally {
                                $wpdb->suppress_errors( $prev_supp );
                            }
                            if ( ! $inserted ) {
                                throw new RuntimeException( 'patient_insert_failed' );
                            }
                            $patient_id = $this->db->wpdb_last_insert_id();
                            if ( $patient_id <= 0 ) {
                                throw new RuntimeException( 'patient_insert_invalid_id' );
                            }
                        } else {
                            // Case B / C: Existing clinic record on this verified mobile.
                            $existing_patient = $patient_rows[0];
                            $patient_id       = (int) ( $existing_patient['id'] ?? 0 );
                            $record_status    = (string) ( $existing_patient['status'] ?? '' );
                            $record_mobile    = MobileValidator::normalize( (string) ( $existing_patient['mobile'] ?? '' ) );
                            $stored_nid_raw   = $existing_patient['national_id'] ?? null;
                            $stored_nid       = is_string( $stored_nid_raw ) ? PatientNidHmac::normalize( $stored_nid_raw ) : null;

                            if (
                                $patient_id <= 0
                                || 'active' !== $record_status
                                || null === $record_mobile
                                || $record_mobile !== $mobile
                                || null === $stored_nid
                                || ! hash_equals( $stored_nid, $normalized_nid )
                            ) {
                                // Refuses archived records, NID-less records (OQ-D9), and NID mismatches.
                                throw new RuntimeException( 'existing_record_rejected' );
                            }

                            $existing_birth_date = $existing_patient['birth_date'] ?? null;
                            if ( null === $existing_birth_date || '' === trim( (string) $existing_birth_date ) ) {
                                $this->db->update(
                                    'cpms_patients',
                                    [
                                        'birth_date' => $birth_date,
                                        'updated_at' => $now_sql,
                                    ],
                                    [ 'id' => $patient_id ],
                                    true
                                );
                            }
                        }

                        // Create pure-patient WP user with opaque login, email, and random unused secret.
                        $user_login = 'cpms_p_' . bin2hex( random_bytes( 8 ) );
                        $user_email = $user_login . '@patient.cpms.invalid';
                        $wp_user_id = wp_insert_user(
                            [
                                'user_login'   => $user_login,
                                'user_pass'    => bin2hex( random_bytes( 32 ) ),
                                'user_email'   => $user_email,
                                'first_name'   => $given_name,
                                'last_name'    => $family_name,
                                'display_name' => trim( $given_name . ' ' . $family_name ),
                                'role'         => RolesAndCapabilities::ROLE_PATIENT,
                            ]
                        );
                        if ( is_wp_error( $wp_user_id ) || $wp_user_id <= 0 ) {
                            throw new RuntimeException( 'wp_user_insert_failed' );
                        }

                        $created_user_descriptor = [
                            'id'    => $wp_user_id,
                            'login' => $user_login,
                            'email' => $user_email,
                        ];

                        // Artefact 1: `cpms_patient_logins` account row (`kind='account', state='active'`).
                        $account_id = $this->logins->insert_pending_account( $wp_user_id, $mobile, $given_name, $family_name );
                        $this->logins->activate_account_from_pending( $account_id, $wp_user_id );

                        // Artefact 2: clinical organization identity (`cpms_patients.identity_id`).
                        $identity_id = $this->identity_activator->activate_for_new_patient(
                            $clinic_id,
                            $patient_id,
                            $mobile,
                            $wp_user_id
                        );

                        // Artefact 3: organization identity <-> WP user edge (`cpms_patient_identity_links`).
                        if ( ! $this->identity_repo->user_link_exists( $identity_id, $wp_user_id ) ) {
                            $this->identity_service->link_user( $organization_id, $identity_id, $wp_user_id, $mobile );
                        }
                        if ( ! $this->identity_repo->user_link_exists( $identity_id, $wp_user_id ) ) {
                            throw new RuntimeException( 'identity_user_link_postcondition_failed' );
                        }

                        // Artefact 4a: durable clinic patient <-> WP user link (`cpms_patient_user_links`).
                        $link_inserted = $this->db->insert(
                            'cpms_patient_user_links',
                            [
                                'clinic_id'      => $clinic_id,
                                'patient_id'     => $patient_id,
                                'wp_user_id'     => $wp_user_id,
                                'mobile_at_link' => $mobile,
                                'is_primary'     => 1,
                                'linked_at'      => $now_sql,
                            ]
                        );
                        if ( ! $link_inserted ) {
                            throw new RuntimeException( 'patient_user_link_insert_failed' );
                        }

                        // Artefact 4b: active two_factor binding (`cpms_patient_logins`).
                        $verified_at = $now_sql;
                        $binding     = $this->logins->upsert_active_binding( $wp_user_id, $clinic_id, $patient_id, $verified_at );

                        // Audit: IDs and closed codes ONLY — never NID, mobile, names, or SQL.
                        $this->audit->log(
                            $is_new_row ? 'PATIENT_REGISTERED' : 'PATIENT_REGISTRATION_LINKED',
                            [ 'wp_user_id' => $wp_user_id, 'role' => 'patient' ],
                            'patient_login_binding',
                            $binding['id'],
                            $patient_id,
                            null,
                            [
                                'binding_id'          => $binding['id'],
                                'verification_method' => PatientLoginRepository::METHOD_TWO_FACTOR,
                                'state'               => PatientLoginRepository::STATE_ACTIVE,
                                'record_origin'       => $is_new_row ? 'created' : 'linked_existing',
                            ],
                            [ 'clinic_id' => $clinic_id ],
                            $clinic_id
                        );

                        return [
                            'user_id'     => $wp_user_id,
                            'patient_id'  => $patient_id,
                            'clinic_id'   => $clinic_id,
                            'binding_id'  => $binding['id'],
                            'verified_at' => $binding['verified_at'],
                        ];
                    } finally {
                        $this->db->setStrict( $was_strict );
                    }
                }
            );
        } catch ( Throwable ) {
            // Invalidate WP user cache AFTER CpmsDb::transactional() has rolled back.
            UserCacheRollback::invalidate( null !== $created_user_descriptor ? [ $created_user_descriptor ] : [] );
            $this->log_failure( 'registration_failed', $clinic_id );
            throw $this->uniform_failure();
        }

        $wp_user_id     = (int) $outcome['user_id'];
        $patient_id     = (int) $outcome['patient_id'];
        $binding_id     = (int) $outcome['binding_id'];
        $verified_at    = (string) $outcome['verified_at'];
        $session_issued = false;

        if ( $issue_session ) {
            $this->session_scope->begin_otp_cookie_issue( $wp_user_id, $clinic_id );
            try {
                $session_token = $this->proofs->issue_non_persistent_auth_cookie( $wp_user_id );
            } finally {
                $this->session_scope->end_otp_cookie_issue();
            }

            if ( '' === $session_token ) {
                $this->log_failure( 'session_cookie_issue_failed', $clinic_id );
                throw $this->uniform_failure();
            }

            $mobile_stamped  = $this->proofs->record_mobile_proof( $wp_user_id, $mobile, $now_ts, $session_token );
            $binding_stamped = $this->proofs->record_binding_proof(
                $wp_user_id,
                $clinic_id,
                $patient_id,
                $binding_id,
                $verified_at,
                $now_ts,
                $session_token
            );

            if ( ! $mobile_stamped || ! $binding_stamped ) {
                $this->proofs->destroy_all_sessions( $wp_user_id );
                $this->log_failure( 'session_proof_stamp_failed', $clinic_id );
                throw $this->uniform_failure();
            }

            $this->session_scope->set_in_process_session_token( $wp_user_id, $session_token );
            $session_issued = true;
        }

        // Optional post-commit SMS notice (never includes NID).
        if ( null !== $this->sms_notifier ) {
            try {
                ( $this->sms_notifier )( $clinic_id, $mobile, $patient_id );
            } catch ( Throwable ) {
                $this->log_failure( 'sms_notice_failed', $clinic_id );
            }
        }

        return [
            'registered'     => true,
            'user_id'        => $wp_user_id,
            'patient_id'     => $patient_id,
            'clinic_id'      => $clinic_id,
            'binding_id'     => $binding_id,
            'session_issued' => $session_issued,
        ];
    }

    private function enforce_pre_limits( ?string $mobile, ?string $normalized_nid, string $ip ): void {
        try {
            $ip_rl = $this->rate->hit( $this->hmac->ip_rate_limit_key_with_domain( 'reg-ip', $ip ), self::RATE_IP_MAX, self::RATE_WINDOW_SEC );
            if ( ! $ip_rl['allowed'] ) {
                $this->log_failure( 'rate_limited_ip', 0 );
                throw $this->uniform_failure();
            }

            if ( null !== $mobile ) {
                $mob_rl = $this->rate->hit(
                    $this->hmac->mobile_rate_limit_key_with_domain( 'reg-mob', 'register', $mobile ),
                    self::RATE_MOBILE_MAX,
                    self::RATE_WINDOW_SEC
                );
                if ( ! $mob_rl['allowed'] ) {
                    $this->log_failure( 'rate_limited_mobile', 0 );
                    throw $this->uniform_failure();
                }
            }

            if ( null !== $normalized_nid ) {
                $read_keys = $this->hmac->rate_limit_keys_for_read_with_domain( 'reg-nid', 'register', $normalized_nid );
                foreach ( $read_keys as $candidate_key ) {
                    if ( $this->rate->peek( $candidate_key, self::RATE_WINDOW_SEC ) >= self::RATE_NID_MAX ) {
                        $this->log_failure( 'rate_limited_nid', 0 );
                        throw $this->uniform_failure();
                    }
                }
                $nid_rl = $this->rate->hit(
                    $this->hmac->rate_limit_key_with_domain( 'reg-nid', 'register', $normalized_nid ),
                    self::RATE_NID_MAX,
                    self::RATE_WINDOW_SEC
                );
                if ( ! $nid_rl['allowed'] ) {
                    $this->log_failure( 'rate_limited_nid', 0 );
                    throw $this->uniform_failure();
                }
            }
        } catch ( BookingException $e ) {
            throw $e;
        } catch ( Throwable ) {
            $this->log_failure( 'rate_limiter_unavailable', 0 );
            throw $this->uniform_failure();
        }
    }

    private function is_trusted_clinic_unlocked( int $clinic_id ): bool {
        if ( $clinic_id <= 0 ) {
            return false;
        }

        $row = $this->db->fetchRow(
            'SELECT c.id FROM ' . $this->db->table( 'cpms_clinics' ) . ' c'
            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
            . " WHERE c.id = %d AND c.status = 'active' AND o.status = 'active' LIMIT 1",
            [ $clinic_id ]
        );
        if ( null === $row ) {
            return false;
        }

        if ( ( new PublicClinicDirectory() )->is_listed( $clinic_id ) ) {
            return true;
        }

        $active_ids = $this->db->fetchAll(
            'SELECT c.id FROM ' . $this->db->table( 'cpms_clinics' ) . ' c'
            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
            . " WHERE c.status = 'active' AND o.status = 'active' ORDER BY c.id ASC LIMIT 2"
        );

        return 1 === count( $active_ids ) && (int) ( $active_ids[0]['id'] ?? 0 ) === $clinic_id;
    }

    private function clean_name( string $raw ): ?string {
        $stripped = preg_replace( '/[\x00-\x1F]/u', '', $raw );
        if ( null === $stripped ) {
            return null;
        }
        $trimmed = trim( $stripped );
        if ( '' === $trimmed || VisibleText::has_bidi_control( $trimmed ) || ! VisibleText::has_visible_content( $trimmed ) ) {
            return null;
        }

        return mb_substr( $trimmed, 0, self::MAX_NAME_LEN );
    }

    private function clean_birth_date( string $raw, int $now_ts ): ?string {
        $folded = self::fold_ascii_digits( trim( $raw ) );
        if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $folded, $matches ) ) {
            return null;
        }

        $year  = (int) $matches[1];
        $month = (int) $matches[2];
        $day   = (int) $matches[3];
        if ( $year < 1900 || ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $folded, new DateTimeZone( 'UTC' ) );
        if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $folded ) {
            return null;
        }

        $today_utc = gmdate( 'Y-m-d', $now_ts );
        if ( $folded > $today_utc ) {
            return null;
        }

        return $folded;
    }

    private function generate_mrn( int $clinic_id ): string {
        for ( $i = 0; $i < 5; $i++ ) {
            $mrn    = 'MR-' . gmdate( 'ymd' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 5 ) );
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND mrn = %s',
                [ $clinic_id, $mrn ]
            );
            if ( 0 === (int) $exists ) {
                return $mrn;
            }
        }

        throw new RuntimeException( 'mrn_generation_failed' );
    }

    private static function fold_ascii_digits( string $value ): string {
        return strtr(
            $value,
            [
                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',
                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',
            ]
        );
    }

    private function uniform_failure(): BookingException {
        return BookingException::of(
            PatientAccess::PROOF_FAILED_CODE,
            PatientAccess::PROOF_FAILED_MESSAGE,
            403
        );
    }

    private function log_failure( string $category, int $clinic_id ): void {
        $this->op->warning(
            'patient.register.rejected',
            [
                'category'  => $category,
                'clinic_id' => $clinic_id,
            ]
        );
    }
}

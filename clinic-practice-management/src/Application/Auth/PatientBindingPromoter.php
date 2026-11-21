<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Patients\PatientIdentityActivator;
use ClinicCore\Application\Patients\PatientIdentityService;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Security\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Single atomic two-factor binding promoter (`cpms_patient_logins`, §5 / §6.2).
 *
 * Enforces every security invariant of the approved two-proof design:
 *  - Must execute at CpmsDb transaction depth 0 (`tx_depth() === 0`).
 *  - Caller must be an authenticated pure-patient account (no staff roles/caps/memberships).
 *  - Rate-limits by account, keyed mobile hash, keyed NID HMAC, and IP (never raw NID).
 *  - Requires a live WordPress session and EITHER a fresh single-use `verify_mobile`
 *    OTP consumed at depth 0 OR an existing valid `cpms_mobile_proofs` entry in the
 *    same live session for the account's own mobile.
 *  - Looks up the candidate patient strictly by `(clinic_id, mobile)` under `FOR UPDATE`
 *    (never placing `national_id` in SQL WHERE) and compares normalized NIDs in PHP via
 *    `hash_equals()`.
 *  - Atomically promotes the four artefacts inside one depth-0 transaction:
 *      1) `cpms_patient_logins` account row (`pending` -> `active`)
 *      2) `cpms_patients.identity_id` (clinical edge)
 *      3) `cpms_patient_identity_links` (identity <-> wp_user edge)
 *      4) `cpms_patient_user_links` (Clinic link) + `cpms_patient_logins` `active` `two_factor` binding
 *  - Writes `cpms_proofs` to the live `WP_Session_Tokens` session ONLY after commit.
 *  - Returns the uniform `403 CLINIC_PATIENT_PROOF_FAILED` envelope for every failure cause.
 */
final class PatientBindingPromoter {

    public const RATE_ACCOUNT_MAX = 5;
    public const RATE_MOBILE_MAX  = 8;
    public const RATE_NID_MAX     = 8;
    public const RATE_IP_MAX      = 20;
    public const RATE_WINDOW_SEC  = 900;

    /** @var (callable(int, string, int): void)|null */
    private $sms_notifier;

    /**
     * @param (callable(int, string, int): void)|null $sms_notifier Optional post-commit SMS hook (receives clinic_id, mobile, patient_id; never NID).
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly PatientAccess $access,
        private readonly PatientLoginRepository $logins,
        private readonly PatientSessionProofStore $proofs,
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
     * Promotes a two-factor patient binding and stamps the live session proof.
     *
     * @return array{binding_id: int, patient_id: int, clinic_id: int, verified_at: string}
     * @throws BookingException Always `403 CLINIC_PATIENT_PROOF_FAILED` on any refusal or error.
     */
    public function promote(
        int $wp_user_id,
        int $clinic_id,
        string $raw_mobile,
        string $raw_national_id,
        ?string $otp_code = null,
        string $ip = '0.0.0.0',
        ?int $now = null,
        ?string $session_token = null
    ): array {
        if ( $this->db->tx_depth() !== 0 ) {
            $this->log_failure( 'nested_transaction', $wp_user_id, $clinic_id );
            throw $this->uniform_failure();
        }

        if ( $wp_user_id <= 0 || $clinic_id <= 0 || ! $this->access->is_pure_patient_actor( $wp_user_id ) ) {
            $this->log_failure( 'actor_rejected', $wp_user_id, $clinic_id );
            throw $this->uniform_failure();
        }

        $mobile         = MobileValidator::normalize( $raw_mobile );
        $normalized_nid = PatientNidHmac::normalize( $raw_national_id );

        // Charge account and IP limits before input rejection so brute-force
        // attempts on malformed payloads cannot bypass throttling.
        $this->enforce_pre_limits( $wp_user_id, $mobile, $normalized_nid, $ip );

        if ( null === $mobile || null === $normalized_nid ) {
            $this->log_failure( 'invalid_input', $wp_user_id, $clinic_id );
            throw $this->uniform_failure();
        }

        $now_ts = $now ?? time();

        // Step 1: Verify mobile possession inside THIS live session (depth 0).
        if ( null !== $otp_code && '' !== trim( $otp_code ) ) {
            try {
                $this->otp->verify( $mobile, trim( $otp_code ), OtpService::PURPOSE_VERIFY_MOBILE, $ip );
            } catch ( Throwable ) {
                $this->log_failure( 'otp_verify_failed', $wp_user_id, $clinic_id );
                throw $this->uniform_failure();
            }

            if ( ! $this->proofs->record_mobile_proof( $wp_user_id, $mobile, $now_ts, $session_token ) ) {
                $this->log_failure( 'session_mobile_stamp_failed', $wp_user_id, $clinic_id );
                throw $this->uniform_failure();
            }
        } else {
            // Without a fresh OTP code, the mobile MUST already be proved in THIS
            // session AND match the account's own registered mobile (dependents
            // with distinct numbers always require an OTP to that number).
            if ( ! $this->proofs->has_valid_mobile_proof( $wp_user_id, $mobile, $now_ts, $session_token ) ) {
                $this->log_failure( 'mobile_proof_missing', $wp_user_id, $clinic_id );
                throw $this->uniform_failure();
            }
            try {
                $pre_account = $this->logins->find_account_by_user_id( $wp_user_id );
            } catch ( Throwable ) {
                $this->log_failure( 'account_read_failed', $wp_user_id, $clinic_id );
                throw $this->uniform_failure();
            }
            if ( null === $pre_account || (string) ( $pre_account['mobile'] ?? '' ) !== $mobile ) {
                $this->log_failure( 'distinct_mobile_requires_otp', $wp_user_id, $clinic_id );
                throw $this->uniform_failure();
            }
        }

        // Step 2: Atomic depth-0 promotion over all four durable artefacts (§5).
        try {
            $promoted = $this->db->transactional(
                function () use ( $wp_user_id, $clinic_id, $mobile, $normalized_nid ): array {
                    $was_strict = $this->db->is_strict();
                    $this->db->setStrict( true );
                    try {
                        // Lock order (§8): account row first.
                        $account = $this->logins->find_account_by_user_id( $wp_user_id, true );
                        if ( null === $account ) {
                            throw new RuntimeException( 'account_missing' );
                        }
                        $account_state = (string) ( $account['state'] ?? '' );
                        if ( PatientLoginRepository::STATE_CLOSED === $account_state ) {
                            throw new RuntimeException( 'account_closed' );
                        }

                        // Resolve active Clinic + Organization server-side.
                        $clinic_rows = $this->db->fetch_all_strict(
                            'SELECT c.id, c.organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' c'
                            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
                            . " WHERE c.id = %d AND c.status = 'active' AND o.status = 'active'"
                            . ' LIMIT 1 FOR UPDATE',
                            [ $clinic_id ]
                        );
                        if ( [] === $clinic_rows ) {
                            throw new RuntimeException( 'clinic_inactive' );
                        }
                        $organization_id = (int) $clinic_rows[0]['organization_id'];

                        // H1 / K1: Lookup candidate record by (clinic_id, OTP-verified mobile)
                        // only — NEVER place `national_id` in SQL WHERE.
                        $patient_rows = $this->db->fetch_all_strict(
                            'SELECT id, clinic_id, mobile, national_id, status, identity_id'
                            . ' FROM ' . $this->db->table( 'cpms_patients' )
                            . " WHERE clinic_id = %d AND mobile = %s AND status = 'active'"
                            . ' LIMIT 1 FOR UPDATE',
                            [ $clinic_id, $mobile ]
                        );
                        if ( [] === $patient_rows ) {
                            throw new RuntimeException( 'patient_not_found' );
                        }
                        $patient        = $patient_rows[0];
                        $patient_id     = (int) $patient['id'];
                        $record_mobile  = MobileValidator::normalize( (string) ( $patient['mobile'] ?? '' ) );
                        $stored_nid_raw = $patient['national_id'] ?? null;
                        $stored_nid     = is_string( $stored_nid_raw ) ? PatientNidHmac::normalize( $stored_nid_raw ) : null;

                        // M1 & M2 & PHP constant-time comparison.
                        if ( null === $record_mobile || $record_mobile !== $mobile ) {
                            throw new RuntimeException( 'mobile_mismatch' );
                        }
                        if ( null === $stored_nid || ! hash_equals( $stored_nid, $normalized_nid ) ) {
                            throw new RuntimeException( 'nid_mismatch' );
                        }

                        // Artefact 1: account pending -> active.
                        if ( PatientLoginRepository::STATE_PENDING === $account_state ) {
                            $this->logins->activate_account_from_pending( (int) $account['id'], $wp_user_id );
                        } elseif ( PatientLoginRepository::STATE_ACTIVE !== $account_state ) {
                            throw new RuntimeException( 'account_invalid_state' );
                        }

                        // Artefact 2: clinical identity edge (`cpms_patients.identity_id`).
                        $identity_id = $this->identity_activator->activate_for_new_patient(
                            $clinic_id,
                            $patient_id,
                            $mobile,
                            $wp_user_id
                        );

                        // Artefact 3: identity <-> WP user edge (`cpms_patient_identity_links`).
                        if ( ! $this->identity_repo->user_link_exists( $identity_id, $wp_user_id ) ) {
                            $this->identity_service->link_user( $organization_id, $identity_id, $wp_user_id, $mobile );
                        }
                        if ( ! $this->identity_repo->user_link_exists( $identity_id, $wp_user_id ) ) {
                            throw new RuntimeException( 'identity_user_link_postcondition_failed' );
                        }

                        // Artefact 4a: Clinic patient <-> WP user link (`cpms_patient_user_links`).
                        $this->ensure_patient_user_link( $clinic_id, $patient_id, $wp_user_id, $mobile );

                        // Artefact 4b: active two_factor binding in `cpms_patient_logins`.
                        $verified_at = $this->db->nowUtcSql();
                        $binding     = $this->logins->upsert_active_binding( $wp_user_id, $clinic_id, $patient_id, $verified_at );

                        // Audit: IDs and closed codes only (no NID, no mobile, no name, no SQL).
                        $this->audit->log(
                            'PATIENT_BINDING_PROMOTED',
                            [ 'wp_user_id' => $wp_user_id, 'role' => 'patient' ],
                            'patient_login_binding',
                            $binding['id'],
                            $patient_id,
                            null,
                            [
                                'binding_id'          => $binding['id'],
                                'verification_method' => PatientLoginRepository::METHOD_TWO_FACTOR,
                                'state'               => PatientLoginRepository::STATE_ACTIVE,
                            ],
                            [ 'clinic_id' => $clinic_id ],
                            $clinic_id
                        );

                        return [
                            'binding_id'  => $binding['id'],
                            'patient_id'  => $patient_id,
                            'clinic_id'   => $clinic_id,
                            'verified_at' => $binding['verified_at'],
                        ];
                    } finally {
                        $this->db->setStrict( $was_strict );
                    }
                }
            );
        } catch ( Throwable $e ) {
            $this->log_failure( 'promotion_failed', $wp_user_id, $clinic_id );
            throw $this->uniform_failure();
        }

        // Step 3: Post-commit session binding proof stamp (§2.7).
        $stamped = $this->proofs->record_binding_proof(
            $wp_user_id,
            $promoted['clinic_id'],
            $promoted['patient_id'],
            $promoted['binding_id'],
            $promoted['verified_at'],
            $now_ts,
            $session_token
        );
        if ( ! $stamped ) {
            $this->log_failure( 'session_binding_stamp_failed', $wp_user_id, $clinic_id );
            throw $this->uniform_failure();
        }

        // Step 4: Optional post-commit SMS notice (M6, never includes NID).
        if ( null !== $this->sms_notifier ) {
            try {
                ( $this->sms_notifier )( $promoted['clinic_id'], $mobile, $promoted['patient_id'] );
            } catch ( Throwable ) {
                // Non-fatal post-commit notification failure never breaks committed proof.
                $this->log_failure( 'sms_notice_failed', $wp_user_id, $clinic_id );
            }
        }

        return $promoted;
    }

    /**
     * Enforces per-account, per-mobile, per-NID-HMAC, and per-IP rate limits (§9).
     */
    private function enforce_pre_limits(
        int $wp_user_id,
        ?string $mobile,
        ?string $normalized_nid,
        string $ip
    ): void {
        $acct_rl = $this->rate->hit( $this->hmac->account_rate_limit_key( $wp_user_id ), self::RATE_ACCOUNT_MAX, self::RATE_WINDOW_SEC );
        $ip_rl   = $this->rate->hit( $this->hmac->ip_rate_limit_key( $ip ), self::RATE_IP_MAX, self::RATE_WINDOW_SEC );
        if ( ! $acct_rl['allowed'] || ! $ip_rl['allowed'] ) {
            $this->log_failure( 'rate_limited_account_or_ip', $wp_user_id, 0 );
            throw $this->uniform_failure();
        }

        if ( null !== $mobile ) {
            $mob_rl = $this->rate->hit( $this->hmac->mobile_rate_limit_key( $mobile ), self::RATE_MOBILE_MAX, self::RATE_WINDOW_SEC );
            if ( ! $mob_rl['allowed'] ) {
                $this->log_failure( 'rate_limited_mobile', $wp_user_id, 0 );
                throw $this->uniform_failure();
            }
        }

        if ( null !== $normalized_nid ) {
            $read_keys = $this->hmac->rate_limit_keys_for_read( $normalized_nid );
            foreach ( $read_keys as $candidate_key ) {
                if ( $this->rate->peek( $candidate_key, self::RATE_WINDOW_SEC ) >= self::RATE_NID_MAX ) {
                    $this->log_failure( 'rate_limited_nid', $wp_user_id, 0 );
                    throw $this->uniform_failure();
                }
            }
            $nid_rl = $this->rate->hit( $this->hmac->rate_limit_key( $normalized_nid ), self::RATE_NID_MAX, self::RATE_WINDOW_SEC );
            if ( ! $nid_rl['allowed'] ) {
                $this->log_failure( 'rate_limited_nid', $wp_user_id, 0 );
                throw $this->uniform_failure();
            }
        }
    }

    private function ensure_patient_user_link( int $clinic_id, int $patient_id, int $wp_user_id, string $mobile ): void {
        $existing = $this->db->fetch_all_strict(
            'SELECT id FROM ' . $this->db->table( 'cpms_patient_user_links' )
            . ' WHERE clinic_id = %d AND patient_id = %d AND wp_user_id = %d'
            . ' LIMIT 1 FOR UPDATE',
            [ $clinic_id, $patient_id, $wp_user_id ]
        );
        if ( [] === $existing ) {
            $inserted = $this->db->insert(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $clinic_id,
                    'patient_id'     => $patient_id,
                    'wp_user_id'     => $wp_user_id,
                    'mobile_at_link' => $mobile,
                    'is_primary'     => 0,
                    'linked_at'      => $this->db->nowUtcSql(),
                ]
            );
            if ( ! $inserted ) {
                throw new RuntimeException( 'patient_user_link_insert_failed' );
            }
        }

        $confirmed = $this->db->fetch_all_strict(
            'SELECT id FROM ' . $this->db->table( 'cpms_patient_user_links' )
            . ' WHERE clinic_id = %d AND patient_id = %d AND wp_user_id = %d'
            . ' LIMIT 1',
            [ $clinic_id, $patient_id, $wp_user_id ]
        );
        if ( [] === $confirmed ) {
            throw new RuntimeException( 'patient_user_link_postcondition_failed' );
        }
    }

    private function uniform_failure(): BookingException {
        return new BookingException(
            PatientAccess::PROOF_FAILED_CODE,
            PatientAccess::PROOF_FAILED_MESSAGE,
            403
        );
    }

    private function log_failure( string $category, int $wp_user_id, int $clinic_id ): void {
        $this->op->warning(
            'patient.two_proof_rejected',
            [
                'category'   => $category,
                'wp_user_id' => $wp_user_id,
                'clinic_id'  => $clinic_id,
            ]
        );
    }
}

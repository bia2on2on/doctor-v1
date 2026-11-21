<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/** Local signed-license enforcement. Owner post-expiration policy supersedes
 * the historical ordinary-expiration exemption and grace-based permissions.
 */
final class SignedLicenseGate implements LicenseGate
{
    /**
     * عملیات‌هایی که در حالت محدود (RESTRICTED و بدتر) مسدود می‌شوند.
     */
    public const BLOCKED_UNDER_RESTRICTION = [
        LicenseGate::OP_PATIENT_CREATE,
        LicenseGate::OP_APPOINTMENT_BOOK,
        LicenseGate::OP_APPOINTMENT_RESCHEDULE,
        LicenseGate::OP_VISIT_CHECKIN,
        LicenseGate::OP_INVOICE_CREATE,
        LicenseGate::OP_SMS_COMMERCIAL,
        LicenseGate::OP_HANDWRITING_NEW,
        LicenseGate::OP_VISIT_FINISH,
        LicenseGate::OP_PAYMENT_RECORD,
        LicenseGate::OP_REFUND,
        LicenseGate::OP_APPOINTMENT_NOSHOW,
        LicenseGate::OP_SETTINGS_WRITE,
        LicenseGate::OP_FILE_UPLOAD,
    ];

    /**
     * Same-Visit finish lane (design §7.2 item 5; PR#11 owner rule): an
     * already-opened Visit stays finishable in every license state. Context is
     * always server-derived (VisitLicenseContext), never request data.
     */
    private const SAFE_FINISH_OPERATIONS = [
        LicenseGate::OP_HANDWRITING_NEW,
        LicenseGate::OP_VISIT_FINISH,
        LicenseGate::OP_INVOICE_CREATE,
        LicenseGate::OP_PAYMENT_RECORD,
        LicenseGate::OP_FILE_UPLOAD,
    ];

    public function __construct(
        private readonly LicenseStateProvider $provider,
        private readonly ?ReturningPatientEvidence $returning = null,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function assert(string $operation, array $context = []): LicenseDecision
    {
        $state = $this->provider->currentState();
        $status = $state['status'];

        $now        = $this->clock !== null ? ( $this->clock )() : time();
        $expires_at = $state['expires_at'] ?? null;
        // B1 (design §7.1): a locally cached verified ordinary signed document must
        // enforce its own T0/T1 regardless of outage age; binding mismatch, revocation,
        // expiry-window and no-cache states are never synthesized into an ordinary term.
        $ordinary   = self::isOrdinaryCached( $status, $state );

        // Owner rule (three-stage license; PR#11 for handwriting): the finish
        // lane for an already-opened Visit stays open in every license state.
        // From T1 onwards the Visit must itself have been opened before T1
        // (created_at < T1) and remain active and non-terminal.
        if ( in_array( $operation, self::SAFE_FINISH_OPERATIONS, true ) && true === ( $context['visit_opened'] ?? false ) ) {
            $reason_str     = (string) ( $state['reason'] ?? '' );
            $expiry_bounded = $ordinary
                || ( LicenseStatus::UNREACHABLE === $status && 'cached_state_stale' === $reason_str )
                || ( LicenseStatus::RESTRICTED === $status && 'expired_unreachable' === $reason_str );
            if ( $expiry_bounded ) {
                // Stored-payload trust (design §7.5): an expiry-bounded finish lane
                // whose signed bound cannot be read is never allowed through — fail closed.
                if ( ! is_int( $expires_at ) || $expires_at <= 0 ) {
                    return LicenseDecision::deny( 'license:' . $status );
                }
                $t1 = LicenseStagePolicy::t1( $expires_at );
                if ( $now >= $t1 ) {
                    $visit_created_at = $context['visit_created_at'] ?? null;
                    if ( ! is_int( $visit_created_at ) || $visit_created_at <= 0 || $visit_created_at >= $t1 ) {
                        return LicenseDecision::deny( 'license:expired' );
                    }
                }

                return LicenseDecision::allow();
            }
            if ( LicenseStatus::RESTRICTED === $status
                && in_array( $reason_str, [ 'activation_window_expired', 'migration_grace_expired' ], true )
            ) {
                if ( ! is_int( $expires_at ) || $expires_at <= 0 ) {
                    return LicenseDecision::deny( 'license:' . LicenseStatus::RESTRICTED );
                }
                // computeActivationWindow() allows now <= end (design §7.4 :318); half-open cutoff is end + 1.
                $window_cutoff    = $expires_at + 1;
                $visit_created_at = $context['visit_created_at'] ?? null;
                if ( ! is_int( $visit_created_at ) || $visit_created_at <= 0 || $visit_created_at >= $window_cutoff ) {
                    return LicenseDecision::deny( 'license:' . LicenseStatus::RESTRICTED );
                }
            }

            return LicenseDecision::allow();
        }

        // Owner rule (three-stage license, final 2026-11-17): signed
        // ordinary-expiry terms get stage-aware decisions. Grace and skew
        // never move T0; invalid/missing license data never synthesizes one.
        if ( $ordinary && is_int( $expires_at ) && $expires_at > 0 ) {
            return $this->assertOrdinaryExpiry( $operation, $context, $status, $expires_at, $now );
        }

        if ( LicenseStatus::allowsNewBusiness( $status ) ) {
            return LicenseDecision::allow();
        }
        if ( ! in_array( $operation, self::BLOCKED_UNDER_RESTRICTION, true ) ) {
            return LicenseDecision::allow();
        }

        $reason = match ($status) {
            LicenseStatus::GRACE => 'license:' . LicenseStatus::GRACE,
            LicenseStatus::RESTRICTED => 'license:' . LicenseStatus::RESTRICTED,
            LicenseStatus::SUSPENDED => 'license:' . LicenseStatus::SUSPENDED,
            LicenseStatus::REVOKED => 'license:' . LicenseStatus::REVOKED,
            LicenseStatus::INVALID => 'license:' . LicenseStatus::INVALID,
            default => 'license:unreachable',
        };

        return LicenseDecision::deny($reason);
    }

    /**
     * Stage-aware decisions for ordinary signed-expiry terms (design §6.1/§7.3;
     * final Q1(a)/Q2(a)/Q3(a)/Q4a(a)). Replaces the superseded one-/six-month
     * post-expiration grants; T1 is T0 + 1 Gregorian month, UTC, day-clamped.
     *
     * @param array<string, mixed> $context Server-derived eligibility context.
     */
    private function assertOrdinaryExpiry( string $operation, array $context, string $status, int $expires_at, int $now ): LicenseDecision {
        $stage = LicenseStagePolicy::stage( $expires_at, $now );
        if ( LicenseStagePolicy::STAGE_VALID === $stage ) {
            return LicenseDecision::allow();
        }

        switch ( $operation ) {
            case LicenseGate::OP_APPOINTMENT_BOOK:
            case LicenseGate::OP_APPOINTMENT_RESCHEDULE:
            case LicenseGate::OP_PATIENT_CREATE:
                // Q1(a)/Q2(a): no new demand from T0, in every reception/returning shape.
                return LicenseDecision::deny( 'license:expired' );

            case LicenseGate::OP_SMS_COMMERCIAL:
                // Stage 2 keeps the SMS channel closed EXCEPT care-related SMS needed to
                // follow previously booked appointments (design §7.2 item 6; T-R12-14):
                // the allow requires server-derived persisted care linkage
                // (context['care_linked'], set only from an actual persisted row matched
                // to the same Clinic — appointment/follow_up). Stage 3 is view-only.
                return ( LicenseStagePolicy::STAGE_CONTINUITY === $stage && true === ( $context['care_linked'] ?? false ) )
                    ? LicenseDecision::allow()
                    : LicenseDecision::deny( 'license:expired' );

            case LicenseGate::OP_VISIT_CHECKIN:
                // Q3(a): only a durable pre-T0 pending/confirmed appointment, and only while now < T1.
                $booking_time       = $context['appointment_created_at'] ?? null;
                $appointment_status = (string) ( $context['appointment_status'] ?? '' );
                $admitted           = LicenseStagePolicy::may_admit_booked_visit(
                    $expires_at,
                    $now,
                    is_int( $booking_time ) ? $booking_time : 0
                ) && in_array( $appointment_status, [ 'pending', 'confirmed' ], true );

                return $admitted ? LicenseDecision::allow() : LicenseDecision::deny( 'license:expired' );

            case LicenseGate::OP_VISIT_FINISH:
            case LicenseGate::OP_INVOICE_CREATE:
            case LicenseGate::OP_PAYMENT_RECORD:
                // No already-opened Visit context: Stage 2 keeps ordinary RBAC
                // (no restriction from T0); Stage 3 is read-only.
                return LicenseStagePolicy::STAGE_READ_ONLY === $stage
                    ? LicenseDecision::deny( 'license:expired' )
                    : LicenseDecision::allow();

            case LicenseGate::OP_FILE_UPLOAD:
                // Stage 2 preserves existing upload behavior; Stage 3 needs the
                // already-opened-visit safe-finish lane (created_at < T1), else deny.
                return LicenseStagePolicy::STAGE_READ_ONLY === $stage
                    ? LicenseDecision::deny( 'license:expired' )
                    : LicenseDecision::allow();

            case LicenseGate::OP_REFUND:
                // Q4a(a): every historical refund is denied at/after T1; allowed through T1-1s under RBAC.
                return LicenseStagePolicy::may_refund( $expires_at, $now, (int) ( $context['original_payment_time'] ?? 0 ) )
                    ? LicenseDecision::allow()
                    : LicenseDecision::deny( 'license:expired' );

            case LicenseGate::OP_APPOINTMENT_CANCEL:
            case LicenseGate::OP_APPOINTMENT_NOSHOW:
            case LicenseGate::OP_PATIENT_UPDATE:
            case LicenseGate::OP_SETTINGS_WRITE:
                // Stage 2 existing-record maintenance under RBAC; Stage 3 is view-only.
                return LicenseStagePolicy::STAGE_CONTINUITY === $stage
                    ? LicenseDecision::allow()
                    : LicenseDecision::deny( 'license:expired' );

            default:
                if ( ! in_array( $operation, self::BLOCKED_UNDER_RESTRICTION, true ) ) {
                    // Hygiene operations outside the new-business lanes stay allowed.
                    return LicenseDecision::allow();
                }

                return LicenseDecision::deny( 'license:' . $status );
        }
    }

    public function state(): string
    {
        return $this->provider->currentState()['status'];
    }

    public function isReadOnly(): bool
    {
        $state      = $this->provider->currentState();
        $status     = $state['status'];
        $expires_at = $state['expires_at'] ?? null;
        if ( self::isOrdinaryCached( $status, $state ) && is_int( $expires_at ) && $expires_at > 0 ) {
            $now = $this->clock !== null ? ( $this->clock )() : time();

            // Ordinary cached terms enforce their own T0/T1 regardless of outage age (B1).
            return LicenseStagePolicy::stage( $expires_at, $now ) === LicenseStagePolicy::STAGE_READ_ONLY;
        }

        return LicenseStatus::isRestricted( $status );
    }

    /**
     * True when the current state is a locally cached, verified ordinary signed
     * document (design §6.1/§7.1 B1). Mismatch/revocation/window expiry and
     * no-cache states are never synthesized into an ordinary term here.
     *
     * @param array<string, mixed> $state
     */
    private static function isOrdinaryCached( string $status, array $state ): bool {
        return match ( $status ) {
            LicenseStatus::ACTIVE => ( $state['reason'] ?? null ) === '',
            LicenseStatus::EXPIRING => ( $state['reason'] ?? null ) === 'near_expiry',
            LicenseStatus::GRACE => in_array( $state['reason'] ?? null, [ 'grace', 'unreachable_grace' ], true ),
            LicenseStatus::RESTRICTED => in_array( $state['reason'] ?? null, [ 'expired', 'expired_unreachable' ], true ),
            LicenseStatus::UNREACHABLE => ( $state['reason'] ?? null ) === 'cached_state_stale',
            default => false,
        };
    }
}

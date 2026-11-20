<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use PHPUnit\Framework\TestCase;

/**
 * F10 — SignedLicenseGate: رفتار عملیات در وضعیت‌های مختلف (spec §16/§17).
 *
 * سیاست RESTRICTED: مسدود = فعالیت مستقل جدید؛ مجاز = لغو/به‌روزرسانی/
 * بهداشت (و همه‌ی مسیرهای در جریان که assert نمی‌کنند — الگوی F4).
 */
final class SignedLicenseGateTest extends TestCase
{
    /**
     * @return LicenseStateProvider
     */
    private function provider(string $status): LicenseStateProvider
    {
        return new class($status) implements LicenseStateProvider {
            public function __construct(private readonly string $status)
            {
            }

            public function currentState(): array
            {
                return [
                    'status' => $this->status,
                    'reason' => 'test',
                    'expires_at' => null,
                    'needs_renewal' => false,
                ];
            }

            public function entitlements(): EntitlementRegistry
            {
                return new EntitlementRegistry();
            }
        };
    }

    /**
     * Provider با status + reason صریح — Phase 16 Slice 1: «علتِ» RESTRICTED
     * (نه خودِ status) تعیین‌کننده است. `$reason = null` یعنی کلید `reason`
     * اصلاً در state حضور ندارد (shape ناقص → باید fail-closed بماند).
     *
     * @return LicenseStateProvider
     */
    private function providerWithReason(string $status, ?string $reason): LicenseStateProvider
    {
        return new class($status, $reason) implements LicenseStateProvider {
            public function __construct(private readonly string $status, private readonly ?string $reason)
            {
            }

            public function currentState(): array
            {
                $state = [
                    'status' => $this->status,
                    'expires_at' => null,
                    'needs_renewal' => false,
                ];
                if ($this->reason !== null) {
                    $state['reason'] = $this->reason;
                }

                return $state;
            }

            public function entitlements(): EntitlementRegistry
            {
                return new EntitlementRegistry();
            }
        };
    }

    public function testActiveAllowsAllProtectedOps(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::ACTIVE));
        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_PATIENT_UPDATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_CANCEL,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} باید در ACTIVE مجاز باشد");
        }
        $this->assertFalse($gate->isReadOnly());
        $this->assertSame(LicenseStatus::ACTIVE, $gate->state());
    }

    public function testRestrictedBlocksNewBusinessButAllowsHygieneOps(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::RESTRICTED));

        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $decision = $gate->assert($op);
            $this->assertFalse($decision->allowed, "{$op} باید در RESTRICTED مسدود شود");
            $this->assertStringStartsWith('license:', $decision->reason);
        }

        // بهداشت/تکمیل/لغو — مجاز (spec §16)
        foreach ([
            LicenseGate::OP_PATIENT_UPDATE,
            LicenseGate::OP_APPOINTMENT_CANCEL,
        ] as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} باید در RESTRICTED مجاز بماند");
        }
        $this->assertTrue($gate->isReadOnly());
    }

    public function testRevokedAndSuspendedAlsoBlockNewBusiness(): void
    {
        foreach ([LicenseStatus::REVOKED, LicenseStatus::SUSPENDED] as $status) {
            $gate = new SignedLicenseGate($this->provider($status));
            $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
            $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
            $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        }
    }

    public function testGraceIsNotAuthorizationToBookAfterSignedT0(): void
    {
        $provider = new class implements LicenseStateProvider {
            public function currentState(): array
            {
                return [
                    'status' => LicenseStatus::GRACE,
                    'reason' => 'grace',
                    'expires_at' => time() - 1,
                    'needs_renewal' => true,
                ];
            }
            public function entitlements(): EntitlementRegistry
            {
                return new EntitlementRegistry();
            }
        };
        $gate = new SignedLicenseGate($provider);
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
    }

    public function testUnreachableWithoutCacheBlocksNewBusiness(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::UNREACHABLE));
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testNotConfiguredAllowsOperationButFlagsSetup(): void
    {
        // فعال‌سازی‌نشده: مجاز (ایمنی بیمار §1) — Health/Admin Setup را نشان می‌دهند
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::NOT_CONFIGURED));
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
        $this->assertFalse($gate->isReadOnly());
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $gate->state());
    }

    public function testUnknownStateFailsClosed(): void
    {
        $gate = new SignedLicenseGate($this->provider('bogus-state'));
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testExpirationWithoutTrustedTimestampDoesNotGrantAnException(): void
    {
        $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, 'expired'));
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertFalse($gate->assert($op)->allowed);
        }
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testActivationWindowExhaustionStillBlocksNewBusiness(): void
    {
        foreach (['activation_window_expired', 'migration_grace_expired'] as $reason) {
            $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, $reason));

            foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
                $decision = $gate->assert($op);
                $this->assertFalse($decision->allowed, "{$op} با reason={$reason} باید مسدود بماند");
                $this->assertStringStartsWith('license:', $decision->reason);
            }
            $this->assertTrue($gate->isReadOnly());
        }
    }

    public function testVendorUnreachableStaleExpirationStillBlocksNewBusiness(): void
    {
        // RESTRICTED + expired_unreachable = «انقضا با وضعیت vendor-unreachable/
        // stale» — نزدیک‌ترین همسایهٔ معنایی به انقضای عادی و صریحاً بیرون از
        // استثنای آن (تطبیق باید دقیق باشد، نه prefix/contains).
        $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, 'expired_unreachable'));

        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertFalse($gate->assert($op)->allowed, "{$op} با reason=expired_unreachable باید مسدود بماند");
        }
        $this->assertTrue($gate->isReadOnly());
    }

    public function testAbsentUnknownOrMalformedRestrictedReasonFailsClosed(): void
    {
        // کلید غایب، رشتهٔ خالی، علت ناشناخته و تطبیق‌های تقریبی — همه fail-closed.
        $reasons = [null, '', 'test', 'EXPIRED', ' expired', 'expired ', 'expiry', 'renewal_required'];
        foreach ($reasons as $reason) {
            $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, $reason));

            foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
                $this->assertFalse(
                    $gate->assert($op)->allowed,
                    "{$op} با reason=" . var_export($reason, true) . ' باید fail-closed مسدود بماند'
                );
            }
            $this->assertTrue($gate->isReadOnly());
        }
    }

    public function testExpiredReasonDoesNotRelaxAnyOtherStatus(): void
    {
        // استثنا فقط برای status=RESTRICTED است؛ reason=expired به‌تنهایی کافی نیست.
        $statuses = [
            LicenseStatus::UNREACHABLE,
            LicenseStatus::INVALID,
            LicenseStatus::SUSPENDED,
            LicenseStatus::REVOKED,
            'bogus-state',
        ];
        foreach ($statuses as $status) {
            $gate = new SignedLicenseGate($this->providerWithReason((string) $status, 'expired'));
            $this->assertFalse(
                $gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed,
                "status={$status} با reason=expired باید مسدود بماند"
            );
        }
    }

    public function testHandwritingForOpenedVisitAllowedAcrossAllLicenseStates(): void
    {
        $now = 1793448000;
        $cases = [
            [LicenseStatus::ACTIVE, ''],
            [LicenseStatus::EXPIRING, 'near_expiry'],
            [LicenseStatus::GRACE, 'grace'],
            [LicenseStatus::GRACE, 'unreachable_grace'],
            [LicenseStatus::RESTRICTED, 'expired'],
            [LicenseStatus::RESTRICTED, 'activation_window_expired'],
            [LicenseStatus::RESTRICTED, 'migration_grace_expired'],
            [LicenseStatus::RESTRICTED, 'binding_mismatch'],
            [LicenseStatus::RESTRICTED, 'expired_unreachable'],
            [LicenseStatus::SUSPENDED, 'suspended'],
            [LicenseStatus::REVOKED, 'revoked'],
            [LicenseStatus::INVALID, 'signature_invalid'],
            [LicenseStatus::UNREACHABLE, 'no_cached_state'],
            [LicenseStatus::UNREACHABLE, 'cached_state_stale'],
            [LicenseStatus::THROTTLED, 'throttled'],
            [LicenseStatus::NOT_CONFIGURED, 'not_configured'],
            [LicenseStatus::ACTIVATION_PENDING, 'activation_pending'],
            [LicenseStatus::ACTIVATION_GRACE, 'migration_grace'],
            [LicenseStatus::DEVELOPMENT, 'dev_mode'],
        ];

        foreach ($cases as [$status, $reason]) {
            $provider = new class($status, $reason, $now - 60) implements LicenseStateProvider {
                public function __construct(
                    private readonly string $status,
                    private readonly string $reason,
                    private readonly int $expiresAt
                ) {
                }

                public function currentState(): array
                {
                    return [
                        'status' => $this->status,
                        'reason' => $this->reason,
                        'expires_at' => $this->expiresAt,
                        'needs_renewal' => true,
                    ];
                }

                public function entitlements(): EntitlementRegistry
                {
                    return new EntitlementRegistry();
                }
            };
            $gate = new SignedLicenseGate($provider, null, static fn (): int => $now);
            $decision = $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                'visit_opened' => true,
                'visit_created_at' => $now - 120,
            ]);
            $this->assertTrue($decision->allowed, "{$status}/{$reason} must allow handwriting on already-opened visit");
        }
    }

    public function testHandwritingForClosedOrUnopenedVisitDeniedInRestrictedStates(): void
    {
        foreach ([
            [LicenseStatus::RESTRICTED, 'expired'],
            [LicenseStatus::RESTRICTED, 'activation_window_expired'],
            [LicenseStatus::SUSPENDED, 'suspended'],
            [LicenseStatus::REVOKED, 'revoked'],
            [LicenseStatus::INVALID, 'signature_invalid'],
            [LicenseStatus::UNREACHABLE, 'no_cached_state'],
            [LicenseStatus::THROTTLED, 'throttled'],
        ] as [$status, $reason]) {
            $gate = new SignedLicenseGate($this->providerWithReason($status, $reason));
            $this->assertFalse(
                $gate->assert(LicenseGate::OP_HANDWRITING_NEW, ['visit_opened' => false])->allowed,
                "{$status}/{$reason} must deny closed/unopened visit"
            );
            $this->assertFalse(
                $gate->assert(LicenseGate::OP_HANDWRITING_NEW)->allowed,
                "{$status}/{$reason} must deny uncontextualized handwriting"
            );
        }
    }

    public function testHandwritingActivationWindowRestrictedBoundaries(): void
    {
        $now = 1793448000;
        $windowEnd = $now - 60;
        foreach (['activation_window_expired', 'migration_grace_expired'] as $reason) {
            $provider = new class($reason, $windowEnd) implements LicenseStateProvider {
                public function __construct(
                    private readonly string $reason,
                    private readonly int $windowEnd
                ) {
                }

                public function currentState(): array
                {
                    return [
                        'status' => LicenseStatus::RESTRICTED,
                        'reason' => $this->reason,
                        'expires_at' => $this->windowEnd,
                        'needs_renewal' => true,
                    ];
                }

                public function entitlements(): EntitlementRegistry
                {
                    return new EntitlementRegistry();
                }
            };
            $gate = new SignedLicenseGate($provider, null, static fn (): int => $now);

            $this->assertTrue(
                $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $windowEnd,
                ])->allowed,
                "{$reason}: visit opened at last allowed instant of window must remain writable"
            );
            $afterDecision = $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                'visit_opened' => true,
                'visit_created_at' => $windowEnd + 1,
            ]);
            $this->assertFalse($afterDecision->allowed, "{$reason}: visit created after window cutoff must be denied");
            $this->assertSame('license:' . LicenseStatus::RESTRICTED, $afterDecision->reason);

            foreach ([0, null] as $badCreated) {
                $this->assertFalse(
                    $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                        'visit_opened' => true,
                        'visit_created_at' => $badCreated,
                    ])->allowed,
                    "{$reason}: missing/invalid visit_created_at must fail closed"
                );
            }

            $noExpiryGate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, $reason), null, static fn (): int => $now);
            $this->assertFalse(
                $noExpiryGate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $windowEnd,
                ])->allowed,
                "{$reason} without window cutoff timestamp must fail closed"
            );
        }
    }

    public function testNonExpiryStatusesWithExpiryReasonsStillAllowOpenedVisit(): void
    {
        $now = 1793448000;
        $expiresAt = $now - 45 * 86400; // Past T1 (+1 month).
        $t1 = \ClinicCore\Domain\Licensing\PostExpirationPolicy::deadline($expiresAt, 1);
        $postT1VisitCreated = $now - 60;

        $makeGate = static function (string $status, string $reason, int $expires) use ($now): SignedLicenseGate {
            $provider = new class($status, $reason, $expires) implements LicenseStateProvider {
                public function __construct(
                    private readonly string $status,
                    private readonly string $reason,
                    private readonly int $expiresAt
                ) {
                }

                public function currentState(): array
                {
                    return [
                        'status' => $this->status,
                        'reason' => $this->reason,
                        'expires_at' => $this->expiresAt,
                        'needs_renewal' => true,
                    ];
                }

                public function entitlements(): EntitlementRegistry
                {
                    return new EntitlementRegistry();
                }
            };

            return new SignedLicenseGate($provider, null, static fn (): int => $now);
        };

        $nonExpiryStatuses = [
            LicenseStatus::REVOKED,
            LicenseStatus::SUSPENDED,
            LicenseStatus::INVALID,
            LicenseStatus::THROTTLED,
            LicenseStatus::NOT_CONFIGURED,
            LicenseStatus::ACTIVATION_PENDING,
            LicenseStatus::ACTIVATION_GRACE,
            LicenseStatus::DEVELOPMENT,
        ];
        $spuriousReasons = [
            'cached_state_stale',
            'expired_unreachable',
            'expired',
            'activation_window_expired',
            'migration_grace_expired',
            'binding_mismatch',
        ];
        foreach ($nonExpiryStatuses as $status) {
            foreach ($spuriousReasons as $reason) {
                $decision = $makeGate($status, $reason, $expiresAt)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $postT1VisitCreated,
                ]);
                $this->assertTrue(
                    $decision->allowed,
                    "status={$status} with spurious reason={$reason} must still allow opened visit"
                );
            }
        }

        // UNREACHABLE only applies T1 cutoff for reason='cached_state_stale';
        // RESTRICTED only applies T1 cutoff for 'expired'|'expired_unreachable' (or window cutoff for window reasons).
        foreach ([
            [LicenseStatus::UNREACHABLE, 'no_cached_state'],
            [LicenseStatus::UNREACHABLE, 'expired'],
            [LicenseStatus::UNREACHABLE, 'expired_unreachable'],
            [LicenseStatus::RESTRICTED, 'binding_mismatch'],
        ] as [$status, $reason]) {
            $this->assertTrue(
                $makeGate($status, $reason, $expiresAt)->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $postT1VisitCreated,
                ])->allowed,
                "{$status}/{$reason} must allow opened visit regardless of expires_at"
            );
        }

        // Real expiry status+reason combinations still enforce Stage 3 T1 boundary.
        foreach ([
            [LicenseStatus::RESTRICTED, 'expired'],
            [LicenseStatus::RESTRICTED, 'expired_unreachable'],
            [LicenseStatus::UNREACHABLE, 'cached_state_stale'],
        ] as [$status, $reason]) {
            $gate = $makeGate($status, $reason, $expiresAt);
            $this->assertTrue(
                $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $t1 - 1,
                ])->allowed,
                "{$status}/{$reason}: visit opened before T1 remains writable"
            );
            $this->assertFalse(
                $gate->assert(LicenseGate::OP_HANDWRITING_NEW, [
                    'visit_opened' => true,
                    'visit_created_at' => $t1,
                ])->allowed,
                "{$status}/{$reason}: visit created at/after T1 must be denied"
            );
        }
    }
}

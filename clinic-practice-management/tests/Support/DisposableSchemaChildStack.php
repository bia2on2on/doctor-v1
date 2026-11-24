<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Settings\SettingsFactory;

/**
 * Shared builder for the service stack an EXTERNAL worker (or a forked
 * child) needs on an ISOLATED, DISPOSABLE schema — never on the shared WP
 * test database. The stack is bound to ONE explicit connection; the session
 * is authenticated through the documented reader seam (forked/external
 * workers cannot see WP_Session_Tokens on the harness connection), the
 * license gate is the pure always-allow ActiveLicenseGate (the production
 * SignedLicenseGate singleton is bound to the parent connection and must
 * not be touched), and SMS/runs are bound to the disposable connection.
 *
 * Test-only machinery; production code is never wired through this class.
 */
final class DisposableSchemaChildStack
{
    /**
     * @param array<string, mixed> $session the captured WP session array for $userId
     * @return array{service: BookingService, db: CpmsDb}
     */
    public static function build(\wpdb $conn, int $userId, array $session, string $token): array
    {
        $db = new CpmsDb($conn);
        $op = new OpLogger($db);
        $audit = new AuditLogger($db, $op);
        $settingsFactory = new SettingsFactory($db, $audit);
        $idem = new Idempotency($db);

        $registry = new SmsProviderRegistry();
        $registry->register(new LogSmsProvider($op));
        $registry->register(new GenericApiSmsProvider(static fn (): array => []));
        $sms = new SmsService(
            $db,
            $settingsFactory,
            $registry,
            new CredentialVault(),
            $audit,
            $op,
            new JobQueue($db, $op),
            static fn (): int => 0
        );
        $license = new ActiveLicenseGate();
        $sessions = new PatientSessionScope(
            $db,
            static fn (int $uid, ?string $token) => (null !== $session && $userId === $uid) ? $session : null,
            static fn (int $uid): array => []
        );
        $proofStore = new PatientSessionProofStore(
            static fn (int $uid): object => new class($userId, $token, $session) {
                /** @param array<string, mixed>|null $session */
                public function __construct(
                    private readonly int $expectedUid,
                    private readonly string $expectedToken,
                    private readonly ?array $session
                ) {
                }

                /** @return array<string, mixed>|null */
                public function get(string $token): ?array
                {
                    return (null !== $this->session && '' !== $this->expectedToken && $token === $this->expectedToken)
                        ? $this->session
                        : null;
                }

                /** @param array<string, mixed> $session */
                public function update(string $token, array $session): void
                {
                }

                public function destroy_all(): void
                {
                }
            },
            static fn (): string => $token
        );

        $patientAccess = new PatientAccess(
            $db,
            new PatientRepository($db),
            logins: new PatientLoginRepository($db),
            proofs: $proofStore
        );

        $service = new BookingService(
            $db,
            new SlotRepository($db),
            new AppointmentRepository($db),
            new PatientRepository($db),
            $settingsFactory,
            $license,
            $audit,
            $op,
            $idem,
            $sms,
            null,
            null,
            null,
            $sessions,
            $patientAccess
        );

        return ['service' => $service, 'db' => $db];
    }
}

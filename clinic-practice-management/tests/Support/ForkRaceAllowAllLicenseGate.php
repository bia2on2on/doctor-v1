<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;

/**
 * Test-only LicenseGate stub for the external race workers: every gate
 * check passes. The race tests exercise Clinic/Location scoping and row
 * locking behaviour, never the licensing boundary — mirroring the three
 * file-scoped Phase 6/7 AllowAll gates the forked children used before the
 * move to proc_open workers.
 */
final class ForkRaceAllowAllLicenseGate implements LicenseGate
{
    public function assert(string $operation, array $context = []): LicenseDecision
    {
        return LicenseDecision::allow();
    }

    public function state(): string
    {
        return 'active';
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}

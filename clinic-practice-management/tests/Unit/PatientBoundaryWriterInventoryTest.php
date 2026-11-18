<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * B0-PR2-G11 — the patient-credential boundary inventory.
 *
 * Every file under `src/` that can write a credential, a WordPress role, a
 * Clinic/organization membership, a capability override, a delegation or a
 * clinician↔user link is listed here with a one-line classification. A file that
 * starts calling such a writer without being classified FAILS this test with its
 * name, so a new staff path cannot appear silently.
 *
 * Matching is FILE-level (never line numbers), so moving code cannot break it.
 * This is a completeness guard, not a behaviour test: the behaviour is asserted
 * by the integration suites that call these writers.
 */
final class PatientBoundaryWriterInventoryTest extends TestCase
{
    /**
     * Writer patterns. Keys are stable labels used in the failure message.
     *
     * @var array<string, string>
     */
    private const WRITERS = array(
        'password'       => '/wp_set_password\s*\(/',
        'user-write'     => '/wp_update_user\s*\(|wp_insert_user\s*\(|wp_create_user\s*\(/',
        'reset'          => '/(?<!->)\breset_password\s*\(/',
        'wp-role'        => '/->add_role\s*\(|->set_role\s*\(|->remove_role\s*\(/',
        'membership'     => '/\bcreate_membership\s*\(|reactivate_membership\s*\(|suspend_membership\s*\(|memberships->insert\s*\(|insert\(\s*\'cpms_clinic_memberships\'/',
        'capability'     => '/->set_capability\s*\(|->remove_capability\s*\(|replace_capabilities\s*\(/',
        'scope'          => '/set_scope_mode\s*\(/',
        'delegation'     => '/->delegate\s*\(|->revoke\s*\(/',
        'parked-meta'    => '/update_user_meta\s*\(\s*\$[A-Za-z_]+,\s*self::META_(?:PREV|PARKED)/',
        'clinician-link' => '/clinicians->create\s*\(|\$repo->create\s*\(\s*\$clinic_id/',
    );

    /**
     * file (relative to the plugin root) => classification.
     *
     * GUARDED    — evaluates PatientAccountPolicy before the write (directly or
     *              through a service/controller that does).
     * NOT NEEDED — cannot reach a patient account, with the reason.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = array(
        'src/Admin/ClinicianAdminPage.php'                                  => 'GUARDED: G7 guard on create/update/activation clinician links.',
        'src/Admin/CustomRoleDefinitionsPage.php'                            => 'GUARDED: delegates to CustomRoleManagementService, which requires clinic-manager authority.',
        'src/Admin/FinanceDelegationPage.php'                               => 'GUARDED: delegates to FinanceDelegationService (G5 guard + revocation rules).',
        'src/Admin/StaffManagementPage.php'                                 => 'GUARDED: PatientAccountPolicy::guard()/is_patient_account() on every credential, role and status path.',
        'src/Application/Finance/FinanceDelegationService.php'              => 'GUARDED: G5 delegate()/revoke() patient checks plus strict prior-effect read.',
        'src/Application/Management/ManagementPortalService.php'            => 'GUARDED: patient checks on enroll, role, status, capability, scope and clinician link.',
        'src/Application/Membership/MembershipService.php'                  => 'NOT NEEDED: low-level primitive; every caller is an allowlisted, guarded service.',
        'src/Application/Onboarding/FacilitySetupService.php'               => 'GUARDED: target-account patient check before clinician/staff membership writes.',
        'src/Application/Onboarding/FirstPracticeSetupService.php'          => 'GUARDED: G8 actor-is-target check before both manager-membership writes.',
        'src/Application/Onboarding/OrganizationClinicProvisioningService.php' => 'GUARDED: manager designation, set_manager() GRANT and bootstrap_owner() check the target.',
        'src/Application/Roles/CustomRoleManagementService.php'             => 'NOT NEEDED: changes role DEFINITIONS only; it never writes a membership or an account (G9 residual risk is documented).',
        'src/Infrastructure/Repository/CustomRoleRepository.php'            => 'NOT NEEDED: storage layer for role definitions only.',
        'src/Infrastructure/Repository/MembershipRepository.php'            => 'NOT NEEDED: storage layer; no authorization decision lives here.',
        'src/Infrastructure/UserCacheRollback.php'                          => 'NOT NEEDED: only evicts WordPress caches for an account the caller just rolled back.',
    );

    public function test_every_writer_file_is_classified(): void
    {
        $root    = dirname( __DIR__, 2 ) . '/src';
        $found   = array();
        $skipped = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        /** @var SplFileInfo $file */
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
                continue;
            }

            $relative = 'src/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            $contents = (string) file_get_contents( $file->getPathname() );

            foreach ( self::WRITERS as $label => $pattern ) {
                if ( 1 === preg_match( $pattern, $contents ) ) {
                    $found[ $relative ][] = $label;
                }
            }
        }

        ksort( $found );

        $unclassified = array();
        foreach ( array_keys( $found ) as $relative ) {
            if ( ! array_key_exists( $relative, self::ALLOWLIST ) ) {
                $unclassified[] = $relative . ' [' . implode( ', ', array_unique( $found[ $relative ] ) ) . ']';
            }
        }

        self::assertSame(
            array(),
            $unclassified,
            "A file writes credentials/roles/memberships/capabilities/delegations/links without being classified "
            . "in PatientBoundaryWriterInventoryTest::ALLOWLIST:\n" . implode( "\n", $unclassified )
        );

        // The allowlist never grows stale quietly: every entry must still match.
        foreach ( array_keys( self::ALLOWLIST ) as $relative ) {
            if ( ! array_key_exists( $relative, $found ) ) {
                $skipped[] = $relative;
            }
        }

        self::assertSame(
            array(),
            $skipped,
            "ALLOWLIST entries no longer call any tracked writer (remove them):\n" . implode( "\n", $skipped )
        );
    }
}

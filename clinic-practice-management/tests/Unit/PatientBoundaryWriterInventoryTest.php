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
 *
 * LIMITATION (stated plainly): a NEW writer call added INSIDE an already
 * allowlisted file is NOT detected — the file is classified once, at file level.
 * The guard only catches a writer appearing in a file that is not classified yet.
 * Comments are stripped before matching, so prose about a writer cannot create a
 * false positive (and comment-only mentions are not evidence of a call).
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
        'reset-key'      => '/\bget_password_reset_key\s*\(/',
        'wp-role'        => '/->add_role\s*\(|->set_role\s*\(|->remove_role\s*\(/',
        'wp-cap'         => '/->add_cap\s*\(|->remove_cap\s*\(/',
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
        'src/Auth/RolesAndCapabilities.php'                                 => 'NOT NEEDED at this level: the role/capability registry; every widening decision lives in RoleCapabilitiesPage (H2 guards) and CustomRoleManagementService (G9 guard).',
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
    );

    /**
     * Remove PHP comments so only executable code is matched.
     */
    private static function stripComments( string $source ): string {
        $stripped = preg_replace( '#/\*.*?\*/#s', '', $source );
        $stripped = preg_replace( '#(^|[^:])//[^\n]*#', '$1', (string) $stripped );

        return (string) $stripped;
    }

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
            // H5 — comments are stripped before matching, so prose that mentions a
            // writer function cannot create a false positive.
            $contents = self::stripComments( (string) file_get_contents( $file->getPathname() ) );

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

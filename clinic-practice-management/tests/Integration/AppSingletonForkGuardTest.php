<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * LICENSE-FIX-7 — fork-lift guard for the DB-bound App singletons.
 *
 * A singleton built before pcntl_fork() holds PHP resources whose
 * descriptors are copied into every child and stay unsynchronized there.
 * App guards such singletons with a builder-PID ledger: a matching PID
 * returns the byte-identical instance (the green path, unchanged), a
 * mismatch rebuilds a private instance for the current process.
 *
 * This test simulates the post-fork state in-process by rewriting the
 * ledger PIDs through Reflection — no fork is created. Both the singleton
 * object graph and the ledger are restored in `finally` to the exact
 * pre-test state, so the rest of the Integration suite observes the
 * identical state it would have observed without this test.
 *
 * Safety of the rebuild path inside the harness (audited before adding):
 *  - no writes are issued: all singleton constructors are store-only
 *    (OpLogger, AuditLogger, LicenseService, LicenseRepository,
 *    SettingsFactory, ReturningPatientRepository) and the only query the
 *    rebuild chain issues is a SELECT on cpms_settings via
 *    Settings::load() (Settings.php, load_strict()), plus a SELECT of the
 *    system Clinic in App::scope()'s fallback resolution.
 *  - the WP test harness rolls back through the ORIGINAL $wpdb, which is
 *    a different object from the private CpmsDb built here; the harness
 *    transaction can neither see nor be disturbed by these connections.
 *  - every rebuilt object is discarded by the `finally` restore, so no
 *    new object leaks into later tests (the restore drops the last
 *    references and mysqlnd closes the private connections).
 */
final class AppSingletonForkGuardTest extends WP_UnitTestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: callable}>
     *         list of (slot id, class-static property name, accessor)
     */
    private function guardedSingletons(): array
    {
        return [
            [ 'db', 'db', static fn () => App::db() ],
            [ 'op', 'op', static fn () => App::op() ],
            [ 'audit', 'audit', static fn () => App::audit() ],
            [ 'settings_factory', 'settings_factory', static fn () => App::settingsFactory() ],
            [ 'licenses', 'licenses', static fn () => App::licenseService() ],
            [ 'gateway', 'gateway', static fn () => App::licenseGateway() ],
            [ 'licenseGate', 'license_gate', static fn () => App::licenseGate() ],
        ];
    }

    public function testGuardedSingletonsRebuildForNewPidAndStayIdenticalForSamePid(): void
    {
        App::migrations()->migrate();

        $reflection    = new ReflectionClass( App::class );
        $ledger_prop   = $reflection->getProperty( 'singleton_pids' );
        $slot_props    = [];
        $singletons    = $this->guardedSingletons();
        foreach ( $singletons as [ $slot, $property ] ) {
            $prop               = $reflection->getProperty( $property );
            $prop->setAccessible( true );
            $slot_props[ $slot ] = $prop;
        }
        $ledger_prop->setAccessible( true );

        // Pre-test snapshot: EXACT pre-test state (singletons may be null).
        $pre_singletons = [];
        foreach ( $singletons as [ $slot ] ) {
            $pre_singletons[ $slot ] = $slot_props[ $slot ]->getValue();
        }
        $pre_ledger = $ledger_prop->getValue();

        // Prime every guarded slot (builds and records them if absent).
        foreach ( $singletons as [ , , $accessor ] ) {
            $accessor();
        }

        // Prime-state snapshot used by the behavior assertions.
        $primed_singletons = [];
        foreach ( $singletons as [ $slot ] ) {
            $primed_singletons[ $slot ] = $slot_props[ $slot ]->getValue();
        }

        try {
            // Green path: same PID, the accessor returns the identical instance.
            foreach ( $singletons as [ $slot, , $accessor ] ) {
                self::assertSame(
                    $primed_singletons[ $slot ],
                    $accessor(),
                    'same process must receive the identical singleton'
                );
            }

            // Simulated fork: the ledger now believes another process owns every slot.
            $forced = $ledger_prop->getValue();
            foreach ( $singletons as [ $slot ] ) {
                $forced[ $slot ] = -1;
            }
            $ledger_prop->setValue( null, $forced );

            // After the simulated fork each accessor rebuilds, records this
            // process' PID, and serves one instance for that PID afterwards.
            foreach ( $singletons as [ $slot, , $accessor ] ) {
                $rebuilt = $accessor();
                self::assertNotSame(
                    $primed_singletons[ $slot ],
                    $rebuilt,
                    'a forked process must rebuild the singleton'
                );
                $ledger_now = $ledger_prop->getValue();
                self::assertSame(
                    (int) getmypid(),
                    $ledger_now[ $slot ],
                    'the ledger must record the building process PID'
                );
                self::assertSame(
                    $rebuilt,
                    $accessor(),
                    'same PID must return the identical instance'
                );
            }
        } finally {
            // Hard restore: EXACT pre-test state for both graph and ledger.
            foreach ( $singletons as [ $slot ] ) {
                $slot_props[ $slot ]->setValue( null, $pre_singletons[ $slot ] );
            }
            $ledger_prop->setValue( null, $pre_ledger );
        }

        // Post-condition: the ledger is byte-identical to its pre-test state.
        self::assertSame(
            $pre_ledger,
            $ledger_prop->getValue(),
            'the builder-PID ledger must be restored for later tests'
        );
    }
}

<?php

/**
 * External race worker — witness coverage for the inherited-socket hazard
 * this branch eliminates (TEST-ONLY).
 *
 * Background: every pcntl_fork() child that used to end its execution through
 * PHP's normal shutdown path (plain exit()) destroyed the inherited WordPress
 * mysqli handle on the shared session socket; the server then tore the
 * harness's outer transaction down and leaked committed fixture rows into
 * the shared test database (observed in CI run 44992895013: 20 failing tests,
 * including the Phase 9 portal preconditions that expected exactly one seeded
 * Clinic).
 *
 * The suite no longer forks. Race actors run as external workers launched
 * through tests/Support/ExternalRaceWorker (proc_open, descriptor
 * pinning): the worker never holds a descriptor of the harness process, so
 * its graceful exit runs the ordinary PHP shutdown path — including mysqlnd's
 * COM_QUIT on the WORKER's own session — without any way to touch the
 * parent's session.
 *
 * What these tests pin down:
 *  1. An external worker opens its OWN MySQL session (different
 *     CONNECTION_ID()), reports its own durable roster count, and ends with a
 *     plain exit(0). Its registered shutdown handler DOES run (marker file is
 *     written) and that is safe: the parent's session is the SAME one it held
 *     before the worker ran — same CONNECTION_ID(), same durable Clinic roster,
 *     no driver error.
 *  2. Plain exit(0) DOES run registered PHP shutdown handlers — the exact
 *     path the inherited-socket hazard lived on. The control runs in a FRESH,
 *     socket-free PHP process via exec(). It documents the mechanism without
 *     touching the database at all.
 *
 * What these tests deliberately do NOT claim: they do not re-prove
 * server-side socket teardown (no deterministic way to do that inside one
 * PHPUnit process) and they do not change or depend on license allow/deny
 * outcomes.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\ExternalRaceWorker;
use WP_UnitTestCase;

final class ForkChildLifetimeTest extends WP_UnitTestCase
{
	/**
	 * Inverted witness: the external worker opens its OWN MySQL session and,
	 * after reporting it and registering a shutdown handler, ends with a
	 * plain exit(0). Its shutdown path runs (marker file is written) and the
	 * parent's session is untouched: same CONNECTION_ID(), same durable Clinic
	 * roster, no driver error.
	 */
	public function testExternalWorkerUsesOwnConnectionAndParentSessionSurvivesWorkerShutdown(): void
	{
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open not available.');
		}

		$before = (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []);
		self::assertGreaterThan(0, $before, 'precondition: parent holds a live MySQL session.');
		$clinic_count_before = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
		self::assertGreaterThan(0, $clinic_count_before, 'precondition: at least one seeded Clinic exists.');

		$tag = bin2hex(random_bytes(6));
		$outcome_file = sys_get_temp_dir() . '/cpms-extworker-' . $tag . '-outcome.json';
		$marker_file = sys_get_temp_dir() . '/cpms-extworker-' . $tag . '-marker';
		@unlink($outcome_file);
		@unlink($marker_file);

		$worker = ExternalRaceWorker::launch(
			dirname(__DIR__) . '/bin/fork-race-wp-worker.php',
			[
				'mode' => 'lifetime_probe',
				'outcome_file' => $outcome_file,
				'marker_file' => $marker_file,
			]
		);
		$rc = $worker->waitForExit(15.0);
		$worker->terminate();

		self::assertSame(0, $rc, 'the external worker must end with a plain, graceful exit(0) — never a signalled or timed-out teardown.');

		$raw = @file_get_contents($outcome_file);
		@unlink($outcome_file);
		$decoded = null;
		if (is_string($raw)) {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$decoded = $json;
			}
		}
		self::assertIsArray($decoded, 'the worker must report its outcome before exiting.');
		self::assertArrayHasKey('connection_id', $decoded, 'the worker must hold an independent MySQL session.');
		self::assertGreaterThan(0, (int) $decoded['connection_id'], 'the worker must hold an independent MySQL session.');
		self::assertNotSame($before, (int) $decoded['connection_id'], 'the worker must NOT reuse the parent session.');

		self::assertFileExists($marker_file, 'worker shutdown handlers DO run on a graceful exit — safe here because the worker inherited no parent descriptor, so there is no shared session to tear down.');
		@unlink($marker_file);

		$after = (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []);
		self::assertSame($before, $after, 'the parent must keep the SAME MySQL session (a teardown + transparent reconnect would change CONNECTION_ID()).');
		$clinic_count_after = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
		self::assertSame($clinic_count_before, $clinic_count_after, 'the parent must read the same durable Clinic roster after the worker ran.');
		self::assertSame('', (string) App::db()->wpdb()->last_error, 'the parent must not see a driver-level error after the worker ran.');
	}

	/**
	 * Negative control (the pre-fix behavior): a plain exit(0) DOES run
	 * registered PHP shutdown handlers — exactly the path that destroys the
	 * inherited socket on the way out. This control runs in a FRESH,
	 * socket-free PHP process via exec(); it deliberately does NOT fork the
	 * harness process, because a plain-exit fork child would close the
	 * inherited MySQL session and poison every test that runs after it in
	 * the same PHPUnit process (the parent's $wpdb handle is shared after
	 * pcntl_fork). It documents the mechanism without touching the database
	 * at all.
	 */
	public function testGracefulChildExitRunsShutdownHandlers(): void
	{
		$marker = tempnam( sys_get_temp_dir(), 'cpms-forkchild-graceful-' );
		self::assertNotFalse( $marker, 'a temp marker path is required for the control.' );
		@unlink( $marker );

		$php    = ( defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) ? PHP_BINARY : 'php';
		$script = 'register_shutdown_function( static function () { file_put_contents( '
			. var_export( $marker, true )
			. ', \'shutdown-ran\' ); } ); exit(0);';
		exec( escapeshellarg( $php ) . ' -r ' . escapeshellarg( $script ) . ' 2>&1', $out, $rc );

		self::assertSame( 0, $rc, 'the control process must exit normally.' );
		self::assertFileExists( $marker, 'plain exit() runs PHP shutdown handlers — the teardown path the lifetime guard removes.' );

		@unlink( $marker );
	}
}

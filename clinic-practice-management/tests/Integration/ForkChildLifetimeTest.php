<?php

/**
 * Fork-child lifetime guard — witness coverage for the fork/inherited-socket
 * leak fixed in this branch (TEST-ONLY).
 *
 * Background: every pcntl_fork() child that ends its execution through PHP's
 * normal shutdown path (plain exit()) destroys the inherited WordPress
 * mysqli handle on the shared session socket; the server then tears the
 * harness's outer transaction down and leaks committed fixture rows into
 * the shared test database (observed in CI run 44992895013: 20 failing tests,
 * including the Phase 9 portal preconditions that expected exactly one seeded
 * Clinic).
 *
 * What these tests pin down:
 *  1. A forked child gets its OWN MySQL session (different CONNECTION_ID())
 *     and, after running the real license-gate read path and ending via the
 *     fork-child lifetime guard, the parent's session is the SAME one it
 *     held before the fork — with the same durable Clinic roster and no
 *     driver error. No destructor or shutdown handler runs in the child.
 *  2. The pre-fix behavior is a live hazard, not a theory: a child that
 *     ends with plain exit(0) DOES run its shutdown handlers (this is the
 *     teardown path that destroys the inherited socket).
 *
 * What these tests deliberately do NOT claim: they do not re-prove server-side
 * socket teardown (no deterministic way to do that inside one PHPUnit
 * process) and they do not change or depend on license allow/deny outcomes.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Tests\Support\ForkChildExit;
use WP_UnitTestCase;

final class ForkChildLifetimeTest extends WP_UnitTestCase
{
	/**
	 * Child opens its own MySQL session and, after running the real
	 * license-gate read path and ending via the lifetime guard, the
	 * parent's session is untouched (same CONNECTION_ID(), same durable
	 * Clinic roster, no driver error) and no child shutdown handler ran.
	 */
	public function testForkedChildUsesOwnConnectionAndParentSessionSurvivesChildGateCall(): void
	{
		if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
			self::markTestSkipped('pcntl fork/wait not available.');
		}
		if (!function_exists('posix_kill') || !defined('SIGKILL')) {
			self::markTestSkipped('posix_kill/SIGKILL not available (hard child exit is required).');
		}

		$before = (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []);
		self::assertGreaterThan(0, $before, 'precondition: parent holds a live MySQL session.');
		$clinic_count_before = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
		self::assertGreaterThan(0, $clinic_count_before, 'precondition: at least one seeded Clinic exists.');

		$tag = bin2hex(random_bytes(6));
		$outcome_file = sys_get_temp_dir() . '/cpms-forkchild-' . $tag . '-outcome.json';
		$marker_file = sys_get_temp_dir() . '/cpms-forkchild-' . $tag . '-marker';
		@unlink($outcome_file);
		@unlink($marker_file);

		$pid = pcntl_fork();
		if ($pid === -1) {
			self::fail('pcntl_fork failed.');
		}
		if ($pid === 0) {
			try {
				// Independent MySQL session — never the inherited socket.
				$own = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
				$child_connection_id = 0;
				if (method_exists($own, 'get_var')) {
					$child_connection_id = (int) $own->get_var('SELECT CONNECTION_ID()');
				}
				// Real license-gate read path, executed from the forked child.
				// The allow/deny outcome is irrelevant here; what matters is that
				// the gate executes against the child's memory state and returns.
				$gate_error = null;
				try {
					App::licenseGate()->assert(LicenseGate::OP_SETTINGS_WRITE);
				} catch (\Throwable $gate) {
					$gate_error = get_class($gate);
				}
				register_shutdown_function(static function () use ($marker_file): void {
					@file_put_contents($marker_file, 'shutdown-ran');
				});
				@file_put_contents(
					$outcome_file,
					(string) json_encode(
						[
							'connection_id' => $child_connection_id,
							'gate_error'    => $gate_error,
						]
					)
				);
			} catch (\Throwable $boot) {
				@file_put_contents($outcome_file, (string) json_encode(['boot_error' => get_class($boot)]));
			}
			ForkChildExit::terminate(0);
		}

		pcntl_waitpid($pid, $status);
		self::assertTrue(
			ForkChildExit::childTerminatedCleanly($status),
			'the child must end via the fork-child lifetime guard (SIGKILL), not by running its shutdown handlers.'
		);

		$raw = @file_get_contents($outcome_file);
		@unlink($outcome_file);
		$decoded = null;
		if (is_string($raw)) {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$decoded = $json;
			}
		}
		self::assertIsArray($decoded, 'the child must report its outcome before terminating.');
		self::assertArrayHasKey('connection_id', $decoded, 'the child must hold an independent MySQL session.');
		self::assertGreaterThan(0, (int) $decoded['connection_id'], 'the child must hold an independent MySQL session.');
		self::assertNotSame($before, (int) $decoded['connection_id'], 'the child must NOT reuse the parent session.');
		self::assertFileDoesNotExist($marker_file, 'no child shutdown handler may run (it would tear down the inherited socket).');

		$after = (int) App::db()->fetchValue('SELECT CONNECTION_ID()', []);
		self::assertSame($before, $after, 'the parent must keep the SAME MySQL session (a teardown + transparent reconnect would change CONNECTION_ID()).');
		$clinic_count_after = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
		self::assertSame($clinic_count_before, $clinic_count_after, 'the parent must read the same durable Clinic roster after the child ran.');
		self::assertSame('', (string) App::db()->wpdb()->last_error, 'the parent must not see a driver-level error after the child ran.');

		@unlink($marker_file);
	}

	/**
	 * Negative control (the pre-fix behavior): a child that ends with plain
	 * exit(0) DOES run its shutdown handlers — exactly the path that
	 * destroys the inherited socket on the way out. This test does not
	 * touch the database at all; it only documents the mechanism.
	 */
	public function testGracefulChildExitRunsShutdownHandlers(): void
	{
		if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
			self::markTestSkipped('pcntl fork/wait not available.');
		}

		$tag = bin2hex(random_bytes(6));
		$marker_file = sys_get_temp_dir() . '/cpms-forkchild-' . $tag . '-graceful-marker';
		@unlink($marker_file);

		$pid = pcntl_fork();
		if ($pid === -1) {
			self::fail('pcntl_fork failed.');
		}
		if ($pid === 0) {
			register_shutdown_function(static function () use ($marker_file): void {
				@file_put_contents($marker_file, 'shutdown-ran');
			});
			exit(0);
		}

		pcntl_waitpid($pid, $status);
		self::assertTrue(pcntl_wifexited($status) && 0 === pcntl_wexitstatus($status), 'control child must exit normally.');
		self::assertFileExists($marker_file, 'plain exit() runs the child shutdown handlers — the teardown path the lifetime guard removes.');

		@unlink($marker_file);
	}
}

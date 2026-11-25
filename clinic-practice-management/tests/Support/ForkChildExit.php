<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Fork-child lifetime guard — TEST-ONLY, never production.
 *
 * Why it exists (code evidence, not speculation):
 *  1) A pcntl_fork() child inherits the parent's *live* MySQL socket (the
 *     harness connection `$wpdb`). The custom-role race worker was removed
 *     for exactly this reason (see tests/bin/custom-role-archival-race-worker.php):
 *     on a graceful PHP exit the inherited mysqli object is destroyed, mysqlnd
 *     tears the connection down with COM_QUIT, and the server drops the
 *     session — inside the harness's per-test outer transaction.
 *  2) WP then transparently reconnects on the parent's next query. The
 *     harness savepoint stack survives PHP-side, but the NEW session owns no
 *     transaction at all, so the per-test ROLLBACK becomes a silent no-op
 *     and committed fixture rows leak into the shared test database.
 *     Observed in CI run 44992895013 (Integration job, exit code 2):
 *     20 failing tests, e.g. Phase9Slice1/2/3 preconditions, Phase6
 *     seedCellRow, FacilitySetupConcurrencyTest — all downstream symptoms of
 *     the same inherited-socket teardown.
 *
 * Contract (children of pcntl_fork() in Integration tests):
 *  - A child NEVER closes, queries or frees the inherited socket through
 *    PHP's normal shutdown path. Every child path ends via self::terminate().
 *  - self::terminate() NEVER returns: it SIGKILLs the current process, so
 *    no object destructor, no register_shutdown_function callback and no
 *    isolated-schema cleanup hook ever runs in the child (hard exit). The
 *    parent's socket is not touched; the harness transaction keeps its
 *    owner (the parent) throughout the child's life.
 *  - If posix/SIGKILL is unavailable (or the kill fails), terminate() falls
 *    back to plain exit($code) and SAYS SO on STDERR — the fallback can
 *    still tear down the inherited socket; a CI operator seeing that line
 *    must treat the run's connection hygiene as unknown.
 *  - Exit codes are meaningful ONLY on the posix-less fallback path. The
 *    parent side must use self::childTerminatedCleanly(), which accepts
 *    both "exited 0" and "killed by our lifetime guard (SIGKILL)". Any other
 *    signal (segfault, SIGUSR, …) is NOT accepted.
 *  - This class writes nothing to any database and never touches product
 *    state; it is a process-lifetime primitive only.
 */
final class ForkChildExit
{
    /**
     * End THIS process without running destructors or shutdown handlers.
     * Never returns on the SIGKILL path; exit($code) on the fallback path.
     */
    public static function terminate(int $code = 0): void
    {
        if (function_exists('posix_kill') && defined('SIGKILL')) {
            @posix_kill((int) getmypid(), SIGKILL);
            // Unreachable when the kill landed. A surviving fallback still exits.
            fwrite(STDERR, '[fork-child] SIGKILL failed; exiting via the fallback path (inherited socket may be torn down)' . PHP_EOL);
        } else {
            fwrite(STDERR, '[fork-child] posix_kill/SIGKILL unavailable; exiting via the fallback path (inherited socket may be torn down)' . PHP_EOL);
        }
        exit($code);
    }

    /**
     * Parent-side reap predicate: TRUE only when the child ended via exit(0)
     * or via the hard lifetime kill this class performs (SIGKILL). Any other
     * termination — abnormal exit codes, deadly signals we do not raise
     * ourselves — is FALSE, so genuine crashes are still reported as
     * abnormal by the suite parents.
     */
    public static function childTerminatedCleanly(int $status): bool
    {
        if (function_exists('pcntl_wifexited') && pcntl_wifexited($status)) {
            return pcntl_wexitstatus($status) === 0;
        }

        return function_exists('pcntl_wifsignaled')
            && pcntl_wifsignaled($status)
            && defined('SIGKILL')
            && pcntl_wtermsig($status) === SIGKILL;
    }
}

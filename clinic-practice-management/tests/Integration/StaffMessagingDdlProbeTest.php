<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Rest\MessagingController;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * TEMPORARY diagnostic probes (removed once the Security class is green).
 *
 * CI publishes test NAMES only, so each question about the red test gets its own
 * method name here: a red name names the wrong hypothesis.
 *
 * The question being settled: `StaffMessagingSecurityTest`'s red moved to
 * whichever test first calls `rest_get_server()` + `MessagingController::
 * register_routes()`. The standing theory is that the first `rest_get_server()`
 * fires `rest_api_init` -> `App::ensureMigrated()` -> `migrations()->migrate()`
 * -> DDL -> implicit MySQL COMMIT, which would end the WP test transaction.
 *
 * Static reading already makes that doubtful: `tests/integration-bootstrap.php`
 * migrates once before any test, `MigrationRunner::ensureSchemaTable()` returns
 * early when the ledger table exists, and `migrate()` then performs two SELECTs
 * and no DDL. `StaffMessagingProbeBaselineTest` also calls `rest_get_server()`
 * inside a test and passes. These probes measure it instead of arguing it.
 *
 * Every probe is read-only apart from a throwaway marker option and a raw
 * SAVEPOINT it releases itself; no production behaviour is changed.
 */
final class StaffMessagingDdlProbeTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    /** Verbatim DDL and transaction verbs, in execution order. */
    private array $recorded = [];

    /** Statements raised through `doing_it_wrong_run` while recording. */
    private array $doing_it_wrong = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
        $this->recorded       = [];
        $this->doing_it_wrong = [];
    }

    protected function tearDown(): void
    {
        $this->stop_recording();
        delete_option('cpms_probe_marker');
        $this->iv1_reset();
        parent::tearDown();
    }

    /**
     * Does merely creating/obtaining the REST server run DDL or a COMMIT?
     */
    public function testRestGetServerRunsNoDdlOrCommit(): void
    {
        global $wp_rest_server;

        $previous = $wp_rest_server ?? null;
        $this->start_recording();

        try {
            // Force the first-creation path: this is the call that fires
            // `rest_api_init` (and therefore App's controllers + ensureMigrated).
            $wp_rest_server = null;
            $server         = rest_get_server();
            self::assertInstanceOf(\WP_REST_Server::class, $server, 'precondition: a server is returned');
        } finally {
            $wp_rest_server = $previous;
            $this->stop_recording();
        }

        self::assertSame(
            [],
            array_values(array_filter($this->recorded, fn (string $kind): bool => 'SAVEPOINT' !== $kind
                && ! str_starts_with($kind, 'CREATE TEMPORARY'))),
            'rest_get_server() must not run DDL or COMMIT; recorded: [' . implode(',', $this->recorded) . ']'
        );
    }

    /**
     * Does the messaging controller's own route registration run DDL or COMMIT?
     */
    public function testRegisterRoutesRunsNoDdlOrCommit(): void
    {
        // Register into a THROWAWAY server and restore the global afterwards, so
        // this probe cannot leak messaging routes into the classes that follow
        // (StaffMessagingProbeBaselineTest asserts that none are present).
        global $wp_rest_server;
        $previous       = $wp_rest_server ?? null;
        $wp_rest_server = new \WP_REST_Server();

        $this->start_recording();
        try {
            (new MessagingController(App::staff_messaging_service()))->register_routes();
        } finally {
            $this->stop_recording();
            $wp_rest_server = $previous;
        }

        self::assertSame(
            [],
            array_values(array_filter($this->recorded, fn (string $kind): bool => 'SAVEPOINT' !== $kind
                && ! str_starts_with($kind, 'CREATE TEMPORARY'))),
            'register_routes() must not run DDL or COMMIT; recorded: [' . implode(',', $this->recorded) . ']'
        );
        self::assertSame(
            [],
            $this->doing_it_wrong,
            'register_routes() must not raise _doing_it_wrong; raised: [' . implode(',', $this->doing_it_wrong) . ']'
        );
    }

    /**
     * Does the PROVEN sibling pattern work for THIS controller inside a test
     * body: fresh WP_REST_Server + do_action('rest_api_init') + register +
     * dispatch? `StaffMessagingHiddenReleaseOneTest` already uses exactly this
     * pattern with the same controller and is green, so a red here would refute
     * the fix candidate; a green here validates it before it is applied.
     */
    public function testSiblingFreshServerPatternDispatchesMessagingRoutes(): void
    {
        global $wp_rest_server;
        $previous = $wp_rest_server ?? null;
        $wp_rest_server = new \WP_REST_Server();

        try {
            do_action('rest_api_init', $wp_rest_server);
            (new MessagingController(App::staff_messaging_service()))->register_routes();

            $routes = array_keys($wp_rest_server->get_routes());
            $found  = array_values(array_filter(
                $routes,
                static fn (string $route): bool => str_contains($route, '/staff/portal/messages')
            ));
            self::assertCount(
                4,
                $found,
                'the four messaging routes must register on a fresh server; found: [' . implode(',', $found) . ']'
            );

            $response = $wp_rest_server->dispatch(new \WP_REST_Request('GET', '/clinic/v1/staff/portal/messages/threads'));
            $body     = $response->get_data();
            $code     = is_array($body) ? (string) ($body['code'] ?? '') : '';
            self::assertNotSame(
                'rest_no_route',
                $code,
                'the dispatch must reach the controller, not the not-found fallback; status ' . $response->get_status()
            );
        } finally {
            $wp_rest_server = $previous;
        }
    }

    /**
     * `App::ensureMigrated()` is private and has no public equivalent, so the
     * public path the first `rest_get_server()` reaches — `migrations()->
     * migrate()` with an already-current ledger — is what is measured here.
     */
    public function testEnsureMigratedIsNoopWhenCurrent(): void
    {
        self::assertSame(
            [],
            App::migrations()->migrate(),
            'precondition: the bootstrap already applied every migration'
        );

        $this->start_recording();
        try {
            $applied = App::migrations()->migrate();
        } finally {
            $this->stop_recording();
        }

        self::assertSame([], $applied, 'migrate() must be a no-op on a current ledger');
        self::assertSame(
            [],
            $this->recorded,
            'a no-op migrate() must run no DDL, COMMIT or transaction verb; recorded: ['
                . implode(',', $this->recorded) . ']'
        );
    }

    /**
     * Is the WP test transaction still open after `rest_get_server()`?
     */
    public function testTransactionSurvivesRestGetServer(): void
    {
        global $wpdb;

        add_option('cpms_probe_marker', 'probe');
        self::assertNotFalse(
            $wpdb->query('SAVEPOINT cpms_probe_sp'),
            'precondition: the test transaction accepts a SAVEPOINT: ' . $wpdb->last_error
        );

        global $wp_rest_server;
        $previous = $wp_rest_server ?? null;

        try {
            $wp_rest_server = null;
            rest_get_server();
        } finally {
            $wp_rest_server = $previous;
        }

        // An implicit COMMIT from real DDL would destroy this savepoint.
        self::assertNotFalse(
            $wpdb->query('ROLLBACK TO SAVEPOINT cpms_probe_sp'),
            'the test transaction must still be open after rest_get_server(): ' . $wpdb->last_error
        );
        $wpdb->query('RELEASE SAVEPOINT cpms_probe_sp');

        self::assertSame(
            'probe',
            (string) get_option('cpms_probe_marker'),
            'the marker written inside the transaction must still be visible'
        );
    }

    private function start_recording(): void
    {
        $this->recorded       = [];
        $this->doing_it_wrong = [];

        // Priority 1 sees the statement BEFORE the bootstrap's savepoint
        // rewriter (priority 20) turns cpms-marked verbs into SAVEPOINTs.
        add_filter('query', [$this, 'record_query'], 1);
        add_action('doing_it_wrong_run', [$this, 'record_doing_it_wrong'], 1);
    }

    private function stop_recording(): void
    {
        remove_filter('query', [$this, 'record_query'], 1);
        remove_action('doing_it_wrong_run', [$this, 'record_doing_it_wrong'], 1);
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function record_query($query)
    {
        $sql = trim((string) $query);
        $sql = trim((string) preg_replace('/\A(?:\s|\/\*.*?\*\/)*/s', '', $sql));

        if (preg_match('/\A(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+(TEMPORARY\s+)?/i', $sql, $m) === 1) {
            $this->recorded[] = strtoupper($m[1]) . (isset($m[2]) && '' !== trim($m[2]) ? ' TEMPORARY' : '');
        } elseif (preg_match('/\A(COMMIT|ROLLBACK|START TRANSACTION|SAVEPOINT|RELEASE)\b/i', $sql, $m) === 1) {
            $this->recorded[] = strtoupper($m[1]);
        }

        return $query;
    }

    /**
     * @param mixed $function_name
     */
    public function record_doing_it_wrong($function_name): void
    {
        $this->doing_it_wrong[] = (string) $function_name;
    }
}

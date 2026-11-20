<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Rest\PatientController;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use PHPUnit\Framework\AssertionFailedError;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Route-completeness proof for PatientController:
 *  - enumerates PatientController's registered patient-facing routes on WP_REST_Server;
 *  - proves every enumerated route+method executes the central PatientAccess gate
 *    and denies unlinked/cross-clinic callers before any handler runs;
 *  - proves non-vacuity by asserting the exact completeness verifier fails against
 *    a deliberately ungated fixture route.
 */
final class PatientAccessControllerCompletenessTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const EXPECTED_PATIENT_ENDPOINTS = [
        'POST /clinic/v1/patient/enroll',
        'GET /clinic/v1/patient/my-records',
        'GET /clinic/v1/patient/me',
        'POST /clinic/v1/patient/me',
        'PUT /clinic/v1/patient/me',
        'PATCH /clinic/v1/patient/me',
    ];

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
    private int $linkA;
    private int $patientUserLinked;
    private int $patientUserZero;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->orgId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ 1 ]
        );
        $this->clinicA = 1;
        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'Completeness Clinic B',
                'slug'            => 'completeness-clinic-b-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB = $db->wpdb_last_insert_id();

        $db->insert(
            'cpms_patients',
            [
                'clinic_id'  => $this->clinicA,
                'mrn'        => 'MR-COMP-' . uniqid(),
                'first_name' => 'Comp',
                'last_name'  => 'Patient',
                'mobile'     => '09120030001',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $patientA = $db->wpdb_last_insert_id();

        $this->patientUserLinked = $this->makePatientUser( 'comp_linked_' . uniqid() );
        $this->patientUserZero   = $this->makePatientUser( 'comp_zero_' . uniqid() );

        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicA,
                'patient_id'     => $patientA,
                'wp_user_id'     => $this->patientUserLinked,
                'mobile_at_link' => '09120030001',
                'is_primary'     => 1,
                'linked_at'      => $now,
            ]
        );
        $this->linkA = $db->wpdb_last_insert_id();

        $identity = App::patient_identity_service()->create_identity( $this->orgId, '09120030001' );
        App::patient_identity_service()->link_user( $this->orgId, $identity, $this->patientUserLinked, '09120030001' );

        $this->installSignedExpiration( time() + 30 * 86400 );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        remove_all_filters( 'cpms_license_public_key' );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function testLiveRestServerEnumeratesExactPatientControllerRoutes(): void {
        $enumerated = $this->enumeratePatientRouteMethods( rest_get_server()->get_routes() );
        self::assertGreaterThan( 0, count( $enumerated ) );

        $expected = self::EXPECTED_PATIENT_ENDPOINTS;
        sort( $expected );
        sort( $enumerated );
        self::assertSame( $expected, $enumerated );
    }

    public function testEveryEnumeratedPatientControllerRoutePassesThroughPatientAccessGate(): void {
        $gateEvents = [];
        $gate       = new PatientAccess(
            App::db(),
            new PatientRepository( App::db() ),
            decision_observer: static function ( array $event ) use ( &$gateEvents ): void {
                $gateEvents[] = $event;
            }
        );

        $this->withIsolatedControllerServer(
            $gate,
            function ( WP_REST_Server $server ) use ( &$gateEvents ): void {
                $this->assertAllPatientRoutesPassGate( $server, $gateEvents );
            }
        );
    }

    public function testCompletenessVerifierFailsAgainstDeliberatelyUngatedFixtureRoute(): void {
        $gateEvents = [];
        $gate       = new PatientAccess(
            App::db(),
            new PatientRepository( App::db() ),
            decision_observer: static function ( array $event ) use ( &$gateEvents ): void {
                $gateEvents[] = $event;
            }
        );

        $this->withIsolatedControllerServer(
            $gate,
            function ( WP_REST_Server $server ) use ( &$gateEvents ): void {
                // Inject a deliberately ungated fixture route under /clinic/v1/patient/.
                $handlerRan = false;
                register_rest_route(
                    'clinic/v1',
                    '/patient/ungated-fixture',
                    [
                        [
                            'methods'             => WP_REST_Server::READABLE,
                            'callback'            => static function () use ( &$handlerRan ): array {
                                $handlerRan = true;

                                return [ 'ok' => true ];
                            },
                            'permission_callback' => '__return_true',
                        ],
                    ]
                );

                $failed = false;
                try {
                    $this->assertAllPatientRoutesPassGate( $server, $gateEvents );
                } catch ( AssertionFailedError $e ) {
                    $failed = true;
                }

                // Asserted outside callbacks: the verifier MUST fail and the ungated handler ran.
                self::assertTrue( $failed, 'Completeness verifier must fail when any patient route bypasses PatientAccess.' );
                self::assertTrue( $handlerRan, 'Deliberately ungated fixture route should have reached its handler without hitting the gate.' );
            }
        );
    }

    /**
     * @param callable(WP_REST_Server): void $fn
     */
    private function withIsolatedControllerServer( PatientAccess $gate, callable $fn ): void {
        global $wp_rest_server;
        $previous       = $wp_rest_server;
        $isolated       = new WP_REST_Server();
        $wp_rest_server = $isolated;

        try {
            ( new PatientController( App::patientService(), $gate ) )->register_routes();
            $fn( $isolated );
        } finally {
            $wp_rest_server = $previous;
        }
    }

    /**
     * Enumerates every registered patient-facing route+method on `$server`
     * and proves each one executes `PatientAccess` (recorded inside `$gateEvents`
     * by the observer callback, asserted here outside the callback) and denies
     * an unauthorized/unlinked request before dispatching the handler.
     *
     * @param list<array<string, mixed>> $gateEvents
     */
    private function assertAllPatientRoutesPassGate( WP_REST_Server $server, array &$gateEvents ): void {
        $routes     = $server->get_routes();
        $enumerated = $this->enumeratePatientRouteMethods( $routes );
        self::assertGreaterThan( 0, count( $enumerated ) );

        $expected = self::EXPECTED_PATIENT_ENDPOINTS;
        sort( $expected );
        $actual = $enumerated;
        sort( $actual );
        self::assertSame( $expected, $actual, 'Registered patient routes drifted from expected set.' );

        foreach ( $routes as $route => $endpoints ) {
            if ( ! str_starts_with( $route, '/clinic/v1/patient/' ) || ! is_array( $endpoints ) ) {
                continue;
            }
            foreach ( $endpoints as $endpoint ) {
                if ( ! is_array( $endpoint ) ) {
                    continue;
                }
                $methods = array_keys( array_filter( (array) ( $endpoint['methods'] ?? [] ) ) );
                foreach ( $methods as $method ) {
                    // 1) Denied request (unlinked/wrong clinic) MUST hit PatientAccess and fail closed.
                    $beforeDeny = count( $gateEvents );
                    wp_set_current_user( $this->patientUserZero );
                    $denyReq = new WP_REST_Request( $method, $route );
                    $denyReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
                    $denyReq->set_param( 'clinic_id', $this->clinicB );
                    if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) && '/clinic/v1/patient/me' === $route ) {
                        $denyReq->set_param( 'first_name', 'ShouldNotWrite' );
                    }
                    if ( '/clinic/v1/patient/enroll' === $route ) {
                        $denyReq->set_param( 'first_name', 'No' );
                        $denyReq->set_param( 'last_name', 'Identity' );
                        $denyReq->set_param( 'mobile', '09129998877' );
                    }

                    $denyRes = $server->dispatch( $denyReq );
                    self::assertGreaterThan(
                        $beforeDeny,
                        count( $gateEvents ),
                        $method . ' ' . $route . ' bypassed PatientAccess on denial.'
                    );
                    $lastDenyEvent = $gateEvents[ count( $gateEvents ) - 1 ];
                    self::assertFalse( $lastDenyEvent['allowed'] );
                    self::assertSame( 404, $denyRes->get_status(), $method . ' ' . $route . ' must fail closed for unlinked/wrong-clinic user.' );

                    // 2) Positive control (authorized linked/eligible user) MUST also pass through PatientAccess.
                    $beforeAllow = count( $gateEvents );
                    wp_set_current_user( $this->patientUserLinked );
                    $allowReq = new WP_REST_Request( $method, $route );
                    $allowReq->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
                    if ( '/clinic/v1/patient/enroll' === $route ) {
                        $allowReq->set_param( 'clinic_id', $this->clinicB );
                        $allowReq->set_param( 'first_name', 'Enrolled' );
                        $allowReq->set_param( 'last_name', 'Patient' );
                        $allowReq->set_param( 'mobile', '09120039999' );
                    } else {
                        $allowReq->set_param( 'clinic_id', $this->clinicA );
                        if ( '/clinic/v1/patient/me' === $route ) {
                            $allowReq->set_param( 'link_id', $this->linkA );
                        }
                    }
                    if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) && '/clinic/v1/patient/me' === $route ) {
                        $allowReq->set_param( 'first_name', 'AllowedEdit' );
                    }

                    $allowRes = $server->dispatch( $allowReq );
                    self::assertGreaterThan(
                        $beforeAllow,
                        count( $gateEvents ),
                        $method . ' ' . $route . ' bypassed PatientAccess on positive control.'
                    );
                    $lastAllowEvent = $gateEvents[ count( $gateEvents ) - 1 ];
                    self::assertTrue( $lastAllowEvent['allowed'] );
                    self::assertSame( 200, $allowRes->get_status(), $method . ' ' . $route . ' positive control failed.' );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $routes
     * @return list<string>
     */
    private function enumeratePatientRouteMethods( array $routes ): array {
        $found = [];
        foreach ( $routes as $route => $endpoints ) {
            if ( ! str_starts_with( $route, '/clinic/v1/patient/' ) || ! is_array( $endpoints ) ) {
                continue;
            }
            foreach ( $endpoints as $endpoint ) {
                if ( ! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) ) {
                    continue;
                }
                foreach ( array_keys( array_filter( (array) $endpoint['methods'] ) ) as $method ) {
                    $found[] = $method . ' ' . $route;
                }
            }
        }

        return $found;
    }

    private function makePatientUser( string $login ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( RolesAndCapabilities::ROLE_PATIENT );

        return $userId;
    }
}

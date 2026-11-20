<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Rest\BookingController;
use ClinicCore\Rest\ClinicalController;
use ClinicCore\Rest\FilesController;
use ClinicCore\Rest\NotificationsController;
use ClinicCore\Rest\PatientController;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use PHPUnit\Framework\AssertionFailedError;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Full REST completeness proof for PR13-SLICE-B:
 *  - verifies against the live booted WP_REST_Server that NO non-allowlisted
 *    patient-reachable route exists outside the 22 gated (METHOD, route) pairs;
 *  - registers all 5 patient-reachable controllers inside a fresh WP_REST_Server
 *    via `do_action( 'rest_api_init', $server )` (restored in tearDown) and
 *    proves all 22 pairs invoke `PatientAccess` and deny before handler dispatch;
 *  - proves non-vacuity via a synthetic ungated patient-route witness.
 */
final class PatientAccessSliceBCompletenessTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    /** @var list<string> */
    private const ALLOWLISTED_PUBLIC_ENDPOINTS = [
        'GET /clinic/v1/health',
        'GET /clinic/v1/availability',
        'POST /clinic/v1/booking/quote',
        'POST /clinic/v1/otp/request',
        'POST /clinic/v1/otp/verify',
    ];

    /** @var list<string> */
    private const GATED_PATIENT_ENDPOINTS = [
        // Slice A (4 definitions -> 6 expanded pairs).
        'POST /clinic/v1/patient/enroll',
        'GET /clinic/v1/patient/my-records',
        'GET /clinic/v1/patient/me',
        'POST /clinic/v1/patient/me',
        'PUT /clinic/v1/patient/me',
        'PATCH /clinic/v1/patient/me',
        // Slice B — BookingController (7 definitions -> 7 pairs).
        'POST /clinic/v1/booking/hold',
        'POST /clinic/v1/booking/confirm',
        'GET /clinic/v1/booking/resume',
        'GET /clinic/v1/appointments/mine',
        'GET /clinic/v1/appointments/(?P<id>\\d+)/reschedule-options',
        'POST /clinic/v1/appointments/(?P<id>\\d+)/reschedule',
        'POST /clinic/v1/appointments/(?P<id>\\d+)/cancel',
        // Slice B — ClinicalController (3 definitions -> 3 pairs).
        'GET /clinic/v1/visits',
        'GET /clinic/v1/visits/(?P<id>\\d+)',
        'GET /clinic/v1/prescriptions',
        // Slice B — FilesController (3 definitions -> 3 pairs).
        'GET /clinic/v1/files/(?P<id>\\d+)/stream',
        'POST /clinic/v1/patients/(?P<patient_id>\\d+)/files',
        'GET /clinic/v1/patients/(?P<patient_id>\\d+)/files',
        // Slice B — NotificationsController (3 definitions -> 3 pairs).
        'GET /clinic/v1/notifications',
        'POST /clinic/v1/notifications/read',
        'GET /clinic/v1/rt/notifications',
    ];

    private int $patientA;
    private int $patientUserLinked;
    private int $multiRoleUser;

    /** @var mixed */
    private $savedRestServer = null;

    /** @var mixed */
    private $savedRestInitHooks = null;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        global $wp_rest_server, $wp_filter;
        rest_get_server();
        $this->savedRestServer    = $wp_rest_server;
        $this->savedRestInitHooks = $wp_filter['rest_api_init'] ?? null;

        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patients',
            [
                'clinic_id'  => 1,
                'mrn'        => 'MR-SBC-' . uniqid(),
                'first_name' => 'SliceB',
                'last_name'  => 'Complete',
                'mobile'     => '09123334455',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->patientA = $db->wpdb_last_insert_id();

        $this->patientUserLinked = $this->makeUser( 'sbc_pat_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => 1,
                'patient_id'     => $this->patientA,
                'wp_user_id'     => $this->patientUserLinked,
                'mobile_at_link' => '09123334455',
                'is_primary'     => 1,
                'linked_at'      => $now,
            ]
        );

        $this->multiRoleUser = $this->makeUser( 'sbc_multi_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $multiObj            = get_userdata( $this->multiRoleUser );
        self::assertNotFalse( $multiObj );
        $multiObj->add_role( RolesAndCapabilities::ROLE_MANAGER );

        $this->installSignedExpiration( time() + 30 * 86400 );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        global $wp_rest_server, $wp_filter;
        if ( null !== $this->savedRestServer ) {
            $wp_rest_server = $this->savedRestServer;
        }
        if ( null !== $this->savedRestInitHooks ) {
            $wp_filter['rest_api_init'] = $this->savedRestInitHooks;
        }
        remove_all_filters( 'cpms_license_public_key' );
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    public function testLiveRestServerHasNoUngatedNonAllowlistedPatientReachableRoute(): void {
        $routes        = rest_get_server()->get_routes();
        $enumerated    = $this->enumerateRouteMethods( $routes );
        $knownPatient  = array_flip( array_merge( self::ALLOWLISTED_PUBLIC_ENDPOINTS, self::GATED_PATIENT_ENDPOINTS ) );
        $nonPublic22   = array_flip( self::GATED_PATIENT_ENDPOINTS );

        // Verify all 22 gated + 5 public endpoints exist on the live server.
        foreach ( array_merge( self::ALLOWLISTED_PUBLIC_ENDPOINTS, self::GATED_PATIENT_ENDPOINTS ) as $expected ) {
            self::assertContains( $expected, $enumerated, 'Expected route missing from live WP_REST_Server: ' . $expected );
        }

        // Every other /clinic/v1/* route+method must deny a pure ROLE_PATIENT caller (403).
        wp_set_current_user( $this->patientUserLinked );
        foreach ( $enumerated as $pair ) {
            if ( isset( $knownPatient[ $pair ] ) ) {
                continue;
            }
            [$method, $pattern] = explode( ' ', $pair, 2 );
            $concreteRoute      = $this->instantiateRoutePattern( $pattern );
            $request            = new WP_REST_Request( $method, $concreteRoute );
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $response = rest_do_request( $request );
            self::assertSame(
                403,
                $response->get_status(),
                'Non-allowlisted route ' . $pair . ' must not be reachable by ROLE_PATIENT (got HTTP ' . $response->get_status() . ').'
            );
        }
        self::assertCount( 22, $nonPublic22 );
    }

    public function testAll22PatientReachableRoutesPassThroughPatientAccessGate(): void {
        $gateEvents = [];
        $gate       = new PatientAccess(
            App::db(),
            new PatientRepository( App::db() ),
            decision_observer: static function ( array $event ) use ( &$gateEvents ): void {
                $gateEvents[] = $event;
            }
        );

        $this->withIsolatedServer(
            static function () use ( $gate ): void {
                ( new PatientController( App::patientService(), $gate ) )->register_routes();
                ( new BookingController( App::bookingService(), App::patient_session_scope(), $gate ) )->register_routes();
                ( new ClinicalController( App::clinicalService(), $gate ) )->register_routes();
                ( new FilesController( App::medicalFileService(), $gate ) )->register_routes();
                ( new NotificationsController( App::notificationService(), $gate ) )->register_routes();
            },
            function ( WP_REST_Server $server ) use ( &$gateEvents ): void {
                $this->assertAllGatedRoutesExecuteGate( $server, $gateEvents );
            }
        );
    }

    public function testCompletenessVerifierFailsOnSyntheticUngatedPatientRoute(): void {
        $gateEvents = [];
        $gate       = new PatientAccess(
            App::db(),
            new PatientRepository( App::db() ),
            decision_observer: static function ( array $event ) use ( &$gateEvents ): void {
                $gateEvents[] = $event;
            }
        );

        $handlerRan = false;
        $this->withIsolatedServer(
            static function () use ( &$handlerRan ): void {
                register_rest_route(
                    'clinic/v1',
                    '/visits',
                    [
                        [
                            'methods'             => WP_REST_Server::READABLE,
                            'callback'            => static function () use ( &$handlerRan ): array {
                                $handlerRan = true;

                                return [ 'visits' => [] ];
                            },
                            'permission_callback' => '__return_true',
                        ],
                    ]
                );
            },
            function ( WP_REST_Server $server ) use ( &$gateEvents ): void {
                $failed = false;
                try {
                    $this->assertSingleEndpointListExecutesGate( $server, [ 'GET /clinic/v1/visits' ], $gateEvents );
                } catch ( AssertionFailedError ) {
                    $failed = true;
                }

                // Asserted outside callbacks: the verifier MUST fail on an ungated route.
                self::assertTrue( $failed, 'Completeness check must fail when an ungated patient route exists.' );
            }
        );

        self::assertTrue( $handlerRan, 'Synthetic ungated witness handler ran without PatientAccess.' );
        self::assertSame( [], $gateEvents );
        unset( $gate );
    }

    /**
     * @param callable(): void               $register
     * @param callable(WP_REST_Server): void $fn
     */
    private function withIsolatedServer( callable $register, callable $fn ): void {
        rest_get_server();

        global $wp_rest_server, $wp_filter;
        $previousServer = $wp_rest_server;
        $previousHooks  = $wp_filter['rest_api_init'] ?? null;
        $isolated       = new WP_REST_Server();
        $wp_rest_server = $isolated;
        unset( $wp_filter['rest_api_init'] );

        $hook = static function () use ( $register ): void {
            $register();
        };
        add_action( 'rest_api_init', $hook );

        try {
            do_action( 'rest_api_init', $isolated );
            $fn( $isolated );
        } finally {
            remove_action( 'rest_api_init', $hook );
            if ( null !== $previousHooks ) {
                $wp_filter['rest_api_init'] = $previousHooks;
            } else {
                unset( $wp_filter['rest_api_init'] );
            }
            $wp_rest_server = $previousServer;
        }
    }

    /**
     * @param list<array<string, mixed>> $gateEvents
     */
    private function assertAllGatedRoutesExecuteGate( WP_REST_Server $server, array &$gateEvents ): void {
        $enumerated = $this->enumerateRouteMethods( $server->get_routes() );
        foreach ( self::GATED_PATIENT_ENDPOINTS as $expected ) {
            self::assertContains( $expected, $enumerated, 'Missing gated endpoint on isolated server: ' . $expected );
        }

        $this->assertSingleEndpointListExecutesGate( $server, self::GATED_PATIENT_ENDPOINTS, $gateEvents );
    }

    /**
     * @param list<string>               $pairs
     * @param list<array<string, mixed>> $gateEvents
     */
    private function assertSingleEndpointListExecutesGate( WP_REST_Server $server, array $pairs, array &$gateEvents ): void {
        foreach ( $pairs as $pair ) {
            [$method, $pattern] = explode( ' ', $pair, 2 );
            $concreteRoute      = $this->instantiateRoutePattern( $pattern );

            $before = count( $gateEvents );
            wp_set_current_user( $this->multiRoleUser );
            $request = new WP_REST_Request( $method, $concreteRoute );
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $this->populateSampleParams( $request, $pattern );

            $response = $server->dispatch( $request );
            self::assertSame( $before + 1, count( $gateEvents ), 'Route did not execute PatientAccess gate: ' . $pair );
            self::assertSame( 403, $response->get_status(), 'Multi-role user must be denied at gate before handler on ' . $pair );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $response->get_data()['code'] ?? null, $pair );
            self::assertFalse( (bool) $gateEvents[ $before ]['allowed'], $pair );
        }
    }

    private function instantiateRoutePattern( string $pattern ): string {
        $route = preg_replace( '#\(\?P<patient_id>[^)]+\)#', (string) $this->patientA, $pattern );
        $route = preg_replace_callback(
            '#\(\?P<[^>]+>([^)]+)\)#',
            static function ( array $matches ): string {
                if ( str_contains( $matches[1], '|' ) && ! str_contains( $matches[1], '\\d' ) ) {
                    return explode( '|', $matches[1] )[0];
                }

                return '1';
            },
            (string) $route
        );

        return (string) $route;
    }

    private function populateSampleParams( WP_REST_Request $request, string $pattern ): void {
        if ( str_contains( $pattern, '/patient/enroll' ) ) {
            $request->set_param( 'clinic_id', 1 );
            $request->set_param( 'first_name', 'S' );
            $request->set_param( 'last_name', 'B' );
            $request->set_param( 'mobile', '09123334455' );
        } elseif ( str_contains( $pattern, '/booking/hold' ) ) {
            $request->set_param( 'clinician_id', 1 );
            $request->set_param( 'slot_date', gmdate( 'Y-m-d', time() + 3 * 86400 ) );
            $request->set_param( 'slot_time', '10:00:00' );
        } elseif ( str_contains( $pattern, '/booking/confirm' ) || str_contains( $pattern, '/booking/resume' ) ) {
            $request->set_param( 'hold_token', 'tok-witness' );
        } elseif ( str_contains( $pattern, '/reschedule' ) && ! str_contains( $pattern, 'reschedule-options' ) ) {
            $request->set_param( 'slot_date', gmdate( 'Y-m-d', time() + 4 * 86400 ) );
            $request->set_param( 'slot_time', '11:00:00' );
        }
    }

    /**
     * @param array<string, mixed> $routes
     * @return list<string>
     */
    private function enumerateRouteMethods( array $routes ): array {
        $found = [];
        foreach ( $routes as $route => $endpoints ) {
            if ( ! str_starts_with( $route, '/clinic/v1/' ) || ! is_array( $endpoints ) ) {
                continue;
            }
            foreach ( $endpoints as $endpoint ) {
                if ( ! is_array( $endpoint ) ) {
                    continue;
                }
                $methods = array_keys( array_filter( (array) ( $endpoint['methods'] ?? [] ) ) );
                foreach ( $methods as $method ) {
                    $found[] = strtoupper( (string) $method ) . ' ' . $route;
                }
            }
        }

        return array_values( array_unique( $found ) );
    }

    private function makeUser( string $login, string $role ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $userId;
    }
}

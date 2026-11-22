<?php

/**
 * Package P3 — defect 6 follow-up: a Clinic whose clinicians have NO durable
 * Location assignment must stay usable on the public booking surface.
 *
 * The Location-first contract publishes, server-side, only the Locations that
 * already have at least one eligible clinician. When a Clinic has no such
 * Location at all, publishing the Clinic with an EMPTY list is not "fail
 * closed" for a visitor: the frontend then has a Location step that offers
 * nothing and disables every doctor, so the page cannot be booked at all. That
 * data state is expected to exist — the product itself ships a recovery screen
 * for clinicians that have no ``cpms_clinician_locations`` row — and the
 * pre-change surface simply let the visitor pick a doctor and derived the
 * Location from the availability answer.
 *
 * This suite pins the behaviour of that state:
 *
 *  A. A Clinic with zero eligible Locations is NOT published in
 *     ``locations_by_clinic``; the frontend keeps the pre-change flow.
 *  B. A durable assignment publishes exactly that one Location (positive
 *     control — the fix must not become a blanket "never publish").
 *  C. The eligibility rule itself does not change: an unassigned doctor still
 *     advertises no Location, and the doctor list is still server-rendered.
 *
 * The suite is READ-ONLY: it creates fixtures, renders the shortcode and reads
 * the published contract. Nothing is booked, mutated or deleted. No assertion
 * depends on wall-clock values.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use WP_UnitTestCase;

final class PkgP3UnassignedClinicianLegacyTest extends WP_UnitTestCase
{
    private const SHORTCODE = 'cpms_public_booking';
    private const TZ_TEHRAN = 'Asia/Tehran';

    private string $uid = '';

    private int $org = 0;

    private int $clinic_id = 0;

    private int $location_id = 0;

    private int $clinician_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user( 0 );

        $this->uid = uniqid( 'p3ul', false );
        $this->build_fixture();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        PatientTwoProofTestHelper::reset();

        parent::tearDown();
    }

    // =====================================================================
    // A — no eligible Location must not be published as an empty contract
    // =====================================================================

    public function test_unlisted_fixture_has_no_public_booking_markup(): void {
        $visible = $this->render( (string) $this->clinic_id );
        self::assertStringContainsString( 'Clinic P3UL', $visible );
        self::assertStringContainsString( 'data-clinic-id="' . $this->clinic_id . '"', $visible );
        self::assertStringContainsString( 'data-clinician-id="' . $this->clinician_id . '"', $visible );

        App::settingsFactory()->forClinic( $this->clinic_id )->set( 'public.listing_opt_in', false );
        $hidden  = $this->render( (string) $this->clinic_id );
        $unknown = $this->render( (string) PHP_INT_MAX );
        $normalize = static fn ( string $html ): string => (string) preg_replace( '/id="cpms-public-booking-[0-9]+"/', 'id="fixture"', $html );
        self::assertSame( $normalize( $unknown ), $normalize( $hidden ) );
        self::assertStringContainsString( 'data-state="error"', $hidden );
        foreach ( [ 'Clinic P3UL', 'data-clinic-id=', 'data-clinician-id=', 'data-location-id=', 'locations_by_clinic' ] as $witness ) {
            self::assertStringNotContainsString( $witness, $hidden );
        }
    }

    public function test_clinic_with_no_eligible_location_publishes_no_contract_entry(): void
    {
        $config    = $this->config( $this->render( (string) $this->clinic_id ) );
        $by_clinic = is_array( $config['locations_by_clinic'] ?? null ) ? $config['locations_by_clinic'] : [];

        self::assertArrayNotHasKey(
            (string) $this->clinic_id,
            $by_clinic,
            'A Clinic with zero eligible Locations must not be published as an EMPTY Location '
            . 'list: the frontend would render a Location step with no option and disable every '
            . 'doctor, so the public surface could not be booked at all. That data state has no '
            . 'Location to choose, therefore the pre-change flow stays in force.'
        );
    }

    public function test_unassigned_doctor_advertises_no_location(): void
    {
        $html = $this->render( (string) $this->clinic_id );

        self::assertSame(
            [],
            $this->locations_of( $html, $this->clinician_id ),
            'An unassigned doctor is eligible nowhere; the item must not advertise a Location.'
        );
    }

    public function test_surface_still_server_renders_the_doctor_list(): void
    {
        $html = $this->render( (string) $this->clinic_id );

        self::assertStringContainsString(
            'data-clinician-id="' . $this->clinician_id . '"',
            $html,
            'The pre-change flow needs the server-rendered doctor list; it is not filtered by '
            . 'Location on the server.'
        );
    }

    // =====================================================================
    // B — positive control: one durable assignment publishes one Location
    // =====================================================================

    public function test_a_durable_assignment_publishes_exactly_that_location(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->insert_clinician_location( $this->clinician_id, $this->location_id, 1, $now );

        $config    = $this->config( $this->render( (string) $this->clinic_id ) );
        $by_clinic = is_array( $config['locations_by_clinic'] ?? null ) ? $config['locations_by_clinic'] : [];

        self::assertArrayHasKey(
            (string) $this->clinic_id,
            $by_clinic,
            'With a durable assignment the Clinic MUST carry its eligible Location.'
        );

        $published = array_map(
            static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ),
            (array) $by_clinic[ (string) $this->clinic_id ]
        );

        self::assertSame(
            [ $this->location_id ],
            $published,
            'Exactly the assigned ACTIVE Location of THIS Clinic is published.'
        );
        self::assertSame(
            [ $this->location_id ],
            $this->locations_of( $this->render( (string) $this->clinic_id ), $this->clinician_id ),
            'The doctor item now carries the Location where it is eligible.'
        );
    }

    public function test_legacy_bound_booking_holds_and_confirms_only_with_a_matching_linked_session(): void {
        $now     = App::db()->nowUtcSql();
        $date    = ( new \DateTimeImmutable( '+5 days', new \DateTimeZone( self::TZ_TEHRAN ) ) )->format( 'Y-m-d' );
        $slot_id = $this->insert_row(
            'cpms_schedule_slots',
            [
                'clinic_id'    => $this->clinic_id,
                'location_id'  => $this->location_id,
                'clinician_id' => $this->clinician_id,
                'slot_date'    => $date,
                'slot_time'    => '10:00:00',
                'duration_min' => 20,
                'capacity'     => 1,
                'booked_count' => 0,
                'held_count'   => 0,
                'is_open'      => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
        );
        $selection = [
            'clinician_id' => $this->clinician_id,
            'slot_id'      => $slot_id,
            'slot_date'    => $date,
            'slot_time'    => '10:00:00',
        ];
        $user_id = self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT ] );
        wp_set_current_user( $user_id );
        $previous_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;

        try {
            $expires = time() + DAY_IN_SECONDS;
            $token   = \WP_Session_Tokens::get_instance( $user_id )->create( $expires );
            $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token );
            self::assertSame( $user_id, wp_validate_auth_cookie( '', 'logged_in' ) );
            $unlinked = $this->config( $this->render( (string) $this->clinic_id ) );
            self::assertNull( $unlinked['session_clinic_id'] );
            self::assertArrayHasKey( 'otp_request_path', $unlinked );
            $denied = $this->booking_post( $unlinked['hold_path'], $selection, $unlinked['nonce'] );
            self::assertSame( 403, $denied->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied->get_data()['code'] );

            $mobile     = '09121234037';
            $patient_id = $this->insert_row(
                'cpms_patients',
                [
                    'clinic_id'  => $this->clinic_id,
                    'mrn'        => 'P3UL-' . $this->uid,
                    'first_name' => 'Legacy',
                    'last_name'  => 'Fixture',
                    'mobile'     => $mobile,
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            $this->insert_row(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $this->clinic_id,
                    'patient_id'     => $patient_id,
                    'wp_user_id'     => $user_id,
                    'mobile_at_link' => $mobile,
                    'is_primary'     => 1,
                    'linked_at'      => $now,
                ],
                [ '%d', '%d', '%d', '%s', '%d', '%s' ]
            );
            cpms_test_seed_patient_session( $user_id, $this->clinic_id );
            PatientTwoProofTestHelper::seed_patient_proof(
                $user_id,
                $this->clinic_id,
                $patient_id,
                [
                    'mobile'             => $mobile,
                    'seed_session_scope' => false,
                    'set_current_user'   => true,
                ]
            );

            $html   = $this->render( (string) $this->clinic_id );
            $config = $this->config( $html );
            self::assertSame( [], $config['locations_by_clinic'] );
            self::assertSame( $this->clinic_id, $config['session_clinic_id'] );
            self::assertArrayNotHasKey( 'otp_request_path', $config );
            self::assertStringNotContainsString( 'data-role="otp-step"', $html );
            self::assertStringContainsString( 'data-role="booking-continue"', $html );

            $availability = new \WP_REST_Request( 'GET', '/clinic/v1' . $config['availability_path'] );
            $availability->set_query_params( [ 'clinician_id' => $this->clinician_id, 'from' => $date, 'to' => $date ] );
            $slots = rest_do_request( $availability );
            self::assertSame( 200, $slots->get_status() );
            self::assertSame( $slot_id, $slots->get_data()['data']['days'][0]['slots'][0]['slot_id'] ?? null );
            $quote = $this->booking_post( $config['quote_path'], $selection );
            self::assertSame( 200, $quote->get_status() );
            self::assertTrue( $quote->get_data()['data']['available'] );
            self::assertSame( $this->location_id, $quote->get_data()['data']['location_id'] );

            $hold = $this->booking_post(
                $config['hold_path'],
                $selection + [ 'location_id' => $this->location_id ],
                $config['nonce']
            );
            self::assertSame( 200, $hold->get_status() );
            $hold_token = $hold->get_data()['data']['hold_token'] ?? '';
            self::assertNotSame( '', $hold_token );
            $confirm = $this->booking_post( $config['confirm_path'], [ 'hold_token' => $hold_token ], $config['nonce'] );
            self::assertSame( 200, $confirm->get_status() );
            self::assertSame( 'confirmed', $confirm->get_data()['data']['status'] );
        } finally {
            if ( null === $previous_cookie ) {
                unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            } else {
                $_COOKIE[ LOGGED_IN_COOKIE ] = $previous_cookie;
            }
            wp_set_current_user( 0 );
        }
    }

    private function booking_post( string $path, array $params, ?string $nonce = null ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'POST', '/clinic/v1' . $path );
        $request->set_body_params( $params );
        $request->set_header( 'Idempotency-Key', wp_generate_uuid4() );
        if ( null !== $nonce ) {
            $request->set_header( 'X-WP-Nonce', $nonce );
        }

        return rest_do_request( $request );
    }

    // =====================================================================
    // fixture
    // =====================================================================

    private function build_fixture(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->org = $this->insert_row(
            'cpms_organizations',
            [
                'name'       => 'Org P3UL ' . $this->uid,
                'slug'       => 'org-p3ul-' . $this->uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        $this->clinic_id = $this->insert_row(
            'cpms_clinics',
            [
                'organization_id' => $this->org,
                'name'            => 'Clinic P3UL ' . $this->uid,
                'slug'            => 'clinic-p3ul-' . $this->uid,
                'timezone'        => self::TZ_TEHRAN,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        App::settingsFactory()->forClinic( $this->clinic_id )->set( 'public.listing_opt_in', true );

        // One ACTIVE Location of this Clinic that nobody is assigned to yet.
        $this->location_id = $this->insert_row(
            'cpms_locations',
            [
                'clinic_id'  => $this->clinic_id,
                'name'       => 'Main P3UL ' . $this->uid,
                'slug'       => 'main-p3ul-' . $this->uid,
                'address'    => 'Address P3UL',
                'timezone'   => self::TZ_TEHRAN,
                'is_primary' => 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        $wp_user_id = (int) wp_insert_user(
            [
                'user_login' => 'p3ul-' . $this->uid,
                'user_email' => 'p3ul-' . $this->uid . '@p3ul.cpms.test',
                'user_pass'  => wp_generate_password( 20, true ),
                'role'       => RolesAndCapabilities::ROLE_DOCTOR,
            ]
        );
        self::assertGreaterThan( 0, $wp_user_id, 'Fixture WordPress user must be created.' );

        // Active clinician, linked user, active Clinic-wide membership — the ONLY
        // missing piece is the durable clinician -> Location assignment.
        $this->clinician_id = $this->insert_row(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinic_id,
                'wp_user_id' => $wp_user_id,
                'full_name'  => 'Dr Unassigned ' . $this->uid,
                'specialty'  => 'قلب',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->insert_row(
            'cpms_clinic_memberships',
            [
                'clinic_id'  => $this->clinic_id,
                'wp_user_id' => $wp_user_id,
                'role_key'   => 'doctor',
                'scope_mode' => 'clinic',
                'status'     => 'active',
                'is_primary' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    private function insert_clinician_location( int $clinician_id, int $location_id, int $is_primary, string $now ): void
    {
        $this->insert_row(
            'cpms_clinician_locations',
            [
                'clinician_id' => $clinician_id,
                'location_id'  => $location_id,
                'is_primary'   => $is_primary,
                'created_at'   => $now,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );
    }

    /**
     * Location ids published for one clinician item.
     *
     * @return list<int>
     */
    private function locations_of( string $html, int $clinician_id ): array
    {
        $pattern = '#data-clinician-id="' . $clinician_id . '"[^>]*data-locations="([^"]*)"#';
        if ( preg_match( $pattern, $html, $m ) !== 1 ) {
            return [];
        }

        $ids = [];
        foreach ( preg_split( '/\s+/', trim( (string) $m[1] ) ) ?: [] as $token ) {
            if ( $token !== '' ) {
                $ids[] = (int) $token;
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function config( string $html ): array
    {
        $pattern = '#<script type="application/json" class="cpms-public-booking__config">(.*?)</script>#s';
        if ( preg_match( $pattern, $html, $m ) !== 1 ) {
            self::fail( 'The rendered surface must publish its runtime config contract.' );
        }

        $decoded = json_decode( (string) html_entity_decode( $m[1], ENT_QUOTES ), true );
        self::assertIsArray( $decoded, 'The runtime config must be valid JSON.' );

        return $decoded;
    }

    private function render( string $clinic_value ): string
    {
        App::boot();

        return (string) do_shortcode( '[' . self::SHORTCODE . ' clinic_id="' . $clinic_value . '"]' );
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert_row( string $table, array $data, array $formats ): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert( $wpdb->prefix . $table, $data, $formats );
        self::assertTrue( (bool) $ok, 'fixture insert into ' . $table . ' failed: ' . $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id, 'fixture id must be positive/dynamic.' );

        return $id;
    }
}

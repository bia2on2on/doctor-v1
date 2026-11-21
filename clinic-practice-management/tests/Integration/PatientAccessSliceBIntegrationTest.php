<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Integration tests for PR13-SLICE-B:
 *  - positive controls (status & body shape parity with `main`) for all 16 newly-gated endpoints;
 *  - required negatives (401 unauthenticated, unlinked, cross-clinic, tampered patient_id,
 *    multi-role / global-cap / scoped-staff 403, archived patient);
 *  - A3 false-lockout staff positive controls on all 6 shared routes;
 *  - A4 DB-error fail-closed proof with witness pass & strict-mode restoration.
 */
final class PatientAccessSliceBIntegrationTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const NS = '/clinic/v1';

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
    private int $locationA;
    private int $locationB;
    private int $clinicianA;
    private int $clinicianDocUser;

    private int $patientA;
    private int $patientB;
    private int $patientOtherA;
    private int $patientArchivedA;

    private int $patientUserA;
    private int $patientUserB;
    private int $patientUserOtherA;
    private int $patientUserArchivedA;
    private int $patientUserZero;

    private int $linkA;
    private int $linkB;

    private int $multiRoleUser;
    private int $globalCapUser;
    private int $scopedMembershipUser;
    private int $customRoleUser;
    private int $explicitGrantUser;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set( 'booking.min_lead_hours', 2 );
        App::settings()->set( 'booking.max_future_days', 60 );
        App::settings()->set( 'booking.hold_ttl_sec', 600 );
        App::settings()->set( 'booking.cancel_deadline_hours', 24 );
        App::settings()->set( 'booking.reschedule_deadline_hours', 24 );
        App::settings()->set( 'clinical.require_chief_complaint', false );
        App::settings()->set( 'queue.auto_enqueue', true );

        $db  = App::db();
        $now = $db->nowUtcSql();

        $this->clinicA = 1;
        $this->orgId   = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $this->clinicA ]
        );
        self::assertGreaterThan( 0, $this->orgId );

        $this->locationA = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d LIMIT 1',
            [ $this->clinicA ]
        );
        if ( $this->locationA <= 0 ) {
            $this->locationA = $this->insertLocation( $this->clinicA, 'SliceB Loc A' );
        }

        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'SliceB Clinic B',
                'slug'            => 'sliceb-clinic-b-' . uniqid(),
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB   = $db->wpdb_last_insert_id();
        $this->locationB = $this->insertLocation( $this->clinicB, 'SliceB Loc B' );

        App::settingsFactory()->forClinic( $this->clinicA )->set( 'public.listing_opt_in', true );
        App::settingsFactory()->forClinic( $this->clinicB )->set( 'public.listing_opt_in', true );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.min_lead_hours', 2 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.max_future_days', 60 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.cancel_deadline_hours', 24 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.reschedule_deadline_hours', 24 );

        $this->clinicianDocUser = $this->makeUser( 'sb_doc_' . uniqid(), RolesAndCapabilities::ROLE_DOCTOR );
        cpms_test_seed_membership( $this->clinicianDocUser, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        $this->clinicianA = $this->insertClinician( $this->clinicA, $this->clinicianDocUser, 'Dr SliceB A' );

        $this->patientA         = $this->insertPatient( $this->clinicA, 'MR-SB-A-' . uniqid(), 'Alice', 'SliceB', '09121010001', 'active' );
        $this->patientOtherA    = $this->insertPatient( $this->clinicA, 'MR-SB-O-' . uniqid(), 'Eve', 'Other', '09121010002', 'active' );
        $this->patientArchivedA = $this->insertPatient( $this->clinicA, 'MR-SB-X-' . uniqid(), 'Arch', 'Pat', '09121010003', 'archived' );
        $this->patientB         = $this->insertPatient( $this->clinicB, 'MR-SB-B-' . uniqid(), 'Bob', 'ClinicB', '09121010004', 'active' );

        $this->patientUserA         = $this->makeUser( 'sb_pat_a_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserOtherA    = $this->makeUser( 'sb_pat_o_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserArchivedA = $this->makeUser( 'sb_pat_x_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserB         = $this->makeUser( 'sb_pat_b_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->patientUserZero      = $this->makeUser( 'sb_pat_z_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );

        $this->linkA = $this->insertPatientLink( $this->clinicA, $this->patientA, $this->patientUserA, '09121010001' );
        $this->insertPatientLink( $this->clinicA, $this->patientOtherA, $this->patientUserOtherA, '09121010002' );
        $this->insertPatientLink( $this->clinicA, $this->patientArchivedA, $this->patientUserArchivedA, '09121010003' );
        $this->linkB = $this->insertPatientLink( $this->clinicB, $this->patientB, $this->patientUserB, '09121010004' );

        PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserA,
            $this->clinicA,
            $this->patientA,
            [ 'mobile' => '09121010001', 'seed_session_scope' => true, 'set_current_user' => false ]
        );
        PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserOtherA,
            $this->clinicA,
            $this->patientOtherA,
            [ 'mobile' => '09121010002', 'seed_session_scope' => true, 'set_current_user' => false ]
        );
        PatientTwoProofTestHelper::seed_patient_proof(
            $this->patientUserB,
            $this->clinicB,
            $this->patientB,
            [ 'mobile' => '09121010004', 'seed_session_scope' => true, 'set_current_user' => false ]
        );

        // Multi-role, global-cap, and Clinic-scoped staff users holding ROLE_PATIENT + active Clinic A patient link.
        $this->multiRoleUser = $this->makeUser( 'sb_multi_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $multiObj            = get_userdata( $this->multiRoleUser );
        self::assertNotFalse( $multiObj );
        $multiObj->add_role( RolesAndCapabilities::ROLE_MANAGER );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->multiRoleUser, '09121010001' );

        $this->globalCapUser = $this->makeUser( 'sb_gcap_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $gcapObj             = get_userdata( $this->globalCapUser );
        self::assertNotFalse( $gcapObj );
        $gcapObj->add_cap( RolesAndCapabilities::MEDICAL_READ );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->globalCapUser, '09121010001' );

        $this->scopedMembershipUser = $this->makeUser( 'sb_scoped_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        cpms_test_seed_membership( $this->scopedMembershipUser, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->scopedMembershipUser, '09121010001' );

        $this->customRoleUser = $this->makeUser( 'sb_crole_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertActiveCustomRoleDef( $this->clinicB, 'cx_sb_intake', [ RolesAndCapabilities::QUEUE_READ ] );
        App::membership_service()->create_membership( $this->clinicB, $this->customRoleUser, 'cx_sb_intake' );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->customRoleUser, '09121010001' );

        $this->explicitGrantUser = $this->makeUser( 'sb_egrant_' . uniqid(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertActiveCustomRoleDef( $this->clinicB, 'cx_sb_empty', [] );
        $grantMid = App::membership_service()->create_membership( $this->clinicB, $this->explicitGrantUser, 'cx_sb_empty' );
        App::membership_service()->set_capability( $grantMid, RolesAndCapabilities::APPT_READ, 'grant' );
        $this->insertPatientLink( $this->clinicA, $this->patientA, $this->explicitGrantUser, '09121010001' );

        $this->installSignedExpiration( time() + 30 * 86400 );
        PatientTwoProofTestHelper::acting_as( 0 );
    }

    protected function tearDown(): void {
        remove_all_filters( 'cpms_license_public_key' );
        PatientTwoProofTestHelper::reset();
        App::resetScope();
        parent::tearDown();
    }

    public function testClinicalPatientRoutesPositiveAndRequiredNegatives(): void {
        $visitA = $this->createCompletedVisitWithPrescription( $this->clinicA, $this->locationA, $this->clinicianA, $this->clinicianDocUser, $this->patientA );
        $visitB = $this->createCompletedVisitWithPrescription( $this->clinicA, $this->locationA, $this->clinicianA, $this->clinicianDocUser, $this->patientOtherA );

        // Positive controls: same status (200) and body shape as `main`.
        $visitsOk = $this->dispatch( 'GET', self::NS . '/visits', [], $this->patientUserA );
        self::assertSame( 200, $visitsOk->get_status() );
        self::assertArrayHasKey( 'visits', $visitsOk->get_data()['data'] ?? [] );
        self::assertCount( 1, $visitsOk->get_data()['data']['visits'] );

        $detailOk = $this->dispatch( 'GET', self::NS . '/visits/' . $visitA, [], $this->patientUserA );
        self::assertSame( 200, $detailOk->get_status() );
        self::assertArrayHasKey( 'visit', $detailOk->get_data()['data'] ?? [] );
        self::assertArrayHasKey( 'notes', $detailOk->get_data()['data'] ?? [] );
        self::assertArrayHasKey( 'prescriptions', $detailOk->get_data()['data'] ?? [] );

        $rxOk = $this->dispatch( 'GET', self::NS . '/prescriptions', [], $this->patientUserA );
        self::assertSame( 200, $rxOk->get_status() );
        self::assertArrayHasKey( 'prescriptions', $rxOk->get_data()['data'] ?? [] );
        self::assertCount( 1, $rxOk->get_data()['data']['prescriptions'] );

        // Negatives across all 3 clinical routes.
        foreach ( [
            self::NS . '/visits',
            self::NS . '/visits/' . $visitA,
            self::NS . '/prescriptions',
        ] as $route ) {
            // Unauthenticated -> 403 PROOF_FAILED.
            $unauth = $this->dispatch( 'GET', $route, [], 0 );
            self::assertSame( 403, $unauth->get_status(), $route );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unauth->get_data()['code'] ?? null );

            // Unlinked patient -> 403 PROOF_FAILED.
            $unlinked = $this->dispatch( 'GET', $route, [], $this->patientUserZero );
            self::assertSame( 403, $unlinked->get_status(), $route );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unlinked->get_data()['code'] ?? null );

            // Inactive (archived) patient -> 403 PROOF_FAILED.
            $archived = $this->dispatch( 'GET', $route, [], $this->patientUserArchivedA );
            self::assertSame( 403, $archived->get_status(), $route );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $archived->get_data()['code'] ?? null );

            // Foreign clinic link_id -> 403 PROOF_FAILED; client-supplied clinic_id is enforced at gate.
            $crossLink = $this->dispatch( 'GET', $route, [ 'link_id' => $this->linkB ], $this->patientUserA );
            self::assertSame( 403, $crossLink->get_status(), $route );

            // Multi-role, global-cap, and scoped-staff -> 403 PROOF_FAILED.
            foreach ( [ $this->multiRoleUser, $this->globalCapUser, $this->scopedMembershipUser, $this->customRoleUser, $this->explicitGrantUser ] as $badActor ) {
                $forbidden = $this->dispatch( 'GET', $route, [], $badActor );
                self::assertSame( 403, $forbidden->get_status(), $route . ' actor=' . $badActor );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $forbidden->get_data()['code'] ?? null );
            }
        }

        // Tampered patient_id / clinic_id in query must never disclose PatientOtherA's or Clinic B's records.
        $tamperVisits = $this->dispatch( 'GET', self::NS . '/visits', [ 'clinic_id' => $this->clinicB, 'patient_id' => $this->patientOtherA ], $this->patientUserA );
        self::assertSame( 200, $tamperVisits->get_status() );
        self::assertSame( [ $visitA ], array_column( $tamperVisits->get_data()['data']['visits'], 'id' ) );

        $tamperDetail = $this->dispatch( 'GET', self::NS . '/visits/' . $visitB, [ 'patient_id' => $this->patientOtherA ], $this->patientUserA );
        self::assertSame( 404, $tamperDetail->get_status() );

        $tamperRx = $this->dispatch( 'GET', self::NS . '/prescriptions', [ 'clinic_id' => $this->clinicB, 'patient_id' => $this->patientOtherA ], $this->patientUserA );
        self::assertSame( 200, $tamperRx->get_status() );
        self::assertCount( 1, $tamperRx->get_data()['data']['prescriptions'] );
    }

    public function testFilesRoutesPositiveAndRequiredNegatives(): void {
        // Positive controls: upload (201), list (200), stream (200).
        $uploadOk = $this->dispatchUpload( self::NS . '/patients/' . $this->patientA . '/files', $this->patientUserA );
        self::assertSame( 201, $uploadOk->get_status() );
        $fileId = (int) ( $uploadOk->get_data()['data']['id'] ?? 0 );
        self::assertGreaterThan( 0, $fileId );

        $listOk = $this->dispatch( 'GET', self::NS . '/patients/' . $this->patientA . '/files', [], $this->patientUserA );
        self::assertSame( 200, $listOk->get_status() );
        self::assertArrayHasKey( 'files', $listOk->get_data()['data'] ?? [] );
        self::assertCount( 1, $listOk->get_data()['data']['files'] );

        $streamOk = $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $this->patientUserA );
        self::assertSame( 200, $streamOk->get_status() );
        self::assertStringContainsString( '%PDF', (string) $streamOk->get_data() );

        $routes = [
            [ 'POST', self::NS . '/patients/' . $this->patientA . '/files', true ],
            [ 'GET', self::NS . '/patients/' . $this->patientA . '/files', false ],
            [ 'GET', self::NS . '/files/' . $fileId . '/stream', false ],
        ];

        foreach ( $routes as [$method, $route, $isUpload] ) {
            // Unauthenticated: shared stream -> 401; patient-only -> 403 PROOF_FAILED.
            $unauth = $isUpload ? $this->dispatchUpload( $route, 0 ) : $this->dispatch( $method, $route, [], 0 );
            self::assertSame( str_contains( $route, '/stream' ) ? 401 : 403, $unauth->get_status(), $method . ' ' . $route );

            // Unlinked patient -> 403 PROOF_FAILED.
            $unlinked = $isUpload ? $this->dispatchUpload( $route, $this->patientUserZero ) : $this->dispatch( $method, $route, [], $this->patientUserZero );
            self::assertSame( 403, $unlinked->get_status(), $method . ' ' . $route );

            // Inactive (archived) patient -> 403 PROOF_FAILED.
            $archivedRoute = str_contains( $route, '/patients/' ) ? self::NS . '/patients/' . $this->patientArchivedA . '/files' : $route;
            $archived      = $isUpload ? $this->dispatchUpload( $archivedRoute, $this->patientUserArchivedA ) : $this->dispatch( $method, $archivedRoute, [], $this->patientUserArchivedA );
            self::assertSame( 403, $archived->get_status(), $method . ' ' . $archivedRoute );

            // Link to another clinic -> 403 on patient-scoped routes; foreign user -> 404 non-enumerating on stream.
            if ( str_contains( $route, '/patients/' ) ) {
                $crossLink = $isUpload
                    ? $this->dispatchUpload( $route, $this->patientUserA, [ 'link_id' => $this->linkB ] )
                    : $this->dispatch( $method, $route, [ 'link_id' => $this->linkB ], $this->patientUserA );
                self::assertSame( 403, $crossLink->get_status(), $method . ' ' . $route );
            } else {
                self::assertSame( 404, $this->dispatch( $method, $route, [], $this->patientUserB )->get_status(), $method . ' ' . $route );
            }

            // Multi-role, global-cap, and scoped-staff -> 403 PROOF_FAILED.
            foreach ( [ $this->multiRoleUser, $this->globalCapUser, $this->scopedMembershipUser, $this->customRoleUser, $this->explicitGrantUser ] as $badActor ) {
                $forbidden = $isUpload ? $this->dispatchUpload( $route, $badActor ) : $this->dispatch( $method, $route, [], $badActor );
                self::assertSame( 403, $forbidden->get_status(), $method . ' ' . $route . ' actor=' . $badActor );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $forbidden->get_data()['code'] ?? null );
            }
        }

        // Tampered patient_id in path -> 403; cross-patient stream -> 404 non-enumerating.
        $tamperUpload = $this->dispatchUpload( self::NS . '/patients/' . $this->patientOtherA . '/files', $this->patientUserA );
        self::assertSame( 403, $tamperUpload->get_status() );
        $tamperList = $this->dispatch( 'GET', self::NS . '/patients/' . $this->patientOtherA . '/files', [], $this->patientUserA );
        self::assertSame( 403, $tamperList->get_status() );
        $tamperStream = $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [ 'patient_id' => $this->patientA ], $this->patientUserOtherA );
        self::assertSame( 404, $tamperStream->get_status() );
    }

    public function testBookingPatientRoutesPositiveAndRequiredNegatives(): void {
        $date1 = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date2 = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $slot1 = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date1, '10:00:00', 3 );
        $slot2 = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date2, '11:00:00', 3 );

        // Positive controls: hold -> resume -> confirm -> mine -> reschedule-options -> reschedule -> cancel.
        $holdRes = $this->dispatch( 'POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianA,
            'slot_date'    => $date1,
            'slot_time'    => '10:00:00',
            'slot_id'      => $slot1,
        ], $this->patientUserA );
        self::assertSame( 200, $holdRes->get_status() );
        $holdToken = (string) ( $holdRes->get_data()['data']['hold_token'] ?? '' );
        self::assertNotSame( '', $holdToken );

        $resumeRes = $this->dispatch( 'GET', self::NS . '/booking/resume', [ 'hold_token' => $holdToken ], $this->patientUserA );
        self::assertSame( 200, $resumeRes->get_status() );
        self::assertSame( 'active', $resumeRes->get_data()['data']['status'] ?? null );

        $confirmRes = $this->dispatch( 'POST', self::NS . '/booking/confirm', [
            'hold_token' => $holdToken,
        ], $this->patientUserA, [ 'Idempotency-Key' => wp_generate_uuid4() ] );
        self::assertSame( 200, $confirmRes->get_status() );
        $apptId = (int) ( $confirmRes->get_data()['data']['appointment_id'] ?? 0 );
        self::assertGreaterThan( 0, $apptId );

        $mineRes = $this->dispatch( 'GET', self::NS . '/appointments/mine', [ 'from' => $date1, 'to' => $date2 ], $this->patientUserA );
        self::assertSame( 200, $mineRes->get_status() );
        self::assertCount( 1, $mineRes->get_data()['data'] ?? [] );

        $optRes = $this->dispatch( 'GET', self::NS . '/appointments/' . $apptId . '/reschedule-options', [], $this->patientUserA );
        self::assertSame( 200, $optRes->get_status() );
        self::assertSame( $apptId, (int) ( $optRes->get_data()['data']['appointment_id'] ?? 0 ) );

        $reschedRes = $this->dispatch( 'POST', self::NS . '/appointments/' . $apptId . '/reschedule', [
            'clinician_id' => $this->clinicianA,
            'slot_date'    => $date2,
            'slot_time'    => '11:00:00',
            'slot_id'      => $slot2,
        ], $this->patientUserA, [ 'Idempotency-Key' => wp_generate_uuid4() ] );
        self::assertSame( 200, $reschedRes->get_status() );
        $newApptId = (int) ( $reschedRes->get_data()['data']['appointment_id'] ?? 0 );
        self::assertGreaterThan( 0, $newApptId );

        $cancelRes = $this->dispatch( 'POST', self::NS . '/appointments/' . $newApptId . '/cancel', [ 'reason' => 'ok' ], $this->patientUserA );
        self::assertSame( 200, $cancelRes->get_status() );
        self::assertSame( 'cancelled_by_patient', $cancelRes->get_data()['data']['status'] ?? null );

        // Re-seed an active appointment for negative tests on appt routes.
        $slot3    = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date2, '12:00:00', 3 );
        $liveAppt = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slot3, $date2, '12:00:00' );

        $bookingEndpoints = [
            [ 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1 ], [] ],
            [ 'POST', self::NS . '/booking/confirm', [ 'hold_token' => 'nonexistent' ], [ 'Idempotency-Key' => wp_generate_uuid4() ] ],
            [ 'GET', self::NS . '/booking/resume', [ 'hold_token' => 'nonexistent' ], [] ],
            [ 'GET', self::NS . '/appointments/mine', [ 'from' => $date1, 'to' => $date2 ], [] ],
            [ 'GET', self::NS . '/appointments/' . $liveAppt . '/reschedule-options', [], [] ],
            [ 'POST', self::NS . '/appointments/' . $liveAppt . '/reschedule', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date2, 'slot_time' => '11:00:00', 'slot_id' => $slot2 ], [ 'Idempotency-Key' => wp_generate_uuid4() ] ],
            [ 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [ 'reason' => 'neg' ], [] ],
        ];

        foreach ( $bookingEndpoints as [$method, $route, $params, $headers] ) {
            // Unauthenticated: shared appointment reschedule/cancel -> 401; patient-only booking -> 403 PROOF_FAILED.
            $unauth       = $this->dispatch( $method, $route, $params, 0, $headers );
            $expectUnauth = ( str_ends_with( $route, '/reschedule' ) || str_ends_with( $route, '/cancel' ) ) ? 401 : 403;
            self::assertSame( $expectUnauth, $unauth->get_status(), $method . ' ' . $route );

            // Multi-role, global-cap, and scoped-staff -> 403.
            foreach ( [ $this->multiRoleUser, $this->globalCapUser, $this->scopedMembershipUser, $this->customRoleUser, $this->explicitGrantUser ] as $badActor ) {
                $forbidden = $this->dispatch( $method, $route, $params, $badActor, $headers );
                self::assertSame( 403, $forbidden->get_status(), $method . ' ' . $route . ' actor=' . $badActor );
                if ( $badActor === $this->multiRoleUser && str_ends_with( $route, '/cancel' ) ) {
                    self::assertContains( $forbidden->get_data()['code'] ?? null, [ PatientAccess::PROOF_FAILED_CODE, 'CLINIC_SCOPE_UNAVAILABLE' ] );
                } else {
                    self::assertSame( PatientAccess::PROOF_FAILED_CODE, $forbidden->get_data()['code'] ?? null );
                }
            }
        }

        // Unlinked & inactive (archived) patient negatives on booking routes fail at gate with 403 PROOF_FAILED.
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1 ], $this->patientUserZero )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/booking/confirm', [ 'hold_token' => 'missing-tok' ], $this->patientUserZero, [ 'Idempotency-Key' => wp_generate_uuid4() ] )->get_status() );
        self::assertSame( 403, $this->dispatch( 'GET', self::NS . '/booking/resume', [ 'hold_token' => 'missing-tok' ], $this->patientUserZero )->get_status() );
        self::assertSame( 403, $this->dispatch( 'GET', self::NS . '/appointments/mine', [ 'from' => $date1, 'to' => $date2 ], $this->patientUserZero )->get_status() );
        self::assertSame( 403, $this->dispatch( 'GET', self::NS . '/appointments/' . $liveAppt . '/reschedule-options', [], $this->patientUserZero )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/reschedule', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date2, 'slot_time' => '11:00:00', 'slot_id' => $slot2 ], $this->patientUserZero, [ 'Idempotency-Key' => wp_generate_uuid4() ] )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [], $this->patientUserZero )->get_status() );

        // Archived patient: fails closed at gate with 403 PROOF_FAILED across all booking routes.
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1, 'patient_id' => $this->patientArchivedA ], $this->patientUserArchivedA )->get_status() );
        self::assertSame( 403, $this->dispatch( 'GET', self::NS . '/appointments/mine', [ 'from' => $date1, 'to' => $date2 ], $this->patientUserArchivedA )->get_status() );
        self::assertSame( 403, $this->dispatch( 'GET', self::NS . '/appointments/' . $liveAppt . '/reschedule-options', [], $this->patientUserArchivedA )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/reschedule', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date2, 'slot_time' => '11:00:00', 'slot_id' => $slot2 ], $this->patientUserArchivedA, [ 'Idempotency-Key' => wp_generate_uuid4() ] )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [], $this->patientUserArchivedA )->get_status() );

        // Cross-clinic link (patientUserB linked ONLY to clinic B) & tampered patient_id in body.
        self::assertSame( 400, $this->dispatch( 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1 ], $this->patientUserB )->get_status() );
        self::assertContains(
            $this->dispatch( 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1, 'patient_id' => $this->patientOtherA ], $this->patientUserA )->get_status(),
            [ 400, 422 ]
        );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [ 'patient_id' => $this->patientA ], $this->patientUserB )->get_status() );
        self::assertSame( 403, $this->dispatch( 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [ 'patient_id' => $this->patientA ], $this->patientUserOtherA )->get_status() );
        self::assertSame( 'confirmed', (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_appointments' ) . ' WHERE id = %d', [ $liveAppt ] ) );
    }

    public function testNotificationRoutesPositiveAndRequiredNegatives(): void {
        $notifId = (int) App::notificationService()->publishToPatient(
            $this->clinicA,
            $this->patientA,
            'appt_reminder',
            [
                'patient_name'     => 'Alice',
                'doctor_name'      => 'Dr SliceB A',
                'appointment_date' => '1405-01-01',
                'appointment_time' => '10:00',
                'clinic_name'      => 'Clinic A',
            ],
            'sb:notif:' . uniqid()
        );
        self::assertGreaterThan( 0, $notifId );

        // Positive controls: inbox (200), rt (200 + ETag + 304), read (200).
        $inboxOk = $this->dispatch( 'GET', self::NS . '/notifications', [], $this->patientUserA );
        self::assertSame( 200, $inboxOk->get_status() );
        self::assertCount( 1, $inboxOk->get_data()['data']['notifications'] ?? [] );

        $rtOk = $this->dispatch( 'GET', self::NS . '/rt/notifications', [], $this->patientUserA );
        self::assertSame( 200, $rtOk->get_status() );
        $etag = (string) ( $rtOk->get_headers()['ETag'] ?? '' );
        self::assertNotSame( '', $etag );
        $rt304 = $this->dispatch( 'GET', self::NS . '/rt/notifications', [], $this->patientUserA, [ 'If-None-Match' => $etag ] );
        self::assertSame( 304, $rt304->get_status() );

        $readOk = $this->dispatch( 'POST', self::NS . '/notifications/read', [ 'ids' => [ $notifId ] ], $this->patientUserA );
        self::assertSame( 200, $readOk->get_status() );
        self::assertSame( 1, (int) ( $readOk->get_data()['data']['marked'] ?? 0 ) );

        $notifRoutes = [
            [ 'GET', self::NS . '/notifications', [] ],
            [ 'POST', self::NS . '/notifications/read', [ 'ids' => [ $notifId ] ] ],
            [ 'GET', self::NS . '/rt/notifications', [] ],
        ];

        foreach ( $notifRoutes as [$method, $route, $params] ) {
            // Unauthenticated -> 401.
            self::assertSame( 401, $this->dispatch( $method, $route, $params, 0 )->get_status(), $method . ' ' . $route );

            // Multi-role, global-cap, and scoped-staff -> 403.
            foreach ( [ $this->multiRoleUser, $this->globalCapUser, $this->scopedMembershipUser, $this->customRoleUser, $this->explicitGrantUser ] as $badActor ) {
                $forbidden = $this->dispatch( $method, $route, $params, $badActor );
                self::assertSame( 403, $forbidden->get_status(), $method . ' ' . $route . ' actor=' . $badActor );
                if ( $badActor === $this->multiRoleUser ) {
                    self::assertContains( $forbidden->get_data()['code'] ?? null, [ PatientAccess::PROOF_FAILED_CODE, 'CLINIC_SCOPE_UNAVAILABLE' ] );
                } else {
                    self::assertSame( PatientAccess::PROOF_FAILED_CODE, $forbidden->get_data()['code'] ?? null );
                }
            }

            // Explicit foreign clinic_id or foreign link_id -> 403 PROOF_FAILED.
            self::assertSame( 403, $this->dispatch( $method, $route, array_merge( $params, [ 'clinic_id' => $this->clinicB ] ), $this->patientUserA )->get_status(), $method . ' ' . $route );
            self::assertSame( 403, $this->dispatch( $method, $route, array_merge( $params, [ 'link_id' => $this->linkB ] ), $this->patientUserA )->get_status(), $method . ' ' . $route );

            // Archived patient with explicit clinic_id -> 403 PROOF_FAILED.
            self::assertSame( 403, $this->dispatch( $method, $route, array_merge( $params, [ 'clinic_id' => $this->clinicA ] ), $this->patientUserArchivedA )->get_status(), $method . ' ' . $route );
        }

        // Unlinked patient without clinic_id fails at gate with 403 PROOF_FAILED; tampered patient_id cannot read/mark notifId.
        $zeroInbox = $this->dispatch( 'GET', self::NS . '/notifications', [ 'patient_id' => $this->patientA ], $this->patientUserZero );
        self::assertSame( 403, $zeroInbox->get_status() );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $zeroInbox->get_data()['code'] ?? null );

        $otherRead = $this->dispatch( 'POST', self::NS . '/notifications/read', [ 'ids' => [ $notifId ], 'patient_id' => $this->patientA ], $this->patientUserOtherA );
        self::assertSame( 200, $otherRead->get_status() );
        self::assertSame( 0, (int) ( $otherRead->get_data()['data']['marked'] ?? -1 ) );
    }

    public function testSharedRoutesStaffPositiveControlsAcrossAuthoritySources(): void {
        $date1    = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date2    = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $slotA    = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date1, '09:00:00', 4 );
        $slotB    = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date2, '14:00:00', 4 );
        $appt1    = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );
        $appt2    = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );

        // 1 & 2. Shared appointment reschedule/cancel across WP role, global cap, Clinic membership (subscriber), custom role, and explicit grant.
        $appt3    = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );
        $appt4    = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );
        $appt5    = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );

        $secWp = $this->makeUser( 'sb_sec_wp_' . uniqid(), RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $secWp, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );

        $capStaff = $this->makeUser( 'sb_cap_staff_' . uniqid(), 'subscriber' );
        $capObj   = get_userdata( $capStaff );
        self::assertNotFalse( $capObj );
        $capObj->add_cap( RolesAndCapabilities::APPT_RESCHEDULE );
        $capObj->add_cap( RolesAndCapabilities::APPT_CANCEL );
        cpms_test_seed_membership( $capStaff, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );

        $subMembershipSec = $this->makeUser( 'sb_sub_msec_' . uniqid(), 'subscriber' );
        cpms_test_seed_membership( $subMembershipSec, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );

        $subCustomBooking = $this->makeUser( 'sb_sub_cbook_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_booking_ops', [ RolesAndCapabilities::APPT_RESCHEDULE, RolesAndCapabilities::APPT_CANCEL ] );
        App::membership_service()->create_membership( $this->clinicA, $subCustomBooking, 'cx_sb_booking_ops' );

        $subGrantBooking = $this->makeUser( 'sb_sub_gbook_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_booking_empty', [] );
        $gBookMid = App::membership_service()->create_membership( $this->clinicA, $subGrantBooking, 'cx_sb_booking_empty' );
        App::membership_service()->set_capability( $gBookMid, RolesAndCapabilities::APPT_RESCHEDULE, 'grant' );
        App::membership_service()->set_capability( $gBookMid, RolesAndCapabilities::APPT_CANCEL, 'grant' );

        foreach ( [ $secWp, $capStaff, $subMembershipSec, $subCustomBooking, $subGrantBooking ] as $idx => $staffActor ) {
            $targetAppt = [ $appt1, $appt2, $appt3, $appt4, $appt5 ][ $idx ];
            App::resetScope();
            $resched    = $this->dispatch(
                'POST',
                self::NS . '/appointments/' . $targetAppt . '/reschedule',
                [
                    'clinician_id' => $this->clinicianA,
                    'slot_date'    => $date2,
                    'slot_time'    => '14:00:00',
                    'slot_id'      => $slotB,
                ],
                $staffActor,
                [
                    'Idempotency-Key' => wp_generate_uuid4(),
                    'X-CPMS-Clinic-Id' => (string) $this->clinicA,
                ]
            );
            self::assertSame( 200, $resched->get_status(), 'Shared reschedule staff actor #' . $idx . ' must stay 200.' );
            $newTargetAppt = (int) ( $resched->get_data()['data']['appointment_id'] ?? 0 );
            self::assertGreaterThan( 0, $newTargetAppt );

            App::resetScope();
            $cancel = $this->dispatch(
                'POST',
                self::NS . '/appointments/' . $newTargetAppt . '/cancel',
                [ 'reason' => 'staff-ok' ],
                $staffActor,
                [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ]
            );
            self::assertSame( 200, $cancel->get_status(), 'Shared cancel staff actor #' . $idx . ' must stay 200.' );
        }

        // Negative controls on shared reschedule/cancel: plain subscriber & doctor-membership subscriber without APPT_CANCEL/RESCHEDULE -> 403.
        $plainSub = $this->makeUser( 'sb_plain_sub_' . uniqid(), 'subscriber' );
        $liveNeg  = $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, $slotA, $date1, '09:00:00' );
        $subDocNoBooking = $this->makeUser( 'sb_sub_docnb_' . uniqid(), 'subscriber' );
        cpms_test_seed_membership( $subDocNoBooking, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        foreach ( [ $plainSub, $subDocNoBooking ] as $unauthStaff ) {
            App::resetScope();
            $negResched = $this->dispatch(
                'POST',
                self::NS . '/appointments/' . $liveNeg . '/reschedule',
                [
                    'clinician_id' => $this->clinicianA,
                    'slot_date'    => $date2,
                    'slot_time'    => '14:00:00',
                    'slot_id'      => $slotB,
                ],
                $unauthStaff,
                [
                    'Idempotency-Key'  => wp_generate_uuid4(),
                    'X-CPMS-Clinic-Id' => (string) $this->clinicA,
                ]
            );
            self::assertSame( 403, $negResched->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $negResched->get_data()['code'] ?? null );

            App::resetScope();
            $negCancel = $this->dispatch(
                'POST',
                self::NS . '/appointments/' . $liveNeg . '/cancel',
                [ 'reason' => 'no-cap' ],
                $unauthStaff,
                [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ]
            );
            self::assertSame( 403, $negCancel->get_status() );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $negCancel->get_data()['code'] ?? null );
        }

        // 3. Shared GET /files/{id}/stream: WP role, global cap, clinic membership (subscriber), custom role, explicit grant, org membership.
        $uploadRes = $this->dispatchUpload( self::NS . '/patients/' . $this->patientA . '/files', $this->patientUserA );
        self::assertSame( 201, $uploadRes->get_status() );
        $fileId = (int) ( $uploadRes->get_data()['data']['id'] ?? 0 );
        self::assertGreaterThan( 0, $fileId );

        // WP role `cpms_doctor` + active membership.
        self::assertSame( 200, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $this->clinicianDocUser )->get_status() );

        // Global cap `FILE_READ` + active membership.
        $globalFileUser = $this->makeUser( 'sb_gfile_' . uniqid(), 'subscriber' );
        $gfObj          = get_userdata( $globalFileUser );
        self::assertNotFalse( $gfObj );
        $gfObj->add_cap( RolesAndCapabilities::FILE_READ );
        cpms_test_seed_membership( $globalFileUser, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertSame( 200, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $globalFileUser )->get_status() );

        // Clinic membership (plain `subscriber` with `cpms_secretary` membership).
        $scopedSec = $this->makeUser( 'sb_sub_sec_' . uniqid(), 'subscriber' );
        cpms_test_seed_membership( $scopedSec, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY );
        self::assertSame( 200, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $scopedSec )->get_status() );

        // Custom role granting FILE_READ + active clinician identity.
        $customDoc = $this->makeUser( 'sb_sub_cdoc_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_filereader', [ RolesAndCapabilities::FILE_READ ] );
        App::membership_service()->create_membership( $this->clinicA, $customDoc, 'cx_sb_filereader' );
        $this->insertClinician( $this->clinicA, $customDoc, 'Dr Custom Stream' );
        self::assertSame( 200, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $customDoc )->get_status() );

        // Explicit membership grant of FILE_READ + active clinician identity.
        $grantDoc = $this->makeUser( 'sb_sub_gdoc_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_empty_stream', [] );
        $gDocMid = App::membership_service()->create_membership( $this->clinicA, $grantDoc, 'cx_sb_empty_stream' );
        App::membership_service()->set_capability( $gDocMid, RolesAndCapabilities::FILE_READ, 'grant' );
        $this->insertClinician( $this->clinicA, $grantDoc, 'Dr Grant Stream' );
        self::assertSame( 200, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $grantDoc )->get_status() );

        // Organization membership without Clinic membership: behaves identically to `main` (404 from stream(), never 403 false lockout).
        $orgMember = $this->makeUser( 'sb_org_mem_' . uniqid(), 'subscriber' );
        App::db()->insert(
            'cpms_organization_memberships',
            [
                'organization_id' => $this->orgId,
                'wp_user_id'      => $orgMember,
                'role_key'        => 'organization_manager',
                'status'          => 'active',
                'created_at'      => App::db()->nowUtcSql(),
                'updated_at'      => App::db()->nowUtcSql(),
            ]
        );
        self::assertSame( 404, $this->dispatch( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $orgMember )->get_status() );

        // 4, 5, 6. Shared notification routes across WP roles, Clinic membership (subscriber), custom role, explicit grant, and org membership.
        $wpManager = $this->makeUser( 'sb_wp_mgr_' . uniqid(), RolesAndCapabilities::ROLE_MANAGER );
        cpms_test_seed_membership( $wpManager, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER );

        $subNotifCustom = $this->makeUser( 'sb_sub_cnotif_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_notif', [ RolesAndCapabilities::QUEUE_READ ] );
        App::membership_service()->create_membership( $this->clinicA, $subNotifCustom, 'cx_sb_notif' );

        $subNotifGrant = $this->makeUser( 'sb_sub_gnotif_' . uniqid(), 'subscriber' );
        $this->insertActiveCustomRoleDef( $this->clinicA, 'cx_sb_notif_empty', [] );
        $gNotifMid = App::membership_service()->create_membership( $this->clinicA, $subNotifGrant, 'cx_sb_notif_empty' );
        App::membership_service()->set_capability( $gNotifMid, RolesAndCapabilities::QUEUE_READ, 'grant' );

        foreach ( [ $secWp, $this->clinicianDocUser, $wpManager, $subMembershipSec, $subNotifCustom, $subNotifGrant ] as $staffUser ) {
            App::resetScope();
            $staffNotifId = (int) App::notificationService()->publishToUser(
                $this->clinicA,
                $staffUser,
                'queue_called',
                [
                    'patient_name' => 'Alice',
                    'doctor_name'  => 'Dr SliceB A',
                    'clinic_name'  => 'Clinic A',
                ],
                'sb:staffnotif:' . uniqid()
            );
            self::assertGreaterThan( 0, $staffNotifId );

            $inbox = $this->dispatch( 'GET', self::NS . '/notifications', [], $staffUser, [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ] );
            self::assertSame( 200, $inbox->get_status() );

            $rt = $this->dispatch( 'GET', self::NS . '/rt/notifications', [], $staffUser, [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ] );
            self::assertSame( 200, $rt->get_status() );

            $read = $this->dispatch( 'POST', self::NS . '/notifications/read', [ 'ids' => [ $staffNotifId ] ], $staffUser, [ 'X-CPMS-Clinic-Id' => (string) $this->clinicA ] );
            self::assertSame( 200, $read->get_status() );
        }

        // Organization membership without Clinic membership: staff branch runs (400 CLINIC_SCOPE_REQUIRED on multi-clinic, never 403 patient lockout).
        App::resetScope();
        $orgNotif = $this->dispatch( 'GET', self::NS . '/notifications', [], $orgMember );
        self::assertSame( 400, $orgNotif->get_status() );
        self::assertSame( 'CLINIC_SCOPE_REQUIRED', $orgNotif->get_data()['code'] ?? null );

        // Plain subscriber without any staff authority or patient proof is denied 403 PROOF_FAILED_CODE.
        App::resetScope();
        $plainNotif = $this->dispatch( 'GET', self::NS . '/notifications', [], $plainSub );
        self::assertSame( 403, $plainNotif->get_status() );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $plainNotif->get_data()['code'] ?? null );
    }

    public function testSliceBDbErrorFailClosedWithWitnessAndStrictRestoration(): void {
        global $wpdb;
        $membershipTable = preg_quote( App::db()->table( 'cpms_clinic_memberships' ), '/' );
        $exactLookupSql  = '/^\s*SELECT\s+clinic_id\s+FROM\s+' . $membershipTable . '\s+WHERE\s+wp_user_id\s*=\s*' . $this->patientUserA . '\s+AND\s+status\s*=\s*\'active\'\s+ORDER\s+BY\s+clinic_id\s*$/';

        // 1. Witness pass (observe-only).
        $witnessMatches = 0;
        $witnessSeen    = [];
        $witnessFilter  = static function ( string $sql ) use ( $exactLookupSql, &$witnessMatches, &$witnessSeen ): string {
            if ( 1 === preg_match( $exactLookupSql, $sql ) ) {
                ++$witnessMatches;
                $witnessSeen[] = $sql;
            }

            return $sql;
        };
        add_filter( 'query', $witnessFilter, 5 );
        try {
            $witnessRes = $this->dispatch( 'GET', self::NS . '/visits', [], $this->patientUserA );
        } finally {
            remove_filter( 'query', $witnessFilter, 5 );
        }
        self::assertSame( 1, $witnessMatches );
        self::assertCount( 1, $witnessSeen );
        self::assertSame( 200, $witnessRes->get_status() );

        // 2. Corrupt ONLY that exact query; assert fail-closed 403 and strict-mode restoration.
        $strictBefore = App::db()->is_strict();
        self::assertFalse( $strictBefore );
        $failMatches = 0;
        $failSeen    = [];
        $corruptSql  = static function ( string $sql ) use ( $exactLookupSql, &$failMatches, &$failSeen ): string {
            if ( 1 === preg_match( $exactLookupSql, $sql ) ) {
                ++$failMatches;
                $failSeen[] = $sql;

                return 'SELECT nonexistent_col_for_sliceb_test FROM wp_nonexistent_cpms_table';
            }

            return $sql;
        };
        $prevSuppress = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $corruptSql, 5 );
        try {
            $deniedRes = $this->dispatch( 'GET', self::NS . '/visits', [], $this->patientUserA );
        } finally {
            remove_filter( 'query', $corruptSql, 5 );
            $wpdb->suppress_errors( $prevSuppress );
        }

        self::assertSame( 1, $failMatches );
        self::assertSame( $witnessSeen, $failSeen );
        self::assertSame( 403, $deniedRes->get_status() );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $deniedRes->get_data()['code'] ?? null );
        self::assertSame( $strictBefore, App::db()->is_strict() );
    }

    private function createCompletedVisitWithPrescription( int $clinicId, int $locationId, int $clinicianId, int $doctorUserId, int $patientId ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert(
            $wpdb->prefix . 'cpms_visits',
            [
                'clinic_id'     => $clinicId,
                'location_id'   => $locationId,
                'clinician_id'  => $clinicianId,
                'patient_id'    => $patientId,
                'source'        => 'walk_in',
                'status'        => 'in_consultation',
                'visit_date'    => gmdate( 'Y-m-d' ),
                'check_in_at'   => $now,
                'waiting_since' => $now,
                'called_at'     => $now,
                'active'        => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $visitId );

        App::replaceExplicitScope( ClinicScope::forClinic( $clinicId, $locationId ) );
        try {
            App::clinicalService()->addNote(
                $doctorUserId,
                $visitId,
                [
                    'category'     => 'chief_complaint',
                    'visibility'   => 'patient_visible',
                    'content_text' => 'Visible complaint',
                ]
            );
            $rx = App::clinicalService()->createPrescription(
                $doctorUserId,
                $visitId,
                [
                    'is_patient_visible' => 1,
                    'items'              => [
                        [
                            'generic_name' => 'Acetaminophen',
                            'dose'         => '500mg',
                            'frequency'    => 'BID',
                        ],
                    ],
                ]
            );
            App::clinicalService()->finalizePrescription( $doctorUserId, (int) $rx['id'] );
            App::clinicalService()->completeConsultation( $doctorUserId, $visitId );
        } finally {
            App::resetScope();
        }

        return $visitId;
    }

    /**
     * @param list<string> $capabilities
     */
    private function insertActiveCustomRoleDef( int $clinicId, string $roleKey, array $capabilities ): void {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $defs   = $wpdb->prefix . 'cpms_custom_role_defs';
        $defCap = $wpdb->prefix . 'cpms_custom_role_capabilities';

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $roleKey,
                'active',
                1,
                $now,
                $now
            )
        );
        $defId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $defId );

        foreach ( $capabilities as $capability ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$defCap} (role_def_id, capability, created_at) VALUES (%d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $defId,
                    $capability,
                    $now
                )
            );
        }
    }

    private function insertLocation( int $clinicId, string $name ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [
                'clinic_id'  => $clinicId,
                'name'       => $name,
                'slug'       => 'sb-loc-' . bin2hex( random_bytes( 3 ) ),
                'timezone'   => 'UTC',
                'is_primary' => 1,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function insertClinician( int $clinicId, int $wpUserId, string $name ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            [
                'clinic_id'  => $clinicId,
                'full_name'  => $name,
                'wp_user_id' => $wpUserId,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function insertSlot( int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $capacity ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert(
            $wpdb->prefix . 'cpms_schedule_slots',
            [
                'clinic_id'      => $clinicId,
                'location_id'    => $locationId,
                'clinician_id'   => $clinicianId,
                'slot_date'      => $date,
                'slot_time'      => $time,
                'duration_min'   => 20,
                'capacity'       => $capacity,
                'booked_count'   => 0,
                'held_count'     => 0,
                'is_open'        => 1,
                'generated_from' => 'manual',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function insertConfirmedAppointment(
        int $clinicId,
        int $locationId,
        int $patientId,
        int $clinicianId,
        int $slotId,
        string $date,
        string $time
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert(
            $wpdb->prefix . 'cpms_appointments',
            [
                'clinic_id'         => $clinicId,
                'location_id'       => $locationId,
                'reference_code'    => 'sb-' . bin2hex( random_bytes( 3 ) ),
                'patient_id'        => $patientId,
                'clinician_id'      => $clinicianId,
                'slot_id'           => $slotId,
                'slot_date'         => $date,
                'slot_time'         => $time,
                'duration_min'      => 20,
                'slot_end_time'     => $time,
                'status'            => 'confirmed',
                'is_walkin_express' => 0,
                'confirmed_at'      => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient( int $clinicId, string $mrn, string $firstName, string $lastName, string $mobile, string $status ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patients',
            [
                'clinic_id'  => $clinicId,
                'mrn'        => $mrn,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'mobile'     => $mobile,
                'status'     => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertPatientLink( int $clinicId, int $patientId, int $userId, string $mobile ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_patient_user_links',
            [
                'clinic_id'      => $clinicId,
                'patient_id'     => $patientId,
                'wp_user_id'     => $userId,
                'mobile_at_link' => $mobile,
                'is_primary'     => 1,
                'linked_at'      => $now,
            ]
        );

        return $db->wpdb_last_insert_id();
    }

    private function makeUser( string $login, string $role ): int {
        $userId = (int) wp_create_user( $login, 'pass-12345', $login . '@test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $userId;
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function dispatch( string $method, string $route, array $params, int $userId, array $headers = [] ): WP_REST_Response {
        PatientTwoProofTestHelper::acting_as( $userId );
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        foreach ( $headers as $name => $val ) {
            $request->set_header( $name, $val );
        }

        return rest_do_request( $request );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchUpload( string $route, int $userId, array $params = [] ): WP_REST_Response {
        PatientTwoProofTestHelper::acting_as( $userId );
        $tmp = tempnam( sys_get_temp_dir(), 'sb_pdf_' );
        self::assertIsString( $tmp );
        file_put_contents( $tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" );

        $request = new WP_REST_Request( 'POST', $route );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'category', 'document' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $request->set_file_params(
            [
                'file' => [
                    'name'     => 'sliceb-test.pdf',
                    'tmp_name' => $tmp,
                    'size'     => (int) filesize( $tmp ),
                    'error'    => UPLOAD_ERR_OK,
                    'type'     => 'application/pdf',
                ],
            ]
        );

        return rest_do_request( $request );
    }
}

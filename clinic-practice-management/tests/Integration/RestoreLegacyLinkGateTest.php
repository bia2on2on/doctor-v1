<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Application\Backup\RestoreIdentityCleanup;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Infrastructure\Repository\PatientLoginRepository;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * Integration tests for RESTORE-SLICE1: legacy-link gate enforcement and
 * two-factor re-verification contracts for the finite PatientAccess primitives,
 * REST routes, portal/shortcode views, and fixtures listed in `T1`-`T8` below
 * (not an exhaustive sweep of all patient surfaces).
 *
 * Verifies for those tested surfaces that durable legacy edges (`cpms_patient_user_links`,
 * `patients.identity_id`, `cpms_patient_identity_links`) and restored/unproved bindings
 * receive `LEVEL_NONE` / `403` without a `cpms_patient_logins` active binding and valid
 * current-session proofs (`cpms_mobile_proofs` + `cpms_binding_proofs`).
 */
final class RestoreLegacyLinkGateTest extends WP_UnitTestCase {
    use SignedExpirationFixture;

    private const NS = '/clinic/v1';

    private static int $seq = 0;

    private int $orgId;
    private int $clinicA;
    private int $clinicB;
    private int $locationA;
    private int $locationB;
    private int $doctorUser;
    private int $clinicianA;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        PatientTwoProofTestHelper::reset();
        App::resetScope();

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
            $this->locationA = $this->insertLocation( $this->clinicA, 'RLG Loc A ' . $this->nextUniqueToken() );
        }

        $clinicSlugB = 'rlg-clinic-b-' . $this->nextUniqueToken();
        $db->insert(
            'cpms_clinics',
            [
                'organization_id' => $this->orgId,
                'name'            => 'RLG Clinic B ' . $clinicSlugB,
                'slug'            => $clinicSlugB,
                'timezone'        => 'UTC',
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $this->clinicB   = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $this->clinicB );
        $this->locationB = $this->insertLocation( $this->clinicB, 'RLG Loc B ' . $this->nextUniqueToken() );

        App::settingsFactory()->forClinic( $this->clinicA )->set( 'public.listing_opt_in', true );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.min_lead_hours', 2 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.max_future_days', 60 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.hold_ttl_sec', 600 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.cancel_deadline_hours', 24 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'booking.reschedule_deadline_hours', 24 );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'clinical.require_chief_complaint', false );
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'queue.auto_enqueue', true );

        App::settingsFactory()->forClinic( $this->clinicB )->set( 'public.listing_opt_in', true );
        App::settingsFactory()->forClinic( $this->clinicB )->set( 'booking.min_lead_hours', 2 );
        App::settingsFactory()->forClinic( $this->clinicB )->set( 'booking.max_future_days', 60 );

        $this->doctorUser = $this->makeUser( 'rlg_doc_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_DOCTOR );
        cpms_test_seed_membership( $this->doctorUser, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR );
        $this->clinicianA = $this->insertClinician( $this->clinicA, $this->doctorUser, 'Dr RLG ' . $this->nextUniqueToken() );

        $this->installSignedExpiration( time() + 30 * 86400 );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        PatientTwoProofTestHelper::reset();
        remove_all_filters( 'cpms_license_public_key' );
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    // =========================================================================
    // T1: Legacy link alone has no PatientAccess level or proved IDs
    // =========================================================================

    public function testLegacyLinkAloneHasNoPatientAccessLevelOrProvedIds(): void {
        $access = App::patient_access();

        // Case 1a: cpms_patient_user_links ONLY (no identity edges, no cpms_patient_logins rows).
        $mobA     = $this->nextUniqueMobile();
        $nidA     = $this->nextUniqueValidNationalId();
        $patientA = $this->insertPatient( $this->clinicA, 'MR-RLG-T1A-' . $this->nextUniqueToken(), 'LegacyOnly', 'PatientA', $mobA, $nidA, '1990-01-01' );
        $userA    = $this->makeUser( 'rlg_t1a_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $linkA    = $this->insertLegacyUserLink( $this->clinicA, $patientA, $userA, $mobA );
        $tokenA   = WP_Session_Tokens::get_instance( $userA )->create( time() + 3600 );
        $now      = time();

        $this->assertNoPatientAccess( $access, $userA, $this->clinicA, $patientA, $linkA, $this->clinicB, $now, $tokenA );

        // Case 1b: cpms_patient_user_links + identity_id + cpms_patient_identity_links (still no cpms_patient_logins binding).
        $mobB     = $this->nextUniqueMobile();
        $nidB     = $this->nextUniqueValidNationalId();
        $patientB = $this->insertPatient( $this->clinicA, 'MR-RLG-T1B-' . $this->nextUniqueToken(), 'LegacyEdges', 'PatientB', $mobB, $nidB, '1991-02-02' );
        $userB    = $this->makeUser( 'rlg_t1b_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $linkB    = $this->insertLegacyUserLink( $this->clinicA, $patientB, $userB, $mobB );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientB, $userB, $mobB );
        $tokenB = WP_Session_Tokens::get_instance( $userB )->create( time() + 3600 );

        $this->assertNoPatientAccess( $access, $userB, $this->clinicA, $patientB, $linkB, $this->clinicB, $now, $tokenB );

        // Case 1c: Previously active user revoked by RestoreIdentityCleanup::revoke() (updates state='closed', live_key=NULL on every patient_logins row where state<>'closed', including kind='account' as well as kind='binding', then destroys WP sessions).
        $mobC     = $this->nextUniqueMobile();
        $nidC     = $this->nextUniqueValidNationalId();
        $patientC = $this->insertPatient( $this->clinicA, 'MR-RLG-T1C-' . $this->nextUniqueToken(), 'Restored', 'PatientC', $mobC, $nidC, '1992-03-03' );
        $userC    = $this->makeUser( 'rlg_t1c_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $linkC    = $this->insertLegacyUserLink( $this->clinicA, $patientC, $userC, $mobC );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientC, $userC, $mobC );
        $logins = new PatientLoginRepository( App::db() );
        $acctC  = $logins->insert_pending_account( $userC, $mobC, 'Restored', 'PatientC' );
        $logins->activate_account_from_pending( $acctC, $userC );
        $logins->upsert_active_binding( $userC, $this->clinicA, $patientC, App::db()->nowUtcSql() );
        $oldTokenC = ( new PatientSessionProofStore() )->issue_non_persistent_auth_cookie( $userC );
        self::assertNotSame( '', $oldTokenC );

        ( new RestoreIdentityCleanup( App::db() ) )->revoke();
        self::assertFalse( WP_Session_Tokens::get_instance( $userC )->verify( $oldTokenC ) );
        self::assertFalse( WP_Session_Tokens::get_instance( $userB )->verify( $tokenB ) );
        $freshTokenC = WP_Session_Tokens::get_instance( $userC )->create( time() + 3600 );
        $this->assertNoPatientAccess( $access, $userC, $this->clinicA, $patientC, $linkC, $this->clinicB, $now, $freshTokenC );

        // Positive control (non-tautological): same legacy-edges fixture (userB) in a fresh post-restore session after real OTP + NID promotion via /patient/link-record.
        $freshTokenB = WP_Session_Tokens::get_instance( $userB )->create( time() + 3600 );
        $logins->insert_pending_account( $userB, $mobB, 'LegacyEdges', 'PatientB' );
        $this->seedOtp( $this->clinicA, $mobB, '415263', OtpService::PURPOSE_VERIFY_MOBILE );
        $promoteResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobB,
                'code'        => '415263',
                'national_id' => $nidB,
            ],
            $userB,
            $freshTokenB
        );
        self::assertSame( 200, $promoteResp->get_status() );
        self::assertSame( PatientAccess::LEVEL_FULL, $access->level( $userB, $patientB, $this->clinicA, $now, $freshTokenB ) );
        self::assertSame( [ $patientB ], $access->proved_patient_ids( $userB, $this->clinicA, $now, $freshTokenB ) );

        $this->withSessionCookie(
            $userB,
            $freshTokenB,
            function () use ( $access, $userB, $patientB, $linkB ): void {
                self::assertTrue( $access->can_access_patient( $userB, $this->clinicA, $patientB ) );
                self::assertTrue( $access->can_access_clinic( $userB, $this->clinicA ) );
                self::assertTrue( $access->can_enroll_in_clinic( $userB, $this->clinicB ) );
                $authOk = $access->authorize_request( $userB, $this->clinicA, $patientB, $linkB, PatientAccess::MODE_CLINICAL );
                self::assertTrue( $authOk['allowed'] );
                self::assertSame( 200, $authOk['http_status'] );
            }
        );
    }

    // =========================================================================
    // T2: Legacy link cannot return profile or clinical data
    // =========================================================================

    public function testLegacyLinkCannotReturnProfileOrClinicalData(): void {
        $mob       = $this->nextUniqueMobile();
        $nid       = $this->nextUniqueValidNationalId();
        $mrn       = 'MR-RLG-T2-' . $this->nextUniqueToken();
        $firstName = 'LegacyProfileFirst_' . $this->nextUniqueToken();
        $lastName  = 'LegacyProfileLast_' . $this->nextUniqueToken();
        $patientId = $this->insertPatient( $this->clinicA, $mrn, $firstName, $lastName, $mob, $nid, '1989-05-12' );
        $userId    = $this->makeUser( 'rlg_t2_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientId, $userId, $mob );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientId, $userId, $mob );

        $complaintText = 'VisibleComplaintSecret_' . $this->nextUniqueToken();
        $rxDrug        = 'AcetaminophenSecret_' . $this->nextUniqueToken();
        $visitId       = $this->createCompletedVisitWithPrescription( $patientId, $complaintText, $rxDrug );

        $token = WP_Session_Tokens::get_instance( $userId )->create( time() + 3600 );

        $routes = [
            [ 'GET', self::NS . '/patient/me', [] ],
            [ 'PUT', self::NS . '/patient/me', [ 'first_name' => 'TamperedName' ] ],
            [ 'GET', self::NS . '/patient/my-records', [] ],
            [ 'POST', self::NS . '/patient/enroll', [ 'clinic_id' => $this->clinicB ] ],
            [ 'GET', self::NS . '/visits', [] ],
            [ 'GET', self::NS . '/visits/' . $visitId, [] ],
            [ 'GET', self::NS . '/prescriptions', [] ],
        ];

        $protectedNeedles = [ $firstName, $lastName, $mrn, $mob, $nid, $complaintText, $rxDrug ];
        foreach ( $routes as $spec ) {
            $resp = $this->dispatchRestWithSessionToken( $spec[0], $spec[1], $spec[2], $userId, $token );
            $this->assertProofFailedResponse( $resp, $spec[0] . ' ' . $spec[1] );
            $this->assertResponseExcludesStrings( $resp->get_data(), $protectedNeedles, $spec[0] . ' ' . $spec[1] );
        }

        $storedFirst = (string) App::db()->fetchValue(
            'SELECT first_name FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d',
            [ $patientId ]
        );
        self::assertSame( $firstName, $storedFirst );

        // Positive control: promote legacy link via fresh OTP + matching NID, then verify GET /patient/me succeeds with expected first_name and assertNoNationalIdLeak(), while GET /patient/my-records, GET /visits, GET /visits/{id}, and GET /prescriptions return 200 with expected counts or seeded text.
        ( new PatientLoginRepository( App::db() ) )->insert_pending_account( $userId, $mob, $firstName, $lastName );
        $this->seedOtp( $this->clinicA, $mob, '516273', OtpService::PURPOSE_VERIFY_MOBILE );
        $linkResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'code'        => '516273',
                'national_id' => $nid,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $linkResp->get_status() );

        $meOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $userId, $token );
        self::assertSame( 200, $meOk->get_status() );
        $meData = $meOk->get_data();
        self::assertIsArray( $meData );
        self::assertArrayHasKey( 'data', $meData );
        self::assertIsArray( $meData['data'] );
        self::assertArrayHasKey( 'first_name', $meData['data'] );
        self::assertSame( $firstName, $meData['data']['first_name'] );
        $this->assertNoNationalIdLeak( $meData, $nid, 'GET /patient/me positive control' );

        $recordsOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/my-records', [], $userId, $token );
        self::assertSame( 200, $recordsOk->get_status() );
        $recordsData = $recordsOk->get_data();
        self::assertIsArray( $recordsData );
        self::assertArrayHasKey( 'data', $recordsData );
        self::assertCount( 1, $recordsData['data'] );

        $visitsOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/visits', [], $userId, $token );
        self::assertSame( 200, $visitsOk->get_status() );
        $visitsData = $visitsOk->get_data();
        self::assertIsArray( $visitsData );
        self::assertArrayHasKey( 'data', $visitsData );
        self::assertArrayHasKey( 'visits', $visitsData['data'] );
        self::assertCount( 1, $visitsData['data']['visits'] );

        $visitDetailOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/visits/' . $visitId, [], $userId, $token );
        self::assertSame( 200, $visitDetailOk->get_status() );
        self::assertStringContainsString( $complaintText, (string) wp_json_encode( $visitDetailOk->get_data(), JSON_UNESCAPED_UNICODE ) );

        $rxOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/prescriptions', [], $userId, $token );
        self::assertSame( 200, $rxOk->get_status() );
        self::assertStringContainsString( $rxDrug, (string) wp_json_encode( $rxOk->get_data(), JSON_UNESCAPED_UNICODE ) );
    }

    // =========================================================================
    // T3: Legacy link cannot return files or portal appointments
    // =========================================================================

    public function testLegacyLinkCannotReturnFilesOrPortalAppointments(): void {
        $mob       = $this->nextUniqueMobile();
        $nid       = $this->nextUniqueValidNationalId();
        $mrn       = 'MR-RLG-T3-' . $this->nextUniqueToken();
        $firstName = 'LegacyFileFirst_' . $this->nextUniqueToken();
        $lastName  = 'LegacyFileLast_' . $this->nextUniqueToken();
        $patientId = $this->insertPatient( $this->clinicA, $mrn, $firstName, $lastName, $mob, $nid, '1988-07-21' );
        $userId    = $this->makeUser( 'rlg_t3_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientId, $userId, $mob );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientId, $userId, $mob );

        $fileMarker   = 'RLG-SECRET-FILE-' . $this->nextUniqueToken();
        $fileName     = 'rlg-secret-' . $this->nextUniqueToken() . '.pdf';
        $uploadedFile = $this->uploadPatientVisiblePdf( $patientId, $fileName, $fileMarker );
        $fileId       = (int) $uploadedFile['id'];
        self::assertGreaterThan( 0, $fileId );

        $futureDate = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slotId     = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $futureDate, '09:30:00', 3 );
        $apptRef    = 'rlg-ref-' . $this->nextUniqueToken();
        $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $patientId, $this->clinicianA, $slotId, $futureDate, '09:30:00', $apptRef );

        $token = WP_Session_Tokens::get_instance( $userId )->create( time() + 3600 );

        $listFilesResp = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patients/' . $patientId . '/files', [], $userId, $token );
        $this->assertProofFailedResponse( $listFilesResp, 'GET /patients/{id}/files' );
        $this->assertResponseExcludesStrings( $listFilesResp->get_data(), [ $fileName, $fileMarker, $firstName, $mrn ], 'GET files' );

        $uploadResp = $this->dispatchPatientUploadWithSessionToken( self::NS . '/patients/' . $patientId . '/files', $userId, $token );
        $this->assertProofFailedResponse( $uploadResp, 'POST /patients/{id}/files' );

        $streamResp = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $userId, $token );
        $this->assertProofFailedResponse( $streamResp, 'GET /files/{id}/stream' );
        $this->assertResponseExcludesStrings( $streamResp->get_data(), [ '%PDF', $fileMarker, $fileName ], 'GET stream' );

        $mineResp = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/appointments/mine', [ 'from' => $futureDate, 'to' => $futureDate ], $userId, $token );
        $this->assertProofFailedResponse( $mineResp, 'GET /appointments/mine' );
        $this->assertResponseExcludesStrings( $mineResp->get_data(), [ $apptRef, $firstName, $lastName ], 'GET appointments/mine' );

        $portalHtmlLegacy = $this->renderPortalAs( $userId, $token );
        foreach ( [ $apptRef, $fileName, $firstName, $lastName, $mrn, $nid ] as $secret ) {
            self::assertStringNotContainsString( $secret, $portalHtmlLegacy );
        }
        $this->withSessionCookie(
            $userId,
            $token,
            function () use ( $userId, $futureDate ): void {
                self::assertSame( [], App::bookingService()->listMine( $userId, $futureDate, $futureDate, $this->clinicA ) );
            }
        );

        // Positive control: promote legacy link via fresh OTP + matching NID, then verify files, stream, and portal appointments work.
        ( new PatientLoginRepository( App::db() ) )->insert_pending_account( $userId, $mob, $firstName, $lastName );
        $this->seedOtp( $this->clinicA, $mob, '617283', OtpService::PURPOSE_VERIFY_MOBILE );
        $promoteResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'code'        => '617283',
                'national_id' => $nid,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $promoteResp->get_status() );

        $listOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patients/' . $patientId . '/files', [], $userId, $token );
        self::assertSame( 200, $listOk->get_status() );
        self::assertStringContainsString( $fileName, (string) wp_json_encode( $listOk->get_data(), JSON_UNESCAPED_UNICODE ) );

        $streamOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/files/' . $fileId . '/stream', [], $userId, $token );
        self::assertSame( 200, $streamOk->get_status() );
        self::assertStringContainsString( $fileMarker, (string) $streamOk->get_data() );

        $mineOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/appointments/mine', [ 'from' => $futureDate, 'to' => $futureDate ], $userId, $token );
        self::assertSame( 200, $mineOk->get_status() );
        self::assertStringContainsString( $apptRef, (string) wp_json_encode( $mineOk->get_data(), JSON_UNESCAPED_UNICODE ) );

        $portalHtmlProved = $this->renderPortalAs( $userId, $token );
        self::assertStringContainsString( $apptRef, $portalHtmlProved );
        self::assertStringContainsString( $fileName, $portalHtmlProved );
        self::assertStringNotContainsString( $nid, $portalHtmlProved );
    }

    // =========================================================================
    // T4: Legacy link cannot return booking chooser names or notifications
    // =========================================================================

    public function testLegacyLinkCannotReturnBookingChooserNamesOrNotifications(): void {
        $mob1   = $this->nextUniqueMobile();
        $mob2   = $this->nextUniqueMobile();
        $nid1   = $this->nextUniqueValidNationalId();
        $nid2   = $this->nextUniqueValidNationalId();
        $name1  = 'ChooserLegacyOne_' . $this->nextUniqueToken();
        $name2  = 'ChooserLegacyTwo_' . $this->nextUniqueToken();
        $p1     = $this->insertPatient( $this->clinicA, 'MR-RLG-T4A-' . $this->nextUniqueToken(), $name1, 'Alpha', $mob1, $nid1, '1987-04-10' );
        $p2     = $this->insertPatient( $this->clinicA, 'MR-RLG-T4B-' . $this->nextUniqueToken(), $name2, 'Beta', $mob2, $nid2, '1993-08-15' );
        $userId = $this->makeUser( 'rlg_t4_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );

        $this->insertLegacyUserLink( $this->clinicA, $p1, $userId, $mob1, 1 );
        $this->insertLegacyUserLink( $this->clinicA, $p2, $userId, $mob2, 0 );
        $this->insertLegacyIdentityEdges( $this->orgId, $p1, $userId, $mob1 );
        $this->insertLegacyIdentityEdges( $this->orgId, $p2, $userId, $mob2 );

        $notifDocSecret = 'DrNotifSecret_' . $this->nextUniqueToken();
        $notifId        = (int) App::notificationService()->publishToPatient(
            $this->clinicA,
            $p1,
            'appt_reminder',
            [
                'patient_name'     => $name1,
                'doctor_name'      => $notifDocSecret,
                'appointment_date' => '1405-09-10',
                'appointment_time' => '10:30',
                'clinic_name'      => 'RLG Clinic',
            ],
            'rlg:notif:' . $this->nextUniqueToken()
        );
        self::assertGreaterThan( 0, $notifId );

        $token = WP_Session_Tokens::get_instance( $userId )->create( time() + 3600 );

        // 1) Booking chooser on both shortcode and patient-portal surfaces must hide names and chooser UI.
        $this->withSessionCookie(
            $userId,
            $token,
            function () use ( $p1, $p2, $name1, $name2 ): void {
                $scHtml     = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
                $portalHtml = PublicBookingShortcode::render_for_patient_portal( $this->clinicA );
                foreach ( [ $scHtml, $portalHtml ] as $html ) {
                    self::assertStringNotContainsString( 'data-role="patient-chooser"', $html );
                    self::assertStringNotContainsString( $name1, $html );
                    self::assertStringNotContainsString( $name2, $html );
                    self::assertStringNotContainsString( 'data-patient-id="' . $p1 . '"', $html );
                    self::assertStringNotContainsString( 'data-patient-id="' . $p2 . '"', $html );
                }
            }
        );

        // 2) Notifications REST endpoints (inbox, rt with/without ETag, mark read) return 403 + CLINIC_PATIENT_PROOF_FAILED; inbox and unconditioned rt exclude $name1/$notifDocSecret, and read_at remains NULL.
        $inboxResp = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/notifications', [], $userId, $token );
        $this->assertProofFailedResponse( $inboxResp, 'GET /notifications' );
        $this->assertResponseExcludesStrings( $inboxResp->get_data(), [ $name1, $notifDocSecret ], 'GET /notifications' );

        $rtResp = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/rt/notifications', [], $userId, $token );
        $this->assertProofFailedResponse( $rtResp, 'GET /rt/notifications' );
        $this->assertResponseExcludesStrings( $rtResp->get_data(), [ $name1, $notifDocSecret ], 'GET /rt/notifications' );

        $rtEtagResp = $this->dispatchRestWithSessionToken(
            'GET',
            self::NS . '/rt/notifications',
            [],
            $userId,
            $token,
            [ 'If-None-Match' => '"' . $notifId . '"' ]
        );
        $this->assertProofFailedResponse( $rtEtagResp, 'GET /rt/notifications If-None-Match' );

        $readResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/notifications/read',
            [ 'ids' => [ $notifId ], 'all' => true ],
            $userId,
            $token
        );
        $this->assertProofFailedResponse( $readResp, 'POST /notifications/read' );

        $readAtBefore = App::db()->fetchValue(
            'SELECT read_at FROM ' . App::db()->table( 'cpms_notifications' ) . ' WHERE id = %d',
            [ $notifId ]
        );
        self::assertNull( $readAtBefore );

        // Positive control: promote both legacy records via fresh OTP + matching NID in the same live session.
        ( new PatientLoginRepository( App::db() ) )->insert_pending_account( $userId, $mob1, $name1, 'Alpha' );
        $this->seedOtp( $this->clinicA, $mob1, '135791', OtpService::PURPOSE_VERIFY_MOBILE );
        $p1Resp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob1,
                'code'        => '135791',
                'national_id' => $nid1,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $p1Resp->get_status() );

        $this->seedOtp( $this->clinicA, $mob2, '246802', OtpService::PURPOSE_VERIFY_MOBILE );
        $p2Resp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob2,
                'code'        => '246802',
                'national_id' => $nid2,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $p2Resp->get_status() );

        $this->withSessionCookie(
            $userId,
            $token,
            function () use ( $p1, $p2, $name1, $name2 ): void {
                $scOk     = do_shortcode( '[cpms_public_booking clinic_id="' . $this->clinicA . '"]' );
                $portalOk = PublicBookingShortcode::render_for_patient_portal( $this->clinicA );
                foreach ( [ $scOk, $portalOk ] as $html ) {
                    self::assertStringContainsString( 'data-role="patient-chooser"', $html );
                    self::assertStringContainsString( 'data-patient-id="' . $p1 . '"', $html );
                    self::assertStringContainsString( 'data-patient-id="' . $p2 . '"', $html );
                    self::assertStringContainsString( $name1, $html );
                    self::assertStringContainsString( $name2, $html );
                }
            }
        );

        $inboxOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/notifications', [], $userId, $token );
        self::assertSame( 200, $inboxOk->get_status() );
        $inboxData = $inboxOk->get_data();
        self::assertIsArray( $inboxData );
        self::assertArrayHasKey( 'data', $inboxData );
        self::assertArrayHasKey( 'notifications', $inboxData['data'] );
        self::assertCount( 1, $inboxData['data']['notifications'] );

        $rtOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/rt/notifications', [], $userId, $token );
        self::assertSame( 200, $rtOk->get_status() );
        $rtHeaders = $rtOk->get_headers();
        self::assertArrayHasKey( 'ETag', $rtHeaders );
        $etag  = (string) $rtHeaders['ETag'];
        $rt304 = $this->dispatchRestWithSessionToken(
            'GET',
            self::NS . '/rt/notifications',
            [],
            $userId,
            $token,
            [ 'If-None-Match' => $etag ]
        );
        self::assertSame( 304, $rt304->get_status() );

        $readOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/notifications/read',
            [ 'ids' => [ $notifId ] ],
            $userId,
            $token
        );
        self::assertSame( 200, $readOk->get_status() );
        $readOkData = $readOk->get_data();
        self::assertIsArray( $readOkData );
        self::assertArrayHasKey( 'data', $readOkData );
        self::assertArrayHasKey( 'marked', $readOkData['data'] );
        self::assertSame( 1, (int) $readOkData['data']['marked'] );
    }

    // =========================================================================
    // T5: Legacy link cannot book, cancel, or reschedule
    // =========================================================================

    public function testLegacyLinkCannotBookCancelOrReschedule(): void {
        $mob       = $this->nextUniqueMobile();
        $nid       = $this->nextUniqueValidNationalId();
        $patientId = $this->insertPatient( $this->clinicA, 'MR-RLG-T5-' . $this->nextUniqueToken(), 'LegacyBook', 'Patient', $mob, $nid, '1990-06-15' );
        $userId    = $this->makeUser( 'rlg_t5_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientId, $userId, $mob );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientId, $userId, $mob );

        $date1    = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $date2    = gmdate( 'Y-m-d', time() + 5 * 86400 );
        $slot1    = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date1, '10:00:00', 3 );
        $slot2    = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date2, '11:00:00', 3 );
        $slotLive = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $date2, '12:00:00', 3 );
        $liveAppt = $this->insertConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $patientId,
            $this->clinicianA,
            $slotLive,
            $date2,
            '12:00:00',
            'rlg-t5-' . $this->nextUniqueToken()
        );

        $db          = App::db();
        $holdsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_slot_holds' ) . ' WHERE clinic_id = %d', [ $this->clinicA ] );
        $apptsBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_appointments' ) . ' WHERE patient_id = %d', [ $patientId ] );

        $token = WP_Session_Tokens::get_instance( $userId )->create( time() + 3600 );

        $endpoints = [
            [ 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date1, 'slot_time' => '10:00:00', 'slot_id' => $slot1 ], [] ],
            [ 'POST', self::NS . '/booking/confirm', [ 'hold_token' => 'nonexistent-hold-token' ], [ 'Idempotency-Key' => wp_generate_uuid4() ] ],
            [ 'GET', self::NS . '/booking/resume', [ 'hold_token' => 'nonexistent-hold-token' ], [] ],
            [ 'GET', self::NS . '/appointments/' . $liveAppt . '/reschedule-options', [], [] ],
            [ 'POST', self::NS . '/appointments/' . $liveAppt . '/reschedule', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $date2, 'slot_time' => '11:00:00', 'slot_id' => $slot2 ], [ 'Idempotency-Key' => wp_generate_uuid4() ] ],
            [ 'POST', self::NS . '/appointments/' . $liveAppt . '/cancel', [ 'reason' => 'legacy-attempt' ], [] ],
        ];

        foreach ( $endpoints as $spec ) {
            $resp = $this->dispatchRestWithSessionToken( $spec[0], $spec[1], $spec[2], $userId, $token, $spec[3] );
            $this->assertProofFailedResponse( $resp, $spec[0] . ' ' . $spec[1] );
        }

        self::assertSame( $holdsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_slot_holds' ) . ' WHERE clinic_id = %d', [ $this->clinicA ] ) );
        self::assertSame( $apptsBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_appointments' ) . ' WHERE patient_id = %d', [ $patientId ] ) );
        $apptRow = $db->fetchRow( 'SELECT status, rescheduled_to FROM ' . $db->table( 'cpms_appointments' ) . ' WHERE id = %d', [ $liveAppt ] );
        self::assertIsArray( $apptRow );
        self::assertSame( 'confirmed', $apptRow['status'] );
        self::assertNull( $apptRow['rescheduled_to'] );

        // Positive control: promote via fresh OTP + matching NID, then run hold -> resume -> confirm -> reschedule-options -> reschedule -> cancel.
        ( new PatientLoginRepository( $db ) )->insert_pending_account( $userId, $mob, 'LegacyBook', 'Patient' );
        $this->seedOtp( $this->clinicA, $mob, '321654', OtpService::PURPOSE_VERIFY_MOBILE );
        $promoteResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'code'        => '321654',
                'national_id' => $nid,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $promoteResp->get_status() );

        $holdOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/booking/hold',
            [
                'clinician_id' => $this->clinicianA,
                'slot_date'    => $date1,
                'slot_time'    => '10:00:00',
                'slot_id'      => $slot1,
            ],
            $userId,
            $token
        );
        self::assertSame( 200, $holdOk->get_status() );
        $holdData = $holdOk->get_data();
        self::assertIsArray( $holdData );
        self::assertArrayHasKey( 'data', $holdData );
        self::assertArrayHasKey( 'hold_token', $holdData['data'] );
        $holdToken = (string) $holdData['data']['hold_token'];
        self::assertNotSame( '', $holdToken );

        $resumeOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/booking/resume', [ 'hold_token' => $holdToken ], $userId, $token );
        self::assertSame( 200, $resumeOk->get_status() );

        $confirmOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/booking/confirm',
            [ 'hold_token' => $holdToken ],
            $userId,
            $token,
            [ 'Idempotency-Key' => wp_generate_uuid4() ]
        );
        self::assertSame( 200, $confirmOk->get_status() );
        $confirmData = $confirmOk->get_data();
        self::assertIsArray( $confirmData );
        self::assertArrayHasKey( 'data', $confirmData );
        self::assertArrayHasKey( 'appointment_id', $confirmData['data'] );
        $bookedId = (int) $confirmData['data']['appointment_id'];
        self::assertGreaterThan( 0, $bookedId );

        $optOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/appointments/' . $bookedId . '/reschedule-options', [], $userId, $token );
        self::assertSame( 200, $optOk->get_status() );

        $reschedOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/appointments/' . $bookedId . '/reschedule',
            [
                'clinician_id' => $this->clinicianA,
                'slot_date'    => $date2,
                'slot_time'    => '11:00:00',
                'slot_id'      => $slot2,
            ],
            $userId,
            $token,
            [ 'Idempotency-Key' => wp_generate_uuid4() ]
        );
        self::assertSame( 200, $reschedOk->get_status() );
        $reschedData = $reschedOk->get_data();
        self::assertIsArray( $reschedData );
        self::assertArrayHasKey( 'data', $reschedData );
        self::assertArrayHasKey( 'appointment_id', $reschedData['data'] );
        $rescheduledId = (int) $reschedData['data']['appointment_id'];
        self::assertGreaterThan( 0, $rescheduledId );

        $cancelOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/appointments/' . $rescheduledId . '/cancel',
            [ 'reason' => 'positive-control' ],
            $userId,
            $token
        );
        self::assertSame( 200, $cancelOk->get_status() );
        $cancelData = $cancelOk->get_data();
        self::assertIsArray( $cancelData );
        self::assertArrayHasKey( 'data', $cancelData );
        self::assertArrayHasKey( 'status', $cancelData['data'] );
        self::assertSame( 'cancelled_by_patient', $cancelData['data']['status'] );
    }

    // =========================================================================
    // T6: Active binding without current-session proof cannot access patient data
    // =========================================================================

    public function testActiveBindingWithoutCurrentSessionProofCannotAccessPatientData(): void {
        $mob       = $this->nextUniqueMobile();
        $nid       = $this->nextUniqueValidNationalId();
        $mrn       = 'MR-RLG-T6-' . $this->nextUniqueToken();
        $firstName = 'ActiveNoProofFirst_' . $this->nextUniqueToken();
        $lastName  = 'ActiveNoProofLast_' . $this->nextUniqueToken();
        $patientId = $this->insertPatient( $this->clinicA, $mrn, $firstName, $lastName, $mob, $nid, '1991-09-09' );
        $userId    = $this->makeUser( 'rlg_t6_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientId, $userId, $mob );
        $this->insertLegacyIdentityEdges( $this->orgId, $patientId, $userId, $mob );

        $db     = App::db();
        $logins = new PatientLoginRepository( $db );
        $acctId = $logins->insert_pending_account( $userId, $mob, $firstName, $lastName );
        $logins->activate_account_from_pending( $acctId, $userId, $firstName, $lastName );
        $binding = $logins->upsert_active_binding( $userId, $this->clinicA, $patientId, $db->nowUtcSql() );

        $complaint = 'ActiveNoProofComplaint_' . $this->nextUniqueToken();
        $rxDrug    = 'ActiveNoProofDrug_' . $this->nextUniqueToken();
        $this->createCompletedVisitWithPrescription( $patientId, $complaint, $rxDrug );

        $futureDate = gmdate( 'Y-m-d', time() + 4 * 86400 );
        $slot1      = $this->insertSlot( $this->clinicA, $this->locationA, $this->clinicianA, $futureDate, '14:00:00', 3 );
        $apptRef    = 'rlg-t6-' . $this->nextUniqueToken();
        $this->insertConfirmedAppointment( $this->clinicA, $this->locationA, $patientId, $this->clinicianA, $slot1, $futureDate, '14:00:00', $apptRef );
        App::notificationService()->publishToPatient(
            $this->clinicA,
            $patientId,
            'appt_reminder',
            [ 'patient_name' => $firstName, 'doctor_name' => 'Dr T6', 'appointment_date' => '1405-09-12', 'appointment_time' => '14:00', 'clinic_name' => 'RLG' ],
            'rlg:t6:' . $this->nextUniqueToken()
        );

        $access = App::patient_access();
        $now    = time();

        // Sub-case A: Plain WP session token (no mobile proof, no binding proof).
        $plainToken = WP_Session_Tokens::get_instance( $userId )->create( $now + 3600 );
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, $patientId, $this->clinicA, $now, $plainToken ) );
        self::assertSame( [], $access->proved_patient_ids( $userId, $this->clinicA, $now, $plainToken ) );

        // Sub-case B: Proof stamped on a DIFFERENT session token cannot authorize plainToken.
        $otherProofStore = new PatientSessionProofStore();
        $otherToken      = $otherProofStore->issue_non_persistent_auth_cookie( $userId );
        self::assertTrue( $otherProofStore->record_mobile_proof( $userId, $mob, $now, $otherToken ) );
        self::assertTrue( $otherProofStore->record_binding_proof( $userId, $this->clinicA, $patientId, (int) $binding['id'], (string) $binding['verified_at'], $now, $otherToken ) );
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, $patientId, $this->clinicA, $now, $plainToken ) );

        // Sub-case C: Real OTP login (PURPOSE_LOGIN) creates session with mobile proof and LEVEL_NONE / [] proved IDs.
        $this->seedOtp( $this->clinicA, $mob, '456789', OtpService::PURPOSE_LOGIN );
        $otpLoginToken = '';
        $capture       = static function ( string $cookie, int $expire, int $expiration, int $uid, string $scheme, string $tok ) use ( $userId, &$otpLoginToken ): void {
            if ( $uid === $userId ) {
                $otpLoginToken = $tok;
            }
        };
        add_action( 'set_logged_in_cookie', $capture, 10, 6 );
        try {
            $loginResp = $this->dispatchRest(
                'POST',
                self::NS . '/otp/verify',
                [
                    'mobile'  => $mob,
                    'code'    => '456789',
                    'purpose' => OtpService::PURPOSE_LOGIN,
                ],
                0,
                false
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $capture, 10 );
        }
        self::assertSame( 200, $loginResp->get_status() );
        self::assertNotSame( '', $otpLoginToken );
        self::assertTrue( $otherProofStore->has_valid_mobile_proof( $userId, $mob, $now, $otpLoginToken ) );
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, $patientId, $this->clinicA, $now, $otpLoginToken ) );
        self::assertSame( [], $access->proved_patient_ids( $userId, $this->clinicA, $now, $otpLoginToken ) );

        $protectedRoutes = [
            [ 'GET', self::NS . '/patient/me', [] ],
            [ 'GET', self::NS . '/patient/my-records', [] ],
            [ 'GET', self::NS . '/visits', [] ],
            [ 'GET', self::NS . '/prescriptions', [] ],
            [ 'GET', self::NS . '/appointments/mine', [ 'from' => $futureDate, 'to' => $futureDate ] ],
            [ 'GET', self::NS . '/notifications', [] ],
            [ 'POST', self::NS . '/booking/hold', [ 'clinician_id' => $this->clinicianA, 'slot_date' => $futureDate, 'slot_time' => '14:00:00', 'slot_id' => $slot1 ] ],
        ];

        foreach ( [ $plainToken, $otpLoginToken ] as $unprovedToken ) {
            foreach ( $protectedRoutes as $spec ) {
                $resp = $this->dispatchRestWithSessionToken( $spec[0], $spec[1], $spec[2], $userId, $unprovedToken );
                $this->assertProofFailedResponse( $resp, $spec[0] . ' ' . $spec[1] );
                $this->assertResponseExcludesStrings( $resp->get_data(), [ $firstName, $lastName, $mrn, $complaint, $rxDrug, $apptRef, $nid ], $spec[0] . ' ' . $spec[1] );
            }
        }

        // Positive control: complete two-factor proof in the OTP-login session via matching NID -> LEVEL_FULL and 200 on /patient/me, /visits, and /booking/hold.
        $linkOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'national_id' => $nid,
            ],
            $userId,
            $otpLoginToken
        );
        self::assertSame( 200, $linkOk->get_status() );
        self::assertSame( PatientAccess::LEVEL_FULL, $access->level( $userId, $patientId, $this->clinicA, time(), $otpLoginToken ) );

        $meOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $userId, $otpLoginToken );
        self::assertSame( 200, $meOk->get_status() );
        $visitsOk = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/visits', [], $userId, $otpLoginToken );
        self::assertSame( 200, $visitsOk->get_status() );
        $holdOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/booking/hold',
            [ 'clinician_id' => $this->clinicianA, 'slot_date' => $futureDate, 'slot_time' => '14:00:00', 'slot_id' => $slot1 ],
            $userId,
            $otpLoginToken
        );
        self::assertSame( 200, $holdOk->get_status() );
    }

    // =========================================================================
    // T7: Legacy link promotion requires fresh OTP or valid same-session proof
    // =========================================================================

    public function testLegacyLinkPromotionRequiresFreshOtpOrValidSameSessionProof(): void {
        $db     = App::db();
        $logins = new PatientLoginRepository( $db );

        // Path A: Legacy-linked pending account in a plain WP session (no same-session mobile proof).
        $mobA      = $this->nextUniqueMobile();
        $nidA      = $this->nextUniqueValidNationalId();
        $wrongNidA = $this->nextUniqueValidNationalId();
        $patientA  = $this->insertPatient( $this->clinicA, 'MR-RLG-T7A-' . $this->nextUniqueToken(), 'PromoteOtp', 'UserA', $mobA, $nidA, '1990-02-14' );
        $userA     = $this->makeUser( 'rlg_t7a_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientA, $userA, $mobA );
        $logins->insert_pending_account( $userA, $mobA, 'PromoteOtp', 'UserA' );
        $tokenA = WP_Session_Tokens::get_instance( $userA )->create( time() + 3600 );

        // 1) No OTP code and no same-session mobile proof -> 403, no active binding.
        $noProofResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'national_id' => $nidA,
            ],
            $userA,
            $tokenA
        );
        $this->assertProofFailedResponse( $noProofResp, 'link-record without OTP or mobile proof' );
        self::assertNull( $logins->find_binding( $userA, $this->clinicA, $patientA, true ) );

        // 2) Wrong OTP code -> 403, no active binding.
        $this->seedOtp( $this->clinicA, $mobA, '112233', OtpService::PURPOSE_VERIFY_MOBILE );
        $wrongOtpResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'code'        => '999888',
                'national_id' => $nidA,
            ],
            $userA,
            $tokenA
        );
        $this->assertProofFailedResponse( $wrongOtpResp, 'link-record with wrong OTP' );
        self::assertNull( $logins->find_binding( $userA, $this->clinicA, $patientA, true ) );

        // 3) Valid fresh OTP + wrong NID -> 403 and no active binding; an immediate replay of the same code with the matching NID is also denied (403) and creates no active binding (the stored consumed state of the OTP row is not asserted by this test).
        $wrongNidResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'code'        => '112233',
                'national_id' => $wrongNidA,
            ],
            $userA,
            $tokenA
        );
        $this->assertProofFailedResponse( $wrongNidResp, 'link-record with valid OTP + wrong NID' );
        self::assertNull( $logins->find_binding( $userA, $this->clinicA, $patientA, true ) );

        // Immediate replay of the same code with matching NID -> still 403 and no active binding (OTP row consumed state is not asserted).
        $replayResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'code'        => '112233',
                'national_id' => $nidA,
            ],
            $userA,
            $tokenA
        );
        $this->assertProofFailedResponse( $replayResp, 'link-record immediate replay of prior OTP' );
        self::assertNull( $logins->find_binding( $userA, $this->clinicA, $patientA, true ) );

        // 4) Query-string national_id rejected -> 403, no active binding.
        $this->seedOtp( $this->clinicA, $mobA, '223344', OtpService::PURPOSE_VERIFY_MOBILE );
        $qsResp = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'code'        => '223344',
                'national_id' => $nidA,
            ],
            $userA,
            $tokenA,
            [],
            [ 'national_id' => $nidA ]
        );
        $this->assertProofFailedResponse( $qsResp, 'link-record with query-string national_id' );
        self::assertNull( $logins->find_binding( $userA, $this->clinicA, $patientA, true ) );

        // 5) Positive Path A: fresh valid OTP + matching NID in request body -> 200, active binding with expected live_key, and GET /patient/me 200 without national_id.
        $promoteOtpOk = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mobA,
                'code'        => '223344',
                'national_id' => $nidA,
            ],
            $userA,
            $tokenA
        );
        self::assertSame( 200, $promoteOtpOk->get_status() );
        $activeBindingA = $logins->find_binding( $userA, $this->clinicA, $patientA, true );
        self::assertIsArray( $activeBindingA );
        self::assertSame( PatientLoginRepository::live_key( $this->clinicA, $patientA ), $activeBindingA['live_key'] );
        $meA = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $userA, $tokenA );
        self::assertSame( 200, $meA->get_status() );
        $this->assertNoNationalIdLeak( $meA->get_data(), $nidA, 'T7 Path A GET /patient/me' );

        // Path B: Legacy-linked active account (no binding yet) uses valid same-session mobile proof from OTP login.
        $mobB      = $this->nextUniqueMobile();
        $nidB      = $this->nextUniqueValidNationalId();
        $wrongNidB = $this->nextUniqueValidNationalId();
        $patientB  = $this->insertPatient( $this->clinicA, 'MR-RLG-T7B-' . $this->nextUniqueToken(), 'PromoteSession', 'UserB', $mobB, $nidB, '1992-11-20' );
        $userB     = $this->makeUser( 'rlg_t7b_' . $this->nextUniqueToken(), RolesAndCapabilities::ROLE_PATIENT );
        $this->insertLegacyUserLink( $this->clinicA, $patientB, $userB, $mobB );
        $acctIdB = $logins->insert_pending_account( $userB, $mobB, 'PromoteSession', 'UserB' );
        $logins->activate_account_from_pending( $acctIdB, $userB );

        $this->seedOtp( $this->clinicA, $mobB, '334455', OtpService::PURPOSE_LOGIN );
        $tokenB   = '';
        $captureB = static function ( string $cookie, int $expire, int $expiration, int $uid, string $scheme, string $tok ) use ( $userB, &$tokenB ): void {
            if ( $uid === $userB ) {
                $tokenB = $tok;
            }
        };
        add_action( 'set_logged_in_cookie', $captureB, 10, 6 );
        try {
            $otpVerifyB = $this->dispatchRest(
                'POST',
                self::NS . '/otp/verify',
                [
                    'mobile'  => $mobB,
                    'code'    => '334455',
                    'purpose' => OtpService::PURPOSE_LOGIN,
                ],
                0,
                false
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $captureB, 10 );
        }
        self::assertSame( 200, $otpVerifyB->get_status() );
        self::assertNotSame( '', $tokenB );

        // Wrong NID in same-session mobile-proof flow -> 403, no active binding.
        $wrongSameSession = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'national_id' => $wrongNidB,
            ],
            $userB,
            $tokenB
        );
        $this->assertProofFailedResponse( $wrongSameSession, 'T7 Path B wrong NID' );
        self::assertNull( $logins->find_binding( $userB, $this->clinicA, $patientB, true ) );

        // Positive Path B: matching NID without OTP code in body succeeds using valid same-session mobile proof.
        $okSameSession = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/link-record',
            [
                'clinic_id'   => $this->clinicA,
                'national_id' => $nidB,
            ],
            $userB,
            $tokenB
        );
        self::assertSame( 200, $okSameSession->get_status() );
        self::assertIsArray( $logins->find_binding( $userB, $this->clinicA, $patientB, true ) );
        $meB = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $userB, $tokenB );
        self::assertSame( 200, $meB->get_status() );
        $this->assertNoNationalIdLeak( $meB->get_data(), $nidB, 'T7 Path B GET /patient/me' );
    }

    // =========================================================================
    // T8: Accountless legacy patient registration requires OTP + matching NID
    // =========================================================================

    public function testAccountlessLegacyPatientRegistrationRequiresOtpAndMatchingNationalId(): void {
        $db        = App::db();
        $logins    = new PatientLoginRepository( $db );
        $mob       = $this->nextUniqueMobile();
        $nid       = $this->nextUniqueValidNationalId();
        $wrongNid  = $this->nextUniqueValidNationalId();
        $firstName = 'LegacyAcctlessFirst_' . $this->nextUniqueToken();
        $lastName  = 'LegacyAcctlessLast_' . $this->nextUniqueToken();
        $patientId = $this->insertPatient( $this->clinicA, 'MR-RLG-T8-' . $this->nextUniqueToken(), $firstName, $lastName, $mob, $nid, null );

        self::assertNull( $logins->find_account_by_mobile( $mob ) );
        self::assertNull(
            $db->fetchValue(
                'SELECT id FROM ' . $db->table( 'cpms_patient_user_links' ) . ' WHERE patient_id = %d LIMIT 1',
                [ $patientId ]
            )
        );

        // 1) Direct registration without Step-1 OTP proof -> 403 CLINIC_PATIENT_PROOF_FAILED.
        $anonReg = $this->dispatchRest(
            'POST',
            self::NS . '/patient/register',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'given_name'  => 'AttemptFirst',
                'family_name' => 'AttemptLast',
                'national_id' => $nid,
                'birth_date'  => '1991-03-15',
            ],
            0,
            true
        );
        $this->assertProofFailedResponse( $anonReg, 'register without Step-1 OTP proof' );

        // 2) Step 1: verify_mobile OTP with clinic_id issues pending account (no active bindings), and GET /patient/me returns 403.
        $this->seedOtp( $this->clinicA, $mob, '654321', OtpService::PURPOSE_VERIFY_MOBILE );
        $step1UserId = 0;
        $step1Token  = '';
        $capture     = static function ( string $cookie, int $expire, int $expiration, int $uid, string $scheme, string $tok ) use ( &$step1UserId, &$step1Token ): void {
            $step1UserId = $uid;
            $step1Token  = $tok;
        };
        add_action( 'set_logged_in_cookie', $capture, 10, 6 );
        try {
            $step1Resp = $this->dispatchRest(
                'POST',
                self::NS . '/otp/verify',
                [
                    'mobile'    => $mob,
                    'code'      => '654321',
                    'purpose'   => OtpService::PURPOSE_VERIFY_MOBILE,
                    'clinic_id' => $this->clinicA,
                ],
                0,
                false
            );
        } finally {
            remove_action( 'set_logged_in_cookie', $capture, 10 );
        }
        self::assertSame( 200, $step1Resp->get_status() );
        self::assertGreaterThan( 0, $step1UserId );
        self::assertNotSame( '', $step1Token );
        $pendingAccount = $logins->find_account_by_user_id( $step1UserId );
        self::assertIsArray( $pendingAccount );
        self::assertSame( PatientLoginRepository::STATE_PENDING, $pendingAccount['state'] );
        self::assertSame( [], $logins->find_active_bindings_for_user( $step1UserId ) );

        $meAfterStep1 = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $step1UserId, $step1Token );
        $this->assertProofFailedResponse( $meAfterStep1, 'GET /patient/me after Step 1 only' );

        // 3) Step 2 with wrong national_id -> 403 without NID leak, no active bindings, and birth_date/identity_id remain NULL.
        $wrongNidReg = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/register',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'given_name'  => 'AttemptFirst',
                'family_name' => 'AttemptLast',
                'national_id' => $wrongNid,
                'birth_date'  => '1991-03-15',
            ],
            $step1UserId,
            $step1Token
        );
        $this->assertProofFailedResponse( $wrongNidReg, 'register Step 2 with wrong NID' );
        $this->assertNoNationalIdLeak( $wrongNidReg->get_data(), $wrongNid, 'T8 wrong NID 403' );
        self::assertSame( [], $logins->find_active_bindings_for_user( $step1UserId ) );
        $patientMid = $db->fetchRow( 'SELECT first_name, last_name, birth_date, identity_id FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d', [ $patientId ] );
        self::assertIsArray( $patientMid );
        self::assertNull( $patientMid['birth_date'] );
        self::assertNull( $patientMid['identity_id'] );

        // 4) Step 2 with query-string national_id -> 403.
        $qsReg = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/register',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'given_name'  => 'AttemptFirst',
                'family_name' => 'AttemptLast',
                'national_id' => $nid,
                'birth_date'  => '1991-03-15',
            ],
            $step1UserId,
            $step1Token,
            [],
            [ 'national_id' => $nid ]
        );
        $this->assertProofFailedResponse( $qsReg, 'register Step 2 with query-string NID' );

        // 5) Positive control: Step 2 with matching national_id links existing legacy row, activates account, creates active binding, and allows GET /patient/me without NID leak.
        $okReg = $this->dispatchRestWithSessionToken(
            'POST',
            self::NS . '/patient/register',
            [
                'clinic_id'   => $this->clinicA,
                'mobile'      => $mob,
                'given_name'  => 'IgnoredGiven',
                'family_name' => 'IgnoredFamily',
                'national_id' => $nid,
                'birth_date'  => '1991-03-15',
            ],
            $step1UserId,
            $step1Token
        );
        self::assertSame( 200, $okReg->get_status() );
        $okData = $okReg->get_data();
        self::assertIsArray( $okData );
        self::assertArrayHasKey( 'data', $okData );
        self::assertArrayHasKey( 'patient_id', $okData['data'] );
        self::assertSame( $patientId, (int) $okData['data']['patient_id'] );
        $this->assertNoNationalIdLeak( $okData, $nid, 'T8 register 200' );

        $patientAfter = $db->fetchRow( 'SELECT first_name, last_name, birth_date, identity_id FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d', [ $patientId ] );
        self::assertIsArray( $patientAfter );
        self::assertSame( $firstName, $patientAfter['first_name'] );
        self::assertSame( $lastName, $patientAfter['last_name'] );
        self::assertSame( '1991-03-15', $patientAfter['birth_date'] );
        self::assertNotNull( $patientAfter['identity_id'] );

        $activeAcct = $logins->find_account_by_user_id( $step1UserId );
        self::assertIsArray( $activeAcct );
        self::assertSame( PatientLoginRepository::STATE_ACTIVE, $activeAcct['state'] );
        self::assertIsArray( $logins->find_binding( $step1UserId, $this->clinicA, $patientId, true ) );

        $meAfter = $this->dispatchRestWithSessionToken( 'GET', self::NS . '/patient/me', [], $step1UserId, $step1Token );
        self::assertSame( 200, $meAfter->get_status() );
        $meAfterData = $meAfter->get_data();
        self::assertIsArray( $meAfterData );
        self::assertArrayHasKey( 'data', $meAfterData );
        self::assertArrayHasKey( 'first_name', $meAfterData['data'] );
        self::assertSame( $firstName, $meAfterData['data']['first_name'] );
        $this->assertNoNationalIdLeak( $meAfterData, $nid, 'T8 GET /patient/me post-register' );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function assertNoPatientAccess(
        PatientAccess $access,
        int $userId,
        int $clinicId,
        int $patientId,
        int $linkId,
        int $otherClinicId,
        int $now,
        string $token
    ): void {
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, $patientId, $clinicId, $now, $token ) );
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, null, $clinicId, $now, $token ) );
        self::assertSame( PatientAccess::LEVEL_NONE, $access->level( $userId, null, null, $now, $token ) );
        self::assertSame( [], $access->proved_patient_ids( $userId, $clinicId, $now, $token ) );
        self::assertSame( [], $access->proved_patient_ids( $userId, null, $now, $token ) );

        $this->withSessionCookie(
            $userId,
            $token,
            static function () use ( $access, $userId, $clinicId, $patientId, $linkId, $otherClinicId ): void {
                self::assertFalse( $access->can_access_patient( $userId, $clinicId, $patientId ) );
                self::assertFalse( $access->can_access_clinic( $userId, $clinicId ) );
                self::assertFalse( $access->can_enroll_in_clinic( $userId, $otherClinicId ) );

                $modeCases = [
                    [ PatientAccess::MODE_CLINICAL, $clinicId, $patientId, $linkId ],
                    [ PatientAccess::MODE_PATIENT_RESOURCE, null, $patientId, null ],
                    [ PatientAccess::MODE_RECORDS_LIST, null, null, null ],
                    [ PatientAccess::MODE_BOOKING_APPOINTMENT, $clinicId, $patientId, $linkId ],
                    [ PatientAccess::MODE_ENROLL, $otherClinicId, null, null ],
                ];
                foreach ( $modeCases as $case ) {
                    $decision = $access->authorize_request( $userId, $case[1], $case[2], $case[3], $case[0] );
                    self::assertFalse( $decision['allowed'], 'Mode ' . $case[0] . ' must fail closed.' );
                    self::assertSame( 403, $decision['http_status'] );
                    self::assertSame( PatientAccess::PROOF_FAILED_CODE, $decision['code'] );
                }
            }
        );
    }

    private function assertProofFailedResponse( WP_REST_Response $response, string $context ): void {
        self::assertSame( 403, $response->get_status(), $context . ' must return HTTP 403.' );
        $data = $response->get_data();
        self::assertIsArray( $data, $context . ' response body must be an array.' );
        self::assertArrayHasKey( 'code', $data, $context . ' response must include error code.' );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $data['code'], $context . ' must return PROOF_FAILED_CODE.' );
    }

    /**
     * @param list<string> $needles
     */
    private function assertResponseExcludesStrings( mixed $payload, array $needles, string $context ): void {
        $encoded = is_string( $payload ) ? $payload : (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        foreach ( $needles as $needle ) {
            if ( '' === $needle ) {
                continue;
            }
            self::assertStringNotContainsString( $needle, $encoded, $context . ' must not disclose protected value.' );
        }
    }

    private function assertNoNationalIdLeak( mixed $payload, string $nid, string $context ): void {
        if ( is_array( $payload ) ) {
            self::assertArrayNotHasKey( 'national_id', $payload, $context . ' must not expose national_id key.' );
            if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
                self::assertArrayNotHasKey( 'national_id', $payload['data'], $context . ' data must not expose national_id key.' );
            }
        }
        $encoded = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        self::assertStringNotContainsString( '"national_id"', $encoded, $context . ' JSON must not contain national_id key.' );
        self::assertStringNotContainsString( $nid, $encoded, $context . ' JSON must not contain raw national_id.' );
    }

    private function nextUniqueToken(): string {
        ++self::$seq;

        return self::$seq . '_' . bin2hex( random_bytes( 4 ) );
    }

    private function nextUniqueMobile(): string {
        ++self::$seq;

        return '0912' . sprintf( '%07d', 9300000 + self::$seq );
    }

    private function nextUniqueValidNationalId(): string {
        ++self::$seq;
        $base = '048' . sprintf( '%06d', 700000 + self::$seq );
        $sum  = 0;
        for ( $i = 0; $i < 9; ++$i ) {
            $sum += (int) $base[ $i ] * ( 10 - $i );
        }
        $rem   = $sum % 11;
        $check = $rem < 2 ? $rem : 11 - $rem;

        return $base . (string) $check;
    }

    private function makeUser( string $login, string $role ): int {
        $userId = (int) wp_create_user( $login, 'pass-rlg-' . $this->nextUniqueToken(), $login . '@rlg.test.local' );
        self::assertGreaterThan( 0, $userId );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( $role );

        return $userId;
    }

    private function insertLocation( int $clinicId, string $name ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_locations',
                [
                    'clinic_id'  => $clinicId,
                    'name'       => $name,
                    'slug'       => 'rlg-loc-' . $this->nextUniqueToken(),
                    'timezone'   => 'UTC',
                    'is_primary' => 1,
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertClinician( int $clinicId, int $wpUserId, string $name ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_clinicians',
                [
                    'clinic_id'  => $clinicId,
                    'full_name'  => $name,
                    'wp_user_id' => $wpUserId,
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertPatient(
        int $clinicId,
        string $mrn,
        string $firstName,
        string $lastName,
        string $mobile,
        ?string $nationalId,
        ?string $birthDate
    ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_patients',
                [
                    'clinic_id'   => $clinicId,
                    'mrn'         => $mrn,
                    'first_name'  => $firstName,
                    'last_name'   => $lastName,
                    'mobile'      => $mobile,
                    'national_id' => $nationalId,
                    'birth_date'  => $birthDate,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertLegacyUserLink(
        int $clinicId,
        int $patientId,
        int $userId,
        string $mobile,
        int $isPrimary = 1
    ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_patient_user_links',
                [
                    'clinic_id'      => $clinicId,
                    'patient_id'     => $patientId,
                    'wp_user_id'     => $userId,
                    'mobile_at_link' => $mobile,
                    'is_primary'     => $isPrimary,
                    'linked_at'      => $now,
                ]
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertLegacyIdentityEdges( int $orgId, int $patientId, int $userId, string $mobile ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_patient_identities',
                [
                    'organization_id'   => $orgId,
                    'internal_ref'      => 'RLG-ID-' . $this->nextUniqueToken(),
                    'normalized_mobile' => $mobile,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]
            )
        );
        $identityId = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $identityId );

        $updated = $db->execute(
            'UPDATE ' . $db->table( 'cpms_patients' ) . ' SET identity_id = %d WHERE id = %d',
            [ $identityId, $patientId ]
        );
        self::assertSame( 1, $updated );

        self::assertTrue(
            $db->insert(
                'cpms_patient_identity_links',
                [
                    'organization_id' => $orgId,
                    'identity_id'     => $identityId,
                    'wp_user_id'      => $userId,
                    'mobile_at_link'  => $mobile,
                    'is_primary'      => 1,
                    'linked_at'       => $now,
                ]
            )
        );

        return $identityId;
    }

    private function insertSlot( int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $capacity ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_schedule_slots',
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
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function insertConfirmedAppointment(
        int $clinicId,
        int $locationId,
        int $patientId,
        int $clinicianId,
        int $slotId,
        string $date,
        string $time,
        string $referenceCode
    ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_appointments',
                [
                    'clinic_id'         => $clinicId,
                    'location_id'       => $locationId,
                    'reference_code'    => $referenceCode,
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
            )
        );

        return $db->wpdb_last_insert_id();
    }

    private function createCompletedVisitWithPrescription( int $patientId, string $complaintText, string $rxDrug ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        self::assertTrue(
            $db->insert(
                'cpms_visits',
                [
                    'clinic_id'     => $this->clinicA,
                    'location_id'   => $this->locationA,
                    'clinician_id'  => $this->clinicianA,
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
            )
        );
        $visitId = $db->wpdb_last_insert_id();
        self::assertGreaterThan( 0, $visitId );

        App::replaceExplicitScope( ClinicScope::forClinic( $this->clinicA, $this->locationA ) );
        try {
            App::clinicalService()->addNote(
                $this->doctorUser,
                $visitId,
                [
                    'category'     => 'chief_complaint',
                    'visibility'   => 'patient_visible',
                    'content_text' => $complaintText,
                ]
            );
            $rx = App::clinicalService()->createPrescription(
                $this->doctorUser,
                $visitId,
                [
                    'is_patient_visible' => 1,
                    'items'              => [
                        [
                            'generic_name' => $rxDrug,
                            'dose'         => '500mg',
                            'frequency'    => 'BID',
                        ],
                    ],
                ]
            );
            App::clinicalService()->finalizePrescription( $this->doctorUser, (int) $rx['id'] );
            App::clinicalService()->completeConsultation( $this->doctorUser, $visitId );
        } finally {
            App::resetScope();
        }

        return $visitId;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadPatientVisiblePdf( int $patientId, string $filename, string $marker ): array {
        $tmp = tempnam( sys_get_temp_dir(), 'rlg_pdf_' );
        self::assertIsString( $tmp );
        file_put_contents( $tmp, "%PDF-1.4\n%{$marker}\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n" );

        App::replaceExplicitScope( ClinicScope::forClinic( $this->clinicA, $this->locationA ) );
        try {
            return App::medicalFileService()->upload(
                $this->doctorUser,
                [
                    'name'     => $filename,
                    'tmp_name' => $tmp,
                    'size'     => (int) filesize( $tmp ),
                    'error'    => UPLOAD_ERR_OK,
                    'type'     => 'application/pdf',
                ],
                $patientId,
                null,
                'document',
                'patient_visible'
            );
        } finally {
            App::resetScope();
            if ( file_exists( $tmp ) ) {
                @unlink( $tmp );
            }
        }
    }

    private function seedOtp( int $clinicId, string $mobile, string $code, string $purpose, int $expiresOffset = 180 ): void {
        $pepper = defined( 'CPMS_PEPPER' ) && '' !== (string) CPMS_PEPPER
            ? (string) CPMS_PEPPER
            : (string) get_option( 'cpms_otp_pepper', '' );
        if ( '' === $pepper ) {
            $pepper = bin2hex( random_bytes( 32 ) );
            update_option( 'cpms_otp_pepper', $pepper, 'no' );
        }

        self::assertTrue(
            App::db()->insert(
                'cpms_otp_tokens',
                [
                    'clinic_id'  => $clinicId,
                    'mobile'     => $mobile,
                    'purpose'    => $purpose,
                    'code_hash'  => OtpPolicy::hashCode( $code, $pepper ),
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expiresOffset ) . '.000',
                    'attempts'   => 0,
                    'created_at' => App::db()->nowUtcSql(),
                ]
            )
        );
    }

    private function renderPortalAs( int $userId, string $sessionToken ): string {
        return $this->withSessionCookie(
            $userId,
            $sessionToken,
            static function (): string {
                ob_start();
                PatientPortalPage::render();

                return (string) ob_get_clean();
            }
        );
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     * @param array<string, mixed>  $queryParams
     */
    private function dispatchRest(
        string $method,
        string $route,
        array $params,
        int $userId,
        bool $withNonce = true,
        array $headers = [],
        array $queryParams = []
    ): WP_REST_Response {
        if ( $userId <= 0 ) {
            wp_set_current_user( 0 );
        } else {
            wp_set_current_user( $userId );
        }

        $request = new WP_REST_Request( $method, $route );
        if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $params ) );
            if ( [] !== $queryParams ) {
                $request->set_query_params( $queryParams );
            }
        } else {
            $request->set_query_params( array_merge( $params, $queryParams ) );
        }

        if ( $withNonce ) {
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }
        foreach ( $headers as $name => $value ) {
            $request->set_header( $name, $value );
        }

        return rest_do_request( $request );
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     * @param array<string, mixed>  $queryParams
     */
    private function dispatchRestWithSessionToken(
        string $method,
        string $route,
        array $params,
        int $userId,
        string $sessionToken,
        array $headers = [],
        array $queryParams = []
    ): WP_REST_Response {
        return $this->withSessionCookie(
            $userId,
            $sessionToken,
            fn (): WP_REST_Response => $this->dispatchRest( $method, $route, $params, $userId, true, $headers, $queryParams )
        );
    }

    private function dispatchPatientUploadWithSessionToken( string $route, int $userId, string $sessionToken ): WP_REST_Response {
        return $this->withSessionCookie(
            $userId,
            $sessionToken,
            static function () use ( $route ): WP_REST_Response {
                $tmp = tempnam( sys_get_temp_dir(), 'rlg_up_' );
                self::assertIsString( $tmp );
                file_put_contents( $tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n" );

                try {
                    $request = new WP_REST_Request( 'POST', $route );
                    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
                    $request->set_param( 'category', 'document' );
                    $request->set_file_params(
                        [
                            'file' => [
                                'name'     => 'rlg-upload-attempt.pdf',
                                'tmp_name' => $tmp,
                                'size'     => (int) filesize( $tmp ),
                                'error'    => UPLOAD_ERR_OK,
                                'type'     => 'application/pdf',
                            ],
                        ]
                    );

                    return rest_do_request( $request );
                } finally {
                    if ( file_exists( $tmp ) ) {
                        @unlink( $tmp );
                    }
                }
            }
        );
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withSessionCookie( int $userId, string $sessionToken, callable $callback ): mixed {
        $prevCookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
        $prevUserId = get_current_user_id();
        $exp        = time() + PatientSessionProofStore::SESSION_TTL_SECONDS;

        wp_set_current_user( $userId );
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $userId, $exp, 'logged_in', $sessionToken );
        App::patient_session_scope()->set_in_process_session_token( $userId, $sessionToken );

        try {
            return $callback();
        } finally {
            App::patient_session_scope()->reset_in_process_state();
            if ( null === $prevCookie ) {
                unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            } else {
                $_COOKIE[ LOGGED_IN_COOKIE ] = $prevCookie;
            }
            wp_set_current_user( $prevUserId );
        }
    }
}

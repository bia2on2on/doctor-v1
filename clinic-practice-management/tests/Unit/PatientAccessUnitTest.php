<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

final class PatientAccessUnitTest extends TestCase {

    public function testUnauthenticatedAndMissingUsersAreDeniedWithPositiveControl(): void {
        $gate = $this->make_gate();

        // Negative: user 0 and nonexistent user 999 fail closed.
        self::assertFalse( $gate->can_access_clinic( 0, 10 ) );
        self::assertFalse( $gate->can_access_clinic( 999, 10 ) );
        self::assertFalse( $gate->can_access_patient( 0, 10, 100 ) );
        self::assertFalse( $gate->can_enroll_in_clinic( 0, 10 ) );

        $unauthZero = $gate->authorize_request( 0, 10 );
        self::assertFalse( $unauthZero['allowed'] );
        self::assertSame( 401, $unauthZero['http_status'] );
        self::assertSame( PatientAccess::UNAUTHORIZED_CODE, $unauthZero['code'] );

        $unauthMissing = $gate->authorize_request( 999, 10 );
        self::assertFalse( $unauthMissing['allowed'] );
        self::assertSame( 401, $unauthMissing['http_status'] );
        self::assertSame( PatientAccess::UNAUTHORIZED_CODE, $unauthMissing['code'] );

        // Positive control: authenticated linked patient user 1 in clinic 10 / patient 100.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        $allowed = $gate->authorize_request( 1, 10, 100 );
        self::assertTrue( $allowed['allowed'] );
        self::assertSame( 200, $allowed['http_status'] );
    }

    public function testStaffUsersAreDeniedEvenIfBindingFixtureExistsWithPositiveControl(): void {
        // User 2 has secretary role only (no cpms_patient), even though clinic/patient
        // bindings list user 2 as bound to prove role separation is enforced.
        $gate = $this->make_gate(
            users: [
                1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                2 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_SECRETARY ] ],
                3 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_DOCTOR ] ],
                4 => [ 'exists' => true, 'roles' => [ 'administrator' ] ],
            ],
            clinic_bindings: [
                '1:10' => true,
                '2:10' => true,
                '3:10' => true,
                '4:10' => true,
            ],
            patient_bindings: [
                '1:10:100' => true,
                '2:10:100' => true,
            ]
        );

        foreach ( [ 2, 3, 4 ] as $staffUserId ) {
            self::assertFalse( $gate->can_access_clinic( $staffUserId, 10 ) );
            self::assertFalse( $gate->can_access_patient( $staffUserId, 10, 100 ) );
            self::assertFalse( $gate->can_enroll_in_clinic( $staffUserId, 10 ) );

            $denied = $gate->authorize_request( $staffUserId, 10, 100 );
            self::assertFalse( $denied['allowed'] );
            self::assertSame( 403, $denied['http_status'] );
            self::assertSame( PatientAccess::FORBIDDEN_CODE, $denied['code'] );
        }

        // Positive control: patient-role user 1 on the same clinic/patient succeeds.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        self::assertTrue( $gate->authorize_request( 1, 10, 100 )['allowed'] );
    }

    public function testUnlinkedAndCrossClinicPatientsAreDeniedWithIdenticalUnknownVsForbiddenResponses(): void {
        $gate = $this->make_gate();

        // Negative: patient user 5 has NO bindings anywhere.
        self::assertFalse( $gate->can_access_clinic( 5, 10 ) );
        self::assertFalse( $gate->can_access_patient( 5, 10, 100 ) );
        $noLinksNoClinic = $gate->authorize_request( 5 );
        $noLinksClinic10 = $gate->authorize_request( 5, 10 );
        self::assertFalse( $noLinksNoClinic['allowed'] );
        self::assertFalse( $noLinksClinic10['allowed'] );

        // Negative: patient user 1 is linked to clinic 10 ONLY, denied on clinic 20 and unknown clinic 999999.
        self::assertFalse( $gate->can_access_clinic( 1, 20 ) );
        self::assertFalse( $gate->can_access_patient( 1, 20, 200 ) );
        self::assertFalse( $gate->can_access_clinic( 1, 999999 ) );

        $forbiddenClinic = $gate->authorize_request( 1, 20 );
        $unknownClinic   = $gate->authorize_request( 1, 999999 );
        $forbiddenLink   = $gate->authorize_request( 1, null, null, 702 ); // Owned by another user in clinic 20.
        $unknownLink     = $gate->authorize_request( 1, null, null, 999999 );
        $clinicMismatch  = $gate->authorize_request( 1, 20, null, 701 ); // Own link 701 (clinic 10) paired with clinic 20.

        foreach ( [ $noLinksNoClinic, $noLinksClinic10, $forbiddenClinic, $unknownClinic, $forbiddenLink, $unknownLink, $clinicMismatch ] as $denied ) {
            self::assertFalse( $denied['allowed'] );
            self::assertSame( 404, $denied['http_status'] );
            self::assertSame( PatientAccess::NOT_FOUND_CODE, $denied['code'] );
            self::assertSame( PatientAccess::NOT_FOUND_MESSAGE, $denied['message'] );
        }

        // Positive controls on the same fixture.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        self::assertTrue( $gate->authorize_request( 1 )['allowed'] );
        self::assertTrue( $gate->authorize_request( 1, 10 )['allowed'] );
        self::assertTrue( $gate->authorize_request( 1, 10, 100, 701 )['allowed'] );
    }

    public function testEnrollRecordsListInvalidSelectorsAndUnwiredGateFailClosedWithPositiveControls(): void {
        $observed = [];
        $gate     = $this->make_gate(
            observer: static function ( array $event ) use ( &$observed ): void {
                $observed[] = $event;
            }
        );

        // Negative: invalid/non-positive/conflicting selectors & unsupported mode fail closed.
        self::assertFalse( $gate->can_access_clinic( 1, 0 ) );
        self::assertFalse( $gate->can_access_patient( 1, 10, -5 ) );
        $invalidFlag   = $gate->authorize_request( 1, 10, null, null, PatientAccess::MODE_CLINICAL, true );
        $badMode       = $gate->authorize_request( 1, 10, null, null, 'unknown_mode' );
        $patientNoCl   = $gate->authorize_request( 1, null, 100 );
        $badLink       = $gate->authorize_request( 1, null, null, 0 );
        $recordsOther  = $gate->authorize_request( 1, 20, null, null, PatientAccess::MODE_RECORDS_LIST );
        $enrollDenied  = $gate->authorize_request( 1, 10, null, null, PatientAccess::MODE_ENROLL );
        $enrollNoCl    = $gate->authorize_request( 1, null, null, null, PatientAccess::MODE_ENROLL );

        foreach ( [ $invalidFlag, $badMode, $patientNoCl, $badLink, $recordsOther, $enrollDenied, $enrollNoCl ] as $denied ) {
            self::assertFalse( $denied['allowed'] );
            self::assertSame( 404, $denied['http_status'] );
            self::assertSame( PatientAccess::NOT_FOUND_CODE, $denied['code'] );
        }

        // Positive controls for records_list (no clinic / bound clinic) and enroll (eligible clinic 20).
        self::assertTrue( $gate->authorize_request( 5, null, null, null, PatientAccess::MODE_RECORDS_LIST )['allowed'] );
        self::assertTrue( $gate->authorize_request( 1, 10, null, null, PatientAccess::MODE_RECORDS_LIST )['allowed'] );
        self::assertTrue( $gate->can_enroll_in_clinic( 1, 20 ) );
        self::assertTrue( $gate->authorize_request( 1, 20, null, null, PatientAccess::MODE_ENROLL )['allowed'] );

        // Observer evidence recorded inside callback, asserted outside.
        self::assertCount( 10, $observed );
        self::assertFalse( $observed[0]['allowed'] );
        self::assertTrue( $observed[9]['allowed'] );

        // Unwired gate (null db/repo/seams) fails closed even for a patient-role user.
        $unwired = new PatientAccess(
            null,
            null,
            static fn ( int $uid ): array => [ 'exists' => 1 === $uid, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ]
        );
        self::assertFalse( $unwired->can_access_clinic( 1, 10 ) );
        self::assertFalse( $unwired->can_access_patient( 1, 10, 100 ) );
        self::assertFalse( $unwired->can_enroll_in_clinic( 1, 20 ) );
        self::assertFalse( $unwired->authorize_request( 1 )['allowed'] );
        self::assertFalse( $unwired->authorize_request( 1, 10 )['allowed'] );
        self::assertFalse( $unwired->authorize_request( 1, null, null, 701 )['allowed'] );
    }

    /**
     * @param array<int, array{exists: bool, roles: list<string>}>|null $users
     * @param array<string, bool>|null                                  $clinic_bindings
     * @param array<string, bool>|null                                  $patient_bindings
     * @param (callable(array<string, mixed>): void)|null               $observer
     */
    private function make_gate(
        ?array $users = null,
        ?array $clinic_bindings = null,
        ?array $patient_bindings = null,
        ?callable $observer = null
    ): PatientAccess {
        $users            ??= [
            1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
            5 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
        ];
        $clinic_bindings  ??= [ '1:10' => true ];
        $patient_bindings ??= [ '1:10:100' => true ];
        $links              = [
            '1:701' => [ 'clinic_id' => 10, 'patient_id' => 100 ],
        ];
        $enroll             = [ '1:20' => true ];

        return new PatientAccess(
            null,
            null,
            static fn ( int $uid ): ?array => $users[ $uid ] ?? null,
            static fn ( int $uid, int $cid ): bool => $clinic_bindings[ $uid . ':' . $cid ] ?? false,
            static fn ( int $uid, int $cid, int $pid ): bool => $patient_bindings[ $uid . ':' . $cid . ':' . $pid ] ?? false,
            static function ( int $uid ) use ( $clinic_bindings ): bool {
                foreach ( $clinic_bindings as $key => $allowed ) {
                    if ( $allowed && str_starts_with( $key, $uid . ':' ) ) {
                        return true;
                    }
                }

                return false;
            },
            static fn ( int $uid, int $lid ): ?array => $links[ $uid . ':' . $lid ] ?? null,
            static fn ( int $uid, int $cid ): bool => $enroll[ $uid . ':' . $cid ] ?? false,
            $observer
        );
    }
}

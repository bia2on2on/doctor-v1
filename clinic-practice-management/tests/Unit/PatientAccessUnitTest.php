<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

final class PatientAccessUnitTest extends TestCase {

    public function testUnauthenticatedAndMissingUsersAreDeniedWithPositiveControl(): void {
        $gate = $this->make_gate();

        // Negative: user 0 and nonexistent user 999 fail closed with 403 PROOF_FAILED.
        self::assertFalse( $gate->can_access_clinic( 0, 10 ) );
        self::assertFalse( $gate->can_access_clinic( 999, 10 ) );
        self::assertFalse( $gate->can_access_patient( 0, 10, 100 ) );
        self::assertFalse( $gate->can_enroll_in_clinic( 0, 10 ) );

        $unauthZero = $gate->authorize_request( 0, 10 );
        self::assertFalse( $unauthZero['allowed'] );
        self::assertSame( 403, $unauthZero['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unauthZero['code'] );

        $unauthMissing = $gate->authorize_request( 999, 10 );
        self::assertFalse( $unauthMissing['allowed'] );
        self::assertSame( 403, $unauthMissing['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $unauthMissing['code'] );

        // Positive control: authenticated linked patient user 1 in clinic 10 / patient 100 with live two-proof session.
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
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
        }

        // Positive control: patient-role user 1 on the same clinic/patient succeeds.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        self::assertTrue( $gate->authorize_request( 1, 10, 100 )['allowed'] );
    }

    public function testUnlinkedAndCrossClinicPatientsAreDeniedWithIdenticalResponses(): void {
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
            self::assertSame( 403, $denied['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $denied['message'] );
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

        // Negative: invalid/non-positive/conflicting selectors, 0-binding records list, & unsupported mode fail closed.
        self::assertFalse( $gate->can_access_clinic( 1, 0 ) );
        self::assertFalse( $gate->can_access_patient( 1, 10, -5 ) );
        $invalidFlag   = $gate->authorize_request( 1, 10, null, null, PatientAccess::MODE_CLINICAL, true );
        $badMode       = $gate->authorize_request( 1, 10, null, null, 'unknown_mode' );
        $patientNoCl   = $gate->authorize_request( 1, null, 100 );
        $badLink       = $gate->authorize_request( 1, null, null, 0 );
        $recordsZero   = $gate->authorize_request( 5, null, null, null, PatientAccess::MODE_RECORDS_LIST );
        $recordsOther  = $gate->authorize_request( 1, 20, null, null, PatientAccess::MODE_RECORDS_LIST );
        $enrollDenied  = $gate->authorize_request( 1, 10, null, null, PatientAccess::MODE_ENROLL );
        $enrollNoCl    = $gate->authorize_request( 1, null, null, null, PatientAccess::MODE_ENROLL );

        foreach ( [ $invalidFlag, $badMode, $patientNoCl, $badLink, $recordsZero, $recordsOther, $enrollDenied, $enrollNoCl ] as $denied ) {
            self::assertFalse( $denied['allowed'] );
            self::assertSame( 403, $denied['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
        }

        // Positive controls for records_list (bound clinic) and enroll (eligible clinic 20).
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

    public function testMultiRoleAndStaffCapPatientUsersAreDeniedWithPositiveControl(): void {
        $gate = $this->make_gate(
            users: [
                1  => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                11 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_DOCTOR, RolesAndCapabilities::ROLE_PATIENT ] ],
                12 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT, RolesAndCapabilities::ROLE_SECRETARY ] ],
                13 => [ 'exists' => true, 'roles' => [ 'administrator', RolesAndCapabilities::ROLE_PATIENT ] ],
                14 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT, 'subscriber' ] ],
                15 => [
                    'exists' => true,
                    'roles'  => [ RolesAndCapabilities::ROLE_PATIENT ],
                    'caps'   => [ RolesAndCapabilities::MEDICAL_READ => true ],
                ],
                16 => [
                    'exists' => true,
                    'roles'  => [ RolesAndCapabilities::ROLE_PATIENT ],
                    'caps'   => [ 'manage_options' => true ],
                ],
            ],
            clinic_bindings: [
                '1:10'  => true,
                '11:10' => true,
                '12:10' => true,
                '13:10' => true,
                '14:10' => true,
                '15:10' => true,
                '16:10' => true,
            ],
            patient_bindings: [
                '1:10:100'  => true,
                '11:10:100' => true,
                '12:10:100' => true,
                '13:10:100' => true,
                '14:10:100' => true,
                '15:10:100' => true,
                '16:10:100' => true,
            ]
        );

        foreach ( [ 11, 12, 13, 14, 15, 16 ] as $mixedUserId ) {
            self::assertFalse( $gate->can_access_clinic( $mixedUserId, 10 ) );
            self::assertFalse( $gate->can_access_patient( $mixedUserId, 10, 100 ) );
            self::assertFalse( $gate->can_enroll_in_clinic( $mixedUserId, 10 ) );

            foreach ( [ PatientAccess::MODE_CLINICAL, PatientAccess::MODE_RECORDS_LIST, PatientAccess::MODE_ENROLL ] as $mode ) {
                $denied = $gate->authorize_request( $mixedUserId, 10, 100, null, $mode );
                self::assertFalse( $denied['allowed'] );
                self::assertSame( 403, $denied['http_status'] );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
                self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $denied['message'] );
            }
        }

        // Positive control: pure patient-role user 1 with zero staff capabilities succeeds.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        self::assertTrue( $gate->authorize_request( 1, 10, 100 )['allowed'] );
    }

    public function testScopedStaffAuthorityAndLookupFailureAreDeniedWithPositiveControl(): void {
        $gate = $this->make_gate(
            users: [
                1  => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                21 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                22 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
            ],
            clinic_bindings: [
                '1:10'  => true,
                '21:10' => true,
                '22:10' => true,
            ],
            patient_bindings: [
                '1:10:100'  => true,
                '21:10:100' => true,
                '22:10:100' => true,
            ],
            scoped_staff_checker: static function ( int $uid ): bool {
                if ( 22 === $uid ) {
                    throw new \RuntimeException( 'membership read failed' );
                }

                return 21 === $uid;
            }
        );

        foreach ( [ 21, 22 ] as $scopedOrFailedId ) {
            self::assertFalse( $gate->can_access_clinic( $scopedOrFailedId, 10 ) );
            self::assertFalse( $gate->can_access_patient( $scopedOrFailedId, 10, 100 ) );
            self::assertFalse( $gate->can_enroll_in_clinic( $scopedOrFailedId, 10 ) );

            foreach ( [ PatientAccess::MODE_CLINICAL, PatientAccess::MODE_RECORDS_LIST, PatientAccess::MODE_ENROLL ] as $mode ) {
                $denied = $gate->authorize_request( $scopedOrFailedId, 10, 100, null, $mode );
                self::assertFalse( $denied['allowed'] );
                self::assertSame( 403, $denied['http_status'] );
                self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
                self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $denied['message'] );
            }
        }

        // Same-fixture positive control: pure patient user 1 with no scoped staff authority succeeds.
        self::assertTrue( $gate->can_access_clinic( 1, 10 ) );
        self::assertTrue( $gate->can_access_patient( 1, 10, 100 ) );
        self::assertTrue( $gate->authorize_request( 1, 10, 100 )['allowed'] );
    }

    public function testSliceBModesPatientResourceBookingAppointmentAndRecordsLink(): void {
        $gate = $this->make_gate(
            users: [
                1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                5 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                6 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
            ],
            clinic_bindings: [
                '1:10' => true,
            ],
            patient_bindings: [
                '1:10:100' => true,
            ],
            booking_clinic_bindings: [
                '1:10' => true,
                '6:10' => true,
            ],
            booking_links: [
                '1:701' => [ 'clinic_id' => 10, 'patient_id' => 100 ],
                '6:706' => [ 'clinic_id' => 10, 'patient_id' => 600 ],
            ]
        );

        // MODE_PATIENT_RESOURCE negatives (unauthenticated, missing/unbound patient, wrong clinic/link).
        $resUnauth = $gate->authorize_request( 0, null, 100, null, PatientAccess::MODE_PATIENT_RESOURCE );
        self::assertSame( 403, $resUnauth['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $resUnauth['code'] );
        foreach ( [
            $gate->authorize_request( 1, null, null, null, PatientAccess::MODE_PATIENT_RESOURCE ),
            $gate->authorize_request( 5, null, 100, null, PatientAccess::MODE_PATIENT_RESOURCE ),
            $gate->authorize_request( 1, null, 999, null, PatientAccess::MODE_PATIENT_RESOURCE ),
            $gate->authorize_request( 1, 20, 100, null, PatientAccess::MODE_PATIENT_RESOURCE ),
            $gate->authorize_request( 1, null, 100, 702, PatientAccess::MODE_PATIENT_RESOURCE ),
            $gate->authorize_request( 1, null, 999, 701, PatientAccess::MODE_PATIENT_RESOURCE ),
        ] as $deniedResource ) {
            self::assertFalse( $deniedResource['allowed'] );
            self::assertSame( 403, $deniedResource['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $deniedResource['code'] );
        }
        // MODE_PATIENT_RESOURCE positive controls (path-only, with clinic, with link).
        self::assertTrue( $gate->authorize_request( 1, null, 100, null, PatientAccess::MODE_PATIENT_RESOURCE )['allowed'] );
        self::assertTrue( $gate->authorize_request( 1, 10, 100, null, PatientAccess::MODE_PATIENT_RESOURCE )['allowed'] );
        self::assertTrue( $gate->authorize_request( 1, null, 100, 701, PatientAccess::MODE_PATIENT_RESOURCE )['allowed'] );

        // MODE_BOOKING_APPOINTMENT negatives (unlinked user 5, wrong clinic, foreign link) fail closed with 403 PROOF_FAILED.
        foreach ( [
            $gate->authorize_request( 5, null, null, null, PatientAccess::MODE_BOOKING_APPOINTMENT ),
            $gate->authorize_request( 1, 20, null, null, PatientAccess::MODE_BOOKING_APPOINTMENT ),
            $gate->authorize_request( 1, null, null, 706, PatientAccess::MODE_BOOKING_APPOINTMENT ),
        ] as $deniedBooking ) {
            self::assertFalse( $deniedBooking['allowed'] );
            self::assertSame( 403, $deniedBooking['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $deniedBooking['code'] );
        }
        // MODE_BOOKING_APPOINTMENT positive controls (including dependent-only user 6 without clinical access).
        self::assertTrue( $gate->authorize_request( 1, null, null, null, PatientAccess::MODE_BOOKING_APPOINTMENT )['allowed'] );
        self::assertTrue( $gate->authorize_request( 6, null, null, 706, PatientAccess::MODE_BOOKING_APPOINTMENT )['allowed'] );
        self::assertFalse( $gate->authorize_request( 6, null, null, null, PatientAccess::MODE_CLINICAL )['allowed'] );

        // MODE_RECORDS_LIST link_id validation rejects foreign/mismatched link_id while allowing valid/absent selectors.
        $badRecordsLink = $gate->authorize_request( 1, null, null, 702, PatientAccess::MODE_RECORDS_LIST );
        self::assertFalse( $badRecordsLink['allowed'] );
        self::assertSame( 403, $badRecordsLink['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $badRecordsLink['code'] );
        $mismatchRecordsLink = $gate->authorize_request( 1, 20, null, 701, PatientAccess::MODE_RECORDS_LIST );
        self::assertFalse( $mismatchRecordsLink['allowed'] );
        self::assertSame( 403, $mismatchRecordsLink['http_status'] );
        self::assertSame( PatientAccess::PROOF_FAILED_CODE, $mismatchRecordsLink['code'] );
        self::assertTrue( $gate->authorize_request( 1, 10, null, 701, PatientAccess::MODE_RECORDS_LIST )['allowed'] );
    }

    public function testAuthorizeLinkRecordRequestRejectsInvalidActorsAndSelectors(): void {
        $gate = $this->make_gate(
            users: [
                1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
                2 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_DOCTOR, RolesAndCapabilities::ROLE_PATIENT ] ],
                5 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
            ]
        );

        foreach ( [
            $gate->authorize_link_record_request( 0, 10 ),
            $gate->authorize_link_record_request( 2, 10 ),
            $gate->authorize_link_record_request( 5, null ),
            $gate->authorize_link_record_request( 5, 0 ),
            $gate->authorize_link_record_request( 5, 10, true ),
        ] as $denied ) {
            self::assertFalse( $denied['allowed'] );
            self::assertSame( 403, $denied['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $denied['code'] );
        }

        // Pure patient user with valid target clinic passes actor/selector gate even before binding exists.
        $allowed = $gate->authorize_link_record_request( 5, 10 );
        self::assertTrue( $allowed['allowed'] );
        self::assertSame( 200, $allowed['http_status'] );
    }

    /**
     * @param array<int, array{exists: bool, roles: list<string>, has_staff_cap?: bool, caps?: array<string, mixed>}>|null $users
     * @param array<string, bool>|null                                                                                      $clinic_bindings
     * @param array<string, bool>|null                                                                                      $patient_bindings
     * @param (callable(array<string, mixed>): void)|null                                                                   $observer
     * @param (callable(int): bool)|null                                                                                    $scoped_staff_checker
     * @param array<string, bool>|null                                                                                      $booking_clinic_bindings
     * @param array<string, array{clinic_id: int, patient_id: int}>|null                                                    $booking_links
     */
    private function make_gate(
        ?array $users = null,
        ?array $clinic_bindings = null,
        ?array $patient_bindings = null,
        ?callable $observer = null,
        ?callable $scoped_staff_checker = null,
        ?array $booking_clinic_bindings = null,
        ?array $booking_links = null
    ): PatientAccess {
        $users            ??= [
            1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
            5 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ] ],
        ];
        $clinic_bindings         ??= [ '1:10' => true ];
        $patient_bindings        ??= [ '1:10:100' => true ];
        $booking_clinic_bindings ??= $clinic_bindings;
        $links                     = [
            '1:701' => [ 'clinic_id' => 10, 'patient_id' => 100 ],
        ];
        $booking_links           ??= $links;
        $enroll                    = [ '1:20' => true ];

        // Build per-user live in-memory sessions + PatientSessionProofStore.
        $managers    = [];
        $bindingsMap = [];
        $now         = time();
        $verifiedAt  = gmdate( 'Y-m-d H:i:s.000', $now );
        $proofStore  = new PatientSessionProofStore(
            static function ( int $uid ) use ( &$managers ): object {
                if ( ! isset( $managers[ $uid ] ) ) {
                    $managers[ $uid ] = new class( [ 'tok-' . $uid => [ 'expiration' => time() + 28800 ] ] ) {
                        /** @var array<string, array<string, mixed>> */
                        public array $sessions;

                        /** @param array<string, array<string, mixed>> $sessions */
                        public function __construct( array $sessions ) {
                            $this->sessions = $sessions;
                        }

                        /** @return array<string, mixed>|null */
                        public function get( string $token ): ?array {
                            return $this->sessions[ $token ] ?? null;
                        }

                        /** @param array<string, mixed> $session */
                        public function update( string $token, array $session ): void {
                            $this->sessions[ $token ] = $session;
                        }

                        public function destroy_all(): void {
                            $this->sessions = [];
                        }
                    };
                }

                return $managers[ $uid ];
            },
            static fn (): string => ''
        );

        // Seed full-identity bindings from $patient_bindings.
        $seq = 1;
        foreach ( $patient_bindings as $key => $allowed ) {
            if ( ! $allowed ) {
                continue;
            }
            [ $uid, $cid, $pid ] = array_map( 'intval', explode( ':', $key ) );
            $mobile              = sprintf( '0912%07d', $uid );
            $bindingId           = $seq++;
            $bindingsMap[ $uid ][ $cid . ':' . $pid ] = [
                'binding_id'    => $bindingId,
                'clinic_id'     => $cid,
                'patient_id'    => $pid,
                'verified_at'   => $verifiedAt,
                'mobile'        => $mobile,
                'full_identity' => true,
            ];
            $token = 'tok-' . $uid;
            $proofStore->record_mobile_proof( $uid, $mobile, $now, $token );
            $proofStore->record_binding_proof( $uid, $cid, $pid, $bindingId, $verifiedAt, $now, $token );
        }

        // Seed booking-only bindings from $booking_links / $booking_clinic_bindings.
        foreach ( $booking_links as $key => $target ) {
            [ $uid ] = array_map( 'intval', explode( ':', $key ) );
            $cid     = (int) $target['clinic_id'];
            $pid     = (int) $target['patient_id'];
            if ( ! ( $booking_clinic_bindings[ $uid . ':' . $cid ] ?? false ) ) {
                continue;
            }
            if ( isset( $bindingsMap[ $uid ][ $cid . ':' . $pid ] ) ) {
                continue;
            }
            $mobile    = sprintf( '0912%07d', $uid );
            $bindingId = $seq++;
            $bindingsMap[ $uid ][ $cid . ':' . $pid ] = [
                'binding_id'    => $bindingId,
                'clinic_id'     => $cid,
                'patient_id'    => $pid,
                'verified_at'   => $verifiedAt,
                'mobile'        => $mobile,
                'full_identity' => false,
            ];
            $token = 'tok-' . $uid;
            $proofStore->record_mobile_proof( $uid, $mobile, $now, $token );
            $proofStore->record_binding_proof( $uid, $cid, $pid, $bindingId, $verifiedAt, $now, $token );
        }

        // Wrapper proof store that supplies `tok-{uid}` for each user when $token === null.
        $userBoundProofs = new PatientSessionProofStore(
            static function ( int $uid ) use ( &$managers ): object {
                if ( ! isset( $managers[ $uid ] ) ) {
                    $managers[ $uid ] = new class( [] ) {
                        /** @var array<string, array<string, mixed>> */
                        public array $sessions;

                        /** @param array<string, array<string, mixed>> $sessions */
                        public function __construct( array $sessions ) {
                            $this->sessions = $sessions;
                        }

                        /** @return array<string, mixed>|null */
                        public function get( string $token ): ?array {
                            return $this->sessions[ $token ] ?? null;
                        }

                        /** @param array<string, mixed> $session */
                        public function update( string $token, array $session ): void {
                            $this->sessions[ $token ] = $session;
                        }
                    };
                }

                return $managers[ $uid ];
            },
            static function () use ( &$managers ): string {
                return '';
            }
        );
        unset( $userBoundProofs );

        // Wait: level() calls $this->proofs->has_valid_binding_proof(..., $now, $token) with $token=null.
        // We track the active user in $user_reader so token_resolver returns `tok-{uid}`!
        $activeUid = 0;
        $gateProofs = new PatientSessionProofStore(
            static function ( int $uid ) use ( &$managers ): object {
                if ( ! isset( $managers[ $uid ] ) ) {
                    $managers[ $uid ] = new class( [] ) {
                        /** @var array<string, array<string, mixed>> */
                        public array $sessions;

                        /** @param array<string, array<string, mixed>> $sessions */
                        public function __construct( array $sessions ) {
                            $this->sessions = $sessions;
                        }

                        /** @return array<string, mixed>|null */
                        public function get( string $token ): ?array {
                            return $this->sessions[ $token ] ?? null;
                        }

                        /** @param array<string, mixed> $session */
                        public function update( string $token, array $session ): void {
                            $this->sessions[ $token ] = $session;
                        }
                    };
                }

                return $managers[ $uid ];
            },
            static function () use ( &$activeUid ): string {
                return $activeUid > 0 ? 'tok-' . $activeUid : '';
            }
        );

        return new PatientAccess(
            user_reader: static function ( int $uid ) use ( $users, &$activeUid ): ?array {
                $activeUid = $uid;

                return $users[ $uid ] ?? null;
            },
            link_resolver: static fn ( int $uid, int $lid ): ?array => $links[ $uid . ':' . $lid ] ?? null,
            enroll_checker: static fn ( int $uid, int $cid ): bool => $enroll[ $uid . ':' . $cid ] ?? false,
            decision_observer: $observer,
            scoped_staff_checker: $scoped_staff_checker ?? static fn ( int $uid ): bool => false,
            booking_link_resolver: static fn ( int $uid, int $lid ): ?array => $booking_links[ $uid . ':' . $lid ] ?? null,
            proofs: $gateProofs,
            two_proof_bindings_reader: static function ( int $uid, ?int $cid = null ) use ( $bindingsMap ): array {
                $rows = array_values( $bindingsMap[ $uid ] ?? [] );
                if ( null !== $cid && $cid > 0 ) {
                    return array_values( array_filter( $rows, static fn ( array $r ): bool => $r['clinic_id'] === $cid ) );
                }

                return $rows;
            }
        );
    }
}

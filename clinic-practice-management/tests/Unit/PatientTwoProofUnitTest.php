<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Auth\PatientAccess;
use ClinicCore\Application\Auth\PatientNidHmac;
use ClinicCore\Application\Auth\PatientSessionProofStore;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

final class PatientTwoProofUnitTest extends TestCase {

    public function testNidHmacNormalizesPersianDigitsAndNeverUsesRawNid(): void {
        self::assertSame( '0012345678', PatientNidHmac::normalize( ' ۰۰۱۲۳۴۵۶۷۸ ' ) );
        self::assertNull( PatientNidHmac::normalize( ' ۰ ۰ ۱۲۳۴۵۶۷۸ ' ) );
        self::assertSame( '0012345678', PatientNidHmac::normalize( '٠٠١٢٣٤٥٦٧٨' ) );
        self::assertNull( PatientNidHmac::normalize( '123456789' ) );
        self::assertNull( PatientNidHmac::normalize( '00123-45678' ) );
        self::assertNull( PatientNidHmac::normalize( 'abcdefghij' ) );

        $hmac = new PatientNidHmac(
            static fn (): string => 'active-32-byte-secret-key-material',
            static fn (): string => 'previous-32-byte-secret-key-material'
        );

        $key = $hmac->rate_limit_key( '۰۰۱۲۳۴۵۶۷۸' );
        self::assertStringStartsWith( 'nid:', $key );
        self::assertStringNotContainsString( '0012345678', $key );
        self::assertSame( 4 + 64, strlen( $key ) );

        $readKeys = $hmac->rate_limit_keys_for_read( '0012345678' );
        self::assertCount( 2, $readKeys );
        self::assertSame( $key, $readKeys[0] );
        self::assertStringNotContainsString( '0012345678', $readKeys[1] );
        self::assertNotSame( $readKeys[0], $readKeys[1] );

        $mobileKey = $hmac->mobile_rate_limit_key( '09123456789' );
        self::assertStringStartsWith( 'pl-mob:', $mobileKey );
        self::assertStringNotContainsString( '09123456789', $mobileKey );
    }

    public function testSessionProofStoreEnforcesLivenessTokenHashAndAbsoluteTtl(): void {
        $sessions = [
            'tok-live'  => [ 'expiration' => 2000000000 ],
            'tok-other' => [ 'expiration' => 2000000000 ],
            'tok-exp'   => [ 'expiration' => 1700000000 ],
        ];

        $manager = new class( $sessions ) {
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

        $currentToken = 'tok-live';
        $store        = new PatientSessionProofStore(
            static fn ( int $uid ): object => $manager,
            static function () use ( &$currentToken ): string {
                return $currentToken;
            }
        );

        $t0 = 1750000000;
        self::assertFalse( $store->has_valid_mobile_proof( 42, '09120001122', $t0 ) );
        self::assertTrue( $store->record_mobile_proof( 42, '09120001122', $t0 ) );
        self::assertTrue( $store->has_valid_mobile_proof( 42, '09120001122', $t0 + 100 ) );

        // Binding proof requires live mobile proof + matching binding_id + verified_at.
        self::assertTrue(
            $store->record_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', $t0 )
        );
        self::assertTrue(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', '09120001122', $t0 + 100 )
        );

        // Raw NID is never stored in session data.
        self::assertArrayNotHasKey( 'national_id', $manager->sessions['tok-live'] );
        self::assertArrayNotHasKey( 'national_id', $manager->sessions['tok-live'][ PatientSessionProofStore::KEY_BINDING_PROOFS ]['1:10'] );

        // Negative: wrong binding_id or changed verified_at fails closed.
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 78, '2026-11-20 10:00:00.000', '09120001122', $t0 + 100 )
        );
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:01.000', '09120001122', $t0 + 100 )
        );

        // Negative: different mobile fails closed.
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', '09129998877', $t0 + 100 )
        );

        // Negative: past 60-minute absolute proof window fails closed even while session lives.
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', '09120001122', $t0 + 3601 )
        );

        // Negative: another session token fails closed (token_hash mismatch).
        $currentToken = 'tok-other';
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', '09120001122', $t0 + 100 )
        );

        // Negative: writing to an expired/non-live session fails closed.
        $currentToken = 'tok-exp';
        self::assertFalse( $store->record_mobile_proof( 42, '09120001122', $t0 ) );

        // Negative: destroyed session fails closed.
        $currentToken = 'tok-live';
        $store->destroy_all_sessions( 42 );
        self::assertFalse(
            $store->has_valid_binding_proof( 42, 1, 10, 77, '2026-11-20 10:00:00.000', '09120001122', $t0 + 100 )
        );
    }

    public function testTwoProofGateFailsClosedWithoutDbOrOnMixedActors(): void {
        $gate = new PatientAccess(
            user_reader: static function ( int $uid ): ?array {
                return match ( $uid ) {
                    1 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ], 'has_staff_cap' => false ],
                    2 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT, RolesAndCapabilities::ROLE_DOCTOR ], 'has_staff_cap' => false ],
                    3 => [ 'exists' => true, 'roles' => [ RolesAndCapabilities::ROLE_PATIENT ], 'has_staff_cap' => true ],
                    default => null,
                };
            },
            scoped_staff_checker: static fn ( int $uid ): bool => false
        );

        // All fail closed with uniform 403 CLINIC_PATIENT_PROOF_FAILED when two-proof store/logins unavailable.
        foreach ( [ 0, 1, 2, 3 ] as $uid ) {
            self::assertSame( PatientAccess::LEVEL_NONE, $gate->level( $uid, 10, 1 ) );
            self::assertSame( [], $gate->proved_patient_ids( $uid ) );
            $decision = $gate->authorize_two_proof_request( $uid, 1, 10, null, PatientAccess::MODE_CLINICAL );
            self::assertFalse( $decision['allowed'] );
            self::assertSame( 403, $decision['http_status'] );
            self::assertSame( PatientAccess::PROOF_FAILED_CODE, $decision['code'] );
            self::assertSame( PatientAccess::PROOF_FAILED_MESSAGE, $decision['message'] );
        }
    }
}

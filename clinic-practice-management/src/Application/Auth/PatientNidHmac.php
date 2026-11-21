<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Auth;

use ClinicCore\Domain\Validators\NationalIdValidator;

/**
 * Keyed HMAC derivation for patient-identifier rate-limit buckets (§9.1).
 *
 * Raw national IDs must never appear in rate-limit keys, logs, audit rows,
 * sessions, or URLs. Key custody follows §9.1:
 *  1) `CPMS_NID_HMAC_KEY` constant when defined and non-empty;
 *  2) otherwise `cpms_nid_hmac_key` option (`autoload = no`), generated once
 *     from `random_bytes( 32 )`.
 *
 * Optional key rotation overlap uses `CPMS_NID_HMAC_PREVIOUS_KEY` /
 * `cpms_nid_hmac_key_previous` on reads while incrementing under the active key.
 */
final class PatientNidHmac {

    public const OPTION_KEY          = 'cpms_nid_hmac_key';
    public const OPTION_PREVIOUS_KEY = 'cpms_nid_hmac_key_previous';
    public const CONST_KEY           = 'CPMS_NID_HMAC_KEY';
    public const CONST_PREVIOUS_KEY  = 'CPMS_NID_HMAC_PREVIOUS_KEY';

    /** @var callable(): string|null */
    private $key_provider;

    /** @var callable(): ?string|null */
    private $previous_key_provider;

    /**
     * @param (callable(): string)|null  $key_provider          Optional key resolver (tests).
     * @param (callable(): ?string)|null $previous_key_provider Optional previous-key resolver (tests).
     */
    public function __construct(
        ?callable $key_provider = null,
        ?callable $previous_key_provider = null
    ) {
        $this->key_provider          = $key_provider;
        $this->previous_key_provider = $previous_key_provider;
    }

    /**
     * Normalizes an input national ID to 10 ASCII digits (folding Persian /
     * Arabic-Indic digits), returning null when invalid or malformed.
     */
    public static function normalize( string $raw_national_id ): ?string {
        return NationalIdValidator::exact_ascii_digits( $raw_national_id );
    }

    /**
     * Active rate-limit bucket key for a normalized national ID.
     */
    public function rate_limit_key( string $normalized_nid ): string {
        $normalized = self::normalize( $normalized_nid );
        if ( null === $normalized ) {
            throw new \InvalidArgumentException( 'Invalid normalized national ID' );
        }

        return 'nid:' . hash_hmac( 'sha256', $normalized, $this->active_key() );
    }

    /**
     * Candidate rate-limit keys for read checks during key-rotation overlap.
     *
     * @return list<string>
     */
    public function rate_limit_keys_for_read( string $normalized_nid ): array {
        $normalized = self::normalize( $normalized_nid );
        if ( null === $normalized ) {
            throw new \InvalidArgumentException( 'Invalid normalized national ID' );
        }

        $keys     = [ 'nid:' . hash_hmac( 'sha256', $normalized, $this->active_key() ) ];
        $previous = $this->previous_key();
        if ( null !== $previous && '' !== $previous ) {
            $prev_bucket = 'nid:' . hash_hmac( 'sha256', $normalized, $previous );
            if ( $prev_bucket !== $keys[0] ) {
                $keys[] = $prev_bucket;
            }
        }

        return $keys;
    }

    /**
     * Rate-limit bucket key for account attempts.
     */
    public function account_rate_limit_key( int $wp_user_id ): string {
        return 'pl-acct:' . max( 0, $wp_user_id );
    }

    /**
     * Rate-limit bucket key for mobile attempts across accounts (keyed HMAC).
     */
    public function mobile_rate_limit_key( string $normalized_mobile ): string {
        return 'pl-mob:' . hash_hmac( 'sha256', $normalized_mobile, $this->active_key() );
    }

    /**
     * Rate-limit bucket key for client IP attempts.
     */
    public function ip_rate_limit_key( string $ip ): string {
        $clean = trim( $ip );

        return 'pl-ip:' . hash( 'sha256', '' !== $clean ? $clean : 'unknown' );
    }

    private function active_key(): string {
        if ( null !== $this->key_provider ) {
            $provided = (string) ( $this->key_provider )();
            if ( '' !== $provided ) {
                return $provided;
            }
        }

        if ( defined( self::CONST_KEY ) ) {
            $const_val = constant( self::CONST_KEY );
            if ( is_string( $const_val ) && '' !== $const_val ) {
                return $const_val;
            }
        }

        if ( function_exists( 'get_option' ) ) {
            $existing = get_option( self::OPTION_KEY, '' );
            if ( is_string( $existing ) && '' !== $existing ) {
                return $existing;
            }

            $generated = bin2hex( random_bytes( 32 ) );
            if ( function_exists( 'add_option' ) ) {
                if ( add_option( self::OPTION_KEY, $generated, '', 'no' ) ) {
                    return $generated;
                }
                $reread = get_option( self::OPTION_KEY, '' );
                if ( is_string( $reread ) && '' !== $reread ) {
                    return $reread;
                }
            }

            return $generated;
        }

        throw new \RuntimeException( 'No HMAC key source available' );
    }

    private function previous_key(): ?string {
        if ( null !== $this->previous_key_provider ) {
            $provided = ( $this->previous_key_provider )();

            return is_string( $provided ) && '' !== $provided ? $provided : null;
        }

        if ( defined( self::CONST_PREVIOUS_KEY ) ) {
            $const_val = constant( self::CONST_PREVIOUS_KEY );

            return is_string( $const_val ) && '' !== $const_val ? $const_val : null;
        }

        if ( function_exists( 'get_option' ) ) {
            $existing = get_option( self::OPTION_PREVIOUS_KEY, '' );

            return is_string( $existing ) && '' !== $existing ? $existing : null;
        }

        return null;
    }
}

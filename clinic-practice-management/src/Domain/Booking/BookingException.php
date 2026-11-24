<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Booking;

use DomainException;

/**
 * خطای جریان رزرو — کد `CLINIC_*` (ADR-0019) + HTTP + Data.
 * Controller این Exception را مستقیم به WP_Error نگاشت می‌کند.
 */
final class BookingException extends DomainException
{
    private bool $profile_incomplete = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of(string $code, string $message, int $http = 400, array $data = []): self
    {
        return new self($code, $message, $http, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function profile_incomplete( string $code, string $message, int $http = 400, array $data = [] ): self {
        $exception                     = new self( $code, $message, $http, $data );
        $exception->profile_incomplete = true;

        return $exception;
    }

    public function is_profile_incomplete(): bool {
        return $this->profile_incomplete;
    }
}

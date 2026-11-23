<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Logging;

use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Operational Log — جدا از Audit (ADR-0008, T-14).
 *
 * سیاست: هیچ PHI در پیام/Context نمی‌رود. اگر لازم شد، فقط IDها (و نه محتوا/موبایل/...).
 */
final class OpLogger
{
    public const LEVELS = ['debug', 'info', 'warning', 'error'];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        try {
            // نمایش خطای DB خاموش — نوشتن Log عملیاتی هرگز نباید خروجی Request/
            // Bootstrap را آلوده کند (مثلاً وقتی خود جدولِ Log هنوز ساخته نشده).
            $wpdb = $this->db->wpdb();
            $hadSuppression = $wpdb->suppress_errors;
            $wpdb->suppress_errors(true);
            try {
                $this->db->insert('cpms_operational_logs', [
                    'level' => $level,
                    // B0-PR3: پیامِ لاگ هم متنِ آزاد است — ارقامِ کد ملی در
                    // هر سه شکلِ ASCII/فارسی/عربی قبل از ذخیره جمع‌بندی می‌شوند.
                    'message'      => mb_substr( NationalIdValidator::redact_digits( $message ), 0, 500 ),
                    'context_json' => json_encode($this->sanitize($context), JSON_UNESCAPED_UNICODE) ?: null,
                    'request_id' => $this->requestId(),
                    'created_at' => $this->db->nowUtcSql(),
                ]);
            } finally {
                $wpdb->suppress_errors($hadSuppression);
            }
        } catch (\Throwable) {
            // Log هرگز نباید Request را شکست بدهد
            // B0-PR3: مسیرِ جایگزین نباید پیامِ خام را به error_log بنویسد.
            error_log( '[cpms] op-log write failed' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- constant-only fallback; never includes request data.
        }
    }

    /**
     * حذف کلیدهای حساس و کوتاه‌کردن مقادیر — خط دفاع دوم در برابر PHI نادرست.
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $sensitive = ['mobile', 'phone', 'national_id', 'otp', 'code', 'password', 'token', 'secret', 'card', 'content', 'text'];
        $out = [];
        foreach ($context as $k => $v) {
            $key          = (string) $k;
            $is_sensitive = 1 === preg_match( '/(' . implode( '|', $sensitive ) . ')/i', $key );
            $base_key     = NationalIdValidator::redact_digits( $key );
            $safe_key     = $base_key;
            $suffix       = 2;
            while ( array_key_exists( $safe_key, $out ) ) {
                $safe_key = $base_key . '~' . $suffix;
                ++$suffix;
            }
            if ( $is_sensitive ) {
                $out[ $safe_key ] = '[masked]';
                continue;
            }
            if ( is_array( $v ) ) {
                $out[ $safe_key ] = $this->sanitize( $v );
                continue;
            }
            if ( is_string( $v ) ) {
                // B0-PR3: مقدارِ متنیِ آزاد (مثلاً `error` با پیامِ exception یا
                // `reason` کاربر) می‌تواند ارقامِ کد ملی داشته باشد.
                $safe             = NationalIdValidator::redact_digits( $v );
                $out[ $safe_key ] = mb_strlen( $safe ) > 200 ? mb_substr( $safe, 0, 200 ) . '…' : $safe;
                continue;
            }
            if ( is_int( $v ) || is_float( $v ) ) {
                $safe             = NationalIdValidator::redact_digits( (string) $v );
                $out[ $safe_key ] = $safe === (string) $v ? $v : $safe;
                continue;
            }
            $out[ $safe_key ] = is_scalar( $v ) || null === $v ? $v : gettype( $v );
        }

        return $out;
    }

    private function requestId(): ?string
    {
        if (function_exists('cpms_request_id')) {
            return cpms_request_id();
        }

        return null;
    }
}

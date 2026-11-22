<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Validators;

/**
 * اعتبارسنجی کد ملی ۱۰ رقمی (algorism استاندارد + checksum) — خالص.
 */
final class NationalIdValidator
{
    public static function isValid(string $input): bool
    {
        $nid = preg_replace('/\D/', '', $input);
        if ($nid === null || strlen($nid) !== 10) {
            return false;
        }
        if (preg_match('/^(\d)\1{9}$/', $nid)) {
            return false; // مثل 1111111111
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nid[$i] * (10 - $i);
        }
        $remainder = $sum % 11;

        if ($remainder === 0) {
            return (int) $nid[9] === 0;
        }
        if ($remainder === 1) {
            return (int) $nid[9] === 1;
        }

        return (int) $nid[9] === 11 - $remainder;
    }

    /**
     * Normalized exact 10 ASCII digits, or null.
     *
     * Trims the term and folds Persian (U+06F0..U+06F9) and Arabic-Indic
     * (U+0660..U+0669) digits to ASCII; the term qualifies as an exact
     * national-ID candidate IFF the whole folded string is exactly ten
     * ASCII digits. Anything else (letters, spaces, separators, 9/11-digit
     * strings, other Unicode digits) returns null. Checksum is deliberately
     * NOT required: matching an exact stored national_id is independent of
     * checksum validity.
     */
    public static function exact_ascii_digits( string $input ): ?string {
        $folded = strtr( trim( $input ), self::DIGIT_FOLD );

        return 1 === preg_match( '/^[0-9]{10}$/', $folded ) ? $folded : null;
    }

    private const DIGIT_FOLD = [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ];

    /**
     * Mask نمایشی (Audit): ***4567
     */
    public static function mask(string $nid): string
    {
        $clean = preg_replace('/\D/', '', $nid) ?: '';

        return strlen($clean) >= 4 ? '***' . substr($clean, -4) : '***';
    }

    /**
     * Redact NID digits inside arbitrary text — B0-PR3 (Audit/Log/اعلان).
     *
     * سیاست مالک: کد ملی هرگز در Audit، Log عملیاتی، متن اعلان یا کلید
     * Rate-Limit ثبت نمی‌شود — نه خام و نه Maskشده. چون متنِ آزاد (دلیل لغو،
     * یادداشت، متن پیام) می‌تواند ارقامِ کد ملی را در خود داشته باشد، کلِ
     * رشته اسکن می‌شود، نه فقط فیلدِ `national_id`.
     *
     * ابتدا ارقامِ فارسی (U+06F0..U+06F9) و عربی-هندی (U+0660..U+0669) به
     * ASCII تبدیل می‌شوند؛ سپس هر رشتهٔ پیوستهٔ ۱۰ رقم یا بیشتر با
     * `[nid-redacted]` جایگزین می‌شود. بنابراین «۰۰۱۲۳۴۵۶۷۳»، «٠٠١٢٣٤٥٦٧٣»،
     * «0012345673» و «کد ملی 0012345673 است» همه حتی یک رقم باقی نمی‌گذارند.
     *
     * False positiveِ پذیرفته‌شده (fail-closed): هر رشتهٔ عددیِ ۱۰ رقمی یا
     * بلندتر — مثلاً تلفنِ ۱۱ رقمی، شناسهٔ عددیِ بلند یا Timestamp — هم
     * جایگزین می‌شود. مقدارِ غیررشتهای (int/bool) دست نمی‌خورد؛ کد ملی همیشه
     * رشته است و کلیدِ `national_id` جداگانه و کامل حذف می‌شود.
     */
    public static function redact_digits(string $value): string
    {
        $folded = strtr($value, self::DIGIT_FOLD);

        return (string) preg_replace('/\d{10,}/', '[nid-redacted]', $folded);
    }
}

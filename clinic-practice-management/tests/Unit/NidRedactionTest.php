<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Sms\SmsTemplateRenderer;
use ClinicCore\Domain\Validators\NationalIdValidator;
use PHPUnit\Framework\TestCase;

/**
 * B0-PR3 — ارقامِ کد ملی هرگز در Audit/Log/اعلان باقی نمی‌مانند.
 *
 * یک تستِ خالص در پایین‌ترینِ seam: خودِ تابعِ جمع‌بندی و دو رندرِرندرِ
 * اعلان/SMS. هر متد هم یک شاهدِ مثبت (محتوای بی‌خطر دست‌نخورده می‌ماند) و
 * هم یک حالتِ منفی (ارقامِ کد ملی در هر سه شکلِ رقم از بین می‌رود) دارد.
 */
final class NidRedactionTest extends TestCase
{
    /** کد ملیِ معتبرِ تست (وزن‌های استاندارد) — هیچ‌کدام واقعی نیستند. */
    private const NID_ASCII = '0012345673';
    private const NID_PERSIAN = '۰۰۱۲۳۴۵۶۷۳';
    private const NID_ARABIC = '٠٠١٢٣٤٥٦٧٣';

    public function testRedactDigitsRemovesAsciiPersianAndArabicIndicRuns(): void
    {
        $in = 'A ' . self::NID_ASCII . ' B ' . self::NID_PERSIAN . ' C ' . self::NID_ARABIC . ' D';
        $out = NationalIdValidator::redact_digits($in);

        // منفی: هیچ‌یک از سه شکلِ رقم باقی نمی‌ماند.
        $this->assertStringNotContainsString(self::NID_ASCII, $out);
        $this->assertStringNotContainsString(self::NID_PERSIAN, $out);
        $this->assertStringNotContainsString(self::NID_ARABIC, $out);
        $this->assertStringNotContainsString('001234', $out, 'حتی زیررشتهٔ ارقام هم نباید بماند');

        // مثبت: متنِ بی‌خطرِ پیرامون دست‌نخورده می‌ماند (ضدِ vacuous).
        $this->assertSame('A [nid-redacted] B [nid-redacted] C [nid-redacted] D', $out);
    }

    public function testRedactDigitsKeepsShortAndNonNidNumbers(): void
    {
        // ۹ رقمی، ۵ رقمی و ۴ رقمی کد ملی نیستند و نباید دست‌بختگان شوند.
        $in = 'کد 987654321 و 12345 و 1234';
        $this->assertSame($in, NationalIdValidator::redact_digits($in), 'رشتهٔ عددیِ کوتاه باید حفظ شود');

        // منفیِ همان متد: ۱۰ رقمیِ کناری باید جمع‌بندی شود.
        $mixed = 'کد 987654321 و ' . self::NID_ASCII . ' ' . self::NID_PERSIAN . ' ' . self::NID_ARABIC;
        $out = NationalIdValidator::redact_digits($mixed);
        $this->assertStringContainsString('987654321', $out, '۹ رقمی باید دست‌نخورده بماند');
        $this->assertStringNotContainsString(self::NID_ASCII, $out, '۱۰ رقمی باید جمع‌بندی شود');
        $this->assertStringNotContainsString(self::NID_PERSIAN, $out);
        $this->assertStringNotContainsString(self::NID_ARABIC, $out);
    }

    public function testRedactDigitsHandlesEmbeddedAndBoundaryRuns(): void
    {
        // داخلِ متن، با حروفِ پیچیده، و در مرزِ رشته.
        $out = NationalIdValidator::redact_digits(
            'x' . self::NID_ASCII . 'y ' . self::NID_PERSIAN . ' ' . self::NID_ARABIC
        );
        $this->assertSame(
            'x[nid-redacted]y [nid-redacted] [nid-redacted]',
            $out,
            'کد ملی بین حروف هم جمع‌بندی می‌شود'
        );
        $this->assertStringNotContainsString(self::NID_ASCII, $out);
        $this->assertStringNotContainsString(self::NID_PERSIAN, $out);
        $this->assertStringNotContainsString(self::NID_ARABIC, $out);

        // ۱۱ رقمی: fail-closed کامل جایگزین می‌شود (false positiveِ پذیرفته‌شده).
        $this->assertSame('[nid-redacted]', NationalIdValidator::redact_digits('12345678901'));

        // رشتهٔ خالی و بدونِ رقم بی‌خطر است.
        $this->assertSame('', NationalIdValidator::redact_digits(''));
        $this->assertSame('متن بدون رقم', NationalIdValidator::redact_digits('متن بدون رقم'));
    }

    public function testNotificationRenderRedactsNidDigits(): void
    {
        $rendered = NotificationEvents::render(NotificationEvents::APPT_CONFIRMED, [
            'doctor_name' => 'دکتر ' . self::NID_ASCII,
            'appointment_date' => '1405/06/10 ' . self::NID_ARABIC,
            'appointment_time' => '09:30 ' . self::NID_PERSIAN,
        ]);

        // منفی: نه در عنوان و نه در متن، حتی یک رقمِ کد ملی نیست.
        $this->assertStringNotContainsString(self::NID_ASCII, (string) $rendered['body']);
        $this->assertStringNotContainsString(self::NID_PERSIAN, (string) $rendered['body']);
        $this->assertStringNotContainsString(self::NID_ARABIC, (string) $rendered['body']);
        $this->assertStringNotContainsString('001234', (string) $rendered['body']);
        $this->assertStringContainsString('[nid-redacted]', (string) $rendered['body']);

        // مثبت: ساختارِ اعلان و تاریخ/ساعت دست‌نخورده‌اند.
        $this->assertStringContainsString('نوبت شما با', (string) $rendered['body']);
        $this->assertStringContainsString('1405/06/10', (string) $rendered['body']);
        $this->assertStringContainsString('09:30', (string) $rendered['body']);
    }

    public function testNotificationRenderKeepsOrdinaryContent(): void
    {
        $rendered = NotificationEvents::render(NotificationEvents::QUEUE_CALLED, [
            'patient_name' => 'بیمار 987654321',
            'room' => 'اتاق ۱',
        ]);

        // مثبت: محتوای معمولی (کوتاه‌تر از ۱۰ رقم) باید بایت‌به‌بایت حفظ شود.
        $this->assertStringContainsString('بیمار 987654321', (string) $rendered['body']);
        $this->assertStringContainsString('اتاق ۱', (string) $rendered['body']);
        $this->assertStringNotContainsString('[nid-redacted]', (string) $rendered['body']);
    }

    public function testSmsTemplateRenderRedactsNidDigits(): void
    {
        $allowed = ['patient_name', 'doctor_name', 'appointment_date'];
        $out = SmsTemplateRenderer::render(
            'سلام {{patient_name}}، نوبت با {{doctor_name}} در {{appointment_date}}.',
            [
                'patient_name' => 'بیمار ' . self::NID_PERSIAN,
                'doctor_name' => 'دکتر ' . self::NID_ASCII,
                'appointment_date' => '1405/06/10 ' . self::NID_ARABIC,
            ],
            $allowed,
            ['patient_name', 'appointment_date']
        );

        // منفی: کد ملیِ فارسی در متنِ SMS نمی‌آید.
        $this->assertStringNotContainsString(self::NID_ASCII, $out);
        $this->assertStringNotContainsString(self::NID_PERSIAN, $out);
        $this->assertStringNotContainsString(self::NID_ARABIC, $out);
        $this->assertStringNotContainsString('001234', $out);
        $this->assertStringContainsString('[nid-redacted]', $out);

        // مثبت: تاریخ و ساختارِ پیام دست‌نخورده‌اند.
        $this->assertStringContainsString('1405/06/10', $out);
        $this->assertStringContainsString('نوبت با دکتر', $out);
    }

    public function testSmsTemplateRenderKeepsOtpAndDates(): void
    {
        // کد OTPِ ۶ رقمی و تاریخ/ساعت باید دست‌نخورده بمانند — جمع‌بندی نباید
        // اعتبارِ پیامِ ارسالی را بشکند.
        $allowed = ['otp_code', 'appointment_date', 'appointment_time', 'note'];
        $out = SmsTemplateRenderer::render(
            'کد: {{otp_code}} — {{appointment_date}} {{appointment_time}} {{note}}',
            [
                'otp_code' => '654321',
                'appointment_date' => '1405/06/10 ' . self::NID_ASCII,
                'appointment_time' => '09:30 ' . self::NID_PERSIAN,
                'note' => self::NID_ARABIC,
            ],
            $allowed,
            ['otp_code']
        );

        $this->assertSame('کد: 654321 — 1405/06/10 09:30 [nid-redacted] [nid-redacted] [nid-redacted]', $out);
        $this->assertStringNotContainsString(self::NID_ASCII, $out);
        $this->assertStringNotContainsString(self::NID_PERSIAN, $out);
        $this->assertStringNotContainsString(self::NID_ARABIC, $out);
    }
}

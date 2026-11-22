<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * B0-PR3 — گرگ‌ومیشِ مرکزیِ Audit/OpLog: هیچ رقمِ کد ملی در هیچ سطرِ
 * `cpms_audit_logs` / `cpms_operational_logs` باقی نمی‌ماند.
 *
 * این تست‌ها همان دو نویسندهٔ واقعی (`AuditLogger::log` و `OpLogger::write`)
 * را از بیرونِ هر callback صدا می‌زنند و سطرِ ذخیره‌شده را می‌خوانند؛
 * state restoration با rollbackِ خودکارِ WP_UnitTestCase تضمین می‌شود.
 */
final class NidAuditMaskingTest extends WP_UnitTestCase
{
    private const NID_ASCII = '0012345673';
    private const NID_PERSIAN = '۰۰۱۲۳۴۵۶۷۳';
    private const NID_ARABIC = '٠٠١٢٣٤٥٦٧٣';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testAuditChokepointRedactsNidKeyAndFreeText(): void
    {
        App::audit()->log(
            'B0_PR3_AUDIT',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            1,
            null,
            ['national_id' => self::NID_ASCII, 'note' => 'کد ملی ' . self::NID_ASCII . ' است', 'numeric_id' => 1234567890],
            ['free' => 'X ' . self::NID_PERSIAN . ' Y'],
            ['meta' => 'Z ' . self::NID_ARABIC . ' W']
        );

        $row = App::db()->fetchRow(
            'SELECT before_json, after_json, meta_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_AUDIT']
        );
        $this->assertIsArray($row, 'رویدادِ Audit باید ذخیره شده باشد');

        $before = json_decode((string) $row['before_json'], true);
        $after = json_decode((string) $row['after_json'], true);
        $meta = json_decode((string) $row['meta_json'], true);

        // منفی: کلیدِ `national_id` کامل حذف می‌شود (نه حتی مقدارِ Maskشده).
        $this->assertArrayNotHasKey('national_id', $before);
        foreach ([self::NID_ASCII, self::NID_PERSIAN, self::NID_ARABIC] as $nid) {
            $this->assertStringNotContainsString($nid, (string) $row['before_json']);
            $this->assertStringNotContainsString($nid, (string) $row['after_json']);
            $this->assertStringNotContainsString($nid, (string) $row['meta_json']);
        }
        $this->assertStringNotContainsString('001234', (string) $row['before_json']);
        $this->assertStringNotContainsString('1234567890', (string) $row['before_json']);

        // منفی: متنِ آزاد در هر سه شکلِ رقم — در before/after/meta.
        foreach ([$before, $after, $meta] as $decoded) {
            $this->assertStringNotContainsString('001234', (string) json_encode($decoded));
        }
        $this->assertSame('[nid-redacted]', $before['numeric_id']);
        $this->assertSame('[nid-redacted]', $after['free']);
        $this->assertSame('Z [nid-redacted] W', $meta['meta']);

        // مثبت: رویدادِ بی‌خطر همچنان زنجیره‌پس معتبر است (جمع‌بندی، شواهد را نابود نمی‌کند).
        $this->assertTrue(App::audit()->verify_chain(100)['ok'], 'زنجیرهٔ Audit نباید بشکند');
    }

    public function testAuditChokepointKeepsNonNidChangeSet(): void
    {
        App::audit()->log(
            'B0_PR3_AUDIT_OK',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            2,
            null,
            ['address' => 'خیابان 12', 'count' => 42, 'mobile' => '09121234567'],
            ['address' => 'خیابان 13', 'count' => 43],
        );

        $row = App::db()->fetchRow(
            'SELECT before_json, after_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_AUDIT_OK']
        );
        $before = json_decode((string) $row['before_json'], true);
        $after = json_decode((string) $row['after_json'], true);

        // مثبت: رشتهٔ عددیِ کوتاه و موبایلِ Maskشده دست‌نخورده‌اند.
        $this->assertSame('خیابان 12', $before['address']);
        $this->assertSame(42, $before['count']);
        $this->assertSame('[nid-redacted]', $before['mobile'], 'موبایلِ ۱۱ رقمی باید fail-closed بماند');
        $this->assertSame('خیابان 13', $after['address']);
    }

    public function testOpLogChokepointRedactsNidInMessageAndContext(): void
    {
        App::op()->error(
            'B0_PR3_OP_FAIL',
            [
                'note' => 'کد ملی ' . self::NID_ASCII,
                'error' => 'fail ' . self::NID_PERSIAN,
                'arabic' => 'note ' . self::NID_ARABIC,
                'numeric_id' => 1234567890,
                'benign' => 'ok',
            ]
        );

        $row = App::db()->fetchRow(
            'SELECT message, context_json FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_OP_FAIL']
        );
        $this->assertIsArray($row, 'رویدادِ لاگ عملیاتی باید ذخیره شده باشد');

        $context = json_decode((string) $row['context_json'], true);

        // منفی: پیام و هر دو مقدارِ متنی مقدار را جمع‌بندی می‌کنند.
        $this->assertStringNotContainsString('001234', (string) $row['context_json']);
        $this->assertStringNotContainsString('1234567890', (string) $row['context_json']);
        $this->assertSame('[nid-redacted]', $context['note']);
        $this->assertSame('fail [nid-redacted]', $context['error']);
        $this->assertSame('note [nid-redacted]', $context['arabic']);
        $this->assertSame('[nid-redacted]', $context['numeric_id']);

        // مثبت: مقدارِ بی‌خطر دست‌نخورده می‌ماند.
        $this->assertSame('ok', $context['benign']);
    }

    public function testOpLogChokepointRedactsNidInMessageText(): void
    {
        App::op()->warning(
            'B0_PR3_OP_MSG ' . self::NID_ASCII . ' ' . self::NID_PERSIAN . ' ' . self::NID_ARABIC
        );

        $row = App::db()->fetchRow(
            'SELECT message FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message LIKE %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_OP_MSG%']
        );
        $this->assertIsArray($row);
        $this->assertSame(
            'B0_PR3_OP_MSG [nid-redacted] [nid-redacted] [nid-redacted]',
            (string) $row['message']
        );
        $this->assertStringNotContainsString(self::NID_ASCII, (string) $row['message']);
        $this->assertStringNotContainsString(self::NID_PERSIAN, (string) $row['message']);
        $this->assertStringNotContainsString(self::NID_ARABIC, (string) $row['message']);
    }

    public function testOpLogChokepointKeepsOrdinaryMessage(): void
    {
        App::op()->info('B0_PR3_OP_OK', ['benign' => 'ok', 'count' => 7]);

        $row = App::db()->fetchRow(
            'SELECT message, context_json FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_OP_OK']
        );
        $context = json_decode((string) $row['context_json'], true);

        $this->assertSame('B0_PR3_OP_OK', (string) $row['message']);
        $this->assertSame('ok', $context['benign']);
        $this->assertSame(7, $context['count']);
    }
}

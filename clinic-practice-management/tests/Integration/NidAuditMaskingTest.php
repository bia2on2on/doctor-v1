<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
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
            [
                'national_id' => self::NID_ASCII,
                'note' => 'کد ملی ' . self::NID_ASCII . ' است',
                'numeric_id' => 1234567890,
                'mobile' => self::NID_ASCII,
                'field_' . self::NID_PERSIAN => 'key witness',
            ],
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
        $this->assertSame('[nid-redacted]', $before['mobile'], 'NID misplaced under mobile used to leak its last four digits');
        $this->assertSame('key witness', $before['field_[nid-redacted]']);
        $this->assertSame('X [nid-redacted] Y', $after['free']);
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

    public function testAuditPhoneKeepsLegacyTailMaskUnlessValueIsNidShaped(): void
    {
        App::audit()->log(
            'B0_PR3_PHONE_AUDIT',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            3,
            null,
            ['phone' => '02100000000'],
            ['phone' => self::NID_ASCII]
        );

        $row = App::db()->fetchRow(
            'SELECT before_json, after_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_PHONE_AUDIT']
        );
        $this->assertIsArray($row, 'phone audit row is persisted');
        $before = json_decode((string) $row['before_json'], true);
        $after = json_decode((string) $row['after_json'], true);

        $this->assertSame('***0000', $before['phone'], 'ordinary eleven-digit phone retains the legacy tail mask');
        $this->assertSame('[nid-redacted]', $after['phone'], 'ten-digit NID-shaped phone is fully redacted');
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
        $this->assertSame('کد ملی [nid-redacted]', $context['note']);
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

    public function testAuditLoggerRedactsObjectPayloads(): void
    {
        $payload = new class(self::NID_ASCII, self::NID_PERSIAN, self::NID_ARABIC) implements \JsonSerializable {
            public function __construct(
                private readonly string $ascii,
                private readonly string $persian,
                private readonly string $arabic
            ) {
            }

            public function jsonSerialize(): array
            {
                return [
                    'ascii' => $this->ascii,
                    'persian' => $this->persian,
                    'arabic' => $this->arabic,
                ];
            }
        };

        App::audit()->log(
            'B0_PR3_OBJECT',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            3,
            null,
            ['payload' => $payload, 'note' => 'ordinary text'],
            null
        );
        $row = App::db()->fetchRow(
            'SELECT before_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_OBJECT']
        );
        $json = (string) $row['before_json'];
        $before = json_decode($json, true);

        $this->assertIsArray($before);
        $this->assertStringStartsWith('[object:', $before['payload']);
        $this->assertStringEndsWith(']', $before['payload']);
        $this->assertSame('ordinary text', $before['note']);
        foreach ([self::NID_ASCII, self::NID_PERSIAN, self::NID_ARABIC] as $nid) {
            $this->assertStringNotContainsString($nid, $json);
        }
        $this->assertStringNotContainsString('001234', $json);
    }

    public function testAuditAndOpLogTruncationLimitsRemainEnforced(): void
    {
        App::audit()->log(
            'B0_PR3_TRUNC_AUDIT',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            4,
            null,
            ['note' => str_repeat('a', 2001)],
            null
        );
        $auditRow = App::db()->fetchRow(
            'SELECT before_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_TRUNC_AUDIT']
        );
        $auditBefore = json_decode((string) $auditRow['before_json'], true);
        $this->assertSame(str_repeat('a', 2000) . '…[truncated]', $auditBefore['note']);

        App::op()->info('B0_PR3_TRUNC_OP ' . str_repeat('x', 600), ['note' => str_repeat('b', 201)]);
        $opRow = App::db()->fetchRow(
            'SELECT message, context_json FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message LIKE %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_TRUNC_OP%']
        );
        $context = json_decode((string) $opRow['context_json'], true);
        $this->assertSame(500, mb_strlen((string) $opRow['message']));
        $this->assertSame(str_repeat('b', 200) . '…', $context['note']);
    }

    public function testOpLogFailureFallbackDoesNotWriteRequestData(): void
    {
        global $wpdb;
        $table = App::db()->table('cpms_operational_logs');
        $pattern = '/\\AINSERT INTO\\s+`?' . preg_quote($table, '/') . '`?\\s*\\(/i';
        $matchedQueries = 0;
        $contents = '';
        $previousErrorLog = ini_get('error_log');
        $temporaryLog = tempnam(sys_get_temp_dir(), 'cpms-oplog-test-');
        $previousSuppression = (bool) $wpdb->suppress_errors;
        $fault = static function (string $query) use (&$matchedQueries, $pattern): string {
            if (preg_match($pattern, $query) === 1) {
                ++$matchedQueries;
                throw new \RuntimeException('synthetic database insert failure');
            }

            return $query;
        };

        try {
            $this->assertIsString($previousErrorLog);
            $this->assertNotFalse($temporaryLog);
            $this->assertNotFalse(ini_set('error_log', $temporaryLog));
            add_filter('query', $fault, 5, 1);
            App::op()->warning('B0_PR3_FALLBACK ' . self::NID_ASCII . ' ' . self::NID_PERSIAN . ' ' . self::NID_ARABIC);
            clearstatcache(true, $temporaryLog);
            $contents = (string) file_get_contents($temporaryLog);
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($previousSuppression);
            if (is_string($previousErrorLog)) {
                ini_set('error_log', $previousErrorLog);
            }
            if (is_string($temporaryLog) && file_exists($temporaryLog)) {
                unlink($temporaryLog);
            }
        }

        $this->assertSame(1, $matchedQueries, 'the exact operational-log INSERT shape must be intercepted once');
        $this->assertSame($previousSuppression, (bool) $wpdb->suppress_errors);
        $this->assertStringContainsString('[cpms] op-log write failed', $contents);
        $this->assertStringNotContainsString('B0_PR3_FALLBACK', $contents);
        foreach ([self::NID_ASCII, self::NID_PERSIAN, self::NID_ARABIC] as $nid) {
            $this->assertStringNotContainsString($nid, $contents);
        }
        if (is_string($temporaryLog) && file_exists($temporaryLog)) {
            unlink($temporaryLog);
        }
    }

    public function testOpLogRedactsNidInContextKeysNestedKeysAndCollisions(): void
    {
        App::op()->warning(
            'B0_PR3_KEY_REDACT',
            [
                'nid_' . self::NID_ASCII   => 'v1',
                'nid_' . self::NID_PERSIAN => 'v2',
                'nested'                   => [
                    'inner_' . self::NID_ARABIC => 'nested_val',
                ],
                'reason'                   => 'ok',
            ]
        );

        $row = App::db()->fetchRow(
            'SELECT context_json FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_KEY_REDACT']
        );
        $this->assertIsArray($row, 'operational log row is persisted');
        $raw = (string) $row['context_json'];
        $this->assertStringNotContainsString(self::NID_ASCII, $raw);
        $this->assertStringNotContainsString(self::NID_PERSIAN, $raw);
        $this->assertStringNotContainsString(self::NID_ARABIC, $raw);

        $ctx = json_decode($raw, true);
        $this->assertIsArray($ctx);
        $this->assertSame('v1', $ctx['nid_[nid-redacted]'] ?? null);
        $this->assertSame('v2', $ctx['nid_[nid-redacted]~2'] ?? null);
        $this->assertSame('nested_val', $ctx['nested']['inner_[nid-redacted]'] ?? null);
        $this->assertSame('ok', $ctx['reason'] ?? null);
    }

    public function testCorrelationIdRejectsNidRunsInBothSinksAndKeepsAuditChainValid(): void
    {
        $hadServer = array_key_exists('HTTP_X_CPMS_CORRELATION_ID', $_SERVER);
        $prevServer = $_SERVER['HTTP_X_CPMS_CORRELATION_ID'] ?? null;
        try {
            $_SERVER['HTTP_X_CPMS_CORRELATION_ID'] = self::NID_ASCII;
            $resolved = cpms_request_id();
            $this->assertIsString($resolved);
            $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', $resolved);

            App::op()->warning('B0_PR3_CORR_OP', ['ok' => 'yes']);
            App::audit()->log('B0_PR3_CORR_AUDIT', ['wp_user_id' => 1, 'role' => 'system'], 'test_resource', 4, null, null, null);
        } finally {
            if ($hadServer) {
                $_SERVER['HTTP_X_CPMS_CORRELATION_ID'] = $prevServer;
            } else {
                unset($_SERVER['HTTP_X_CPMS_CORRELATION_ID']);
            }
        }

        $opRow = App::db()->fetchRow(
            'SELECT request_id FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_CORR_OP']
        );
        $this->assertIsArray($opRow);
        $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', (string) ($opRow['request_id'] ?? ''));

        $auditRow = App::db()->fetchRow(
            'SELECT request_id FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_CORR_AUDIT']
        );
        $this->assertIsArray($auditRow);
        $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', (string) ($auditRow['request_id'] ?? ''));

        $chain = App::audit()->verifyChain();
        $this->assertTrue((bool) ($chain['ok'] ?? false), 'audit chain remains valid after correlation-id logging');
    }

    public function testCorrelationIdFromHeaderRejectsNidVariantsWithoutMemoBypass(): void
    {
        $valid = 'req-valid-12345678';
        $this->assertSame($valid, \ClinicCore\Infrastructure\Logging\CorrelationId::fromHeader($valid));

        foreach ([self::NID_ASCII, self::NID_PERSIAN, self::NID_ARABIC] as $nid) {
            $resolved = \ClinicCore\Infrastructure\Logging\CorrelationId::fromHeader($nid);
            $this->assertNotSame($nid, $resolved);
            $this->assertSame(1, preg_match('/^cpms-[0-9a-f]{16}$/', $resolved));
            $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', $resolved);
        }

        App::op()->warning('B0_PR3_CORR_DIRECT_OP', ['ok' => 'yes']);
        App::audit()->log('B0_PR3_CORR_DIRECT_AUDIT', ['wp_user_id' => 1, 'role' => 'system'], 'test_resource', 5, null, null, null);

        $opRow = App::db()->fetchRow(
            'SELECT request_id FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_CORR_DIRECT_OP']
        );
        $this->assertIsArray($opRow);
        $this->assertArrayHasKey('request_id', $opRow);
        $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', (string) $opRow['request_id']);

        $auditRow = App::db()->fetchRow(
            'SELECT request_id FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_CORR_DIRECT_AUDIT']
        );
        $this->assertIsArray($auditRow);
        $this->assertArrayHasKey('request_id', $auditRow);
        $this->assertDoesNotMatchRegularExpression('/[0-9۰-۹٠-٩]{10,}/u', (string) $auditRow['request_id']);

        $chain = App::audit()->verifyChain();
        $this->assertArrayHasKey('ok', $chain);
        $this->assertTrue((bool) $chain['ok']);
    }

    public function testAuditLoggerKeyCollisionsPreserveValuesAndCapIterations(): void
    {
        $many = [];
        for ($i = 0; $i < 102; $i++) {
            $many['k_' . str_pad((string) $i, 10, '0', STR_PAD_LEFT)] = 'v' . $i;
        }
        App::audit()->log(
            'B0_PR3_AUDIT_COLLISION',
            ['wp_user_id' => 1, 'role' => 'system'],
            'test_resource',
            6,
            null,
            ['field_' . self::NID_ASCII => 'first', 'field_[nid-redacted]' => 'second', 'reason' => 'ok'],
            ['nested' => ['k_' . self::NID_PERSIAN => 'n1', 'k_' . self::NID_ARABIC => 'n2']],
            $many
        );

        $row = App::db()->fetchRow(
            'SELECT before_json, after_json, meta_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['B0_PR3_AUDIT_COLLISION']
        );
        $this->assertIsArray($row);
        $before = json_decode((string) $row['before_json'], true);
        $after  = json_decode((string) $row['after_json'], true);
        $meta   = json_decode((string) $row['meta_json'], true);

        $this->assertIsArray($before);
        $this->assertArrayHasKey('field_[nid-redacted]', $before);
        $this->assertArrayHasKey('field_[nid-redacted]~2', $before);
        $this->assertArrayHasKey('reason', $before);
        $this->assertSame('first', $before['field_[nid-redacted]']);
        $this->assertSame('second', $before['field_[nid-redacted]~2']);
        $this->assertSame('ok', $before['reason']);

        $this->assertIsArray($after);
        $this->assertArrayHasKey('nested', $after);
        $this->assertArrayHasKey('k_[nid-redacted]', $after['nested']);
        $this->assertArrayHasKey('k_[nid-redacted]~2', $after['nested']);
        $this->assertSame('n1', $after['nested']['k_[nid-redacted]']);
        $this->assertSame('n2', $after['nested']['k_[nid-redacted]~2']);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('k_[nid-redacted]~101', $meta);
        $this->assertArrayHasKey('[collision-overflow]', $meta);

        $chain = App::audit()->verifyChain();
        $this->assertArrayHasKey('ok', $chain);
        $this->assertTrue((bool) $chain['ok']);
    }

    /**
     * B0-PR3 follow-up — the stored payload copy must not keep a raw NID.
     *
     * `insertNotification()` always stored the caller's variables verbatim in
     * `payload_json.vars` beside the already redacted title/body, so a caller
     * that passed a national ID produced an at-rest NID in cpms_notifications.
     * Contract asserted here: (a) no NID digit run survives anywhere in the
     * stored payload, (b) ordinary variables are still stored unchanged — the
     * masking is targeted, not a whole-payload wipe.
     */
    public function testNotificationPayloadVarsMaskNidWhileOrdinaryVarsRemain(): void
    {
        $nid = PatientTwoProofTestHelper::next_valid_national_id();

        $notifId = App::notificationService()->publishToPatient(
            1,
            4242,
            NotificationEvents::APPT_CONFIRMED,
            [
                'patient_name' => 'بیمار ' . $nid,
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/10',
                'appointment_time' => '09:30',
                'clinic_name' => 'مطب تست',
            ],
            'nid_mask_notification_payload_test_' . random_int(100000, 999999)
        );

        self::assertNotNull($notifId);
        $row = App::db()->fetchRow(
            'SELECT payload_json FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$notifId]
        );
        self::assertIsArray($row);
        $payloadJson = (string) $row['payload_json'];

        // Negative: no NID digit run survives in the stored payload.
        self::assertStringNotContainsString($nid, $payloadJson);
        self::assertStringContainsString('[nid-redacted]', $payloadJson);

        // Positive witness: ordinary variables are stored unchanged.
        $payload = json_decode($payloadJson, true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('vars', $payload);
        self::assertIsArray($payload['vars']);
        self::assertArrayHasKey('doctor_name', $payload['vars']);
        self::assertSame('دکتر تست', $payload['vars']['doctor_name']);
        self::assertArrayHasKey('appointment_date', $payload['vars']);
        self::assertSame('1405/06/10', $payload['vars']['appointment_date']);
        self::assertArrayHasKey('body', $payload);
        self::assertStringContainsString('نوبت شما با', (string) $payload['body']);
        self::assertStringNotContainsString($nid, (string) $payload['body']);
    }
}

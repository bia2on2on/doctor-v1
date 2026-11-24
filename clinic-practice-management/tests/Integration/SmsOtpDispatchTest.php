<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\PatientTwoProofTestHelper;
use ClinicCore\Tests\Support\RecordingSmsProvider;
use WP_UnitTestCase;

/**
 * D5 — OTP delivery without secret persistence (real MySQL, recording provider).
 *
 * PROVEN DEFECT this suite pins down: for a plain-text (non-template) OTP the
 * stored message is deliberately masked ("***"), and dispatchMessage() handed
 * that masked row text straight to the provider's sendText(), so the SMS
 * transport received "***" instead of the real one-time code.
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. an immediate plain-text OTP dispatch gives the provider the REAL code,
 *     transiently in memory only;
 *  2. the persisted cpms_sms_messages row keeps the masked text and a NULL
 *     vars_json — no plaintext OTP at rest and no OTP in queue data;
 *  3. template mode still works and carries the live code as a variable;
 *  4. a re-drive that no longer holds the live code fails CLOSED with the
 *     explicit CLINIC_SMS_OTP_NO_RETRY classification — it never sends the
 *     masked text as if delivered and never calls the provider;
 *  5. non-OTP messages keep their existing stored-text behavior.
 */
final class SmsOtpDispatchTest extends WP_UnitTestCase
{
    private const MOBILE = '09123450001';
    private const CLINIC_ID = 1;

    private RecordingSmsProvider $recording;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();

        $this->recording = new RecordingSmsProvider();
        App::providers()->register($this->recording);

        App::settings()->set('sms.provider', RecordingSmsProvider::ID);
        App::settings()->set('sms.templates', []);
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        App::settings()->set('sms.provider', 'log');
        App::settings()->set('sms.templates', []);
        Settings::flushCache();
        parent::tearDown();
    }

    public function testPlainTextOtpProviderReceivesRealCodeWhileStoredRowStaysMasked(): void
    {
        $code = '482915';

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertGreaterThan(0, $result['message_id']);

        // The transport received the real code, exactly once, as plain text.
        self::assertCount(1, $this->recording->textSends, 'one plain-text provider call must happen');
        self::assertSame([], $this->recording->templateSends, 'text mode must not use the template transport');
        $outbound = $this->recording->textSends[0];
        self::assertSame(self::MOBILE, $outbound['mobile']);
        self::assertStringContainsString($code, $outbound['message'], 'the provider must receive the real OTP, not the mask');
        self::assertStringNotContainsString('***', $outbound['message'], 'the outbound provider text must not be masked');

        // The durable row keeps the mask and never stores the code or its vars.
        $row = $this->fetchRow((int) $result['message_id']);
        self::assertSame(SmsMessageStatus::SENT, (string) $row['status']);
        self::assertStringContainsString('***', (string) $row['message'], 'the stored message must stay masked');
        self::assertStringNotContainsString($code, (string) $row['message'], 'the raw OTP must never be persisted');
        self::assertNull($row['vars_json'], 'OTP variables must never be persisted');

        // No OTP value anywhere else in the row or in the durable job queue.
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->queueRowsContaining($code), 'no queue payload may carry the OTP');
    }

    public function testTemplateModeStillDeliversLiveOtpVariableAndStoresPlaceholder(): void
    {
        App::settings()->set('sms.templates', ['otp' => ['template_id' => 'TPL-OTP-1', 'updated_at' => gmdate('c')]]);
        Settings::flushCache();

        $code = '731046';
        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertCount(1, $this->recording->templateSends, 'template mode must use the template transport');
        self::assertSame([], $this->recording->textSends);

        $tpl = $this->recording->templateSends[0];
        self::assertSame('TPL-OTP-1', $tpl['template_id']);
        self::assertSame($code, (string) ($tpl['variables']['otp_code'] ?? ''), 'the template variables must carry the real OTP');

        $row = $this->fetchRow((int) $result['message_id']);
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE), 'no OTP at rest in template mode either');
        self::assertNull($row['vars_json']);
        self::assertSame('TPL-OTP-1', (string) $row['template_id']);
    }

    public function testReDriveWithoutLiveOtpFailsClosedAndNeverCallsProvider(): void
    {
        $code = '905612';
        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertCount(1, $this->recording->textSends);

        // Simulate a post-exit re-drive (job/manual retry in another process):
        // put the row back into a non-terminal state and dispatch without vars.
        $messageId = (int) $result['message_id'];
        App::db()->update('cpms_sms_messages', ['status' => SmsMessageStatus::QUEUED, 'failure_code' => null], ['id' => $messageId]);

        App::smsService()->dispatchMessage($messageId);

        $row = $this->fetchRow($messageId);
        self::assertSame(SmsMessageStatus::FAILED, (string) $row['status'], 'an OTP without live vars must fail closed');
        self::assertSame(
            \ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE,
            (string) $row['failure_code'],
            'the explicit no-retry classification must be recorded'
        );
        self::assertCount(1, $this->recording->textSends, 'the fail-closed path must not call the provider again');
        self::assertStringContainsString('***', (string) $row['message'], 'the stored message must remain masked');
        self::assertNull($row['vars_json']);
    }

    public function testFailedImmediateOtpFailsClosedWithoutRetryableClassification(): void
    {
        $code = '664820';
        $this->recording->failNextRetryable = true;

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );
        // P2: an OTP is always inline and never enqueued, so even with the live
        // code in memory a retryable transport failure must be classified FAILED
        // (never RETRYING / retry_enqueued) — no recoverable retry job exists.
        self::assertSame(SmsMessageStatus::FAILED, $result['status']);

        $messageId = (int) $result['message_id'];
        $row = $this->fetchRow($messageId);
        self::assertSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE), 'a failed send must not leave the OTP at rest');
        self::assertNull($row['vars_json']);

        // A later re-drive keeps the fail-closed state and never calls the provider.
        App::smsService()->dispatchMessage($messageId);
        $row = $this->fetchRow($messageId);
        self::assertSame(SmsMessageStatus::FAILED, (string) $row['status']);
        self::assertSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
        self::assertCount(0, $this->recording->textSends, 'no masked or retried OTP send must reach the provider');
    }

    public function testRetryableNonOtpFailureStillClassifiesAsRetrying(): void
    {
        // P2 must not change the ordinary queued-message retry contract: only OTP
        // events fail closed; a retryable non-OTP inline failure stays RETRYING.
        $this->recording->failNextRetryable = true;

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name'     => 'بیمار تست',
                'appointment_date' => '1405/08/01',
                'appointment_time' => '09:00',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::RETRYING, $result['status']);
        $row = $this->fetchRow((int) $result['message_id']);
        self::assertSame('RETRYING', (string) $row['status']);
        self::assertNotSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
    }

    public function testNonOtpMessagesKeepStoredTextAndVarsBehavior(): void
    {
        $vars = [
            'patient_name' => 'بیمار تست',
            'doctor_name' => 'دکتر تست',
            'appointment_date' => '1405/06/10',
            'appointment_time' => '09:30',
            'clinic_name' => 'مطب تست',
        ];

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            $vars,
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        self::assertCount(1, $this->recording->textSends);
        $row = $this->fetchRow((int) $result['message_id']);
        // Non-OTP behavior is untouched: provider text equals the stored text,
        // and non-secret variables remain persisted for template retries.
        self::assertSame((string) $row['message'], $this->recording->textSends[0]['message']);
        self::assertNotNull($row['vars_json']);
        self::assertStringContainsString('بیمار تست', (string) $row['vars_json']);
    }

    /**
     * B0-PR3 follow-up — `vars_json` is a stored copy of the caller's
     * variables (and the template-variable source on a later dispatch). It
     * used to be persisted verbatim, so a caller passing a national ID left a
     * raw NID at rest in cpms_sms_messages.
     *
     * Contract asserted here: the stored copy masks NID-shaped digit runs
     * while keeping ordinary values for retries, AND delivery is unchanged —
     * the provider still receives the legitimate rendered text.
     */
    public function testStoredVarsMaskNidWhileDeliveredTextKeepsOrdinaryContent(): void
    {
        $nid = PatientTwoProofTestHelper::next_valid_national_id();

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name' => 'بیمار تست',
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/10',
                'appointment_time' => '09:30',
                'clinic_name' => 'مطب تست',
                'national_id' => $nid,
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        $row = $this->fetchRow((int) $result['message_id']);
        // Stored vars copy: NID masked, ordinary values kept for retries.
        self::assertNotNull($row['vars_json']);
        self::assertStringNotContainsString($nid, (string) $row['vars_json']);
        self::assertStringContainsString('[nid-redacted]', (string) $row['vars_json']);
        $storedVars = json_decode((string) $row['vars_json'], true);
        self::assertIsArray($storedVars);
        self::assertArrayHasKey('patient_name', $storedVars);
        self::assertSame('بیمار تست', $storedVars['patient_name']);
        self::assertArrayHasKey('appointment_date', $storedVars);
        self::assertSame('1405/06/10', $storedVars['appointment_date']);

        // Delivery witness: the provider still receives the legitimate text.
        self::assertCount(1, $this->recording->textSends);
        self::assertSame((string) $row['message'], $this->recording->textSends[0]['message']);
        self::assertStringContainsString('بیمار تست', $this->recording->textSends[0]['message']);
        self::assertStringContainsString('1405/06/10', $this->recording->textSends[0]['message']);
        self::assertStringNotContainsString($nid, $this->recording->textSends[0]['message']);
    }

    /**
     * B0-PR3 follow-up, template path — the stored `vars_json` is exactly the
     * variable map handed to a template provider on a later dispatch, so the
     * masked copy must still carry every ordinary variable while no NID digit
     * run reaches the provider payload.
     */
    public function testTemplateDispatchUsesMaskedStoredVarsButDeliversOrdinaryOnes(): void
    {
        App::settings()->set('sms.templates', ['appointment_reminder' => ['template_id' => 'TPL-NID-1', 'updated_at' => gmdate('c')]]);
        Settings::flushCache();

        $nid = PatientTwoProofTestHelper::next_valid_national_id();
        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name' => 'بیمار تست',
                'appointment_date' => '1405/06/10',
                'appointment_time' => '09:30',
                'national_id' => $nid,
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        self::assertSame([], $this->recording->textSends);
        self::assertCount(1, $this->recording->templateSends);
        $variables = $this->recording->templateSends[0]['variables'];
        self::assertIsArray($variables);
        self::assertArrayHasKey('patient_name', $variables);
        self::assertSame('بیمار تست', $variables['patient_name']);
        self::assertArrayHasKey('national_id', $variables);
        self::assertSame('[nid-redacted]', $variables['national_id']);
        self::assertArrayHasKey('appointment_date', $variables);
        self::assertSame('1405/06/10', $variables['appointment_date']);
    }

    /**
     * F2 closure - :302 above is vacuous alone: there the NID lives only in
     * `national_id`, which APPT_REMINDER (SmsEvents.php:58-63) never renders.
     * Here the NID sits in `patient_name`, which it does render, so
     * SmsTemplateRenderer.php:62 (redact_digits) is the line under test.
     */
    public function testRenderedTextMasksNidPlacedInAVariableTheTemplateRenders(): void
    {
        $nid = PatientTwoProofTestHelper::next_valid_national_id();

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name' => 'بیمار ' . $nid,
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/10',
                'appointment_time' => '09:30',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        self::assertCount(1, $this->recording->textSends);
        $delivered = (string) $this->recording->textSends[0]['message'];
        // (a) the redaction marker reaches the delivered text, because the
        // rendered variable really carried the digits.
        self::assertStringContainsString('[nid-redacted]', $delivered);
        // (b) the raw NID never does.
        self::assertStringNotContainsString($nid, $delivered);
        // (c) positive witness: ordinary rendered values survive unmasked.
        self::assertStringContainsString('دکتر تست', $delivered);
        self::assertStringContainsString('1405/06/10', $delivered);
    }

    // ------------------------------------------------------------------

    /**
     * D7 — OtpService must never report retry_enqueued=true when dispatch
     * fails: OTP delivery is inline-only, the plaintext code lives in this
     * request's memory and no recoverable queue retry can ever run. A
     * structural failure (here a real MySQL 1054 on the sms message INSERT,
     * which SmsService cannot catch internally) must surface as an honest
     * sms_sent=false / retry_enqueued=false while the challenge token stays
     * durable for a fresh user-initiated request.
     */
    public function testOtpServiceNeverReportsFalseRetryEnqueuedOnStructuralDispatchFailure(): void
    {
        $mobile = '0912' . random_int(4000000, 4999999);

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_sms_messages')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };
        global $wpdb;
        $suppressed = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);

        try {
            $result = App::otpService()->request($mobile, 'login', null, null, self::CLINIC_ID);
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($suppressed);
        }

        self::assertFalse($result['sms_sent'], 'the failed dispatch must not be reported as sent');
        self::assertFalse($result['retry_enqueued'], 'no retry can ever be enqueued for an inline OTP — the flag must stay honest');
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s',
                [$mobile]
            ),
            'the durable challenge token survives the dispatch failure for an authorized fresh request'
        );
        self::assertSame(
            0,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages')),
            'the faulted message row must not exist — and therefore no plaintext OTP can be at rest'
        );
        self::assertGreaterThan(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
                ['OTP_SENT_FAIL']
            ),
            'the structural failure must be audited as OTP_SENT_FAIL'
        );

        // Authorized fresh request (fault removed): normal honest success flags.
        $mobileOk = '0912' . random_int(5000000, 5999999);
        $ok = App::otpService()->request($mobileOk, 'login', null, null, self::CLINIC_ID);
        self::assertTrue($ok['sms_sent']);
        self::assertFalse($ok['retry_enqueued'], 'a successful inline OTP send never enqueues a retry either');
    }

    /**
     * Item 1 (independent reader BLOCKER) - a privileged test-send carries STAFF
     * free text, so a NID can be pasted into `cpms_sms_messages.message`.
     * VERIFIED before the fix: SmsService.php:253-254 put the override text into
     * $text untouched and :305 persisted it raw. The contract pinned here: the
     * PERSISTED copy is masked exactly like every other stored text, while the
     * text handed to the provider is deliberately NOT changed (open product
     * question for the owner, not a regression): the service passes it to
     * dispatchMessage() as a live in-memory override (the same transient
     * mechanism an OTP code uses), so the provider still receives the raw text
     * and the row never does.
     */
    public function testTestSendMasksNidInPersistedMessageButDeliversStaffTextUnchanged(): void
    {
        $nid = PatientTwoProofTestHelper::next_valid_national_id();
        self::assertTrue(NationalIdValidator::isValid($nid), 'precondition: the helper returns a checksum-valid NID');
        // Unique per run: the lookup below keys on the recipient, and a rerun in
        // the same process must never see an earlier row.
        $mobile = '0912' . sprintf('%07d', (int) (microtime(true) * 1000) % 10000000);
        $typed = 'تست ارسال؛ کد ملی ' . $nid . ' را بررسی کنید';

        // testSend() resolves the Clinic from the live operation scope, so bind
        // it explicitly and restore whatever was there before (try/finally), the
        // same way the other integration suites do via App::replaceExplicitScope.
        $lastId = $this->storedMessageIdFor($mobile);
        $previous = ScopeContext::tryGet();
        try {
            App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));
            $result = App::smsService()->testSend($mobile, $typed, 1);
        } finally {
            App::replaceExplicitScope($previous);
        }
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        // (1) the real stored row: the raw NID is absent and the marker is there.
        $messageId = $this->storedMessageIdFor($mobile);
        self::assertGreaterThan($lastId, $messageId, 'precondition: the call inserted exactly the new row we read');
        $row = $this->fetchRow($messageId);
        $stored = (string) $row['message'];
        self::assertStringNotContainsString($nid, $stored, 'the persisted test-send text must not keep the raw NID');
        self::assertStringContainsString('[nid-redacted]', $stored, 'the persisted copy must carry the redaction marker');
        // (2) positive witness: ordinary words of the same fixture survive.
        self::assertStringContainsString('تست ارسال', $stored);
        self::assertStringContainsString('را بررسی کنید', $stored);
        self::assertSame('test', (string) $row['event']);

        // (3) no other persisted column of that row keeps the digits.
        self::assertStringNotContainsString($nid, (string) json_encode($row, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->queueRowsContaining($nid), 'no queue payload may carry the test text');

        // (4) the provider still gets exactly the staff text. PENDING OWNER
        // DECISION: whether delivery itself should be masked is an open product
        // question (reported), so this assertion pins the CURRENT behaviour only
        // and must be revisited if the owner chooses to mask delivery too.
        self::assertCount(1, $this->recording->textSends);
        self::assertSame($typed, (string) $this->recording->textSends[0]['message'], 'delivered text is a product decision and is unchanged by this fix');
    }

    /**
     * Task D (b) - the live override is bound to ONE row and cleared afterwards.
     * The binding is private, so this pins the observable contract: after
     * sendEvent() returns, a later dispatch of a DIFFERENT row must deliver that
     * row's own stored text and never the bound staff text. Dropping the
     * finally-clear in SmsService::sendEvent() (or making
     * live_text_applies_to() always true) would let the staff text leak onto the
     * next dispatched row and fail the assertions below.
     */
    public function testLiveTextOverrideIsBoundToOneRowAndNeverLeaksToAnother(): void
    {
        $nid = PatientTwoProofTestHelper::next_valid_national_id();
        $typed = 'تست ارسال؛ کد ملی ' . $nid . ' را بررسی کنید';

        // A second message sent inline (the established successful non-OTP
        // path), then put back into a non-terminal state so it can be
        // dispatched once more AFTER the test-send.
        $other = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name' => 'بیمار تست',
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/10',
                'appointment_time' => '09:30',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $other['status'], 'precondition: the other row sent inline');
        $otherId = (int) $other['message_id'];
        $otherStored = (string) $this->fetchRow($otherId)['message'];

        $before = count($this->recording->textSends);
        $previous = ScopeContext::tryGet();
        try {
            App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));
            App::smsService()->testSend('0912' . sprintf('%07d', 7_000_001), $typed, 1);
            // Re-drive the OTHER row after the test-send finished.
            App::db()->update('cpms_sms_messages', ['status' => SmsMessageStatus::QUEUED], ['id' => $otherId]);
            App::smsService()->dispatchMessage($otherId);
        } finally {
            App::replaceExplicitScope($previous);
        }

        $sent = array_slice($this->recording->textSends, $before);
        self::assertCount(2, $sent, 'both messages must reach the provider');
        self::assertSame($typed, (string) $sent[0]['message'], 'the test-send delivers its own live text verbatim');
        $deliveredOther = (string) $sent[1]['message'];
        self::assertSame($otherStored, $deliveredOther, 'the later row delivers exactly its own stored text');
        self::assertStringNotContainsString($typed, $deliveredOther, 'the later row must never receive the bound staff text');
        self::assertStringContainsString('بیمار تست', $deliveredOther, 'witness: the later row really rendered its own content');
    }

    /**
     * Task D (b) BLOCKER-2 isolation, half 2 of 2: the ROW-ID CHECK ALONE.
     *
     * The combined test above cannot fail on the id check alone: the finally
     * clears the slot before the next dispatch, so no stale binding ever exists
     * to be mis-delivered. This test supplies the pathological state directly —
     * the slot holds row A's text bound to row A's id (both obtained from the
     * real table) while row B is dispatched — so it depends on NOTHING but the
     * id check in live_text_applies_to() (SmsService.php:371-372, read at
     * :490-492). Removing that check (always true) delivers A's marker to B.
     */
    public function testLiveTextBindingIsIgnoredForADifferentRowWithoutRelyingOnTheClear(): void
    {
        $service = App::smsService();
        $slot = new \ReflectionProperty($service, 'live_text');

        // Row A: a real inserted row (unique event/recipient keeps rows apart).
        $markerA = 'CPMS-ROWA-MARKER-' . random_int(100000, 999999);
        $markerB = 'CPMS-ROWB-MARKER-' . random_int(100000, 999999);

        $rowA = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            '0912' . sprintf('%07d', 8_200_001),
            [
                'patient_name' => $markerA,
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/11',
                'appointment_time' => '10:15',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $rowA['status'], 'precondition: row A sent inline');
        $idA = (int) $rowA['message_id'];

        // Row B: a second real row; re-queued so it can be dispatched again.
        $rowB = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            '0912' . sprintf('%07d', 8_200_002),
            [
                'patient_name' => $markerB,
                'doctor_name' => 'دکتر تست',
                'appointment_date' => '1405/06/12',
                'appointment_time' => '11:45',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $rowB['status'], 'precondition: row B sent inline');
        $idB = (int) $rowB['message_id'];
        self::assertNotSame($idA, $idB);
        $bStored = (string) $this->fetchRow($idB)['message'];
        self::assertStringContainsString($markerB, $bStored, 'witness: row B renders its own content');
        self::assertStringNotContainsString($markerA, $bStored);

        // The pathological state, set directly: A's text bound to A's id, then B
        // dispatched. Nothing clears the slot here, so only the id check stands
        // between A's marker and B's provider call.
        $slot->setValue($service, ['LIVE-TEXT-OF-' . $markerA, $idA]);
        self::assertSame(['LIVE-TEXT-OF-' . $markerA, $idA], $slot->getValue($service), 'precondition: the stale binding is in place');

        $before = count($this->recording->textSends);
        $previous = ScopeContext::tryGet();
        try {
            App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));
            App::db()->update('cpms_sms_messages', ['status' => SmsMessageStatus::QUEUED], ['id' => $idB]);
            $service->dispatchMessage($idB);
        } finally {
            $slot->setValue($service, null); // leave the singleton clean for later tests
            App::replaceExplicitScope($previous);
        }

        $sent = array_slice($this->recording->textSends, $before);
        self::assertCount(1, $sent, 'row B must reach the provider exactly once');
        $delivered = (string) $sent[0]['message'];
        self::assertStringNotContainsString($markerA, $delivered, 'the stale binding must never be delivered on behalf of another row');
        self::assertStringNotContainsString('LIVE-TEXT-OF-', $delivered, 'no live text at all may reach this row');
        self::assertStringContainsString($markerB, $delivered, 'witness: the delivered text is row B own rendered content');
    }

    /**
     * Task D (b) BLOCKER-2 isolation, 1 of 2: the finally-clear on the NORMAL
     * inline path. A successful inline dispatch must leave the private slot
     * empty, so no staff text stays bound for the next row.
     *
     * Reads the private single slot by reflection (the established in-repo
     * pattern, e.g. BookingFlowTest.php:824) after a REAL sendEvent() ->
     * dispatchMessage() call, and asserts on both the delivered text and the
     * slot. Depends on nothing but the clear: it passes whether or not the
     * row-id check exists.
     *
     * Mutation note (INFERENCE — no PHP in this sandbox): delete
     * `$this->live_text = null;` in the finally at SmsService.php:352 and this
     * method fails on the assertNull below, because the slot keeps the
     * `[ text, message_id ]` pair after the send returned.
     */
    public function testLiveTextSlotIsClearedAfterASuccessfulInlineDispatch(): void
    {
        $service = App::smsService();
        $slot = new \ReflectionProperty($service, 'live_text');
        $marker = 'CPMS-SLOT-OK-' . random_int(100000, 999999);

        // Precondition: the slot starts empty, so anything found later was
        // written by the call under test.
        self::assertNull($slot->getValue($service), 'precondition: the live-text slot is empty before the call');

        $before = count($this->recording->textSends);
        // The normal path must NOT throw; if it does, the failure belongs to
        // this call, so it propagates after the scope is restored.
        $ok = [];
        $previous = ScopeContext::tryGet();
        try {
            App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));
            $ok = $service->testSend('0912' . sprintf('%07d', 8_100_001), 'marker ' . $marker, 1);
        } finally {
            App::replaceExplicitScope($previous);
        }

        self::assertSame(SmsMessageStatus::SENT, $ok['status'], 'precondition: the normal path really dispatched inline');
        $sent = array_slice($this->recording->textSends, $before);
        self::assertCount(1, $sent, 'the normal path delivered exactly once');
        self::assertStringContainsString($marker, (string) $sent[0]['message'], 'witness: the normal path delivered its bound text');
        self::assertNull(
            $slot->getValue($service),
            'after a NORMAL inline dispatch the slot must be empty — otherwise the staff text stays bound for the next row'
        );
    }

    /**
     * Task D (b) BLOCKER-2 isolation, 2 of 2: the finally-clear on the
     * EXCEPTION path. The dispatch must throw out of sendEvent() and still
     * leave the slot empty — the finally, not the happy path, clears it.
     *
     * A provider throw cannot produce this: dispatchMessage() catches every
     * \Throwable from the send (SmsService.php:512) and records FAILED, so it
     * never escapes. The honest escaping failure is a database error AFTER the
     * slot is bound (:348) and BEFORE dispatchMessage()'s own try (:465): the
     * status->SENDING update at :457-463. With CpmsDb in strict mode
     * (SettingsAuditAtomicityTest.php:181 does the same) CpmsDb::ensureNoSqlError()
     * throws a RuntimeException (CpmsDb.php:64-69) that leaves sendEvent()
     * through the finally at :351-353.
     *
     * Mutation note (INFERENCE — no PHP in this sandbox): delete
     * `$this->live_text = null;` in the finally at SmsService.php:352 and this
     * method fails on the assertNull below; it is independent of the row-id
     * check, because a non-null slot fails on its own.
     */
    public function testLiveTextSlotIsClearedWhenAnInlineDispatchEscapes(): void
    {
        $service = App::smsService();
        $slot = new \ReflectionProperty($service, 'live_text');
        $marker = 'CPMS-SLOT-THROW-' . random_int(100000, 999999);

        self::assertNull($slot->getValue($service), 'precondition: the live-text slot is empty before the call');

        // The statement is matched EXACTLY: anchored \A..\z, the real table
        // (with $wpdb->prefix), the exact SET list with literal values, and
        // THIS test's row id. No "contains" on the table token, no prefix-only
        // match.
        //
        // Shape produced by SmsService::updateMessage() (:1265-1269) and rendered
        // by wpdb::prepare (WP 7.1.3 src/wp-includes/class-wpdb.php:1458-1760):
        //   UPDATE <$wpdb->prefix>cpms_sms_messages SET `status` = 'SENDING',
        //   `attempts` = '1', updated_at = '2026-11-24 14:41:55.000' WHERE id = 42
        // Every SET value is a %s placeholder, and prepare() quotes an
        // unformatted %s unless the character before it is '%'
        // (class-wpdb.php:1602-1611; allow_unsafe_unquoted_parameters defaults to
        // true at :690) — so the attempts int renders QUOTED. `id` is the only
        // %d and renders bare. updateMessage() writes the table name WITHOUT
        // backticks, unlike wpdb::insert's "INSERT INTO `table`"
        // (class-wpdb.php:2613).
        //
        // The id is read at UPDATE time on purpose: the 'query' filter runs
        // BEFORE execution (class-wpdb.php:2230) and insert_id is only assigned
        // after the insert executes (:2315), so at this point it already holds
        // the row this call inserted. A filter at priority 5 sees unescaped
        // text, because wpdb registers remove_placeholder_escape at priority 0
        // (:2427-2428).
        $table = App::db()->table('cpms_sms_messages');
        $faultHits = 0;
        $absentTable = 'cpms_r5_absent_' . random_int(1000000, 9999999);
        $fault = static function ($query) use ($table, $absentTable, &$faultHits) {
            $rowId = App::db()->wpdb_last_insert_id();
            if ($rowId <= 0) {
                return $query;
            }

            $pattern = '/\AUPDATE ' . preg_quote($table, '/')
                . ' SET `status` = \'SENDING\', `attempts` = \'\d+\', '
                . 'updated_at = \'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\' '
                . 'WHERE id = ' . $rowId . '\z/';

            if (preg_match($pattern, (string) $query) !== 1) {
                return $query;
            }

            $faultHits++;

            // Same statement, ONE token changed: the table name. The real table
            // name appears nowhere in the rewritten text.
            return (string) preg_replace(
                '/\AUPDATE ' . preg_quote($table, '/') . '/',
                'UPDATE ' . $absentTable,
                (string) $query,
                1
            );
        };

        $sentBeforeFault = count($this->recording->textSends);
        $wasStrict = App::db()->is_strict();
        global $wpdb;
        $suppressed = (bool) $wpdb->suppress_errors(true);
        App::db()->setStrict(true);
        add_filter('query', $fault, 5);
        $threw = null;
        try {
            $service->testSend('0912' . sprintf('%07d', 8_100_002), 'marker ' . $marker, 1);
        } catch (\Throwable $error) {
            $threw = $error;
        } finally {
            remove_filter('query', $fault, 5);
            App::db()->setStrict($wasStrict);
            $wpdb->suppress_errors($suppressed);
        }

        self::assertSame(1, $faultHits, 'the fault must fire exactly once, on the one SENDING update of this row');
        self::assertNotNull($threw, 'precondition: the database failure must escape sendEvent() — that is what exercises the finally');
        self::assertNull(
            $slot->getValue($service),
            'after a THROWING inline dispatch the slot must still be empty — the finally is what clears it'
        );
        self::assertSame($sentBeforeFault, count($this->recording->textSends), 'the faulted dispatch never reached the provider');
        foreach ($this->recording->textSends as $send) {
            self::assertStringNotContainsString($marker, (string) $send['message'], 'the faulted text was never delivered');
        }

        // Persisted-state evidence, and only that. `attempts` stays 0 and the
        // provider count stays unchanged TOGETHER; `attempts === 0` alone does
        // NOT prove no UPDATE was attempted — the rewritten UPDATE IS attempted,
        // against a table that does not exist, and fails.
        //
        // What the stored `message` column holds depends on which path wrote the
        // row, and this is a test-send row:
        //  - test-send (this row): `$text` is replaced by
        //    NationalIdValidator::redact_digits($text) before the insert
        //    (SmsService.php:298-302), and that helper only masks runs of 10+
        //    digits (NationalIdValidator.php:124-128), so a short marker survives;
        //  - a non-template OTP row: the code becomes `***` (SmsService.php:286-288);
        //  - an ordinary business event: the rendered text is stored unmasked.
        $failedId = $this->storedMessageIdFor('0912' . sprintf('%07d', 8_100_002));
        self::assertGreaterThan(0, $failedId, 'the exception-path call inserted its row before the fault');
        $failedRow = $this->fetchRow($failedId);
        self::assertSame(0, (int) $failedRow['attempts'], 'with the provider count unchanged, persisted state shows no send was recorded');
        self::assertNotSame(SmsMessageStatus::SENT, (string) $failedRow['status'], 'a faulted dispatch never reports the row as sent');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $messageId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE id = %d',
            [$messageId]
        );
        self::assertIsArray($row);

        return $row;
    }

    private function queueRowsContaining(string $needle): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE payload_json LIKE %s',
            ['%' . $needle . '%']
        );
    }

    /**
     * Highest durable message id for a recipient (0 when the recipient has no
     * row yet). Reading it before and after the call proves the row asserted on
     * is the row that call inserted — from the real table, not from an
     * in-memory return value.
     */
    private function storedMessageIdFor(string $recipient): int
    {
        return (int) App::db()->fetchValue(
            'SELECT MAX(id) FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE recipient = %s',
            [$recipient]
        );
    }

    private function uniqueContext(): int
    {
        return (int) (microtime(true) * 1000) + random_int(1000, 9999);
    }
}

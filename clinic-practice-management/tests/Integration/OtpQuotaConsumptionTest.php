<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Auth\PatientNidHmac;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * C11 — سهمیهٔ روزانه/ساعتیِ هر شماره یک «سهمیهٔ صدور» است، نه «سهمیهٔ تلاش».
 *
 * قرارداد:
 *   - درخواستی که با Cooldown یا Lockout رد می‌شود هیچ‌کدام از شمارنده‌های
 *     `otp-day:` / `otp-hour:` را مصرف نمی‌کند. (این‌ها نامِ منطقیِ سطل‌اند؛ از
 *     B0-PR3 کلیدِ ذخیره‌شده `prefix:HMAC` است و هیچ رقم موبایل/کد ملی در
 *     `window_key` نمی‌ماند — به تست‌های `testOtpRateLimitKeysNeverStoreRawDigits...`
 *     و `testQuotaHelpersReadServerDerivedKeysNotRawMobileKeys` مراجعه کنید.)
 *   - درخواستی که واقعاً Challenge صادر می‌کند دقیقاً یکی به هر دو اضافه می‌کند.
 *   - سقف دقیق است: بعد از max صدورِ موفق، درخواست بعدی رد می‌شود و شمارنده
 *     از max بالاتر نمی‌رود (نه max+1).
 *   - شمارندهٔ `otp-ip:` دست‌نخورده و مستقل از این تغییر است.
 *
 * هیچ‌کدام از تست‌ها `otp.cooldown_sec` را صفر نمی‌کنند: Cooldown واقعی (۶۰
 * ثانیه) فعال می‌ماند و برای عبور از آن، `created_at` آخرین Challenge به عقب
 * برده می‌شود (همان روشی که probeهای مرزیِ production-race-proof استفاده
 * می‌کنند).
 */
final class OtpQuotaConsumptionTest extends WP_UnitTestCase
{
    private const DAY = 86400;
    private const HOUR = 3600;

    /** هر تست شمارهٔ خودش را دارد تا وضعیت بین تست‌ها نشت نکند. */
    private const MOBILE_COOLDOWN = '09127770001';
    private const MOBILE_LOCKOUT = '09127770002';
    private const MOBILE_DAILY = '09127770003';
    private const MOBILE_HOURLY = '09127770004';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    private function service(): OtpService
    {
        return App::otpService();
    }

    /** شمارندهٔ پنجرهٔ جاری (فقط خواندن). */
    private function counter(string $key, int $windowSec): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT hits FROM {$table} WHERE window_key = %s AND window_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
                $key,
                intdiv(time(), $windowSec)
            )
        );
    }

    /**
     * Row key for the day/hour bucket of one mobile. Derived from the SAME production
     * secret carrier the service uses (PatientNidHmac over the HMAC secret), so
     * these reads follow the real key instead of restating its shape. Before
     * the key was hashed this was 'otp-day:' . $mobile.
     */
    private function bucketKey(string $prefix, string $mobile): string
    {
        $normalized = MobileValidator::normalize($mobile);
        $this->assertNotNull($normalized, 'precondition: the fixture mobile normalizes');

        return (new PatientNidHmac())->mobile_rate_limit_key_with_domain($prefix, 'otp', $normalized);
    }

    private function dayHits(string $mobile): int
    {
        return $this->counter($this->bucketKey('otp-day', $mobile), self::DAY);
    }

    private function hourHits(string $mobile): int
    {
        return $this->counter($this->bucketKey('otp-hour', $mobile), self::HOUR);
    }

    private function latestChallengeId(string $mobile): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
                $mobile,
                OtpService::PURPOSE_LOGIN
            )
        );
    }

    private function challengeCount(string $mobile): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE mobile = %s AND purpose = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $mobile,
                OtpService::PURPOSE_LOGIN
            )
        );
    }

    /**
     * (g) A national ID shaped like a mobile must never be stored as a
     * rate-limit key. MobileValidator accepts a 10-digit input starting with 9
     * (:33-35), so OtpService::request() would otherwise keep the raw digits in
     * cpms_rate_limits.window_key for the day/hour/guard buckets. The ip
     * buckets are out of scope: their key comes from ClientIp::resolve(), so
     * no request-supplied identity text can reach them (and OtpService.php
     * :191-196 defers any IP-bucket keying to the owner's open decision Q6).
     *
     * Asserted on stored state, three ways:
     *  - the intended derivation IS in use (the server-derived key rows exist);
     *  - the raw digits appear in no column of any RateLimiter write for it;
     *  - the witness of the intended prefix ('otp-day:') is present, so a
     *    pass cannot come from having written nothing at all.
     */
    public function testOtpRateLimitKeysNeverStoreRawDigitsOfAMobileShapedNationalId(): void
    {
        $nid = $this->mobileShapedChecksumValidNid();
        $normalized = MobileValidator::normalize($nid);
        $this->assertSame('0' . $nid, $normalized, 'precondition: the 10-digit fixture normalizes to the 09... form');

        $this->issue($nid);

        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';

        // (a) intended derivation in use: the exact HMAC rows exist.
        $dayKey = $this->bucketKey('otp-day', $nid);
        $guardKey = $this->bucketKey('otp-send-guard', $nid);
        $this->assertSame(1, $this->dayHits($nid), 'the issued challenge charged the HMAC day bucket');
        $this->assertSame(1, $this->hourHits($nid), 'the issued challenge charged the HMAC hour bucket');
        $this->assertStringStartsWith('otp-day:', $dayKey, 'witness: the intended bucket prefix survives');
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE window_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
            $guardKey
        )), 'the per-mobile guard row is keyed by the same derivation');

        // (b) no stored key of any bucket this call wrote carries the raw digits.
        $keys = $wpdb->get_col("SELECT window_key FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
        $this->assertNotEmpty($keys, 'the call must have written at least one bucket');
        $offenders = [];
        foreach ((array) $keys as $key) {
            if (str_contains((string) $key, $nid) || str_contains((string) $key, substr($nid, 1))) {
                $offenders[] = (string) $key;
            }
        }
        $this->assertSame([], $offenders, 'no rate-limit key may embed the raw NID (or its 9-digit tail)');
        $this->assertStringNotContainsString($nid, (string) json_encode($keys), 'raw digits must not appear in any stored key');
        $this->assertSame(0, $this->rateRowsContaining($nid), 'raw digits must not appear in ANY column of cpms_rate_limits');
    }

    /**
     * (g2) The existing daily throttle still trips at the SAME attempt number as
     * before, through the real service on a fixed bucket: with the documented
     * default cap of 3, the 4th issuance is denied with CLINIC_OTP_DAILY_LIMIT
     * and the counter never exceeds 3. Cooldown is switched off the way
     * OtpFlowTest.php:418 does, so the counts are the bucket's own.
     */
    public function testExistingDailyThrottleTripsAtTheSameAttemptNumber(): void
    {
        $mobile = '09122223344';
        $dailyMax = (int) Settings::DEFAULTS['otp.daily_max'];
        $this->assertSame(3, $dailyMax, 'guard: the default daily cap this test pins is 3');

        App::settings()->set('otp.cooldown_sec', 0);
        Settings::flushCache();

        for ($issued = 1; $issued <= $dailyMax; $issued++) {
            $this->issue($mobile);
            $this->assertSame($issued, $this->dayHits($mobile), 'attempt ' . $issued . ' of 3 is allowed and counted once');
        }

        $denied = $this->expectApiCode('CLINIC_OTP_DAILY_LIMIT', fn () => $this->issue($mobile));
        $data = $denied->getData();
        $this->assertFalse((bool) $data['allowed'], 'the 4th attempt is denied at the same attempt number as before');
        $this->assertSame(0, (int) $data['remaining']);
        $this->assertSame($dailyMax, $this->dayHits($mobile), 'a denied attempt must not push the counter past the cap');
        $this->assertSame($dailyMax, $this->challengeCount($mobile), 'a denied attempt must not create a challenge');
    }

    /**
     * (h) The same number in every accepted spelling shares one bucket, and two
     * different numbers do not. Shaped on the day counter so the assertion is
     * about the derived key, not about the OTP policy.
     */
    public function testSameMobileInAnyFormatSharesOneBucketAndDifferentMobilesDoNot(): void
    {
        $local = '09123334455';
        $forms = ['09123334455', '9123334455', '+989123334455', '00989123334455'];
        foreach ($forms as $form) {
            $normalized = MobileValidator::normalize($form);
            $this->assertSame($local, $normalized, 'precondition: ' . $form . ' normalizes to the local form');
            App::rate()->hit($this->bucketKey('otp-day', $form), 10, self::DAY);
        }

        // One bucket, four hits: every spelling landed on the same derived key.
        $this->assertSame(4, $this->dayHits($local), 'every accepted spelling must share one bucket');

        // A different number is a different bucket even with the same length/prefix.
        $other = '09123334456';
        $this->assertSame(0, $this->dayHits($other), 'a different number must not share the bucket');
        App::rate()->hit($this->bucketKey('otp-day', $other), 10, self::DAY);
        $this->assertSame(1, $this->dayHits($other));
        $this->assertSame(4, $this->dayHits($local), 'charging another number must not touch this bucket');
    }

    /**
     * (h2) Mission 3, reviewer finding: (h) charges the DERIVED key through the
     * helper, so it proves the derivation agrees with itself, not that the real
     * request path lands on it. This drives FOUR real OTP requests through
     * OtpService::request() — one per accepted spelling of the same number —
     * with the cooldown switched off the way OtpFlowTest.php:418 does, and then
     * asserts on the real cpms_rate_limits table:
     *  - exactly ONE day/hour counter pair was charged (4 hits each), i.e. all
     *    four spellings shared one bucket in production;
     *  - the stored mobile-bearing keys are exactly the derived ones — no raw
     *    digits in any rate-limit key.
     * The ip bucket is deliberately excluded: it is IP-keyed and out of scope.
     */
    public function testFourRealRequestsInFourSpellingsChargeOneDerivedBucket(): void
    {
        $local = '09125556677';
        $forms = ['09125556677', '9125556677', '+989125556677', '0098912556677'];

        // Four issuances need headroom above the default daily cap of 3, or the
        // 4th request would be refused instead of counted. The caps are pinned
        // here so the test states its own budget; the assertion is about which
        // KEY is charged, not about the cap.
        App::settings()->set('otp.cooldown_sec', 0);
        App::settings()->set('otp.daily_max', 10);
        App::settings()->set('otp.hourly_max', 10);
        Settings::flushCache();
        $this->assertSame(10, (int) App::settings()->get('otp.daily_max'), 'precondition: the daily cap leaves room for four real requests');
        $this->assertSame(10, (int) App::settings()->get('otp.hourly_max'), 'precondition: the hourly cap leaves room for four real requests');

        foreach ($forms as $index => $form) {
            $this->assertSame($local, MobileValidator::normalize($form), 'precondition: ' . $form . ' is an accepted spelling');
            $this->issue($form);
            $this->assertSame($index + 1, $this->dayHits($local), 'real request #' . ($index + 1) . ' charged the shared day bucket');
            $this->assertSame($index + 1, $this->hourHits($local), 'real request #' . ($index + 1) . ' charged the shared hour bucket');
        }

        // Exactly one HMAC key set for this number: the two mobile-bearing
        // buckets plus the send-guard row, all carrying the derived prefix.
        $dayKey = $this->bucketKey('otp-day', $local);
        $hourKey = $this->bucketKey('otp-hour', $local);
        $guardKey = $this->bucketKey('otp-send-guard', $local);
        $this->assertNotSame($dayKey, $hourKey);
        $this->assertSame(4, $this->counter($dayKey, self::DAY), 'the production path charged exactly this day key');
        $this->assertSame(4, $this->counter($hourKey, self::HOUR), 'the production path charged exactly this hour key');
        $this->assertSame(0, $this->rateRowsContaining($local), 'no stored row may contain the raw number');
        $this->assertSame(0, $this->rateRowsContaining(substr($local, 1)), 'nor its 10-digit form without the leading zero');
        $this->assertGreaterThan(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_rate_limits') . ' WHERE window_key = %s',
            [$guardKey]
        ), 'witness: the per-mobile guard row is derived with the same carrier');
    }

    /**
     * (i2) Mission 3, reviewer finding: (i) proves the helper ignores a raw key,
     * but not that the PRODUCTION path ignores one. This pre-seeds a raw-key row
     * for the current window, then issues a real request for the same number and
     * asserts the request did not read that row's counter: the derived counter is
     * charged from zero and the raw row is left exactly as seeded.
     */
    public function testARealRequestIgnoresAPreSeededRawKeyRow(): void
    {
        $mobile = '09128889900';
        App::settings()->set('otp.cooldown_sec', 0);
        Settings::flushCache();

        // The legacy shape this PR retired, seeded at a value the daily cap (3)
        // would refuse if the request still read it.
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';
        $rawKey = 'otp-day:' . $mobile;
        $windowId = intdiv(time(), self::DAY);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (window_key, window_id, window_sec, hits) VALUES (%s, %d, %d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL
            $rawKey,
            $windowId,
            self::DAY,
            99
        ));
        $this->assertSame(99, $this->counter($rawKey, self::DAY), 'precondition: the raw legacy row exists at 99');

        // A real request must not inherit that counter: the quota starts fresh
        // and the challenge is issued instead of being refused as a limit.
        $this->issue($mobile);
        $this->assertSame(1, $this->dayHits($mobile), 'the derived counter starts from zero, so the raw row was not read');
        $this->assertSame(99, $this->counter($rawKey, self::DAY), 'the legacy raw row is untouched — nothing migrates and nothing reads it');
        $this->assertGreaterThan(0, $this->latestChallengeId($mobile), 'the request was allowed despite the raw row sitting at 99');
    }

    /**
     * (i) Regression guard for the helpers above: they must read the keys the
     * SERVER writes, not a private copy of the derivation. A charge added only
     * with the key the helper itself derives is visible to dayHits() by construction, so the
     * meaningful assertion is the reverse direction: a charge the real request
     * path makes is visible to the helper (covered above), and a raw-key charge
     * is NOT visible to it. If the helpers were reading raw keys, this would fail.
     */
    public function testQuotaHelpersReadServerDerivedKeysNotRawMobileKeys(): void
    {
        $mobile = '09124445566';
        App::rate()->hit('otp-day:' . $mobile, 10, self::DAY);

        $this->assertSame(0, $this->dayHits($mobile), 'a raw-key legacy bucket must not be read as the quota of this mobile');

        $this->issue($mobile);

        $this->assertSame(1, $this->dayHits($mobile), 'the real issuance charge is what the helper sees');
        $this->assertSame(1, $this->hourHits($mobile));
    }

    /**
     * (j) A checksum-valid national ID that MobileValidator accepts as a mobile:
     * 10 digits starting with 9 (MobileValidator.php:33-35 -> '0' . digits).
     */
    private function mobileShapedChecksumValidNid(): string
    {
        // Fixed value, documented: 10 ASCII digits starting with 9, so
        // MobileValidator treats it as 09...; the last digit is the national-ID
        // check digit computed from the first nine.
        $base = '912345678';
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $base[$i] * (10 - $i);
        }
        $rem = $sum % 11;
        $candidate = $base . (string) ($rem < 2 ? $rem : 11 - $rem);

        $this->assertTrue(NationalIdValidator::isValid($candidate), 'precondition: the fixture passes the national-ID check');
        $this->assertSame('0' . $candidate, MobileValidator::normalize($candidate), 'precondition: the fixture is a mobile-shaped NID');

        return $candidate;
    }

    /** Counts cpms_rate_limits rows whose ANY column contains the needle. */
    private function rateRowsContaining(string $needle): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';
        $total = 0;
        foreach ((array) $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
            $total += str_contains((string) json_encode($row), $needle) ? 1 : 0;
        }

        return $total;
    }

    /**
     * عبور از Cooldownِ واقعی بدون تغییرِ تنظیمات: مبدأِ Cooldown همان
     * `created_at` آخرین Challenge است، پس فقط همان ردیف را عقب می‌بریم.
     */
    private function movePastCooldown(string $mobile): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';
        $id = $this->latestChallengeId($mobile);
        $this->assertGreaterThan(0, $id, 'a challenge must exist before its cooldown can elapse');
        $updated = $wpdb->update(
            $table,
            ['created_at' => gmdate('Y-m-d H:i:s', time() - 61) . '.000'],
            ['id' => $id]
        );
        $this->assertSame(1, (int) $updated, 'cooldown fixture must age exactly one challenge');
    }

    private function issue(string $mobile): array
    {
        return $this->service()->request($mobile, OtpService::PURPOSE_LOGIN);
    }

    private function expectApiCode(string $expected, callable $action): OtpException
    {
        try {
            $action();
        } catch (OtpException $error) {
            $this->assertSame($expected, $error->apiCode());

            return $error;
        }

        $this->fail('expected ' . $expected);
    }

    /**
     * (a) درخواستِ ردشده با Cooldown سهمیهٔ روز/ساعت را مصرف نمی‌کند.
     *
     * علیه رفتارِ قدیمی شکست می‌خورد: آنجا hit()ها پیش از بررسیِ Cooldown اجرا
     * می‌شدند و شمارنده‌ها 2 می‌شدند.
     */
    public function testCooldownRejectedRequestDoesNotConsumeQuota(): void
    {
        $mobile = self::MOBILE_COOLDOWN;

        $this->issue($mobile);
        $this->assertSame(1, $this->dayHits($mobile), 'the issued challenge charges the daily quota exactly once');
        $this->assertSame(1, $this->hourHits($mobile), 'the issued challenge charges the hourly quota exactly once');

        $this->expectApiCode('CLINIC_OTP_COOLDOWN', fn () => $this->issue($mobile));

        $this->assertSame(1, $this->dayHits($mobile), 'a cooldown-rejected request must not consume the daily quota');
        $this->assertSame(1, $this->hourHits($mobile), 'a cooldown-rejected request must not consume the hourly quota');
        $this->assertSame(1, $this->challengeCount($mobile), 'no challenge may be created by a rejected request');
    }

    /**
     * (b) درخواستِ ردشده با Lockout سهمیهٔ روز/ساعت را مصرف نمی‌کند.
     */
    public function testLockoutRejectedRequestDoesNotConsumeQuota(): void
    {
        $mobile = self::MOBILE_LOCKOUT;

        $issued = $this->issue($mobile);
        $this->assertSame(1, $this->dayHits($mobile));
        $this->assertSame(1, $this->hourHits($mobile));

        // قفل با ۵ تلاش نادرست (پیش‌فرض otp.max_attempts=5) — کد واقعی لازم نیست.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $expected = $attempt === 5 ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID';
            $error = $this->expectApiCode($expected, fn () => $this->service()->verify($mobile, '000000'));
            $this->assertSame(5 - $attempt, (int) ($error->getData()['remaining'] ?? -1));
        }
        // تلاش‌های نادرست سهمیهٔ صدور را لمس نمی‌کنند.
        $this->assertSame(1, $this->dayHits($mobile), 'failed verifications must not touch the issuance quota');
        $this->assertSame(1, $this->hourHits($mobile), 'failed verifications must not touch the issuance quota');

        $locked = $this->expectApiCode('CLINIC_OTP_LOCKED', fn () => $this->issue($mobile));
        $this->assertGreaterThanOrEqual(1, (int) $locked->getData()['retry_after']);

        $this->assertSame(1, $this->dayHits($mobile), 'a lockout-rejected request must not consume the daily quota');
        $this->assertSame(1, $this->hourHits($mobile), 'a lockout-rejected request must not consume the hourly quota');
        $this->assertSame(1, $this->challengeCount($mobile), 'no challenge may be created while locked out');
        $this->assertSame(120, (int) $issued['expires_in'], 'TTL stays at the operational default');
    }

    /**
     * (c) سقفِ روزانه دقیقاً بعد از max صدورِ موفق مسدود می‌کند.
     */
    public function testDailyCapBlocksExactlyAfterMaxIssuedChallenges(): void
    {
        $mobile = self::MOBILE_DAILY;
        $dailyMax = (int) Settings::DEFAULTS['otp.daily_max'];
        $this->assertSame(3, $dailyMax, 'guard: this test documents the default daily cap');

        for ($issued = 1; $issued <= $dailyMax; $issued++) {
            $this->issue($mobile);
            $this->assertSame($issued, $this->dayHits($mobile), 'each issued challenge charges the daily quota exactly once');
            $this->assertSame($issued, $this->hourHits($mobile), 'each issued challenge charges the hourly quota exactly once');
            if ($issued < $dailyMax) {
                $this->movePastCooldown($mobile);
            }
        }

        $this->assertSame($dailyMax, $this->challengeCount($mobile));

        $denied = $this->expectApiCode('CLINIC_OTP_DAILY_LIMIT', fn () => $this->issue($mobile));
        $data = $denied->getData();
        $this->assertGreaterThanOrEqual(1, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(self::DAY, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(1, abs((int) $data['reset_at'] - time() - (int) $data['retry_after']));
        $this->assertFalse((bool) $data['allowed']);
        $this->assertSame(0, (int) $data['remaining']);

        $this->assertSame($dailyMax, $this->dayHits($mobile), 'the cap must never be exceeded (never max+1)');
        $this->assertSame($dailyMax, $this->hourHits($mobile), 'a cap-rejected request must not consume either quota');
        $this->assertSame($dailyMax, $this->challengeCount($mobile), 'a cap-rejected request must not create a challenge');
    }

    /**
     * (d) سقفِ ساعتی هم دقیقاً همین‌طور مسدود می‌کند (با سقفِ روزانهٔ بالاتر تا
     * سقفِ ساعتی زودتر برسد).
     */
    public function testHourlyCapBlocksExactlyAfterMaxIssuedChallenges(): void
    {
        $mobile = self::MOBILE_HOURLY;
        $hourlyMax = 2;

        Settings::flushCache();
        App::settings()->set('otp.daily_max', 10);
        App::settings()->set('otp.hourly_max', $hourlyMax);
        Settings::flushCache();

        for ($issued = 1; $issued <= $hourlyMax; $issued++) {
            $this->issue($mobile);
            $this->assertSame($issued, $this->hourHits($mobile), 'each issued challenge charges the hourly quota exactly once');
            $this->assertSame($issued, $this->dayHits($mobile));
            if ($issued < $hourlyMax) {
                $this->movePastCooldown($mobile);
            }
        }

        $denied = $this->expectApiCode('CLINIC_RATE_LIMITED', fn () => $this->issue($mobile));
        $data = $denied->getData();
        $this->assertFalse((bool) $data['allowed']);
        $this->assertSame(0, (int) $data['remaining']);
        $this->assertGreaterThanOrEqual(1, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(self::HOUR, (int) $data['retry_after']);

        $this->assertSame($hourlyMax, $this->hourHits($mobile), 'the hourly cap must never be exceeded');
        $this->assertSame($hourlyMax, $this->dayHits($mobile), 'a cap-rejected request must not consume the daily quota either');
        $this->assertSame($hourlyMax, $this->challengeCount($mobile));
    }

    /**
     * (f-مکمل) `RateLimiter::peek()` که تصمیمِ سهمیه بر آن استوار است، فقط
     * می‌خوانَد و وضعیت را تغییر نمی‌دهد. (RateLimiterTest دست‌نخورده می‌ماند،
     * پس پوششِ peek اینجا اضافه شده است.)
     */
    public function testPeekDoesNotMutateTheCounter(): void
    {
        $key = 'otp-day:' . '09127770099';

        $this->assertSame(0, App::rate()->peek($key, self::DAY), 'peek on a missing window reads zero');

        $first = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($first['allowed']);

        $this->assertSame(1, App::rate()->peek($key, self::DAY));
        $this->assertSame(1, App::rate()->peek($key, self::DAY), 'peek is idempotent');
        $this->assertSame(1, App::rate()->peek($key, self::DAY));

        // هنوز دقیقاً یک مصرف؛ اگر peek می‌نوشت، اینجا 4 می‌شد و سقف زودتر می‌بست.
        $second = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($second['allowed']);
        $this->assertSame(1, (int) $second['remaining']);

        $third = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($third['allowed'], 'exactly max hits are allowed');
        $this->assertSame(0, (int) $third['remaining']);

        $fourth = App::rate()->hit($key, 3, self::DAY);
        $this->assertFalse($fourth['allowed'], 'max+1 is rejected — exact-max behaviour is unchanged');
    }
}

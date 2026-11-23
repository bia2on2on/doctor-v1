<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Security;

use ClinicCore\Application\Auth\PatientNidHmac;
use ClinicCore\Application\Backup\RestoreBarrierGate;
use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * محدودسازی نرخ ورود به WordPress — Phase 1A، Item 3.
 *
 * وضعیت پیش از Phase 1: مستندِ خودِ RateLimiter کلید `login:{ip}` را
 * تبلیغ می‌کرد، اما در کل افزونه هیچ فراخوانی‌ای برای آن وجود نداشت و
 * هیچ Hook ای روی `authenticate` / `wp_login_failed` بسته نشده بود؛ یعنی
 * فرم ورود و احراز هویت REST هیچ کنترل Bruteforce ای نداشتند.
 *
 * طراحی:
 *  - دو پنجرهٔ مستقل: بر اساس IP و بر اساس نامِ کاربریِ هدف. اولی حملهٔ
 *    توزیع‌نشده را می‌گیرد، دومی Credential Stuffing روی یک حساب را از
 *    چند IP کند می‌کند.
 *  - فقط تلاش‌های **ناموفق** شمرده می‌شوند، پس ورود عادی هرگز قفل نمی‌شود.
 *  - سقف در **اولویت ۱۰۰** — پس از هندلرهای اصلی هستهٔ WordPress (۲۰)
 *    و پیش از گیتِ بازیابی (PHP_INT_MAX) — اجرا می‌شود، نه پیش از
 *    مقایسهٔ رمز. هستهٔ WordPress با اعتبارنامهٔ غیرتهی هر خطای ورودی را
 *    بازنویسی می‌کند؛ بنابراین تذکر `authenticate` در اولویت ۵ نمی‌توانست
 *    سقف را تحمیل کند. در اولویت دیرهنگام، نتیجهٔ نهایی (WP_User و
 *    WP_Error) با `CLINIC_RATE_LIMITED` جایگزین می‌شود تا رمز درست نیز
 *    به سقف نرسد و پاسخ حساب موجود و ناموجود یکسان بماند.
 *  - پیام خطا عمداً یکسان و بدون افشای وجود/عدم وجود حساب است
 *    (مقاومت در برابر Enumeration).
 *  - IP از ClientIp می‌آید، پس `X-Forwarded-For` فقط با اعلام صریح
 *    Proxy معتمد خوانده می‌شود.
 *
 * ⚠️ عمداً Scope-Independent: هیچ ارجاعی به Organization/Clinic ندارد.
 * سقف‌های per-Clinic به تصمیم باز Q6 (دامنهٔ جدول cpms_rate_limits)
 * وابسته‌اند و در Phase 1B/2 تعیین می‌شوند.
 */
final class LoginRateLimiter
{
    /** حداکثر تلاش ناموفق از یک IP در پنجره. */
    public const MAX_PER_IP = 20;

    /** حداکثر تلاش ناموفق روی یک نام کاربری در پنجره. */
    public const MAX_PER_USERNAME = 10;

    /** طول پنجره (ثانیه). */
    public const WINDOW_SEC = 900;

    public function __construct(
        private readonly RateLimiter $rate,
        private readonly ?OpLogger $log = null
    ) {
    }

    public function register(): void
    {
        // اولویت 100: پس از هندلرهای هسته (20 و 30 و spam-check 99) و پیش از
        // گیتِ بازیابی (PHP_INT_MAX) — تصمیم نهایی محدودیت است.
        add_filter( 'authenticate', [ $this, 'blockWhenThrottled' ], 100, 3 );
        add_action( 'wp_login_failed', [ $this, 'recordFailure' ], 10, 2 );
    }

    /**
     * در پایان زنجیرهٔ `authenticate` (پس از هندلرهای هسته)، وضعیت
     * محدودیت را کنترل می‌کند؛ اگر سبد در سقف یا بالاتر باشد، نتیجهٔ
     * نهایی — چه WP_User و چه WP_Error — با `CLINIC_RATE_LIMITED`
     * جایگزین می‌شود.
     *
     * @param \WP_User|\WP_Error|null $user
     *
     * @return \WP_User|\WP_Error|null
     */
    public function blockWhenThrottled($user, string $username = '', string $password = '')
    {
        // درخواست بدون اعتبارنامه (مثلاً بررسی کوکی) را دست نمی‌زنیم.
        if ($username === '' && $password === '') {
            return $user;
        }

        // گیتِ بازیابی (PHP_INT_MAX) پس از این فیلتر دوباره هر نتیجه‌ای را
        // — شامل خطای محدودیت — دریافت می‌کند و یا آن را حفظ یا همان را
        // جایگزین می‌کند؛ هیچ‌یک از دو گیت نمی‌تواند دیگری را باز کند.
        try {
            if ( ! $this->is_login_throttled( $username ) ) {
                return $user;
            }
            $this->record( 'LOGIN_THROTTLED', $username, ClientIp::resolve() );
        } catch (\Throwable) {
            // Fail-closed: throttle housekeeping must never escape into the
            // `authenticate` chain (e.g. the HMAC key could not be read or
            // persisted). No cookie/session is issued, nothing is counted, no
            // option is written, no OpLogger row is written, no retry — and the
            // response is IDENTICAL for known/unknown accounts and for correct
            // or wrong passwords.
            return new \WP_Error(
                'cpms_login_throttle_unavailable',
                'سرویس محدودیت در دسترس نیست — کمی بعد دوباره تلاش کنید',
                ['status' => 503]
            );
        }

        return new \WP_Error(
            'CLINIC_RATE_LIMITED',
            'تلاش‌های ناموفق زیاد بوده است — کمی بعد دوباره تلاش کنید',
            ['status' => 429]
        );
    }

    /**
     * فقط شکست‌ها شمرده می‌شوند.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing public callback.
    public function recordFailure( string $username, ?\WP_Error $error = null ): void
    {
        try {
            $accounts = null !== $error && RestoreBarrierGate::blocked() ? RestoreBarrierGate::recovery_failure_accounts( $error ) : null;
            if ( null !== $accounts ) {
                foreach ( $accounts as $account ) {
                    if ( ! $this->is_login_throttled( $account ) ) {
                        $this->count_failure( $account );
                    }
                }
                return;
            }
            $this->count_failure( $username );
        } catch (\Throwable) {
            // Fail-closed silence: wp_login_failed must never escape into core.
            // Nothing counted, nothing logged, nothing written — the caller
            // already holds the failure.
        }
    }

    public function is_login_throttled( string $username ): bool {
        $ip = ClientIp::resolve();
        return ( null !== $ip && $this->isOver( 'login-ip:' . $ip, self::MAX_PER_IP ) )
            || ( '' !== $username && $this->isOver( 'login-user:' . $this->user_key( $username ), self::MAX_PER_USERNAME ) );
    }

    private function count_failure( string $username ): void {
        $ip = ClientIp::resolve();
        // تلاش ردشده در سقفی که به آن رسیده یا از آن گذشته است، آن سبد را
        // بیشتر نمی‌افزاید: شمارندهٔ هر سبد نزدیک سقف (IP ≈ ۲۰، نام کاربری
        // ≈ ۱۰) می‌ماند — درخواست‌های همزمان بین peek و hit می‌توانند آن را
        // اندکی از سقف عبور دهند — و با چرخش پنجرهٔ ۹۰۰ ثانیه‌ای باز می‌شود.
        if ( $ip !== null && ! $this->isOver( 'login-ip:' . $ip, self::MAX_PER_IP ) ) {
            $this->rate->hit( 'login-ip:' . $ip, self::MAX_PER_IP, self::WINDOW_SEC );
        }
        if ( $username !== '' && ! $this->isOver( 'login-user:' . $this->user_key( $username ), self::MAX_PER_USERNAME ) ) {
            $this->rate->hit( 'login-user:' . $this->user_key( $username ), self::MAX_PER_USERNAME, self::WINDOW_SEC );
        }

        $this->record( 'LOGIN_FAILED', $username, $ip );
    }

    /**
     * آیا شمارندهٔ فعلی از سقف عبور کرده است؟
     *
     * از `hit()` با سقف بسیار بزرگ استفاده نمی‌کنیم تا خودِ بررسی،
     * شمارنده را بالا نبرد؛ `peek()` فقط می‌خواند.
     */
    private function isOver(string $key, int $max): bool
    {
        return $this->rate->peek($key, self::WINDOW_SEC) >= $max;
    }

    /**
     * نام کاربری هرگز خام وارد کلید نمی‌شود: کلید هش HMAC زده شده با کلید
     * موجود HMAC (ثابت CPMS_NID_HMAC_KEY یا Option بدون autoload) و با
     * پیشوند `login-user:` جدا می‌شود، بنابراین هیچ کلید نام کاربری‌ای با
     * کلید `nid:` یکسان یا برابر نیست.
     */
    private function user_key( string $username ): string {
        return substr(
            hash_hmac( 'sha256', 'login-user:' . strtolower( trim( $username ) ), $this->hmac_key() ),
            0,
            32
        );
    }

    /**
     * کلید HMAC موجود — همان منبع `PatientNidHmac`: ثابت `CPMS_NID_HMAC_KEY`
     * اگر تعریف شده و غیرتهی باشد، در غیر این صورت Option بدون Autoload با
     * همان نام (ساخته‌شده یک‌بار با random_bytes). هیچ Option یا Constant جدیدی
     * اضافه نمی‌کند.
     */
    private function hmac_key(): string {
        if ( defined( PatientNidHmac::CONST_KEY ) ) {
            $constant = constant( PatientNidHmac::CONST_KEY );
            if ( is_string( $constant ) && '' !== $constant ) {
                return $constant;
            }
        }

        if ( function_exists( 'get_option' ) ) {
            $existing = get_option( PatientNidHmac::OPTION_KEY, '' );
            if ( is_string( $existing ) && '' !== $existing ) {
                return $existing;
            }

            if ( function_exists( 'add_option' ) ) {
                $generated = bin2hex( random_bytes( 32 ) );
                if ( add_option( PatientNidHmac::OPTION_KEY, $generated, '', 'no' ) ) {
                    return $generated;
                }
                $reread = get_option( PatientNidHmac::OPTION_KEY, '' );
                if ( is_string( $reread ) && '' !== $reread ) {
                    return $reread;
                }
            }

            throw new \RuntimeException( 'Failed to persist HMAC key' );
        }

        throw new \RuntimeException( 'No HMAC key source available' );
    }

    /**
     * عمداً از AuditLogger استفاده نمی‌شود.
     *
     * امضای `AuditLogger::log()` پارامتر `$clinicId` با مقدار پیش‌فرض 1
     * دارد و ورود، رویدادی پیش از هر Scope کلینیکی است؛ ثبت آن روی
     * `clinic_id = 1` یعنی جاسازی همان فرضی که AD-13 ممنوع کرده است.
     * تا زمانی که Phase 2 مدل Organization را بسازد، این رویدادها فقط در
     * لاگ عملیاتی می‌روند (ثبت‌شده در Deferred Register فاز 1B).
     */
    private function record(string $action, string $username, ?string $ip): void
    {
        $this->log?->warning($action, [
            // نام کاربری خام لاگ نمی‌شود (Enumeration/PII)
            'username_hash' => $this->user_key( $username ),
            'ip' => $ip,
        ]);
    }
}

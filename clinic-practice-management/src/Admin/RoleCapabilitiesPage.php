<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Application\Patients\PatientAccountPolicy;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\PersianDigits;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * صفحه «CPMS (دسترسی‌ها)» — مدیریت Capabilities نقش‌های CPMS (ADR-0030 / Part 1).
 *
 *  - فقط Administrator فنی با `cpms_config` (P-3) — عملیات حساس با Audit کامل.
 *  - ماتریس Role × Capability فقط برای نقش‌های تمپلیت (منشی/پزشک)؛ نقش بیمار
 *    همیشه بدون Cap است (P-5 — Ownership) و نمایش صرفاً اطلاعی دارد.
 *  - ذخیره از مسیر Override عمدی (`RolesAndCapabilities::OPTION_OVERRIDE`) است؛
 *    Self-healing به Override احترام می‌گذارد و دیگر تعمدِ ادمین را پاک نمی‌کند.
 *  - Capهای حساس (P-11) با نشانگر جدا نمایش داده می‌شوند.
 *  - هر تغییر → Audit `ROLE_PERMISSION_CHANGED` با before/after.
 */
final class RoleCapabilitiesPage
{
    private const NOTICE_KEY = 'cpms_roles_notice';
    private const NONCE_ACTION = 'cpms_save_role_caps';

    /** نقش‌های دارای Preset قابل‌ویرایش (V1 — چ. G). بیمار عمداً حذف است (P-5). */
    private const ROLE_PRESETS = [
        RolesAndCapabilities::ROLE_SECRETARY,
        RolesAndCapabilities::ROLE_DOCTOR,
        RolesAndCapabilities::ROLE_ACCOUNTANT,
        RolesAndCapabilities::ROLE_MANAGER,
    ];

    /** برچسب نمایشی هر نقش در Preset. */
    private const ROLE_LABELS = [
        RolesAndCapabilities::ROLE_SECRETARY => 'منشی مطب (cpms_secretary)',
        RolesAndCapabilities::ROLE_DOCTOR => 'پزشک (cpms_doctor)',
        RolesAndCapabilities::ROLE_ACCOUNTANT => 'حسابدار (cpms_accountant)',
        RolesAndCapabilities::ROLE_MANAGER => 'مدیر کلینیک (cpms_manager)',
    ];

    /** نشانگر Cap حساس (P-11) — در UI با ⚠️ مشخص می‌شود. */
    private const SENSITIVE = [
        RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::PRIVATE_NOTE_CREATE, RolesAndCapabilities::PRIVATE_NOTE_UPDATE,
        RolesAndCapabilities::EXPORT, RolesAndCapabilities::AUDIT_READ,
        RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::PAYMENT_REFUND,
        RolesAndCapabilities::INVOICE_VOID, RolesAndCapabilities::INVOICE_ADJUST,
        RolesAndCapabilities::PATIENT_ARCHIVE, RolesAndCapabilities::PATIENT_MERGE,
        RolesAndCapabilities::CONSULT_REOPEN,
    ];

    /** برچسب فارسی هر Capability (منبع: permission-matrix §2). */
    private const LABELS = [
        'cpms_patient_read' => 'مشاهده پرونده بیمار',
        'cpms_patient_create' => 'ثبت بیمار جدید',
        'cpms_patient_update' => 'ویرایش اطلاعات بیمار',
        'cpms_patient_archive' => 'آرشیو بیمار (حساس)',
        'cpms_patient_merge' => 'ادغام پرونده تکراری (حساس)',
        'cpms_appt_read' => 'مشاهده نوبت‌ها',
        'cpms_appt_create' => 'ثبت نوبت',
        'cpms_appt_confirm' => 'تأیید نوبت',
        'cpms_appt_cancel' => 'لغو نوبت',
        'cpms_appt_reschedule' => 'جابه‌جایی نوبت',
        'cpms_appt_no_show' => 'ثبت عدم حضور',
        'cpms_visit_read' => 'مشاهده ویزیت‌ها',
        'cpms_queue_read' => 'مشاهده صف',
        'cpms_queue_checkin' => 'چک‌این / پذیرش',
        'cpms_queue_advance' => 'پیشبرد صف (به‌صف/لغو/صورتحساب)',
        'cpms_queue_call' => 'فراخوان بیمار',
        'cpms_queue_checkout' => 'خروج بیمار',
        'cpms_consult_start' => 'شروع مشاوره',
        'cpms_consult_complete' => 'تکمیل ویزیت',
        'cpms_consult_reopen' => 'بازگشایی ویزیت تکمیل‌شده (حساس)',
        'cpms_medical_read' => 'مشاهده محتوای بالینی',
        'cpms_note_create' => 'ثبت یادداشت بالینی',
        'cpms_note_update' => 'ویرایش یادداشت بالینی',
        'cpms_rec_create' => 'ثبت توصیه پزشکی',
        'cpms_private_note_read' => 'مشاهده یادداشت خصوصی پزشک (فوق حساس)',
        'cpms_private_note_create' => 'ثبت یادداشت خصوصی',
        'cpms_private_note_update' => 'ویرایش یادداشت خصوصی',
        'cpms_rx_read' => 'مشاهده نسخه‌ها',
        'cpms_rx_create' => 'ثبت نسخه',
        'cpms_rx_void' => 'ابطال نسخه',
        'cpms_file_upload' => 'بارگذاری فایل پزشکی',
        'cpms_file_read' => 'دریافت/مشاهده فایل پزشکی',
        'cpms_invoice_read' => 'مشاهده فاکتور',
        'cpms_invoice_create' => 'صدور فاکتور',
        'cpms_invoice_adjust' => 'اصلاح مبلغ فاکتور (حساس)',
        'cpms_invoice_void' => 'ابطال فاکتور (حساس)',
        'cpms_payment_create' => 'ثبت پرداخت',
        'cpms_payment_void' => 'ابطال پرداخت (حساس)',
        'cpms_payment_refund' => 'بازپرداخت (حساس)',
        'cpms_finance_read' => 'مشاهده خلاصه مالی',
        'cpms_report_read' => 'مشاهده گزارش‌ها',
        'cpms_export' => 'خروجی CSV (حساس)',
        'cpms_audit_read' => 'مشاهده Audit (حساس)',
        'cpms_search' => 'جستجوی بیمار',
        'cpms_config' => 'پیکربندی سیستم (فنی)',
        'cpms_sms_config' => 'تنظیمات پیامک (فنی)',
    ];

    /** گروه‌بندی نمایشی. */
    private const GROUPS = [
        'بیمار / پرونده' => [
            RolesAndCapabilities::PATIENT_READ, RolesAndCapabilities::PATIENT_CREATE, RolesAndCapabilities::PATIENT_UPDATE,
            RolesAndCapabilities::PATIENT_ARCHIVE, RolesAndCapabilities::PATIENT_MERGE,
        ],
        'نوبت' => [
            RolesAndCapabilities::APPT_READ, RolesAndCapabilities::APPT_CREATE, RolesAndCapabilities::APPT_CONFIRM,
            RolesAndCapabilities::APPT_CANCEL, RolesAndCapabilities::APPT_RESCHEDULE, RolesAndCapabilities::APPT_NO_SHOW,
        ],
        'صف / ویزیت' => [
            RolesAndCapabilities::VISIT_READ, RolesAndCapabilities::QUEUE_READ, RolesAndCapabilities::QUEUE_CHECKIN,
            RolesAndCapabilities::QUEUE_ADVANCE, RolesAndCapabilities::QUEUE_CALL, RolesAndCapabilities::QUEUE_CHECKOUT,
            RolesAndCapabilities::CONSULT_START, RolesAndCapabilities::CONSULT_COMPLETE, RolesAndCapabilities::CONSULT_REOPEN,
        ],
        'بالینی' => [
            RolesAndCapabilities::MEDICAL_READ, RolesAndCapabilities::NOTE_CREATE, RolesAndCapabilities::NOTE_UPDATE,
            RolesAndCapabilities::REC_CREATE, RolesAndCapabilities::RX_READ, RolesAndCapabilities::RX_CREATE, RolesAndCapabilities::RX_VOID,
        ],
        'یادداشت خصوصی پزشک' => [
            RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::PRIVATE_NOTE_CREATE, RolesAndCapabilities::PRIVATE_NOTE_UPDATE,
        ],
        'فایل پزشکی' => [
            RolesAndCapabilities::FILE_UPLOAD, RolesAndCapabilities::FILE_READ,
        ],
        'مالی' => [
            RolesAndCapabilities::INVOICE_READ, RolesAndCapabilities::INVOICE_CREATE, RolesAndCapabilities::INVOICE_ADJUST,
            RolesAndCapabilities::INVOICE_VOID, RolesAndCapabilities::PAYMENT_CREATE, RolesAndCapabilities::PAYMENT_VOID,
            RolesAndCapabilities::PAYMENT_REFUND, RolesAndCapabilities::FINANCE_READ,
        ],
        'گزارش / جستجو / سایر' => [
            RolesAndCapabilities::REPORT_READ, RolesAndCapabilities::EXPORT, RolesAndCapabilities::SEARCH,
            RolesAndCapabilities::AUDIT_READ, RolesAndCapabilities::CONFIG, RolesAndCapabilities::SMS_CONFIG,
        ],
    ];

    /**
     * ماتریس دسترسی (Role × Capability) فقط برای «مالک فنی/امنیتی» است.
     * در Chunk G، مدیر کلینیک (cpms_manager) با cpms_config مدیریت ستادی می‌کند اما
     * نمی‌تواند مرز امنیتیِ Capabilityها را با چک‌باکس دور بزند (anti privilege-escalation).
     * بنابراین گیت صفحه «دسترسی‌ها» روی `manage_options` (مالک فنی/administrator) است،
     * نه روی `cpms_config`؛ و صفحهٔ «کاربران» (cpms-staff) روی cpms_config می‌ماند.
     */
    private const LICENSE_BLOCKED_MESSAGE = 'مجوز سامانه اجازهٔ این تغییر را نمی‌دهد؛ سوابق قابل مشاهده باقی می‌مانند.';

    /** Three-stage license (design §7.3 staff/settings row, final 2026-11-17). */
    private static function settings_writes_blocked(): bool {
        $decision = \ClinicCore\Bootstrap\App::licenseGate()->assert( \ClinicCore\Domain\Licensing\LicenseGate::OP_SETTINGS_WRITE );
        if ( $decision->allowed ) {
            return false;
        }
        \ClinicCore\Bootstrap\App::op()->warning( 'license.blocked', [ 'operation' => \ClinicCore\Domain\Licensing\LicenseGate::OP_SETTINGS_WRITE, 'reason' => $decision->reason ] );

        return true;
    }

    private static function isSecurityOwner(): bool
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    /** قابل دسترسی برای تست/UI — آیا کاربر جاری مالک فنی/امنیتی است؟ */
    public static function canEditMatrix(): bool
    {
        return self::isSecurityOwner();
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_save_role_caps', [self::class, 'save']);
        add_action('admin_post_cpms_reset_role_caps', [self::class, 'reset']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parent_slug(),
            'مجوزهای پیشرفته',
            'مجوزهای پیشرفته',
            'manage_options',
            'cpms-roles',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!self::isSecurityOwner()) {
            wp_die('دسترسی ندارید', 403);
        }

        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }
        $overrides = RolesAndCapabilities::overrides();
        ?>
<div class="wrap" dir="rtl">
    <h1>مجوزهای پیشرفته</h1>

    <?php if (is_string($notice) && $notice !== '') : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <div class="notice notice-info inline"><p>
        دسترسی‌ها با <strong>Role Preset</strong> قابل‌فهم تعریف می‌شوند؛ برای ویرایش دقیقِ هر
        Capability از <strong>Advanced Permissions</strong> (جمع‌شونده) استفاده کنید. تغییرات
        <strong>عمدی و ماندگار</strong> ثبت می‌شوند (Override — ADR-0030) و توسط Self-healing حذف
        نمی‌شوند؛ هر تغییر در Audit با ذکر کاربر، زمان و تفاضل ثبت می‌شود.
        <span class="cpms-sensitive">⚠️ = Capability حساس (P-11).</span>
    </p></div>

    <!-- نقش بیمار: فقط اطلاعی -->
    <div class="cpms-role-preset">
        <h2 class="title">بیمار (cpms_patient)</h2>
        <p class="description"><?php echo esc_html(self::roleDescription(RolesAndCapabilities::ROLE_PATIENT)); ?></p>
    </div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          data-cpms-confirm="تغییر دسترسی‌ها عمدی و ماندگار است و می‌تواند به توانایی‌های حساس (P-11) مربوط باشد. ادامه می‌دهید؟">
        <input type="hidden" name="action" value="cpms_save_role_caps">
        <?php wp_nonce_field(self::NONCE_ACTION); ?>

        <?php foreach (self::ROLE_PRESETS as $role) : ?>
            <?php self::renderRolePreset($role, $overrides); ?>
        <?php endforeach; ?>

        <p style="margin-top:16px">
            <button type="submit" class="button button-primary button-hero">ذخیره دسترسی‌ها</button>
        </p>
    </form>
</div>
        <?php
    }

    /**
     * یک Role Preset — نمای معمولی: توضیح فارسی نقش + «می‌تواند / نمی‌تواند» + هشدار حساس؛
     * و Advanced Permissions جدا/جمع‌شونده با گروه‌بندی + جستجو + نشانگر حساس.
     *
     * Backend Security حفظ می‌شود: نام فیلد نقش‌ها همان `role_caps[role][]` است و `save()`
     * از همان مسیر `RolesAndCapabilities::setRoleCaps` + whitelist + Audit استفاده می‌کند.
     *
     * @param array<string, mixed> $overrides
     */
    private static function renderRolePreset(string $role, array $overrides): void
    {
        $label = self::ROLE_LABELS[$role] ?? $role;
        $effective = RolesAndCapabilities::capsMap($role); // array<string,bool> (فقط فعال‌ها، با true)
        $enabled = array_keys($effective);
        $disabled = array_values(array_diff(RolesAndCapabilities::ALL_CAPS, $enabled));
        $isOverridden = isset($overrides[$role]);
        $sensitiveEnabled = array_values(array_intersect($enabled, self::SENSITIVE));
        ?>
        <div class="cpms-role-preset">
            <h2 class="title"><?php echo esc_html($label); ?>
                <?php if ($isOverridden) : ?>
                    <span class="description">— تغییر یافته نسبت به پیش‌فرض
                        (<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_reset_role_caps&role=' . $role), 'cpms_reset_role_caps_' . $role)); ?>"
                            data-cpms-confirm="بازگشت این نقش به Template پیش‌فرض افزونه؟ همهٔ تغییرات سفارشی بازنشانی می‌شود."><?php echo esc_html('بازگشت به پیش‌فرض'); ?></a>)
                    </span>
                <?php endif; ?>
            </h2>
            <p class="description"><?php echo esc_html(self::roleDescription($role)); ?></p>

            <details class="cpms-details cpms-preset-overview">
                <summary>
                    <span>نمای سریع دسترسی‌ها</span>
                    <span class="cpms-group-count">
                        (<?php echo esc_html( PersianDigits::digits( count( $enabled ) ) ); ?>
                        فعال /
                        <?php echo esc_html( PersianDigits::digits( count( $disabled ) ) ); ?>
                        غیرفعال)
                    </span>
                    <?php if ($sensitiveEnabled !== []) : ?>
                        <span class="cpms-sensitive">⚠️ حساس</span>
                    <?php endif; ?>
                </summary>
                <div class="cpms-cards" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
                    <div class="cpms-card">
                        <h2>✅ می‌تواند</h2>
                        <?php if ($enabled === []) : ?>
                            <p class="description">هیچ Capability فعالی ندارد.</p>
                        <?php else : ?>
                            <ul style="margin:0;padding-inline-start:18px">
                                <?php foreach ($enabled as $cap) : ?>
                                    <li><?php echo esc_html(self::labelFor($cap)); ?><?php echo in_array($cap, self::SENSITIVE, true) ? ' <span class="cpms-sensitive">⚠️</span>' : ''; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div class="cpms-card">
                        <h2>⛔ نمی‌تواند</h2>
                        <?php if ($disabled === []) : ?>
                            <p class="description">هیچ Capability غیرفعالی ندارد.</p>
                        <?php else : ?>
                            <ul style="margin:0;padding-inline-start:18px">
                                <?php foreach ($disabled as $cap) : ?>
                                    <li><?php echo esc_html(self::labelFor($cap)); ?><?php echo in_array($cap, self::SENSITIVE, true) ? ' <span class="cpms-sensitive">⚠️</span>' : ''; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sensitiveEnabled !== []) : ?>
                    <div class="cpms-danger-box">
                        <strong>⚠️ دسترسی حساس فعال:</strong>
                        <?php echo esc_html(implode('، ', array_map([self::class, 'labelFor'], $sensitiveEnabled))); ?>.
                        این توانایی‌ها (P-11) به دادهٔ حساس بالینی/مالی دسترسی می‌دهند.
                    </div>
                <?php endif; ?>
            </details>

            <details class="cpms-details cpms-advanced" style="margin-top:14px">
                <summary>Advanced Permissions — ویرایش دقیق Capabilities</summary>
                <p class="description">برای نقش‌های مخاطب‌حساس توصیه می‌شود دسترسی‌های غیرضروری را خاموش کنید. این بخش به‌صورت گروه‌های جمع‌شونده و کم‌تراکم ارائه می‌شود؛ در ابتدا همهٔ گروه‌ها بسته‌اند و هر گروه را فقط در صورت نیاز باز کنید.</p>
                <div class="cpms-danger-box cpms-advanced-warning">
                    <strong>⚠️ هشدار امنیتی:</strong> تغییر دسترسی‌ها عمدی و ماندگار است و می‌تواند به توانایی‌های حساس (P-11) مربوط باشد؛ هر تغییر در Audit با ذکر کاربر ثبت می‌شود.
                </div>
                <p class="cpms-cap-search-field">
                    <label class="cpms-cap-search-label" for="cpms-cap-search-<?php echo esc_attr($role); ?>">جستجو در Capability ها (فارسی یا فنی)</label>
                    <input type="search" id="cpms-cap-search-<?php echo esc_attr($role); ?>" class="cpms-cap-search regular-text"
                            data-scope="<?php echo esc_attr($role); ?>"
                            placeholder="مثلاً: نسخه، پرداخت، نوبت…" aria-label="جستجوی Capability">
                </p>
                <div class="cpms-cap-search-status" data-scope="<?php echo esc_attr($role); ?>" aria-live="polite"></div>
                <?php foreach ( self::GROUPS as $group_title => $caps_in_group ) : ?>
                    <?php
                        $enabled_in_group = array_values(
                            array_intersect( $caps_in_group, $enabled )
                        );
                        $group_sensitive  = array_values(
                            array_intersect( $caps_in_group, self::SENSITIVE )
                        );
                        $enabled_count_fa = PersianDigits::digits( count( $enabled_in_group ) );
                        $total_count_fa   = PersianDigits::digits( count( $caps_in_group ) );
                    ?>
                    <details class="cpms-details cpms-cap-group" data-cap-group data-scope="<?php echo esc_attr($role); ?>">
                        <summary>
                            <span><?php echo esc_html( $group_title ); ?></span>
                            <span class="cpms-group-count">
                                (<?php echo esc_html( $enabled_count_fa ); ?> /
                                <?php echo esc_html( $total_count_fa ); ?>)
                            </span>
                            <?php if ( $group_sensitive !== [] ) : ?>
                                <span class="cpms-sensitive">⚠️ حساس</span>
                            <?php endif; ?>
                        </summary>
                        <div class="cpms-cap-list">
                            <?php foreach ( $caps_in_group as $cap ) : ?>
                                <?php $sensitive = in_array($cap, self::SENSITIVE, true); ?>
                                <label data-cap data-scope="<?php echo esc_attr($role); ?>">
                                    <input type="checkbox" name="role_caps[<?php echo esc_attr($role); ?>][]"
                                            value="<?php echo esc_attr($cap); ?>"
                                            <?php checked(!empty($effective[$cap])); ?>>
                                    <span><?php echo esc_html(self::labelFor($cap)); ?></span>
                                    <?php echo $sensitive ? ' <span class="cpms-sensitive">⚠️</span>' : ''; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
                <div class="cpms-cap-search-empty" data-scope="<?php echo esc_attr($role); ?>">دسترسی‌ای مطابق جستجوی شما پیدا نشد.</div>
            </details>
        </div>
        <?php
    }

    /** توضیح فارسی یک نقش (Chunk F/G). */
    private static function roleDescription(string $role): string
    {
        return match ($role) {
            RolesAndCapabilities::ROLE_SECRETARY => 'مسئول پذیرش و صف: ثبت/جابه‌جایی نوبت، چک‌این، مالی روزانه و ارتباط با بیماران.',
            RolesAndCapabilities::ROLE_DOCTOR => 'جریان اصلی درمان: مشاهدهٔ صف و نوبت، مشاوره، نسخه، یادداشت بالینی و توصیهٔ پزشکی.',
            RolesAndCapabilities::ROLE_PATIENT => 'بدون Capability — دسترسی فقط با مالکیت (فقط داده‌های خودش از طریق موبایل/OTP). این نقش عمداً قابل ویرایش نیست (P-5).',
            RolesAndCapabilities::ROLE_ACCOUNTANT => 'امور مالی و گزارش‌های مالی را می‌بیند (فاکتور/پرداخت/مانده/گزارش مالی/خروجی مالی مجاز)؛ به اطلاعات بالینی و یادداشت خصوصی پزشک دسترسی ندارد.',
            RolesAndCapabilities::ROLE_MANAGER => 'کاربران، پزشکان، برنامه کاری و عملیات مطب را مدیریت می‌کند؛ یادداشت خصوصی پزشک، محتوای بالینی و نسخه/فایل پزشکی را نمی‌بیند. برای مدیریت مطب مجبور به ادمین وردپرس شدن نیست.',
            default => '',
        };
    }

    /**
     * ذخیره ماتریس — Authorization کامل: cpms_config + Nonce + Audit.
     */
    public static function save(): void
    {
        if (!self::isSecurityOwner()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer(self::NONCE_ACTION);

        if ( self::settings_writes_blocked() ) {
            set_transient( self::NOTICE_KEY, self::LICENSE_BLOCKED_MESSAGE, 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
            exit;
        }

        $user = wp_get_current_user();
        $input = isset($_POST['role_caps']) && is_array($_POST['role_caps']) ? wp_unslash($_POST['role_caps']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize در sanitizeCapList (whitelist سخت)

        // Pure, testable boundary (no exit/redirect): the admin handler only owns
        // CSRF/authorization and the redirect; rollback/cache semantics live here.
        try {
            $report = self::apply_capability_matrix( $input, (int) $user->ID );
        } catch ( \Throwable $e ) {
            // The boundary already rolled the option rows back; now revert the
            // in-memory option/role caches so effective permissions cannot outlive
            // the rolled-back durable state (no false success, no privilege residue).
            self::restore_effective_caps_after_rollback();
            set_transient( self::NOTICE_KEY, 'خطا: تغییرات دسترسی ثبت نشد — همهٔ نقش‌ها به وضعیت پیشین بازگشتند.', 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
            exit;
        }

        $notice = $report === []
            ? 'بدون تغییر — دسترسی‌ها از قبل همین بود.'
            : 'ذخیره شد (' . implode( '، ', $report ) . ') — در Audit ثبت شد.';
        set_transient( self::NOTICE_KEY, $notice, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
        exit;
    }

    /**
     * Re-sync effective in-memory permission state with the durable (rolled-back)
     * database state. A MySQL rollback reverts the wp_options rows but not the
     * live object cache or the WP_Roles/WP_User capability snapshots, so both must
     * be rebuilt explicitly after an audit-driven rollback.
     *
     * Narrow invalidation (persistent-object-cache safe): only the two option
     * rows the transaction mutates — the capability-override option and the
     * WP_Roles registry option — plus their alloptions/notoptions snapshots are
     * evicted. A global wp_cache_flush() would discard every unrelated cached
     * row on Redis/Memcached backends, so it is deliberately avoided.
     */
    private static function restore_effective_caps_after_rollback(): void {
        $role_key = wp_roles()->role_key;
        foreach ( [ RolesAndCapabilities::OPTION_OVERRIDE, $role_key ] as $option ) {
            wp_cache_delete( $option, 'options' );
            $not_options = wp_cache_get( 'notoptions', 'options' );
            if ( is_array( $not_options ) && isset( $not_options[ $option ] ) ) {
                unset( $not_options[ $option ] );
                wp_cache_set( 'notoptions', $not_options, 'options' );
            }
        }
        wp_cache_delete( 'alloptions', 'options' );
        wp_roles()->for_site();
        // Explicit self-healing (idempotent by design, TP-10): reconcile the
        // rebuilt in-memory role registry against the rolled-back durable
        // override option, removing any capability whose add_cap survived in
        // memory — effective permissions must equal durable state exactly.
        RolesAndCapabilities::register();
        $current = wp_get_current_user();
        if ( $current instanceof \WP_User ) {
            $current->get_role_caps();
        }
    }

    /**
     * Pure capability-matrix boundary (no redirect/exit) so integration tests can
     * prove audit-failure rollback of BOTH durable option rows and effective
     * in-memory permissions. All role changes and their mandatory audit events
     * share one transaction; an audit failure throws after full rollback.
     *
     * @param array<string, mixed> $input role_caps payload
     * @return list<string> human-readable per-role change report
     */
    public static function apply_capability_matrix( array $input, int $actor_wp_user_id ): array {
        if ( self::settings_writes_blocked() ) {
            throw new \RuntimeException( self::LICENSE_BLOCKED_MESSAGE );
        }
        $report = [];
        // D6 audit atomicity: capability overrides (wp_options) and the WP role
        // registry (wp_user_roles option) are MySQL rows like any other, so the
        // multi-role changes and every mandatory audit event share ONE boundary.
        // A failed audit rolls ALL role changes back; the cache restore in the
        // admin handler then reverts effective permissions to durable state.
        try {
            App::db()->transactional(
                function () use ( $input, $actor_wp_user_id, &$report ) {
                    // B0-PR2-B3 — one locking read of the durable override row: it
                    // both serializes the preset writers and gives the real before-state.
                    $snapshot = self::locked_override_snapshot();
                    // R1 — the write is built from the LOCKED snapshot, role by role, and
                    // persisted once at the end: no other role's consistent-read entry
                    // can be written back over the state this request locked.
                    $map = $snapshot;
                    foreach ( self::ROLE_PRESETS as $role ) {
                        $requested = isset( $input[ $role ] ) && is_array( $input[ $role ] ) ? $input[ $role ] : [];
                        $before    = self::caps_from_snapshot( $role, $snapshot );
                        // B0-PR2-H2 — widening a BUILT-IN preset is refused while any
                        // active holder of that role is a patient/mixed/unknown account,
                        // because the preset applies to those members immediately.
                        self::assert_widening_has_no_patient_holder( $role, $requested, $before );
                        $after   = self::apply_to_locked_map( $map, $role, $requested );
                        $added   = array_values( array_diff( $after, $before ) );
                        $removed = array_values( array_diff( $before, $after ) );
                        if ( $added !== [] || $removed !== [] ) {
                            App::audit()->log(
                                'ROLE_PERMISSION_CHANGED',
                                [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'administrator' ],
                                'role',
                                null,
                                null,
                                [ 'role' => $role, 'caps' => $before ],
                                [ 'role' => $role, 'caps' => $after ],
                                [ 'added' => $added, 'removed' => $removed ]
                            );
                            $report[] = $role . ': +' . count( $added ) . '/-' . count( $removed );
                        }
                    }
                    self::persist_override_map( $map );
                }
            );
        } catch ( \Throwable $e ) {
            // Roll back the in-memory option/role caches to the durable (rolled-back)
            // database state before propagating, so effective permissions can never
            // outlive a failed mandatory audit — including on multi-role requests where
            // earlier roles in the same boundary were already changed in memory.
            self::restore_effective_caps_after_rollback();
            throw $e;
        }

        return $report;
    }

    /**
     * Lock-protected, STRICT snapshot of the durable capability overrides (B3).
     *
     * `capsMap()` falls back to the template when the override option cannot be
     * read, so a narrowed preset looked like its template and could be "widened"
     * past the guard, and a stale before-state could restore a capability after a
     * concurrent narrowing. This reads the option ROW itself, inside the caller's
     * transaction, with `FOR UPDATE` so the two preset writers serialize on it
     * (https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html), and
     * refuses on a malformed value instead of guessing.
     *
     * @return array<string, mixed>
     */
    private static function locked_override_snapshot(): array {
        $wpdb = App::db()->wpdb();
        $rows = App::db()->fetch_all_strict(
            'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1 FOR UPDATE',
            [ RolesAndCapabilities::OPTION_OVERRIDE ]
        );

        $snapshot = array();
        if ( array() !== $rows ) {
            $stored = maybe_unserialize( (string) ( $rows[0]['option_value'] ?? '' ) );
            if ( ! is_array( $stored ) ) {
                throw new \RuntimeException( 'the capability override option is unreadable' );
            }
            $snapshot = $stored;
        }

        // The row was just read from the database, so drop any cached copy: the
        // writers below build their new value from this durable state.
        wp_cache_delete( RolesAndCapabilities::OPTION_OVERRIDE, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        return $snapshot;
    }

    /**
     * Current capabilities of one preset, from the locked snapshot (never a guess).
     *
     * @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private static function caps_from_snapshot( string $role, array $snapshot ): array {
        if ( ! array_key_exists( $role, $snapshot ) ) {
            // R1 — the built-in TEMPLATE, never capsMap(): that reader can serve a
            // cached (consistent-read) copy that differs from the row this snapshot
            // locked, which would let a stale wider set look like "no change".
            return RolesAndCapabilities::sanitizeCapList( self::template_caps( $role ) );
        }
        if ( ! is_array( $snapshot[ $role ] ) ) {
            throw new \RuntimeException( 'the capability override option is malformed' );
        }

        return RolesAndCapabilities::sanitizeCapList( $snapshot[ $role ] );
    }

    /**
     * Write one role's requested set into the LOCKED map (R1): stored when it differs
     * from the built-in template, removed when it equals it — the same rule
     * `RolesAndCapabilities::setRoleCaps()` applies, without its `get_option()` read.
     *
     * @param array<string, mixed>     $map
     * @param array<int|string, mixed> $requested
     * @return list<string> the persisted (sanitized) capability list
     */
    private static function apply_to_locked_map( array &$map, string $role, array $requested ): array {
        $clean = RolesAndCapabilities::sanitizeCapList( $requested );
        if ( $clean === RolesAndCapabilities::sanitizeCapList( self::template_caps( $role ) ) ) {
            unset( $map[ $role ] );
        } else {
            $map[ $role ] = $clean;
        }

        return $clean;
    }

    /**
     * Persist the COMPLETE override map that was built from the locked snapshot (R1).
     *
     * `setRoleCaps()` rebuilds the option from `get_option()` — a consistent read that
     * can carry a stale, wider entry for a role this request never touched, writing it
     * back over durable state. The map written here starts from the row the writer
     * locked, so only the roles this request changed can differ from durable state.
     *
     * @param array<string, mixed> $map
     */
    private static function persist_override_map( array $map ): void {
        update_option( RolesAndCapabilities::OPTION_OVERRIDE, $map, true );
        // B1 — `update_option()` also returns false for an unchanged value, so a failed
        // write is detected by READING the ROW back STRICTLY, never by the return value
        // and never through `get_option()`: that reader answers `array()` for a cached
        // absence as well as for a failed read, so a blocked write whose intended end
        // state is the empty map would read back equal and commit a success audit.
        wp_cache_delete( RolesAndCapabilities::OPTION_OVERRIDE, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        $read_back = self::strict_override_read();
        if ( self::normalize_override_map( $read_back ) !== self::normalize_override_map( $map ) ) {
            throw new \RuntimeException( 'the capability override write did not persist' );
        }
        RolesAndCapabilities::register();
    }

    /**
     * STRICT read of the durable override map (B1).
     *
     * A successful `update_option()` always leaves exactly one row for this option, so a
     * missing row or a database error is a FAILURE, never "the empty map". The stored
     * value is unserialized with `maybe_unserialize()` and a non-array payload is a
     * failure too — the caller compares the normalized result with the map it intended.
     *
     * The SELECT is a LOCKING read (`FOR UPDATE`). A plain SELECT would be a consistent
     * read: inside a transaction whose snapshot was established before this write it can
     * answer with the pre-write version of the row, which would mis-report the write this
     * request just made — https://dev.mysql.com/doc/refman/8.0/en/innodb-consistent-read.html
     * and https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html. A locking read
     * always returns the latest committed version of the row the writer already holds.
     *
     * @return array<string, mixed>
     */
    private static function strict_override_read(): array {
        $wpdb = App::db()->wpdb();
        $rows = App::db()->fetch_all_strict(
            'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1 FOR UPDATE',
            [ RolesAndCapabilities::OPTION_OVERRIDE ]
        );
        if ( array() === $rows ) {
            throw new \RuntimeException( 'the capability override row is missing' );
        }

        $stored = maybe_unserialize( (string) ( $rows[0]['option_value'] ?? '' ) );
        if ( ! is_array( $stored ) ) {
            throw new \RuntimeException( 'the capability override row is unreadable' );
        }

        return $stored;
    }

    /**
     * Order-independent form of an override map, for the read-back comparison (R1).
     *
     * @param array<string, mixed> $map
     * @return array<string, list<string>|null> null marks a malformed entry
     */
    private static function normalize_override_map( array $map ): array {
        $normal = array();
        foreach ( $map as $role => $caps ) {
            if ( ! is_array( $caps ) ) {
                $normal[ (string) $role ] = null;
                continue;
            }
            $list = RolesAndCapabilities::sanitizeCapList( $caps );
            sort( $list );
            $normal[ (string) $role ] = $list;
        }
        ksort( $normal );

        return $normal;
    }

    /**
     * Template capability list of one built-in preset.
     *
     * @return list<string>
     */
    private static function template_caps( string $role ): array {
        return match ( $role ) {
            RolesAndCapabilities::ROLE_DOCTOR => RolesAndCapabilities::DOCTOR_CAPS,
            RolesAndCapabilities::ROLE_ACCOUNTANT => RolesAndCapabilities::ACCOUNTANT_CAPS,
            RolesAndCapabilities::ROLE_MANAGER => RolesAndCapabilities::MANAGER_CAPS,
            default => RolesAndCapabilities::SECRETARY_CAPS,
        };
    }

    /**
     * Refuse a widening of one built-in preset while any ACTIVE holder of that role
     * is a patient / mixed / unknown account (B0-PR2-H2).
     *
     * A widening is any capability present in the requested set but absent from the
     * current preset. Reductions are always allowed. The check runs before the write
     * and throws, so the caller's transaction writes nothing.
     *
     * @param array<int|string, mixed> $requested
     * @param list<string>             $before
     */
    private static function assert_widening_has_no_patient_holder( string $role, array $requested, array $before ): void {
        $added = array_values( array_diff( RolesAndCapabilities::sanitizeCapList( $requested ), $before ) );
        if ( [] === $added ) {
            return;
        }

        $repository = new MembershipRepository( App::db() );
        foreach ( $repository->member_user_ids_for_role( $role ) as $user_id ) {
            if ( PatientAccountPolicy::is_patient_account( $user_id ) ) {
                throw new \RuntimeException( 'widening a role preset held by a patient account is refused' );
            }
        }
    }

    /**
     * Pure single-role template-reset boundary (no redirect/exit). Throws on audit
     * failure after the option/role changes have rolled back.
     */
    public static function reset_role_to_template( string $role ): void {
        if ( self::settings_writes_blocked() ) {
            throw new \RuntimeException( self::LICENSE_BLOCKED_MESSAGE );
        }
        if ( ! in_array( $role, self::ROLE_PRESETS, true ) ) {
            return;
        }
        try {
            App::db()->transactional(
                function () use ( $role ) {
                    // B0-PR2-B3 — the same locking, strict read as the matrix editor.
                    $snapshot = self::locked_override_snapshot();
                    $before   = self::caps_from_snapshot( $role, $snapshot );
                    $template = self::template_caps( $role );
                    // B0-PR2-H2 — a reset restores the template, which can WIDEN a
                    // currently narrowed preset; refused under the same condition.
                    self::assert_widening_has_no_patient_holder( $role, $template, $before );
                    // R1 — the reset removes ONLY this role's entry from the locked map.
                    $map = $snapshot;
                    unset( $map[ $role ] );
                    self::persist_override_map( $map );
                    App::audit()->log(
                        'ROLE_PERMISSION_RESET',
                        [ 'wp_user_id' => (int) get_current_user_id(), 'role' => 'administrator' ],
                        'role',
                        null,
                        null,
                        [ 'role' => $role, 'caps' => $before ],
                        [ 'role' => $role, 'caps' => array_keys( array_filter( RolesAndCapabilities::capsMap( $role ) ) ) ],
                        []
                    );
                }
            );
        } catch ( \Throwable $e ) {
            self::restore_effective_caps_after_rollback();
            throw $e;
        }
    }

    /**
     * بازگشت نقش به Template پیش‌فرض کلاس (حذف Override) — لینک از همان صفحه.
     */
    public static function reset(): void
    {
        if (!self::isSecurityOwner()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer('cpms_reset_role_caps_' . (string) ($_GET['role'] ?? ''));

        if ( self::settings_writes_blocked() ) {
            set_transient( self::NOTICE_KEY, self::LICENSE_BLOCKED_MESSAGE, 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
            exit;
        }

        $role = (string) ( $_GET['role'] ?? '' );
        if ( in_array( $role, self::ROLE_PRESETS, true ) ) {
            // D6 audit atomicity: the template reset and its mandatory audit share
            // one boundary; on audit failure both the option row and the effective
            // in-memory role capabilities are restored to the pre-reset state.
            try {
                self::reset_role_to_template( $role );
            } catch ( \Throwable $e ) {
                self::restore_effective_caps_after_rollback();
                set_transient( self::NOTICE_KEY, 'خطا: بازنشانی نقش ثبت نشد — وضعیت پیشین حفظ شد.', 60 );
                wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
                exit;
            }
        }

        set_transient( self::NOTICE_KEY, 'نقش به Template پیش‌فرض بازگشت — در Audit ثبت شد.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=cpms-roles' ) );
        exit;
    }

    private static function labelFor(string $cap): string
    {
        return self::LABELS[$cap] ?? $cap;
    }
}

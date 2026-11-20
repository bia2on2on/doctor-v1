<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-22..TP-35 — Release 1.0 role authorization matrix, exercised through the
 * real clinic-scoped AuthorizationService (no hard-coded booleans, no mocks).
 *
 * Roles under test (src/Auth/RolesAndCapabilities.php):
 *   cpms_patient     :19
 *   cpms_secretary   :20  (reception / منشی مطب)
 *   cpms_doctor      :21
 *   cpms_accountant  :23  (finance / حسابدار)
 *   cpms_manager     :25
 *   administrator     WordPress core role — no ROLE_* constant exists; its
 *                     default cpms_config + cpms_sms_config are granted in
 *                     RolesAndCapabilities::register() (:197-207).
 *
 * Design sources (cited inline below):
 *   docs/permissions/permission-matrix.md §3 (:137-166), §4.1-§4.4 (:181-247),
 *   §7 financial policy (:273-289), P-3/P-5 (:26-29), P-9 (:32).
 *   docs/architecture/phase0.5-target-model.md §الف-۴ (:190-256).
 *   docs/verification-report-admin-ux-final.md (:91-97).
 *   docs/decisions/2026-10-15-financial-authorization-policy.md (§1-§3).
 *   docs/adr/ADR-0002-roles-capabilities.md.
 *
 * Scope discipline: this file proves the capability/role matrix only. The
 * patient route gate (PatientAccess / RestBase / PatientController) is owned by
 * another PR and is deliberately NOT touched here.
 *
 * Resolution rule read from the code before writing any expectation
 * (AuthorizationService::can(), src/Application/Authorization/AuthorizationService.php:79-168):
 *   explicit membership DENY > explicit membership GRANT > membership role_key
 *   preset (RolesAndCapabilities::capsMap) > deny by default.
 * A WordPress-global role or capability is never consulted by can(), so neither
 * a second global role nor a global cpms_* capability can widen Clinic scope.
 */
final class ReleaseRoleAuthorizationMatrixTest extends WP_UnitTestCase
{
    private int $clinicId = 0;

    private int $foreignClinicId = 0;

    /**
     * Doctor — allowed actions (permission-matrix.md:139-166 §3, §4.3 :219-234;
     * verification-report-admin-ux-final.md:94).
     *
     * @var list<string>
     */
    private const DOCTOR_ALLOWED = [
        'cpms_patient_read', 'cpms_patient_update',
        'cpms_appt_read', 'cpms_appt_create', 'cpms_appt_confirm', 'cpms_appt_cancel',
        'cpms_appt_reschedule', 'cpms_appt_no_show',
        'cpms_visit_read', 'cpms_queue_read', 'cpms_queue_call',
        'cpms_consult_start', 'cpms_consult_complete', 'cpms_consult_reopen',
        'cpms_medical_read', 'cpms_note_create', 'cpms_note_update', 'cpms_rec_create',
        'cpms_private_note_read', 'cpms_private_note_create', 'cpms_private_note_update',
        'cpms_rx_read', 'cpms_rx_create', 'cpms_rx_void',
        'cpms_file_upload', 'cpms_file_read',
        'cpms_invoice_read', 'cpms_invoice_create',
        'cpms_payment_void', 'cpms_payment_refund',
        'cpms_finance_read', 'cpms_report_read', 'cpms_search',
    ];

    /**
     * Doctor — denied actions. Every entry is a trap a naive implementation
     * would wrongly allow (permission-matrix.md:140-166 §3, §4.3 :231-233).
     *
     * @var list<string>
     */
    private const DOCTOR_DENIED = [
        'cpms_patient_create', 'cpms_patient_archive', 'cpms_patient_merge',
        'cpms_queue_checkin', 'cpms_queue_advance', 'cpms_queue_checkout',
        'cpms_invoice_adjust', 'cpms_invoice_void',
        'cpms_payment_create',
        'cpms_export', 'cpms_audit_read',
        'cpms_config', 'cpms_sms_config',
    ];

    /**
     * Secretary (reception) — allowed actions (permission-matrix.md:139-166 §3,
     * §4.2 :203-217, §7 :279; verification-report-admin-ux-final.md:95).
     *
     * @var list<string>
     */
    private const SECRETARY_ALLOWED = [
        'cpms_patient_read', 'cpms_patient_create', 'cpms_patient_update',
        'cpms_appt_read', 'cpms_appt_create', 'cpms_appt_confirm', 'cpms_appt_cancel',
        'cpms_appt_reschedule', 'cpms_appt_no_show',
        'cpms_visit_read', 'cpms_queue_read', 'cpms_queue_checkin',
        'cpms_queue_advance', 'cpms_queue_checkout',
        'cpms_file_upload', 'cpms_file_read',
        'cpms_invoice_read', 'cpms_invoice_create', 'cpms_invoice_adjust', 'cpms_invoice_void',
        'cpms_payment_create',
        'cpms_finance_read', 'cpms_search',
    ];

    /**
     * Secretary — denied actions. cpms_payment_void / cpms_payment_refund are
     * the 2026-10-15 owner decision (permission-matrix.md:279) and the naive
     * trap: the older phase0.5 matrix still granted them.
     *
     * @var list<string>
     */
    private const SECRETARY_DENIED = [
        'cpms_patient_archive', 'cpms_patient_merge',
        'cpms_queue_call',
        'cpms_consult_start', 'cpms_consult_complete', 'cpms_consult_reopen',
        'cpms_medical_read', 'cpms_note_create', 'cpms_note_update', 'cpms_rec_create',
        'cpms_private_note_read', 'cpms_private_note_create', 'cpms_private_note_update',
        'cpms_rx_read', 'cpms_rx_create', 'cpms_rx_void',
        'cpms_payment_void', 'cpms_payment_refund',
        'cpms_report_read', 'cpms_export', 'cpms_audit_read',
        'cpms_config', 'cpms_sms_config',
    ];

    /**
     * Accountant (finance) — allowed actions (phase0.5-target-model.md:192,
     * verification-report-admin-ux-final.md:96, permission-matrix.md:281 §7).
     *
     * @var list<string>
     */
    private const ACCOUNTANT_ALLOWED = [
        'cpms_invoice_read', 'cpms_invoice_create', 'cpms_invoice_adjust', 'cpms_invoice_void',
        'cpms_payment_create', 'cpms_payment_void', 'cpms_payment_refund',
        'cpms_finance_read', 'cpms_report_read', 'cpms_export', 'cpms_search',
    ];

    /**
     * Accountant — denied actions. Patient/appointment/queue and every clinical
     * capability are the naive traps (verification-report-admin-ux-final.md:96
     * «تمام بالینی/خصوصی/نسخه/فایل/صف/cpms_config/بیماران/پزشک ممنوع»).
     *
     * @var list<string>
     */
    private const ACCOUNTANT_DENIED = [
        'cpms_patient_read', 'cpms_patient_create', 'cpms_patient_update',
        'cpms_patient_archive', 'cpms_patient_merge',
        'cpms_appt_read', 'cpms_appt_create', 'cpms_appt_confirm', 'cpms_appt_cancel',
        'cpms_appt_reschedule', 'cpms_appt_no_show',
        'cpms_visit_read', 'cpms_queue_read', 'cpms_queue_checkin',
        'cpms_queue_advance', 'cpms_queue_call', 'cpms_queue_checkout',
        'cpms_consult_start', 'cpms_consult_complete', 'cpms_consult_reopen',
        'cpms_medical_read', 'cpms_note_create', 'cpms_note_update', 'cpms_rec_create',
        'cpms_private_note_read', 'cpms_private_note_create', 'cpms_private_note_update',
        'cpms_rx_read', 'cpms_rx_create', 'cpms_rx_void',
        'cpms_file_upload', 'cpms_file_read',
        'cpms_audit_read', 'cpms_config', 'cpms_sms_config',
    ];

    /**
     * Clinic Manager — allowed actions (phase0.5-target-model.md:193,
     * verification-report-admin-ux-final.md:93, permission-matrix.md:282 §7).
     *
     * @var list<string>
     */
    private const MANAGER_ALLOWED = [
        'cpms_config', 'cpms_sms_config', 'cpms_patient_read', 'cpms_report_read', 'cpms_search',
    ];

    /**
     * Clinic Manager — denied actions. Every finance capability is the naive
     * trap: permission-matrix.md:282 keeps the manager on explicit delegation
     * only, and the clinical/private set is refused by item 4.
     *
     * @var list<string>
     */
    private const MANAGER_DENIED = [
        'cpms_patient_create', 'cpms_patient_update', 'cpms_patient_archive', 'cpms_patient_merge',
        'cpms_appt_read', 'cpms_appt_create', 'cpms_appt_confirm', 'cpms_appt_cancel',
        'cpms_appt_reschedule', 'cpms_appt_no_show',
        'cpms_visit_read', 'cpms_queue_read', 'cpms_queue_checkin',
        'cpms_queue_advance', 'cpms_queue_call', 'cpms_queue_checkout',
        'cpms_consult_start', 'cpms_consult_complete', 'cpms_consult_reopen',
        'cpms_medical_read', 'cpms_note_create', 'cpms_note_update', 'cpms_rec_create',
        'cpms_private_note_read', 'cpms_private_note_create', 'cpms_private_note_update',
        'cpms_rx_read', 'cpms_rx_create', 'cpms_rx_void',
        'cpms_file_upload', 'cpms_file_read',
        'cpms_invoice_read', 'cpms_invoice_create', 'cpms_invoice_adjust', 'cpms_invoice_void',
        'cpms_payment_create', 'cpms_payment_void', 'cpms_payment_refund',
        'cpms_finance_read',
        'cpms_export', 'cpms_audit_read',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        RolesAndCapabilities::register();

        $this->clinicId = $this->seedClinic('role-matrix-a');
        $this->foreignClinicId = $this->seedClinic('role-matrix-b');
        self::assertNotSame($this->clinicId, $this->foreignClinicId, 'fixture: two distinct Clinics');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    // ================= TP-22 — slugs, preset sizes, admin default =================

    public function testRoleSlugsAndPresetSizesMatchDesignDocument(): void
    {
        // RolesAndCapabilities.php:19-25 — the five registered CPMS role slugs.
        self::assertSame('cpms_patient', RolesAndCapabilities::ROLE_PATIENT);
        self::assertSame('cpms_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        self::assertSame('cpms_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        self::assertSame('cpms_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        self::assertSame('cpms_manager', RolesAndCapabilities::ROLE_MANAGER);

        // phase0.5-target-model.md:192-193 — doctor 33 / secretary 25 / accountant 11 / manager 5.
        // Secretary is 23 today: the 2026-10-15 policy removed cpms_payment_void and
        // cpms_payment_refund from the preset (permission-matrix.md:279) — 25 - 2 = 23.
        self::assertCount(33, RolesAndCapabilities::DOCTOR_CAPS, 'doctor preset size');
        self::assertCount(23, RolesAndCapabilities::SECRETARY_CAPS, 'secretary preset size (post 2026-10-15)');
        self::assertCount(11, RolesAndCapabilities::ACCOUNTANT_CAPS, 'accountant preset size');
        self::assertCount(5, RolesAndCapabilities::MANAGER_CAPS, 'manager preset size');

        // P-5 — the patient preset is empty (permission-matrix.md:28, :168).
        self::assertSame([], RolesAndCapabilities::capsMap(RolesAndCapabilities::ROLE_PATIENT), 'patient holds no capability');

        // P-3 — the WordPress administrator default is technical-only
        // (permission-matrix.md:169; ADR-0002 «فقط cpms_config + فنی»).
        $admin = get_role('administrator');
        self::assertInstanceOf(\WP_Role::class, $admin, 'administrator role must exist');
        self::assertTrue($admin->has_cap(RolesAndCapabilities::CONFIG), 'admin default: cpms_config');
        self::assertTrue($admin->has_cap(RolesAndCapabilities::SMS_CONFIG), 'admin default: cpms_sms_config');
        self::assertFalse($admin->has_cap(RolesAndCapabilities::MEDICAL_READ), 'P-3: no default medical access');
        self::assertFalse($admin->has_cap(RolesAndCapabilities::AUDIT_READ), 'P-3: no default audit access');
        self::assertFalse($admin->has_cap(RolesAndCapabilities::EXPORT), 'P-3: no default export');
    }

    // ================= TP-23 — design allowed sets ↔ built-in presets =================

    public function testDesignAllowedSetsEqualBuiltinRolePresets(): void
    {
        self::assertSame(
            $this->sorted(self::DOCTOR_ALLOWED),
            $this->sorted(RolesAndCapabilities::DOCTOR_CAPS),
            'doctor design set must equal DOCTOR_CAPS exactly (permission-matrix.md:139-166)'
        );
        self::assertSame(
            $this->sorted(self::SECRETARY_ALLOWED),
            $this->sorted(RolesAndCapabilities::SECRETARY_CAPS),
            'secretary design set must equal SECRETARY_CAPS exactly (permission-matrix.md:139-166 + §7)'
        );
        self::assertSame(
            $this->sorted(self::ACCOUNTANT_ALLOWED),
            $this->sorted(RolesAndCapabilities::ACCOUNTANT_CAPS),
            'accountant design set must equal ACCOUNTANT_CAPS exactly (phase0.5-target-model.md:192)'
        );
        self::assertSame(
            $this->sorted(self::MANAGER_ALLOWED),
            $this->sorted(RolesAndCapabilities::MANAGER_CAPS),
            'manager design set must equal MANAGER_CAPS exactly (phase0.5-target-model.md:193)'
        );

        // Least privilege both ways: no design-denied action may sit in the preset.
        self::assertSame([], array_values(array_intersect(self::DOCTOR_DENIED, RolesAndCapabilities::DOCTOR_CAPS)));
        self::assertSame([], array_values(array_intersect(self::SECRETARY_DENIED, RolesAndCapabilities::SECRETARY_CAPS)));
        self::assertSame([], array_values(array_intersect(self::ACCOUNTANT_DENIED, RolesAndCapabilities::ACCOUNTANT_CAPS)));
        self::assertSame([], array_values(array_intersect(self::MANAGER_DENIED, RolesAndCapabilities::MANAGER_CAPS)));
    }

    // ================= TP-24..TP-27 — staff roles through the real service =================

    public function testDoctorRoleAllowedAndDeniedActionsThroughAuthorizationService(): void
    {
        $doctor = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_DOCTOR, 'rm_doctor');
        $authz = $this->authz();

        foreach (self::DOCTOR_ALLOWED as $cap) {
            self::assertTrue($authz->can($doctor, $this->clinicId, $cap), "doctor must be allowed {$cap}");
        }
        foreach (self::DOCTOR_DENIED as $cap) {
            self::assertFalse($authz->can($doctor, $this->clinicId, $cap), "doctor must be denied {$cap}");
        }

        // The authoritative authorize() path agrees with can() on a representative pair.
        $authz->authorize($doctor, $this->clinicId, RolesAndCapabilities::MEDICAL_READ);
        $this->assertTypedDenial(
            'AUTH_DENIED',
            403,
            fn () => $authz->authorize($doctor, $this->clinicId, RolesAndCapabilities::PATIENT_CREATE),
            'doctor patient_create'
        );
    }

    public function testSecretaryRoleAllowedAndDeniedActionsThroughAuthorizationService(): void
    {
        $secretary = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_SECRETARY, 'rm_secretary');
        $authz = $this->authz();

        foreach (self::SECRETARY_ALLOWED as $cap) {
            self::assertTrue($authz->can($secretary, $this->clinicId, $cap), "secretary must be allowed {$cap}");
        }
        foreach (self::SECRETARY_DENIED as $cap) {
            self::assertFalse($authz->can($secretary, $this->clinicId, $cap), "secretary must be denied {$cap}");
        }

        $authz->authorize($secretary, $this->clinicId, RolesAndCapabilities::QUEUE_CHECKIN);
        $this->assertTypedDenial(
            'AUTH_DENIED',
            403,
            fn () => $authz->authorize($secretary, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID),
            'secretary payment_void (2026-10-15 policy)'
        );
    }

    public function testAccountantRoleAllowedAndDeniedActionsThroughAuthorizationService(): void
    {
        $accountant = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT, 'rm_accountant');
        $authz = $this->authz();

        foreach (self::ACCOUNTANT_ALLOWED as $cap) {
            self::assertTrue($authz->can($accountant, $this->clinicId, $cap), "accountant must be allowed {$cap}");
        }
        foreach (self::ACCOUNTANT_DENIED as $cap) {
            self::assertFalse($authz->can($accountant, $this->clinicId, $cap), "accountant must be denied {$cap}");
        }

        $authz->authorize($accountant, $this->clinicId, RolesAndCapabilities::INVOICE_VOID);
        $this->assertTypedDenial(
            'AUTH_DENIED',
            403,
            fn () => $authz->authorize($accountant, $this->clinicId, RolesAndCapabilities::PATIENT_READ),
            'accountant patient_read'
        );
    }

    public function testManagerRoleAllowedAndDeniedActionsThroughAuthorizationService(): void
    {
        $manager = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_MANAGER, 'rm_manager');
        $authz = $this->authz();

        foreach (self::MANAGER_ALLOWED as $cap) {
            self::assertTrue($authz->can($manager, $this->clinicId, $cap), "manager must be allowed {$cap}");
        }
        foreach (self::MANAGER_DENIED as $cap) {
            self::assertFalse($authz->can($manager, $this->clinicId, $cap), "manager must be denied {$cap}");
        }

        $authz->authorize($manager, $this->clinicId, RolesAndCapabilities::CONFIG);
        $this->assertTypedDenial(
            'AUTH_DENIED',
            403,
            fn () => $authz->authorize($manager, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID),
            'manager payment_void (permission-matrix.md:282)'
        );
    }

    // ================= TP-28 — patient role (capability/matrix only) =================

    public function testPatientRoleHoldsNoCapabilityThroughAuthorizationService(): void
    {
        $patient = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_PATIENT, 'rm_patient');
        $authz = $this->authz();

        $role = get_role(RolesAndCapabilities::ROLE_PATIENT);
        self::assertInstanceOf(\WP_Role::class, $role, 'cpms_patient role must be registered');
        $cpms = array_values(array_filter(
            array_keys($role->capabilities),
            static fn (string $cap): bool => str_starts_with($cap, 'cpms_')
        ));
        self::assertSame([], $cpms, 'P-5: the patient role must not hold any cpms_* capability');

        foreach (RolesAndCapabilities::ALL_CAPS as $cap) {
            self::assertFalse($authz->can($patient, $this->clinicId, $cap), "patient must never hold {$cap} (P-5)");
        }
    }

    // ================= TP-29 — administrator (WP role, not a CPMS preset) =================

    public function testAdministratorGlobalCapsDoNotAuthorizeClinicScope(): void
    {
        $admin = $this->makeUser('rm_admin', 'administrator');
        $authz = $this->authz();

        $user = get_userdata($admin);
        self::assertNotFalse($user);
        self::assertTrue($user->has_cap(RolesAndCapabilities::CONFIG), 'premise: global cpms_config');
        self::assertTrue($user->has_cap(RolesAndCapabilities::SMS_CONFIG), 'premise: global cpms_sms_config');

        // Global caps with no Clinic membership authorize nothing.
        self::assertFalse($authz->can($admin, $this->clinicId, RolesAndCapabilities::CONFIG));
        self::assertFalse($authz->can($admin, $this->clinicId, RolesAndCapabilities::PATIENT_READ));
        $this->assertTypedDenial(
            'AUTH_NO_MEMBERSHIP',
            403,
            fn () => $authz->authorize($admin, $this->clinicId, RolesAndCapabilities::CONFIG),
            'administrator without membership'
        );

        // Even WITH a Clinic membership whose role_key is the WordPress administrator
        // label, the global caps do not leak: the membership role_key is the only role
        // source (AuthorizationService.php:150-165). capsMap('administrator') is empty.
        $membershipId = App::membership_service()->create_membership($this->clinicId, $admin, 'administrator');
        self::assertGreaterThan(0, $membershipId, 'administrator membership fixture');
        self::assertFalse(
            $authz->can($admin, $this->clinicId, RolesAndCapabilities::CONFIG),
            'global cpms_config must not authorize Clinic scope'
        );
        self::assertFalse($authz->can($admin, $this->clinicId, RolesAndCapabilities::SMS_CONFIG));
        self::assertFalse($authz->can($admin, $this->clinicId, RolesAndCapabilities::PATIENT_READ));
        $this->assertTypedDenial(
            'AUTH_DENIED',
            403,
            fn () => $authz->authorize($admin, $this->clinicId, RolesAndCapabilities::CONFIG),
            'administrator membership with role_key administrator'
        );

        // Control on the SAME actor: only a membership role_key that carries the
        // capability authorizes it — the WordPress role is irrelevant.
        App::membership_service()->set_role_key($membershipId, RolesAndCapabilities::ROLE_MANAGER);
        self::assertTrue(
            $authz->can($admin, $this->clinicId, RolesAndCapabilities::CONFIG),
            'control: membership role_key cpms_manager authorizes cpms_config'
        );
        self::assertFalse(
            $authz->can($admin, $this->clinicId, RolesAndCapabilities::MEDICAL_READ),
            'control: the manager preset still carries no clinical capability'
        );
    }

    // ================= TP-30 — role user with no Clinic membership =================

    public function testRoleUserWithoutClinicMembershipIsDeniedClinicData(): void
    {
        $authz = $this->authz();

        $cases = [
            'cpms_doctor'     => [RolesAndCapabilities::ROLE_DOCTOR, RolesAndCapabilities::MEDICAL_READ],
            'cpms_secretary'  => [RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::PATIENT_READ],
            'cpms_accountant' => [RolesAndCapabilities::ROLE_ACCOUNTANT, RolesAndCapabilities::INVOICE_READ],
            'cpms_manager'    => [RolesAndCapabilities::ROLE_MANAGER, RolesAndCapabilities::CONFIG],
            'cpms_patient'    => [RolesAndCapabilities::ROLE_PATIENT, RolesAndCapabilities::PATIENT_READ],
            'administrator'   => ['administrator', RolesAndCapabilities::CONFIG],
        ];

        foreach ($cases as $label => [$roleKey, $cap]) {
            $userId = $this->makeUser('rm_nomember_' . $label, $roleKey);
            self::assertFalse(
                $authz->can($userId, $this->clinicId, $cap),
                "{$label} without a Clinic membership must be denied {$cap}"
            );
            $this->assertTypedDenial(
                'AUTH_NO_MEMBERSHIP',
                403,
                fn () => $authz->authorize($userId, $this->clinicId, $cap),
                "{$label} without a Clinic membership"
            );
        }
    }

    // ================= TP-31 — two global roles, one membership role_key =================

    public function testUserHoldingTwoRolesResolvesOnlyThroughMembershipRoleKey(): void
    {
        $authz = $this->authz();

        // The same WP account carries BOTH global roles in each case; the durable
        // membership role_key is the only role source can() consults.
        $doctorSide = $this->makeUser('rm_multi_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        $this->addGlobalRole($doctorSide, RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($doctorSide, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR);

        $accountantSide = $this->makeUser('rm_multi_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->addGlobalRole($accountantSide, RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($accountantSide, $this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT);

        self::assertTrue(
            $authz->can($doctorSide, $this->clinicId, RolesAndCapabilities::MEDICAL_READ),
            'doctor membership: doctor capability granted'
        );
        self::assertFalse(
            $authz->can($doctorSide, $this->clinicId, RolesAndCapabilities::EXPORT),
            'doctor membership: the second (accountant) global role must not add cpms_export'
        );
        self::assertFalse(
            $authz->can($doctorSide, $this->clinicId, RolesAndCapabilities::INVOICE_VOID),
            'doctor membership: the second (accountant) global role must not add cpms_invoice_void'
        );

        self::assertTrue(
            $authz->can($accountantSide, $this->clinicId, RolesAndCapabilities::EXPORT),
            'accountant membership: accountant capability granted'
        );
        self::assertFalse(
            $authz->can($accountantSide, $this->clinicId, RolesAndCapabilities::MEDICAL_READ),
            'accountant membership: the second (doctor) global role must not add cpms_medical_read'
        );
        self::assertFalse(
            $authz->can($accountantSide, $this->clinicId, RolesAndCapabilities::PRIVATE_NOTE_READ),
            'accountant membership: the second (doctor) global role must not add cpms_private_note_read'
        );
    }

    // ================= TP-32 — global capability does not leak =================

    public function testGlobalCapabilityDoesNotLeakIntoClinicScope(): void
    {
        $authz = $this->authz();

        $doctor = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_DOCTOR, 'rm_global_doctor');
        $this->grantGlobalCap($doctor, RolesAndCapabilities::EXPORT);
        $this->grantGlobalCap($doctor, 'manage_options');

        self::assertFalse(
            $authz->can($doctor, $this->clinicId, RolesAndCapabilities::EXPORT),
            'a global cpms_export capability must not authorize Clinic scope'
        );
        self::assertFalse(
            $authz->can($doctor, $this->clinicId, RolesAndCapabilities::CONFIG),
            'manage_options must not authorize Clinic scope'
        );
        self::assertTrue(
            $authz->can($doctor, $this->clinicId, RolesAndCapabilities::MEDICAL_READ),
            'control: the membership preset still applies'
        );

        $accountant = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT, 'rm_global_acc');
        $this->grantGlobalCap($accountant, RolesAndCapabilities::MEDICAL_READ);

        self::assertFalse(
            $authz->can($accountant, $this->clinicId, RolesAndCapabilities::MEDICAL_READ),
            'a global clinical capability must not authorize Clinic scope'
        );
        self::assertTrue(
            $authz->can($accountant, $this->clinicId, RolesAndCapabilities::EXPORT),
            'control: the accountant preset still applies'
        );

        // scoped_decision() is deliberately a different contract, described here
        // rather than invented: with NO membership row it returns null so the
        // legacy WordPress-capability path may still answer, and once a membership
        // exists it is the scoped engine's verdict only — a global capability can
        // never turn it true (AuthorizationService.php:186-209).
        $nonMember = $this->makeUser('rm_global_nomember', RolesAndCapabilities::ROLE_DOCTOR);
        self::assertNull(
            $authz->scoped_decision($nonMember, $this->clinicId, RolesAndCapabilities::EXPORT),
            'no membership: scoped_decision stays null (legacy path)'
        );
        self::assertFalse(
            $authz->scoped_decision($doctor, $this->clinicId, RolesAndCapabilities::EXPORT),
            'membership without the capability: scoped_decision is the scoped verdict'
        );
    }

    // ================= TP-33 — doctor sensitive payment needs ownership =================

    public function testDoctorSensitivePaymentOperationsRequireVerifiedOwnership(): void
    {
        $doctor = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_DOCTOR, 'rm_own_doctor');
        $authz = $this->authz();

        // The capabilities ARE in the doctor preset (permission-matrix.md:158-159 §3).
        self::assertTrue($authz->can($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID));
        self::assertTrue($authz->can($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_REFUND));

        // ...but the ownership-aware entry point refuses an unowned transaction
        // (2026-10-15 policy decision 2; AuthorizationService::can_with_ownership).
        self::assertFalse(
            $authz->can_with_ownership($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID, false)
        );
        self::assertFalse(
            $authz->can_with_ownership($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_REFUND, false)
        );
        self::assertTrue(
            $authz->can_with_ownership($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID, true)
        );

        try {
            $authz->authorize_with_ownership($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_REFUND, false);
            self::fail('doctor refund without verified ownership must throw');
        } catch (AuthorizationException $e) {
            self::assertSame('AUTH_OWNERSHIP_REQUIRED', $e->getErrorCode());
            self::assertSame(403, $e->getHttpStatus());
        }

        // An explicit membership GRANT cannot lift the doctor ownership conjunct.
        $membership = App::membership_service()->membership_for($this->clinicId, $doctor);
        self::assertNotNull($membership, 'doctor membership fixture');
        App::membership_service()->set_capability(
            (int) $membership['id'],
            RolesAndCapabilities::PAYMENT_VOID,
            'grant'
        );
        self::assertFalse(
            $authz->can_with_ownership($doctor, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID, false),
            'an explicit membership GRANT must not bypass the doctor ownership conjunct'
        );

        // A non-doctor actor is not ownership-bound (policy §3): the accountant
        // preset authorizes the sensitive payment capability unconditionally.
        $accountant = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_ACCOUNTANT, 'rm_own_acc');
        self::assertTrue(
            $authz->can_with_ownership($accountant, $this->clinicId, RolesAndCapabilities::PAYMENT_VOID, false),
            'accountant keeps the unchanged non-ownership-bound behaviour'
        );
    }

    // ================= TP-34 — authorize() agrees with can() on the whole matrix =================

    public function testAuthorizeAgreesWithCanAcrossTheWholeDesignMatrix(): void
    {
        $matrix = [
            RolesAndCapabilities::ROLE_DOCTOR     => [self::DOCTOR_ALLOWED, self::DOCTOR_DENIED],
            RolesAndCapabilities::ROLE_SECRETARY  => [self::SECRETARY_ALLOWED, self::SECRETARY_DENIED],
            RolesAndCapabilities::ROLE_ACCOUNTANT => [self::ACCOUNTANT_ALLOWED, self::ACCOUNTANT_DENIED],
            RolesAndCapabilities::ROLE_MANAGER    => [self::MANAGER_ALLOWED, self::MANAGER_DENIED],
        ];

        foreach ($matrix as $roleKey => [$allowed, $denied]) {
            $userId = $this->staff($this->clinicId, $roleKey, 'rm_matrix');
            $authz = $this->authz();

            // The design partition must cover the whole 46-capability catalogue exactly once.
            self::assertSame(
                $this->sorted(RolesAndCapabilities::ALL_CAPS),
                $this->sorted(array_merge($allowed, $denied)),
                "{$roleKey}: design allowed+denied must equal ALL_CAPS with no overlap"
            );

            foreach ($allowed as $cap) {
                self::assertTrue($authz->can($userId, $this->clinicId, $cap), "{$roleKey} {$cap} can()");
                $authz->authorize($userId, $this->clinicId, $cap);
            }
            foreach ($denied as $cap) {
                self::assertFalse($authz->can($userId, $this->clinicId, $cap), "{$roleKey} {$cap} can()");
                $this->assertTypedDenial(
                    'AUTH_DENIED',
                    403,
                    fn () => $authz->authorize($userId, $this->clinicId, $cap),
                    "{$roleKey} {$cap} authorize()"
                );
            }
        }
    }

    // ================= TP-35 — Clinic scoping / cross-Clinic =================

    public function testRoleMembershipIsClinicScopedAndDoesNotLeakToAnotherClinic(): void
    {
        $authz = $this->authz();
        $doctor = $this->staff($this->clinicId, RolesAndCapabilities::ROLE_DOCTOR, 'rm_scope_doctor');

        self::assertTrue($authz->can($doctor, $this->clinicId, RolesAndCapabilities::MEDICAL_READ));
        self::assertFalse(
            $authz->can($doctor, $this->foreignClinicId, RolesAndCapabilities::MEDICAL_READ),
            'a membership in Clinic A must not authorize Clinic B'
        );
        $this->assertTypedDenial(
            'AUTH_NO_MEMBERSHIP',
            403,
            fn () => $authz->authorize($doctor, $this->foreignClinicId, RolesAndCapabilities::MEDICAL_READ),
            'doctor requesting a foreign Clinic'
        );

        // Durable object ownership read server-side from persistence: the object
        // belongs to Clinic B, so Clinic-A authority must not reach it.
        $patientId = $this->insertPatient($this->foreignClinicId, 'rm-scope');
        $ownerClinicId = (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
        self::assertSame($this->foreignClinicId, $ownerClinicId, 'fixture: durable owner is Clinic B');
        self::assertFalse(
            $authz->canForObject($doctor, $this->clinicId, RolesAndCapabilities::PATIENT_READ, $ownerClinicId),
            'cross-Clinic object access must be denied'
        );
    }

    // ================= helpers =================

    /**
     * A real Clinic row (status defaults to active) in the seeded Organization.
     */
    private function seedClinic(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization must exist');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'Role Matrix Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id'   => $clinicId,
                'mrn'         => 'M-' . bin2hex(random_bytes(5)),
                'first_name'  => $tag,
                'last_name'   => 'RoleMatrix',
                'mobile'      => '09' . random_int(1000000000, 9999999999),
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user(
            $login . '_' . bin2hex(random_bytes(3)),
            wp_generate_password(24),
            $login . '_' . bin2hex(random_bytes(2)) . '@rolematrix.test'
        );
        self::assertGreaterThan(0, $userId, "user {$login} created");
        $user = get_userdata($userId);
        self::assertNotFalse($user, "user {$login} must exist");
        $user->set_role($role);

        return $userId;
    }

    /**
     * A WP user with an ACTIVE membership carrying the given built-in role key.
     */
    private function staff(int $clinicId, string $roleKey, string $tag): int
    {
        $userId = $this->makeUser($tag, $roleKey);
        cpms_test_seed_membership($userId, $clinicId, $roleKey);

        return $userId;
    }

    private function addGlobalRole(int $userId, string $role): void
    {
        $user = get_userdata($userId);
        if ($user === false) {
            self::fail("user {$userId} not found");
        }
        $user->add_role($role);
        self::assertTrue($user->has_role($role), "fixture: user must hold the second global role {$role}");
    }

    private function grantGlobalCap(int $userId, string $capability): void
    {
        $user = get_userdata($userId);
        if ($user === false) {
            self::fail("user {$userId} not found");
        }
        $user->add_cap($capability);
        self::assertTrue($user->has_cap($capability), "fixture: user must hold the global capability {$capability}");
    }

    private function authz(): \ClinicCore\Application\Authorization\AuthorizationService
    {
        return App::authorization_service();
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param callable(): void $call
     */
    private function assertTypedDenial(string $code, int $status, callable $call, string $label): AuthorizationException
    {
        try {
            $call();
            self::fail("{$label}: expected {$code} denial");
        } catch (AuthorizationException $e) {
            self::assertSame($code, $e->getErrorCode(), "{$label}: error code");
            self::assertSame($status, $e->getHttpStatus(), "{$label}: http status");

            return $e;
        }

        throw new \RuntimeException('unreachable');
    }
}

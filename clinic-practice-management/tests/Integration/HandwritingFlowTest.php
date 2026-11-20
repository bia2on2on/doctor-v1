<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Handwriting\HandwritingException;
use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\HandwritingRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های دست‌خط F7 — FR-9.1..9.3 + ADR-0009 + ADR-0014 + TP-12 (بخش Integration).
 *
 * پوشش:
 *  - F1 ایجاد سند + صفحه پیش‌فرض + Audit HW_DOC_CREATE + مالکیت (ماتریس 4.3)
 *  - F1b لیست سند ویزیت؛ F1c افزودن صفحه (page_index ترتیبی، page_count)
 *  - F2 پروتکل Revision: apply فقط C==R+1؛ C≤R یا C>R+1 → 409 CLINIC_CONFLICT
 *    با وضعیت سرور؛ مسیر Force = load-then-save با conflict_reason
 *  - Idempotency (Contract §0): رترای همان کلید = پاسخ ذخیره‌شده بدون version bump
 *  - stroke_data: gzip+base64 و JSON خام (سازگاری Safari) + اعتبارسنجی 422
 *  - F3 خواندن صفحه (Strokes decode)
 *  - GC (handwriting.gc): سیاست keep/max_age + زمان‌بندی RECURRING
 */
final class HandwritingFlowTest extends WP_UnitTestCase
{
    use \ClinicCore\Tests\Support\SignedExpirationFixture;

    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientId;
    private int $doctorUserId;
    private int $otherDoctorUserId;
    private int $secretaryUserId;
    private ?ClinicScope $previousScope = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        $this->previousScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic(1));

        // C6 repair — عضویت فعال staff صریح است (نه fixture سراسری).
        // تست‌های patient/non-member عمداً عضویت نمی‌گیرند.
        $this->secretaryUserId = $this->makeUser('hw_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('hw_doctor', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorUserId, 1, 'cpms_doctor');
        $this->otherDoctorUserId = $this->makeUser('hw_other', 'cpms_doctor');
        cpms_test_seed_membership($this->otherDoctorUserId, 1, 'cpms_doctor');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Hand',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-HW-' . $seq,
                'Hand',
                'P' . $seq,
                '0912' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        remove_all_filters('cpms_license_public_key');
        App::replaceExplicitScope($this->previousScope);
        $this->previousScope = null;
        parent::tearDown();
    }

    public function testExpiryBlocksPremiumWritesButPreservesExistingHandwritingAndClinicalCare(): void
    {
        [$doc, $pageId] = $this->makeDocWithPage();
        $this->installSignedExpiration(time() - 1);
        $service = App::handwritingService();
        $page = $service->getPage($this->doctorUserId, $pageId);
        $this->assertNotEmpty($page);
        $this->assertNotEmpty($service->listDocuments($this->doctorUserId, (int) $doc['visit_id']));
        // Owner rule (three-stage license): handwriting create/add/save for an
        // already-opened visit must succeed in Stage 2, Stage 3, and every license state.
        $secondDoc = $service->createDocument($this->doctorUserId, (int) $doc['visit_id'], null, []);
        $this->assertGreaterThan(0, (int) $secondDoc['id']);
        $addedPage = $service->addPage($this->doctorUserId, (int) $doc['id'], []);
        $this->assertSame(1, (int) $addedPage['page_index']);
        $saved = $service->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $saved['status']);
        $persisted = $service->getPage($this->doctorUserId, $pageId);
        $this->assertSame(1, (int) $persisted['client_revision']);
        $this->assertCount(1, $persisted['strokes']);
        // Existing consultation can still complete after all new-service windows.
        $this->installSignedExpiration(time() - 220 * 86400);
        $note = App::clinicalService()->addNote($this->doctorUserId, (int) $doc['visit_id'], [
            'category' => 'chief_complaint', 'visibility' => 'patient_visible', 'content_text' => 'Essential documentation after expiry',
        ]);
        $this->assertSame(1, $note['version']);
        $completed = App::clinicalService()->completeConsultation($this->doctorUserId, (int) $doc['visit_id']);
        $this->assertSame('consultation_completed', $completed['status']);
        $this->assertNotEmpty($service->getPage($this->doctorUserId, $pageId));
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    public function testOpenedVisitHandwritingPersistsAcrossValidStage2AndStage3Boundaries(): void
    {
        // 1. Valid license (Stage 1)
        $this->installSignedExpiration(time() + 86400);
        $visitValid = $this->makeConsultation();
        $svc = App::handwritingService();
        $docValid = $svc->createDocument($this->doctorUserId, $visitValid, 'Stage 1', []);
        $pageValid = $svc->addPage($this->doctorUserId, (int) $docValid['id'], []);
        $resValid = $svc->savePage($this->doctorUserId, (int) $pageValid['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $resValid['status']);
        $readValid = $svc->getPage($this->doctorUserId, (int) $pageValid['id']);
        $this->assertSame(1, (int) $readValid['client_revision']);
        $this->assertCount(1, $readValid['strokes']);

        // 2. Stage 2 (T0 <= now < T1)
        $visitStage2 = $this->makeConsultation();
        $this->installSignedExpiration(time() - 1);
        $docStage2 = $svc->createDocument($this->doctorUserId, $visitStage2, 'Stage 2', []);
        $pageStage2 = $svc->addPage($this->doctorUserId, (int) $docStage2['id'], []);
        $resStage2 = $svc->savePage($this->doctorUserId, (int) $pageStage2['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $resStage2['status']);
        $readStage2 = $svc->getPage($this->doctorUserId, (int) $pageStage2['id']);
        $this->assertSame(1, (int) $readStage2['client_revision']);
        $this->assertCount(1, $readStage2['strokes']);
        $this->assertNotNull($svc->listDocuments($this->doctorUserId, $visitStage2)['document']);

        // 3. Stage 3 exact half-open boundaries: T1 - 1 (Stage 2) and T1 (Stage 3)
        $t0 = strtotime('2026-01-31 12:00:00 UTC');
        $t1 = PostExpirationPolicy::deadline($t0, 1);
        $visitBoundary = $this->makeConsultation();
        App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $t1 - 1) . '.000'], ['id' => $visitBoundary]);
        $this->installSignedExpiration($t0);

        $beforeT1Service = $this->serviceAtClock($t1 - 1);
        $docBeforeT1 = $beforeT1Service->createDocument($this->doctorUserId, $visitBoundary, 'Before T1', []);
        $pageBeforeT1 = $beforeT1Service->addPage($this->doctorUserId, (int) $docBeforeT1['id'], []);
        $saveBeforeT1 = $beforeT1Service->savePage($this->doctorUserId, (int) $pageBeforeT1['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $saveBeforeT1['status']);
        $this->assertCount(1, $beforeT1Service->getPage($this->doctorUserId, (int) $pageBeforeT1['id'])['strokes']);

        $atT1Service = $this->serviceAtClock($t1);
        $docAtT1 = $atT1Service->createDocument($this->doctorUserId, $visitBoundary, 'At T1', []);
        $pageAtT1 = $atT1Service->addPage($this->doctorUserId, (int) $docAtT1['id'], []);
        $saveAtT1 = $atT1Service->savePage($this->doctorUserId, (int) $pageAtT1['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $saveAtT1['status']);
        $readAtT1 = $atT1Service->getPage($this->doctorUserId, (int) $pageAtT1['id']);
        $this->assertSame(1, (int) $readAtT1['client_revision']);
        $this->assertCount(1, $readAtT1['strokes']);

        // 4. REST round-trip in wall-clock Stage 3
        $this->installSignedExpiration(time() + 86400);
        wp_set_current_user($this->doctorUserId);
        $restVisit = $this->makeConsultation();
        $stage3T0 = time() - 40 * 86400;
        $stage3T1 = PostExpirationPolicy::deadline($stage3T0, 1);
        App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $stage3T1 - 60) . '.000'], ['id' => $restVisit]);
        $this->installSignedExpiration($stage3T0);

        $restDoc = $this->dispatch('POST', self::NS . '/handwriting/documents', ['visit_id' => $restVisit, 'title' => 'REST Stage 3']);
        $this->assertSame(201, $restDoc->get_status());
        $restPageId = (int) $restDoc->get_data()['data']['pages'][0]['id'];
        $restSave = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $restPageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(200, $restSave->get_status());
        $restRead = $this->dispatch('GET', self::NS . '/handwriting/pages/' . $restPageId);
        $this->assertSame(200, $restRead->get_status());
        $this->assertSame(1, (int) $restRead->get_data()['data']['client_revision']);
        $this->assertCount(1, $restRead->get_data()['data']['strokes']);
    }

    public function testOpenedVisitHandwritingPersistsInNoLicensePlaceholderAndAdverseStates(): void
    {
        global $wpdb;
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();

        // NOT_CONFIGURED (no install/state rows)
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_license_state'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_license_install'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, App::licenseService()->currentState()['status']);
        $docNc = $svc->createDocument($this->doctorUserId, $visitId, 'Not Configured', []);
        $saveNc = $svc->savePage($this->doctorUserId, (int) $docNc['pages'][0]['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
        ], null);
        $this->assertSame(200, $saveNc['status']);
        $this->assertCount(1, $svc->getPage($this->doctorUserId, (int) $docNc['pages'][0]['id'])['strokes']);

        // ACTIVATION_PENDING (fresh 7-day window without license document)
        $nowSql = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_license_install', [
            'install_id' => bin2hex(random_bytes(16)),
            'environment' => 'production',
            'activation_window_started_at' => $nowSql,
            'activation_window_type' => 'fresh',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, App::licenseService()->currentState()['status']);
        $docPending = $svc->createDocument($this->doctorUserId, $visitId, 'Activation Pending', []);
        $pagePending = $svc->addPage($this->doctorUserId, (int) $docPending['id'], []);
        $this->assertSame(200, $svc->savePage($this->doctorUserId, (int) $pagePending['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
        ], null)['status']);
        $this->assertCount(1, $svc->getPage($this->doctorUserId, (int) $pagePending['id'])['strokes']);

        // RESTRICTED via activation_window_expired (no license document ever activated):
        // docs/architecture/license-activation-design.md:318 permits safe-finish only for a
        // Visit already open at/before the window cutoff; $visitId was created after the cutoff,
        // so creating/adding/saving handwriting on it must be denied once the window expires.
        App::db()->update('cpms_license_install', [
            'activation_window_started_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400) . '.000',
        ], ['activation_window_type' => 'fresh']);
        $winExpired = App::licenseService()->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $winExpired['status']);
        $this->assertSame('activation_window_expired', $winExpired['reason']);
        $docsBeforeExp = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_documents'));
        try {
            $svc->createDocument($this->doctorUserId, $visitId, 'Window Expired', []);
            $this->fail('post-cutoff visit must be denied once activation_window_expired takes effect');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        try {
            $svc->addPage($this->doctorUserId, (int) $docPending['id'], []);
            $this->fail('post-cutoff addPage must be denied once activation_window_expired takes effect');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        try {
            $svc->savePage($this->doctorUserId, (int) $pagePending['id'], [
                'client_revision' => 2,
                'stroke_data' => $this->strokeData(),
            ], null);
            $this->fail('post-cutoff savePage must be denied once activation_window_expired takes effect');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertSame($docsBeforeExp, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_documents')));

        // REVOKED signed document
        $this->installSignedDocumentWith(['revoked' => true, 'reason' => 'revoked']);
        $this->assertSame(LicenseStatus::REVOKED, App::licenseService()->currentState()['status']);
        $docRevoked = $svc->createDocument($this->doctorUserId, $visitId, 'Revoked', []);
        $this->assertSame(200, $svc->savePage($this->doctorUserId, (int) $docRevoked['pages'][0]['id'], [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
        ], null)['status']);
        $this->assertCount(1, $svc->getPage($this->doctorUserId, (int) $docRevoked['pages'][0]['id'])['strokes']);
    }

    public function testHandwritingDenialsRemainEnforcedUnderStage2AndStage3(): void
    {
        global $wpdb;
        $openVisitId = $this->makeConsultation();
        $stage3T0 = time() - 40 * 86400;
        $stage3T1 = PostExpirationPolicy::deadline($stage3T0, 1);
        App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $stage3T1 - 60) . '.000'], ['id' => $openVisitId]);

        $svc = App::handwritingService();
        $doc = $svc->createDocument($this->doctorUserId, $openVisitId, 'Pre-Stage3', []);
        $pageId = (int) $doc['pages'][0]['id'];

        $this->installSignedExpiration($stage3T0);

        // 1. Unopened (non-existent) visit
        try {
            $svc->createDocument($this->doctorUserId, 9999999, null, []);
            $this->fail('expected 404 for unopened/non-existent visit');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        // 2. Cross-clinic visit (visit belongs to clinic 2, actor scoped to clinic 1)
        $orgId = (int) App::db()->fetchValue('SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = 1');
        $nowSql = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_clinics', [
            'organization_id' => $orgId,
            'name' => 'Clinic B HW',
            'slug' => 'clinic-b-hw-' . bin2hex(random_bytes(3)),
            'timezone' => 'Asia/Tehran',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $clinicBId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'cpms_locations', [
            'clinic_id' => $clinicBId,
            'name' => 'Clinic B Loc HW',
            'slug' => 'clinic-b-loc-hw-' . bin2hex(random_bytes(3)),
            'timezone' => 'Asia/Tehran',
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $clinicBLocId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicBId,
            'location_id' => $clinicBLocId,
            'clinician_id' => $this->clinicianId,
            'patient_id' => $this->patientId,
            'source' => 'walk_in',
            'status' => 'in_consultation',
            'visit_date' => gmdate('Y-m-d'),
            'check_in_at' => $nowSql,
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', $stage3T1 - 60) . '.000',
            'updated_at' => $nowSql,
        ]);
        $foreignVisitId = (int) $wpdb->insert_id;
        try {
            $svc->createDocument($this->doctorUserId, $foreignVisitId, null, []);
            $this->fail('expected 404 for cross-clinic visit');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
        $this->assertAudit('FORBIDDEN_ACCESS_ATTEMPT', 'handwriting', 0);

        // 3. Missing capability (secretary) & non-owner doctor
        try {
            $svc->createDocument($this->secretaryUserId, $openVisitId, null, []);
            $this->fail('expected 403 for secretary');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
        try {
            $svc->createDocument($this->otherDoctorUserId, $openVisitId, null, []);
            $this->fail('expected 404 for non-owner doctor');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        // 4. New visit creation under Stage 3 stays denied
        $seq = random_int(1000, 999999);
        $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => 1,
            'mrn' => 'MR-HWNV-' . $seq,
            'first_name' => 'New',
            'last_name' => 'Visit',
            'mobile' => '0914' . sprintf('%07d', $seq),
            'status' => 'active',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $freshPatientId = (int) $wpdb->insert_id;
        $visitsBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits'));
        try {
            App::visitService()->walkIn($this->secretaryUserId, $freshPatientId, $this->clinicianId);
            $this->fail('new visit creation under Stage 3 must stay denied');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertSame($visitsBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits')));

        // 5. Visit created at/after T1 is denied in Stage 3
        $postT1VisitId = (new VisitRepository(App::db()))->insert(1, [
            'patient_id' => $freshPatientId,
            'clinician_id' => $this->clinicianId,
            'visit_date' => gmdate('Y-m-d'),
            'check_in_at' => $nowSql,
            'status' => 'in_consultation',
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', $stage3T1) . '.000',
        ]);
        try {
            $svc->createDocument($this->doctorUserId, $postT1VisitId, null, []);
            $this->fail('post-T1 visit must be denied in Stage 3');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }

        // 6. Closed/terminal visit (checked_out) is denied in Stage 3 and Stage 2
        App::visitService()->transition($this->doctorUserId, $openVisitId, 'complete');
        App::visitService()->transition($this->secretaryUserId, $openVisitId, 'invoice_ready');
        App::visitService()->transition($this->doctorUserId, $openVisitId, 'waive');

        foreach ([$stage3T0, time() - 1] as $expiryTs) {
            $this->installSignedExpiration($expiryTs);
            $docsBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_documents'));
            $pagesBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_pages'));
            $versionsBefore = $this->versionCount($pageId);

            foreach (['document', 'page', 'save'] as $operation) {
                try {
                    if ($operation === 'document') {
                        $svc->createDocument($this->doctorUserId, $openVisitId, null, []);
                    } elseif ($operation === 'page') {
                        $svc->addPage($this->doctorUserId, (int) $doc['id'], []);
                    } else {
                        $svc->savePage($this->doctorUserId, $pageId, [
                            'client_revision' => 1,
                            'stroke_data' => $this->strokeData(),
                        ], null);
                    }
                    $this->fail("closed visit must be denied {$operation}");
                } catch (HandwritingException $e) {
                    $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
                }
            }
            $this->assertSame($docsBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_documents')));
            $this->assertSame($pagesBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_pages')));
            $this->assertSame($versionsBefore, $this->versionCount($pageId));
        }
    }

    public function testActivationWindowRestrictedAllowsPreCutoffVisitAndDeniesPostCutoffVisit(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_license_state'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_license_install'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now) . '.000';
        $wpdb->insert($wpdb->prefix . 'cpms_license_install', [
            'install_id' => bin2hex(random_bytes(16)),
            'environment' => 'production',
            'activation_window_started_at' => $nowSql,
            'activation_window_type' => 'fresh',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);

        // Create both persisted visits and an initial doc/page on each while the window is pending.
        $atCutoffVisitId = $this->makeConsultation();
        $afterCutoffVisitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $atCutoffDoc = $svc->createDocument($this->doctorUserId, $atCutoffVisitId, 'At Window Cutoff', []);
        $atCutoffPageId = (int) $atCutoffDoc['pages'][0]['id'];
        $afterCutoffDoc = $svc->createDocument($this->doctorUserId, $afterCutoffVisitId, 'After Window Cutoff', []);
        $afterCutoffPageId = (int) $afterCutoffDoc['pages'][0]['id'];

        foreach ([
            ['fresh', 7, 'activation_window_expired', 10],
            ['migration', 30, 'migration_grace_expired', 35],
        ] as [$windowType, $windowDays, $expectedReason, $startedDaysAgo]) {
            $startedAt = $now - $startedDaysAgo * 86400;
            $windowEnd = $startedAt + $windowDays * 86400;
            App::db()->update('cpms_license_install', [
                'activation_window_type' => $windowType,
                'activation_window_started_at' => gmdate('Y-m-d H:i:s', $startedAt) . '.000',
            ], ['id' => (int) App::db()->fetchValue('SELECT id FROM ' . $wpdb->prefix . 'cpms_license_install LIMIT 1')]);

            $state = App::licenseService()->currentState();
            $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
            $this->assertSame($expectedReason, $state['reason']);

            App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $windowEnd) . '.000'], ['id' => $atCutoffVisitId]);
            App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $windowEnd + 1) . '.000'], ['id' => $afterCutoffVisitId]);

            // At the last allowed instant of the window (design §7.4 :318): create/add/save succeed.
            $docAllowed = $svc->createDocument($this->doctorUserId, $atCutoffVisitId, 'Allowed ' . $windowType, []);
            $pageAllowed = $svc->addPage($this->doctorUserId, (int) $docAllowed['id'], []);
            $saveAllowed = $svc->savePage($this->doctorUserId, (int) $pageAllowed['id'], [
                'client_revision' => 1,
                'stroke_data' => $this->strokeData(),
                'saved_by' => 'manual',
            ], null);
            $this->assertSame(200, $saveAllowed['status']);
            $this->assertCount(1, $svc->getPage($this->doctorUserId, (int) $pageAllowed['id'])['strokes']);
            $this->assertGreaterThan(0, $atCutoffPageId);

            // One second after the window cutoff: create/add/save are denied and write nothing.
            $docsBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_documents');
            $pagesBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_pages');
            $versionsBefore = $this->versionCount($afterCutoffPageId);

            foreach (['document', 'page', 'save'] as $operation) {
                try {
                    if ($operation === 'document') {
                        $svc->createDocument($this->doctorUserId, $afterCutoffVisitId, null, []);
                    } elseif ($operation === 'page') {
                        $svc->addPage($this->doctorUserId, (int) $afterCutoffDoc['id'], []);
                    } else {
                        $svc->savePage($this->doctorUserId, $afterCutoffPageId, [
                            'client_revision' => 1,
                            'stroke_data' => $this->strokeData(),
                        ], null);
                    }
                    $this->fail("{$expectedReason}: post-cutoff {$operation} must be denied");
                } catch (HandwritingException $e) {
                    $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
                }
            }
            $this->assertSame($docsBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_documents'));
            $this->assertSame($pagesBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_pages'));
            $this->assertSame($versionsBefore, $this->versionCount($afterCutoffPageId));
        }
    }

    public function testPersistedNonOpenedAndCompletedVisitsAreDeniedAcrossValidStage2AndStage3(): void
    {
        global $wpdb;
        $now = time();
        $this->installSignedExpiration($now + 86400);

        $svc = App::handwritingService();

        // 1. Persisted non-opened visit: skipped (terminal, active = 0).
        $skippedSeq = random_int(1000, 999999);
        $nowSql = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => 1,
            'mrn' => 'MR-HWSK-' . $skippedSeq,
            'first_name' => 'Skip',
            'last_name' => 'Visit',
            'mobile' => '0915' . sprintf('%07d', $skippedSeq),
            'status' => 'active',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $skippedVisit = App::visitService()->walkIn($this->secretaryUserId, (int) $wpdb->insert_id, $this->clinicianId);
        $skippedVisitId = (int) $skippedVisit['id'];
        $skippedDoc = $svc->createDocument($this->doctorUserId, $skippedVisitId, 'Pre-skip', []);
        $skippedPageId = (int) $skippedDoc['pages'][0]['id'];
        App::visitService()->transition($this->doctorUserId, $skippedVisitId, 'skip', ['reason' => 'patient absent']);

        // 2. Persisted non-opened visit: cancelled (terminal, active = 0).
        $cancelledSeq = random_int(1000, 999999);
        $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => 1,
            'mrn' => 'MR-HWCN-' . $cancelledSeq,
            'first_name' => 'Cancel',
            'last_name' => 'Visit',
            'mobile' => '0916' . sprintf('%07d', $cancelledSeq),
            'status' => 'active',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $cancelledVisit = App::visitService()->walkIn($this->secretaryUserId, (int) $wpdb->insert_id, $this->clinicianId);
        $cancelledVisitId = (int) $cancelledVisit['id'];
        $cancelledDoc = $svc->createDocument($this->doctorUserId, $cancelledVisitId, 'Pre-cancel', []);
        $cancelledPageId = (int) $cancelledDoc['pages'][0]['id'];
        App::visitService()->transition($this->secretaryUserId, $cancelledVisitId, 'cancel', ['reason' => 'cancelled']);

        // 3. Persisted non-opened visit: non-terminal status but active = 0.
        $inactiveVisitId = $this->makeConsultation();
        $inactiveDoc = $svc->createDocument($this->doctorUserId, $inactiveVisitId, 'Pre-inactive', []);
        $inactivePageId = (int) $inactiveDoc['pages'][0]['id'];
        App::db()->update('cpms_visits', ['active' => 0], ['id' => $inactiveVisitId]);

        // 4. Persisted completed visit: checked_out (active = 0).
        $completedVisitId = $this->makeConsultation();
        $completedDoc = $svc->createDocument($this->doctorUserId, $completedVisitId, 'Pre-complete', []);
        $completedPageId = (int) $completedDoc['pages'][0]['id'];
        App::visitService()->transition($this->doctorUserId, $completedVisitId, 'complete');
        App::visitService()->transition($this->secretaryUserId, $completedVisitId, 'invoice_ready');
        App::visitService()->transition($this->doctorUserId, $completedVisitId, 'waive');

        $stage3T0 = $now - 40 * 86400;
        $stage3T1 = PostExpirationPolicy::deadline($stage3T0, 1);
        foreach ([$skippedVisitId, $cancelledVisitId, $inactiveVisitId, $completedVisitId] as $vid) {
            App::db()->update('cpms_visits', ['created_at' => gmdate('Y-m-d H:i:s', $stage3T1 - 60) . '.000'], ['id' => $vid]);
        }

        $cases = [
            ['skipped', $skippedVisitId, (int) $skippedDoc['id'], $skippedPageId],
            ['cancelled', $cancelledVisitId, (int) $cancelledDoc['id'], $cancelledPageId],
            ['inactive', $inactiveVisitId, (int) $inactiveDoc['id'], $inactivePageId],
            ['checked_out', $completedVisitId, (int) $completedDoc['id'], $completedPageId],
        ];
        $stages = [
            ['valid', $now + 86400, 'CLINIC_INVALID_TRANSITION', 409],
            ['stage2', $now - 1, 'CLINIC_LICENSE_BLOCKED', 503],
            ['stage3', $stage3T0, 'CLINIC_LICENSE_BLOCKED', 503],
        ];

        foreach ($stages as [$stageLabel, $expiresAt, $expectedCode, $expectedHttp]) {
            $this->installSignedExpiration($expiresAt);
            $clockSvc = $this->serviceAtClock($now);

            foreach ($cases as [$visitLabel, $visitId, $docId, $pageId]) {
                $docsBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_documents');
                $pagesBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_pages');
                $versionsBefore = $this->versionCount($pageId);

                foreach (['document', 'page', 'save'] as $op) {
                    try {
                        if ($op === 'document') {
                            $clockSvc->createDocument($this->doctorUserId, $visitId, null, []);
                        } elseif ($op === 'page') {
                            $clockSvc->addPage($this->doctorUserId, $docId, []);
                        } else {
                            $clockSvc->savePage($this->doctorUserId, $pageId, [
                                'client_revision' => 1,
                                'stroke_data' => $this->strokeData(),
                            ], null);
                        }
                        $this->fail("{$stageLabel}/{$visitLabel}/{$op} must be denied");
                    } catch (HandwritingException $e) {
                        $this->assertSame($expectedCode, $e->errorCode, "{$stageLabel}/{$visitLabel}/{$op}");
                        $this->assertSame($expectedHttp, $e->httpStatus, "{$stageLabel}/{$visitLabel}/{$op}");
                    }
                }

                $this->assertSame($docsBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_documents'));
                $this->assertSame($pagesBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_handwriting_pages'));
                $this->assertSame($versionsBefore, $this->versionCount($pageId));
            }
        }
    }

    public function testSavePageRechecksLockedRevisionAndRollsBackOnVersionInsertFailure(): void
    {
        global $wpdb;
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $doc = $svc->createDocument($this->doctorUserId, $visitId, 'Concurrency Guard', []);
        $pageId = (int) $doc['pages'][0]['id'];

        $competingStrokes = $this->strokeData();
        $staleStrokes = base64_encode(gzencode(json_encode([[
            'id' => 's-stale',
            'tool' => 'pen',
            'color' => '#ff0000',
            'size' => 6,
            'points' => [[5, 5, 0.4, 1690000100]],
        ]], JSON_UNESCAPED_UNICODE) ?: '[]') ?: '[]');

        // Part 1: moved-target race — inject a revision update when the page lock runs.
        // Accepted LIMITATION: WP_UnitTestCase shares a single DB connection, so we simulate
        // a concurrent commit via a counted exact-SQL query filter rather than two connections.
        $exactLockSql = 'SELECT * FROM ' . $wpdb->prefix . 'cpms_handwriting_pages WHERE id = ' . $pageId . ' LIMIT 1 FOR UPDATE';
        $hits = 0;
        $injectedUpdatedRows = null;
        $denialsAtInjection = null;
        $injector = static function (string $sql) use ($exactLockSql, &$hits, &$injectedUpdatedRows, &$denialsAtInjection, $wpdb, $competingStrokes, $pageId): string {
            if ($sql === $exactLockSql) {
                $hits++;
                if ($hits === 1) {
                    $denialsAtInjection = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d AND after_json IS NULL',
                            'HW_PAGE_SAVE',
                            'handwriting_page',
                            $pageId
                        )
                    );
                    $injectedUpdatedRows = $wpdb->query(
                        $wpdb->prepare(
                            'UPDATE ' . $wpdb->prefix . 'cpms_handwriting_pages SET client_revision = 1, version = 2, stroke_count = 1, stroke_data = %s WHERE id = %d',
                            $competingStrokes,
                            $pageId
                        )
                    );
                }
            }

            return $sql;
        };
        add_filter('query', $injector);
        $conflictCaught = null;
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1,
                'stroke_data' => $staleStrokes,
                'saved_by' => 'manual',
            ], null);
            $this->fail('expected CLINIC_CONFLICT when revision moved before lock');
        } catch (HandwritingException $e) {
            $conflictCaught = $e;
        } finally {
            remove_filter('query', $injector);
        }

        $this->assertSame(1, $hits, 'exact page-lock SQL must run exactly once');
        $this->assertSame(0, $denialsAtInjection, 'no conflict audit exists before lock injection');
        $this->assertSame(1, $injectedUpdatedRows, 'injected UPDATE must match the target page row');
        $this->assertInstanceOf(HandwritingException::class, $conflictCaught);
        $this->assertSame('CLINIC_CONFLICT', $conflictCaught->errorCode);
        $this->assertSame(409, $conflictCaught->httpStatus);
        $this->assertSame(1, (int) ($conflictCaught->data['server']['client_revision'] ?? -1));
        $this->assertSame(2, (int) ($conflictCaught->data['server']['version'] ?? -1));

        $afterConflict = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(0, (int) $afterConflict['client_revision']);
        $this->assertSame(1, (int) $afterConflict['version']);
        $this->assertSame([], $afterConflict['strokes'], 'stale save must not overwrite DB strokes');
        $this->assertSame(0, $this->versionCount($pageId));

        $this->assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d AND after_json IS NOT NULL',
                ['HW_PAGE_SAVE', 'handwriting_page', $pageId]
            ),
            'moved-target conflict must write zero success audits'
        );
        $denialAudit = App::db()->fetchRow(
            'SELECT before_json, after_json, meta_json FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d AND after_json IS NULL ORDER BY id DESC LIMIT 1',
            ['HW_PAGE_SAVE', 'handwriting_page', $pageId]
        );
        $this->assertNotNull($denialAudit, 'moved-target conflict must write denial audit after rollback');
        $this->assertNull($denialAudit['after_json']);
        $beforeJson = json_decode((string) ($denialAudit['before_json'] ?? '{}'), true);
        $metaJson = json_decode((string) ($denialAudit['meta_json'] ?? '{}'), true);
        $this->assertSame(1, (int) ($beforeJson['client_revision'] ?? -1));
        $this->assertSame(2, (int) ($beforeJson['version'] ?? -1));
        $this->assertTrue((bool) ($metaJson['conflict'] ?? false));
        $this->assertSame(1, (int) ($metaJson['client_revision'] ?? -1));
        $this->assertSame(1, (int) ($metaJson['server_revision'] ?? -1));
        $this->assertSame('revision_mismatch', (string) ($metaJson['reason'] ?? ''));

        // Part 2: duplicate/failed version insert in soft DB mode must roll back the page update.
        $this->assertFalse(App::db()->is_strict(), 'runtime default uses soft DB mode');
        $wpdb->insert($wpdb->prefix . 'cpms_handwriting_page_versions', [
            'page_id' => $pageId,
            'version' => 2,
            'stroke_data' => $competingStrokes,
            'saved_by' => 'manual',
            'created_at' => App::db()->nowUtcSql(),
        ]);
        $this->assertSame(1, $this->versionCount($pageId));

        $versionFailure = null;
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1,
                'stroke_data' => $staleStrokes,
                'saved_by' => 'manual',
            ], null);
            $this->fail('expected RuntimeException when version insert fails');
        } catch (\RuntimeException $e) {
            $versionFailure = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $versionFailure);

        $afterRollback = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(0, (int) $afterRollback['client_revision'], 'page revision must roll back');
        $this->assertSame(1, (int) $afterRollback['version'], 'page version must roll back');
        $this->assertSame(0, (int) $afterRollback['stroke_count'], 'page stroke_count must roll back');
        $this->assertSame([], $afterRollback['strokes'], 'page strokes must roll back');
        $this->assertSame(1, $this->versionCount($pageId), 'only pre-seeded version row remains');
        $this->assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d AND after_json IS NOT NULL',
                ['HW_PAGE_SAVE', 'handwriting_page', $pageId]
            )
        );
        $this->assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d AND after_json IS NULL',
                ['HW_PAGE_SAVE', 'handwriting_page', $pageId]
            )
        );
    }

    public function testSavePageRollsBackWhenPageUpdateFailsAndAllowsZeroAffectedRows(): void
    {
        global $wpdb;
        $this->assertFalse(App::db()->is_strict(), 'runtime default uses soft DB mode');
        wp_set_current_user($this->doctorUserId);
        [, $pageId] = $this->makeDocWithPage();

        // 0 affected rows is NOT a SQL failure.
        (new HandwritingRepository(App::db()))->update_page_or_fail(999999999, ['stroke_count' => 0]);

        $pagesTable = $wpdb->prefix . 'cpms_handwriting_pages';
        $pattern = '/\A\s*UPDATE\s+(`?' . preg_quote($pagesTable, '/') . '`?)\s+SET\b.*\bWHERE\s+`?id`?\s*=\s*\'?' . $pageId . '\'?\s*\z/is';
        $updateHits = 0;
        $matchedSql = '';
        $prevSuppress = (bool) $wpdb->suppress_errors(true);
        $fault = static function ($sql) use ($pattern, &$updateHits, &$matchedSql, $pageId) {
            $query = (string) $sql;
            if (preg_match($pattern, $query, $m) === 1) {
                $updateHits++;
                $matchedSql = $query;

                return 'UPDATE ' . $m[1] . ' SET `cpms_fault_injection_no_such_column` = 1 WHERE id = ' . $pageId;
            }

            return $sql;
        };
        add_filter('query', $fault, 5);
        try {
            $res = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
                'client_revision' => 1,
                'stroke_data' => $this->strokeData(),
                'saved_by' => 'manual',
            ], ['Idempotency-Key' => $this->uuid()]);
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($prevSuppress);
        }

        $this->assertSame(1, $updateHits, 'exact page UPDATE SQL must match once');
        $this->assertNotSame('', $matchedSql);
        $this->assertSame(500, $res->get_status(), 'failed page UPDATE must return an error response');
        $this->assertSame('CLINIC_INTERNAL_ERROR', $this->errorCode($res));

        $after = App::handwritingService()->getPage($this->doctorUserId, $pageId);
        $this->assertSame(0, (int) $after['client_revision']);
        $this->assertSame(1, (int) $after['version']);
        $this->assertSame(0, (int) $after['stroke_count']);
        $this->assertSame([], $after['strokes'], 'strokes must remain unchanged after failed UPDATE');
        $this->assertSame(0, $this->versionCount($pageId), 'failed page UPDATE must roll back version insert');
        $this->assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
                ['HW_PAGE_SAVE', 'handwriting_page', $pageId]
            ),
            'failed page UPDATE must write zero HW_PAGE_SAVE audits'
        );
    }

    // ================= F1 — ایجاد سند =================

    public function testCreateDocumentMakesDefaultPageAndAudits(): void
    {
        $visitId = $this->makeConsultation();
        $visit = $this->visitRow($visitId);

        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visitId, 'نسخه دست‌خط', []);

        $this->assertSame($visitId, (int) $doc['visit_id']);
        $this->assertSame((int) $visit['patient_id'], (int) $doc['patient_id']);
        $this->assertSame($this->clinicianId, (int) $doc['clinician_id']);
        $this->assertSame(1, (int) $doc['page_count']);
        $this->assertCount(1, $doc['pages']);
        $this->assertSame(1240, (int) $doc['pages'][0]['width']);
        $this->assertSame(1754, (int) $doc['pages'][0]['height']);
        $this->assertSame('lined', (string) $doc['pages'][0]['background_template']);

        $this->assertAudit('HW_DOC_CREATE', 'handwriting_document', (int) $doc['id']);
    }

    public function testCreateDocumentRejectsDoctorWithoutVisitOwnership(): void
    {
        $visitId = $this->makeConsultation();

        try {
            App::handwritingService()->createDocument($this->otherDoctorUserId, $visitId, null, []);
            $this->fail('expected 404 for non-owner doctor');
        } catch (HandwritingException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
        $this->assertAudit('FORBIDDEN_ACCESS_ATTEMPT', 'handwriting', $visitId);
    }

    public function testSecretaryCannotCreateDocument(): void
    {
        $visitId = $this->makeConsultation();

        try {
            App::handwritingService()->createDocument($this->secretaryUserId, $visitId, null, []);
            $this->fail('expected 403 for secretary');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }
    }

    // ================= F1b / F1c — لیست و افزودن صفحه =================

    public function testListDocumentsReturnsLatestForVisit(): void
    {
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $svc->createDocument($this->doctorUserId, $visitId, null, []);

        $list = $svc->listDocuments($this->doctorUserId, $visitId);
        $this->assertNotNull($list['document']);
        $this->assertCount(1, $list['document']['pages']);

        $empty = $svc->listDocuments($this->doctorUserId, $this->makeConsultation());
        $this->assertNull($empty['document']);
    }

    public function testAddPageAppendsSequentially(): void
    {
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $doc = $svc->createDocument($this->doctorUserId, $visitId, null, []);

        $page2 = $svc->addPage($this->doctorUserId, (int) $doc['id'], ['background_template' => 'graph']);

        $this->assertSame(1, (int) $page2['page_index']);
        $this->assertSame('graph', (string) $page2['background_template']);
        $this->assertSame(2, (int) $svc->listDocuments($this->doctorUserId, $visitId)['document']['page_count']);
        $this->assertAudit('HW_PAGE_ADD', 'handwriting_page', (int) $page2['id']);
    }

    // ================= F2 — پروتکل Revision (ADR-0014) =================

    public function testSaveAppliesOnlyWhenClientRevisionIsServerPlusOne(): void
    {
        [$doc, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // C = R+1 = 1 → apply
        $r1 = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'width' => 1240, 'height' => 1754,
            'saved_by' => 'manual',
        ], $this->uuid());
        $this->assertSame(200, $r1['status']);
        $this->assertSame(1, $r1['response']['client_revision']);
        $this->assertSame(2, $r1['response']['version']);
        $this->assertSame(1, $r1['response']['stroke_count']);

        // C = 1 دوباره (کمتر از R+1=2) → 409 بدون bump
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1,
                'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_CONFLICT', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame(1, $e->data['server']['client_revision']);
            $this->assertIsArray($e->data['server']['strokes']);
            $this->assertSame(2, $e->data['server']['version']);
        }

        // C = R+2 → 409
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 3,
                'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_CONFLICT', $e->errorCode);
        }

        // version/revision سرور دست‌نخورده
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(1, $page['client_revision']);
        $this->assertSame(2, $page['version']);
        $this->assertSame(1, $this->versionCount($pageId));

        $this->assertAudit('HW_PAGE_SAVE', 'handwriting_page', $pageId);
    }

    public function testForcePathAfterConflictAppliesWithConflictReason(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $this->uuid());

        // تضاد → load سرور (R=1) → بازنویسی با C=2 و conflict_reason
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1, 'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException) {
            // سرور را «load» کردیم (R=1) — شبیه‌سازی کلاینت
        }

        $forced = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 2,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
            'conflict_reason' => 'overwrite_after_conflict',
        ], $this->uuid());
        $this->assertSame(200, $forced['status']);
        $this->assertSame(2, $forced['response']['client_revision']);

        global $wpdb;
        $meta = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_json FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            'HW_PAGE_SAVE',
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertStringContainsString('overwrite_after_conflict', (string) $meta);
    }

    public function testIdempotentReplayReturnsStoredResponseWithoutVersionBump(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();
        $key = $this->uuid();

        $first = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $key);
        $replay = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $key);

        $this->assertSame(200, $replay['status']);
        // JSON objectها ذاتاً بدون‌ترتیب‌اند (RFC 8259 §4) — مقایسه Value-wise با ksort
        $expected = $first['response'];
        $actual = $replay['response'];
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        // فقط یک نسخه — bump دوباره نداشتیم
        $this->assertSame(1, $this->versionCount($pageId));
    }

    // ================= stroke_data — سازگاری و اعتبارسنجی =================

    public function testRawJsonBase64AcceptedAndStoredGzipped(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // Safari قدیمی: base64(JSON خام) بدون gzip — سرور فشرده و ذخیره می‌کند
        $r = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1,
            'stroke_data' => base64_encode(json_encode([$this->stroke()]) ?: '[]'),
        ], $this->uuid());
        $this->assertSame(200, $r['status']);

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT stroke_data FROM ' . $wpdb->prefix . 'cpms_handwriting_pages WHERE id = %d',
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $decoded = base64_decode($stored, true);
        $this->assertNotFalse($decoded);
        $this->assertSame("\x1f\x8b", substr($decoded, 0, 2), 'stored payload must be gzip');

        // F3 — خواندن: Strokes decode شده برمی‌گردد
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertCount(1, $page['strokes']);
        $this->assertSame('pen', $page['strokes'][0]['tool']);
        $this->assertSame(2, $page['version']);
    }

    public function testInvalidStrokePayloadRejected(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        $cases = [
            '!!!not-base64!!!',
            base64_encode('{"not":"an array"}'),
            base64_encode('[{"no_points":true}]'),
            base64_encode('[{"points":[["x","y"]]}]'),
        ];
        foreach ($cases as $payload) {
            try {
                $svc->savePage($this->doctorUserId, $pageId, [
                    'client_revision' => 1, 'stroke_data' => $payload,
                ], $this->uuid());
                $this->fail('expected validation error for ' . $payload);
            } catch (HandwritingException $e) {
                $this->assertSame('CLINIC_VALIDATION', $e->errorCode);
                $this->assertSame(422, $e->httpStatus);
            }
        }

        // ابعاد نامعتبر
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1, 'stroke_data' => $this->strokeData(), 'width' => 10,
            ], $this->uuid());
            $this->fail('expected width validation');
        } catch (HandwritingException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    // ================= REST — لایه Contract =================

    public function testRestPutRequiresIdempotencyKeyHeader(): void
    {
        wp_set_current_user($this->doctorUserId);
        [, $pageId] = $this->makeDocWithPage();

        $res = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('CLINIC_VALIDATION', $this->errorCode($res));
    }

    public function testRestSaveRoundTripAndConflictEnvelope(): void
    {
        wp_set_current_user($this->doctorUserId);
        [, $pageId] = $this->makeDocWithPage();

        $ok = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'width' => 1240, 'height' => 1754,
        ], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(200, $ok->get_status());
        $this->assertSame(1, $ok->get_data()['data']['client_revision']);

        // Save دیگر با C کهنه → Envelope خطای 409 (ADR-0019)
        $conflict = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(409, $conflict->get_status());
        $this->assertSame('CLINIC_CONFLICT', $this->errorCode($conflict));
        $this->assertArrayHasKey('server', $conflict->get_data()['data']);
    }

    public function testRestGetPageAndList(): void
    {
        wp_set_current_user($this->doctorUserId);
        [$doc, $pageId] = $this->makeDocWithPage();

        $page = $this->dispatch('GET', self::NS . '/handwriting/pages/' . $pageId);
        $this->assertSame(200, $page->get_status());
        $this->assertSame(1240, $page->get_data()['data']['width']);

        $list = $this->dispatch('GET', self::NS . '/handwriting/documents', ['visit_id' => (int) $doc['visit_id']]);
        $this->assertSame(200, $list->get_status());
        $this->assertNotNull($list->get_data()['data']['document']);

        // منشی: بدون cpms_medical_read → 403
        wp_set_current_user($this->secretaryUserId);
        $forbidden = $this->dispatch('GET', self::NS . '/handwriting/pages/' . $pageId);
        $this->assertSame(403, $forbidden->get_status());
    }

    // ================= GC — سیاست نگهداری (handwriting.gc) =================

    public function testGcPurgesOldVersionsBeyondKeep(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // 12 Save → نسخه‌های 2..13
        for ($c = 1; $c <= 12; $c++) {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => $c, 'stroke_data' => $this->strokeData(),
            ], $this->uuid());
        }
        $this->assertSame(12, $this->versionCount($pageId));

        // نسخه‌های 2..6 را 40 روز گذشته می‌کنیم (قدیمی‌تر از hw.version_max_age_days=30)
        global $wpdb;
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400) . '.000';
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_handwriting_page_versions SET created_at = %s WHERE page_id = %d AND version BETWEEN 2 AND 6',
            $old,
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $deleted = $svc->purgeVersions();

        // keep=10: maxVersion=13 → حذف version≤3 و قدیمی → نسخه‌های 2 و 3 (نسخه 4..6 قدیمی اما در ۱۰ نسخه آخر)
        $this->assertSame(2, $deleted);
        $this->assertSame(10, $this->versionCount($pageId));
        // نسخه‌های تازه هرگز حذف نمی‌شوند
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(13, $page['version']);
    }

    public function testHandwritingGcIsScheduledRecurringJob(): void
    {
        App::scheduleRecurringJobs();

        $queued = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
            ['handwriting.gc', \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
        );
        $this->assertSame(1, $queued);

        // اجرا از مسیر Dispatcher واقعی (handler ثبت‌شده در App::dispatcher)
        $processed = App::dispatcher()->tick(20);
        $this->assertGreaterThanOrEqual(1, $processed);
    }

    // ================= Helpers =================

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function makeDocWithPage(): array
    {
        $visitId = $this->makeConsultation();
        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visitId, null, []);

        return [$doc, (int) $doc['pages'][0]['id']];
    }

    /** ویزیت واقعی تا in_consultation — بیمار تازه هر بار (J-5). */
    private function makeConsultation(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-HWV-' . $seq,
                'Visit',
                'P' . $seq,
                '0913' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryUserId, $patientId, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function stroke(): array
    {
        return [
            'id' => 's1',
            'tool' => 'pen',
            'color' => '#1a1a2e',
            'size' => 4,
            'points' => [[10, 20, 0.5, 1690000000], [40, 60, 0.8, 1690000050]],
        ];
    }

    private function strokeData(): string
    {
        return base64_encode(gzencode(json_encode([$this->stroke()], JSON_UNESCAPED_UNICODE) ?: '[]') ?: '[]');
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(int $visitId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$visitId]
        );
        if ($row === null) {
            throw new \RuntimeException("visit {$visitId} not found");
        }

        return $row;
    }

    private function versionCount(int $pageId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            [$pageId]
        );
    }

    private function assertAudit(string $action, string $resourceType, int $resourceId): void
    {
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            [$action, $resourceType, $resourceId]
        );
        $this->assertGreaterThanOrEqual(1, $count, "audit {$action} for {$resourceType}#{$resourceId}");
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $data = $res->get_data();

        return is_array($data) && isset($data['code']) ? (string) $data['code'] : '';
    }

    private function serviceAtClock(int $now): HandwritingService
    {
        $db = App::db();
        $gate = new SignedLicenseGate(App::licenseService(), null, static fn (): int => $now);

        return new HandwritingService(
            $db,
            new HandwritingRepository($db),
            new VisitRepository($db),
            App::settingsFactory(),
            App::audit(),
            new Idempotency($db),
            $gate
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function installSignedDocumentWith(array $overrides): void
    {
        $this->assertTrue(
            extension_loaded('sodium') && LicenseSignature::available(),
            'ext/sodium is required for signed license tests'
        );
        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        remove_all_filters('cpms_license_public_key');
        add_filter('cpms_license_public_key', static fn (): string => $public);
        $now = time();
        $payload = array_merge([
            'product' => 'cpms',
            'license_id' => 'owner-hw-state-test',
            'install_id' => App::licenseService()->installId(),
            'issued_at' => $now - 30 * 86400,
            'expires_at' => $now + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['handwriting' => true]],
        ], $overrides);
        $canonical = LicenseSignature::canonicalJson($payload);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, sodium_crypto_sign_secretkey($pair)));
        App::licenseService()->activateWithDocument($canonical, $signature);
    }
}

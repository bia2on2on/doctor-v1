<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Focused cross-clinic object-ID checks for staff patient health-data reads
 * and profile updates.
 *
 * Uses a secretary with active membership in Clinic A, real PatientController
 * REST dispatch, and foreign patient rows in Clinic B. Other health-data routes
 * are inventoried but remain outside this test's coverage.
 */
final class ClinicIsolationPatientDataTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $organization_id = 0;

    private int $clinic_a = 0;

    private int $clinic_b = 0;

    private int $actor_id = 0;

    private int $patient_a = 0;

    private int $patient_b = 0;

    private string $foreign_name = '';

    private string $foreign_mrn = '';

    private string $foreign_mobile = '';

    private string $own_name = '';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();

        $this->organization_id = $this->createOrganization();
        $this->clinic_a = $this->createClinic($this->organization_id, 'patient-data-a');
        $this->clinic_b = $this->createClinic($this->organization_id, 'patient-data-b');
        $this->actor_id = $this->makeUser('patient_data_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        $membership_id = cpms_test_seed_membership(
            $this->actor_id,
            $this->clinic_a,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        self::assertGreaterThan(0, (int) $membership_id, 'actor has active secretary membership in Clinic A');
        self::assertTrue(user_can($this->actor_id, RolesAndCapabilities::PATIENT_UPDATE));
        self::assertSame(
            'active',
            (string) App::db()->fetchValue(
                'SELECT status FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
                [(int) $membership_id]
            )
        );

        $this->patient_a = $this->insertPatient($this->clinic_a, 'OwnPatient');
        $this->patient_b = $this->insertPatient($this->clinic_b, 'ForeignPatient');
        $this->own_name = $this->patient_last_name($this->patient_a);
        $this->foreign_name = $this->patient_last_name($this->patient_b);
        $this->foreign_mrn = $this->patient_mrn($this->patient_b);
        $this->foreign_mobile = $this->patient_mobile($this->patient_b);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        $this->purgeOrganizationRows();
        if ($this->actor_id > 0) {
            wp_delete_user($this->actor_id);
        }
        parent::tearDown();
    }

    /**
     * An authorized Clinic A secretary reads its own patient but cannot use a
     * patient ID to read Clinic B's record.
     */
    public function testStaffPatientReadCannotCrossClinicByObjectId(): void
    {
        $own = $this->dispatch('GET', self::NS . '/patients/' . $this->patient_a, $this->clinic_a);
        self::assertSame(200, $own->get_status(), 'positive control: own-clinic patient is readable');
        self::assertIsArray($own->get_data());
        self::assertStringContainsString($this->own_name, wp_json_encode($own->get_data()));

        $foreign = $this->dispatch('GET', self::NS . '/patients/' . $this->patient_b, $this->clinic_a);
        self::assertSame(404, $foreign->get_status(), 'foreign patient is indistinguishable from not found');
        self::assertIsArray($foreign->get_data());
        self::assertSame('CLINIC_NOT_FOUND', (string) $foreign->get_data()['code']);
        $body = wp_json_encode($foreign->get_data());
        self::assertIsString($body);
        self::assertStringNotContainsString($this->foreign_name, $body);
        self::assertStringNotContainsString($this->foreign_mrn, $body);
        self::assertStringNotContainsString($this->foreign_mobile, $body);
    }

    /**
     * Clinic B staff update must be rejected at the patient ownership check and
     * leave the persisted foreign profile unchanged.
     */
    public function testStaffPatientUpdateCannotCrossClinicByObjectId(): void
    {
        $own_phone = '02188888888';
        $own = $this->dispatch(
            'PATCH',
            self::NS . '/patients/' . $this->patient_a,
            $this->clinic_a,
            ['phone' => $own_phone]
        );
        self::assertSame(200, $own->get_status(), 'positive control: same actor updates own-clinic patient');
        self::assertIsArray($own->get_data());
        self::assertStringContainsString($this->own_name, wp_json_encode($own->get_data()));
        self::assertStringContainsString($own_phone, wp_json_encode($own->get_data()));

        $before = $this->patient_state($this->patient_b);
        $attempted_phone = '02177776666';
        $foreign = $this->dispatch(
            'PATCH',
            self::NS . '/patients/' . $this->patient_b,
            $this->clinic_a,
            ['phone' => $attempted_phone]
        );
        self::assertSame(404, $foreign->get_status(), 'foreign update is reported as missing');
        self::assertIsArray($foreign->get_data());
        self::assertSame('CLINIC_NOT_FOUND', (string) $foreign->get_data()['code']);
        $body = wp_json_encode($foreign->get_data());
        self::assertIsString($body);
        $foreign_tokens = [$this->foreign_name, $this->foreign_mrn, $this->foreign_mobile, $attempted_phone];
        self::assertNotEmpty($foreign_tokens);
        foreach ($foreign_tokens as $token) {
            self::assertNotSame('', $token);
            self::assertStringNotContainsString($token, $body);
        }
        self::assertSame($before, $this->patient_state($this->patient_b), 'Clinic B patient row is unchanged');
    }

    private function patient_state(int $patient_id): array
    {
        return (array) App::db()->fetchRow(
            'SELECT first_name, last_name, mrn, mobile, phone, status FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patient_id]
        );
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'Isolation patient data ' . $unique,
            'isolation-patient-data-' . $unique,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture inserted');

        return $id;
    }

    private function createClinic(int $organization_id, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organization_id,
            'Isolation ' . $tag,
            'isolation-' . $tag . '-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'clinic fixture inserted independently of legacy Clinic 1');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(4));
        $user_id = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@isolation.test');
        self::assertGreaterThan(0, $user_id, 'staff user fixture inserted');
        $user = get_userdata($user_id);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $user_id;
    }

    private function insertPatient(int $clinic_id, string $last_name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $mrn = 'ISO-' . strtoupper($unique);
        $mobile = '0912' . (string) random_int(10000000, 99999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinic_id,
            $mrn,
            'Isolation',
            $last_name . $unique,
            $mobile,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture inserted');

        return $id;
    }

    private function patient_last_name(int $patient_id): string
    {
        return (string) App::db()->fetchValue(
            'SELECT last_name FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patient_id]
        );
    }

    private function patient_mrn(int $patient_id): string
    {
        return (string) App::db()->fetchValue(
            'SELECT mrn FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patient_id]
        );
    }

    private function patient_mobile(int $patient_id): string
    {
        return (string) App::db()->fetchValue(
            'SELECT mobile FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patient_id]
        );
    }

    private function dispatch(string $method, string $route, int $clinic_id, array $params = []): WP_REST_Response
    {
        wp_set_current_user($this->actor_id);
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinic_id);

        return rest_do_request($request);
    }

    private function purgeOrganizationRows(): void
    {
        if ($this->organization_id <= 0) {
            return;
        }
        global $wpdb;
        $org_id = (int) $this->organization_id;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org_id . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org_id . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}

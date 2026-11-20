<?php

/**
 * Migration 0032 — Patient identity & login table (`cpms_patient_logins`).
 *
 * Adds one table, `{wp_prefix}cpms_patient_logins`, for patient account and
 * binding rows as specified in `docs/architecture/patient-identity-login-design.md` §1.
 *
 * DDL is portable MySQL 8 / MariaDB 10.11 / 11.4 (InnoDB, utf8mb4, no generated
 * columns, no stored procedures, no FOREIGN KEY constraints so it remains safe
 * under the WordPress test-suite `CREATE TEMPORARY TABLE` rewrite).
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version'     => '2026_11_20_0032',
    'description' => 'Patient identity and login account/binding table (cpms_patient_logins)',
    'up'          => static function ( CpmsDb $db ): void {
        $logins = $db->table( 'cpms_patient_logins' );

        $db->query(
            "CREATE TABLE IF NOT EXISTS {$logins} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` ENUM('account','binding') NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `mobile` CHAR(11) NULL,
            `state` ENUM('pending','active','closed') NOT NULL,
            `clinic_id` BIGINT UNSIGNED NULL,
            `patient_id` BIGINT UNSIGNED NULL,
            `verified_at` DATETIME(3) NULL,
            `verification_method` ENUM('two_factor') NULL,
            `live_key` VARCHAR(40) NULL,
            `account_key` BIGINT UNSIGNED NULL,
            `given_name` VARCHAR(120) NULL,
            `family_name` VARCHAR(120) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_pl_mobile` (`mobile`),
            UNIQUE KEY `u_pl_bind` (`wp_user_id`,`clinic_id`,`patient_id`),
            UNIQUE KEY `u_pl_live` (`live_key`),
            UNIQUE KEY `u_pl_account` (`account_key`),
            KEY `idx_pl_kind_state_user` (`kind`,`state`,`wp_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
    'down'        => static function ( CpmsDb $db ): void {
        // Development only — production restores from backup.
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_patient_logins' ) );
    },
];

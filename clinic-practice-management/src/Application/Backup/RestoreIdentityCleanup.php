<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Db\CpmsDb;
use WP_Session_Tokens;
use WP_User_Meta_Session_Tokens;

/** Post-import identity revocation for use under a closed restore barrier. */
final class RestoreIdentityCleanup {
    public function __construct( private readonly CpmsDb $db ) {
    }

    public function revoke(): void {
        $manager = apply_filters( 'session_token_manager', WP_User_Meta_Session_Tokens::class ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reuse the WordPress core session backend selector.
        if ( WP_User_Meta_Session_Tokens::class !== $manager ) {
            throw BackupException::of( 'RESTORE_SESSION_MANAGER_UNSUPPORTED', 'Cannot verify custom session storage.' );
        }

        $table = $this->db->table( 'patient_logins' );
        if ( ! $this->db->query(
            'UPDATE %i SET state = %s, live_key = NULL WHERE state <> %s',
            [ $table, 'closed', 'closed' ]
        ) ) {
            throw BackupException::of( 'RESTORE_BINDING_CLOSURE_FAILED', 'Patient login closure failed.' );
        }

        WP_Session_Tokens::destroy_all_for_all_users();
        // The WordPress bulk API returns no status; check storage independently.
        if ( [] !== $this->db->fetch_all_strict(
            'SELECT 1 FROM %i WHERE meta_key = %s LIMIT 1',
            [ $this->db->wpdb()->usermeta, 'session_tokens' ]
        ) ) {
            throw BackupException::of( 'RESTORE_SESSION_REVOCATION_FAILED', 'WordPress sessions remain after revocation.' );
        }
    }
}

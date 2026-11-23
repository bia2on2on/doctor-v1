<?php
/**
 * Save per-Clinic public listing consent from the shortcode admin page.
 *
 * @package ClinicCore
 */

declare( strict_types=1 );

namespace ClinicCore\Admin;

use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Bootstrap\App;
use Throwable;

/** The shortcode admin page is the sole caller of this form handler. */
final class PublicListingAdminAction {
	/**
	 * Save only for the Clinic the caller resolved from active memberships and CONFIG.
	 *
	 * @return bool|null True on verified save, false on rejection, null on a normal page view.
	 */
	public static function maybe_save( int $authorized_clinic_id, int $actor_user_id ): ?bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only detects the form; the nonce is verified before mutation.
		if ( ! isset( $_POST['cpms_public_listing_save'] ) ) {
			return null;
		}
		if ( $authorized_clinic_id <= 0 || $actor_user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = isset( $_POST['cpms_public_listing_nonce'] ) && is_string( $_POST['cpms_public_listing_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['cpms_public_listing_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'cpms_public_listing_' . $authorized_clinic_id ) ) {
			return false;
		}
		// Three-stage license (design §7.3 staff/settings row, final 2026-11-17): Stage 3 is view-only.
		$license_decision = App::licenseGate()->assert( \ClinicCore\Domain\Licensing\LicenseGate::OP_SETTINGS_WRITE );
		if ( ! $license_decision->allowed ) {
			App::op()->warning( 'license.blocked', [ 'operation' => \ClinicCore\Domain\Licensing\LicenseGate::OP_SETTINGS_WRITE, 'reason' => $license_decision->reason ] );
			return false;
		}
		$enabled = isset( $_POST['cpms_public_listing_enabled'] ) && '1' === $_POST['cpms_public_listing_enabled'];
		try {
			$settings = App::settingsFactory()->forClinic( $authorized_clinic_id );
			$settings->set( PublicClinicDirectory::CONSENT_SETTING, $enabled, $actor_user_id );

			$row = App::db()->fetch_all_strict(
				'SELECT value_json FROM ' . App::db()->table( 'cpms_settings' ) . ' WHERE clinic_id = %d AND `key` = %s',
				[ $authorized_clinic_id, PublicClinicDirectory::CONSENT_SETTING ]
			);

			return ( $row[0]['value_json'] ?? null ) === wp_json_encode( $enabled );
		} catch ( Throwable ) {
			return false;
		}
	}
}

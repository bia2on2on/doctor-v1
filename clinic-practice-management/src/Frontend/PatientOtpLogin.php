<?php
/**
 * Patient Portal guest OTP login and self-registration form.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Bootstrap\App;
use Throwable;

/**
 * Patient Portal's guest OTP login & Slice 2 self-registration surface.
 * Presentation/configuration for existing public OTP routes and
 * `POST /clinic/v1/patient/register`; authentication remains in OtpService
 * and PatientRegistrationService.
 */
final class PatientOtpLogin {

	private const REST_NAMESPACE   = 'clinic/v1';
	private const OTP_REQUEST_PATH = '/otp/request';
	private const OTP_VERIFY_PATH  = '/otp/verify';
	private const REGISTER_PATH    = '/patient/register';

	public static function render(): void {
		$id               = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'cpms-patient-otp-' ) : 'cpms-patient-otp-' . wp_rand( 1000, 999999 );
		$reg_id           = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'cpms-patient-reg-' ) : 'cpms-patient-reg-' . wp_rand( 1000, 999999 );
		$register_clinics = self::register_clinics();
		$config           = array(
			'rest_root'        => rtrim( rest_url( self::REST_NAMESPACE ), '/' ),
			'otp_request_path' => self::OTP_REQUEST_PATH,
			'otp_verify_path'  => self::OTP_VERIFY_PATH,
			'register_path'    => self::REGISTER_PATH,
			'register_clinics' => $register_clinics,
		);
		$json             = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}
		?>
		<section class="cpms-patient-portal-shell__notice cpms-pp-login" data-role="portal-login">
			<h2>ورود به پورتال بیمار</h2>
			<p>شمارهٔ موبایل تأییدشدهٔ خود را وارد کنید تا کد یک‌بارمصرف را دریافت کنید.</p>
			<form class="cpms-pp-login__form" data-role="patient-otp-login" novalidate>
				<div class="cpms-pp-login__field">
					<label for="<?php echo esc_attr( $id ); ?>-mobile">شمارهٔ موبایل</label>
					<input id="<?php echo esc_attr( $id ); ?>-mobile" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" data-role="patient-otp-mobile" required>
				</div>
				<div class="cpms-pp-login__field" data-role="patient-otp-code-step" hidden>
					<label for="<?php echo esc_attr( $id ); ?>-code">کد ۶ رقمی پیامک‌شده</label>
					<input id="<?php echo esc_attr( $id ); ?>-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" data-role="patient-otp-code" required>
				</div>
				<div class="cpms-pp-login__actions">
					<button type="button" class="cpms-pp-btn cpms-pp-btn--primary" data-role="patient-otp-request">دریافت کد ورود</button>
					<button type="button" class="cpms-pp-btn cpms-pp-btn--primary" data-role="patient-otp-verify" hidden>تأیید و ورود</button>
				</div>
				<p class="cpms-pp-login__status" data-role="patient-otp-status" role="status" aria-live="polite" hidden></p>
			</form>
			<p class="cpms-pp-login__scope-hint">پس از ورود با کد یک‌بارمصرف، در پورتال مرکز درمانی و کد ملی خود را برای مشاهدهٔ پرونده تأیید کنید.</p>
			<script type="application/json" class="cpms-patient-portal__auth-config"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with script-safe HEX flags. ?></script>
		</section>
		<?php if ( [] !== $register_clinics ) : ?>
		<section class="cpms-patient-portal-shell__notice cpms-pp-register" data-role="patient-register-section">
			<h2>ثبت‌نام بیمار جدید</h2>
			<p>اگر هنوز حساب کاربری ندارید، ابتدا شمارهٔ موبایل خود را با کد پیامکی تأیید کنید و سپس مشخصات خود را ثبت نمایید.</p>
			<form class="cpms-pp-login__form" data-role="patient-register-form" novalidate>
				<div class="cpms-pp-login__field">
					<label for="<?php echo esc_attr( $reg_id ); ?>-clinic">مرکز درمانی</label>
					<select id="<?php echo esc_attr( $reg_id ); ?>-clinic" data-role="register-clinic" required>
						<?php if ( count( $register_clinics ) > 1 ) : ?>
							<option value="">انتخاب مرکز درمانی…</option>
						<?php endif; ?>
						<?php foreach ( $register_clinics as $clinic ) : ?>
							<option value="<?php echo esc_attr( (string) $clinic['id'] ); ?>"><?php echo esc_html( (string) $clinic['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cpms-pp-login__field">
					<label for="<?php echo esc_attr( $reg_id ); ?>-mobile">شمارهٔ موبایل</label>
					<input id="<?php echo esc_attr( $reg_id ); ?>-mobile" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" data-role="register-mobile" required>
				</div>
				<div class="cpms-pp-login__actions">
					<button type="button" class="cpms-pp-btn cpms-pp-btn--secondary" data-role="register-otp-request">دریافت کد تأیید ثبت‌نام</button>
				</div>
				<div data-role="register-details-step" hidden>
					<div class="cpms-pp-login__field">
						<label for="<?php echo esc_attr( $reg_id ); ?>-code">کد ۶ رقمی پیامک‌شده</label>
						<input id="<?php echo esc_attr( $reg_id ); ?>-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" data-role="register-code" required>
					</div>
					<div class="cpms-pp-login__field">
						<label for="<?php echo esc_attr( $reg_id ); ?>-given-name">نام</label>
						<input id="<?php echo esc_attr( $reg_id ); ?>-given-name" type="text" maxlength="120" autocomplete="given-name" data-role="register-given-name" required>
					</div>
					<div class="cpms-pp-login__field">
						<label for="<?php echo esc_attr( $reg_id ); ?>-family-name">نام خانوادگی</label>
						<input id="<?php echo esc_attr( $reg_id ); ?>-family-name" type="text" maxlength="120" autocomplete="family-name" data-role="register-family-name" required>
					</div>
					<div class="cpms-pp-login__field">
						<label for="<?php echo esc_attr( $reg_id ); ?>-national-id">کد ملی (۱۰ رقم)</label>
						<input id="<?php echo esc_attr( $reg_id ); ?>-national-id" type="text" inputmode="numeric" maxlength="10" dir="ltr" data-role="register-national-id" required>
					</div>
					<div class="cpms-pp-login__field">
						<label for="<?php echo esc_attr( $reg_id ); ?>-birth-date">تاریخ تولد (میلادی YYYY-MM-DD)</label>
						<input id="<?php echo esc_attr( $reg_id ); ?>-birth-date" type="text" inputmode="numeric" maxlength="10" placeholder="YYYY-MM-DD" dir="ltr" autocomplete="bday" data-role="register-birth-date" required>
					</div>
					<div class="cpms-pp-login__actions">
						<button type="submit" class="cpms-pp-btn cpms-pp-btn--primary" data-role="register-submit">تکمیل ثبت‌نام</button>
					</div>
				</div>
				<p class="cpms-pp-login__status" data-role="register-status" role="status" aria-live="polite" hidden></p>
			</form>
		</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Server-trusted clinics eligible for public guest registration:
	 * only active clinics in active organizations that have explicitly opted in
	 * via `public.listing_opt_in` (fail-closed; no sole-clinic fallback).
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private static function register_clinics(): array {
		try {
			$directory = new PublicClinicDirectory();
			$db        = App::db();
			$rows      = $db->fetchAll(
				'SELECT c.id, c.name FROM ' . $db->table( 'cpms_clinics' ) . ' c'
				. ' INNER JOIN ' . $db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
				. " WHERE c.status = 'active' AND o.status = 'active'"
				. ' ORDER BY c.id ASC LIMIT 50'
			);
			$opted_in  = array();
			foreach ( $rows as $row ) {
				$id   = (int) ( $row['id'] ?? 0 );
				$name = trim( (string) ( $row['name'] ?? '' ) );
				if ( $id > 0 && '' !== $name && $directory->is_listed( $id ) ) {
					$opted_in[] = array(
						'id'   => $id,
						'name' => $name,
					);
				}
			}

			return $opted_in;
		} catch ( Throwable ) {
			return array();
		}
	}
}

# License & Activation Design — CPMS 1.0 (Draft, docs-only)

Status: **DRAFT for owner approval. No code, key, migration, settings, workflow or header change is included.**

## خلاصهٔ فارسی

- این سند طراحیِ نظامِ مجوز و فعال‌سازیِ «Clinic Practice Management» (CPMS) نسخهٔ ۱٫۰ است؛ فقط سند است و هیچ کدی تغییر نمی‌کند.
- مخزن عمومی است. کلید خصوصیِ امضا هرگز در مخزن، CI یا افزونه نیست؛ فقط کلید عمومیِ تأیید در افزونه قرار می‌گیرد.
- ارزش این نظام تجاری و قراردادی است: امضا از جعلِ «سند مجوز» جلوگیری می‌کند، نه از ویرایشِ کدِ افزونه (کد عمومی و قابل‌خواندن است).
- سند مجوز (`schema_version=2`) شامل `license_id`، `install_id`، دامنهٔ امضاشده، `entitlements`، `issued_at`، `expires_at` و `key_id` است؛ هیچ PHI در آن نیست.
- فعال‌سازی آفلاین: مشتری `install_id` و دامنه را به مالک می‌فرستد؛ مالک سندِ امضاشده می‌سازد؛ مشتری آن را در wp-admin الصاق می‌کند. فعال‌سازی آنلاین اختیاری است (فقط اگر `license.server_url` تنظیم شود).
- قانون صلب: هرگز مشاهدهٔ دادهٔ بیمار، نوبت‌های موجود، تکمیل ویزیت، پرداخت فاکتورهای موجود، پشتیبان‌گیری/بازیابی و ورود OTP مسدود نمی‌شود.
- پیشنهاد: قفلِ ۷روزهٔ نخستین اجرا با جریانی امن‌تر جایگزین شود — در طول پنجره همه‌چیز با اعلان فارسی برجسته کار می‌کند و پس از پنجره فقط «بیمار/نوبت جدید» محدود می‌شود.
- کانال توزیع/به‌روزرسانیِ ZIP تصمیمِ مالک است (بخش ۴ و ۱۲)؛ هیچ دامنهٔ واقعی یا آدرس GitHub به‌عنوان نشانی محصول استفاده نشده است؛ جای نگه‌دار `<PRODUCT_DOMAIN>` است.
- تصمیم‌های مالک پیش از هر پیاده‌سازی لازم است (بخش ۱۲).

---

## 1. Goals, non-goals, honest threat model

### 1.1 Goals
- G1. A real, cryptographically verifiable license document for CPMS 1.0 that is later upgradeable to the full product line without data loss.
- G2. A commercial/contractual value model: the signed document is the license; the plugin verifies it locally.
- G3. Activation that works for a phone-only owner and a customer with no GitHub knowledge (offline first; online optional).
- G4. No path in which a 1.0 customer is left without an activation route.
- G5. Hard safety rule: license state must never block clinical reads, existing care, payments on existing invoices, backup/export/restore, or patient OTP login.

### 1.2 Non-goals
- N1. Preventing edits of shipped plugin PHP. The repository and the distributed ZIP are public/readable; anyone may fork and remove the gate.
- N2. Hiding PHI. The license payload carries no PHI (ADR-0028); this is a control-plane document.
- N3. Implementing a central license service, billing, activation counting, revocation UI, or update-plane changes in this design. Those are outside this document (ADR-0023 §7 marks them FUTURE/OPEN).
- N4. Any schema change. See §10.

### 1.3 Honest threat model (public code base)
- **What the signature stops:** a forged or altered license document. An attacker cannot mint a document that verifies under the trusted public key ring, cannot change `expires_at`, `install_id`, `domain`, `entitlements`, or `key_id`, and cannot reuse another installation's document because `install_id` and (when present) the signed `domain` claim are checked (`LicenseService.php:318-339`, `LicenseService.php:407-425`).
- **What the signature does NOT stop:** editing the plugin code. Because all PHP is public, an attacker can patch out the gate. The design does not claim code secrecy and does not rely on it. The economic value is therefore commercial/contractual (a valid signed document is the thing the customer pays for; support, renewal, updates and entitlements ride on it), not "unbreakable DRM".
- **Threats accepted:** local tampering with the gate; clock manipulation; cloning a site to another install_id; mass document sharing. Mitigations are contractual + signed binding + server-side (future) activation counting, not code obfuscation.
- **Secrets boundary:** only public verification keys ship. The private signing key is generated and held outside the repository (§3). No secret, token, password or key material belongs in the repo, the PR, CI logs or this document.
- **Known-unfixed-code note (no exploit detail):** the current shipped placeholder public-key ring is empty/invalid, so every activation attempt fails closed today (§7, §12-D1). This is a release blocker to be resolved before 1.0 ships, not a vulnerability to exploit.

### 1.4 Evidence labels
`OBSERVED` = read in this checkout at the cited `file:line`. `INFERENCE` = reasoned from OBSERVED code. `UNKNOWN` = not established here. See Appendix A.

## 2. License document

### 2.1 Fields (v2)
| Field | Type | Meaning |
|---|---|---|
| `product` | string | Must equal `cpms` (`LicenseService.php:320`). |
| `schema_version` | int | Must be `2` (`LicenseSignature.php:22,99`). |
| `key_id` | string | Bounded id selecting one trusted public key (`LicenseKeys.php:94-104`). |
| `license_id` | string | Commercial license id; non-empty (`LicenseService.php:326-329`). |
| `install_id` | string | 32-hex local install identity; must match local `cpms_license_install` (`LicenseService.php:323`). |
| `domain` | string, optional | Signed domain binding claim; canonicalized before compare (`LicenseService.php:407-425`, `LicenseDomain.php:24-32`). |
| `entitlements` | object | `{features: {name: bool}, limits: {name: int}}` (`EntitlementRegistry.php:29-47`). |
| `issued_at` | int | Epoch seconds; optional but validated (`LicenseService.php:328-329`). |
| `expires_at` | int | Epoch seconds; required, positive, not before `issued_at` (`LicenseService.php:327-329`). |
| `activation_id` | string, optional | Opaque central activation-record id; bounded grammar (`LicenseSignature.php:107-112`). |
| `update_rights_until` | int, optional | Positive epoch seconds; update-plane right (`LicenseSignature.php:113-117`). |
| `revoked` / `suspended` / `reason` | bool/string | Commercial state, signed (`LicenseStateMachine.php:68-83`). |

No PHI, no patient data, no secrets. No new fields are introduced by this design.

### 2.2 Canonical serialization and signature
- Reuse the existing verifier rather than adding a second scheme. `LicenseSignature::canonicalJson()` recursively `ksort`s keys and `json_encode`s with `JSON_UNESCAPED_UNICODE` (`LicenseSignature.php:34-39`); verification is `sodium_crypto_sign_verify_detached` over that exact byte string with the selected Ed25519 public key (`LicenseSignature.php:119-143`).
- Justification: the canonicalization and detached Ed25519 path are already implemented, unit-tested, and used by both online and offline activation (`LicenseService.php:233,247,277,334`). Reusing it avoids a parallel, weaker scheme. libsodium's detached Ed25519 API is the reference behaviour here: <https://doc.libsodium.org/public-key_cryptography/public-key_signatures>; PHP binding: <https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php>.
- Fail-closed: if `sodium` is unavailable the signature is "not verifiable" and activation is refused (`LicenseSignature.php:24-27,131-133`).

### 2.3 Format versioning
- `schema_version` must be the integer `2`; any other value, or a partial metadata pair (only one of `schema_version`/`key_id`), fails closed (`LicenseSignature.php:56-67,97-106`).
- Legacy (unversioned) documents keep the existing legacy path; they never fall back from the v2 path (`LicenseSignature.php:75-89`). No `schema_version=3` is designed.
- The design keeps the existing `DOCUMENT_SCHEMA_VERSION = 2` constant unchanged.

## 3. Keys and custody

### 3.1 Generation outside the repository
- Generate an Ed25519 key pair once, on a device the owner controls, **outside** this repository and outside CI. Only the base64 public key is copied into the shipped `LicenseKeys` ring; the private key never enters the repo, the plugin, the database, the ZIP, or any log.
- The existing placeholder is intentionally invalid: `LicenseKeys.php:23` (`REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64`) and the empty ring `LicenseKeys.php:31` (`TRUSTED_PUBLIC_KEYS_B64 = []`). Release-keys are a separate key (`ReleaseKeys.php:16`); do not reuse one key for both (ADR-0029 separation).

### 3.2 Custody options for a phone-only owner
| # | Option | Pros | Cons | Loss impact | Compromise impact |
|---|---|---|---|---|---|
| C1 | Encrypted password-manager entry (secure note with the key file) on the phone | Owner already has it; encrypted at rest; backup/export possible | Phone loss/theft; phishing; sync provider breach | Recoverable from the manager's backup if one exists; otherwise all issuance stops | Attacker can sign forged documents; rotate immediately (§3.4) |
| C2 | Hardware key / small HSM holding the private key | Key never leaves the device; no file to copy | Cost; phone may not have the right port/adapter; needs a signing step | Same as C1 but no digital copy exists | Very low likelihood; device theft + PIN |
| C3 | Paper seed/backup phrase in a safe or bank deposit | Offline; independent of any device; durable if stored well | Fire/water/theft; transcription errors; no quick reissue | No digital recovery; issuance stops | Theft gives full signing power; rotate immediately |
| C4 | Air-gapped standby phone / old device storing the encrypted key file | Phone-only friendly; cheap; separate from daily phone | Device failure/battery/storage loss; still a digital copy | Recoverable only from a second copy | Same as C1 |

Recommendation: **C1 as the working copy + C3 as the offline backup** (or C2 if the owner already uses a hardware key). Keep at least one offline copy so a lost phone does not end issuance.

### 3.3 Explicitly rejected
- **Rejected by default:** storing the signing key in GitHub Actions secrets or any CI. In a public repository, fork pull requests and workflow logs are real exposure paths; a secret usable to sign license documents must not be reachable from CI. Reference: <https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions>.
- Rejected: repo files, `.env`, commits, issues/PRs, cloud notes without encryption, email/chat, the plugin ZIP, the database, `wp-config.php` committed anywhere.
- Test keys are generated only in tests and must be rejected by the release build (§3.5).

### 3.4 Key id and rotation
- `key_id` is a bounded ASCII identifier, not key material (`LicenseKeys.php:105-114`). The trusted ring maps `key_id => base64 public key` and may hold up to 32 keys (`LicenseKeys.php:33-34,62-91`).
- Rotation: add the new public key under a new `key_id` to the ring; issue documents signed by the new private key; keep the old public key until old documents no longer need local verification; then remove it. The existing code already supports concurrent trusted keys and exact `key_id` selection (`LicenseSignature.php:103-106`).
- Lost-key recovery: generate a new pair, add its public key to the ring, and reissue documents to affected installs. Because only the public key ships, the old private key cannot be reconstructed; the old public key may be kept for verification during the transition.

### 3.5 Release-build gate (proposed; docs only)
- Today `clinic-practice-management/bin/build-release.sh` has a content policy self-check (`:109-120`) but **no** check for `REPLACE_AT_RELEASE` placeholders or test keys. Proposed gate (to be implemented only after owner approval): the release build fails if (a) `LicenseKeys::PRODUCTION_PUBLIC_B64` or `ReleaseKeys::PRODUCTION_PUBLIC_B64` still contains `REPLACE_AT_RELEASE`, (b) the trusted ring is empty, or (c) any test/known non-production key id or public key appears in the shipped source. This is a build-policy change, not a code change in this PR.

## 4. Activation flows and distribution channel

### 4.1 Offline flow (default; no GitHub or server knowledge needed)
1. Customer installs the ZIP in wp-admin and opens **CPMS → سلامت سیستم** (System page).
2. The page shows the masked install id and a prominent Persian activation notice.
3. Customer sends the owner two values: **install_id** (shown on that page) and the site **domain** (e.g. `clinic.example`). No PHI is sent.
4. Owner builds/signs a v2 document on the offline device with the private key: `product=cpms`, `schema_version=2`, a fresh `key_id`, a `license_id`, the customer's `install_id`, the canonical `domain`, `entitlements`, `issued_at`, `expires_at`.
5. Customer pastes the document JSON and the base64 signature into the **«فعال‌سازی آفلاین/دستی (سند امضاشده)»** form and submits. Exact wp-admin steps in Persian are in §8.
6. Server verifies install match + signature + dates and stores the document (`LicenseService.php:241-250,318-339`); the state becomes `ACTIVE` (or `EXPIRING`/`GRACE` near expiry).

### 4.2 Optional online flow
- Only when `license.server_url` is set (`Settings.php:119`, default `''` = offline/manual). `HttpVendorGateway` enforces HTTPS, an SSRF guard, timeouts, and a hard metadata allowlist (no PHI): `HttpVendorGateway.php:31-34,57-60,116-136`.
- Customer enters a license key; `activateWithKey` posts to `/activate` and stores the returned signed document through the same `verifyAndStore` path (`LicenseService.php:219-236`). The online path is an optional convenience, never a requirement.

### 4.3 ZIP distribution / update channel (OPEN owner decision — do not assume)
| Option | Pros | Cons |
|---|---|---|
| Public GitHub release | Free; versioned; checksums possible | Requires the customer to know GitHub and pick the right asset; creating a release/tag needs separate owner approval |
| Separate download site at `<PRODUCT_DOMAIN>` | Familiar "download" UX; no GitHub knowledge; can host license/support | Needs a domain + hosting + TLS + update feed; more to operate |
| Manual delivery (email/messaging) | Simplest for a phone-only owner; no infra | No self-service; no automatic update notice; scaling cost |
Recommendation: manual/offline delivery for 1.0 first customers, then a simple download page; treat a public GitHub release as one accepted channel, not the only one.

## 5. Domain binding

- Canonicalization is the single existing seam: lower-case, strip one leading `www.`; scheme/path/port and IDN/punycode are out of scope; never `HTTP_HOST` (`LicenseDomain.php:24-32`, `LicenseService.php:380-388`).
- `http` vs `https` and `www` vs bare are treated as the same host after canonicalization. A mismatch fails closed to `RESTRICTED`/`binding_mismatch` (`LicenseService.php:407-425`); restoring the bound domain restores the normal state with the document untouched.
- Staging/clones: a staging host is a different canonical domain, so it needs its own document (or the owner may omit the `domain` claim for legacy/unbound behaviour — see `LicenseService.php:408-410`). Clones share the source `install_id` only if the whole database is copied; a restore onto a **new** install gets a new `install_id` and therefore needs a new document/rebind.
- Restore onto a new install: the backup/restore design is **not** in this repository's `main`; treat it as an open interface. This design assumes the restore interface preserves or replaces `cpms_license_install.install_id` and must be reconciled with licensing when that design lands. Do not design it here.
- Domain change / re-binding and abuse limits: a reissue is a new signed document with a new `domain`; because the owner controls issuance, reissue count and one-active enforcement are commercial/policy controls (future central service), not code. The signed `activation_id` claim is the intended hook (`LicenseSignature.php:107-112`).

## 6. Time, states and offline operation

- T0 = signed `expires_at`. The existing state machine yields `ACTIVE → EXPIRING → GRACE → RESTRICTED` (`LicenseStateMachine.php:47-100`); the owner post-expiration policy governs new business at/after T0 (`SignedLicenseGate.php:47-64`, `PostExpirationPolicy.php:20-26`).
- Renewal overlap: a renewed document with a later `expires_at` simply supersedes the stored row; permission is restored immediately and no destructive local lock is added (ADR-0023 §0). Renewal inside `EXPIRING`/`GRACE` is normal.
- Pre-expiry notices: `EXPIRING` when within the 7-day expiry grace; `GRACE` after T0 (`LicensePolicy.php:24`, `LicenseStateMachine.php:93-97`). UI wording in §8.
- Grace: the expiry grace is 7 days for status display; it does not move T0 (`LicensePolicy.php:39-42`, `LicenseStateMachine.php:85-91`). The post-expiration policy separately bounds new-service windows (`PostExpirationPolicy.php:12-26`).
- Clock rollback: the activation window start is clamped so rollback cannot extend the window (`LicenseStateMachine.php:126-127`); expiry uses a bounded skew tolerance (`LicensePolicy.php:28`, `LicenseStateMachine.php:85-91`).
- Fully offline within the licensed period: no network call happens on ordinary page loads; the last verified document governs, and `UNREACHABLE` is distinct from `INVALID` (`LicenseStateMachine.php:71-75`, `ADR-0023 §3`). The offline document path is always available while the trusted public key is valid.

## 7. Behavior matrix and the 7-day lock

### 7.1 Current behaviour (OBSERVED)
- Fresh install: `ACTIVATION_PENDING` for `activationWindowDays` (default 7) → `RESTRICTED` at window end (`LicenseStateMachine.php:138-144`, `LicensePolicy.php:29`).
- Migration install: `ACTIVATION_GRACE` for 30 days → `RESTRICTED` (`LicenseStateMachine.php:129-135`, `LicensePolicy.php:30`).
- `RESTRICTED` is not in `allowsNewBusiness`, so the blocked operations are `patient.create`, `appointment.book`, `appointment.reschedule`, `visit.checkin`, `invoice.create`, `sms.commercial`, `handwriting.new` (`LicenseStatus.php:81-92`, `SignedLicenseGate.php:15-23`); denials surface as `CLINIC_LICENSE_BLOCKED` (503) at the service boundary.
- Reads/history/export, cancelling, visit completion and payments on existing invoices are not in the blocked set and remain allowed (`SignedLicenseGate.php:62-69`).
- **Divergence to decide:** `visit.checkin` **is** in the blocked set. At ordinary expiry (`RESTRICTED` reason `expired`) check-in goes through `PostExpirationPolicy` (`SignedLicenseGate.php:48-61`); under other `RESTRICTED` reasons (activation-window expiry, binding mismatch) it is denied (`SignedLicenseGate.php:68-70`). See D8.
- **Correction to the task's premise:** `invoice.create` is in the blocked set (`SignedLicenseGate.php:20`) but no `src/` caller currently asserts `OP_INVOICE_CREATE`; the other six operations are asserted at the cited services. The claim is otherwise accurate for the listed operations.

### 7.2 Confirmation of "no way out after 7 days"
**CONFIRMED for new business, REFUTED for the safety rule.** With the shipped empty/invalid trusted ring, every signature check fails (`LicenseKeys.php:23,31`; `LicenseSignature.php:103-106`), so `verifyAndStore` refuses every document (`LicenseService.php:334-335`). A fresh install therefore becomes `RESTRICTED` after 7 days with no owner-issued document accepted — a dead end for new patients/bookings. It is **not** a data lock: viewing patient/medical data, existing appointments, visit completion, payments on existing invoices, backup/export/restore and OTP login are not blocked by `RESTRICTED` (`SignedLicenseGate.php:62-69`, `SmsService.php:93-98`). The dead end is created by the placeholder keys, not by the gate design; supplying the real public key removes it.

### 7.3 Proposed replacement for the 7-day hard lock
Hard rule (never block): patient/medical reads, existing appointments, completing visits, payments on existing invoices, backup/export/restore, OTP login of existing patients.
Default proposal: the first-run window is a **notice period, not a hard lock**.
- During the window: all functions work; a persistent Persian banner states the activation deadline and shows install_id/domain.
- After the window: block only **new patients and new bookings** (and the other new-business ops), keep every safety-critical function open, and keep the activation form available so a late document always restores full function.
Options for the owner:
- O1: 7-day window, hard `RESTRICTED` after (current). Cons: the dead-end risk above.
- O2: 14-day window, then new-business-only restriction. Balanced.
- O3: 30-day window (same as migration), then new-business-only restriction. Most forgiving; longest unlicensed use.
- O4: no time limit — only the safety rule applies and new business stays open until a document says otherwise. Weakest commercial control.
Recommendation: **O2 or O3** with the safety rule always enforced. The exact length is owner decision D4.

### 7.4 Matrix (states × operations)
Design target (T) vs current code (C). "Existing appointment" = created before the restriction began.

| State | New patient/booking | Existing appt read/cancel | Check-in existing appt | Complete visit | Payment existing invoice | Reads/export | Backup/restore | OTP login |
|---|---|---|---|---|---|---|---|---|
| `ACTIVE`/`EXPIRING`/`GRACE` | allow | allow | allow | allow | allow | allow | allow | allow |
| `RESTRICTED` ordinary expiry, ≥T0 | deny | allow | T: allow; C: owner 1/6-month windows then deny | allow | allow | allow | allow | allow |
| `RESTRICTED` activation/binding | deny | allow | T: allow; C: deny | allow | allow | allow | allow | allow |
| `ACTIVATION_PENDING`/`ACTIVATION_GRACE` (in window) | allow (notice) | allow | allow | allow | allow | allow | allow | allow |
| `SUSPENDED`/`REVOKED`/`INVALID`/`UNREACHABLE` | deny | allow | T: allow; C: deny | allow | allow | allow | allow | allow |

The only divergence from the safety target is check-in of an existing appointment under non-expiry restrictions; that is D8. Everything else in the target already matches the current code.

## 8. Admin UX (Persian, words only)

- Status page: existing **CPMS → سلامت سیستم** page, section **«مجوز»**. It already shows status, masked install id, license id, activation-window label, expiry in Jalali/UTC, and last refresh (`SystemPage.php:192-257`, labels at `:1013-1030`).
- Activation form (online) and offline document form already exist (`SystemPage.php:240-255`, handlers `:395-420`).
- Exact Persian steps for offline activation:
  1. وارد wp-admin شوید و از منوی **CPMS** وارد **«سلامت سیستم»** شوید.
  2. در بخش **«مجوز»**، **install_id** و **دامنهٔ سایت** را یادداشت کنید.
  3. این دو مقدار را برای مالک محصول بفرستید (بدون هیچ دادهٔ بیماری).
  4. سند مجوز و امضای base64 را که مالک می‌فرستد دریافت کنید.
  5. در همان صفحه، **«فعال‌سازی آفلاین/دستی (سند امضاشده)»** را باز کنید.
  6. متن سند را در کادر سند و امضا را در کادر امزا الصاق کنید و **«فعال‌سازی با سند»** را بزنید.
  7. در صورت موفقیت، وضعیت **«✅ فعال»** نمایش داده می‌شود؛ در صورت شکست، پیام خطا (مثلاً عدم تطابق نصب یا امضا) ظاهر می‌شود.
- Notices: keep the existing Persian `LicenseStatusNotice` texts for grace/restricted/invalid and add a first-run Persian banner with the deadline and the install_id/domain to send. No code is included in this PR.

## 9. Test plan (to be implemented with the code change)

- Unit: canonical JSON stability; signature verify/accept/reject; `key_id` grammar; install match; date validation; `LicensePolicy` math; `LicenseDomain` canonicalization; state machine transitions and window clamp.
- Integration: offline activation stores a valid document; tampered payload/signature/`key_id`/`install_id`/`domain`/`expires_at` are rejected; wrong-domain document → `RESTRICTED`/`binding_mismatch`; online activate/refresh with a stub gateway.
- Mutation: flip each signed field and assert rejection; swap two documents between installs; reuse a document after expiry.
- Expiry/renewal: `ACTIVE → EXPIRING → GRACE → RESTRICTED`; renew restores `ACTIVE`; overlap (new `expires_at`) works.
- Key rotation: two trusted keys concurrently; old document still verifies; unknown `key_id` fails closed; removing an old key fails documents needing it.
- Clock rollback: rollback cannot extend the activation window; expiry skew tolerance.
- Offline: valid document with `license.server_url` empty activates and operates with no network.
- Release-build gate: build fails on `REPLACE_AT_RELEASE`, empty ring, or a test key.
- Real ZIP install: install the built ZIP on a clean WordPress, confirm fresh-window notice and offline activation end-to-end.

## 10. Schema

- **Prefer NONE.** The design reuses `cpms_license_install` and `cpms_license_state` (`2026_09_07_0008_licensing.php:46-72`) and adds no column, table or migration. No schema change is designed.
- If a future need appears (for example persisting a reissue counter or a re-bind history), do **not** design the migration here. Add a stop item:
  - **STOPPED - OWNER DECISION NEEDED.** Question: does the license feature need persisted reissue/rebind state, or can that stay in the owner's commercial records and the signed document? Options: (a) no schema (recommended; signed document + owner records only); (b) add a column/table in the full line first, then copy the migration byte-identically to 1.0. Pros/cons: (a) zero migration risk and no shared-table divergence; (b) enables local abuse counters but adds a migration and shared-schema coupling. Recommendation: (a). Risk if deferred: reissue abuse is only commercially controllable, not locally rate-limited.

## 11. Impact on the 1.0 → Full upgrade path

- The license tables are **shared** between 1.0 and the full line. This design adds no table/column, so nothing in 1.0 must be undone at upgrade; a 1.0 install with a valid document continues to verify in the full line under the same trusted ring.
- Migration rule: new migration numbers are decided in the **full line first** and copied byte-identical to 1.0; 1.0 never creates a migration absent from the full line. Because this design creates **no migration**, it is compatible with that rule. Any future schema stop item (§10) must follow the full-line-first numbering.
- Data-loss safety: the document, `install_id` and activation window persist across the upgrade; the offline activation path and the public-key ring are unchanged, so a 1.0 customer is never left without an activation route.
- Entitlements/update rights are signed claims (`LicenseSignature.php:107-117`); the full line can extend entitlement keys without a schema change, keeping unknown keys fail-closed (`EntitlementRegistry.php:49-53`).

## 12. Open owner decisions

- **D1. Supply the real release key pair (blocker).** Options: (a) generate outside the repo and put only the public key in `LicenseKeys`/`ReleaseKeys`, with the private key in custody per §3.2 (recommended); (b) ship 1.0 with no keys (current) — not acceptable because of §7.2. Recommendation: (a) before any 1.0 ZIP ships.
- **D2. ZIP distribution/update channel.** Options: public GitHub release / `<PRODUCT_DOMAIN>` download site / manual delivery (§4.3). Recommendation: manual for first customers, then a download page; treat GitHub release as optional.
- **D3. Private-key custody.** Options C1–C4 (§3.2). Recommendation: C1 + C3 (encrypted manager + offline paper backup).
- **D4. First-run window length and post-window behaviour.** Options O1–O4 (§7.3). Recommendation: O2/O3 with the safety rule always enforced.
- **D5. Online license server.** Options: (a) keep `license.server_url` empty and offline-only for 1.0 (recommended, lowest risk); (b) stand up the central service. This design does not implement the service.
- **D6. Domain binding strictness.** Options: (a) keep the signed `domain` claim and fail closed on mismatch (recommended, already implemented); (b) omit the claim (unbound). Recommendation: (a) for production documents.
- **D7. Test-key policy in the release build.** Options: (a) fail the build on any test key/placeholder (recommended, §3.5); (b) allow test keys in non-release builds only, with no build gate. Recommendation: (a).
- **D8. Is check-in of an existing appointment "existing care" or "new business"?** Options: (a) never block check-in of an appointment created before the restriction (recommended; matches the ADR-0023 §0 "existing appointments remain actionable" intent, but needs a code change); (b) keep the current blanket block for non-expiry restrictions. Recommendation: (a), because turning away a patient who already has a booked appointment is a care-safety risk.

---

## Appendix A — Verified code facts (OBSERVED)

| Fact | Location |
|---|---|
| Legacy public-key placeholder `REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64` | `clinic-practice-management/src/Domain/Licensing/LicenseKeys.php:23` |
| Trusted v2 ring is empty | `LicenseKeys.php:31` |
| Release-manifest key placeholder | `clinic-practice-management/src/Domain/Update/ReleaseKeys.php:16` |
| Canonical JSON (recursive ksort) + detached Ed25519 verify | `clinic-practice-management/src/Domain/Licensing/LicenseSignature.php:34-39,119-143` |
| `DOCUMENT_SCHEMA_VERSION = 2`; partial metadata fails closed | `LicenseSignature.php:22,56-67,97-106` |
| Fresh window 7 days / migration 30 days | `clinic-practice-management/src/Domain/Licensing/LicensePolicy.php:29-30` |
| Post-expiration new-service windows | `clinic-practice-management/src/Domain/Licensing/PostExpirationPolicy.php:20-26` |
| Blocked ops list | `clinic-practice-management/src/Domain/Licensing/SignedLicenseGate.php:15-23` |
| T0 rule and existing-care exemption | `SignedLicenseGate.php:47-64` |
| `install_id` must match; signature checked; then stored | `clinic-practice-management/src/Application/Licensing/LicenseService.php:318-339` |
| Offline activation entry point | `LicenseService.php:241-250` |
| Domain binding mismatch → `RESTRICTED` | `LicenseService.php:407-425` |
| Domain canonicalization (lower-case, strip `www.`) | `clinic-practice-management/src/Domain/Licensing/LicenseDomain.php:24-32` |
| State machine transitions and window clamp | `clinic-practice-management/src/Domain/Licensing/LicenseStateMachine.php:47-100,116-145` |
| `allowsNewBusiness` set | `clinic-practice-management/src/Domain/Licensing/LicenseStatus.php:81-92` |
| `license.server_url` default `''` | `clinic-practice-management/src/Settings/Settings.php:119` |
| HTTPS-only + SSRF guard + metadata allowlist | `clinic-practice-management/src/Infrastructure/Licensing/HttpVendorGateway.php:57-60,116-136` |
| Offline/online admin forms and handlers | `clinic-practice-management/src/Admin/SystemPage.php:240-255,395-420` |
| Persian status labels | `clinic-practice-management/src/Admin/SystemPage.php:1013-1030` |
| `licenseGate()` uses `SignedLicenseGate` (not `ActiveLicenseGate`) | `clinic-practice-management/src/Bootstrap/App.php:1981-1988` |
| `ActiveLicenseGate` is not wired into `src/` | `clinic-practice-management/src/Domain/Licensing/ActiveLicenseGate.php:14-30` (only its own file + a doc comment in `LicenseGate.php:18-19`) |
| Migration 0008 seeds install id + window (file is `2026_09_07_0008_licensing.php`, version `2026_09_07_0008`) | `clinic-practice-management/src/Migrations/2026_09_07_0008_licensing.php:40-89` |
| Release build has a content policy check, no key/placeholder gate | `clinic-practice-management/bin/build-release.sh:109-120` |
| Repository visibility is public | GitHub API `repos/bia2on2on/doctor-v1` → `private=false` (2026-11-17) |

Labels: `OBSERVED` = read at the cited line. `INFERENCE` = §7.2 reasoning and the upgrade compatibility argument. `UNKNOWN` = real PHP/WordPress runtime behaviour, real ZIP install, CI status (Actions may be off; CI status is not claimed). `LIMITATION` = the backup/restore design is absent from this repo's `main`, so restore-onto-new-install is an open interface (§5).

## Appendix B — Official sources

- libsodium public-key signatures (Ed25519, detached mode): <https://doc.libsodium.org/public-key_cryptography/public-key_signatures>
- PHP `sodium_crypto_sign_verify_detached()`: <https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php>
- PHP `random_bytes()` (install-id entropy): <https://www.php.net/manual/en/function.random-bytes.php>
- WordPress `admin_post_{action}` handler hook: <https://developer.wordpress.org/reference/hooks/admin_post_action/>
- WordPress nonces: <https://developer.wordpress.org/plugins/security/nonces/>
- WordPress HTTP API `wp_remote_post()`: <https://developer.wordpress.org/reference/functions/wp_remote_post/>
- GitHub Actions secrets (why CI custody is rejected): <https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions>
- GitHub releases (distribution channel option): <https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases>
- OWASP Key Management Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html>
- OWASP Cryptographic Storage Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html>

# License & Activation Design — CPMS 1.0 (Draft, docs-only, revision 2)

## خلاصهٔ فارسی

- این سند طراحیِ نظامِ مجوز و فعال‌سازیِ «Clinic Practice Management» (CPMS) نسخهٔ ۱٫۰ است؛ فقط سند است و هیچ کد، کلید، Migration یا تنظیمی تغییر نمی‌کند.
- مخزن عمومی است. کلید خصوصیِ امضا هرگز در مخزن، CI، افزونه، ZIP یا لاگ نیست؛ فقط کلید عمومیِ تأیید در افزونه قرار می‌گیرد. تولید و نگهداری کلید بیرون از مخزن است.
- ارزشِ این نظام قراردادی/تجاری است: امضا از جعل و تغییرِ «سند مجوز» جلوگیری می‌کند، نه از ویرایشِ کدِ افزونه (کد عمومی و خواندنی است).
- سند مجوز (`schema_version=2`) شامل `license_id`، `install_id`، دامنهٔ امضاشده، `entitlements`، `issued_at`، `expires_at` و `key_id` است؛ هیچ دادهٔ بیماری در آن نیست.
- فعال‌سازی آفلاین: مشتری `install_id` کامل و دامنهٔ متعارف را از صفحهٔ سلامت سیستم برمی‌دارد، برای مالک می‌فرستد، سندِ امضاشده را می‌گیرد و در همان صفحه الصاق می‌کند. فعال‌سازی آنلاین اختیاری است.
- **تصمیم‌های الزامیِ این نسخه:** (۱) ثبت‌نام/Check-in نوبتِ **موجود** در هیچ وضعیتِ مجوز مسدود نمی‌شود و برای آن تستِ الزامی در هر دو جهت لازم است؛ (۲) دروازهٔ Build انتشار **اجباری** است و معیارهای دقیقِ PASS، پیامِ دقیقِ شکست، مسئولِ اجرا و فهرستِ صریحِ شرایطِ توقفِ انتشار در ۳٫۵ آمده است؛ (۳) چهار کلاسِ واقعی برای امضا با گوشی (محیطِ ترمینال، شریک/دستگاهِ دوم، کلیدِ سخت‌افزاری آفلاین یا ماشینِ جدا، خدمتِ KMS ابری) در ۳٫۲ با هزینه، زمان، محدودیت، اثرِ ازدست‌رفتن و مصالحه و بازیابی ارزیابی شده‌اند؛ چون هیچ‌کدام هم‌زمان کاملاً امن و کارآمد نیست، هیچ‌کدام به‌صورتِ خاموش انتخاب نشده‌اند (S-2).
- **تصحیح‌های مهم نسبت به نسخهٔ ۱ (بر پایهٔ کد فعلی):** صفحهٔ سلامت سیستم فقط `install_id` **ماسک‌شده** را نشان می‌دهد و دامنه را نشان نمی‌دهد؛ فرمِ فعال‌سازی/تمدید در `ACTIVE`، `EXPIRING`، `GRACE`، `SUSPENDED` و `REVOKED` پنهان است — پس فعال‌سازیِ آفلاین امروز عملاً ممکن نیست و تغییرِ رابط کاربری پیش‌نیازِ اجراست (۸٫۱).
- سندِ منقضیِ امضاشده امروز در فعال‌سازی پذیرفته و ذخیره می‌شود (بررسیِ انقضا فقط مثبت‌بودن و ترتیب است، نه مقایسه با «اکنون»)؛ `issued_at` فقط Cast می‌شود. بررسیِ لازم در ۴٫۴ آمده است.
- عقب‌کشیدنِ ساعت، پنجرهٔ فعال‌سازی را **طولانی می‌کند**؛ ادعای نسخهٔ ۱ نادرست بود. راه‌حلِ پیشنهادیِ بدونِ Schema در ۶٫۵ آمده و نیازمند تأیید مالک است.
- در انقضای عادی (T0) رزروِ نوبتِ جدید حتی در `GRACE` هم مسدود می‌شود؛ پنجره‌های یک/شش‌ماهه فقط برای «ایجاد بیمار» و «Check-in مراجعهٔ بدون نوبت» هستند (۷٫۱).
- `visit.checkin` در فهرستِ مسدودشدنی‌ها هست، ولی امروز فقط در مسیرِ مراجعهٔ بدونِ نوبت بررسی می‌شود؛ Check-in نوبتِ موجود هیچ بررسیِ مجوزی ندارد و **نباید** بگیرد (۷٫۱ و ۷٫۳).
- ناسازگاریِ دامنه همیشه `binding_mismatch` نمی‌دهد؛ وضعیتِ شدیدترِ موجود حفظ می‌شود (۵).
- سندِ ذخیره‌شده در هر خواندنِ وضعیت دوباره امضا-سنجی نمی‌شود؛ اثرِ دست‌کاریِ دیتابیس و بازیابی روی نصبِ تازه در ۷٫۵ آمده است.
- Entitlement ناشناخته «بسته» است، ولی Limit ناشناخته «بی‌سقف» می‌شود؛ الزامِ طراحی در ۱۱ آمده است.
- تصمیم‌های بازِ مالک در بخش ۱۲ (کلیدِ واقعی، کانالِ توزیع، نگهداریِ کلید و روشِ امضا با گوشی، طولِ پنجره، سرورِ آنلاین، دامنه، دروازهٔ Build، و Check-in). هیچ‌کدام در این نسخه به‌صورتِ خاموش فرض نشده‌اند.

---

Status: **DRAFT for owner approval. No code, key, migration, settings, workflow or header change is included.** Revision 2 answers two independent reader BLOCKER reports on head `07fd09f3`; every finding was re-verified in this checkout before editing (Appendix A). **Addendum M1-M3 (owner decisions received) is incorporated in this same revision:** M1 makes check-in of an existing appointment a hard requirement with required tests; M2 documents the mandatory release-build gate's exact PASS criteria, exact failure message, runner and RELEASE-STOP conditions; M3 evaluates four phone-only signing classes and leaves the choice as STOP item S-2.

## 1. Goals, non-goals, honest threat model

### 1.1 Goals
- G1. A real, cryptographically verifiable license document for CPMS 1.0, upgradeable later to the full product line without data loss.
- G2. A commercial/contractual value model: the signed document is the license; the plugin verifies it locally.
- G3. Activation that works for a **phone-only owner** and a customer with no GitHub knowledge (offline first; online optional).
- G4. No path in which a 1.0 customer is left without an activation route.
- G5. Hard safety rule (H1): license state must never block clinical reads, existing appointments, **check-in of an existing appointment**, visit completion, payments on existing invoices, backup/export/restore, or patient OTP login.

### 1.2 Non-goals
- N1. Preventing edits of shipped plugin PHP. The repository and the ZIP are public/readable; anyone may fork and remove the gate.
- N2. Hiding PHI. The license payload carries no PHI (ADR-0028); this is a control-plane document.
- N3. Implementing a central license service, billing, activation counting, revocation UI, or update-plane changes. Those are outside this document (ADR-0023 §7 marks them FUTURE/OPEN).
- N4. Any schema change (see §10).

### 1.3 Honest threat model (public code base)
- **What the signature stops:** a forged or altered document. An attacker cannot mint a document that verifies under the trusted public-key ring, cannot change `expires_at`, `install_id`, `domain`, `entitlements`, `key_id`, and cannot reuse another installation's document where the signed claims are checked (`LicenseService.php:318-339`, `:407-425`).
- **What the signature does NOT stop:** editing the plugin code — all PHP is public, so an attacker can patch out the gate. No code secrecy is claimed. The economic value is commercial/contractual (a valid signed document is what the customer pays for; support, renewal, updates and entitlements ride on it).
- **Threats accepted:** local gate tampering; clock manipulation (§6.5, a real limitation); cloning a site to another `install_id`; mass document sharing. Mitigations are contractual + signed binding + (future) server-side activation counting, not obfuscation.
- **Secrets boundary:** only public verification keys ship. No secret, token, password or key material belongs in the repo, this PR, CI logs, the ZIP or this document (§3).
- **Known-unfixed-code note (no exploit detail):** the shipped public-key ring is empty and the legacy placeholder is invalid, so every activation attempt fails closed today (§7.2, D1). That is a release blocker, not a vulnerability to exploit.

### 1.4 Evidence labels
`OBSERVED` = read in this checkout at the cited `file:line`. `INFERENCE` = reasoned from OBSERVED code. `UNKNOWN` = not established here. `LIMITATION` = accepted, stated gap. See Appendix A.

## 2. License document

### 2.1 Fields (v2)
| Field | Type | Meaning / current check |
|---|---|---|
| `product` | string | Must equal `cpms` (`LicenseService.php:320`). |
| `schema_version` | int | Must be `2`; partial metadata pair fails closed (`LicenseSignature.php:22,56-67,97-106`). |
| `key_id` | string | Bounded ASCII id; must already exist in the trusted ring (`LicenseKeys.php:94-102,107-111`). |
| `license_id` | string | Commercial id; non-empty (`LicenseService.php:326,329`). |
| `install_id` | string | Local install identity; must match `cpms_license_install` at verification time (`LicenseService.php:323`). |
| `domain` | string, optional | Signed domain claim, canonicalized before compare (`LicenseService.php:407-425`, `LicenseDomain.php:24-32`). |
| `entitlements` | object | `{features:{name:bool}, limits:{name:int}}` (`EntitlementRegistry.php:29-47`). |
| `issued_at` | int | Epoch seconds. **Current check is weak:** cast with `(int)`; compared only as `expires_at < issued_at` when `issued_at > 0` (`LicenseService.php:328-329`). |
| `expires_at` | int | Epoch seconds; required and positive; **not compared with "now" at activation** (`LicenseService.php:327,329`). |
| `activation_id` | string, optional | Opaque activation-record id; bounded grammar (`LicenseSignature.php:107-112`). |
| `update_rights_until` | int, optional | Positive epoch seconds; update-plane right (`LicenseSignature.php:113-117`). |
| `revoked` / `suspended` / `reason` | bool/string | Commercial state, signed (`LicenseStateMachine.php:68-83`). |

No PHI, no patient data, no secrets, and no new fields are introduced by this design.

### 2.2 Canonical serialization and signature
- Reuse the existing verifier: `canonicalJson()` recursively `ksort`s and `json_encode`s with `JSON_UNESCAPED_UNICODE` (`LicenseSignature.php:34-39`); verification is `sodium_crypto_sign_verify_detached` over exactly that byte string with the selected Ed25519 public key (`:119-143`). Detached Ed25519 is the reference behaviour: <https://doc.libsodium.org/public-key_cryptography/public-key_signatures>; PHP binding: <https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php>.
- Fail-closed: if `sodium` is unavailable the document is "not verifiable" and activation is refused (`LicenseSignature.php:24-27,131-133`).
- Any issuer tooling MUST produce the canonical byte string from the same rules (sorted keys, unescaped Unicode, no whitespace) or verification fails; the document that the customer pastes is exactly the JSON the issuer signed.

### 2.3 Format versioning
- `schema_version` must be the integer `2`; any other value, or only one of `schema_version`/`key_id`, fails closed (`LicenseSignature.php:56-67,97-106`).
- Legacy (unversioned) documents keep the existing legacy path and never fall back from v2 (`LicenseSignature.php:75-89`). No `schema_version=3` is designed; `DOCUMENT_SCHEMA_VERSION = 2` stays unchanged.

## 3. Keys and custody

### 3.1 Generation outside the repository
- Generate an Ed25519 key pair once, on a device the owner controls, **outside** this repository and outside CI. Only the base64 public key is copied into the shipped `LicenseKeys` ring; the private key never enters the repo, the plugin, the database, the ZIP or any log.
- Current placeholders are intentionally invalid: `LicenseKeys.php:23` (`REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64`) and the empty ring `LicenseKeys.php:31` (`TRUSTED_PUBLIC_KEYS_B64 = []`). Release-manifest keys are a separate key (`ReleaseKeys.php:16`); do not reuse one key for both (ADR-0029 separation).

### 3.2 Phone-only signing and recovery — four evaluated workflows (M3)
Signing an Ed25519 document is a computation: it cannot be done by hand, in a chat app, or in a photo. The four candidate classes requested by the owner are evaluated below. **Nothing here is claimed to exist in this repository; no key material may be stored in the repo or CI; every UNKNOWN must be confirmed on the owner's own device before the first paid document.** "Loss" = the key becomes unusable; "compromise" = an attacker can sign.

| # | Workflow | Prerequisites | Money / time | Limits | Loss impact | Compromise impact | Key-loss recovery | Practicality (phone-only, no domain) |
|---|---|---|---|---|---|---|---|---|
| W1 | **Phone terminal environment** — Android Termux + PHP CLI (sodium) or Python/PyNaCl; iOS has no general terminal | Android phone; Termux from its official source; `php` with sodium or `python`+`pynacl`; the key file copied from the generation device; a short issuer script (not shipped here) | ≈ $0; setup 1-3 h, then ~5 min/document | No hardware isolation; the key is a readable file; clipboard handling; package availability on the exact device/OS is **UNKNOWN** | Phone loss/theft = key loss unless an offline backup exists; restore or rotate (§3.4) | Malware/foreground app that reads storage or the clipboard can exfiltrate the key; rotate immediately | Restore the key file from the offline backup; else new pair + ship new public key + reissue | **Medium** on Android; **LOW/UNKNOWN** on iOS |
| W2 | **Trusted technical partner or second device holding the key** | A person/device the owner trusts; a working signer on it; a safe channel for document + signature | Possible compensation; coordination per document | Key outside the owner's sole control; partner availability; the partner's device security | Partner's copy lost = rotate | Partner's device compromised or insider misuse = forged documents; rotate | Partner re-issues, or rotate + reissue | **High if a trusted partner exists**, but adds a third party and contradicts sole custody |
| W3 | **Offline hardware key or separate offline machine** — YubiKey PIV (firmware 5.7+, Ed25519 algorithm E0) or OpenPGP ed25519 (sign/auth only), or an air-gapped spare phone/laptop | A token that performs *raw* Ed25519 over arbitrary bytes (most FIDO2 keys do not) plus PKCS#11/OpenPGP tooling, or a spare air-gapped device with PHP/Python; OTG/NFC to the phone | Token ≈ $25-70 or spare device ≈ $50-150; setup 1-3 h | Key never leaves the device (good); not all tokens support arbitrary Ed25519; the phone needs OTG/NFC | Device loss = backup seed/second device or rotate | Very low: no exportable key; theft needs the PIN; rotate if the PIN is compromised | Backup seed/second device, or rotate + reissue | **Medium** (Android OTG/NFC); **UNKNOWN** on iOS; the air-gapped spare machine is the most practical variant |
| W4 | **Cloud key-management signing service** — Google Cloud KMS `EC_SIGN_ED25519` (PureEdDSA, raw data) and AWS KMS Ed25519 (`ECC_NIST_EDWARDS25519`) | A cloud account; a KMS key with an Ed25519 algorithm; owner-only IAM + MFA; a small signing call the owner approves interactively | ≈ $1-3/month per key + per-call cost; setup 1-2 h | Key is in the cloud, not the owner's sole custody; network dependency; provider lock-in; message pre-processing must match the canonical bytes | Provider account loss = provider account recovery, then rotate | Cloud-account compromise = forged documents; MFA and no CI secret reduce this; CI custody stays rejected | Re-create/rotate the KMS key and reissue | **Medium**; documented (Google Cloud KMS, AWS KMS) but third-party and network-dependent |

**Recommendation:** no workflow is both fully secure and trivially practical for a phone-only owner with no domain, so **none is chosen here**. W1 is cheapest and self-contained; W3 (air-gapped spare machine) is most secure; W4 is most ergonomic but adds a third party. The choice and the UNKNOWN confirmations are an explicit STOP item (§10, S-2) and remain owner decision D3. Every workflow requires at least one offline key copy and a written rotate-on-loss procedure (§3.4).

### 3.3 Explicitly rejected
- **Rejected:** storing the signing key in GitHub Actions secrets or any CI. In a public repository, fork pull requests and workflow logs are real exposure paths; a key that can sign license documents must not be reachable from CI. Reference: <https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions>.
- Rejected: repo files, `.env`, commits, issues/PRs, unencrypted cloud notes, email/chat, the plugin ZIP, the database, committed `wp-config.php`, and any "test key" left in shipped source.
- Test keys are generated only inside tests and must be rejected by the release build (§3.5).

### 3.4 Key id and rotation (F2)
- `key_id` is a bounded ASCII identifier, not key material: `is_valid_key_id()` requires a leading letter and `[A-Za-z0-9._-]*`, ≤64 chars (`LicenseKeys.php:107-111`; the file ends at `:112`). The trusted ring maps `key_id => base64 public key`, at most 32 entries, and a malformed or oversized ring is rejected **as a whole** (`LicenseKeys.php:33-34,62-89`). Selection is an exact lookup: an unknown or malformed `key_id` returns `null` and verification fails (`LicenseKeys.php:94-102`, `LicenseSignature.php:97-106`).
- **Correction (F2):** a document's `key_id` must **already be trusted by the installed plugin**. "A fresh `key_id` per document" is wrong and would make every such document unverifiable. Rotation order is therefore: (1) add the new public key under its new `key_id` to the ring and ship a plugin release; (2) only after customers can install that release, start issuing documents signed by the new private key; (3) keep the old public key until no still-installed version needs it; (4) remove it in a later release. Two keys can be trusted at once by design.
- Lost-key recovery: generate a new pair, add its public key to the ring, ship the release per the order above, then reissue documents to affected installs. The old private key cannot be reconstructed (only the public key ships); the old public key may stay for verification during the transition. If the ring must change **before** any release can be shipped, the affected installs are re-activated with a document signed by a key they already trust.

### 3.5 Release-build gate — MANDATORY, exact criteria (M2)
- Today `clinic-practice-management/bin/build-release.sh` runs a content-policy self-check (`:109-120`) but has **no** key/placeholder check (OBSERVED). The gate below is a build-policy change for a later PR; this document fixes its criteria.
- **Who runs it:** the owner, or a release operator the owner designates, on a machine the owner controls (or a CI job that holds **no** signing secret). The private signing key is never present in the build environment; the gate needs only the public source and the produced ZIP.
- **PASS criteria — every one must hold or the build stops:**
  1. `LicenseKeys::PRODUCTION_PUBLIC_B64` and `ReleaseKeys::PRODUCTION_PUBLIC_B64` contain no `REPLACE_AT_RELEASE` substring.
  2. The trusted ring `LicenseKeys::TRUSTED_PUBLIC_KEYS_B64` is non-empty (at least one real public key).
  3. Every ring `key_id` matches `^[A-Za-z][A-Za-z0-9._-]{0,63}$` and is unique (`LicenseKeys.php:107-111`).
  4. Every ring public key base64-decodes to exactly 32 bytes (`SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES`; length checked at `LicenseSignature.php:139`).
  5. No test key in shipped source: no test key id or value from the test fixtures appears (release source contains no `tests/` and no generated key material).
  6. ZIP content hash/manifest: `<name>-<version>-manifest.txt` (`build-release.sh:99`) lists exactly the files inside the ZIP, and the recorded `.sha256` (`:100-101`) equals the actual ZIP digest.
  7. Version consistency: the ZIP version, `CPMS_VERSION` in the main plugin file (`build-release.sh:23`), and the update-manifest version are identical.
  8. The existing content-policy self-check passes (`:109-120`: no forbidden files; required runtime SDK/autoload present).
- **Exact failure message:** `BUILD FAILED: license/release key gate: <CHECK> failed (<detail>)` on stderr, exit non-zero, where `<CHECK>` is one of `PLACEHOLDER`, `EMPTY_RING`, `KEY_ID_FORMAT`, `PUBKEY_LENGTH`, `TEST_KEY`, `MANIFEST_MISMATCH`, `VERSION_MISMATCH`, `CONTENT_POLICY`, and `<detail>` names the offending file/value. No ZIP is published on failure.
- **RELEASE-STOP conditions (any one stops the release):** a `REPLACE_AT_RELEASE` placeholder; an empty trusted ring; a missing/invalid real public key; a malformed or duplicate `key_id`; a wrong-length public key; any test key in shipped source; a manifest/hash mismatch; a version mismatch; a failed content-policy check. A stop is not overridable in CI; the owner fixes the source and rebuilds.

## 4. Activation flows and distribution channel

### 4.1 Offline flow (default) — with its current blockers and required UI prerequisites (F1)
Current state (OBSERVED): the license section renders `install_id_masked` only (`SystemPage.php:200`; the mask is `substr($install,0,8).'…'`, `LicenseService.php:198`), renders **no domain at all** (`SystemPage.php:192-237`), and renders the online/offline forms only for `NOT_CONFIGURED`, `ACTIVATION_PENDING`, `ACTIVATION_GRACE`, `UNREACHABLE`, `INVALID`, `RESTRICTED` (`SystemPage.php:238-257`). In `ACTIVE`, `EXPIRING`, `GRACE`, `SUSPENDED` and `REVOKED` the page instead prints "the document is valid" (`:258-264`). The POST handlers exist and do not check the state (`SystemPage.php:395-422`), but a customer cannot reach them from those states. **Therefore the offline flow as written in revision 1 was impossible: the customer cannot read the full install id or the domain, and a renewal cannot be submitted before or after expiry.**

Required UI prerequisites (implementation must precede or ship with the gate; see §8.1): show the **full** `install_id` (already produced by `LicenseService::statusMeta()` `:197`) with a copy action, show the **canonical domain the verifier will compute** (`LicenseService.php:380-388`, `LicenseDomain.php:24-32`) with a copy action, both visible to CPMS administrators only; and render the activation/offline form in **every** state so renewal is always possible.

Target flow once §8.1 is implemented:
1. Customer installs the ZIP and opens **CPMS → سلامت سیستم** → **«مجوز»**.
2. Customer copies the **install_id** and the **canonical domain** with the copy buttons (no PHI).
3. Customer sends both to the owner.
4. Owner signs a v2 document on a device per §3.2: `product=cpms`, `schema_version=2`, a `key_id` **already in the plugin's trusted ring** (§3.4), `license_id`, the customer's `install_id`, the canonical `domain`, `entitlements`, `issued_at`, `expires_at`.
5. Customer pastes the document JSON and the base64 signature into **«فعال‌سازی آفلاین/دستی (سند امضاشده)»** and submits (§8 steps).
6. Server verifies install match, signature and dates and stores the document (`LicenseService.php:241-250,318-339`); state becomes `ACTIVE` (or `EXPIRING`/`GRACE` near/after T0).

### 4.2 Optional online flow
- Only when `license.server_url` is set (`Settings.php:119`, default `''` = offline/manual). `HttpVendorGateway` enforces HTTPS, an SSRF guard, timeouts and a hard metadata allowlist (no PHI): `HttpVendorGateway.php:31-34,57-60,116-136`.
- The customer enters a license key; `activateWithKey` posts to `/activate` and stores the returned document through the same `verifyAndStore` path (`LicenseService.php:219-236`). The online path is a convenience, never a requirement.

### 4.3 ZIP distribution / update channel (OPEN owner decision)
| Option | Pros | Cons |
|---|---|---|
| Public GitHub release | Free; versioned; checksums possible | Requires GitHub knowledge; creating a release/tag needs separate owner approval |
| Download site at `<PRODUCT_DOMAIN>` | Familiar UX; no GitHub knowledge; can host license/support | Domain + hosting + TLS + update feed to operate |
| Manual delivery (email/messaging) | Simplest for a phone-only owner; no infra | No self-service; no automatic update notice; scaling cost |
Recommendation: manual/offline delivery for the first 1.0 customers, then a simple download page; a public GitHub release is one accepted channel, not the only one.

### 4.4 What activation must additionally enforce (F3)
Current check (OBSERVED `LicenseService.php:318-339`): `product` match, `install_id` match, `license_id` non-empty, `expires_at > 0`, and `expires_at >= issued_at` only when `issued_at > 0`; `issued_at`/`expires_at` are cast with `(int)`; **no comparison with "now"** — a correctly signed, already-expired document is accepted and stored, and a non-integer or negative `issued_at` skips the ordering rule entirely.
Required future check (implementation prerequisite, with tests in §9):
- A1. Reject `expires_at <= now + maxClockSkew` (already-expired document). Honest note: this **does** reject a retroactive document, so the owner must always issue a document whose `expires_at` is in the future; an install that currently holds an expired document is in the same restricted region either way, so nothing is lost that a valid renewal would not restore.
- A2. Reject non-integer or non-positive `issued_at`/`expires_at` (strict `is_int`, no silent cast) and `issued_at > now + maxClockSkew` (future-issued).
- A3. Keep `expires_at >= issued_at`; optionally reject documents whose lifetime exceeds an owner-set maximum.
- A4. Keep the signature check last and unchanged (`LicenseService.php:334-335`).
Rejections use the existing generic `CLINIC_LICENSE_INVALID` code and never disclose which field failed to the browser (it may be logged locally without values).

## 5. Domain binding

- Canonicalization is the single existing seam: lower-case and strip one leading `www.`; scheme/path/port and IDN/punycode are out of scope; never `HTTP_HOST` (`LicenseDomain.php:24-32`, `LicenseService.php:380-388`). The UI prerequisite in §8.1 must display exactly this canonical value.
- A mismatch turns a **permissive** outcome into `RESTRICTED`/`binding_mismatch`; restoring the bound domain restores the normal state with the document untouched.
- **Correction (F7):** the mismatch does **not** always win. `applyDomainBinding()` returns the computed outcome unchanged when it is already self-blocking and is not ordinary expiration (`LicenseService.php:415-417`, `isSelfBlocking()` `:432-441`). So `SUSPENDED`, `REVOKED`, `INVALID` and stale `UNREACHABLE` keep their own (more severe) state and reason; `ACTIVE`, `EXPIRING`, `GRACE` and `RESTRICTED`/`expired` can be converted to `binding_mismatch`.
- Staging/clones: a staging host is a different canonical domain and needs its own document, or the owner omits the `domain` claim (`LicenseService.php:408-410`). Clones share the source `install_id` only when the whole database is copied (§7.5).
- Domain change and re-binding: a reissue is a new signed document with a new `domain`; reissue counts and one-active enforcement are commercial/policy controls (future central service), not code. The signed `activation_id` claim is the intended hook (`LicenseSignature.php:107-112`).

## 6. Time, states and offline operation

- T0 = signed `expires_at`. The state machine yields `ACTIVE → EXPIRING → GRACE → RESTRICTED` (`LicenseStateMachine.php:47-100`); `EXPIRING` starts one expiry-grace before T0 (`:93-97`, `LicensePolicy.php:24`); `GRACE` is T0 + skew … T0 + grace + skew (`:85-91`).
- Grace does not move T0: the gate checks the signed `expires_at` **before** the status shortcut (`SignedLicenseGate.php:47-48`). Renewal simply supersedes the stored row; a renewed document with a later `expires_at` restores full function immediately, with no destructive local lock (ADR-0023 §0).
- Fully offline within the licensed period: no network call on ordinary page loads; the last verified document governs, and `UNREACHABLE` is distinct from `INVALID` (`LicenseStateMachine.php:71-75`). The offline document path is always available while the trusted public key is valid.
- **Clock rollback — corrected (F4).** Revision 1 claimed the window "is clamped so rollback cannot extend the window". That is **false**: `computeActivationWindow()` only clamps a *future* `started_at` to now (`LicenseStateMachine.php:126-127`), and nothing persists the highest time ever seen, so moving the system clock backwards moves `now` backwards and the fixed `started_at + window` end lies further away — the unlicensed window lengthens. The same rollback also postpones T0 evaluation by up to the skew tolerance (`LicensePolicy.php:28`, `LicenseStateMachine.php:85-91`).
- **Proposal P-AR (needs owner approval; no schema):** persist `max(observed now)` in a WordPress **option** (e.g. `cpms_license_last_seen`, written at most once per hour) and evaluate both the activation window and T0 against `effective_now = max(now, option)`. Pros: no table/column, works offline, removes the trivial rollback. Cons/limits: it is a **speed bump**, not DRM — anyone with database or filesystem write access can also edit the option or delete it, and a restore can move it backwards (§7.5). It must never block the H1 operations (§1.1) even when it detects a rollback: on detection the state becomes `needs_renewal`/notice, not a data lock.

## 7. Behaviour matrix, the 7-day window, and what the code actually does

### 7.1 Current behaviour (OBSERVED, corrected by F5/F6)
- Fresh install: `ACTIVATION_PENDING` for `activationWindowDays` (default 7) → `RESTRICTED` (`LicenseStateMachine.php:138-144`, `LicensePolicy.php:29`). Migration install: `ACTIVATION_GRACE` for 30 days → `RESTRICTED` (`LicenseStateMachine.php:129-135`, `LicensePolicy.php:30`).
- `allowsNewBusiness()` is true for `ACTIVE`, `EXPIRING`, `GRACE`, `NOT_CONFIGURED`, `ACTIVATION_PENDING`, `ACTIVATION_GRACE`, `DEVELOPMENT` (`LicenseStatus.php:81-92`).
- **At and after T0 the ordinary-expiry branch runs first** (`SignedLicenseGate.php:47-64`): `appointment.book`, `appointment.reschedule`, `sms.commercial`, `handwriting.new` are **denied immediately — including while the status is still `GRACE`** (`:49-51`); `patient.create` and `visit.checkin` are decided by `PostExpirationPolicy` (`:52-61`): a returning patient gets a 6-month window, otherwise only a staff `reception` call gets a 1-month window (`PostExpirationPolicy.php:20-26`); every other operation, including invoice creation, is allowed in that branch (`:62-63`). **Correction (F6):** revision 1's matrix showed `GRACE` as "allow everything" for new patients/bookings; that was wrong for bookings and for patient creation without reception.
- Outside that branch (status not `allowsNewBusiness`): `RESTRICTED` (non-expiry reasons), `SUSPENDED`, `REVOKED`, `INVALID`, stale `UNREACHABLE` deny exactly `BLOCKED_UNDER_RESTRICTION` (`SignedLicenseGate.php:15-23,65-81`).
- **Attribution correction (F5).** The blocked list contains `visit.checkin` (`SignedLicenseGate.php:19`), but the **only** assertion of that operation is the walk-in path — `VisitService.php:244-251`, with `reception => true`. Check-in of an **existing appointment** (`VisitService::checkIn()` `:99-212`) asserts **no** license operation at all, so it is not blocked today and must stay that way (M1, §7.3).
- `invoice.create` is in the blocked list but no `src/` caller asserts it (OBSERVED, unchanged from revision 1); the other six operations are asserted where cited (`BookingService.php:500,664,877,991,1014,1546,1719,1824,1934`; `PatientService.php:399,457`; `SmsService.php:97`; `HandwritingService.php:58`; `VisitService.php:245`).
- Reads/history/export, cancelling, visit completion and payments on existing invoices are not in the blocked set (`SignedLicenseGate.php:62-64,68-70`). Denials surface as `CLINIC_LICENSE_BLOCKED` (503) at the service boundary (`BookingService.php:2676-2683`, `PatientService.php:582`, `VisitService.php:1799`, `HandwritingService.php:59`).

### 7.2 What "no way out after 7 days" really means (corrected)
- With the shipped empty/invalid ring, every signature check fails (`LicenseKeys.php:23,31`; `LicenseSignature.php:103-106`), so `verifyAndStore` rejects every document (`LicenseService.php:334-335`): a fresh install reaches `RESTRICTED` after the window with no acceptable document — a commercial dead end (no new patients/bookings/walk-ins). It is **not** a clinical lock: reads, existing appointments, **check-in of an existing appointment**, visit completion, payments on existing invoices, backup/export/restore and OTP login stay available (§7.1, §7.3). The dead end is created by the placeholder keys, not by the gate; D1 removes it.
- **Correction (F5):** revision 1 wrote "REFUTED for the safety rule" while also implying check-in was covered by the gate. Neither is accurate as stated: today check-in of an existing appointment is not gated at all (so it is not blocked — by omission, not by design), while **walk-in** check-in *is* denied by the blocked list and by the post-expiration windows. The design therefore treats "existing-appointment check-in is never blocked" as a **requirement to be pinned by a test** (§7.3, T-L22), not as an accident of the current call graph.

### 7.3 Proposed policy (binding where marked M1)
- **H1 (never block):** reads of patient/medical data, existing appointments and their reads/cancellations, **check-in and reception admission of an appointment that already existed before the restriction**, visit completion, payments on existing invoices, backup/export/restore, OTP login of existing patients, and the activation/renewal form itself.
- **M1 (DECIDED — mandatory, no owner option):** check-in and reception admission of an existing appointment are never blocked in **any** license state, including `ACTIVATION_PENDING` expiry, `RESTRICTED` (expiry or binding), `SUSPENDED`, `REVOKED`, `INVALID` and stale `UNREACHABLE`. Implementation rules: no `LicenseGate::OP_VISIT_CHECKIN` assertion may be added to `VisitService::checkIn()` `:99-212`; the blocked-list entry applies **only** to walk-in creation (`VisitService.php:244-251`), and the build must carry a test asserting both directions (T-L22/T-L23). This supersedes the blocking option of revision 1's D8.
- New patients, new bookings/reschedules, new invoices, commercial SMS and new handwriting remain blockable, with the windows of §7.1 for the first-run window.
- First-run window length and post-window behaviour remain owner decisions O1-O4 (D4), but **any** option must keep H1 and M1.

### 7.4 Matrix — current code (C) vs this design's target (T)
"Existing appt" = created before the restriction began. New-patient/booking cells show C → T where they differ.

| State | New booking/reschedule | New patient | Walk-in check-in | **Existing-appt check-in** | Complete visit | Payments | Reads/export | Backup/restore | OTP login |
|---|---|---|---|---|---|---|---|---|---|
| `ACTIVE`, `EXPIRING` (pre-T0) | allow | allow | allow | allow | allow | allow | allow | allow | allow |
| `GRACE` / `RESTRICTED` reason `expired` (T0 or later) | **C: deny** (also in `GRACE`); T: deny | C: 1-month window with `reception`, 6-month for returning patients, else deny; T: same | C: 1-month window (`reception`), else deny; T: same | **allow (M1)** | allow | allow | allow | allow | allow |
| `RESTRICTED` activation-window / `binding_mismatch` | deny | deny | deny | **allow (M1)** — C already allows because it asserts nothing; T pins it | allow | allow | allow | allow | allow |
| `ACTIVATION_PENDING` / `ACTIVATION_GRACE` (in window) | allow | allow | allow | allow | allow | allow | allow | allow | allow |
| `SUSPENDED` / `REVOKED` / `INVALID` / stale `UNREACHABLE` | deny | deny | deny | **allow (M1)**; C: not asserted | allow | allow | allow | allow | allow |

Reading of the table: the only differences between C and T are (i) bookings/patient creation shown honestly for `GRACE`, and (ii) M1 pinning existing-appointment check-in with a test. Nothing in T reduces access relative to C except the document checks of §4.4, which only reject documents that are already unusable.

### 7.5 Stored payload trust, database tampering, and restore (F8)
- **OBSERVED:** `currentState()` reads `payload_json` from `cpms_license_state`, json-decodes it, and computes the state machine from it (`LicenseService.php:74-82`, `decodePayload()` `:359-364`). The signature is **not** re-verified on this path, and `install_id`/`domain` are only checked inside `verifyAndStore()` at activation (`:323`, `:407-425`). Consequences, stated honestly: (a) anyone who can write `cpms_license_state.payload_json` can change `expires_at`, `revoked`, `suspended` or `entitlements` and the state follows — i.e. the local gate trusts the database row, not only the signature; (b) a database restored onto a **new** install keeps the old `install_id` in the row until a refresh, so the document keeps being honoured locally even though the current install id differs.
- **Required design behaviour (implementation prerequisite):** re-verify the stored document (signature + `install_id` + `domain`) at least once per request, caching the result for that request only; on failure the state becomes `INVALID` with the generic reason, H1/M1 stay in force (nothing clinical is locked), and the UI shows "activation/renewal required". Optional: compare against a last-known-good `verified_at`.
- **Restore onto a new install:** the backup/restore design is **not** in this repository's `main` (LIMITATION). Until it lands, treat it as an open interface and decide explicitly (owner, with that design) whether a restore preserves the licence row: (i) preserve + re-bind via a new document (recommended; the customer re-activates once), or (ii) let the restore seed a new `install_id` and require a fresh document. This design performs no schema change and adds no restore hook; §10 lists it as a stop item rather than an invented mechanism.

## 8. Admin UX (Persian, words only)

### 8.1 Implementation prerequisites (MUST ship with, or before, the gate)
- P-UI1. Show the **full** `install_id` with a copy button, visible only to CPMS administrators. The value already exists in `LicenseService::statusMeta()` `:197`; the page currently renders only the masked form (`SystemPage.php:200`).
- P-UI2. Show the **canonical domain** the verifier computes, with a copy button, same audience (`LicenseService.php:380-388`). The page currently shows no domain at all (`SystemPage.php:192-237`).
- P-UI3. Render the online key form **and** the offline document form in **every** license state, so renewal works before and after T0: today the forms render only for `NOT_CONFIGURED`, `ACTIVATION_PENDING`, `ACTIVATION_GRACE`, `UNREACHABLE`, `INVALID`, `RESTRICTED` (`SystemPage.php:238-257`) and are replaced by a "document is valid" paragraph in `ACTIVE`, `EXPIRING`, `GRACE`, `SUSPENDED`, `REVOKED` (`:258-264`).
- P-UI4. The status notice (`LicenseStatusNotice.php:13-34`) must always include the path to that form, in `GRACE` and in restricted states.
- P-UI5. Neither value is PHI: the install id is random and the domain is public. Both must be escaped and `dir="ltr"` as today.
- P-UI6. The renewal path must never depend on the online server (`license.server_url` may be empty).

### 8.2 Status page and steps
- Status page: **CPMS → سلامت سیستم**, section **«مجوز»**: status + reason, install id (masked today; full per P-UI1), licence id, activation-window label, expiry in Jalali/UTC, last refresh (`SystemPage.php:192-237`, labels `:1013-1030`).
- Excluding the current UI gaps above, the existing forms and POST handlers already exist (`SystemPage.php:240-257`, handlers `:395-422`).
- Exact Persian steps for offline activation (after P-UI1..P-UI6):
  1. وارد wp-admin شوید و از منوی **CPMS** وارد **«سلامت سیستم»** شوید.
  2. در بخش **«مجوز»**، دکمهٔ **«کپی install_id»** و **«کپی دامنه»** را بزنید.
  3. این دو مقدار را برای مالک محصول بفرستید (بدون هیچ دادهٔ بیماری).
  4. سند مجوز و امضای base64 را از مالک دریافت کنید.
  5. در همان صفحه، **«فعال‌سازی آفلاین/دستی (سند امضاشده)»** را باز کنید (در همهٔ وضعیت‌ها، از جمله «فعال» و «تمدید لازم است»).
  6. متن سند را در کادر سند و امضا را در کادر امضا الصاق کنید و **«فعال‌سازی با سند»** را بزنید.
  7. در صورت موفقیت وضعیت **«✅ فعال»** نمایش داده می‌شود؛ در صورت شکست پیام خطای عمومی (مثلاً «سند نامعتبر») ظاهر می‌شود.
- Notices: keep the existing Persian `LicenseStatusNotice` texts for grace/restricted/invalid and add the first-run banner with the deadline and the two copy values. No code is included in this PR.

## 9. Test plan (to be implemented with the code change)

- Unit: canonical JSON stability; signature verify/accept/reject; `key_id` grammar and unknown-id rejection; install match; date validation; `LicensePolicy` maths; `LicenseDomain` canonicalization; state-machine transitions; activation-window computation.
- **M1 (required, both directions): T-L22** in **every** state of §7.4 — explicitly `GRACE`, `RESTRICTED` (expiry and `binding_mismatch`), `SUSPENDED`, `REVOKED`, `INVALID` and stale `UNREACHABLE` — check-in of a pre-existing appointment succeeds and creates exactly one visit (no `CLINIC_LICENSE_BLOCKED`); **T-L23** walk-in creation may be denied in the restricted states and the denial is a 503 envelope.
- `GRACE`: a booking is denied at T0 even while the status is still `GRACE`; a returning patient's creation succeeds inside the 6-month window, a `reception` creation inside the 1-month window, and neither succeeds after it (T-L24).
- Activation dates (A1-A4): an already-expired but correctly signed document is rejected; a future-issued document is rejected; non-integer/negative `issued_at` is rejected; a lifetime over the owner maximum is rejected; a valid future-dated document is accepted (T-L25).
- Rotation: two trusted keys coexist; a document whose `key_id` is not in the ring is rejected; removing an old key fails documents that need it (T-L26).
- Clock rollback: with the P-AR option present, a 30-day rollback does not extend the activation window; H1/M1 operations still succeed when a rollback is detected (T-L27). Without P-AR the test asserts the **documented limitation**, not anti-rollback.
- Tampering (F8): editing `payload_json` so `expires_at` moves forward is detected at the next read and yields `INVALID` with H1/M1 intact (T-L28); a restored row whose `install_id` differs from the current install is detected (T-L29).
- Domain: a wrong-domain document yields `RESTRICTED`/`binding_mismatch` when the outcome was permissive, and a `SUSPENDED` document keeps `SUSPENDED` (F7, T-L30).
- Entitlements: an unknown feature is off; an unknown limit can never raise a known limit; a missing known limit takes its code default, not "unlimited" (T-L31).
- Release-build gate: the build fails on `REPLACE_AT_RELEASE`, on an empty ring, and on a test key in shipped source (M2, T-L32).
- Offline: a valid document with `license.server_url` empty activates and operates with no network. Real ZIP install on a clean WordPress, end to end (UNKNOWN until executed).

## 10. Schema

- **Prefer NONE.** The design reuses `cpms_license_install` and `cpms_license_state` (`2026_09_07_0008_licensing.php:46-72`) and adds no column, table or migration.
- **Proposal P-AR (no schema):** one WordPress **option** holds the last-seen high-water mark (§6.5). Options are not tables or columns; it still needs owner approval because it is a new persisted artifact.
- If a future need appears (reissue/rebind history, restore hook), do **not** design it here:
  - **STOPPED - OWNER DECISION NEEDED (S-1).** Question: does the license feature need persisted reissue/rebind state, or a restore hook that re-binds `install_id`? Options: (a) no schema — signed document + owner records + re-activation after a restore (recommended); (b) add a column/table in the full line first and copy the migration byte-identically to 1.0. Pros/cons: (a) zero migration risk, no shared-schema divergence; (b) enables local abuse counters and automatic re-bind, at the cost of a migration and shared-schema coupling. Recommendation: (a). Risk if deferred: reissue abuse is only commercially controllable, and a restored site needs one manual re-activation.
  - **STOPPED - OWNER DECISION NEEDED (S-2, M3).** Question: which phone-only signing workflow (W1-W4, §3.2) should the owner standardize, and does the owner accept its UNKNOWN items? Options: (a) **W1** phone terminal (Android) — cheapest and self-contained, medium practicality; (b) **W2** trusted partner/second device — practical but adds a third party and contradicts sole custody; (c) **W3** offline hardware key or air-gapped spare machine — most secure, medium practicality; (d) **W4** cloud KMS (Google Cloud KMS / AWS KMS Ed25519) — ergonomic but third-party and network-dependent. Recommendation: prove **W1** end-to-end for the first paid documents, target **W3** (air-gapped spare machine) for the long term, and keep at least one offline key copy; do not standardize W2/W4 without explicit risk acceptance. Pros/cons: see §3.2. Risk if deferred: no signing path is approved, so D1 cannot be completed and 1.0 cannot ship a verifiable document.

## 11. Impact on the 1.0 → Full upgrade path

- The license tables are **shared** between 1.0 and the full line. This design adds no table/column, so nothing in 1.0 must be undone at upgrade; a 1.0 install with a valid document continues to verify in the full line under the same ring.
- Migration rule: new migration numbers are decided in the **full line first** and copied byte-identical to 1.0; 1.0 never creates a migration absent from the full line. This design creates **no** migration, so it is compatible by construction.
- Data-loss safety: the document, `install_id` and activation window persist across the upgrade; the offline path and the public-key ring are unchanged, so a 1.0 customer always keeps an activation route.
- **Entitlements — corrected (F9).** Today `hasFeature()` fails closed for an unknown/absent feature (`EntitlementRegistry.php:49-53`), but `limitOf()` returns `null` for an unknown or absent limit and `allowsCreation()` then returns **true** (`:58-76`) — an unknown limit is silently *unlimited*. **Design requirement:** the set of limit keys is a closed allowlist defined in code with an explicit default per key; a document may only *narrow* those defaults; unknown keys are ignored (never treated as a grant) and logged; a missing known key takes its code default (never "unlimited"). The same rule applies to features: a document can only enable features the code knows (`LicenseSignature.php:107-117` remains the extension hook).

## 12. Open owner decisions

- **D1. Supply the real release key pair (BLOCKER, unchanged).** Options: (a) generate outside the repo and place only the public key in `LicenseKeys`/`ReleaseKeys`, private key in custody per §3.2 (recommended); (b) ship 1.0 with no keys (current) — not acceptable (§7.2). Recommendation: (a) before any 1.0 ZIP ships.
- **D2. ZIP distribution/update channel** (§4.3): public GitHub release / `<PRODUCT_DOMAIN>` download site / manual delivery. Recommendation: manual first, then a download page; GitHub release optional.
- **D3. Private-key custody AND phone-only signing workflow (M3).** Options are the four workflows **W1-W4** (§3.2), plus at least one offline key copy. Recommendation: prove **W1** end-to-end first, target **W3** (air-gapped spare machine); the choice and its UNKNOWN confirmations are STOP item **S-2** (§10). **Owner decision required** — no workflow is both fully secure and trivially practical, so none is chosen by default.
- **D4. First-run window length and post-window behaviour.** Options O1 (7-day, hard restrict — current), O2 (14-day, new-business-only restriction), O3 (30-day, same), O4 (no time limit). Every option must keep H1 and **M1**. Recommendation: O2/O3.
- **D5. Online license server.** Options: (a) keep `license.server_url` empty and stay offline-only for 1.0 (recommended); (b) stand up the central service. Not implemented here; it is independent of the W1-W4 signing choice.
- **D6. Domain binding strictness.** Options: (a) keep the signed `domain` claim and fail closed on mismatch (recommended, already implemented); (b) omit the claim (unbound).
- **D7. Test-key policy in the release build — DECIDED by M2.** The gate is mandatory, and its exact PASS criteria, exact failure message, runner and RELEASE-STOP conditions are fixed in §3.5. No "no gate" option and no open message wording remains; the owner only confirms the gate before the first release.
- **D8. Existing-appointment check-in — DECIDED by M1.** Never blocked in any license state, pinned by T-L22/T-L23; revision 1's blocking option (b) is withdrawn. The only open part is wording of the UI notice when a block affects walk-ins.

---

## Appendix A — Verified code facts (OBSERVED at `c9771403`; revisions marked)

| Fact | Location |
|---|---|
| Legacy public-key placeholder `REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64` | `LicenseKeys.php:23` |
| Trusted v2 ring empty; ≤32 keys; whole-ring rejection | `LicenseKeys.php:31,33-34,62-89` |
| Exact `key_id` lookup; grammar `[A-Za-z][A-Za-z0-9._-]*` ≤64; **file ends at `:112` (F10)** | `LicenseKeys.php:94-102,107-111` |
| Release-manifest key placeholder | `src/Domain/Update/ReleaseKeys.php:16` |
| Canonical JSON + detached Ed25519 verify; schema 2; partial metadata fails closed | `LicenseSignature.php:22,34-39,56-67,97-106,119-143` |
| Activation checks: product, install, ids, positive/ordered dates only — **no "now" comparison; `(int)` casts (F3)** | `LicenseService.php:318-339` |
| State read trusts the stored payload; no signature re-verification (F8) | `LicenseService.php:74-82,359-364` |
| Masked install id; full id available in `statusMeta()` but not rendered (F1) | `LicenseService.php:197-198`; `SystemPage.php:200` |
| Activation/offline forms rendered only for six non-`ACTIVE` states (F1) | `SystemPage.php:238-264` |
| Forms/POST handlers exist and do not check state | `SystemPage.php:240-257,395-422` |
| Domain mismatch keeps an already self-blocking state (F7) | `LicenseService.php:407-425,432-441` |
| Blocked ops list incl. `visit.checkin`; T0 branch; windows | `SignedLicenseGate.php:15-23,47-64` |
| Existing-appointment check-in asserts no license op (F5) | `VisitService.php:99-212` |
| Walk-in check-in asserts `visit.checkin` with `reception => true` (F5) | `VisitService.php:244-251` |
| Post-expiration windows: 6 months returning / 1 month `reception` (F6) | `PostExpirationPolicy.php:20-26` |
| `allowsNewBusiness` set (incl. `GRACE`) | `LicenseStatus.php:81-92` |
| Activation-window maths; **future-only clamp, no anti-rollback persistence (F4)** | `LicenseStateMachine.php:116-145` (clamp `:126-127`) |
| `GRACE`/`EXPIRING` boundaries and skew | `LicenseStateMachine.php:85-97`, `LicensePolicy.php:24,28` |
| Fresh 7 / migration 30 days | `LicensePolicy.php:29-30` |
| Unknown feature closed; unknown/absent limit unlimited (F9) | `EntitlementRegistry.php:49-53,58-76` |
| `licenseGate()` wires `SignedLicenseGate` (not `ActiveLicenseGate`) | `App.php:1979-1988` |
| `ActiveLicenseGate` unused in `src/` | `ActiveLicenseGate.php:14-30`; `LicenseGate.php:18-19` |
| `license.server_url` default `''` | `Settings.php:119` |
| HTTPS-only + SSRF guard + metadata allowlist | `HttpVendorGateway.php:31-34,57-60,116-136` |
| License tables (install + state) | `2026_09_07_0008_licensing.php:40-72` |
| Build self-check exists; **no key/placeholder gate** | `bin/build-release.sh:109-120` |
| Build already emits manifest + `.sha256` and derives version from `CPMS_VERSION` | `bin/build-release.sh:23,99-101` |
| Service-boundary denial envelope `CLINIC_LICENSE_BLOCKED` 503 | `BookingService.php:2676-2683`; `PatientService.php:582`; `VisitService.php:1799`; `HandwritingService.php:59` |

Labels: `OBSERVED` = read at the cited line in this checkout. `INFERENCE` = §7.2 reasoning and the upgrade-compatibility argument. `UNKNOWN` = real PHP/WordPress runtime behaviour, a real ZIP install, phone tooling feasibility (W1-W4), CI status (Actions may be off; not claimed). `LIMITATION` = no anti-rollback without P-AR; the stored payload is trusted at read (until the §7.5 change); the backup/restore design is absent from this repo's `main`, so restore-onto-new-install stays an open interface.

## Appendix B — Official sources

- libsodium public-key signatures (Ed25519, detached): <https://doc.libsodium.org/public-key_cryptography/public-key_signatures>
- PHP `sodium_crypto_sign_verify_detached()`: <https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php>
- PHP `random_bytes()` (install-id entropy): <https://www.php.net/manual/en/function.random-bytes.php>
- WordPress `admin_post_{action}`: <https://developer.wordpress.org/reference/hooks/admin_post_action/>
- WordPress nonces: <https://developer.wordpress.org/plugins/security/nonces/>
- GitHub Actions secrets (why CI custody is rejected): <https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions>
- GitHub releases (distribution option): <https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases>
- OWASP Key Management Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html>
- OWASP Cryptographic Storage Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html>
- Termux (phone terminal environment, W1): <https://termux.dev>
- PyNaCl (Python Ed25519, W1): <https://pynacl.readthedocs.io/en/latest/>
- YubiKey PIV Ed25519 support (W3): <https://developers.yubico.com/PIV/Introduction/YubiKey_and_PIV.html>
- YubiKey OpenPGP ed25519 (sign/auth only, W3): <https://docs.yubico.com/hardware/yubikey/yk-tech-manual/yk5-apps-openpgp.html>
- Google Cloud KMS Ed25519 `EC_SIGN_ED25519` (W4): <https://docs.cloud.google.com/kms/docs/algorithms>
- AWS KMS Ed25519 key spec (W4): <https://docs.aws.amazon.com/kms/latest/developerguide/supported-algorithms.html>

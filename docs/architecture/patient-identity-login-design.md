# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 11)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
> Merging does **not** authorise a build; it starts only after this PR is merged and two independent readers pass. `cpms_patient_logins` is **owner-approved**; **any other new table, or any column outside that table, needs separate owner approval — stop and ask.** No code, migration, test or workflow here.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` |
| **Prior head** | `131c42f564d33986f3a9cc14843abff91f9a5923` (revision 10) |
| **Compared with** | revision 9 = `e04d318eb6f1580ccd9f2194c5ab1654391b5147` (`arena/2283a2f6-doctor`) |
| **Labels** | **OBSERVED** read in code at this commit, not executed · **INFERENCE** · **UNKNOWN** · **LIMITATION** · **DECIDED** · **DEBT**. Working assumptions pending owner: **OQ-D8**, **OQ-D9** |
| **Not executed** | No PHP/MySQL/WordPress/browser run; PHP gates NOT APPLICABLE (docs-only). |

## Revision 11 changes

| # | Change |
|---|---|
| **R1** | **Proof is per session, not per row (F1).** An OTP login opens an **account-only** session. Every access to a patient's data needs **both** proofs **in that same session**: an SMS code for that record's mobile, and the record's NID, both given in that session. The gate reads **session proof flags** (§2.7), not a database row and not "the login method". A recycled SIM therefore gets an account with no clinical data. |
| **R2** | **NID out of every response (F2, F3).** Patient-facing views never contain the raw NID; `national_id` leaves `ME_EDITABLE`, so the duplicate-NID branch is unreachable from the patient side and its wording never becomes an oracle. Staff keep masked NID with a permission-gated reveal — an **API contract change needing separate owner approval at build time**. |
| **R3** | **One writer, four artefacts (F4).** §5 names `PatientBindingPromoter` and the exact promotion moment: one depth-0 transaction writes the account state, the binding row, `cpms_patients.identity_id`, `cpms_patient_identity_links` **and** `cpms_patient_user_links` — the last one is what the selectors JOIN, so a binding without it is inert. The false claim "no identity merge is possible" is withdrawn (§2.3). |
| **R4** | **Correction closes bindings (F5).** Changing a record's NID or mobile, merging or deactivating it, closes every binding of that record in the same transaction. |
| **R5** | **Nothing is deleted (F6).** The 14-day sweep closes and scrubs, never deletes: accounts, WP users and rows survive; deleting is only an owner **option**. |
| **R6** | **No generated columns (F7).** Two plain nullable key columns with `UNIQUE` indexes replace both generated keys, because the dumper inserts every column it finds. Engine NULL semantics cited (§1). |
| **R7** | **Citations and claims corrected (F8)**: OTP token read/lock, the identity↔user edge (never written today), the audit diff path, the recycled-number row of §14, "no path deletes an account". |
| **R8** | **Consistency (F9)**: link-record is reachable with **no** binding (§2.5/§12), its route ships in **P6** (§6.2/§11), OQ-D8 and OQ-D9 are "working assumption, pending owner". |
| **R9** | **One failure envelope (F10)** for every proof failure — status and body shape identical, one mapper (§6.10). |
| **R10** | **Lost decisions restored (F11)**: OQ-D2 restored, OQ-D4 recorded as removed with its reason, OQ-D3/D5/D6/D7 dispositioned (§16). |
| **R11** | Kept at 320 lines or fewer; every cited line re-opened in this checkout (Appendix A). |

## 1. Data model (D1)

One table, `{wp_prefix}cpms_patient_logins`, **two row kinds**: an *account* row is one WP user's login identity; a *binding* row is one permission of that account over one record in one clinic. **All types declared; no generated columns (R6).**

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | no | PK |
| `kind` | `ENUM('account','binding')` | no | every predicate names the kind it reads |
| `wp_user_id` | `BIGINT UNSIGNED` | no | the owning account |
| `mobile` | `CHAR(11)` | **yes** | account rows only, normalised `09xxxxxxxxx`; NULL on bindings; set to NULL when a never-proved account is retired (§7.5) |
| `state` | `ENUM('pending','active','closed')` | no | §5 |
| `clinic_id`, `patient_id` | `BIGINT UNSIGNED` | **yes** | binding rows only; NULL on account rows |
| `verified_at` | `DATETIME(3)` | **yes** | binding rows only; the proof moment (§5) |
| `verification_method` | `ENUM('two_factor')` | **yes** | one legal value; no staff value exists |
| `live_key` | `VARCHAR(40)` | **yes** | application-maintained: `CONCAT(clinic_id,':',patient_id)` when `kind='binding' AND state='active'`, otherwise NULL |
| `account_key` | `BIGINT UNSIGNED` | **yes** | application-maintained: `wp_user_id` on `kind='account'` rows, otherwise NULL |
| `given_name`, `family_name` | `VARCHAR(120)` | **yes** | pending accounts only; scrubbed (§7.5) |
| `created_at`, `updated_at` | `DATETIME(3)` | no | UTC |

**No `national_id` column** (M4): the NID lives only in a request body for the duration of a proof and is never persisted, logged or echoed.

| Key | Definition | Invariant |
|---|---|---|
| `u_pl_mobile` | `UNIQUE (mobile)` | one account per mobile; binding rows are NULL, and **a UNIQUE index permits multiple NULLs** (`dev.mysql.com/doc/refman/8.4/en/create-index.html`, "Unique Indexes"; `mariadb.com/docs/server/reference/data-types/null-values`, "Primary Keys and UNIQUE Indexes") |
| `u_pl_bind` | `UNIQUE (wp_user_id, clinic_id, patient_id)` | one binding row per account+clinic+record, closed rows included, so re-proof reactivates rather than inserts; account rows are (NULL, NULL) and never collide |
| `u_pl_live` | `UNIQUE (live_key)` | **at most one ACTIVE binding per (clinic, patient) across all accounts** — the DB half of "one live account per record"; closed rows hold NULL and are unlimited |
| `u_pl_account` | `UNIQUE (account_key)` | exactly one account row per account; bindings hold NULL |

Plus `KEY (kind, state, wp_user_id)` for the gate read. **Why not generated columns (R6):** the backup dumper reads `SHOW COLUMNS FROM` (`BackupSqlDumper.php:146-156`) and writes **every** column it finds into the `INSERT` (`:178-196`), so a generated column would be re-inserted into a restored table and fail; a plain column that the application keeps correct is dumped and restored like any other. **Restore** sets `state='closed'`, `live_key=NULL`, then recomputes `live_key` (all NULL after a restore) and `account_key` from `wp_user_id` (§7.3). **UNKNOWN:** not executed on MariaDB 11.4.13 or MySQL 8 — the build must run a dump→restore round-trip test on both (T-L26). "One patient per mobile per clinic" is still `u_pat_mobile (clinic_id, mobile)` on the record (`0001:82`), not a key here.

## 2. Access model

### 2.1 One gate, two proofs
`PatientAccess::level(int $wp_user_id, ?int $patient_id = null, ?int $clinic_id = null)` → `none` | `booking` | `full`, recomputed on every request (never a cookie value; the cookie only carries the WP session token). **`none` for every patient object** unless **all** of these hold: (1) an account row `state='active'`; (2) a **binding** row `state='active'` with non-NULL `verified_at` for `(wp_user_id, clinic_id, patient_id)`; (3) a **binding proof** in **this** session, written by a matching NID, **and** a **mobile proof** in this session for that record's mobile (§2.7); (4) for `full` only, the two edges of §2.4. `booking` = (1)+(2)+(3) without the edges. **No predicate grants on `mobile` alone**: a number opens a session; a record needs the NID proof as well.

### 2.2 Recount (method unchanged since revision 5)
137 `register_rest_route` sites / 18 controllers, every `permission_callback` callee resolved ⇒ 153 runtime routes; **18 patient-reachable sites / 20 runtime routes** (loop expansion: `DoctorPortalController.php:82-91`, `QueueController.php:217-225`, `PatientController.php:64-93`, `FilesController.php:72-95`). A staff-role user is never patient-only (`Admin/PatientPortalPage.php:92-111`, `:116-124`) nor eligible for SMS login.

### 2.3 Existing edges are NON-PROOF (corrected, F8)
`cpms_patient_user_links` (Clinic-scoped, written by `PatientService::create_record` `:461-464` and by staff), `cpms_patients.identity_id` and `cpms_patient_identity_links` grant no record access by themselves and are never upgraded by registration, booking, reschedule or identity reuse. **Correction:** revision 10 said the identity↔user edge is written on every create — it is not. `PatientIdentityActivator::activate_for_new_patient()` `:77-107` writes **only** the clinical edge (`:101`) and no user link; `PatientIdentityService::link_user()` `:248-280` does write `cpms_patient_identity_links`, but **it has no caller in `src/`** (OBSERVED: `grep -rn "link_user(" src` returns only its own definition and one docblock), so today that table is written by nothing in the plugin, and `clinical_access_identity_predicate()` `:261-269` admits identity-bearing records only through the dead EXISTS branch. `PatientRepository::clinical_access_identity_predicate()` is the hole: it admits `p.identity_id IS NULL` **or** a matching identity link, and it is used by `active_linked_records_for_user` `:85-99`, `user_has_clinical_access_to_patient` `:140-157` (a boolean access check, not a selector), `active_linked_patients_for_user` `:164-177` and `find_active_linked_patient_by_link` `:182-195` (call sites `:95`, `:151`, `:173`, `:191`). **Identity reuse is real, and "no identity merge is possible" is withdrawn:** `activate_for_new_patient()` reuses an identity whenever the caller passes a `verified_wp_user_id` that has exactly one identity link in that Organization (`:93-94`, `resolve_verified_identity()` `:156-176`, exactly-one rule `:161-165`, Organization re-check `:170-173`), so two records of one Organization **can** point at one identity. **The real guard** is therefore threefold: the record's NID must equal the NID proved for *that record* (two proofs, §6); the identity must belong to the same Organization (`:170-173`); and P3 replaces the predicate above with `EXISTS` on an **active binding**, so a shared identity grants nothing by itself (§2.4). `cpms_patient_identities` carries no NID (`0018:27-36` in `2026_09_09_0018_patient_identities.php`).

### 2.4 Four artefacts, one writer (R3)
`full` needs two edges, and the selectors need a third row that revision 10 left implicit: **(1) clinical edge** `cpms_patients.identity_id`, written through `PatientIdentityService::link_clinical_record()` (`PatientIdentityActivator.php:101`); **(2) identity↔user edge** `cpms_patient_identity_links`, written by `PatientIdentityService::link_user()` `:248-280` → `insert_user_link()` `:268-277` (duplicate pair rejected `:259-261`; `u_pil_pair` `0019:65`); **(3) Clinic patient↔user link** `cpms_patient_user_links`, which is what every selector JOINs (`PatientRepository.php:89`, `:116`, `:146`, `:167`, `:185`) and what the dependent/booking predicate reads (`BookingService.php:3057-3063`, `:892`, `:1342`); the existing writer pattern is `PatientService::create_record()` `:461-464` under `u_link_pair` (`0001:97`). **(4) the binding row** itself. **Build rule:** one writer writes all four in **one** transaction (§5, §6.3); a binding without (3) is inert because the selectors never reach it. Only the link route writes bindings; record-creation paths (`create` `:370-378` → `create_record` `:379-496`, `activate_identity` `:561-576`, `createByStaff`, reschedule `:1720-1750`) write the clinical edge and no binding.

### 2.5 Levels
**`booking`** — active binding **and** both session proofs (§2.7): hold, confirm, resume, `/appointments/mine`, reschedule-options, the patient branches of reschedule/cancel, the minimal `GET /patient/me`, portal appointments; no list is served without a binding. **`full`** — the same plus both edges: visits, prescriptions, files + stream, `/patient/enroll`, `/patient/my-records`, full `GET` and `PATCH /patient/me`. **`proof`** — `POST /patient/link-record` needs **no binding**: an account session is enough (it is the route that *creates* bindings, §6.2), and it is the only patient route reachable from an account-only session. **Refused writes:** `PATCH /patient/me` for `booking`. Enforcement is duplicated at the service layer: `PatientService::me()` `:128-129`, `updateMe()` `:139`, `linked_records()` `:99-112`, `PatientController.php:52-62`, `NotificationService::inbox()` `:155-184`, `Admin\PatientPortalPage::render()`.

### 2.6 Non-REST surfaces
`admin_post_*`: 51 occurrences / 46 distinct; `wp_ajax_*`: 0; `add_shortcode`: 1 (`PublicBookingShortcode.php:219`); `template_redirect`: 9 hits — `RuntimeSchemaGate.php:42`, `DoctorPortalShell.php:30,31`, `Frontend/ManagementPortalController.php:80`, `ManagementPortalShell.php:64`, `PatientPortalShell.php:50`, `StaffPortalShell.php:194` + 2 comments — **NOT PROVEN COMPLETE**. The runtime self-check must enumerate `rest_get_server()->get_routes()` and fail on any route with no level.

### 2.7 The session proof store (R1, F1)
- **Where.** WordPress keeps session data server-side per token: `wp_get_session_token()` returns the current token (`developer.wordpress.org/reference/functions/wp_get_session_token/`), and `WP_Session_Tokens::get()` / `::update()` read and replace that session's data (`developer.wordpress.org/reference/classes/wp_session_tokens/`, backed by `WP_User_Meta_Session_Tokens`). The proof entry is a key inside **that** session array — never in a cookie, never in a new table, and it disappears with logout, `destroy_all()` and session expiry.
- **Shape.** Two maps inside that session array. `cpms_mobile_proofs = { "<mobile>": "<ISO-8601 UTC>" }` records **which mobiles this session has proved by SMS**; `cpms_proofs = { "<clinic_id>:<patient_id>": { "binding_id": int, "verified_at": "<ISO-8601 UTC>" } }` records **which bindings this session has proved by NID**. A fresh login starts with exactly one mobile proof — the account's own — and **no** binding proof.
- **Write.** A mobile proof is written by a successful OTP `verify()` for that mobile **inside this session** (the login itself, or a second mobile verified in the proof route, §6.2). A binding proof is written **only** by the two-factor route (§6.2), after its transaction commits, and only for the binding it just proved. `update()` is re-checked against the session it read (the session may have expired mid-request; a missing session fails the write closed).
- **Read.** The gate needs **both** maps to agree with the database: a binding proof whose `binding_id` **and** `verified_at` match the live row, **and** a mobile proof for that record's `mobile`. A re-proof, a correction-close (§7.6) or a sever changes the row, so every stale binding proof is invalid without touching session storage. A missing entry, a mismatch, an expired session or an unparseable entry ⇒ level `none` (fail closed).
- **Lifetime.** The entry lives as long as the session (owner option: a shorter idle bound, e.g. 30 minutes, is a constant, not a schema change). Closing an account or a binding destroys the sessions of that user (`WP_Session_Tokens::destroy_all()`), so a "closed" state cannot be outlived by a live cookie.
- **Fallback (INFERENCE).** If the token API is unavailable in the target WordPress version (4.0+ per the class changelog), the same entries may live in a transient keyed by `hash('sha256', $token)` with the session's expiry; this is weaker (a transient survives the session) and must be an explicit owner decision, not a quiet substitution.

## 3. Login, transaction depth, cookie (citation corrected, F8)
Normalise → limits → verify. The OTP row is read **outside** the transaction (`OtpService.php:400-404`) and re-read **with `FOR UPDATE`** inside it (`:455-458`, re-checks at `:462-467`), then consumed atomically (`:527-534`). **Correction:** `:449-575` is that transaction; **`:536-559` performs no identity lookup at all** — the comment at `:536-555` states it, and `$user_id = 0`, `$session_issued = false` at `:556-559`, so **no session is issued today** and the cookie branch at `:591-599` is unreachable. 0 live rows + a valid code ⇒ `202 register_required`, no cookie. **P4 flips exactly that block:** look up the account row by mobile, set `user_id` and `session_issued`, and issue the cookie **after** commit with a mobile proof for the account's own number and **no** binding proof (§2.7). **Cookie:** nested `transactional` calls become savepoints (`CpmsDb.php:272-320`: `START TRANSACTION` `:282`, `SAVEPOINT` `:284`, commit/release `:298-304`), so issuance needs depth 0 or fails closed (neutral 503). **That accessor does not exist** — `tx_depth` is private (`CpmsDb.php:31`, `:286`, `:316`); P1 adds a read-only one. `remember=false` (today `true`, `OtpService.php:595`); 8 h via `auth_cookie_expiration`. **Fresh `verify_mobile` code** is keyed by (mobile, purpose) (`:232`, `:658-672`) with `OtpPolicy::canSend` (`:234`); the bound is the per-mobile charge (`:274-275`). INFERENCE: concurrency governed by the guard row `:200-211`.

## 4. Registration
`POST /patient/register` — registered only in P7 (§11). Consume a `verify_mobile` OTP (`:39`); create exactly two rows (WP user with a random login and an unusable password, pattern `ManagementPortalService.php:1551-1558`; one **account** row `state='pending'`, no binding, no names beyond what the person typed). **No patient row, no link, no `clinic_id`** — the clinic is unknown (`cpms_appointments.patient_id NOT NULL` `0001:226`). A second registration on a used mobile creates nothing (`u_pl_mobile`) and returns the identical `202`. The queue is this table, not `cpms_notifications` (`clinic_id NOT NULL` `0001:634`, purged by `NotifDispatchHandler.php:39-41` → `NotificationRepository::purgeArchived` `:232-242`).

## 5. Account/binding state machine and the single writer (R3, F4)

**States.** Account: `pending` → `active` (first successful two-factor proof) → `closed`; `pending` → `closed` (retirement, §7.5). Binding: created `active`; `active` → `closed` (sever, restore, correction); `closed` → `active` only by a **fresh** two-factor proof (re-activation of the same row through `u_pl_bind`). No other transition exists; nothing is deleted (R5).

**The promotion moment.** A binding becomes `active` **iff** the transaction that writes all four artefacts commits. Before that commit the account is `pending` and grants nothing; after it, and only with its session proofs, `full` is possible.

**The one writer — `PatientBindingPromoter::promote()`** (new class, P6), called only from `POST /patient/link-record`, at depth 0, inside one `CpmsDb::transactional`:

| # | Write | Existing seam it must reuse |
|---|---|---|
| 1 | `SELECT … FOR UPDATE` the candidate patient row of the request's clinic | §6.4 |
| 2 | account row `state='active'` (from `pending`) | `cpms_patient_logins` |
| 3 | clinical edge `cpms_patients.identity_id` | `PatientIdentityActivator::activate_for_new_patient()` `:77-107` (or `assert_existing_link_in_organization` `:90`) |
| 4 | identity↔user edge | `PatientIdentityService::link_user()` `:248-280`, skipped when `user_link_exists()` |
| 5 | Clinic patient↔user link | `cpms_patient_user_links` insert, pattern `PatientService.php:461-464`, `u_link_pair` `0001:97`; tolerate the existing row |
| 6 | binding row `active` + `verified_at` + `live_key` + `account_key=NULL`, or re-activate the existing row of `u_pl_bind` | `u_pl_live` is the backstop |
| 7 | one audit row (ids and closed codes only) | `AuditLogger` |

**Fail-closed:** every step verifies its own postcondition (pattern `assert_persisted_identity()` `PatientIdentityActivator.php:104`); any failure throws and the whole unit rolls back — no binding, no edge, no link, account still `pending`. The **binding proof is written after commit only** (§2.7), so a rolled-back promotion cannot leave a usable proof. Re-entry is idempotent: a second promotion for the same triple re-activates the same row.

## 6. Both proofs

### 6.1 Owner policy (final, first release)
**M1** the record's mobile must equal the OTP mobile; **M2** a record with no NID is never auto-linked; **M3** linking only in the clinic whose record matches both; **M4** limits per account/mobile/IP, never on the NID, which appears only in a request body (never URL, log, audit, rate-limit key, notification, exception); **M5** every failure returns the identical status/body/message (§6.10); **M6** on success an SMS to that mobile plus one audit row with ids/closed codes; **M7** every additional patient needs its own proof, **and its own session proof** (§2.7). **M8 (R1):** the two proofs must be given **in the same session** — an OTP session alone is account-only. Pending cap 2 per account including dependents; reception may only sever, close and correct.

### 6.2 Link-record: one clinic per proof, and the bootstrap
`POST /patient/link-record {mobile, code?, clinic_id, national_id}` — requires an **account session** and **no** binding; **shipped in P6** with the writer of §5, not with the gate (F9). The OTP is consumed **in this session** at depth 0 (§6.5) for the body's `mobile`: when that mobile is already proved in this session (the account's own, from login) `code` may be omitted, otherwise a code sent to **that** mobile must be presented — which is what lets a guardian prove a dependent whose mobile differs (M7). Then the search runs only inside the one clinic resolved for this request: `SELECT … FROM cpms_patients WHERE clinic_id = %d AND mobile = %s AND national_id = %s AND status='active' FOR UPDATE` (§6.4); `u_pat_mobile` makes that at most one row, and no row ⇒ the generic envelope. `mobile` is never taken as an identity claim: it must equal both the proved mobile and the record's mobile (M1). **Bootstrap:** a zero-binding account has no clinic, so (i) `clinic_id` comes in the body, is validated server-side as a live clinic of a live Organization, and is applied as a per-request explicit scope only — `App::replaceExplicitScope()` (`App.php:2285-2293`, `ScopeContext.php:19-41`), never to the session; (ii) the cookie stamp is written only at the next OTP issue (`begin_otp_cookie_issue()` `PatientSessionScope.php:132-142`; `record_and_maybe_stamp_cookie_token()` `:534-584`) from the account's active bindings; (iii) with no stamp the resolver falls back to "one distinct linked clinic", else `null` (`resolve_clinic_id()` `:261-277`), and every clinic-scoped route fails closed. A stamp is a snapshot and grants nothing; a resolved clinic is not a binding. `require_clinic_matching()` `:347-358` throws the clinic-name-free error.

### 6.3 The only setter: a two-factor proof, in a session
A binding is one row `kind='binding'`, `state='active'`, non-NULL `verified_at`, owned by exactly the writer of §5. There is **no staff-granted path** (`verification_method` has one value; nothing staff-side sets `verified_at`), and a login alone grants nothing: the NID proved in the session, plus the mobile proved in the session, are the second half (§2.7). **One live binding per record:** `u_pl_live` enforces it across accounts; the §6.4 row lock plus a live re-read produce the friendly generic failure and remain the fallback. A second account cannot bind a bound record — same envelope. **Re-proof:** presenting both proofs again for an existing binding refreshes `verified_at`, refreshes the session entry and is idempotent; it never creates a second live row.

### 6.4 Concurrency
`FOR UPDATE` on the candidate patient row, then re-check tenant, `status='active'`, `mobile`, `national_id`; only then write. Sever, restore-closure and correction-closure take the **same** row lock, so proof, sever and restore serialise on one row. Tests: concurrent NID edit, concurrent sever, competing accounts (T-L9, T-L25).

### 6.5 Consume runs at depth zero
The OTP consume lives **inside** `verify()`'s transaction (`OtpService.php:449`…`:527-534`) and would roll back with a failed link. The proof route therefore runs consume + attempt charge + decision in a unit that must execute at **depth 0**, checked before it runs; a nested caller fails closed (neutral 503, no consume). Depth 0 means `transactional` issues a real `START TRANSACTION`/`COMMIT` (`CpmsDb.php:282`, `:298-304`), so the consume commits before any binding row can exist. Limits: per account, per mobile across all accounts, per IP; keys hashed; the NID is never a key; `cpms_rate_limits` already exists. Tests T-L8, T-L15.

### 6.6 Audit free text — full census (correction, F8)
`git grep -o "audit->log(\|audit()->log("` over `src`: **108 call sites in 37 files** (OBSERVED). Carriers of user free text or a NID: `PatientService.php:472-482` `PATIENT_CREATED` via `staffView()` `:801-813` over `publicView()` `:779-795` (raw `national_id` at **`:787`**); `PatientService.php:161-171` `PATIENT_PROFILE_UPDATED` and `:521-531` `PATIENT_UPDATED`, both through `diffView()` `:864-875`; `ClinicalService.php:1597-1610` `SEARCH_EXECUTED` (`q` typed by the user, `:1606`); `:435-444`, `:681-689`, `:972-981`; `BookingService.php:1057-1070`, `:1755`, `:1962` via `appointmentView()` `:3367-3383`; `:2058-2069`, `:2209-2216`; `VisitService.php:384-387` (raw visit row); `FinanceService.php:647-665`, `:754-770`; `VisitPricingService.php:276-290`, `:330-349`, `:379-392`; `MedicalFileService.php:348-357`; `HandwritingService.php:117-125`; `LocationService.php:144-157`, `:284-293`; `ClinicProfileService.php:203-219`; `FirstPracticeSetupService.php:269`, `:501`; `OrganizationClinicProvisioningService.php:730`; `ClinicianAdminPage.php:682`; `CustomRoleManagementService.php:166`, `:219`; `Settings.php:374`. **Correction:** `diffView()` `:864-875` does not copy the whole row — it iterates the **field names** in `$changed` and copies each from `staffView()` `:801-813`; because `staffView` starts from `publicView()` (raw NID at `:787`), an NID edit is audited as raw text. **Masking facts:** `AuditLogger::sanitize()` recurses into arrays (`:253-255`), masking is exact-key and **case-sensitive** (`MASK_KEYS` `:26`, applied `:249`) while `FORBIDDEN_KEYS` is case-insensitive (`:24`, `:246`), and a `reason`/`q`/`description` string holding an NID passes through. **P2 rule (owner):** closed codes, booleans and bounded lengths; **no regex sanitising of free text**; the search term is logged as a boolean/count. Closed-code-only sites and pass-through helpers are unchanged from revision 10; historical rows are immutable and their contents **UNKNOWN**. Because R2 removes the NID from `publicView()`, `staffView()` no longer carries it either — the audit then has no NID to leak, which is why R2 and P2 must land together.

### 6.7 Notifications, per proved binding
For `pending`, no-row, closed, **unbound** and **unproved-in-this-session** accounts every notification read **and** write endpoint returns one fixed empty envelope with a constant ETag, computed **before** any `lastId`/ETag/304 work: `GET /notifications` `NotificationsController.php:34-49` (guard `:42-43`); `POST /notifications/read` `:51-71` (`:64-65`); `GET /rt/notifications` `:74-85` (`:78-79`) — gated before `lastId` `:104`, the 304 branch `:106-111` and `since()` `:113`. When the session **is** proved, the payload is filtered to patients with a binding for the request's clinic: `NotificationService::active_linked_patient_ids_in_clinic()` `:465-477` must intersect the binding set for inbox, cursor, counts and writes.

### 6.8 Shared and recycled mobile (DECIDED + R1)
`u_pat_mobile` UNIQUE (`0001:82`) and `mobile NOT NULL` (`0001:60`): a shared number serves **one patient per clinic** in this release; several per clinic is next-version (§18). One account per mobile (`u_pl_mobile`) means a second person on that number cannot register until reception retires the old account. **Recycled number, exactly:** the new holder receives the old account's SMS and can open an **account-only** session (§2.7) — and nothing else: no binding, no binding proof, every record route `none`, identical envelopes (§6.10). To give the new holder a working path, reception **closes** the old account (account row `state='closed'`, `mobile` set to NULL to free `u_pl_mobile`, sessions destroyed, audit) — **a state change that grants nothing**, never a reassignment of the old bindings, which stay `closed` with `live_key=NULL`. The new holder then registers and proves their own record. Identities carry a non-unique `normalized_mobile` (`0019:36-51`, deliberately not UNIQUE) and no NID (`0018:27-36`), so no matcher can join two people by number at Organization level.

### 6.9 Staff actions: sever, close, correct — never grant
Reception creates records via `ReceptionPortalController.php:183-188` → `PatientService::create` `:370-378` → `create_record` `:379-496`, can set a checked NID, and can **correct** record data (which closes bindings, §7.6). It can **sever** a binding: `UPDATE … SET state='closed', live_key=NULL` + one audit row + `WP_Session_Tokens::destroy_all()` — **a state change, never a DELETE, and never a change to the account row or to `wp_users`**. It can **close** an account (account row `state='closed'`, sessions destroyed); the WP user and its password hash stay. **Closing never deletes an account or a user** (§7.5). Reception search returns `searchView()` `:831-844` (masked NID `:839`) via `search()` `:245-257` and `to_search_view()` `:823-825`; add `login_state`. **OBSERVED, and an API contract change:** `get()` `:264-273` returns `staffView()` with the **raw** NID to authenticated staff of the same clinic; R2 removes the field from `publicView()`, hence from `staffView()`, and the build must replace it with a masked value plus a permission-gated reveal — **that contract change needs separate owner approval at build time** and is not assumed here. **"Pending registrants must first visit reception" stays removed** — a `pending` account proves itself through §6.2.

### 6.10 One failure envelope (R9, F10)
Every failure of the proof route — bad OTP, expired OTP, NID mismatch, record mobile ≠ OTP mobile, record without NID, **unknown mobile**, **closed account**, no binding possible, duplicate race, **rate limit**, malformed body, and any unexpected exception — returns **the same status and the same body shape**:

| Aspect | Value for every case above |
|---|---|
| HTTP status | `403` (no `429`, no `404`, no `409`, no `Retry-After`) |
| Body | `{"code":"CLINIC_PATIENT_PROOF_FAILED","message":"<one fixed Persian sentence>","data":{}}` |
| Mapping | one mapper, `PatientProofFailure::envelope(Throwable)`, installed at the route's outer `try/catch`; every thrown `OtpException`/`BookingException`/`PatientIdentityException`/`RuntimeException` is caught there; nothing is re-thrown to the REST base |
| Differences | **none by design** — the code, message, `data`, header set and body length are constants; no field, length, count or branch varies with the input (no timing claim is made: only the response is uniform, and no counter is exposed) |
| Documented exception | `503` with the same body shape when the system cannot run the unit at all (schema pending, depth ≠ 0, DB unavailable) — a system state, not an input the NID or mobile can select |

Success returns `200` with `{"code":"OK","message":"…","data":{"binding_id":…}}`; the audit row is written for both outcomes with ids and closed codes only.

## 7. Lifecycle

### 7.1 Table existence
`App::is_runtime_blocked_by_pending_schema()` `App.php:622-639` returns `false` for a supported engine (`:626-628`) and for unreadable evidence (`:630-634`) — it reports **pending migrations**, not a missing table. The gate does its own cached-per-request existence check and fails closed (`none` + neutral refusal).

### 7.2 Restore barrier
The dump set is `SHOW TABLES LIKE '{prefix}cpms_%'` (`BackupSqlDumper.php:113-126`, pattern `:118`), so an **option** (proposed `cpms_restore_barrier`) is outside it and survives a restore. **OBSERVED: no such option exists in this tree today** — it is a P1 deliverable. Set before the restore; while set every patient level is `none`. **UNKNOWN:** `restoreApply` has no pre-drop hook in this tree; the build PR must add one with a kill-between-drop-and-recreate test.

### 7.3 Restore closes every binding and recomputes keys (R5, R6)
Clearing the barrier is one manager action that destroys patient-only sessions, writes one audit row, and sets `state='closed'`, `live_key=NULL` on every binding row of the restored data, then **recomputes** `live_key` (all NULL) and `account_key` (from `wp_user_id`) for every row — a restored dump therefore carries no live key that could collide with a new binding. No restored binding is trusted; every account falls back to `none` and needs a fresh proof (both factors, new session). **Accounts, account rows and password hashes are kept; nothing is deleted.** This matches the restore rule taken read-only from `docs/architecture/backup-restore-design.md` in branch `arena/f52cec7c-doctor` at `efa0e214495b198a3a60243126212b4625e296c6`; that file does not exist in this tree at `c9771403`, so the requirement is recorded here and not claimed as met. A dump taken before those columns existed recreates the table without them (`BackupSqlDumper.php:131-141` dumps `SHOW CREATE TABLE`), so the P1 migration runs again after a restore and the hook recomputes both keys; if a row needs a key the build cannot recompute, the restore fails closed.

### 7.4 Backups and uninstall
Backups already contain every patient NID from `cpms_patients.national_id`; **encrypting the backup is a requirement of the backup work, not a fact of this tree** (UNKNOWN/LIMITATION). **Uninstall (`uninstall.php:8-20`):** unset ⇒ `return` at `:9-11`, nothing deleted, account and binding rows survive a reinstall; set ⇒ every `{prefix}cpms_%` table dropped (`:15-18`) plus two options (`:19-20`), so login rows are destroyed, **patient sessions are not explicitly destroyed** (LIMITATION — the build must add it), and patient `wp_users` are **not** deleted.

### 7.5 Retention — close and scrub, never delete (R5, F6)
The sweep is a **new** `cleanup.patient_logins` job (`App::RECURRING_JOBS` `App.php:2616-2635`; existing `cleanup.*` `:2625-2628`, `OtpCleanupHandler.php:22-25` owns only `cpms_otp_tokens`). **It deletes nothing.** It performs two state changes: (a) a **binding** older than the retention window with no active use is `closed` (`live_key=NULL`), its proof entries die with the session it belongs to; (b) a `pending` account that never proved anything after 14 days becomes `state='closed'` with `given_name`/`family_name` set to NULL **and `mobile` set to NULL** — which frees the number under `u_pl_mobile` while keeping the row, the account and the WP user intact. A closed account is terminal for access and is never deleted; a closed row is never restored to `active` without a fresh proof. **Names are scrubbed at the decision to close** (`given_name`/`family_name` → NULL), which is the revision-9 rule kept; the NID is never stored at all, so there is nothing else to scrub. **Deleting rows or WP users is not the default**; it is an **OPTION for the owner** in §16 (OQ-D2 carries the sweep's design), and no code path in this design deletes an account (§13).

### 7.6 Record correction closes the record's bindings (R4, F5)
When reception changes a record's `national_id` or `mobile` (including `PatientService::update()` and the reception correction route), or merges/deactivates the record, the **same transaction** must set every binding row of that `(clinic_id, patient_id)` to `state='closed'`, `live_key=NULL`, write one audit row, and after commit destroy the sessions of the affected users (`WP_Session_Tokens::destroy_all()`), because every session proof for that binding is now stale (§2.7). The patient must prove again with the new number and NID; no old binding can be reopened by possession of a number alone. A correction that only touches a name, address or phone (not the mobile, not the NID) does not close anything — the rule names the two proof fields explicitly.

## 8. Cap and lock order
Cap = 2 active appointments per account (DECIDED), counted across all patients with an **active binding** on the account (never `cpms_appointments.wp_user_id` `0001:230`, which `createByStaff` `:1935-1954` does not populate). The locking read runs in the **outermost** transaction: account row `FOR UPDATE` → `COUNT(*)` of future non-cancelled appointments. Depth guard: fail closed if nested (§6.5). **Staff bookings are exempt. Reschedule replaces the row:** `reschedule()` calls `appointments->create()` `BookingService.php:1720-1741` (status `confirmed`, `rescheduled_from` set `:1735`) and only then updates the source (`:1743-1750`), so the replacement enters the active set and a reschedule **consumes cap capacity**, counted as one by excluding rows with a non-NULL `rescheduled_to` (`:1747`). **Lock order:** account row → Organization → Clinic → Slot → Location (`:1881-1886`, `:2750-2790`); reschedule appointment-first (`:1568-1574`); `createByStaff` tenant→Slot→Location (`:1805-1935`). The only new edge is "account row before tenant prefix" ⇒ no cycle (INFERENCE).

## 9. Abuse limits
`Settings::DEFAULTS` (`Settings.php:28-40`) are configurable defaults (`get()` `:207`), not ceilings. Per mobile 3/day, 10/hour, 60 s cooldown, 900 s lockout after 5 bad codes; `/otp/request` per IP 10/hour; `/otp/verify` per IP 20/3600 hard-coded (`OtpService.php:392`). `/patient/register` needs a consumed OTP plus hashed buckets. `/patient/link-record`: per account, per mobile across all accounts, per IP (§6.5). The NID is never a key (M4). Existing debt not copied: `OtpService` keys `otp-day:`/`otp-hour:` on the **raw** mobile (`:274-275`, `:622-629`).

## 10. Dependents
Several patients per account work because bindings are many: `linkedActivePatientsForClinic` `BookingService.php:3057-3063`; `>1` link makes the hold refuse to guess (`:3121`) with an optional `patient_id` (`BookingController.php:90`) validated by `assert_requested_patient_owned_by_caller` `:3131-3153`. Dependents are **in** the release: the dependent's record is created by reception and proved **from the guardian's session** with a code sent to the dependent's own mobile plus the dependent's NID (M7); both proofs land in that session (§2.7), so a guardian's proof never inherits to another record and each dependent needs its own pair before its data is served. A dependent with **no mobile of their own** cannot be proved from a guardian's session — that is exactly OQ-D1, a stated LIMITATION (§6.8), not a hidden grant. No age limit; never merged with a walk-in. Negative test T-L13.

## 11. Rollout

| PR | Contents | Binding settable? | Record access |
|---|---|---|---|
| **P1** | table `cpms_patient_logins` (both key columns), existence check, restore barrier + pre-drop hook **+ key recompute**, **public tx-depth accessor**, uninstall session destruction | no | no |
| **P2** | audit redaction (§6.6) and NID out of `publicView()` (§6.9) — the API contract change | no | no |
| **P3** | gate on **all** routes by actor branch + service-layer checks + runtime-registry test + `:261-269` predicate replacement (§2.3) | no | **no** |
| **P4** | SMS login issuance: cookie at depth 0, **account-only session — one mobile proof, no binding proof** (§3, §2.7) + clinic stamping and its bootstrap (§6.2) | no | no |
| **P5** | cap enforcement (§8) and lock order | no | no |
| **P6** | **binding**: `/patient/link-record` → `PatientBindingPromoter` (§5), session proof (§2.7), sever/close, correction-closure (§7.6) | **yes** | **yes**, per proved binding |
| **P7** | public `/patient/register` + pending queue | yes | per proved binding |
| **P8-P10** | UI journeys (incl. dependents, M7), browser lanes, acceptance evidence | yes | per proved binding |

**Invariants:** gate (`P3`) < sessions (`P4`) < binding (`P6`) < registration (`P7`); no binding before `P6`; no `pending` row before `P7`; only `P6` writes `verified_at` and only `P6` writes a proof entry; "attach SMS login" ships inside the gate PR (as a *proof* entry point, never a grant). Minutes (INFERENCE incl. review): P1 110 · P2 70 · P3 260 · P4 120 · P5 120 · P6 300 · P7 150 · P8 200 · P9 120 ⇒ **≈1 450 implementation + 800–950 review ≈ 2 250–2 400**.

## 12. State matrices

**A. Routes by actor** (`✗` refused · `—` not routed · `✓` allowed; `booking` = binding **and** both session proofs, without the two edges):

| Route / branch | no row | pending | active, no proof in session | active + proof | closed | password-only | staff | anon |
|---|---|---|---|---|---|---|---|---|
| `POST /patient/link-record` | ✗ 403 | ✓ proof | ✓ proof | ✓ idempotent | ✗ 403 | ✗ 403 | ✗ 403 | ✗ 403 |
| `/booking/hold`, `/booking/confirm`, `/booking/resume` | ✗ | ✗ proof | ✗ proof | ✓ bound patient | ✗ | ✗ | — | ✗ |
| `/appointments/mine`, `…/reschedule-options`, `…/reschedule`, `…/cancel` | ✗ | ✗ proof | ✗ proof | ✓ bound patient | ✗ | ✗ | staff branch | ✗ |
| `GET /patient/me` | ✗ | minimal | minimal | full | ✗ | ✗ | — | ✗ |
| `PATCH /patient/me` | ✗ | ✗ | ✗ | ✓ `full` only | ✗ | ✗ | — | ✗ |
| `/patient/enroll`, `/patient/my-records`, `/visits*`, `/prescriptions` | ✗ | ✗ 403 | ✗ 403 | ✓ `full` | ✗ | ✗ | staff path | ✗ |
| `/patients/{id}/files`, `/files/{id}/stream` | ✗ | ✗ 403 | ✗ 403 | ✓ own `full` | ✗ | ✗ | `MedicalFileService::stream()` `:206-262` | ✗ |
| `/notifications`, `/notifications/read`, `/rt/notifications` | ✗ | fixed empty | fixed empty | ✓ bound only | fixed empty | ✗ | staff branch | ✗ |
| portal render | ✗ | nothing | nothing | appointments / full | ✗ | ✗ | — | ✗ 403 |
| `/patient/register` (P7+) | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✗ | ✓ 202 |

**B. Binding/account states** (§5): `pending` — no binding, no record access, proof route only. `active` — binding exists; record access only with a binding proof **and** a mobile proof in the same session. `closed` — grants nothing, holds no live key, cannot become `active` without a fresh two-factor proof. **A login never changes any row**: an OTP-only session is `active` account + zero proofs ⇒ `none` everywhere except the proof route. **Login oracle** (`/otp/request` always sends, 200): unknown mobile + valid code ⇒ `202 register_required`, no cookie; live account ⇒ 200 + cookie with **no** proof; wrong code or staff ⇒ the §6.10 envelope. `register_required` is distinguishable only to a caller who has proved possession of that mobile (owner-permitted); record access **and record existence** are never signalled (§5).

## 13. Self-audit (Phase 2 of revision 11)

- **(a) F1-F6.** No path reopens a binding without both proofs in the same session: a login writes only a mobile proof and never a binding proof (§3, §2.7); only `P6`'s writer writes a binding proof (§5, §6.3); the gate compares both entries with the live row; sever, restore and correction all close rows (§6.9, §7.3, §7.6). No patient response carries a raw NID (§2.5, §6.9) and no patient-side branch can distinguish "NID held by another record" because the field is not editable (§6.9, R2). Promotion is one depth-0 transaction over four artefacts (§5) with per-step postconditions. Retention deletes nothing (§7.5): the sweep closes, scrubs names and frees the mobile; **no path deletes an account, a row or a WP user** — the only destructive operation left is `uninstall.php` with its explicit option (§7.4).
- **(b) F7-F10.** Both key columns are plain and application-maintained, dumped and restored like any column (§1); restore closes and recomputes (§7.3); NULL-distinctness is cited from both engines (§1) and T-L26 must prove the round-trip on MariaDB 11.4 and MySQL 8. Citations corrected: OTP read `:400-404`/lock `:455-458`, no identity lookup in `:536-559` (§3); `link_user()` has no caller (§2.3); `diffView()` iterates field names (§6.6); §14 recycled-number row rewritten; §13 "no path deletes an account" replaced. Consistency: link-record needs no binding (§2.5, §12A) and ships in `P6` (§6.2, §11); OQ-D8/OQ-D9 marked "working assumption, pending owner" everywhere. One envelope (§6.10).
- **(c) Ordering, buildability, claim words.** Gate `P3` < sessions `P4` < binding `P6` < registration `P7`; the binding fits the approved table; P1 adds the depth accessor and the two key columns; `only`/`never`/`cannot` are traced to the cited line; `template_redirect` stays **NOT PROVEN COMPLETE**; the one-clinic property is a property of the query, not of the data. Two working assumptions (OQ-D8, OQ-D9) and one STOP (§16) are not silently assumed.

## 14. Security (threats)
| Threat | Mitigation | Residual (accepted) |
|---|---|---|
| **Recycled number** | An OTP-only session holds **no binding proof** (R1): the old binding is not reopened, and even the correct NID cannot reach a closed account. Reception closes the old account and frees the number (§6.8) | The old account keeps its row until retired; a subscriber who is also the same person still needs the NID *and* a live binding |
| **SIM swap/theft** | NID re-entered **in the same session**; mismatch grants nothing; no creation branch to probe | M6's SMS goes to the attacker's number — a weak warning only |
| **NID known to relatives/documents** | Mobile possession still required, per session | A relative holding both can prove — the authority documents already give that |
| **NID brute force after SIM compromise** | Limits per account/mobile/IP, never the NID (M4); one uniform envelope (§6.10) | Bounded only by configurable `Settings` (§9); windows UNKNOWN |
| **Record-existence probing** | No patient-side creation, no collision branch, one envelope for every non-matching attempt (§5, §6.10) | An account holding an active binding still knows its own record exists |
| **Squatting a national ID** | `u_pat_nid (clinic_id, national_id)` UNIQUE (`0001:83`) + `assert_unique_national_id()` (`:850-857`, called from `create_record` `:442`) | A squat **can** block the real patient in that clinic, and M2 refuses auto-link; resolution is manual and audited |
| **Session-store tampering** | Proof entries live server-side in the session record, die with it, and are re-checked against the live row | A DB-level attacker can edit user meta as with any WordPress data (LIMITATION) |

## 15. Tests

| # | Case | Expected |
|---|---|---|
| T-L1 | matching mobile + NID in the scoped clinic, same session | account `active`, binding `active`, four artefacts (§2.4), both session proofs, `full` next request, M6 SMS without NID, audit ids only |
| T-L2 | wrong NID | no binding, **byte-identical** envelope to T-L3/T-L4/T-L7/T-L20/T-L27 |
| T-L3/T-L4 | record mobile ≠ OTP mobile (M1); record has no NID (M2) | no binding, identical envelope |
| T-L5 | same mobile, two clinics | each proof binds only in its clinic; two binding rows; no cross-clinic "exactly one" failure |
| T-L6 | active, no session proof: every record **and** booking route + 3 notification endpoints | `none` / fixed empty, `If-None-Match` ⇒ fixed envelope, no 304 |
| T-L7 | limits: per account, per mobile across accounts, per IP | identical 403 envelope; keys hashed; no NID in `cpms_rate_limits` |
| T-L8 | failed proof | OTP not restored, counters not reset (§6.5) |
| T-L9 | concurrent NID edit; concurrent sever; two accounts racing one record | one outcome, `u_pl_live` holds, generic refusal, no orphan binding |
| T-L10 | audit payloads after success/failure | no NID, no mobile, no name, no free text |
| T-L11 | reception sever | bindings `closed`, `live_key` NULL, account and `wp_users` intact, sessions destroyed, audit row |
| T-L12 | pending account proves itself | succeeds without visiting reception (§6.9) |
| T-L13 | dependent with a distinct mobile; guardian proof | a code to the dependent's mobile **and** the dependent's NID, both in the guardian's session (M7); never inherited; a dependent with no mobile stays unprovable (OQ-D1) |
| T-L14 | restore then re-establish | every binding `closed`, `live_key`/`account_key` recomputed, sessions destroyed; `full` again only after both proofs |
| T-L15 | proof called from inside a transaction | fails closed (503 envelope), no consume, no binding |
| T-L16 | reception/booking/registration/reschedule/identity reuse | **no** `verified_at` row and **no** binding proof appears anywhere (§5) |
| T-L17 | a mobile with an unprovable existing record, then a mobile with none | identical envelope, **no** record created |
| T-L18 | cap with dependents: 3 bound patients, 2 future appointments | third appointment refused, counted across patients (§8) |
| T-L19 | password-only and closed accounts | password login grants no binding and no proof; a closed account cannot re-bind |
| **T-L20 (R1)** | OTP-only session, then the same account in a **new** session | every record/booking route `none` in both until the NID is proved in that session |
| **T-L21 (R1)** | recycled SIM: new session on the old account | no clinical data; after reception closes the account, the number registers afresh and the old binding stays `closed` |
| **T-L22 (R2)** | every patient response (`me`, `PATCH me`, booking, appointment list, files) | no `national_id` key, raw or masked |
| **T-L23 (R3)** | `PATCH /patient/me` carrying an NID, once with and once without another record holding it | identical response, no write, no oracle |
| **T-L24 (R4)** | force a failure at each of the four artefacts in §5 | nothing persists, account stays `pending`, no proof entry, retry succeeds whole |
| **T-L25 (R5)** | reception changes NID, then mobile; merge; deactivate | bindings closed, `live_key` NULL, sessions destroyed, re-proof required |
| **T-L26 (R6)** | dump → restore round-trip with both key columns; `SHOW COLUMNS` | insert covers every column, restore closes bindings and recomputes keys; MariaDB 11.4 **and** MySQL 8 |
| **T-L27 (R9)** | bad OTP, expired OTP, NID mismatch, unknown mobile, closed account, rate limit, malformed body, forced exception | identical status 403 and byte-identical body for all |
| **T-L28 (R5)** | 14-day sweep on a never-proved pending account | row `closed`, names and mobile NULL, WP user kept, row present, re-registration on that mobile succeeds |
| **T-L29 (R3)** | two records sharing one identity; binding for record A | record B stays `none` (P3 EXISTS), and reuse needs the matching NID |

## 16. Weakest points, open decisions, dispositions, STOP

**Weakest:** `u_pl_mobile` fixes one account per mobile until reception retires it (§1/§6.8); two plain key columns are **INFERENCE** on the engines until T-L26 runs (the §6.4 lock is the fallback); the NID is not a secret and a squat blocks a real patient's NID while M2 refuses auto-link; `restoreApply` has no pre-drop hook, `uninstall.php` does not destroy sessions, the sweep has no host yet, and `identity_id IS NULL` rows stay readable by legacy predicates until P3 lands (DEBT); the session-proof store depends on the WordPress session API (§2.7).

**Decision dispositions (F11, compared with revision 9):**

| Decision (rev 9) | Revision 11 |
|---|---|
| **OQ-D1** dependent sharing the guardian's mobile | **kept open** as a LIMITATION (§6.8, §10) — the owner must decide the cross-version rule |
| **OQ-D2** stalled-sweep warning | **restored** as an owner decision (§7.5). A stalled sweep now only delays a *closure and scrub* of a never-proved row; it grants nothing and blocks nothing, so it is a retention/hygiene risk, not an access risk. Recommendation: a warning in the health page plus the P1 job host and a test |
| **OQ-D3** per-account vs per-link cap | **closed, DECIDED:** per account (§8) |
| **OQ-D4** "attach login" mandatory forever | **removed, with its reason**: the owner policy forbids *any* staff-granted access, so a permanent staff "attach" cannot exist. Its purpose — a stable way to reach a linked record — is now met by the two-factor proof that is re-established per session (§2.7, §6.3), and reception retains sever/close/correct only (§6.9) |
| **OQ-D5** | closed in revision 9; no new content |
| **OQ-D6** shared mobile | **DECIDED** (§6.8), unchanged |
| **OQ-D7** collision flag | **closed**: no flag is stored, because no patient-side creation exists (§5) |

**OQ-D8 — how does a clinical record come into existence in the first release?** Working assumption, **pending owner** (option A below), no schema change. **(A) reception-only creation [RECOMMENDED]**: no oracle at all, because a non-matching attempt is identical whether a record exists or not; cost: a person without a record cannot self-serve booking. **(B) patient-side creation at first booking**: keeps self-service, but a mobile holder can distinguish "record exists" from "no record" — a disclosure no uniform error can remove; needs written owner acceptance plus rate limits and an SMS on every creation. **(C) in-person issued creation code**: closes the oracle, keeps self-service, but needs the OTP `purpose` ENUM to gain a value (`0001:118-119`) = schema change ⇒ **S1**.

**OQ-D9 — a record with no national ID can never be auto-linked (M2).** Working assumption, **pending owner** (option A). **(A) accept [RECOMMENDED]**: that patient keeps in-person service and gets no digital access; reception may correct the record's data (which closes bindings, §7.6), never grant. **(B) a staff-granted binding**: fits the table but **breaks the owner rule that every access-granting setter needs both proofs**, and would make a staff member an access authority. Recommendation: (A) now; (B) only as a separate owner decision with a signed-consent design.

**Separate owner approval needed at build time:** masking the staff NID and adding the permission-gated reveal (§6.9) is an API contract change for staff consumers; it is flagged here, not assumed.

**Deletion — OPTION for the owner, not the default (F6).** If the owner wants the 14-day sweep to *delete* never-proved pending rows and their WP users, that is a separate decision with its own audit and restore consequences; this design does **not** do it.

**STOP items:** **S1 — "STOPPED - OWNER DECISION NEEDED: schema".** Question: may `cpms_otp_tokens.purpose` (`0001:118-119`, `ENUM('login','verify_mobile')`) gain a third value `record_create` for OQ-D8 option (C)? Options: add the value, or keep two. Recommendation: **do not add now**. A binding column on another existing table later would be a second STOP of the same kind, not to be designed around. **No other schema is required**; both new columns live inside the approved table.

## 17. What revision 11 changed
Session-scoped two-proof access and the proof store (R1); NID out of patient responses and out of patient-editable fields (R2); one writer over four artefacts with the promotion moment (R3); correction closes bindings (R4); no deletion anywhere (R5); plain key columns instead of generated ones, with both engines cited and a restore recompute (R6); the citations and the recycled-number, identity-reuse and deletion claims corrected (R7); link-record reachability and rollout consistency (R8); one failure envelope (R9); OQ-D2 restored and OQ-D4 removed with reasons (R10).

## 18. Next version (not this release)
Several patients per mobile in one clinic — blocked by `u_pat_mobile` (`0001:82`) and `mobile NOT NULL` (`0001:60`); needs its own design and owner approval before any schema change. Dependent self-service beyond §10 follows the same design. No column or table is proposed here.

## Appendix A — Official sources (opened in this session unless marked)
- MySQL 8.4, "Unique Indexes": *"A UNIQUE index permits multiple NULL values for columns that can contain NULL."* — <https://dev.mysql.com/doc/refman/8.4/en/create-index.html>
- MariaDB, "Primary Keys and UNIQUE Indexes": *"UNIQUE indexes can contain multiple NULL values. Primary keys are never nullable."* — <https://mariadb.com/docs/server/reference/data-types/null-values>
- WordPress `wp_get_session_token()` — <https://developer.wordpress.org/reference/functions/wp_get_session_token/>
- WordPress `WP_Session_Tokens` (`get`, `update`, `destroy_all`, `get_instance`) — <https://developer.wordpress.org/reference/classes/wp_session_tokens/>
- `uninstall.php`, `admin_post_{action}` and nonce references are unchanged from revision 10 and were **not** re-opened today (<https://developer.wordpress.org/plugins/security/nonces/>).

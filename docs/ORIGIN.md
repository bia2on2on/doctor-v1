# doctor-v1: project origin and Release 1.0

`doctor-v1` is the Release 1.0 line of Clinic Practice Management System (CPMS),
a WordPress plugin for running medical practices. Product site: `<PRODUCT_DOMAIN>`.

**Release 1.0 work recorded here:** design documents only, no new code built.
The imported baseline already includes plugin code and tests; this record is not
an implementation, build, or release of the new scope.

## Provenance

- Imported from `bia2on2on/doctor` `main` at
  `c97714034e62b001a313ee6636b25d7bece9d374`: 3,071 commits, no tags.
- The old repository stays unchanged. Release 1.0 work is tracked in `doctor-v1`.
- These old PRs are technical references only. Their branches exist in `doctor-v1`;
  the references do not authorize importing or merging their changes.

| Old PR | Technical reference | Branch in `doctor-v1` |
| --- | --- | --- |
| #354 | Patient identity, SMS login, and self-registration design | `arena/2283a2f6-doctor` |
| #357 | Browser acceptance harnesses for possession-only OTP | `arena/031854b0-doctor` |
| #358 | Encrypted backup and restore design | `arena/f52cec7c-doctor` |

## Release 1.0 scope

- **Independent doctor:** a single-doctor clinic, including operation at several
  locations for the same doctor.
- **Doctor building:** a building of doctors operating independent clinics.
- **Patient-facing:** patient panel, patient SMS login and identity verification,
  new-patient registration, and public booking.
- **Practice-facing:** patient and medical-record management, scheduling and
  reception, visit recording (including handwriting and prescriptions), basic
  finance, and reports.
- **Operational:** security and role-based access control (RBAC), custom roles,
  and backup and restore.

Staff messaging is hidden for 1.0, not deleted. Child-specific features are out of
1.0 and deferred to the next version. The [owner decision record](status/ledger.md#9-release-10-owner-decisions)
records the current target separately from the older technical designs.

## Release acceptance criteria

The following ten criteria are derived from the recorded scope and owner decisions.
**All ten are NOT yet verified.** CI check results alone are not release acceptance.

1. An independent doctor can set up and operate a single-doctor clinic.
2. One doctor can run the clinic at several locations with the correct schedules
   and access scope at each location.
3. Doctors in one building can run independent clinics without sharing patient
   or clinical records across clinic boundaries.
4. Patients can access their own panel after SMS OTP and matching national-ID
   proof; mobile-only proof and staff actions cannot grant that access.
5. A new patient can self-register and book a public appointment within the
   approved patient identity policy.
6. Scheduling and reception support the appointment lifecycle; a no-show is
   recorded only manually, with a reason and an actor.
7. Authorized users can manage patients and medical records and record visits,
   including handwritten notes and prescriptions.
8. Authorized users can perform basic finance operations and produce reports.
9. Standard and custom roles enforce least privilege and clinic/location scope.
10. A manual full encrypted backup can be restored onto an empty install with
    all login bindings closed, subject to the agreed locking and read-only-window
    requirements.

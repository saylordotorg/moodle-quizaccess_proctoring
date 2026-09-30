# Selected improvements — 1.11.0

Implemented choices **1, 2 and 4** from the security-audit follow-up list, including the earlier security fixes. Release 1.11.0 was deployed to the development Moodle installation on September 30, 2026; see [the deployment record](DEPLOYMENT_DEV_2026-09-30.md) for verification and the remaining student acceptance checks. This does not establish production readiness.

## Settings and defaults

Open **Site administration → Saylor Proctored Quiz** (or search for that page).

| Section | Setting | Default | Effect |
| --- | --- | --- | --- |
| Precheck | Device readiness check | On | Links to a separate device and connection test before starting an attempt. |
| ID verification | Maximum age of a passed ID check | Zero / unlimited | Requires a new check after the configured duration. |
| ID verification | Verify ID for every new attempt | Off | Requires a fresh pass in the current session before each new attempt. |
| ID verification | Repeat ID verification after a profile name change | Off | Invalidates a pass when the saved profile-name fingerprint differs. |
| ID verification | Repeat ID verification after a policy change | Off | Invalidates a pass when the saved identity-policy fingerprint differs. |
| Monitoring | Record periodic screen coverage | Off | Adds routine screen captures at the webcam cadence when desktop capture is enabled. |

Webcam coverage reports and short connection recovery are automatic. Screen-sharing checks and existing event screenshots retain their existing controls. Enable the new periodic screen setting only when those additional images are wanted; the generated privacy notice includes a corresponding disclosure.

## 1. Monitoring coverage and connection recovery

The detailed student report and inline attempt panel show webcam and optional screen capture counts, delayed uploads, estimated missing time and gap intervals. Expectations and the capture cadence are saved when Moodle creates a new attempt and retained across page reloads. Enabling periodic screen collection applies to new attempts; a page reload cannot add it to an older exam. Disabling it stops further routine screen uploads.

Historical attempts without a saved policy show unknown coverage; the report does not infer their requirements from today's settings. An older active attempt can start recording webcam coverage from its next page load, with that partial period identified and periodic screen storage kept off.

The browser retries temporary failures with increasing delays and wakes the queue when connectivity returns. The queue holds at most four payloads within a 4 MiB serialized-data budget for up to two minutes. Evidence stays in memory and is discarded on reload or close. Students see connection, dropped-evidence and device-interruption messages. Authentication failures stop evidence uploads and clear the recovery queue; the student must restore their session before uploads can resume.

Each capture carries a stable request token and its original capture time. The server checks attempt ownership, bounds timestamps and prevents duplicate retry records. Evidence captured before completion can arrive for up to two minutes after the attempt finishes. Browser capture times are advisory; server receipt times are retained.

Periodic screen captures are optional, consume storage under the existing retention policy, and are excluded from misconduct scoring, suspicious-activity totals and AI review. A coverage gap is information for a human reviewer: it does not establish misconduct or prove whether a device was active. The browser cannot attest that an unmodified client supplied the evidence.

## 2. Configurable identity rechecks

Administrators can combine the four recheck controls. Defaults preserve existing reuse. Policies apply before new attempts; an in-progress exam is not interrupted. Approved per-student requirement waivers continue to work, including a forced ID requirement when the site default is off.

Every-attempt verification requires a successful check in the current authenticated session, followed by starting within ten minutes. Preflight consumes that pass, and Moodle's attempt-start event binds the record to the attempt actually created. A second tab cannot reuse the consumed pass to start another attempt. If attempt creation fails after preflight, the student must verify again.

Profile-name and policy fingerprints are saved with successful checks. Policy changes cover the provider endpoint, required document sides, enabled identity checks and effective score rules; rotating an API credential alone does not invalidate a pass. Enabling fingerprint-based invalidation requires a fresh check for older records without fingerprints. Expiry uses the original verification time, falling back to creation time for legacy records.

## 4. Device and network readiness

The quiz and preflight pages link to **Check devices and connection**. The standalone page tests camera access, an optional microphone level, whole-screen selection and a small synthetic upload to Moodle. Permission, missing-device, insecure-context, busy-device and connectivity problems have actionable messages. Tests run only after the student's click and release their streams automatically or with **Stop device tests**.

Device previews remain local; no camera, screen or audio evidence is uploaded by readiness. The connection check sends 32 KiB of synthetic text. Moodle separately sends cached HTTPS HEAD probes to the face/identity endpoints used by the quiz, without student images. Students receive safe status labels; provider URLs, credentials and raw replies are not returned.

A reachable endpoint is not proof that verification will succeed. Browser/device tests also do not guarantee later exam conditions. Readiness neither creates an attempt nor starts its timer, does not pause an existing attempt, and does not replace required preflight checks.

## Upgrade and rollout

See [release validation](RELEASE_VALIDATION_2026-09-30.md) for the subsequent full GitHub CI results and [dev acceptance checks](DEV_ACCEPTANCE_2026-09-30.md) for the remaining real-device walkthrough. The [storage review](STORAGE_REVIEW_DEV_2026-09-30.md) records a confirmed gap for evidence whose attempt no longer exists; no retention cleanup was performed in that review.

1. Back up the Moodle database and the installed plugin directory.
2. Deploy the complete plugin, including rebuilt `amd/build` files. This release sets version `2026093000` and adds identity snapshots, capture metadata and duplicate-request indexes.
3. From the Moodle root, run `php admin/cli/upgrade.php --non-interactive`, then `php admin/cli/purge_caches.php`.
4. Review the new settings. ID recheck restrictions and periodic screen storage remain off until enabled; readiness is on.
5. Exercise a representative quiz with a student test account in supported browsers, including denied permissions, a brief disconnect, session expiry, submission and resuming the same attempt. Check the resulting coverage report and chosen ID policy.

The earlier audit's HTTPS/TLS and server-preflight requirements also apply; see [SECURITY_AUDIT.md](SECURITY_AUDIT.md). Remaining optional improvements are listed in [FEATURE_IMPROVEMENTS.md](FEATURE_IMPROVEMENTS.md).

## Validation

- JavaScript: **51 tests passed**, with no failures or skips (`node --test tests/js/*.test.js`). Coverage includes queue bounds, retry classification, clock skew, stream cleanup, session-failure handling, preloaded offline messages and source/built viewer escaping.
- All **eight changed/new AMD modules** parse, register the correct module names and match builds regenerated from their current sources.
- Both new Mustache templates parse; **seven rendering/escaping checks passed**.
- Syntax checks passed for **all 116 plugin PHP files**. Moodle coding checks pass for the new feature code; seven existing style errors in `lib.php` and two existing report warnings remain unchanged.
- **85 distinct focused Moodle tests passed** across the validation runs on Moodle 4.5.13+, PHP 8.3.14 and MariaDB 11.5.2: schema upgrade/preservation (1), identity policies (11), coverage/recovery (12), readiness (8), and existing authorization, preflight, privacy and quiz-rule regressions (53). The final combined regression run passed 66 tests / 291 assertions; the final readiness run passed 8 tests / 67 assertions. The repeated identity event-binding check is counted only once in the distinct total. Runs used stop-on-error/failure and fail-on-risky checks.
- `git diff --check` passes. Local test transcripts and `FEATURE_VALIDATION.md` are retained in the sibling `_security_test_env` directory, which contains a deny-all `.htaccess` rule.

Test cleanup restored the original Moodle configuration and PHPUnit XML byte-for-byte, removed the temporary plugin junction and stopped the isolated database listener. The existing database services and plugin source were preserved.

The focused results above predate the subsequent full GitHub matrix: all six Moodle environments ran 264 tests with 67,920 assertions successfully, as recorded in [release validation](RELEASE_VALIDATION_2026-09-30.md). Live cameras, screen-selection dialogs, visual layout and real biometric comparisons still require the student acceptance walkthrough; offline tests and provider readiness probes do not establish those flows.

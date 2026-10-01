# Dev deployment record — 2026-09-30

**Status: deployed and verified.** Release 1.11.0, including the security fixes and selected improvements 1, 2, and 4, is live on [dev.sylr.org](https://dev.sylr.org). The database upgrade and cache purge completed successfully. Maintenance is off and the original cron setting is restored.

The cutover ran on September 30, 2026 from 21:42:44 to 21:45:56 UTC (4:42–4:45 p.m. America/Chicago). The SSM deployment command was `299c1bef-efe4-4a61-8754-562e8fc8b300` and returned `Success`.

## Target and artifact

| Item | Value |
| --- | --- |
| Site | [dev.sylr.org](https://dev.sylr.org) |
| EC2 instance | `i-04c58928fad484d97` |
| Moodle root | `/var/www/html/moodle` |
| Installed environment | Moodle 4.5.6+ / PHP 8.3.6 |
| Previous plugin version | `2026092500` |
| Deployed release | `quizaccess_proctoring` 1.11.0 / `2026093000` |
| Artifact contents | 134 runtime files, including rebuilt AMD assets |
| Artifact SHA-256 | `4bac4d1925ea4b530d4b257a1572c0dfe5094aa715fddcaf415ee468d9be9313` |

Deployment scripts, manifests and detailed verification results are retained in an operator-managed workspace outside this repository. At cutover, the runtime artifact was built from the working tree, including new files; its checksum identifies the deployed contents independently of a later Git commit or release tag.

### Live-walkthrough correction

The student walkthrough exposed a readiness check that could report success without decoded video frames and screen-monitor errors that hid the failing stage. A five-file correction was deployed afterward through SSM command `a796c856-9ebb-40fd-81e1-0774ecc59242` (`Success`): readiness source/build, readiness string preloads, English strings, and the screen-monitor page. Camera/screen readiness now requires playback and decoded frames within five seconds. Monitor playback is also bounded and reports separate permission, capture, playback, and missing-frame failures; whole-screen and marker requirements stay enforced.

The correction archive SHA-256 is `562e6e130c359466189c8f845605f7ae30c12546ddc3ad904169675105ce6eff`. All five previous file hashes matched the original deployment before replacement, and all five updated hashes were verified afterward. PHP lint and **88 JavaScript tests** passed, including the same readiness cases against source and shipped AMD. The dev-only guard verified no recent active attempts; caches were purged and maintenance was disabled successfully. The five prior files are backed up under `/var/backups/moodle-proctoring/dev.sylr.org/20260930-preview-fix`. No schema, version, policy, Lambda, or other-site change was needed for this correction. Live acceptance remains tracked separately in [the acceptance checklist](DEV_ACCEPTANCE_2026-09-30.md).

## Dedicated AWS verification endpoint

The dev Lambda is `moodle-proctoring-face-verify-dev`, alias `dev`, published version `1`. The existing shared Lambda was left unchanged.

- Base: `https://gdkt6gk4oaherkygxzslkjmpsq0zazzp.lambda-url.us-east-1.on.aws/`
- Configured Moodle face endpoint: `https://gdkt6gk4oaherkygxzslkjmpsq0zazzp.lambda-url.us-east-1.on.aws/verify-face`
- Configured Moodle ID endpoint: `https://gdkt6gk4oaherkygxzslkjmpsq0zazzp.lambda-url.us-east-1.on.aws/verify-id`

Endpoint checks returned authenticated HEAD **204**, unauthenticated **401**, and invalid ID input **422**. The service's **13 Python tests passed**. Moodle's own HTTPS provider checks report both face and identity services reachable. Published Lambda code SHA-256 is `f472ea8d83c2d9e5c08967b1f12401caa050cb4414973e9d7cca7935fd25bbae`. The original function's code hash and modification timestamp were verified unchanged. No API keys or other secret values are recorded here.

## Cutover and backups

The private backup location is `/var/backups/moodle-proctoring/dev.sylr.org/20260930-2026093000`. It contains the Moodle configuration, database dump, previous plugin directory and archive, original cron-state record, and cron restoration helper. The compressed database dump is 441,599,074 bytes. Database and plugin backup checksums pass. The root-owned backup directory is mode `0700`; the database dump, configuration, client credentials and control-state files are mode `0600`.

No recent active quiz attempts were found before cutover. Moodle CLI maintenance blocked web access; scheduled and ad hoc tasks were disabled and drained before the snapshot. Same-filesystem renames installed the plugin, Moodle upgraded successfully, and caches were purged before reopening. All 134 deployed artifact files match the manifest. Capture metadata, duplicate-request indexes, identity metadata, readiness AJAX registration, and both new attempt observers were verified on the live host.

Moodle also initialized the previously absent `exacomp/assessment_preconfiguration` setting through its normal upgrade default-setting process. No Moodle core code or other site's plugin code was deployed. The host has approximately 2.5 GiB of free disk space after retaining the backups.

Verified new-setting values:

| Setting | Value |
| --- | --- |
| `readinessenabled` | `1` |
| `idverificationmaxage` | `0` — unlimited reuse |
| `idverificationeachattempt` | `0` |
| `idverificationnamechange` | `0` |
| `idverificationpolicychange` | `0` |
| `monitoringcoveragescreens` | `0` — periodic screen storage off |

## Pilot identity policy update — September 30, 2026

After deployment, the approved pilot enabled `idverificationnamechange=1` and `idverificationpolicychange=1`. The maximum age remains `0` (unlimited), and `idverificationeachattempt` remains off. The values were read back successfully after the update; the table above records the original cutover defaults. A pre-change policy snapshot is retained with the private deployment backups.

One existing successful ID record lacks the new fingerprints. Where ID verification is required, that legacy pass will require a fresh check before a new attempt under the pilot policy. In-progress attempts are not interrupted. These settings still need the representative student acceptance test described below.

## Validation and remaining checks

Local release validation records **85 distinct focused Moodle/PHP tests** and **51 JavaScript tests** passed; see [FEATURE_RELEASE_NOTES.md](FEATURE_RELEASE_NOTES.md) for scope and limitations. These results are separate from deployment verification on this host.

Live HTTPS checks passed: homepage and login return **200**, anonymous readiness access redirects to login, and the published `deviceReadiness` and `evidenceQueue` JavaScript assets match the local release hashes. Server verification command `0164d38b-8b7e-42f8-bea8-7ad4436f736a` returned `Success`; backup permissions were explicitly tightened and verified afterward.

Real cameras, screen-selection dialogs, and real-ID verification through the complete Moodle-to-AWS flow have **not** been tested. A representative student test should still cover device permissions, preflight, attempt resume, interrupted evidence uploads and coverage reports. Provider reachability confirms authentication and connectivity, not successful biometric comparison. The full cross-version CI matrix was not run during this deployment.

## Preflight submission correction — September 30, 2026

The laptop walkthrough exposed missing IDs on hidden preflight controls: screen sharing completed in the browser but its submitted confirmation stayed `0`. The correction adds explicit IDs, restores fresh server-validated face state on form redisplay, and displays server errors above the stepper. The face evidence lifetime, requirement enforcement, identity policy and verification service are unchanged.

Five runtime files (`rule.php`, the English language file, and the `startAttempt` source, build and source map) were deployed with archive SHA-256 `3fc9c3fac508428439ebb4a57f36c8b1f45bbd4265db374efd488572b9b13707`. SSM command `95233d96-2304-4a12-8c00-af23f59f694c` succeeded. The exact site root, release version, maintenance state and absence of recent active attempts were checked before a brief maintenance interval. All before/after hashes passed, PHP syntax passed, caches were purged and maintenance was disabled. A private file-level rollback copy is at `/var/backups/moodle-proctoring/dev.sylr.org/20260930-preflight-fix`; no database/schema or Lambda update was needed.

Local validation passed 102 JavaScript cases and 12 focused Moodle cases / 61 assertions. The new real-form test failed on the prior missing-ID markup and passed on the corrected markup. It covers browser-selected controls reaching the actual POST, student and administrator preview forms, failed/missing CAPTCHA, and unconfirmed-screen rejection. Physical-device start/review acceptance remains pending the user's retry.

## Attempt-page layout correction — September 30, 2026

A Chrome walkthrough of attempt `4745` (cmid `1967`) showed the webcam, a second face image and the screen-check marker crowding each other under the quiz navigation. The second image was the face-detection crop (`#cropimg`), which had no hiding style on the attempt page. The marker was pinned to the navigation block's bottom edge, the same spot the docked webcam occupies, while the status panel's screen slot stayed empty. Commit `e50427f` hides the crop, which is still produced and uploaded, and reserves the marker's height in the screen slot so the webcam sits below it. The marker stays fixed outside the panel and falls back to its previous position whenever the slot is not fully in view, so it never leaves the shared screen. Marker verification, the whole-screen requirement and every upload are unchanged.

Four runtime files (the `proctoring` source, build and source map, and `styles.css`) were deployed at 00:50:30 UTC on October 1 (7:50 p.m. America/Chicago) through SSM command `5c6a9aaf-d715-4f2e-b3f7-54041a839cbd` (`Success`). An earlier attempt, `1551fccc-f3e8-4cdd-b139-b67ab9f6eb13`, failed under the default `sh` shell before touching any file. The host fetched each file from the public repository at commit `e50427f`. Before replacement, the site root, release version `2026093000` and all four prior hashes were verified against commit `f297671`. Each download matched its expected SHA-256 before installation and again after installation, and caches were purged. Maintenance mode was not used: this was a JavaScript and CSS change with no schema or version change, and the only active attempt was the tester's own. A private file-level rollback copy is at `/var/backups/moodle-proctoring/dev.sylr.org/20261001-005030-layout-fix`. The live site serves the updated build and stylesheet.

| File | SHA-256 after deployment |
| --- | --- |
| `amd/src/proctoring.js` | `f6b4090521a2a4c72f85ba449b99e5255c04ec1760dfc48eae5d6ce8862d4949` |
| `amd/build/proctoring.min.js` | `fc06f5af3f96c1677608e9aee6da2d8a58da568812ab57ae87771654c74348de` |
| `amd/build/proctoring.min.js.map` | `0267e8529cc38e85c88a1480245d672be7b2e767ffdf8319d7cb5f04608f0912` |
| `styles.css` | `867b1221111ab08b3430f0ad404560a662d2f8028962b49cbac045c795bc6f87` |

Local validation passed 102 JavaScript cases, and the rebuilt AMD file parsed. No Grunt toolchain was available, so the build was produced with terser 5. The corrected layout has not yet been confirmed visually on a physical device.

### Open finding: Safari camera coverage

An earlier Safari 26.6 walkthrough of the same attempt showed the coverage-gap banner. Its preflight camshot uploaded, but the attempt page made no `send_camshot` calls while sending 151 activity events. The 1.11.0 capture guard skips frames from muted or paused camera tracks. The suspected cause is that Safari mutes the attempt page's camera while the screen-monitor helper window holds the screen capture. This has not been confirmed. The retest options are Chrome on the same device, or Safari with `screensharepersistencemode=main`. No setting was changed.

## Full-page preflight monitoring correction — September 30, 2026

A Safari walkthrough on cmid `1968` showed the webcam circle, screen marker and coverage banner on the start-attempt checklist, while the checklist rail and Turnstile security check were blank. "Start a new preview" had posted to `startattempt.php`, which renders the preflight as a full page. Moodle also calls `setup_attempt_page()` there, so live attempt monitoring started with no attempt. That monitoring's notification cleanup hides every `.alert` after a face is found, and the checklist panel and security check both carry that class. The same thing affects any full-page preflight, including a redisplay after a failed server check. Commit `6487aba` returns early from `setup_attempt_page()` on `startattempt.php`. The preflight uses its own record (`id` 0), so nothing it needs is skipped. Attempt pages are unchanged.

Only `rule.php` was deployed, at 01:19:45 UTC on October 1 (8:19 p.m. America/Chicago), through SSM command `5e3b30e8-5ff5-42da-8069-0502cd3e5287` (`Success`). The site root, release version `2026093000` and prior hash `f3bed0dcc11a487d7e382567214c7be46d236ca9becc2de65ffcab7fa9e2f64b` were verified first. The file was fetched from the public repository at commit `6487aba`. Its hash matched before and after installation (`eefb95622c4cc36d8b0959da00a421ce181aeca46599301aeb27f0923a9570f3`). PHP lint passed on the host, caches were purged, and the homepage returned **200**. Maintenance mode was not used: there was no schema or version change. The rollback copy is at `/var/backups/moodle-proctoring/dev.sylr.org/20261001-011945-preflight-monitoring-fix`.

Locally, `test_startattempt_preflight_page_does_not_start_monitoring` fails on the prior code and passes on the fix. `monitoring_coverage_test.php` (13 tests / 63 assertions) and `preflight_form_integration_test.php` (6 tests / 42 assertions) pass. The Safari camera finding above still needs a clean retest after this correction.

## ID capture guide correction — September 30, 2026

In Safari 26.6 on a MacBook Pro, **Capture ID image** stayed disabled. A console probe showed the ID camera live at 1920×1920, the guide displayed at 352×222 with "ID not in window", and the button disabled with that title. The red guide rectangle was not visible in the student's view: Safari composited the playing `<video>` above the absolutely positioned guide, so there was no target to hold the card in. Commit `28c0b61` gives the video `z-index: 0` and the guide `z-index: 1`. Detection thresholds and capture logic are unchanged. The black face-camera box on that step is expected; it starts only when **Verify ID** is pressed.

Only `styles.css` was deployed, at 01:31:11 UTC on October 1 (8:31 p.m. America/Chicago), through SSM command `0cadfff9-f5d5-4858-bf79-091084f00a4c` (`Success`). The site root, release version and prior hash `867b1221111ab08b3430f0ad404560a662d2f8028962b49cbac045c795bc6f87` were verified first. The file was fetched at commit `28c0b61` and matched `84461a121b0c5ce4876ac541deab078f08cd0d0a7892cd08fddfad15ae590b76` before and after installation. Caches were purged, the homepage returned **200**, and the live stylesheet contains the change. The rollback copy is at `/var/backups/moodle-proctoring/dev.sylr.org/20261001-013111-id-guide-fix`. The fix has not yet been confirmed in Safari. If the card is still not detected once the guide is visible, the detection heuristics are the next suspect.

## Screen-share status grace correction — September 30, 2026

A Chrome preview attempt (`4747`, cmid `1968`) logged one `screen_share_stopped` (`persistent_monitor_unavailable`) at 20:45:09, four seconds after the first quiz page loaded. The share never ended: none of the later page changes logged anything, and the submit-time event at 21:50 carried a desktop capture. `screen_share_stopped` scores as a risk factor, so the false event very likely accounts for most of that attempt's 23/100 Moderate score.

The cause is a race between the quiz page and the background helper window. On page load, the quiz page read the helper's stored status before the helper had replied. Browsers throttle the helper's timers, so that status can be up to a minute old. Any status that was not ready logged the stop immediately. Commit `0743e6a` makes three changes:

- On page load, the quiz page ignores a stored status older than 5 seconds and waits for the helper's live reply.
- Only a fresh (≤ 5 s) `stopped` status from the helper ends the share at once. Any other not-ready status must persist for 10 seconds, and a ready reply cancels it.
- The event detail now records `statusage` in seconds, `helperready` and `helperstopped`.

The 30-second marker grace is unchanged. Existing events and scores were not modified.

Six runtime files were deployed at 02:59:32 UTC on October 1 (9:59 p.m. America/Chicago) through SSM command `5d7c3fcf-b219-46c3-adcf-b183326d005e` (`Success`): the `proctoring` and `screenMonitorClient` sources, builds and source maps. The site root, release version and all six prior hashes were verified first. Each file was fetched at commit `0743e6a` and matched its expected SHA-256 before and after installation. Caches were purged, the homepage returned **200**, and both live builds contain the change. The rollback copy is at `/var/backups/moodle-proctoring/dev.sylr.org/20261001-025932-helper-status-grace`.

Locally, 108 JavaScript tests pass. The three new quiz-page cases cover a stale status followed by a ready reply (no event), a fresh stop (immediate event with diagnostics) and a silent helper (one event after 10 s). They fail on the prior code. Three new client cases cover the 5-second startup freshness rule; one of them fails on the prior code. The new cases also pass against the shipped minified builds. The builds were produced with terser 5. The fix has not yet been confirmed on a real attempt.

## Manual rollback cautions

Stop or drain scheduled jobs and other writers before restoration. Restore the database snapshot and matching prior plugin code together; a code-only rollback does not reverse the schema or settings upgrade. Restoring the snapshot loses database writes made after it was taken, so assess subsequent activity first. Recover the original provider URLs and settings from the database backup, then restore the recorded maintenance and cron state and verify the site. The dedicated dev Lambda and the unchanged shared Lambda must remain clearly distinguished.

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

## Manual rollback cautions

Stop or drain scheduled jobs and other writers before restoration. Restore the database snapshot and matching prior plugin code together; a code-only rollback does not reverse the schema or settings upgrade. Restoring the snapshot loses database writes made after it was taken, so assess subsequent activity first. Recover the original provider URLs and settings from the database backup, then restore the recorded maintenance and cron state and verify the site. The dedicated dev Lambda and the unchanged shared Lambda must remain clearly distinguished.

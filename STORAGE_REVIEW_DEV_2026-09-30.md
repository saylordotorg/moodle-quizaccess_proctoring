# Dev storage and retention review — 2026-09-30

**Read-only findings; no cleanup or configuration changes performed.** The root filesystem is 92% full, with 2.45 GiB available. This is principally shared-host capacity pressure. A separate, confirmed proctoring retention gap leaves records whose quiz attempts no longer exist outside the automatic cleanup query.

## Verified scope

Inspection ran at 22:02–22:07 UTC through AWS SSM on `i-04c58928fad484d97`, `us-east-1`. Moodle's configuration matched `https://dev.sylr.org`, code `/var/www/html/moodle`, and data `/var/www/moodledata`; plugin version was `2026093000` (1.11.0). The deployed retention-task checksum matched the reviewed local source.

Only aggregate directory sizes, the dev Moodle database, allowlisted settings, and task metadata/error-marker counts were inspected. No student identities, image contents, credentials, raw task output, or other sites' database contents were retrieved. Shared-host paths below were measured only as aggregate sizes. No files were deleted, cleanup jobs invoked, or AWS/site settings changed.

## Capacity findings

The filesystem has 28.02 GiB total and 25.56 GiB used; inode use is only 16%. Major allocations explain the pressure:

| Allocation | Size | Interpretation |
| --- | ---: | --- |
| Active swap | 6.00 GiB | Active system allocation; not disposable cleanup space. |
| `/var` | 11.82 GiB | Includes the following shared web, database, log, and backup totals. |
| `/var/www` | 6.61 GiB | Dev code and data account for 2.37 GiB; other contents were not examined. |
| `/var/lib/mysql` | 2.48 GiB | Entire database engine, potentially including other sites. |
| `/var/log` | 0.93 GiB | System journals account for 670 MiB. |
| `/var/backups` | 0.56 GiB | Current dev rollback backup accounts for 0.50 GiB. |
| `/root` | 3.86 GiB | Aggregate only; contents and ownership purpose not inspected. |
| `/usr` | 3.08 GiB | Installed system files. |
| `/tmp` | 0.72 GiB | Aggregate only; active-file safety not assessed. |

Rows overlap; do not sum the table. The current rollback backup is `/var/backups/moodle-proctoring/dev.sylr.org/20260930-2026093000` and should remain available during release acceptance.

Dev Moodledata is 1.36 GiB, including 1.26 GiB in `filedir`. `trashdir` is only 4 KiB, temporary data 1.39 MiB, and cache plus localcache about 50 MiB. There is no large Moodle trash/cache backlog to clear.

Dev database table/index estimates total 1.37 GiB. The largest tables include standard activity logs (236 MiB), outcome-map snapshots (234 MiB), outcome-map results (209 MiB), and AI course-assistant chunks (176 MiB). These are not proctoring tables. InnoDB estimates and filesystem allocation differ; deleting database rows does not necessarily return space to the OS.

## Proctoring storage and retention

Both capture and ID-image retention are configured to **30 days**. Capture cadence is 30 seconds; periodic coverage screenshots remain disabled (`monitoringcoveragescreens=0`).

| File area | Files | Logical size |
| --- | ---: | ---: |
| Violation screenshots | 925 | 423.9 MiB |
| Webcam pictures | 259 | 23.1 MiB |
| Face crops | 121 | 4.7 MiB |
| Reference photos | 4 | 1.4 MiB |
| ID document and live image | 2 | 0.31 MiB |

Total logical proctoring storage is 453.4 MiB, representing 991 distinct content hashes and 307.9 MiB of distinct content. Moodle deduplicates files, so neither logical size nor distinct-content size is a guaranteed reclaim amount; shared references and retained evidence must be considered.

**The capture-retention gap is confirmed:** all 354 capture-log rows are older than 30 days, but 352 reference nonexistent quiz attempts and two reference unfinished attempts. None match the cleanup rule requiring a finished, sufficiently old attempt. The deletion queue and newly eligible counts are both zero, including under a hypothetical 90-day window. All 2,333 browser-event rows are older than 30 days; 2,262 lack a matching attempt. All 925 screenshot files and all 259 webcam files are older than 30 days. The inspection did not establish why those attempts disappeared or whether every file is safe to remove.

The ID-verification table contains one record, modified September 2; it is not yet 30 days old and is correctly ineligible at the inspection time. Reference photos are not covered by the finished-attempt retention rule.

## Cleanup health

The plugin cleanup task is enabled every minute, with no failure delay; its latest observed pass was **22:03:02 UTC**. The available seven-day task-log window contained 212 reported passes, zero failures, zero caught-error markers, and zero database writes. This agrees with the zero eligible records, rather than a stopped scheduler.

Core file cleanup is enabled every six hours: latest observed pass **17:55:01 UTC**, next due **23:55 UTC**. All 20 available recent entries passed. Physical trash cleanup last occurred **September 29 at 23:55:01 UTC**, with the normal 86,400-second interval. Task-log retention is 28 days plus a configured 20-run limit, so available history does not prove uninterrupted operation throughout seven days.

Reviewed code queues at most 250 expired capture logs and deletes at most 10 per run; ID cleanup processes at most 50 records per run. The cleanup task catches exceptions without rethrowing them, which can make a failure appear successful to the scheduler. No such error marker was observed in the available history.

## Recommended next actions

1. **Plan host capacity first.** A separately approved volume expansion is less destructive than deleting unclassified shared-host data. Review host-owned files, temporary data, and journal retention with their owners. Do not remove active swap or unrelated sites' content. Proctoring cleanup alone cannot materially resolve the 92% utilization.
2. **Define and implement orphan-evidence retention.** Include missing-attempt and abandoned/preflight cases with an explicit age policy, protected-review/hold exclusions, a dry-run inventory, and tests. Use Moodle's File API and normal trash cleanup; do not remove hashed files directly or infer deletion safety solely from a missing attempt.
3. **Make cleanup failures observable.** Propagate/report exceptions and expose eligible, queued, deleted, and skipped counts so a successful task record cannot conceal failed cleanup.
4. **Review broader dev retention separately.** Standard logs, outcome-map data, AI data, and future backups are larger ongoing growth areas. Keep the current rollback backup until acceptance completes; only remove local backup copies after an approved retention decision and verification of a recoverable replacement.

This was an inspection, not a deletion dry run or a complete host/security audit. File ages are not substitutes for retention eligibility. No volume changes, object-storage/Lambda inventories, cohosted-site inspections, or new tests were performed.

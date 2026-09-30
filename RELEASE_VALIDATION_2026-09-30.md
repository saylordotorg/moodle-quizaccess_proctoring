# Release validation — 2026-09-30

Release 1.11.0 is recorded in draft [PR #29](https://github.com/saylordotorg/moodle-quizaccess_proctoring/pull/29). The plugin and optional verification-service source are versioned together; the service is deployed separately. The committed Lambda source matches the source in the deployed dev archive exactly.

## GitHub CI

All eight jobs passed on commit `da95f8d0a85cffea5d0ab271e6e4579a7324f737` in [workflow run 36784618197](https://github.com/saylordotorg/moodle-quizaccess_proctoring/actions/runs/36784618197). Each PHP job ran the complete plugin suite with the explicit empty-suite guard enabled. This run predates the live-discovered preview correction; the PR checks show validation of that subsequent change.

| Environment | Result |
| --- | --- |
| Moodle 4.5 / PHP 8.1 / MariaDB 10.11 | 264 tests, 67,920 assertions passed |
| Moodle 4.5 / PHP 8.3 / MySQL 8.4 | 264 tests, 67,920 assertions passed |
| Moodle 4.5 / PHP 8.3 / PostgreSQL 16 | 264 tests, 67,920 assertions passed |
| Moodle 5.2 / PHP 8.3 / MySQL 8.4 | 264 tests, 67,920 assertions passed |
| Moodle 5.2 / PHP 8.4 / PostgreSQL 16 | 264 tests, 67,920 assertions passed |
| Experimental Moodle main / PHP 8.4 / PostgreSQL 17 | 264 tests, 67,920 assertions passed |
| JavaScript regressions | 51 tests passed |
| Verification service / Python 3.12 | 13 offline tests passed; no skips |

The PHP rows repeat the same 264-case plugin suite across different supported environments; they are not distinct added tests. Positive test counts were verified from job logs, including the new identity, monitoring-coverage, and readiness cases. CI uses each Moodle installation's generated root configuration rather than the plugin's legacy PHPUnit 9 configuration and rejects an empty test suite explicitly.

Required PHP lint, plugin validation, upgrade-savepoint, and Mustache checks passed. The existing informational codechecker step still reports style errors/warnings and is nonblocking; a green workflow does not mean the repository is style-clean. Newer Moodle lanes report 156 nonblocking PHPUnit deprecations; those are compatibility-maintenance work, not a claimed clean deprecation result. The full Linux runs completed the database-heavy property cases that had made the earlier Windows run slow.

Locally, JavaScript tests were rerun successfully, PHP 8.1 syntax checks passed, and the verification service's 13 cases and optional server-adapter checks passed. A read-only dependency advisory scan with pip-audit 2.10.1 reported no known vulnerabilities for the pinned service requirements. No dependency audit guarantees the absence of undiscovered vulnerabilities.

After the student walkthrough exposed premature device-readiness success, the preview correction passed **88 JavaScript tests** and PHP syntax checks. The readiness cases run against both source and rebuilt AMD. Monitor tests cover denied requests, playback rejection, bounded hanging playback, missing frames, navigation cleanup, and the unchanged whole-screen/marker gates. The five corrected runtime files were deployed to dev with verified before/after hashes; the follow-up PR workflow reruns the complete CI matrix.

## Dev policy and remaining acceptance

The user-approved dev pilot enables rechecks after profile-name and identity-policy changes. Expiry remains unlimited and every-attempt verification remains off. Settings were read back successfully and their previous values were saved privately. One legacy successful ID record lacks fingerprints and therefore needs a fresh check before a new attempt under these policies. Distribution defaults and periodic screen storage remain unchanged.

The [student acceptance checklist](DEV_ACCEPTANCE_2026-09-30.md) is in progress with Demo Student on user-selected quiz `1967`. The upload and both provider checks passed; preflight completed privacy, honesty, CAPTCHA, ID verification and face registration. The monitor-window screen share failed and Start correctly stayed disabled. Investigation found readiness could claim success before video frames arrived, prompting a bounded playback/frame check and clearer monitor errors. Camera/screen readiness needs repeating with that correction, followed by the remaining attempt/review steps. No student credentials or identity images were requested in chat or written to this repository, and no completed attempt is claimed.

The [storage review](STORAGE_REVIEW_DEV_2026-09-30.md) confirmed 92% disk utilization, mostly shared-host allocations, and a retention gap for evidence whose attempt no longer exists. No evidence was deleted, retention semantics changed, or volume resized. The current rollback backup remains available.

Keep the PR in draft until the current-head CI and manual acceptance results are reviewed. No production deployment or merge was performed in this release-preparation pass.

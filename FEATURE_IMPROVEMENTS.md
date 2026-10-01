# Feature improvements to choose

Choices **1, 2 and 4** are implemented in 1.11.0 and deployed to dev. See [FEATURE_RELEASE_NOTES.md](FEATURE_RELEASE_NOTES.md) for settings, defaults and upgrade instructions, and [release validation](RELEASE_VALIDATION_2026-09-30.md) for CI and remaining acceptance checks. The remaining choices below are proposals awaiting selection. Effort is relative: small means a localized change; medium spans UI, storage or workflow; large requires a broader design and integration work.

| Choice | Improvement | What you would get | Priority | Effort |
| --- | --- | --- | --- | --- |
| 1 — Implemented | Monitoring coverage and connection recovery | Received-evidence gaps and delayed uploads in attempt reports; bounded recovery after short interruptions; optional periodic screen captures, off by default. | High | Medium |
| 2 — Implemented | Configurable identity rechecks | Choose when a passed ID check expires and whether a new attempt, profile-name change, or changed identity policy requires verification again. | High | Medium |
| 3 | Trusted face enrollment | Choose student self-registration or staff/ID-approved reference photos. Record who approved the reference and support controlled replacement and optional liveness checks. | High | Large |
| 4 — Implemented | Device and network readiness check | A separate page to test camera, microphone, whole-screen sharing, permissions and connection readiness before starting an attempt. | High | Medium |
| 5 | Review history and student appeals | Record who released/confirmed a hold or changed a note, show a readable decision timeline, and let students submit an explanation to authorized reviewers. | Medium | Medium |
| 6 | Evidence retention dashboard | Show storage use, oldest evidence, upcoming deletion counts and cleanup failures. Preview policy changes and evidence tied to deleted attempts, with explicit age and hold rules before cleanup. | High | Medium |
| 7 | Provider health and spending controls | Show request failures, response times and estimated usage; add configurable daily budgets, retry limits and outage handling. | Medium | Medium |
| 8 | Faster review workspace | Add assignment, saved filters, evidence timeline navigation and bulk decisions with authorization checked for every selected attempt. | Medium | Medium–large |

Choices **3, 5, 6, 7 and 8** remain available. The [dev storage review](STORAGE_REVIEW_DEV_2026-09-30.md) makes **6** the recommended next choice: the host is 92% full and existing cleanup skips evidence whose attempt no longer exists. Choice **3** matters most if a face match is expected to establish identity independently of ID verification.

Browser monitoring remains evidence supplied by a student's device. Liveness and coverage checks can improve confidence, but ordinary page JavaScript cannot prove that a modified client is reporting honestly.

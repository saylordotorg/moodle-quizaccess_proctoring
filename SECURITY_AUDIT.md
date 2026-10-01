# Security audit — 2026-09-30

## Scope and status

Reviewed the local quizaccess_proctoring plugin, its browser code and built AMD assets, external/AJAX functions, report and override pages, file access, outbound integrations, background processing and Moodle Privacy API. At the end of the original audit, the fixes were local working-tree changes. Subsequent versioning, dev deployment and validation are recorded below; this source audit does not certify a live installation.

Baseline: commit 59afe62 on master, plugin release 1.10.5. The audit fixes and selected feature follow-ups are recorded under 1.11.0 in CHANGELOG.md. This document's validation section records the original audit run; see [FEATURE_RELEASE_NOTES.md](FEATURE_RELEASE_NOTES.md) for subsequent feature validation.

Subsequent release preparation is recorded in [RELEASE_VALIDATION_2026-09-30.md](RELEASE_VALIDATION_2026-09-30.md), including full GitHub matrix results, the dev deployment, and outstanding device acceptance and retention findings. The optional service is now versioned under `tools/verification_service`; the sibling copy described below is the original audit source.

The optional sibling ai_service Python bridge was also reviewed and patched. It sits outside the proctoring Git repository and must be deployed separately if used.

Severity below describes the pre-fix behavior. Findings are grouped by security boundary; some groups contain multiple related defects. The review used source inspection and regression testing. It is not an exhaustive penetration test of a running production site or of third-party providers.

## Confirmed findings and fixes

| Severity | Finding | Fix |
| --- | --- | --- |
| High | Preflight checks were requested only from view.php, so a direct request to Moodle's attempt-start handler could skip the entire plugin preflight. | Require checks for new attempts regardless of caller, and for explicit browser resume/start pages. Regression coverage exercises Moodle's real access manager. |
| High | Face validation was enforced in browser UI only; forced per-student CAPTCHA/ID/face/screen settings were not consistently enforced by server validation. | Record completed face checks in the authenticated session, bound to user and module, with a ten-minute lifetime and consumption after preflight. Use shared override resolution for rendering and validation. Approved waivers still work. |
| High | Browser capture requests could claim the staff reference-image namespace. Independent log/reference record IDs could collide, contaminating face-image selection or deletion. | Force browser captures to camshot_image; scope reference and capture deletion by parent type and delete corresponding stored reference-face files. Preflight captures use attempt ID zero until an attempt exists. |
| High | Profile names and image captions could reach JavaScript HTML sinks after escaping had been decoded by the DOM. | Build image elements with DOM attribute setters and render captions as text. Rebuild shipped AMD modules and test both source and built files with hostile strings. |
| High | Report review parameters and override targets could cross the intended quiz/course boundary; some checks used course permissions despite module-level restrictions. | Bind report/user/attempt identifiers to stored records, authorize the actual module, check the scope of override reads/writes/revocations, and filter sibling quiz summaries and selectors. |
| High | Provider flags containing truthy strings such as "false" could synthesize passing fallback scores; disabling face matching could leave only a nonblocking name check. | Require an explicit positive boolean value before supplying fallback scores, keep error/retry/manual responses from becoming a pass, and make the name check mandatory when it is the sole identity check. |
| High | Configurable provider URLs allowed cleartext HTTP, some private/translated address forms escaped the IP filter, and validation and connection DNS lookups were separate. Moodle cURL defaults also did not guarantee peer-certificate verification. | Require HTTPS, public destinations and verified TLS; pin the same checked addresses to the request, honor site cURL restrictions, and disable redirects. Fixed provider and CAPTCHA calls explicitly verify TLS too. |
| High | Privacy erasure could leave identifying event details, attempt links, raw AI evidence and queued face tasks. Override justifications/audit data were omitted from privacy handling. | Delete subject evidence records and related work, include course/module overrides in discovery/export/deletion, and anonymize staff attribution while preserving unrelated students' records. |
| Medium | Image request bounds were checked after normalization and declared MIME types were not consistently compared with decoded bytes. | Bound the original encoded request and accept only supported decoded raster formats whose MIME type agrees with the data URL. |
| Medium | The optional Python bridge lacked bounded request/image handling; malformed Lambda JSON and invalid threshold types could fail with unhandled errors. | Authenticate before parsing FastAPI image bodies, bound body/image sizes, validate positive finite thresholds and input types, compare API keys in constant time, and handle encoded Lambda bodies. |

## Validation

- PHP 8.1 syntax checks pass for the plugin PHP files, including all changed files.
- JavaScript regressions: 21 passed, including hostile names/captions against source and shipped AMD builds. Command: node --test tests/js/*.test.js.
- Optional Python bridge: 10 offline security tests passed with AWS mocked; Python syntax compilation also passed.
- Targeted Moodle integration checks: 76 distinct tests passed across focused runs on Moodle 4.5.13+, PHP 8.3.14 and MariaDB 11.5.2. Coverage includes external authorization, provider verdicts, image input, report scope, preflight enforcement, overrides, file access and privacy deletion. The final two capture cases also pass with PHPUnit's fail-on-risky option after correcting an existing output-buffer ownership bug.
- The full plugin suite was interrupted because its existing property tests made the run slow. Its external API loader errors were reproduced, corrected and covered by the focused reruns; this audit does not claim a complete full-suite pass. Local test transcripts are retained in the sibling _security_test_env directory.
- Moodle coding checks pass for the new security helpers/tests and updated service/preflight code. The seven pre-existing coding-style errors in lib.php remain unchanged (verified against HEAD).
- The GitHub database/PHP matrix and live provider/browser flows have not been run in this session.

Test isolation cleanup restored the original Moodle test connection settings, removed the temporary plugin junction and its generated test-suite entries, and stopped the isolated database listener. Disposable database/data files and transcripts remain in the sibling _security_test_env folder, which has a deny-all .htaccess rule. Automatic approval review blocked temporary-file deletion with the generic reason "blocked by policy"; no deletion workaround was used.

## Deployment implications

- Existing HTTP provider endpoints must move to an HTTPS endpoint with a trusted certificate. The plugin will reject HTTP and invalid TLS certificates.
- When face matching is disabled for ID verification, the name check now determines acceptance even if name matching was configured as advisory. At least one identity check must pass.
- Deploy the rebuilt amd/build assets with the PHP changes and purge Moodle caches.
- Privacy deletion now removes sensitive subject records instead of retaining pseudonymized evidence. This applies when Moodle authorizes an erasure request.
- Previously processed erasures may have left records whose user ID was already cleared. This code change cannot identify their former owner retrospectively; review those retained records under the site's retention policy.
- New attempts must complete preflight on the server. API/mobile clients cannot bypass it by skipping the quiz view page; the browser remains the supported proctoring workflow.
- Optional features are listed separately in FEATURE_IMPROVEMENTS.md.

## Remaining boundaries

- Face self-registration remains supported for compatibility. It establishes a reference image; it is not independent proof of identity or liveness.
- Existing successful ID checks remain reusable by default. The selected follow-up adds configurable expiry, per-attempt verification and rechecks after profile-name or identity-policy changes; administrators must enable the policies they want.
- Webcam, screen-share and browser activity signals originate on the client. A modified browser can forge or suppress them; client flags are not hardware attestation.
- When an outbound proxy resolves destination names itself, network restrictions must also be enforced by that proxy; application DNS pinning cannot govern the proxy's resolver.
- Live third-party provider behavior, production network policy, backups and complete browser/camera flows require deployment-environment validation.

## Reference guidance

The review follows Moodle's [security guidance](https://moodledev.io/general/development/policies/security), [external function context/capability requirements](https://moodledev.io/docs/4.5/apis/subsystems/external/writing-a-service) and [File API access-control responsibilities](https://moodledev.io/docs/5.0/apis/subsystems/files).

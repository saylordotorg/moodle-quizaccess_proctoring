# Dev acceptance checks — 2026-09-30

Release: `quizaccess_proctoring` 1.11.0 / `2026093000` on dev.sylr.org.

The release has passed focused automated tests and deployment smoke checks. The real-device student walkthrough is **pending** an authenticated test-student browser session and a designated test quiz. The opened dev browser currently shows the sign-in page. This checklist is not a claim that those manual steps passed.

Use a disposable test student and quiz with no real academic outcome. Keep ID/camera evidence and account credentials out of the repository and PR. Record only the result, browser, timestamps, and relevant non-sensitive counts. A user must operate their own physical camera, ID presentation, and screen-selection prompts.

| Check | Expected result | Status |
| --- | --- | --- |
| Anonymous readiness access | Redirects to login; no attempt is created | Passed in deployment smoke checks |
| Authenticated readiness | Device tests start only after a click; synthetic upload and provider checks return safe status labels | Pending student session |
| Stop readiness tests | Camera, microphone, and screen streams stop; no device images are uploaded | Pending real devices |
| New-attempt preflight | Required identity, face, and screen checks must complete before the timed attempt starts | Pending real devices; server regression tests pass |
| Permission denied or wrong screen source | Actionable message; no false completed check | Pending real devices |
| Temporary upload failure | Warning appears; bounded retries preserve capture time and do not duplicate evidence after recovery | Pending browser walkthrough; JavaScript/server regressions pass |
| Resume an existing attempt | Original monitoring expectations persist; resume does not add periodic screen collection | Pending browser walkthrough; server regression tests pass |
| Submit and review | Attempt finishes; report displays received captures, delays and gaps without treating coverage alone as misconduct | Pending student/reviewer walkthrough |
| ID reuse with unchanged profile/policy | A current fingerprinted successful check can be reused; no age expiry or every-attempt requirement | Automated policy tests pass; live walkthrough pending |
| Profile-name or identity-policy change | A new attempt requires another ID check; an existing attempt is not interrupted | Automated policy tests pass; live walkthrough pending |

The approved dev pilot sets `idverificationnamechange=1`, `idverificationpolicychange=1`, `idverificationmaxage=0`, and `idverificationeachattempt=0`. One existing successful ID record lacks fingerprints and will require a fresh check before a new attempt. Distribution defaults are unchanged. Periodic screen coverage storage remains off.

Physical device and biometric results cannot be inferred from authenticated provider `HEAD` checks. Keep the release PR in draft until GitHub CI results and the remaining acceptance checks are reviewed. The separate storage review identifies a retention gap for evidence whose attempt no longer exists; this pass did not delete evidence or change retention semantics.

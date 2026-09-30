# Dev acceptance checks — 2026-09-30

Release: `quizaccess_proctoring` 1.11.0 / `2026093000` on dev.sylr.org.

The release passed the full CI matrix and deployment smoke checks before the live walkthrough exposed a readiness defect. Testing uses the Codex in-app browser, signed in as Demo Student on user-selected quiz `1967` (PRDV225: Certificate Final Exam). The 32 KiB upload and both provider reachability checks passed. The original device checks reported success, but camera/screen readiness did not yet require decoded video frames; those results need repeating with the correction. Preflight reached five of six complete, including an explicit "ID verified" result and face registration. The user reported the monitor window rejecting screen sharing, so no attempt started.

Use a disposable test student and quiz with no real academic outcome. Keep ID/camera evidence and account credentials out of the repository and PR. Record only the result, browser, timestamps, and relevant non-sensitive counts. A user must operate their own physical camera, ID presentation, and screen-selection prompts.

| Check | Expected result | Status |
| --- | --- | --- |
| Anonymous readiness access | Redirects to login; no attempt is created | Passed in deployment smoke checks |
| Authenticated readiness | Device tests start only after a click; synthetic upload and provider checks return safe status labels | Upload (168 ms), both providers and microphone passed; camera/screen decoded-frame recheck pending after correction |
| Stop readiness tests | Camera, microphone, and screen streams stop; no device images are uploaded | Stop control exercised after successful previews; lifecycle regressions and source review pass |
| New-attempt preflight | Required identity, face, and screen checks must complete before the timed attempt starts | The laptop reached all required checks, but Start redisplayed preflight. A missing hidden-field ID prevented screen confirmation from being submitted; correction verified in the real Moodle form, live retry pending |
| Permission denied or wrong screen source | Actionable message; no false completed check | Pending real devices |
| Temporary upload failure | Warning appears; bounded retries preserve capture time and do not duplicate evidence after recovery | Pending browser walkthrough; JavaScript/server regressions pass |
| Resume an existing attempt | Original monitoring expectations persist; resume does not add periodic screen collection | Pending browser walkthrough; server regression tests pass |
| Submit and review | Attempt finishes; report displays received captures, delays and gaps without treating coverage alone as misconduct | Pending student/reviewer walkthrough |
| ID reuse with unchanged profile/policy | A current fingerprinted successful check can be reused; no age expiry or every-attempt requirement | Automated policy tests pass; live walkthrough pending |
| Profile-name or identity-policy change | A new attempt requires another ID check; an existing attempt is not interrupted | Automated policy tests pass; live walkthrough pending |

The approved dev pilot sets `idverificationnamechange=1`, `idverificationpolicychange=1`, `idverificationmaxage=0`, and `idverificationeachattempt=0`. At pilot enablement, one existing successful ID record lacked fingerprints and required a fresh check before a new attempt. Distribution defaults are unchanged. Periodic screen coverage storage remains off.

The walkthrough prompted two corrections: readiness now requires fulfilled playback and decoded camera/screen frames within five seconds, and the monitor distinguishes request/permission, playback, and missing-frame failures. All failure paths retain the screen requirement and release failed streams. A regular desktop-browser comparison and the remaining timed-attempt steps are still needed before release acceptance.

Biometric results cannot be inferred from successful readiness previews or authenticated provider `HEAD` checks. Keep the release PR in draft until the remaining acceptance checks are reviewed. The separate storage review identifies a retention gap for evidence whose attempt no longer exists; this pass did not delete evidence or change retention semantics.

The subsequent laptop start loop was traced to `entirescreenconfirmed`: Moodle does not generate IDs for hidden controls, while the browser tried to update `id_entirescreenconfirmed`. The checklist therefore showed screen sharing complete but submitted `0`. Scoped request and session checks confirmed that Start returned the form without creating an attempt and that fresh face evidence remained present. The corrected form explicitly identifies all three confirmation controls. Redisplayed forms also restore still-valid server face evidence and display server errors above the stepper; intentional face retries invalidate restored readiness. Face evidence retains its existing user/quiz scope and ten-minute expiry. The real-form regression fails on the previous markup and passes after the correction; it does not substitute for the remaining physical-device walkthrough.

An existing site-wide embedded Mentor customization emits a JavaScript syntax error across login/course/quiz/readiness pages. Static inspection found its rendered script flattened to one line: a `// Node.COMMENT_NODE` comment consumes the remainder, including closing braces. No matching customization code exists in this plugin. The malformed block does not execute; independent Moodle/plugin scripts continue. Repair its injection source by preserving line breaks or serving a correctly built external script, then parse the rendered output. This pass did not modify the separate customization. The in-app browser also reported unavailable WebGL, but readiness, ID verification, and face registration subsequently completed.

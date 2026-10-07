# Saylor Proctored Quiz

Saylor Proctored Quiz is a Moodle quiz access rule that adds pre-attempt identity checks, webcam capture, screen-sharing checks, browser activity monitoring, risk scoring, review holds, and reporting to Moodle quiz attempts.

The Moodle component name is `quizaccess_proctoring`. The plugin must be installed at:

```text
mod/quiz/accessrule/proctoring
```

## Features

- Webcam capture during proctored quiz attempts.
- Bounded upload recovery after short connection interruptions, with evidence coverage and delayed-upload details for reviewers.
- A device-readiness page for local camera, microphone and whole-screen tests before starting an attempt.
- Optional face registration and pre-attempt face validation using the configured Saylor AI face-match endpoint.
- Optional pre-attempt ID verification with front ID image, optional back ID image, live face image, face threshold, and name threshold.
- Configurable ID-check expiry and rechecks for new attempts, profile-name changes or identity-policy changes.
- Optional honor statement and privacy notice steps before the attempt starts.
- Optional CAPTCHA before new attempts using Cloudflare Turnstile or Moodle reCAPTCHA.
- Optional entire-screen sharing before quiz start and during attempts.
- Browser activity logging for tab visibility, focus loss, page exit, clipboard actions, right-click, shortcuts, possible AI-tool interactions, audio events, screen-share events, and monitor checks.
- Optional clipboard blocking.
- Optional desktop violation screenshots for configured suspicious events.
- Optional periodic screen captures for coverage reporting, disabled by default and excluded from risk scoring and AI review.
- Optional face-in-view blur behavior while the quiz is open.
- Risk scoring based on webcam, face, screen-share, monitor, clipboard, tab/focus, shortcut, audio, and possible AI-tool evidence.
- Optional high-risk grade/certificate review holds, automatic hold release, and retake lockouts.
- Optional advisory AI image review for high-risk attempts through OpenAI, Anthropic, or an OpenAI-compatible vision endpoint.
- Admin reports for attempt captures, activity events, risk summaries, AI review results, and hold review.
- Daily report email task for recent or high-risk proctored attempts.
- Retention cleanup for webcam, face, desktop, and ID verification images.

## Requirements

- Moodle 4.5 or later.
- PHP and database versions supported by the installed Moodle version.
- HTTPS for browser camera, microphone, and screen-sharing APIs.
- Moodle cron configured and running.
- A browser with webcam support through `getUserMedia`.
- Chrome or Edge is recommended for desktop screen sharing and multi-monitor checks.

Optional integrations:

- Saylor AI face-match endpoint and API key.
- Saylor ID verification endpoint and API key.
- Cloudflare Turnstile site key and secret key, or Moodle site-wide reCAPTCHA keys.
- OpenAI, Anthropic, or OpenAI-compatible vision API credentials for advisory AI image review.

The optional AWS Lambda and FastAPI verification-service source is versioned in
[tools/verification_service](tools/verification_service/README.md). Deploy that service separately from Moodle;
its README documents the request contract, offline tests, and the Lambda version deployed to dev.

## Installation

Install the plugin from this repository or from a ZIP built from this repository.

From the Moodle root:

```bash
cd mod/quiz/accessrule
git clone https://github.com/saylordotorg/moodle-quizaccess_proctoring.git proctoring
cd ../../..
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

ZIP installation:

1. Create or download a ZIP of the repository contents.
2. Extract it to `mod/quiz/accessrule/proctoring`.
3. Visit Site administration as an administrator, or run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

After installation, verify that Moodle cron is running. Several features depend on scheduled tasks.

Development tools, tests, local environments, and deployment credentials are not required in a Moodle runtime archive.

## Upgrade

1. Back up the Moodle database and the existing `mod/quiz/accessrule/proctoring` directory.
2. Replace the plugin files with the new version.
3. Run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

4. Confirm that Site administration does not show pending plugin upgrades.
5. Review scheduled tasks and site settings after the upgrade.

Version 1.11.0 adds database fields and indexes, so deploy the complete plugin including `amd/build`, run the upgrade, and purge caches. See [FEATURE_RELEASE_NOTES.md](FEATURE_RELEASE_NOTES.md) for the new settings and validation record.

## Site Configuration

Open Site administration and search for `Saylor Proctored Quiz` if the navigation path differs by Moodle version.

Configure the site-wide defaults before enabling the plugin on quizzes.

### Precheck

Use these settings to control the pre-attempt steps shown before students can start a proctored quiz:

- Device readiness check (enabled by default): offers a separate test page from the quiz and preflight pages.
- Honor statement requirement, statement text, and agreement label.
- Privacy notice requirement, notice text, and agreement label.
- CAPTCHA before attempt.
- CAPTCHA provider: Cloudflare Turnstile or Moodle reCAPTCHA.
- Turnstile site key and secret key when Turnstile is selected.

### Face Verification

Use these settings to control webcam captures and face matching:

- Reference image management through the Saylor Proctored Quiz users list.
- Webcam capture interval.
- Webcam capture image width.
- Face match method: Saylor AI API or None.
- Saylor AI endpoint URL and API key.
- Face match threshold.
- Optional face check before quiz start.
- Optional continuous face check settings.

Students need a usable reference image before face validation can pass.

### ID Verification

Use these settings to require identity document verification before the attempt starts:

- Enable ID verification.
- ID verification endpoint URL and API key.
- Require both front and back ID images.
- Check ID portrait against the live face image.
- Check ID name against the Moodle profile name.
- Face and name thresholds.
- Whether to show failure details to students.
- ID verification image retention days.
- Maximum age of a passed ID check (zero means unlimited).
- Verify ID for every new attempt.
- Repeat ID verification after a profile name change.
- Repeat ID verification after a policy change.

When enabled, students must complete the ID step successfully before Moodle allows the proctored attempt to start.

The four recheck controls preserve existing reuse by default. When verification for every new attempt is enabled, the student must pass in the current session and start within ten minutes. The pass is consumed when preflight is accepted and linked to the created attempt. Recheck policies govern new attempts; they do not interrupt an exam already in progress. Existing approved requirement waivers still apply.

### Monitoring

Use these settings to control browser and screen monitoring:

- Monitor browser activity.
- Block clipboard actions.
- Require entire-screen sharing.
- Multi-monitor handling: off, log, warn, or block.
- Screen-share persistence mode: auto, main quiz page, or helper window.
- Capture desktop screenshots for violation events.
- Record periodic screen coverage (off by default; requires desktop capture to be enabled).
- Mobile and tablet screen-share behavior: bypass, require, or block.
- Blur quiz content when no face is detected.
- Face blur thresholds and grace period.

Screen sharing requires a browser that supports the Screen Capture API. Students should select the entire screen, not a browser tab or application window, when the entire-screen requirement is enabled.

Webcam and opted-in screen coverage reports estimate gaps in evidence received by Moodle. Connection recovery retains at most four queued payloads within a 4 MiB budget for up to two minutes, in memory only; reloading or closing the page discards pending evidence. Gaps and routine screen captures do not themselves add misconduct points or enter AI review. Periodic screen images use the existing evidence-retention policy and increase storage use.

### Risk Review

Use these settings to control high-risk attempt handling:

- Hold grades/certificates for high-risk attempts.
- Default high-risk threshold from 1 to 100.
- Student-facing hold notice.
- Student Affairs review window before automatic hold release.
- Retake lockout after a high-risk score.
- Retake lockout duration.

Reviewers with the `quizaccess/proctoring:reviewriskholds` capability can release or confirm high-risk holds from the proctoring reports.

### AI Image Review

Use these settings for advisory image review after an attempt reaches the configured trigger threshold:

- Enable AI image review.
- Provider: OpenAI, Anthropic, or OpenAI-compatible endpoint.
- Provider API key and model.
- OpenAI-compatible endpoint URL.
- Desktop screenshot review mode.
- AI review trigger threshold.
- AI review flag threshold.
- Maximum images per AI review.

AI image review results are advisory evidence for a human reviewer. They are not an automatic misconduct decision.

### Reporting And Retention

Use these settings to control operational reporting and cleanup:

- Daily report email task.
- Daily report recipients.
- Whether external email recipients are allowed.
- Whether the daily report includes all attempts or only high-risk/held attempts.
- Whether empty reports are sent.
- Image retention days for captured attempt images and related records.
- **Share proctoring summaries with the SIS** (`sisexportenabled`, site administrators only, default off). See below.

### Sharing Summaries With The SIS

The Saylor SIS can read a summary of each proctored attempt through the web service `quizaccess_proctoring_get_attempt_summaries`: risk score and band, capture/face-mismatch/violation counts, ID check outcome, grade-hold decision, AI review status and decision, reviewer sign-off, per-student override states, and a link back to the attempt report. Images, ID scans, screenshots, AI review text, reviewer notes and override justifications are never returned.

To turn it on for a site (the degree site only; leave it off on certificate sites):

1. Turn on **Share proctoring summaries with the SIS** in the Reports section of the plugin settings.
2. Grant `quizaccess/proctoring:exportsummaries` at system context to the role held by the SIS web-service user. No archetype has it.
3. Confirm the function is in the SIS web service ("Completion History SIS" adds it automatically when `local_completionhistory` is installed; otherwise add it under Site administration > Server > Web services > External services).

With the setting off the function refuses with `sisexportdisabled`, even for a token that holds the capability.

## Quiz Configuration

Edit a quiz and open the `Saylor Proctored Quiz` section in the quiz settings form.

Set `Proctoring required` to enabled for quizzes that should use this access rule.

Per-quiz options can inherit the site default or override it:

- Entire-screen sharing before quiz start.
- CAPTCHA before attempt.
- Risk review mode.
- Risk review threshold.

Save the quiz settings. The pre-attempt checks appear the next time a student starts a new attempt.

## Student Attempt Flow

Students can open **Check devices and connection** from the quiz page before starting. Camera, microphone and screen previews stay on the student's device; the connection test sends synthetic data and probes configured services without images. Readiness does not start an attempt, pause an existing timer or replace mandatory preflight checks.

When proctoring is required, students may be asked to complete these steps before the quiz starts:

1. Accept the privacy notice.
2. Accept the honor statement.
3. Complete CAPTCHA.
4. Complete ID verification.
5. Register or validate their face.
6. Share the entire screen.
7. Complete a multi-monitor check.

The exact steps depend on site settings and quiz overrides.

During the attempt, the plugin can capture webcam images, log suspicious browser activity, check screen-sharing state, capture desktop violation screenshots, and blur quiz content until a face is visible.

## Reports And Review

Users with report access can open proctoring reports from the quiz or the plugin report pages.

Reports include:

- Webcam captures and face crops.
- Desktop event screenshots and optional periodic screen captures.
- Browser activity events.
- Monitoring coverage, delayed uploads and estimated intervals without received evidence. Historical attempts without a saved monitoring policy show unknown coverage.
- Risk score and risk factors.
- Face-match status.
- ID verification status when enabled.
- AI image review status and results when enabled.
- High-risk review hold status.

Authorized reviewers can release or confirm risk holds. Releasing a hold clears the grade/certificate hold and related retake lockout. Confirming a hold keeps the enforcement outcome in place.

## Admin Pages

The plugin adds these admin-facing pages:

- Saylor Proctored Quiz users list for reference images.
- Proctoring AI cost estimate.
- AI review diagnostics.
- Bulk delete controls for stored proctoring records.

## Scheduled Tasks

Moodle cron should run regularly. The plugin defines these scheduled tasks:

- `quizaccess_proctoring\task\delete_images_task`
  Deletes eligible captured images based on retention settings.
- `quizaccess_proctoring\task\send_daily_report_task`
  Sends daily proctoring reports when enabled.
- `quizaccess_proctoring\task\execute_ai_review_task`
  Processes queued AI image reviews.
- `quizaccess_proctoring\task\release_expired_risk_holds_task`
  Releases active risk holds after the configured review window.
- `quizaccess_proctoring\task\initiate_facematch_task`
  Legacy/task-based face-match initiation. Disabled by default.
- `quizaccess_proctoring\task\execute_facematch_task`
  Legacy/task-based face-match execution. Disabled by default.

Review task schedules in Site administration if reports, cleanup, or AI review are not running as expected.

## Data And Privacy

Depending on enabled settings, the plugin may store:

- Webcam captures.
- Face crops.
- Reference face images.
- ID document images.
- Live ID verification face images.
- Desktop event screenshots and optional periodic screen captures.
- Capture/receipt times, retry tokens and the monitoring policy saved for an attempt.
- Verification timestamps and profile-name/identity-policy fingerprints.
- Browser activity events.
- Risk scores and review decisions.
- AI image review summaries and evidence notes.

When an administrator turns on sharing with the SIS, a summary of each proctored attempt (scores, counts, statuses and decisions; no images or notes) is readable by the SIS web-service user.

Pluginfile access is restricted to the owning student or users with report capability in the relevant Moodle context. Outbound AI and ID verification endpoints must use publicly routable HTTPS with a trusted TLS certificate. Destinations are validated and pinned before proctoring images are sent; private-network endpoints and redirects are rejected.

Administrators should configure privacy notice text, retention windows, report recipient rules, and external AI endpoints according to institutional policy.

## Troubleshooting

Camera does not open:

- Confirm the site uses HTTPS.
- Confirm the browser has camera permission for the Moodle site.
- Confirm no other application is holding the camera.

Face validation fails:

- Confirm the student has a clear reference image.
- Confirm the Saylor AI endpoint URL and API key are configured.
- Check the face threshold and provider logs.

ID verification fails:

- Confirm the ID image is readable and well lit.
- Confirm the live face image is clear.
- Confirm profile name matching rules and thresholds.
- Check the ID verification endpoint configuration.

Screen sharing fails:

- Use Chrome or Edge on desktop.
- Select the entire screen when prompted.
- Allow pop-ups if the helper screen monitor window is used.
- Check mobile/tablet screen-share behavior settings.

CAPTCHA does not appear or cannot be completed:

- Confirm the selected provider has valid keys.
- For reCAPTCHA, confirm Moodle's site-wide reCAPTCHA configuration.
- For Turnstile, confirm both site key and secret key are configured.

Reports, retention cleanup, or AI review do not run:

- Confirm Moodle cron is running.
- Check scheduled task status.
- Check provider credentials and endpoint validation errors.

## License

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <http://www.gnu.org/licenses/>.

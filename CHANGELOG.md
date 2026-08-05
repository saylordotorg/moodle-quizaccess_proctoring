# Changelog
All notable changes to this project will be documented in this file.

# v1.7.0
The "I can't provide a photo ID" flow is now self-service, asks the student why, and sends no automated mail.
- A no-ID request is now a short form rather than a bare click: a required "Why can't you provide a photo ID?" dropdown (never issued one / lost, stolen or damaged / expired and cannot renew / refugee, asylum seeker or displaced / held by an employer or authority / another reason), a required explanation in the student's own words, and an optional note on what documentation they can provide instead. Submit stays disabled until both required answers exist, and the web service refuses an empty request instead of filing it — a request carrying no information only buys a round of "so why don't you have an ID?".
- Staff read those answers where they work. The per-exam panel and a new site-wide queue both show the chosen reason with the student's explanation underneath, so the request can be judged without opening anything else.
- New "ID exceptions" tab on the site-wide Proctoring reports page: every pending request across all courses and exams in one table (student, course, exam, reason and explanation, requested date), with per-row Approve/Decline, checkboxes for batch decisions spanning several exams, and a link into each exam's Manage overrides page. Offered only to staff holding manageoverrides at system context, and every decision re-checks that capability on the target exam. Manage overrides itself remains per-exam and now links across to the queue.
- Both views and both routes go through one new `id_exception` service, so the per-exam panel and the site-wide tab cannot disagree about what is pending or behave differently when deciding. It also fixes a boundary case in the old pending logic: a student who re-requested in the same second a decision was recorded had their new request silently swallowed, because timestamps alone cannot order the two.
- The email draft is now built in the browser from the answers the student just gave, so the support ticket and the Moodle request say the same thing.

- Clicking it no longer emails anyone. It opens a triage prompt — "Which of these describes your situation?" — with three answers: *I can't get a usable picture of my ID* (six capture tips: get it out of the plastic sleeve, lay it flat on a dark surface, light behind you, fill the frame, let the camera focus, use a phone's back camera — then, only if the student says the tips did not help, what to email student support), *I have an ID but don't have it with me right now* (nothing to send: the attempt and its timer have not started, fetch it and come back), and *I don't have a photo ID* (the details student support needs to review an exception). Both escalation paths state the turnaround as "normally within 1-2 business days (Monday to Friday)" and say replies come to the email the student sends, rather than pointing them at their Moodle profile address.
- Because a first click now only asks a question, accidental clicks cost nobody an inbox item. This replaces the previous behavior, where one click emailed the contact address and sent the student a confirmation.
- The address is rendered as a `mailto:` link that opens a draft pre-filled with the student's name, account email, course, exam, and labelled blanks for their reason — nothing is sent until the student sends it, which the guidance says explicitly. The visible link text is the plain address, so it stays copyable when no mail client is configured.
- The two escalation answers still record a declaration, so the student appears under "Pending ID exception requests" on the quiz's Manage overrides page with the reason they gave ("Cannot capture a usable ID picture" / "Has no photo ID"; requests recorded before this release show "Not stated"). Staff keep one-click Approve/Decline there, and the student still gets the approval or decline email. The daily throttle is now per reason, so a student who first reported a capture problem can still declare they have no ID.
- Removed the staff "action needed" notification and the student "we received your request" confirmation, along with their strings and the now-unused `exemption_email::notify_staff_request()`, `notify_student_received()`, and `external_recipient()`. The only proctoring exception emails left are the ones a staff decision triggers.
- The ID exception contact address now defaults to contact@saylor.org (was empty), and its setting description explains that the plugin never writes to it. Sites with an address already configured keep it — update it in Site administration.
- The approval and decline emails now carry `Reply-To: "Student support" <contact@saylor.org>` (the configured ID exception contact address), so a student who just hits reply reaches a person instead of the site's noreply black hole. The From stays the site noreply address. Their footer changes to match — "replies go to student support" when a contact address is configured, and the old "please do not reply" only when there is genuinely nowhere to reply to.
- Every date these emails show a student is now rendered in Eastern time with the zone named ("25 July 2026, 1:20 PM EDT"), instead of falling back to the server timezone whenever a student has no timezone on their profile — which is what made request times read as Central. The approval and decline emails also name the request they answer, so a student with more than one pending exam can tell which is which.
- Risk-review hold notifications carry the same Reply-To as the exception emails, since students reply to those too.
- Dropped the advice to check that the profile email address is accurate. It only ever appeared after a request had already gone out, when correcting the profile would change nothing: staff correspond through the address on the request itself, and nobody re-checks a profile for a later edit.

Also in this release:
- Rewrote the default pre-quiz honesty statement. It now names what is actually off-limits ("notes, books, websites, AI assistants, or help from another person unless the instructions for this exam explicitly allow them"), forbids copying, photographing, recording, or sharing questions and answers, and states the consequence students care about — a failing grade or the loss of certificate eligibility. Sites that customised the statement keep their own text.
- The honesty statement now links the Student Handbook below it ("Read the Student Handbook for the full academic integrity policy."), opening in a new tab so students do not lose their setup steps. The target is a new "Student handbook URL" admin setting, defaulted to Saylor's handbook academic integrity section; clearing it hides the link.
- The privacy notice's retention line and the risk-review student notice no longer read "day(s)": they use "days", with a separate singular string used only when an administrator sets the retention period or review window to exactly 1 day.
- Dropped the Spanish and Portuguese language packs. The plugin now ships English only; Moodle falls back to the English strings for any other site language.

# v1.6.4
- The per-attempt action button on the proctoring report is now labelled "View report" (with a report icon) instead of "View images", since it opens the full per-student report (summary, webcam captures, and suspicious activity), not just images. The Spanish and Portuguese translations were updated to match.
- Restored the version.php / install.xml version lockstep (install.xml had been left at 2026072107 while version.php advanced to 2026072120) and added the missing upgrade savepoint covering the 1.2.1–1.6.3 releases.

# v1.6.3
- Fixed the "Desktop capture required" prompt reappearing on every quiz page and the focus-loss churn it caused. Browsers throttle the hidden screen-share helper window's timers to as little as once a minute, so its marker check went stale, and status requests replied from that stale cache — every page navigation then looked like a wrong-screen share within seconds. The helper now runs a fresh marker check whenever the quiz page asks for status (message handlers are not throttled), and the marker grace window is extended to cover page-navigation gaps.
- Clicking "Share entire screen" no longer logs focus-loss violations against the student: the helper window (or the browser's share picker) taking focus at the plugin's own request suppresses focus-loss logging for 15 seconds.

# v1.6.2
- Browser-native AI side panels (Gemini in Chrome, Copilot in Edge) are now detected. The old check only fired when a student clicked an AI-named link inside the quiz page itself, so panels living in the browser's own UI were invisible to it. Opening one now triggers a "Possible AI tool interaction" event (with the usual desktop capture) via its geometry signature — the page viewport narrows sharply (220px+) while the window keeps its size and the zoom level stays constant, which filters out window resizes, snapping, and zooming. A panel already open when the attempt page loads is caught by the window-vs-viewport width gap at integer zoom levels. 90-second cooldown between detections.

# v1.6.1
- Exception emails now sign off as Saylor University with the postal address (1041 SE 17th Street, Suite 100, Fort Lauderdale, Florida 33316) in the footer, in both the HTML and plain-text parts.
- The Start attempt stepper no longer shows the quiz's own verbose "Time limit" heading and paragraph below the card — it duplicated the footer's compact "Time limit: N — cannot be paused" line. Only the core time-limit heading and message are hidden, and only when the compact line is present.

# v1.6.0
ID exception requests now have a full email lifecycle (Claude Design: "ID Exception Request Email").
- The staff notification is redesigned as a branded HTML email matching the approved design: Saylor logo header, accent card with a "Proctoring · Action needed" eyebrow, a details panel (student, course, exam, requested time), and a "Review on Manage overrides" button — with a plain-text alternative for text-only clients. All exception emails share this shell (new exemption_email class).
- Students now get emails at every stage: a confirmation when they submit ("Request received" — 24-48 hour review window, profile-email accuracy reminder), a green "Your ID exception was approved" email with a Go-to-the-exam button when staff approve, and a red-accented "not approved" email pointing them at the contact address when staff decline. Every email is localized to the recipient's language (en/es/pt_br).
- The Manage overrides page now shows a "Pending ID exception requests" panel listing students who asked for the waiver, with one-click Approve (creates the quiz-scoped ID verification override, emails the student) and Decline (emails the student) actions. Decisions are recorded as id_exemption_approved/declined audit events, and a request stays pending until a decision is made.

# v1.5.3
- The ID exception confirmation now sets expectations and catches stale contact details: "Your request has been sent to student support. They will review it within 24-48 hours and contact you with any questions. Once an exception is approved you can start the exam without ID verification. Make sure the email (student@example.com) in your profile is accurate." — with the student's actual profile email shown inline. Updated in English, Spanish, and Portuguese.

# v1.5.2
- The per-student overrides page is now discoverable: the quiz proctoring report header shows a "Manage overrides" button for users with the manage-overrides capability. Previously the page (where staff waive ID verification and other requirements per student — including granting the "I can't provide a photo ID" exceptions) was reachable only by typing its URL.

# v1.5.1
- Hidden the quiz page's "Back" button during proctored attempts. Moodle core renders a lone tertiary-navigation Back link (to the quiz view page) on every attempt page; on a proctored attempt it only walks students out of the exam mid-attempt — and fires focus-loss violations on the way. It is hidden on attempt and summary pages of proctored quizzes only; review pages and non-proctored quizzes keep it.

# v1.5.0
Redesigned the Start attempt precheck as a guided stepper (Claude Design: "Proctoring Setup Stepper").
- The precheck modal now lays out as a two-pane wizard: a "Setup checklist" rail on the left with a numbered dot per requirement (blue for the current step, green check when done, red outline when action is needed), and the active step filling the right pane under a "Step N of M" kicker. Only enabled requirements appear, and the modal widens to 1000px to give camera and screen-share steps room. Completed steps can be revisited by clicking them in the rail; future steps stay locked.
- The quiz's Start attempt / Cancel buttons move into a pinned footer inside the card, joined by a live "N of M complete" counter and a compact time-limit line ("Time limit: 2 hours — cannot be paused") when the quiz is timed. The Start button renders grey while locked and green once every step is complete.
- On phones the rail collapses into segmented progress bars under the header (grey pending, blue current, green done) and the footer stacks with a full-width start button.
- Implemented as a presentation-layer transform in startAttempt.js over the existing precheck markup and step machine — validation, gating, and every step's behavior are unchanged. New strings localized in English, Spanish, and Portuguese.

# v1.4.1
- Fixed "Blur quiz when multiple monitors are detected" overriding the multi-monitor detection mode. With the mode set to Log or Warn — explicit "allow extra monitors" policies — students were still blocked mid-attempt by the blur overlay ("Disconnect or disable extra monitors to continue the quiz"). The mode now wins: the blur enforcement only runs when the mode is Block (blocks at start and during the attempt) or Off (blur as the sole enforcement); in Log/Warn modes extra monitors are logged or warned about but never block the quiz. The setting description now documents this interplay.

# v1.4.0
- Sharper ID captures on capable cameras. The ID camera now asks for up to 3840x2160 (was 2560x1440); if the camera still hands out a low-resolution mode, the live track is asked to renegotiate before capture. The final capture now uses the ImageCapture takePhoto API when the browser supports it — a true still photo from the camera's photo pipeline, typically far sharper than a grabbed video frame — with the guide-window crop rescaled to the photo's resolution and a 3-second fallback to the old frame grab. Uniform near-black letterbox bars (from virtual cameras and mismatched driver modes) are trimmed off the capture before the blur check, so padded frames no longer shrink the usable ID image or sneak past quality gates.
- "I can't provide a photo ID" exception requests. A new site setting (ID exception contact email, under ID verification) enables a low-key link in the precheck's ID step for students who cannot obtain identity documents — refugees, displaced people, and others. Clicking it emails the configured contact (e.g. student affairs) with the student, course, exam, and a direct link to the quiz's Manage overrides page, where staff can waive the ID requirement for that student — the exception always stays a human decision; nothing is bypassed automatically. Requests are limited to one per student/exam/day, logged as an id_exemption_requested event for audit, and the student sees a clear confirmation. Localized in English, Spanish, and Portuguese.
- Simpler live-face step: removed the redundant "Start camera" button — Verify ID already starts the camera itself, and when it does a cold start it now waits a moment for exposure to settle (and the student to compose) before taking the comparison snapshot.

# v1.3.1
- Fixed the Proctoring settings page save bar rendering as a misplaced dark box floating mid-page. The bar reused Moodle's Bootstrap column wrapper (offset-sm-3 col-sm-3), whose width caps squeezed the note text, and its viewport-fixed positioning broke under theme ancestors with CSS transforms. The submit button now moves into a clean bar appended as the form's last child, floated with position: sticky (immune to transformed ancestors) as a rounded tray 16px above the viewport bottom while scrolling, settling into flow at the end of the page.
- Fixed the Risk factor scoring page save button rendering as a bare Bootstrap button at the bottom-left. Its sticky save bar (with the "Changes apply to new attempts" note) never activated because the code required a .form-buttons wrapper that Moodle 4.5 does not render; the bar is now built the same way as the settings page's and aligns with the 1080px card column.
- Data retention card: the "Delete all records" area now reads explanation-first — the warning text sits above the red button with proper spacing — and the heading is corrected to "Delete all records captured during exams".

# v1.3.0
Redesigned the per-student report's Webcam captures and Suspicious activity tabs to match the approved mockups (Claude Design: "Proctoring Report Tabs Redesign").
- Webcam captures tab: leads with an identity verdict band — profile photo next to the best-scoring capture with a plain-language verdict ("Identity confirmed" / "Possible identity mismatch" / "Photos not analyzed yet") derived from the per-photo face-match results, the student's name/email, and the Analyze action relabeled to say how many photos remain ("Analyze remaining N photos"). The old two-table layout is replaced by a responsive photo grid where each capture carries a colored status ring and badge (green matched with score, red mismatch, yellow no face, grey not analyzed), client-side filter pills with counts (All / Matched / Mismatch / No face / Not analyzed), the capture cadence ("One photo about every 30 seconds"), and a color legend. Lightbox viewing and the analyze flow are unchanged.
- Suspicious activity tab: the flat 6-column event table is replaced by a reviewer-first story. A new pure grouping class (\quizaccess_proctoring\local\activity_grouper, unit-tested) folds the raw event stream into away-from-exam episodes — "The student left the exam window 5 times" — with a rollup line ("The 42 raw browser events below boil down to 5 moments away, totaling about 1 min 40 secs"), and an attempt timeline bar marking each absence (orange) and absences with desktop captures (red). Each episode is a collapsible card showing when it started, how long it lasted, what else was recorded (pastes right after returning attach to the gap), any desktop capture inline with its per-event AI review, and the raw monospace event rows for auditing. Flagged events outside any gap (audio, shortcuts, phone) stay as standalone cards; mouse-edge wiggles and other routine noise collapse into a "Show N routine events" drawer so they stop drowning out real findings. Every raw event remains visible — nothing is dropped, only regrouped.

# v1.2.1
- Sharper ID verification captures. The ID document camera now requests up to 2560x1440 from the device (previously 1280x720), so cameras that support 1080p or better deliver a much more detailed frame, and the cropped ID image is kept at up to 1920 pixels wide (previously capped at 1280, and as low as 640 after cropping). Captured ID images are now saved as high-quality JPEG instead of PNG, which keeps the larger captures fast to upload without visible quality loss; stored filenames now carry the correct extension for their actual format.
- Blur rejection at capture time. When the student clicks "Capture ID image", the final full-resolution crop is checked for sharpness; a blurry capture (camera hunting for focus, motion blur, dirty lens) is rejected with a new "The captured image looked blurry..." message telling the student to hold the ID steady, fix lighting, or adjust distance, instead of being accepted and failing later at review or OCR.
- Sharper identity/face verification selfies. The live webcam capture used for the ID face match and the face preflight is now at least 640 pixels wide. Previously it inherited the in-quiz camshot width setting (default 230, floored to 240), so the image the face matcher received could be tiny; the camshot setting still controls the periodic in-quiz captures. Preflight captures also no longer upscale beyond the camera's native resolution.

# v1.2.0
Starts the 1.2 series, bundling the admin/report redesign work from the 1.1.40–1.1.43 entries below (settings page, Exam integrity review queue, Risk factor scoring cards) with their follow-up fixes. Going forward, minor versions mark feature releases and patch versions mark fixes.
- Risk factor scoring page: Moodle validation feedback is no longer hidden with the original settings rows. When a save is rejected (e.g. a non-integer risk level or factor cap), the error message rendered in the original (now hidden) row is relocated next to the relocated input inside its card, so the reason the save failed stays visible.

# v1.1.43
- Fixed the Risk factor scoring page rendering the raw Moodle settings list above the redesigned cards. The transform now hides each original settings row from JavaScript (tagging it directly) instead of relying on a CSS child selector, which did not match Moodle's actual settings markup nesting, so only the redesigned cards show.
- Fixed the settings-page toggle running off the right edge on narrow admin content widths: the setting-row control column can now shrink (minmax(0, max-content)) instead of being pinned to its min-content width, so the toggle always stays within the card.

# v1.1.42
- Removed the redundant heading banner from the top of the Proctoring settings page (the "Site-wide exam supervision / Proctoring settings / Choose how exams are supervised…" block), which duplicated the Moodle settings page heading. The settings search box is kept.
# v1.1.41
- Redesigned the Overall reports page into an "Exam integrity review" queue matching the approved mockup. It now leads with clickable pulse cards (waiting for review, proctored attempts, clean, escalated), review-queue view pills (Needs review / All attempts / Reviewed), and expandable attempt cards that show the score with its risk band, a plain-language signal summary, the detected signals, the score breakdown, and per-attempt actions. The review queue maps onto the existing risk-hold lifecycle — an active hold needs review, a released hold is reviewed, and a confirmed/auto-failed hold is escalated — so "Mark reviewed — no concern" and "Escalate to integrity case" reuse the existing release/confirm actions with no new data model. Only actionable (held) attempts enter the Needs review queue; an unheld attempt that still has violations is shown as "Flagged" under All attempts rather than parked in the queue with no available action. Course/period/sort filters are preserved.

# v1.1.40
- Redesigned the Risk factor scoring admin page to match the approved mockup. Factors are now grouped into cards (Webcam & identity, Screen & monitors, AI tools & copying, Keyboard shortcuts, Audio & pacing), each row showing a toggle, points-per-event and maximum-points fields, and a live "share of score" bar. The cap toggle shows a live "if every factor fired at its maximum" total; the Low/Moderate/High/Critical boundaries have a gradient editor that keeps its order automatically (and now writes the corrected order back so saved values never invert); the false-positive tuning panel and a sticky save bar round it out. Implemented as a JS transform over the existing settings form, so the underlying config keys, validation, and save are unchanged.
# v1.1.39
- Settings page now inherits the LMS theme background instead of forcing a hardcoded grey on the main content region. The white setting cards keep their borders, so they stay delineated on any theme.

# v1.1.38
- Settings page UX: made the setting rows uniform and fixed the save bar so it floats. Every control (toggles, dropdowns, text fields) now aligns to one shared right edge with a consistent field width, and labels reclaim the horizontal space the old fixed control column was wasting. The save bar, previously a last-in-flow sticky element that never actually floated, is now a fixed bottom tray that stays pinned to the viewport while scrolling and clears the content above it; its Save button was also darkened to meet colour-contrast guidelines. Small muted-grey and font-size adjustments across the page for legibility. CSS-only.

# v1.1.36
- Accessibility pass on the report Summary verdict card. All text sizes moved from small fixed pixels (11-13px) to larger rem-based sizes that respect user font-size preferences (body text ~15px, secondary text no smaller than 13px, headline and score enlarged). Muted grays and the Low/Moderate/High/Critical zone and pill colors darkened to meet WCAG AA 4.5:1 contrast, the "View captures" button background darkened so its white label passes contrast, and keyboard focus outlines added to the button, false-positive actions, and collapsible section toggles.

# v1.1.35
- Privacy: the finding-reviews table is now covered by the privacy provider — declared in the metadata, included in user data exports (for the student and the reviewer), and anonymized on deletion (student id, reviewer id, and the reviewer note, which describes the student's evidence). Also fixed a latent duplicate named-parameter bug in the risk-holds export query that would have failed exports for users with holds.
- Uncapped scores: the auto-release risk ceiling is now aware of the score cap setting. The ceiling counts as disabled only when it exceeds the maximum achievable score (100 with the cap on; the sum of enabled factors' caps with the cap off), so an expired hold with an uncapped score such as 135 is retained by a ceiling of 101 instead of being auto-released.
- CI: restructured the Moodle Plugin CI workflow from 39 jobs to 7. Environment-independent checks (phplint, validate, savepoints, mustache) now run once in a dedicated static job that also runs the Moodle code checker informationally; PHPUnit runs on a trimmed matrix - full database spread on the production series (4.05), min/max PHP on the latest stable (5.02), and one non-blocking experimental lane against Moodle main. Fixed the finding-reviews schema declaring an empty-string default on a NOT NULL CHAR column, which XMLDB rejects with a debugging message that failed the CI install step.
- Reviewers can now mark a risk finding as a false positive directly on the per-student report (requires the review-risk-holds capability). A marked finding stops adding risk points immediately — the attempt score recomputes everywhere it is displayed — and stays visible as a muted "excluded from score" card with the reviewer's name and date and an Undo action; its events leave the flag timeline and the "Needs review" count. When the corrected score falls below an active hold's threshold, the hold banner suggests releasing it. Marks are stored with a full who/when audit (undo revokes rather than deletes).
- Added a "False-positive review data" section to the Risk factor scoring admin page: active marks per factor with the most recent mark date, so factor points, caps, and detection thresholds can be tuned from evidence rather than guesswork.

# v1.1.34
- Added optional webcam phone detection (default off; site setting "Detect phones in webcam images"). When enabled, the attempt page runs TensorFlow.js COCO-SSD object detection on the live webcam feed in the student's browser; a phone-like object must stay visible across three consecutive checks (with a configurable confidence threshold and a 90-second cooldown) before one "Phone detected" event is logged with the webcam frame attached as evidence. Events score through a new "Phone visible in webcam" risk factor (12 points, capped at 24 — tunable on the Risk factor scoring page), appear on the report's finding cards/timeline with the frame inline, and route through AI image review with a phone-specific prompt. Detection can be waived per student via a new Manage overrides requirement, the privacy notice discloses it automatically, and the feature stays silently off until the object-detection libraries are vendored into thirdpartylibs/objectdetect (see the README there).

# v1.1.33
- Redesigned the Summary tab of the per-student proctoring report. A verdict banner now leads with the score, risk-level pill, plain-language session summary, and a "Review captures" shortcut; below it a score meter shows where the attempt sits against the configured level boundaries. Flagged factors render as "Needs review" finding cards with event counts, points, an explanation of what the evidence means, and inline desktop-capture thumbnails; a flag timeline places each event between attempt start and submission. Checks that recorded nothing are collapsed into a "Passed" list, disabled factors are listed as "not monitored", and the raw per-factor scoring table is kept under a collapsed "Scoring details" section.

# v1.1.32
- Removed the yes/no "Overview" table from the per-student report. Every row duplicated a count already shown (with points) in the risk score details table, which also covers seven more evidence types; the plain-language session summary serves the quick-read purpose. Also drops the extra event-count queries that only fed this table.

# v1.1.31
- Added a "Cap attempt risk score at 100" option (default on) to the Risk factor scoring admin page. When disabled, the attempt risk score is the raw sum of all factor points and can exceed 100; thresholds, level boundaries, and the auto-release ceiling compare against the uncapped value. Reports keep the "score/100" label.

# v1.1.30
- Added a "Risk factor scoring" admin page (AI proctor settings, between Overall reports and Review diagnostics). Every risk factor can now be enabled/disabled and its points-per-event and maximum points configured; disabled factors score nothing and are hidden from risk score details. Also made the Low/Moderate/High/Critical risk-level boundaries configurable (defaults 20/50/80, clamped so they never invert). Shipped defaults match the previous hardcoded model, so scores are unchanged until an admin edits them. Because scores are recalculated from stored evidence on view, changes also affect scores displayed for past attempts; existing grade holds keep their recorded score.

# v1.1.29
- Desktop violation screenshots are now captured regardless of the multi-monitor policy. v1.1.22 skipped in-quiz desktop capture when multiple monitors were blocked (to avoid re-prompting for the screen share on every quiz page), which also silently stopped attaching desktop screenshots to violation events in the quiz report. Auto screen-share persistence mode now always uses the helper window on desktop, so the verified share survives page navigation and violation screenshots are recorded in every multi-monitor mode.

# v1.1.27
- Overall reports: the "violations" count now counts only suspicious browser events (matching the risk calculator) and ignores routine recovery events such as tab_visible, focus_returned, and mouse_returned_window, so totals and sorting are no longer inflated.
- Overall reports: the active-hold and AI-flagged summary cards now reflect the current filter (course, period, and minimum violations) instead of the whole window, so they no longer show counts for attempts outside the filtered result set.
- Fixed a potential failure in the automatic hold-release task: student decision notifications now pass an explicit context to format_string() and are fully guarded, so they cannot error when run from cron without a page context.

# v1.1.26
- Allowed teacher and editing-teacher roles to send webcam photos, so staff can take a proctored quiz for testing without hitting a "Proctoring send webcam photo" permission error. Granted to existing teacher/editing-teacher roles on upgrade (existing per-role overrides are preserved) and to new roles via the capability archetypes.

# v1.1.25
- Students are now notified (Moodle notification + email, per their messaging preferences) when a grade hold on their proctored attempt is released or confirmed. Added a new "Proctored quiz review decisions" message provider and two site settings: "Notify students of grade-hold decisions" (default on) and "Notify students of automatically released holds" (default on). Notifications fire from every path — the per-student report, the Overall reports dashboard, and the automatic review-window release task.

# v1.1.24
- Added inline "Release" and "Confirm violation" actions to the grade-hold column of the Overall reports dashboard, so reviewers can clear a hold (restoring the earned grade) or uphold it (setting the quiz grade to zero) without opening each student's report. Confirm shows a confirmation dialog, and both actions require the proctoring:reviewriskholds capability.

# v1.1.23
- Added a site-wide "Overall reports" dashboard under AI proctor settings: aggregates proctored attempts across all courses, with filters (course, period, minimum violations), sortable by violations or recency, plus summary cards.
- Added a "Time taken" column to the proctoring report and the per-student summary, showing attempt duration (and the average time per question).
- Added an optional risk factor that flags unusually fast completions. When enabled, an attempt whose average time per question falls below a configurable floor (default 15 seconds) adds to the risk score. Disabled by default.

# v1.1.22
- Fixed the desktop capture prompt reappearing on every quiz page when multiple monitors are blocked. In Auto screen-share persistence mode, the helper window is now used only when multiple monitors are allowed (keeping the verified share alive across navigation); when monitors are blocked, in-quiz desktop capture is skipped so students are no longer re-prompted on each page. Use "Always use helper window" to keep desktop capture active even when monitors are blocked.

# v1.1.21
- Cleared all remaining Moodle coding-standard (PHPCS) issues from the Catalyst plugin review: reordered language strings alphabetically in all three language packs, added `@covers` coverage annotations and missing return types across the PHPUnit suite, and marked the test classes final.

# v1.1.20
- Added multilingual ID name matching so ID verification accepts romanized, legal, and alternate profile names when checking extracted document names.
- Stabilized the external service authorization tests in CI with per-test process isolation and a Moodle 4.5 fixture fix.

# v1.1.19
- Avoided shipping a large AMD JavaScript payload on the proctoring admin settings pages.
- Added privacy provider regression tests covering metadata, context discovery, and per-user deletion.
- Fixed the privacy provider context query for risk holds.

# v1.1.18
- Added top-level admin settings shortcuts for common proctoring gates and tabbed navigation for the main settings groups.

# v1.1.17
- Reorganized the site admin quiz settings so AI proctor settings contains Review diagnostics and Cost estimate subpages.

# v1.1.16
- Added an admin option to blur quiz content when supported browser detection finds multiple active monitors during an attempt.

# v1.1.15
- Made the multi-monitor preflight checklist item span the full checklist width so long action messages wrap inside the panel.

# v1.1.14
- Reused a passed ID verification live face capture to complete the face recognition preflight step when both checks are enabled.

# v1.1.13
- Disabled automatic ID photo capture and gated the manual capture button on a steady green ID-in-window state.

# v1.1.12
- Extracted risk scoring and outbound endpoint validation into local service classes, preserving existing compatibility functions.
- Rejected outbound API endpoints with invalid explicit ports before DNS resolution.
- Refreshed the README to remove screenshots and document the current Saylor Proctored Quiz setup, monitoring, review, and reporting workflow.

# v1.1.11
- Made the ID camera guide smaller, changed its feedback to red/green in-window states, and required all four ID edges before auto-capture.

# v1.1.10
- Required multiple ID-card edges before browser auto-capture can start the steady-hold timer.

# v1.1.9
- Tightened ID auto-capture detection so textured backgrounds do not start the hold timer without a document boundary.

# v1.1.8
- Split ID detection from the steady-hold timer so auto-capture progress starts only after an ID-like document is visible.

# v1.1.7
- Added blue/green ID guide feedback while the document is detected and held steady before auto-capture.

# v1.1.6
- Added an optional ID verification requirement for students to provide both front and back ID images.

# v1.1.5
- Added ID verification suboptions for checking the ID photo, the ID name, or both.

# v1.1.4
- Added an admin option to show student-facing ID verification failure reasons.

# v1.1.3
- Slowed automatic ID image capture so the document must remain aligned for longer before capture.

# v1.1.2
- Added automatic ID image capture when the document remains aligned in the on-screen guide.

# v1.1.1
- Added an optional guided ID document camera capture with an on-screen alignment outline for pre-attempt ID verification.

# v1.1.0
- Added optional pre-attempt ID verification with ID image upload, live face capture, and Saylor/custom AI endpoint scoring.
- Added private ID verification evidence storage, retention cleanup, pluginfile access controls, and Privacy API export/delete coverage.
- Added preflight gating so students cannot start a proctored quiz until the server records a passing ID verification result when enabled.

# v1.0.2
- Hardened daily proctoring report email recipients so external addresses require explicit admin opt-in.
- Scoped daily report rows for Moodle-user recipients to attempts they can view with proctoring report capability.

# v1.0.1
- Added Moodle `RISK_PERSONAL` metadata to capabilities that submit, view, analyze, or delete proctoring personal data.

# v1.0.0
- Marked the Saylor Proctored Quiz plugin as the first stable v1 release for Moodle 4.5.
- Added P0/P1 security hardening for pluginfile access, destructive actions, browser-submitted proctoring data, analysis actions, Privacy API coverage, and outbound AI endpoints.
- Added Moodle Plugin CI workflow coverage for Moodle 4.5.
- Fixed Moodle 4.5 XMLDB install validation issues found by CI.

# v1.6.16
- Renamed the displayed plugin name to Saylor Proctored Quiz.
- Removed Proctoring Pro promotional copy and links from plugin settings and user image pages.

# v1.6.17
- Added a student report overview/status table for common proctoring checks.

# v1.6.15
- Renamed the Custom AI endpoint URL setting label to Saylor AI endpoint URL.

# v1.6.14
- Removed the retired third-party face matching service settings and runtime integration.
- Kept Custom AI API as the only external face matching method.
- Added an upgrade step to clear retired face matching configuration from existing installs.

# v1.6.11
- Added Brazilian Portuguese language support 
- Add elearning product and proctoring pro version link

# v1.6.10
- Fixed unstallation issue where the plugin could not be removed due to a missing database table.
- Fixed users list page to exclude deleted users and ensure proper pagination.

# v1.6.9
- Added Spanish language support with a complete translation of 240 strings.
- Internationalized the report search UI by replacing hardcoded text with language strings.
- Updated report template to use internationalized placeholders for search functionality.
- Fixed minor whitespace issue in version.php.

# v1.6.8
Updates:
- Designed the student table header for improved visual consistency and readability.
- Renamed Proctoring Summary Report to Course Report.
- Enhanced the search functionality to allow searching by username in addition to email.
- Removed the Proctoring Promo Page, as it is no longer included in any pages.
- Added an identity mismatch indicator — if a user’s identity does not match, a ⚠️ icon will be displayed.

# v1.6.7
- Settings design changed according to moodle feedback
  
# v1.6.5
- Implemented language file support for JavaScript.
- Used the prefix `proctoring-xxxx` for CSS selectors to avoid style conflicts
  

# v1.6.4

## Fixed
- Ensured CSS uses properly namespaced selectors to prevent UI conflicts.
- Fixed missing language strings.
- Resolved errors in scheduled tasks.

# v1.6.3

## Improvements  
- Removed unused variables and optimized database queries.  
- Ensured placeholders in SQL queries for security.  

## Bug Fixes  
- Fixed pagination issue in user list.  
- Resolved image upload exception and Apache log errors.  
- Fixed `rand()` function error in scheduled tasks.  

## Security  
- Added missing capability checks in web services.  
- Validated `sesskey` before executing actions.  

## Code Standards  
- Fixed third-party library paths and ensured localization.  
- Resolved PHP warnings and improved PostgreSQL compatibility.  


# v1.6.2
### Updated

- User list pages are now sortable in both ascending and descending order.
- Added notification when analyzing the image.
- Corrected breadcrumb added for better navigation.
- Removed hardcoded string.
- Full name is now displayed in the user list page.

# v1.6.1

### Bug Fixes
- **Security Issue (#108):** Fixed user image exposure via public URLs in the Proctoring Plugin.

### Changed
- **Delete All Images (#69):** Optimized image deletion using a cron job to handle large file volumes efficiently.

# v1.6.0

### Updated

- Image upload with face is now handled on the server side.
- Code refactored to comply with Moodle standards.
- Settings page UI updated.
- Search box added to the user list.
- Pagination added to the report page.

# v1.5.1

### Updated

- Fixed the images count in the Proctoring summary report
- Redesigned the user interface of the Proctoring Pro promo page
- Added a proctoring pro promo banner in the users list page

# v1.5.0

### Updated
- Discontinued AWS Rekognition support from the version 1.5.0.
- Removed vendor folder containing AWS SDK.
- Some CSS fixes.
- Removed proctoring log button.

# v1.4.2

### New Features
- New option in face match method named as **None** at Proctoring Settings.

- Turned off Face Recognition models when face match method is either AWS or None.

### Updated
- Updated Video preview in the existing modal when **Validate Face Before Starting Quiz** is enabled.


# v1.3.2

### Release Notes:
- Updated plugin required version to 2023042400 (Moodle 4.2 stable).
- Updated release version to '1.3.2'.



# v1.3.0

### New Features
- Added new Custom AI API for face matching.
- Added Custom AI API field in the settings page for face verification.
- Added custom AI API Key field in the settings page for face verification.

### Update:
- New report page with proctoring pro promotional page.


### Removed

- Username and Password from the settings page.

# v1.5.1 

- Responsive Mobile View for Start Attempt and Proctoring Report
- Change the settings name for clarity.
- Checked automatic analysis of all images (-1) and five random images.
- Fixed Promotion page 
- Fixed the issue where the user image remained in the database after being deleted by the admin.
- Change Face Validation status: 'True' to 'Face Match', 'False' to 'Face Not Match',
 and if the site admin has not uploaded the user, display 'Face Not Found, please contact admin'.
- if the user doesn't upload an image, a warning will be shown, and they will be redirected to the upload page.

# Certificate Proctoring Tool Feedback v2 (7/26/2026) — triage and requirements

Source: "Certificate Proctoring Tool Feedback, v2 7/26/2026" (Stephanie Felice, with comments from
Lindsay Rice and replies from David Ta). Struck-through items in that document were already closed
by v1.7.0 and the unreleased work on `master`; they are not repeated here.

Every open item in the document is accounted for below in exactly one of three states:

- **BUILD** — engineering work, specified with acceptance criteria.
- **ANSWER** — a question for the reviewer, or a policy/ops decision. No code, but an answer owed.
- **REPRO** — a defect report that cannot be specified until reproduced.

Priority is the reviewer's own emphasis plus how badly the current behaviour misleads a reviewer:
P1 = actively misleading or blocks review, P2 = review-quality gap, P3 = polish.

---

## A. Master dashboard — buckets and flow (P1)

The reviewer's core complaint: the four pulse cards do not describe the work, and there is no
statement of how an attempt moves between them.

Her requested order and naming, verbatim:

1. All attempts
2. Flagged attempts (replacing Clean attempts — "we'd get more value out of a flagged bucket")
3. Withheld certificates (replacing "Waiting for your review" — "SA hasn't determined yet what and
   how we're reviewing, so this title is misleading")
4. Confirmed violations (replacing "Escalated" — "these seem to be closed cases")

Current code: `overall_reports.php:479-514` builds the cards in the order Needs / All / Clean /
Escalated, labelled from `overallreport:pulse_*` in `lang/en/quizaccess_proctoring.php:473-482`.
The underlying review states (`needs`, `flagged`, `reviewed`, `escalated`, `clean` in
`classes/local/overall_report.php:340-393`) are correct and stay as they are — this is naming,
ordering and explanation, not a data-model change.

- **A1 (BUILD)** Pulse cards render in the order All attempts → Flagged attempts → Withheld
  certificates → Confirmed violations. The Clean card is dropped from the card row; the `clean`
  queue stays reachable as a view pill, so no row becomes unreachable.
- **A2 (BUILD)** `pulse_needs` reads "Withheld certificates", `pulse_escalated` reads "Confirmed
  violations", and a new `pulse_flagged` card reads "Flagged attempts" with the hint "Signals
  detected, no certificate held". The hint under Withheld certificates keeps its three
  configuration-aware variants (`pulse_needs_hint`, `_autofail`, `_off`).
- **A3 (BUILD)** View pills follow the same vocabulary and order: All attempts, Flagged, Withheld
  certificates, Confirmed violations, Reviewed, Clean.
- **A4 (BUILD)** Per-row status labels match the card vocabulary: `status_needs` reads "Certificate
  withheld" and `status_escalated` reads "Violation confirmed".
- **A5 (BUILD)** The default landing queue stays `needs` (now "Withheld certificates"), which is
  what the reviewer asked for in the previous round.
- **A6 (BUILD)** A collapsed "How an attempt gets here" disclosure above the table states the
  lifecycle in one screen: a submitted attempt scores; a score at or above the hold threshold opens
  a hold, which withholds the grade and any certificate and puts the attempt under Withheld
  certificates; a reviewer either releases it (→ Reviewed) or confirms the violation (→ Confirmed
  violations); an attempt with signals but no hold sits under Flagged, where a reviewer can sign it
  off; an attempt with nothing detected is Clean. This is the direct answer to "What is the intended
  flow here?" and "After I have reviewed a report, what do I do with it?", written where the
  question gets asked.
- **A7 (ANSWER)** Her reading of her own test attempt is right: a high score only reaches Withheld
  certificates if a hold actually opened. With no certificate activity in the course the certificate
  label resolves to "no certificate", the hold still opens, and the attempt does appear under
  Withheld certificates — but with nothing to withhold. If no hold opened at all (score below the
  threshold, or holds switched off), it lands in Flagged. The card hint now says which of those is
  the case.

Note on naming collision: the standalone **Held certificates** tab
(`overall_reports.php:406-430`) lists active holds whose certificate currently resolves to "held" —
the same population as the Withheld certificates card, minus attempts in courses with no certificate
activity. A8 keeps them from reading as two unrelated things.

- **A8 (BUILD)** The Withheld certificates card links onward to the Held certificates tab, and the
  Held certificates page says in one line how its population relates to the card.

## B. Attempts report — filters and table (P2)

Most of this section closed with the table rework (commit `ee75b04`): the report is in columns,
carries name + email, a hyperlinked Moodle ID, exam score, duration, account age, a link to the
attempt, and no stray "Details" affordance.

Open:

- **B1 (BUILD)** "Most violations" / "Min. violations" name something the reviewer cannot see. Both
  are renamed to the thing the column actually shows — detected events — and the filter carries a
  one-line explanation of what counts ("suspicious browser events plus face mismatches; recovery
  events are not counted"). Her comment thread about Flagged vs Clean shows the two easy misreads
  worth stating in that help text: the period filter applies to the events, and a minimum of 1 or
  more necessarily makes the Clean count 0.
- **B2 (BUILD)** Every date the plugin shows staff uses the grade-results report's format
  (`str_replace(',', ' ', get_string('strftimedatetime'))`). The dashboard already does
  (`classes/local/overall_report.php:761`); the per-quiz report (`report.php:562`), Held
  certificates (`overall_report.php:1123`) and Manage overrides (`manage_overrides.php:313,388`)
  still use bare `userdate()`. One shared helper, used everywhere.

## C. Per-quiz proctoring report (P1/P2)

- **C1 (BUILD, P1)** The suspicious-activity count is wrong in the way that matters most: it counts
  every row in `quizaccess_proctoring_events` (`report.php:571-575`), including recovery and routine
  events, so an attempt reads "724 suspicious activities" while the report body shows four findings.
  It must count what the dashboard counts — the suspicious event types in
  `overall_report::SUSPICIOUS_EVENT_TYPES` — and the column header must say "Detected events", with
  the flagged-findings count shown alongside so the number in the table and the number in the report
  agree.
- **C2 (BUILD)** Rename the "Time taken" column to "Duration", matching the grade report.
- **C3 (BUILD)** Add Exam score and Account age columns (the dashboard already computes both;
  the report does not show either).
- **C4 (BUILD)** Add a link to the student's actual quiz attempt, next to the existing View report
  action.
- **C5 (BUILD)** Sortable email column, and separate First name / Last name sort headers as on the
  grade report — the reviewer uses that sort to spot duplicate accounts.
- **C6 (BUILD, P2)** ID verification evidence is captured (`quizaccess_proctoring_idv`, images
  written by `external.php:1039-1080`) and displayed nowhere. Add an ID verification block to the
  per-student report: the captured ID image, the live comparison image, the name-match verdict and
  reason, and the time of the check — visible to the same roles that can already see webcam
  captures, and deleted by the same retention task that already deletes those files
  (`classes/task/delete_images_task.php:204-218`).

## D. Collaboration (P2)

"We're still missing some way to identify that a member of Student Affairs has reviewed a particular
report… at both the master dashboard level and at the report level" and "a place for Student Affairs
to add a note."

- **D1 (BUILD)** Sign-off is recorded and shown on the dashboard (`attempt_review`, surfaced at
  `overall_report.php:845-853`) but the per-student report shows nothing. The report must state the
  review state in words — who released, confirmed or signed off, and when — and offer the same
  sign-off action for a flagged attempt with no hold.
- **D2 (BUILD)** Reviewer notes. A new append-only table keyed the same way sign-offs are
  (course/quiz/user/attempt, falling back to report id), with add and delete-own on the per-student
  report, author and timestamp on every note, and a note count on the dashboard row so a reviewer
  can see there is context before opening. Notes are staff-only, never shown to the student, and
  handled by the privacy provider the same way the existing reviewer-verdict table is: exported to
  the student they are about and to their author, deleted outright when that student's module data
  is deleted, and anonymised (author only) when the author's is.

## E. Manage overrides (P2)

- **E1 (BUILD)** Student names link to the profile, in both the pending-requests table and the
  active-overrides table.
- **E2 (BUILD)** Student identity is split into Name / Email / ID columns instead of one
  "name · email" cell, matching other Moodle participant tables.
- **E3 (BUILD)** Requested and Expiry render in Eastern time with the zone named, the same way the
  exception emails already do (`exemption_email::DISPLAY_TIMEZONE`). The helper moves somewhere both
  can use it.
- **E4 (BUILD)** The required Justification is captured on create/edit but never displayed. Add it
  as a column on the active-overrides table.
- **E5 (ANSWER)** The site-wide queue she asked for exists as of v1.7.0: the ID exceptions tab on
  the Proctoring reports page lists pending requests across every course and exam with batch
  approve/decline. Manage overrides itself stays per-exam because a proctoring override is
  quiz-scoped.

## F. Held certificates (P3)

- **F1 (BUILD)** Answer the date question in the table rather than in a reply: "Held since" is when
  the hold opened. Add an "Attempt finished" column next to it so the two dates are never confused,
  and name both in the intro line.
- **F2 (ANSWER/DEV)** The request to "have data populated on this page" is a dev-environment task,
  not a code change: the dev course has no certificate activity, so nothing can be held. Add a
  certificate activity to the dev test course and drive one held attempt through it.

## G. Scoring (P2)

- **G1 (BUILD)** "Turn off the capping at 100 as the default option… while we are evaluating
  scoring, we need to see the raw score." `riskscorecapenabled` defaults to on
  (`settings.php:1073`). Default it to off for new installs; sites that already saved a value keep
  it, and the upgrade does not touch existing configuration.
- **G2 (BUILD)** "Finished unusually fast" never states its own threshold. Both the factor
  description and the per-attempt finding must state the configured pace and the attempt's actual
  pace ("Completed in 8 mins — about 9 secs per question, against a 15 secs minimum"). This is the
  reviewer's question answered in the product: the threshold is a pace, not a percentile.
- **G3 (BUILD)** "Voices or sounds detected" — the detector is a level threshold on the microphone,
  not speech recognition, and nothing is recorded or transcribed. The factor description must say
  so, so nobody reads the finding as evidence of dictation.

## H. In-exam (P1/P2)

- **H1 (REPRO/BUILD, P1)** Repeated "you aren't sharing your screen" prompts mid-attempt. The
  unreleased marker-grace and no-stop-on-fault work on `master` addresses exactly this failure mode.
  Needs a confirming run on dev before it is called closed; if it still recurs, the remaining cause
  is a separate ticket.
- **H2 (BUILD, P2)** The in-exam warning banner is only visible at the top of the page. It is
  `position: sticky` inside `#region-main` (`styles.css:1380`, inserted at
  `amd/src/proctoring.js:363-382`), which fails under themes whose ancestors carry a transform —
  and Saylor's theme is a candidate. Move it to a viewport-anchored container that does not depend
  on an ancestor's containing block, keeping the alert role and dismissal behaviour.
- **H3 (BUILD, P3)** Collapse the course index drawer by default on a proctored attempt page.
- **H4 (ANSWER)** Desktop calculators: currently a desktop calculator app looks like focus loss and
  scores as one. Whether to allow it is a policy decision; if the answer is yes, it is an "allow one
  brief app switch" carve-out and its own ticket.

## I. Pre-exam / setup (P2/P3)

- **I1 (BUILD)** The empty "Time limit" heading in the setup window. The stepper hides
  `quizaccess_timelimit`'s message and legend by id (`amd/src/startAttempt.js:1738-1748`); the
  heading the reviewer still sees means those ids or that structure did not match. Hide the whole
  time-limit fieldset when the compact footer line is present and the fieldset holds nothing else.
- **I2 (BUILD)** Pillarboxed camera preview on a built-in Mac camera. The ID and face steps request
  a high resolution without an aspect-ratio constraint, so a camera handing back a 4:3 mode is
  letterboxed into a 16:9 box. Constrain the request and let the preview cover its box instead of
  fitting inside it.
- **I3 (BUILD)** Nothing tells a student on a phone or tablet that the exam needs a laptop or
  desktop (Lindsay's comment). State the device requirement before setup starts, and detect the
  no-usable-camera case explicitly rather than failing at step 2.
- **I4 (REPRO)** "After my first exam attempt, I haven't been able to get ID verification to work
  again" and Lindsay's blur-loop plus failed upload on a PC. Two reports of the ID step failing
  after first use; the screenshots name an error string that will identify the path. Reproduce
  before specifying.

## J. Student feedback (P2)

Stephanie: the course feedback survey only reaches completers, and the students whose feedback is
most valuable are the ones who abandoned setup or failed the technology. "We'd also get better
realtime feedback if the feedback mechanism is placed closer to or in the activity itself."

- **J1 (BUILD)** A configurable feedback link (site setting, empty by default so nothing appears
  until Ops supplies a URL) rendered in two places students actually reach: the setup stepper — the
  one surface an abandoning student sees — and the post-attempt proctoring notice. The link carries
  the course and exam as query parameters so a response can be traced without asking the student
  what they were doing.

## K. Answers owed, no code

- **K1** Course-level proctoring language on live courses (PRDV225 on dev mentions proctoring only
  inside the activity) — content task, not plugin.
- **K2** Students with no webcam: the mechanism exists (a webcam override on Manage overrides); the
  policy of who qualifies is Student Affairs'. This is the third time the question has been asked
  and it needs a written answer, not another pointer at the mechanism.
- **K3** The dev site's "Saylor Academy" branding changes on production (already answered in the
  document).

---

## Ordering

1. A1–A6, A8 — the buckets, since every other dashboard answer is read through them.
2. C1 — the 724-vs-4 count, the one number in the tool that is actively wrong.
3. C2–C5, B1, B2 — report columns, labels and dates.
4. E1–E4 — overrides page.
5. G1–G3, F1 — scoring defaults and thresholds.
6. D1, D2 — collaboration.
7. H2, H3, I1 — front-end fixes.
8. I2, I3, J1 — camera, device gate, feedback link.
9. H1, I4 — reproduce on dev.

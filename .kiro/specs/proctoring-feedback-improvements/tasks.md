# Implementation Plan: Proctoring Feedback Improvements (P0 + P2)

## Overview

This plan converts the P0 (Requirements 1–6, components C1–C6) and P2 (Requirements 13–18,
components C7–C12) portions of the design into incremental, test-driven coding tasks for the
`quizaccess_proctoring` Moodle plugin. Implementation language is **PHP** (server-side) and
**JavaScript/AMD** (client-side camera lifecycle), following the languages used in the design.

Sequencing follows Moodle plugin conventions: schema/settings/version scaffolding first, then the
pure decision helpers (test-first where practical, since most Correctness Properties test them),
then wiring each helper into its call site, then the client-side AMD change, then documentation and
reconciliation. Correctness-critical P0 items (auto-release ceiling, certificate label consistency,
AI-review-at-submission, tool-failure isolation, certificate date, camera lifecycle) are completed
and verified before P2 begins.

Property-based tests run a **minimum of 100 generated iterations** and each carries a tag comment in
the form: `Feature: proctoring-feedback-improvements, Property {n}: {property_text}`. UI/DOM/
lifecycle criteria (Req 6, 14, 18.1/18.3/18.4) and documentation (16.1) are covered by
example/interaction/smoke tests instead of properties, per the Testing Strategy.

Tasks marked with `*` are optional test sub-tasks and can be skipped for a faster MVP; they are
never core implementation.

## Tasks

# P0 — Urgent / Correctness (Requirements 1–6)

- [x] 1. Schema, settings, version, and accessor scaffolding (C1, C3)
  - [x] 1.1 Add schema columns and bump plugin version
    - Add nullable `autoreleaseblockedscore` (int(10), not null, default `0`) and
      `autoreleaseblockedreason` (char(40), null, default null) to the
      `quizaccess_proctoring_risk_holds` table in `db/install.xml`
    - Add a matching upgrade step in `db/upgrade.php` that adds both fields on existing installs
      (guarded by `field_exists`), then calls `upgrade_plugin_savepoint`
    - Bump the version number in `version.php` to match the new upgrade savepoint
    - _Requirements: 1.5_
    - _Design: C1, Data Models → Schema changes_
  - [x] 1.2 Add site settings and language strings
    - Add `quizaccess_proctoring/riskreviewceiling` (admin_setting_configtext, int, default `101`)
      to `settings.php`
    - Add `quizaccess_proctoring/aireviewtriggermode` (admin_setting_configselect with options
      `everyattempt` and `threshold`, default `threshold`) to `settings.php`
    - Add the setting labels/help and enum option strings to `lang/en/quizaccess_proctoring.php`
    - _Requirements: 1.1, 3.1_
    - _Design: C1, C3, Data Models → New site settings_
  - [x] 1.3 Implement configuration accessors in `lib.php`
    - Implement `quizaccess_proctoring_get_risk_review_ceiling(): int` reading
      `riskreviewceiling` via `get_config()` and clamping the result to 0–101
    - Implement `quizaccess_proctoring_get_ai_review_trigger_mode(): string` reading
      `aireviewtriggermode`, returning one of `everyattempt`/`threshold` (default `threshold`)
    - _Requirements: 1.1, 1.4, 3.1_
    - _Design: C1, C3_
  - [x] 1.4 Write smoke tests for the new settings/accessors
    - Assert `riskreviewceiling` defaults to `101` and the accessor clamps out-of-range values
    - Assert `aireviewtriggermode` accepts only `{everyattempt, threshold}` and defaults to
      `threshold`
    - _Requirements: 1.1, 3.1_
    - _Design: Testing Strategy → SMOKE (Req 1.1, 3.1)_

- [x] 2. Auto-release ceiling gate (C1, Requirement 1)
  - [x] 2.1 Implement the pure auto-release selection helper in `lib.php`
    - Implement `quizaccess_proctoring_auto_release_selection(array $holds, int $ceiling, int $now, int $days): array`
      partitioning holds into `release` and `retain`: a hold is released iff it is active, its
      review window has expired (`timecreated` older than `$days`), and `riskscore < $ceiling`;
      when `$ceiling > 100`, every expired active hold is released
    - Keep the helper database-free so it can be exercised by property tests
    - _Requirements: 1.2, 1.3, 1.4_
    - _Design: C1, Testing Strategy → auto_release_selection_
  - [x] 2.2 Write property test for the auto-release selection helper
    - **Property 1: Auto-release ceiling gate** — a hold is released iff active, expired, and its
      risk score is strictly below the ceiling; ceiling > 100 releases all expired active holds
    - Generate holds with random status/age/score and random ceiling; assert the partition; run
      >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 1: Auto-release ceiling gate`
    - **Validates: Requirements 1.2, 1.3, 1.4**
    - _Design: Correctness Properties → Property 1_
  - [x] 2.3 Wire the ceiling gate and annotation pass into auto-release
    - Update `quizaccess_proctoring_auto_release_expired_risk_holds()` in `lib.php` to add the
      `riskscore < :ceiling` SQL predicate (omitted when ceiling disabled) using
      `quizaccess_proctoring_get_risk_review_ceiling()`
    - Add an idempotent annotation pass that records `autoreleaseblockedscore` and
      `autoreleaseblockedreason` (`riskceiling`) once per retained hold
      (guard `autoreleaseblockedreason IS NULL OR ''`)
    - Update `classes/task/release_expired_risk_holds_task.php` to `mtrace` both the released and
      retained/annotated counts
    - _Requirements: 1.2, 1.3, 1.5_
    - _Design: C1_
  - [x] 2.4 Write property test for retention-reason annotation (DB path)
    - **Property 2: Ceiling-blocked holds record their retention reason** — a ceiling-blocked hold
      stays active with `autoreleaseblockedscore` == its risk score and a set reason; re-running is
      idempotent
    - Use `advanced_testcase` with `resetAfterTest()` and generated fixture holds; run >= 100
      iterations over generated scores/ceilings
    - Tag: `Feature: proctoring-feedback-improvements, Property 2: Ceiling-blocked holds record their retention reason`
    - **Validates: Requirements 1.5**
    - _Design: Correctness Properties → Property 2_

- [x] 3. Certificate label and state consistency (C2, Requirement 2)
  - [x] 3.1 Implement the pure certificate-state helper in `lib.php`
    - Implement `quizaccess_proctoring_certificate_state(bool $hasactive, bool $hasreleased, bool $hasconfirmed, bool $hasgrade): string`
      returning one of `held`/`withheld`/`released`/`issued`/`none` per the derivation table
    - _Requirements: 2.1, 2.2_
    - _Design: C2, Data Models → Certificate-state model, Testing Strategy → certificate_state_
  - [x] 3.2 Write property test for the certificate-state helper
    - **Property 3: Certificate label reflects live state and never mislabels an issued certificate**
      — never returns `held`/`withheld` when a grade is present and no active/confirmed hold exists;
      re-resolving after a transition yields the new state
    - Generate all combinations of (active, released, confirmed, grade-present) flags; run
      >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 3: Certificate label reflects live state and never mislabels an issued certificate`
    - **Validates: Requirements 2.1, 2.2, 2.3**
    - _Design: Correctness Properties → Property 3_
  - [x] 3.3 Wire the resolver into both reports
    - Implement `quizaccess_proctoring_resolve_certificate_label(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): array`
      in `lib.php` that gathers live hold + gradebook state and delegates to
      `quizaccess_proctoring_certificate_state()`, returning `{state,label,class}`
    - Replace the direct `quizaccess_proctoring_get_risk_hold_status_label()` call for the
      certificate column in `report.php` and `classes/local/overall_report.php` with the resolver
    - Resolve defensively (missing rows → `none`, read-only queries)
    - _Requirements: 2.1, 2.2, 2.3_
    - _Design: C2_
  - [x] 3.4 Write example test for label reconciliation edge case
    - Assert a grade present with a stale released hold resolves to `issued`/`released` (never
      `held`)
    - _Requirements: 2.2_
    - _Design: Testing Strategy → Edge cases (stale released hold)_

- [x] 4. Automatic AI image review at submission (C3, Requirement 3)
  - [x] 4.1 Implement the pure enqueue-decision helper in `lib.php`
    - Implement `quizaccess_proctoring_should_enqueue_ai_review(bool $configured, string $mode, int $score, int $triggerthreshold): bool`
      returning `$configured && ($mode === 'everyattempt' || $score >= $triggerthreshold)`
    - _Requirements: 3.2, 3.3, 3.4_
    - _Design: C3, Testing Strategy → should_enqueue_ai_review_
  - [x] 4.2 Write property test for the enqueue-decision helper
    - **Property 13: AI-review enqueue decision follows the configured trigger mode**
    - Generate random configured-state, mode, score, and threshold; assert the biconditional; run
      >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 13: AI-review enqueue decision follows the configured trigger mode`
    - **Validates: Requirements 3.2, 3.3, 3.4**
    - _Design: Correctness Properties → Property 13_
  - [x] 4.3 Add `$force` to the enqueue path and wire the observer to the mode
    - Add a `$force = false` parameter to `quizaccess_proctoring_queue_ai_review()` in `lib.php`
      that bypasses the internal `riskscore < triggerthreshold` early-return when true; delegate the
      decision to `quizaccess_proctoring_should_enqueue_ai_review()`
    - Update `classes/proctoring_observer.php` `handle_quiz_attempt_submitted()` to read the trigger
      mode: `everyattempt` calls with `$force = true`, `threshold` calls with `$force = false`; both
      remain gated by `quizaccess_proctoring_ai_review_configured()`
    - _Requirements: 3.2, 3.3, 3.4, 3.8_
    - _Design: C3_
  - [x] 4.4 Distinguish pending from "Not queued" in the status label
    - Update `quizaccess_proctoring_get_ai_review_status_label()` in `lib.php` so QUEUED/PROCESSING
      render as pending, COMPLETE as completed, FAILED as tool-failure, and "Not queued" is returned
      only when no AI review row exists
    - _Requirements: 3.6_
    - _Design: C3, Testing Strategy → get_ai_review_status_label_
  - [x] 4.5 Write property test for the AI status-label mapping
    - **Property 4: AI review status is never presented as "Not queued" once a row exists**
    - Generate every status value (including "no row"); assert the label variant; run >= 100
      iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 4: AI review status is never presented as "Not queued" once a row exists`
    - **Validates: Requirements 3.6**
    - _Design: Correctness Properties → Property 4_
  - [x] 4.6 Write example/interaction tests for mode-selected enqueue and result surfacing
    - `everyattempt` mode: a below-threshold proctored submission inserts a `QUEUED` row (Req 3.2)
    - `threshold` mode: at/above-threshold submission inserts `QUEUED`; below-threshold inserts no
      row and the report shows "Not queued" (Req 3.3, 3.4)
    - A completed review surfaces as the current score without a manual action (Req 3.5, 3.7); the
      manual "Analyze images" re-run control renders in both modes (Req 3.8)
    - _Requirements: 3.2, 3.3, 3.4, 3.5, 3.7, 3.8_
    - _Design: Testing Strategy → EXAMPLE (Req 3.2–3.8)_

- [x] 5. Distinguish tool failure from student risk (C4, Requirement 4)
  - [x] 5.1 Separate tool-failure presentation from student-risk signals
    - Update `quizaccess_proctoring_format_ai_review_for_template()` in `lib.php` to return distinct
      `isfailed` vs `isflagged` flags
    - Render a neutral "tool could not complete" indicator (class `proctoring-ai-toolfailure`) in
      `templates/report.mustache`, visually separated from student-risk badges; add the language
      string
    - _Requirements: 4.1_
    - _Design: C4_
  - [x] 5.2 Write property test for tool-failure isolation from the risk score
    - **Property 5: Tool failures are isolated from student risk** — the risk score computed with any
      AI outcome equals the score computed ignoring the AI outcome; a failed review never creates or
      sustains a hold when it is the only adverse signal
    - Drive `risk_calculator` with injected event counts plus a random AI outcome; run >= 100
      iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 5: Tool failures are isolated from student risk`
    - **Validates: Requirements 4.1, 4.2, 4.3, 16.2**
    - _Design: Correctness Properties → Property 5_
  - [x] 5.3 Write example test for provider-error handling
    - Simulate a provider error and assert a `FAILED` AI review row with `errormessage` is written
      and **no** student-risk event is created
    - _Requirements: 4.3_
    - _Design: Testing Strategy → EXAMPLE (Req 4.3)_

- [x] 6. Certificate date reflects exam completion (C5, Requirement 5)
  - [x] 6.1 Implement the pure certificate-date helper in `lib.php`
    - Implement `quizaccess_proctoring_certificate_date_for_release(int $timefinish, int $fallback): int`
      returning `$timefinish` when known, else `$fallback`, independent of release delay
    - _Requirements: 5.1, 5.2_
    - _Design: C5, Testing Strategy → certificate_date_for_release_
  - [x] 6.2 Write property test for the certificate-date helper
    - **Property 6: Released certificate date is the exam completion date** — the chosen date equals
      the completion time (or the defined fallback when unknown), independent of the release delay
    - Generate random completion times, fallbacks, and delays; run >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 6: Released certificate date is the exam completion date`
    - **Validates: Requirements 5.1, 5.2**
    - _Design: Correctness Properties → Property 6_
  - [x] 6.3 Backdate the restored grade in `release_risk_hold`
    - In `quizaccess_proctoring_release_risk_hold()` (`lib.php`), after `quiz_update_grades()`, fetch
      `quiz_attempts.timefinish` (fallback to the report log timemodified, then current time) and set
      the grade item `dategraded` to `quizaccess_proctoring_certificate_date_for_release()` via
      `quiz_grade_item_update()`; log and proceed on date-fetch error
    - _Requirements: 5.1, 5.2_
    - _Design: C5, Error Handling → Certificate date backdating_

- [x] 7. Camera lifecycle scoped to Pre-Check (C6, Requirement 6)
  - [x] 7.1 Scope camera acquisition and teardown in `amd/src/proctoring.js`
    - Remove/guard any `requestUserCamera()` call on activity page load so the camera is not acquired
      before the Pre-Check modal opens (Req 6.1)
    - Move `getUserMedia` acquisition into the modal `shown`/open callback, bind the `MediaStream` to
      the modal `<video>`, and track it in a module-scoped `precheckStream` handle (Req 6.2)
    - Add `teardownPrecheckCamera()` that stops all tracks, nulls `video.srcObject`, and clears
      `precheckStream`/`isCameraAllowed`; wire it to modal hidden/cancel, abort/exit, and
      `beforeunload`/`pagehide` (Req 6.3), mirroring the existing `stopIdDocumentStream()` pattern
    - _Requirements: 6.1, 6.2, 6.3_
    - _Design: C6, Error Handling → Camera lifecycle_
  - [x] 7.2 Rebuild the AMD bundle
    - Rebuild `amd/build/proctoring.min.js` (and source map) from the updated source
    - _Requirements: 6.1, 6.2, 6.3_
    - _Design: C6 (rebuild to amd/build/)_
  - [x] 7.3 Write interaction/smoke tests for the camera lifecycle
    - Assert `getUserMedia` is not called before the modal opens, is called on open, and that all
      tracks are stopped and `srcObject` nulled on cancel/hide/`pagehide` (JS interaction or Behat)
    - _Requirements: 6.1, 6.2, 6.3_
    - _Design: Testing Strategy → EXAMPLE, JS (Req 6.1/6.2/6.3)_

- [x] 8. Checkpoint — P0 verified before P2
  - Ensure all tests pass, ask the user if questions arise.

# P2 — Report UX and Review Quality (Requirements 13–18)

- [x] 9. Sortable and filterable report (C7, Requirement 13)
  - [x] 9.1 Implement the safe ORDER BY helper in `lib.php`
    - Implement `quizaccess_proctoring_report_order_by(string $sort, string $dir): string` mapping a
      requested key/direction to an allowlisted `ORDER BY` fragment, defaulting to newest-first for
      unknown input (no raw user SQL)
    - _Requirements: 13.1, 13.2_
    - _Design: C7, Testing Strategy → report_order_by_
  - [x] 9.2 Write property test for the ORDER BY helper
    - **Property 7: Report sorting orders rows by the selected column and defaults to newest-first**
    - Generate row lists plus random allowlisted sort keys/directions and unknown keys; sort
      in-memory and assert ordering; run >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 7: Report sorting orders rows by the selected column and defaults to newest-first`
    - **Validates: Requirements 13.1, 13.2**
    - _Design: Correctness Properties → Property 7_
  - [x] 9.3 Implement the name-initial filter helper in `lib.php`
    - Implement `quizaccess_proctoring_name_matches_initials(string $firstname, string $lastname, string $firstinitial, string $lastinitial): bool`
      matching case-insensitively, treating a blank initial as "all"
    - _Requirements: 13.3_
    - _Design: C7, Testing Strategy → name_matches_initials_
  - [x] 9.4 Write property test for the name-initial filter helper
    - **Property 8: Name-initial filter matches by initial** — included iff first name starts with
      the selected first initial (or blank) and last name starts with the selected last initial (or
      blank), case-insensitively
    - Generate names and initial filters, including non-Latin and mixed-case; run >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 8: Name-initial filter matches by initial`
    - **Validates: Requirements 13.3**
    - _Design: Correctness Properties → Property 8_
  - [x] 9.5 Wire sort/filter into the per-quiz report
    - Update `report.php` to default `ORDER BY timemodified DESC`, accept `sort`/`dir` params via
      `quizaccess_proctoring_report_order_by()`, and accept `firstnameinitial`/`lastnameinitial`
      params via `quizaccess_proctoring_name_matches_initials()`
    - Add column-sort headers and an A–Z initial bar to `templates/report.mustache`; add language
      strings
    - _Requirements: 13.1, 13.2, 13.3_
    - _Design: C7_

- [x] 10. Plain-language session summary (C9, Requirement 15)
  - [x] 10.1 Implement the pure session-summary generator
    - Implement `quizaccess_proctoring_build_session_summary(array $risk, $aireview): string` (in
      `classes/local/session_summary.php`) selecting top contributing factors, phrasing each in
      plain language, collapsing high evidence counts into a count phrase, and joining into at most
      three sentences; never emit raw telemetry key tokens
    - _Requirements: 15.1, 15.2, 15.3_
    - _Design: C9, Testing Strategy → build_session_summary_
  - [x] 10.2 Write property test for the session-summary generator
    - **Property 9: Plain-language summary is bounded and telemetry-free** — at most three
      sentences, never contains raw telemetry key tokens (e.g. "viewportheight"), and size stays
      bounded as an evidence count grows arbitrarily large
    - Generate risk-factor inputs with very large counts and random AI outcomes; run >= 100
      iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 9: Plain-language summary is bounded and telemetry-free`
    - **Validates: Requirements 15.1, 15.2, 15.3**
    - _Design: Correctness Properties → Property 9_
  - [x] 10.3 Render the summary and collapse raw telemetry in the report
    - Call the generator from `report.php`, render the summary in `templates/report.mustache`, and
      move raw telemetry key/values into a collapsed "raw details" disclosure; add language strings
    - _Requirements: 15.1, 15.2, 15.3_
    - _Design: C9_

- [x] 11. Documented and reconciled risk-scoring model (C10, Requirement 16)
  - [x] 11.1 Write property test for risk-score monotonicity
    - **Property 10: Risk score is monotonic in suspicious evidence** — for two attempts whose
      suspicious-event multisets differ only in that the second has at least as many of every type,
      the second score is >= the first
    - Drive `risk_calculator` with generated event-count pairs where the second dominates the first;
      run >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 10: Risk score is monotonic in suspicious evidence`
    - **Validates: Requirements 16.2**
    - _Design: Correctness Properties → Property 10_
  - [x] 11.2 Document and reconcile the scoring model
    - Add a "Risk-Scoring Model" doc block to `classes/local/risk_calculator.php` listing every
      factor, per-event points, and cap (mirroring `calculate_attempt()`)
    - Audit that every event type in `overall_report::SUSPICIOUS_EVENT_TYPES` maps to a scoring
      factor; add any missing factor and note it in the reconciliation, confirming AI outcome is
      never summed into the score
    - _Requirements: 16.1, 16.2_
    - _Design: C10_
  - [x] 11.3 Write smoke test for the scoring-model documentation
    - Assert the doc block/reference exists and lists every factor and cap
    - _Requirements: 16.1_
    - _Design: Testing Strategy → SMOKE (Req 16.1)_

- [x] 12. Small report fixes (C12, Requirement 18)
  - [x] 12.1 Implement the Yes/No identity-mismatch mapping helper in `lib.php`
    - Implement a helper mapping the identity/name-mismatch flag to the localized "Yes"/"No" string
      (never blank/raw)
    - _Requirements: 18.2_
    - _Design: C12, Testing Strategy → Yes/No mapping_
  - [x] 12.2 Write property test for the Yes/No mapping helper
    - **Property 12: Identity Mismatch renders as Yes or No** — exactly localized "Yes" when a
      mismatch is present and exactly "No" otherwise, never blank/raw
    - Generate every flag value (truthy/falsy/blank/raw); run >= 100 iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 12: Identity Mismatch renders as Yes or No`
    - **Validates: Requirements 18.2**
    - _Design: Correctness Properties → Property 12_
  - [x] 12.3 Apply the report display fixes
    - Replace `date('Y/M/d H:i:s', ...)` in `report.php` with `userdate()` using the results-report
      format (Req 18.1)
    - Render the Identity Mismatch column via the Yes/No helper (Req 18.2)
    - In `templates/report.mustache`, make "View images" the primary/emphasized action and
      de-emphasize "Delete" (retaining the destructive `confirm()` guard) (Req 18.3)
    - Remove the non-actionable "This can include…" line and its language string usage (Req 18.4)
    - _Requirements: 18.1, 18.2, 18.3, 18.4_
    - _Design: C12_
  - [x] 12.4 Write example tests for the report display fixes
    - Assert `userdate()` with the results-report format is used, "View images" is primary and
      "Delete" de-emphasized, and the "This can include…" line is absent
    - _Requirements: 18.1, 18.3, 18.4_
    - _Design: Testing Strategy → EXAMPLE (Req 18.1/18.3/18.4)_

- [x] 13. Cross-course held-certificate dashboard (C11, Requirement 17)
  - [x] 13.1 Add the held-certificates builder in `classes/local/overall_report.php`
    - Add a `held_certificates()` builder that selects `status = ACTIVE` holds across all courses,
      decorates each with user/course/quiz names and the resolved certificate label (C2), and
      paginates using existing `PER_PAGE`/`MAX_ATTEMPTS` bounds
    - _Requirements: 17.1, 17.2_
    - _Design: C11_
  - [x] 13.2 Write property test for dashboard membership (DB path)
    - **Property 11: Cross-course dashboard lists exactly the held certificates** — an attempt is in
      the dashboard iff its resolved certificate label is "held"; re-deriving after a status change
      reflects updated membership
    - Use `advanced_testcase` with `resetAfterTest()` and generated multi-course holds; run >= 100
      iterations
    - Tag: `Feature: proctoring-feedback-improvements, Property 11: Cross-course dashboard lists exactly the held certificates`
    - **Validates: Requirements 17.1, 17.2**
    - _Design: Correctness Properties → Property 11_
  - [x] 13.3 Surface the dashboard view
    - Add a held-certificate view toggle to `overall_reports.php`, render the aggregated list via a
      template, and guard it with the system-context review capability; add language strings
    - _Requirements: 17.1, 17.2_
    - _Design: C11_

- [x] 14. Inline attempt-review integration (C8, Requirement 14)
  - [x] 14.1 Extract the per-attempt panel and add the fragment renderer
    - Extract the per-attempt panel context builder from `report.php` into a reusable partial/method
      that, given `(courseid, cmid, userid, attemptid)`, builds the risk score, resolved
      certificate label, AI review status, and plain-language summary as an embeddable fragment
    - _Requirements: 14.1, 14.2_
    - _Design: C8_
  - [x] 14.2 Wire the fragment into the quiz attempt-review page
    - Add the renderer/hook that renders the fragment inline on the quiz attempt-review page,
      guarded by the existing `quizaccess/proctoring:reviewriskholds`/report-access capabilities
      (read/summary only; decision controls deferred to P1)
    - _Requirements: 14.1, 14.2_
    - _Design: C8_
  - [x] 14.3 Write example/interaction test for the inline panel
    - Assert the fragment renders alongside the attempt data for an authorized reviewer and is hidden
      for users lacking the capability
    - _Requirements: 14.1, 14.2_
    - _Design: Testing Strategy → EXAMPLE (Req 14.1/14.2)_

- [x] 15. Final verification — full suite, AMD rebuild, and upgrade path
  - Run the plugin's existing PHPUnit suite (`phpunit.xml`) plus all new property, example, and
    smoke tests
  - Rebuild AMD modules (`grunt amd`) and verify the built `amd/build/proctoring.min.js`
  - Confirm the upgrade path adds the two `quizaccess_proctoring_risk_holds` columns on an existing
    install and that `version.php` is bumped
  - Ensure all tests pass, ask the user if questions arise.
  - _Requirements: 1.5, 6.1, 6.2, 6.3_
  - _Design: Testing Strategy → Regression and build_

## Notes

- Tasks marked with `*` are optional test sub-tasks and can be skipped for a faster MVP; core
  implementation tasks are never optional.
- Each task references specific requirement acceptance criteria and the design component it
  implements for traceability.
- Property-based tests (Properties 1–13) run a minimum of 100 iterations and are tagged
  `Feature: proctoring-feedback-improvements, Property {n}: ...` using the pure helpers.
- UI/DOM/lifecycle criteria (Req 6, 14, 18.1/18.3/18.4) and documentation (16.1) are covered by
  example/interaction/smoke tests per the Testing Strategy.
- Checkpoint (task 8) ensures correctness-critical P0 items are verified before P2 work begins.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["1.3"] },
    { "id": 2, "tasks": ["2.1", "1.4"] },
    { "id": 3, "tasks": ["3.1", "2.2"] },
    { "id": 4, "tasks": ["4.1", "3.2"] },
    { "id": 5, "tasks": ["4.4", "4.2"] },
    { "id": 6, "tasks": ["6.1", "4.5"] },
    { "id": 7, "tasks": ["9.1", "6.2"] },
    { "id": 8, "tasks": ["9.3", "9.2"] },
    { "id": 9, "tasks": ["12.1", "9.4"] },
    { "id": 10, "tasks": ["2.3", "7.1", "12.2"] },
    { "id": 11, "tasks": ["5.1", "2.4", "7.2"] },
    { "id": 12, "tasks": ["6.3", "5.2", "7.3"] },
    { "id": 13, "tasks": ["4.3", "5.3"] },
    { "id": 14, "tasks": ["3.3", "4.6"] },
    { "id": 15, "tasks": ["9.5", "3.4"] },
    { "id": 16, "tasks": ["10.1"] },
    { "id": 17, "tasks": ["10.3", "10.2"] },
    { "id": 18, "tasks": ["12.3", "12.4"] },
    { "id": 19, "tasks": ["11.1", "11.2"] },
    { "id": 20, "tasks": ["13.1", "11.3"] },
    { "id": 21, "tasks": ["13.3", "13.2"] },
    { "id": 22, "tasks": ["14.1"] },
    { "id": 23, "tasks": ["14.2"] },
    { "id": 24, "tasks": ["14.3"] }
  ]
}
```

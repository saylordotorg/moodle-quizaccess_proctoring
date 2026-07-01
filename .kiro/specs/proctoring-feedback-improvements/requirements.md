# Requirements Document

## Introduction

This feature captures reviewer feedback from Student Affairs (Stephanie) on the `quizaccess_proctoring` plugin, combined with an engineering triage of that feedback. The goal is to correct incorrect and privacy-affecting behaviors, improve day-to-day operability for a small Student Affairs review team, raise report review quality, and align in-exam detection, content, and copy with reviewer expectations.

Requirements are grouped by theme and aligned to the reviewer's own priority tiers (P0 highest correctness urgency through P4 content/copy). Each requirement is engineering-actionable and expressed with EARS-format acceptance criteria.

Several items in the feedback are leadership or policy decisions rather than engineering work. These are **not** written as acceptance criteria. They are recorded in the "Assumptions and Dependencies" section so their impact on the engineering-actionable requirements is explicit. Key dependencies:

- The **leadership risk-ceiling decision** feeds the auto-release risk ceiling (Requirement 1).
- The **posture decision** (monitor-and-flag vs. lockdown browser) gates the in-exam posture requirements (Requirements 18–20).
- **Content items** (Requirements 22–27) depend on Operations-authored copy before Engineering can wire them.

This document defines *what* the system must do. Implementation choices are deferred to the design phase.

## Glossary

- **Proctoring_System**: The `quizaccess_proctoring` Moodle quiz access plugin, including its scheduled tasks, reports, and in-exam client behavior.
- **Risk_Hold**: A record in the `quizaccess_proctoring_risk_holds` table that withholds a quiz grade (and dependent certificate) pending review. Each row carries a `riskscore`.
- **Risk_Score**: A numeric measure (0–100) of how suspicious a proctoring session is, stored on the Risk_Hold row.
- **Risk_Ceiling**: A configurable Risk_Score threshold at/above which a Risk_Hold is exempt from automatic release and requires human review.
- **Auto_Release**: The scheduled behavior (`release_expired_risk_holds_task` running `quizaccess_proctoring_auto_release_expired_risk_holds()`) that releases active holds whose review window has expired.
- **Confirm_Withhold**: The reviewer action (`quizaccess_proctoring_confirm_risk_hold()`) that confirms a violation and keeps the grade held at zero.
- **Release_Decision**: A reviewer action that clears a Risk_Hold and restores the grade.
- **AI_Image_Review**: The automated image analysis process (`execute_ai_review_task`) that inspects captured images and contributes to the Risk_Score.
- **AI_Review_Trigger_Mode**: A site-administrator setting that selects when AI_Image_Review is enqueued at submission. It has at least two values: "every attempt" (enqueue for every proctored attempt) and "threshold" (enqueue only when the attempt's Risk_Score is at or above the AI-review trigger threshold).
- **Tool_Failure**: An error from an external tool or API (e.g., an OpenAI/vision service error) during AI_Image_Review, as distinct from student-attributable risk.
- **Pre_Check**: The pre-exam modal where the camera, identity, and consent checks run before an attempt begins.
- **Honesty_Statement**: The academic integrity statement shown to students, including Handbook links and unauthorized-AI-use language.
- **Certificate_Status**: The durable, queryable state of a certificate for an attempt: held, released, or withheld, including who acted and when.
- **Student_Affairs_Reviewer**: A member of the Student Affairs team who reviews flagged sessions and makes Release/Confirm_Withhold decisions.
- **Name_Mismatch**: A discrepancy between the name on a student's photo ID and the student's Moodle profile name.
- **Override_Exemption**: A Student_Affairs-granted exemption from webcam and/or ID requirements (e.g., under-13, no ID, no webcam).
- **Communication_Log**: An audit record of student-facing communications (what was sent, when, and why).

---

## Requirements

The requirements below are all engineering-actionable and grouped by the reviewer's priority tiers (P0 through P4). Policy and leadership items are recorded separately in the "Assumptions and Dependencies" section.

**P0 — Urgent / Correctness**

### Requirement 1: Risk-Ceiling Gate on Auto-Release

**User Story:** As a Student_Affairs_Reviewer, I want high-risk holds to be exempt from automatic release, so that suspicious attempts are cleared only by human review and not by the expiry timer.

#### Acceptance Criteria

1. THE Proctoring_System SHALL provide a configurable Risk_Ceiling setting expressed as a Risk_Score value.
2. WHEN the Auto_Release task evaluates an expired active Risk_Hold whose Risk_Score is below the Risk_Ceiling, THE Proctoring_System SHALL release the Risk_Hold.
3. IF an expired active Risk_Hold has a Risk_Score at or above the Risk_Ceiling, THEN THE Proctoring_System SHALL retain the Risk_Hold and leave it available for human review.
4. WHERE the Risk_Ceiling is configured to a value that permits all scores, THE Proctoring_System SHALL preserve the prior behavior of releasing every expired active Risk_Hold.
5. THE Proctoring_System SHALL record, for each Risk_Hold retained by the Risk_Ceiling, the Risk_Score and the reason the hold was not auto-released.

### Requirement 2: Certificate Label and State Consistency

**User Story:** As a Student_Affairs_Reviewer, I want the report's certificate label to match the actual certificate state, so that I can trust the report when adjudicating.

#### Acceptance Criteria

1. THE Proctoring_System SHALL display a certificate label that reflects the actual issued/withheld state of the certificate for the attempt.
2. IF a certificate has been issued for an attempt, THEN THE Proctoring_System SHALL NOT display a label indicating the certificate is withheld.
3. WHEN the certificate state changes for an attempt, THE Proctoring_System SHALL update the displayed certificate label to match the new state.

### Requirement 3: Automatic AI Image Review at Submission

**User Story:** As a site administrator, I want to choose whether AI image review runs for every proctored attempt or only for attempts at or above the AI-review trigger threshold, so that automatic review at submission matches our review capacity while still keeping the displayed Risk_Score current before a reviewer sees it.

#### Acceptance Criteria

1. THE Proctoring_System SHALL provide a configurable AI_Review_Trigger_Mode setting with at least the two modes "every attempt" and "threshold".
2. WHERE AI_Image_Review is configured and enabled AND the AI_Review_Trigger_Mode is "every attempt", WHEN a proctored attempt is submitted, THE Proctoring_System SHALL enqueue AI_Image_Review for that attempt regardless of the Risk_Score.
3. WHERE AI_Image_Review is configured and enabled AND the AI_Review_Trigger_Mode is "threshold", WHEN a proctored attempt is submitted whose Risk_Score is at or above the AI-review trigger threshold, THE Proctoring_System SHALL enqueue AI_Image_Review for that attempt.
4. WHERE the AI_Review_Trigger_Mode is "threshold", IF a submitted attempt's Risk_Score is below the AI-review trigger threshold, THEN THE Proctoring_System SHALL leave AI_Image_Review not enqueued for that attempt.
5. THE Proctoring_System SHALL incorporate a completed AI_Image_Review result into the Risk_Score presented to a Student_Affairs_Reviewer without requiring a manual action.
6. WHILE AI_Image_Review for an attempt is enqueued or in progress, THE Proctoring_System SHALL display the AI_Image_Review status as pending rather than "Not queued".
7. WHEN AI_Image_Review completes for an attempt, THE Proctoring_System SHALL display the resulting Risk_Score as the current score without requiring a manual "Analyze images" action.
8. THE Proctoring_System SHALL keep the manual "Analyze images" action available as a re-run affordance regardless of the AI_Review_Trigger_Mode.

### Requirement 4: Distinguish Tool Failure From Student Risk

**User Story:** As a Student_Affairs_Reviewer, I want AI tool failures shown separately from student violations, so that a service error does not look like misconduct.

#### Acceptance Criteria

1. WHEN AI_Image_Review ends in a Tool_Failure, THE Proctoring_System SHALL display the outcome as a tool failure distinct from student-attributable risk.
2. IF the only adverse signal for an attempt is a Tool_Failure, THEN THE Proctoring_System SHALL NOT withhold the certificate on that basis.
3. THE Proctoring_System SHALL record Tool_Failure events separately from student risk signals in the session record.

### Requirement 5: Certificate Date Reflects Exam Completion

**User Story:** As a student, I want my certificate to show the exam completion date, so that a certificate issued after a hold reflects when I actually completed the exam.

#### Acceptance Criteria

1. WHEN a certificate is issued after a Risk_Hold is released, THE Proctoring_System SHALL set the certificate date to the exam completion date rather than the generation date.
2. THE Proctoring_System SHALL use the exam completion date as the certificate date regardless of the delay between completion and issuance.

### Requirement 6: Camera Lifecycle Scoped to Pre-Check

**User Story:** As a student, I want the camera to activate only during the pre-check, so that my camera is not on before I consent and not left on after I leave.

#### Acceptance Criteria

1. WHEN a student opens the exam activity, THE Proctoring_System SHALL NOT activate the camera before the Pre_Check modal is opened.
2. WHEN the Pre_Check modal is opened, THE Proctoring_System SHALL initialize the camera within the Pre_Check modal.
3. WHEN a student aborts, exits, or navigates away from the Pre_Check modal, THE Proctoring_System SHALL tear down the camera and release the camera device.

**P1 — Operability for the Student Affairs Team**

### Requirement 7: Inline Decision Controls in the Report

**User Story:** As a Student_Affairs_Reviewer, I want release, confirm-withhold, and note controls inline in the proctoring report, so that I can adjudicate without leaving the report.

#### Acceptance Criteria

1. THE Proctoring_System SHALL present inline controls in the proctoring report to Release a Risk_Hold and to Confirm_Withhold a Risk_Hold.
2. WHEN a Student_Affairs_Reviewer selects Release for a Risk_Hold, THE Proctoring_System SHALL release the Risk_Hold and restore the grade.
3. WHEN a Student_Affairs_Reviewer selects Confirm_Withhold for a Risk_Hold, THE Proctoring_System SHALL confirm the violation and keep the grade held.
4. THE Proctoring_System SHALL allow a Student_Affairs_Reviewer to add a free-text note to a Risk_Hold.
5. WHEN a Student_Affairs_Reviewer records a decision on a Risk_Hold, THE Proctoring_System SHALL store and display a reviewer stamp identifying the reviewer and the decision date.

### Requirement 8: Durable, Queryable Certificate Status

**User Story:** As a Student_Affairs_Reviewer, I want certificate status stored durably and queryably, so that a dashboard can report held, released, and withheld certificates.

#### Acceptance Criteria

1. THE Proctoring_System SHALL persist Certificate_Status for each attempt as one of held, released, or withheld.
2. THE Proctoring_System SHALL persist, with each Certificate_Status, the acting reviewer identity and the decision timestamp.
3. WHEN a Certificate_Status changes, THE Proctoring_System SHALL update the persisted record so it reflects the current state.
4. THE Proctoring_System SHALL expose Certificate_Status in a form that can be queried across attempts.

### Requirement 9: Override / Exemption Path for Webcam and ID

**User Story:** As a Student_Affairs_Reviewer, I want to grant webcam and ID exemptions, so that students who cannot meet those requirements (under-13, no ID, no webcam) are handled equitably.

#### Acceptance Criteria

1. THE Proctoring_System SHALL allow a Student_Affairs_Reviewer to grant an Override_Exemption from the webcam requirement, the ID requirement, or both, for a specified student.
2. WHERE an Override_Exemption from the webcam requirement is in effect, THE Proctoring_System SHALL allow the student to complete the attempt without a webcam capture.
3. WHERE an Override_Exemption from the ID requirement is in effect, THE Proctoring_System SHALL allow the student to complete the attempt without an ID capture.
4. THE Proctoring_System SHALL record the Override_Exemption, its scope, the granting reviewer, and the timestamp.

### Requirement 10: Name-Mismatch Cure Workflow

**User Story:** As a Student_Affairs_Reviewer, I want to clear a name mismatch after reviewing the ID, so that legitimate students are cleared without forcing a Moodle profile rename.

#### Acceptance Criteria

1. WHEN the name on a student's ID differs from the student's Moodle profile name, THE Proctoring_System SHALL flag a Name_Mismatch for review.
2. THE Proctoring_System SHALL allow a Student_Affairs_Reviewer to clear a Name_Mismatch without changing the student's Moodle profile name.
3. WHERE an ID contains non-Latin characters and the Moodle profile name uses Latin characters, THE Proctoring_System SHALL present both values for the reviewer to compare and allow the reviewer to clear the Name_Mismatch.
4. WHEN a Student_Affairs_Reviewer clears a Name_Mismatch, THE Proctoring_System SHALL record the clearing reviewer and the timestamp.

### Requirement 11: Student Communication Log

**User Story:** As a Student_Affairs_Reviewer, I want a log of student communications, so that we have an audit trail for compliance needs.

#### Acceptance Criteria

1. WHEN the Proctoring_System sends a communication to a student, THE Proctoring_System SHALL record the communication content, the timestamp, and the reason in the Communication_Log.
2. THE Proctoring_System SHALL make the Communication_Log entries for a student available for review.

### Requirement 12: Notification Threshold Gating

**User Story:** As a Student_Affairs_Reviewer, I want notifications gated by risk, so that we are not emailed on every flag.

#### Acceptance Criteria

1. THE Proctoring_System SHALL provide a configurable Risk_Score notification threshold.
2. IF a flagged session's Risk_Score is below the notification threshold, THEN THE Proctoring_System SHALL NOT send a Student_Affairs notification for that session.
3. WHEN a flagged session's Risk_Score is at or above the notification threshold, THE Proctoring_System SHALL send a Student_Affairs notification to the configured destination address.
4. THE Proctoring_System SHALL send Student_Affairs notifications to the configured destination address consistently for all qualifying sessions.

**P2 — Report UX and Review Quality**

### Requirement 13: Sortable and Filterable Report

**User Story:** As a Student_Affairs_Reviewer, I want to sort and filter the report, so that I can find sessions efficiently.

#### Acceptance Criteria

1. WHEN the proctoring report is first displayed, THE Proctoring_System SHALL order sessions from newest to oldest by default.
2. THE Proctoring_System SHALL allow a Student_Affairs_Reviewer to sort the report by column.
3. THE Proctoring_System SHALL allow a Student_Affairs_Reviewer to filter the report by first-name and last-name initial letter.

### Requirement 14: Inline Attempt-Review Integration

**User Story:** As a Student_Affairs_Reviewer, I want the proctoring report shown alongside the quiz attempt-review page, so that I can see exam and proctoring data together.

#### Acceptance Criteria

1. WHEN a Student_Affairs_Reviewer views the quiz attempt-review page for a proctored attempt, THE Proctoring_System SHALL display the proctoring report for that attempt inline on the same page.
2. THE Proctoring_System SHALL present the exam attempt data and the proctoring data together within the attempt-review context.

### Requirement 15: Plain-Language Session Summary

**User Story:** As a Student_Affairs_Reviewer, I want a short plain-language summary per session, so that I can state why a certificate was withheld without reading raw telemetry.

#### Acceptance Criteria

1. THE Proctoring_System SHALL generate a plain-language summary of one to three sentences for each reviewed session.
2. THE Proctoring_System SHALL hide raw telemetry values (for example, "viewportheight 715") from the default session view.
3. WHERE a session contains a high volume of flags, THE Proctoring_System SHALL collapse the repeated flags into a summarized form by default.

### Requirement 16: Documented and Reconciled Risk-Scoring Model

**User Story:** As a Student_Affairs_Reviewer, I want the risk-scoring model documented and reconciled, so that scores match observed behavior.

#### Acceptance Criteria

1. THE Proctoring_System SHALL provide documentation describing how the Risk_Score is computed from its contributing signals.
2. THE Proctoring_System SHALL reconcile the Risk_Score computation so that a high suspicious-event count does not produce a low Risk_Score and a maximal Risk_Score is not produced when AI_Image_Review finds nothing.

### Requirement 17: Cross-Course Held-Certificate Dashboard

**User Story:** As a Student_Affairs_Reviewer, I want a master dashboard across all courses, so that I can see held certificates in one place.

#### Acceptance Criteria

1. THE Proctoring_System SHALL provide a dashboard that aggregates held certificates across all courses.
2. WHEN a certificate is held or its Certificate_Status changes in any course, THE Proctoring_System SHALL reflect the current state in the cross-course dashboard.

### Requirement 18: Small Report Fixes

**User Story:** As a Student_Affairs_Reviewer, I want the report's small display issues fixed, so that the report is consistent and actionable.

#### Acceptance Criteria

1. THE Proctoring_System SHALL display date and time values in the proctoring report in the same format used by the results report.
2. THE Proctoring_System SHALL display the Identity Mismatch value as Yes or No.
3. THE Proctoring_System SHALL de-emphasize the Delete action and emphasize the View images action in the report.
4. THE Proctoring_System SHALL remove the non-actionable "This can include..." line from the report.

**P3 — In-Exam Posture and Detection**

> The posture decision (monitor-and-flag vs. lockdown browser) is a leadership decision recorded in Assumptions and Dependencies. It gates Requirements 19–21. Requirement 22 (stating known limits) is independent and can proceed regardless of that decision.

### Requirement 19: Persistent Out-of-Focus Banner

**User Story:** As a student, I want the out-of-focus warning to stay visible when I scroll, so that I always know when the exam has lost focus.

#### Acceptance Criteria

1. WHILE the exam window is out of focus, THE Proctoring_System SHALL display an out-of-focus banner.
2. WHILE the out-of-focus banner is displayed, THE Proctoring_System SHALL keep the banner visible when the student scrolls the page.

### Requirement 20: Reduced Exam Navigation Distraction

**User Story:** As a student, I want a cleaner attempt experience, so that I am not distracted or forced to redo onboarding after an accidental exit.

#### Acceptance Criteria

1. WHILE a proctored attempt is in progress, THE Proctoring_System SHALL hide the course sidebar.
2. WHEN a student re-enters an in-progress attempt after an accidental exit, THE Proctoring_System SHALL resume the attempt without requiring the student to repeat the full onboarding flow.

### Requirement 21: Strengthened Focus-Loss Capture

**User Story:** As a Student_Affairs_Reviewer, I want focus-loss events captured reliably, so that tab switches are not missed.

#### Acceptance Criteria

1. WHEN the exam window loses focus during a proctored attempt, THE Proctoring_System SHALL record a focus-loss event.
2. WHEN a student switches to another browser tab during a proctored attempt, THE Proctoring_System SHALL record the tab switch as a focus-loss event.

### Requirement 22: Openly Stated Detection Limits

**User Story:** As a Student_Affairs_Reviewer, I want the tool's detection limits stated openly, so that expectations are accurate.

#### Acceptance Criteria

1. THE Proctoring_System SHALL state that browsers cannot block operating-system screenshots.
2. THE Proctoring_System SHALL state that cellphone and second-device detection is not provided.

**P4 — Content, Copy, and Tutorial**

> Requirements 23–27 depend on Operations-authored copy being supplied before Engineering wires it. These requirements cover the wiring and placement of that copy, not authorship of the wording.

### Requirement 23: Proctoring Notices and Help Center Links

**User Story:** As a student, I want proctoring notices and help links on the activity and launch pages, so that I understand what to expect and where to get help.

#### Acceptance Criteria

1. THE Proctoring_System SHALL display a proctoring notice on the exam activity page.
2. THE Proctoring_System SHALL display a proctoring notice on the exam launch page.
3. THE Proctoring_System SHALL display a Help Center link on the exam activity page and on the exam launch page.

### Requirement 24: Honesty Statement Wiring

**User Story:** As a student, I want the honesty statement to link the Handbook and state unauthorized-AI rules, so that integrity expectations are clear.

#### Acceptance Criteria

1. THE Proctoring_System SHALL display the Honesty_Statement using Operations-authored wording.
2. THE Proctoring_System SHALL include a link to the Student Handbook within the Honesty_Statement.
3. THE Proctoring_System SHALL include the explicit unauthorized-AI-use language within the Honesty_Statement.

### Requirement 25: Photo ID Wording and Help Link

**User Story:** As a student, I want clear photo ID wording and a requirements link, so that I know what ID is acceptable.

#### Acceptance Criteria

1. THE Proctoring_System SHALL use the "Photo ID" wording in the ID capture step.
2. THE Proctoring_System SHALL display a link to the ID-requirements Help Center article in the ID capture step.

### Requirement 26: Tutorial Fixes

**User Story:** As a student, I want the tutorial corrected and clearer, so that onboarding is accurate and usable.

#### Acceptance Criteria

1. THE Proctoring_System SHALL display the tutorial video and captions at an enlarged size.
2. THE Proctoring_System SHALL display a statement describing mobile-device support in the tutorial.
3. THE Proctoring_System SHALL replace all "Moodle" references in student-facing tutorial text with "course", "system", or "platform".
4. THE Proctoring_System SHALL apply the bold box and gray-line formatting in the tutorial.
5. WHEN the tutorial renders a section previously affected by the white-section defect, THE Proctoring_System SHALL render that section correctly.
6. THE Proctoring_System SHALL display a support-contact reference on the Security Check step.

### Requirement 27: Screen-Share and Pre-Check Navigation

**User Story:** As a student, I want screen-share and pre-check navigation to be recoverable, so that I can complete setup without getting stuck.

#### Acceptance Criteria

1. WHILE the operating-system screen-share popup is displayed, THE Proctoring_System SHALL keep the screen-share instructions visible.
2. WHEN a required Chrome restart completes during the screen-share flow, THE Proctoring_System SHALL deep-link the student back to the attempt.
3. THE Proctoring_System SHALL allow a student to navigate backward through the Pre_Check steps to unwind a prior consent or selection.

---

## Assumptions and Dependencies

The following items from the reviewer feedback are policy, leadership, or strategy decisions. They are **not** engineering acceptance criteria. They are recorded here because they constrain or gate the engineering-actionable requirements above.

### Leadership and Policy Decisions

- **P3#1 — Posture decision (gating):** Whether exams use monitor-and-flag or a lockdown browser is a leadership decision. It gates Requirements 19, 20, and 21. Those requirements assume monitor-and-flag; a lockdown decision would change their scope.
- **P5 — Open vs. closed book** and the associated Student Handbook update. Ops/leadership owns this; it may influence Honesty_Statement content (Requirement 24).
- **P5 — 7-day policy:** Leadership sets the Risk_Ceiling value that feeds Requirement 1, along with Student Affairs review capacity/SLA and any rate-limiting or duplicate-account detection expectations.
- **P5 — Institutional testing philosophy** spanning certificate, ACE, degree, and admissions programs. Informs the equity handling in Requirement 9.
- **P5 — Support/staffing model:** No SmarterProctoring-style live chat is in scope here; support-contact wiring (Requirement 26.6) assumes an Ops-provided contact.
- **P5 — Translated / RTL browser UI testing:** A testing-scope decision owned by leadership/QA.
- **P5 — "Taview" vs. "Talview" brand naming:** A low-priority brand decision, not an engineering requirement.
- **P2#7 — ID-image retention:** Whether and how long ID images are retained is a privacy/retention policy decision. Student Affairs needs ID images to adjudicate mismatches (Requirement 10); the retention period must be set before finalizing storage behavior.

### Content Authorship Dependencies

- Requirements 23–27 depend on **Operations-authored copy** (notices, Honesty_Statement wording, Photo ID wording, tutorial text, Help Center article URLs) being supplied before Engineering can wire them.

### Verified Current-Code Notes

- Auto_Release (`quizaccess_proctoring_auto_release_expired_risk_holds()` in `lib.php`, run by `release_expired_risk_holds_task`) currently releases all expired active holds with no Risk_Ceiling; Requirement 1 introduces the ceiling using the existing `riskscore` column on the hold row.
- `quizaccess_proctoring_confirm_risk_hold($holdid, $reviewerid)` already exists as the Confirm_Withhold backend; Requirement 7 adds the inline UI, notes, and reviewer stamp.
- Hold-decision notifications already exist (`quizaccess_proctoring_should_notify_hold_decision` / `quizaccess_proctoring_notify_hold_decision`); Requirement 12 adds threshold gating on top.

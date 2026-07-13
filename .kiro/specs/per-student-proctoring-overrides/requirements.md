# Requirements Document

## Introduction

This feature adds a **per-student proctoring override** layer to the `quizaccess_proctoring` Moodle plugin. Today, proctoring requirements (CAPTCHA/security check, webcam/face, ID verification, screen-share, multi-monitor) are resolved from a site default and then a per-quiz setting using an inherit/enabled/disabled tri-state. There is no way to tailor the proctoring experience for a single test-taker. When a student cannot satisfy a requirement — for example, a student blocked by the Cloudflare Turnstile CAPTCHA, or a student who cannot present a photo ID or use a webcam because of a disability or other accommodation — the only levers available are per-quiz or site-wide, which affect every test-taker.

This feature introduces an ad-hoc, single-test-taker tailored proctoring session. A capability-gated Student Affairs reviewer can grant an override that waives one or more individual proctoring requirements for a specified student, optionally scoped to a specific quiz. The override participates in requirement resolution as a new, highest-precedence layer (site default → per-quiz setting → per-student override), so the per-student decision wins without changing the onboarding flow for any other test-taker. Every override is recorded with the granting reviewer, timestamp, scope, affected requirements, and a justification, because accommodations are compliance-sensitive. Overrides can be edited, revoked, and optionally time-bound, and they are designed to sit parallel to Moodle core's native per-user/per-group quiz overrides.

This feature **generalizes and supersedes** Requirement 9 ("Override / Exemption Path for Webcam and ID") of the `proctoring-feedback-improvements` spec, extending the webcam/ID exemption concept into a general per-requirement override layer. That overlap is recorded in Assumptions and Dependencies.

This document defines *what* the system must do. Implementation choices (schema, UI placement, resolution plumbing) are deferred to the design phase. Policy and leadership decisions (who is authorized, justification retention, interaction with institutional accommodation policy) are recorded in Assumptions and Dependencies rather than as acceptance criteria.

## Glossary

- **Proctoring_System**: The `quizaccess_proctoring` Moodle quiz access plugin, including its settings resolution, preflight/onboarding client, reports, and scheduled tasks.
- **Proctoring_Requirement**: An individual proctoring check that a student may be required to complete before or during an attempt. The in-scope set is: CAPTCHA (security check), Webcam (face register/validate), ID_Verification, Screen_Share, and Multi_Monitor check.
- **Proctoring_Override**: A per-student record that changes the effective state of one or more Proctoring_Requirements for a single specified student, optionally scoped to a specific quiz (or group). The highest-precedence layer in requirement resolution.
- **Override_State**: The tri-state value (inherit, enabled, disabled) that a Proctoring_Override may assign to an individual Proctoring_Requirement, consistent with the existing per-quiz tri-state semantics.
- **Resolution_Order**: The ordered evaluation that determines the effective state of a Proctoring_Requirement for a given student and quiz: site default, then per-quiz setting, then Proctoring_Override, where a non-inherit Proctoring_Override value takes precedence.
- **Effective_Requirement_State**: The final enabled/disabled outcome for a Proctoring_Requirement for a specific student and attempt after Resolution_Order is applied.
- **Student_Affairs_Reviewer**: A user holding the `quizaccess/proctoring:reviewriskholds` (or an equivalent designated) capability who is authorized to review proctoring sessions and grant, edit, or revoke Proctoring_Overrides.
- **Override_Scope**: The applicability of a Proctoring_Override: the target student plus an optional target quiz (and, where supported, an optional target group), which determines which attempts the override affects.
- **Justification**: The free-text reason/accommodation basis recorded with a Proctoring_Override to support compliance and audit.
- **Override_Audit_Record**: The persisted history of create/edit/revoke actions on a Proctoring_Override, including the acting reviewer identity and timestamp.
- **Expiry**: An optional time bound after which a Proctoring_Override no longer applies.
- **Pre_Check**: The pre-exam onboarding flow (`amd/src/startAttempt.js`) where privacy notice, honor statement, CAPTCHA, ID verification, face register/validate, screen sharing, and multi-monitor steps may be shown.
- **Native_Quiz_Override**: Moodle core's built-in per-user and per-group quiz override feature (backed by the `quiz_overrides` table) covering timing and attempts.

---

## Requirements

### Requirement 1: Grant a Per-Student Proctoring Override

**User Story:** As a Student_Affairs_Reviewer, I want to create a proctoring override for an individual student, so that a single test-taker can receive an accommodation without affecting any other student.

#### Acceptance Criteria

1. WHERE the acting user holds the Student_Affairs_Reviewer capability, THE Proctoring_System SHALL allow the acting user to create a Proctoring_Override for a specified student, and upon successful creation SHALL persist the Proctoring_Override and indicate that creation succeeded.
2. IF a user without the Student_Affairs_Reviewer capability attempts to create a Proctoring_Override, THEN THE Proctoring_System SHALL deny the action, SHALL NOT create the Proctoring_Override, and SHALL return an indication that the action was not permitted.
3. WHEN a Student_Affairs_Reviewer creates a Proctoring_Override, THE Proctoring_System SHALL require an Override_Scope that identifies exactly one target student who is an existing user enrolled in the course context in which the Proctoring_Override is created.
4. IF a Student_Affairs_Reviewer attempts to create a Proctoring_Override whose Override_Scope does not identify exactly one existing target student, THEN THE Proctoring_System SHALL reject the creation, SHALL NOT create the Proctoring_Override, and SHALL return an error indication that a valid target student is required.
5. WHERE a Student_Affairs_Reviewer scopes a Proctoring_Override to a specific quiz, THE Proctoring_System SHALL apply the Proctoring_Override only to attempts by the target student on that quiz.
6. WHERE a Proctoring_Override is created without a specific quiz in its Override_Scope, THE Proctoring_System SHALL apply the Proctoring_Override to all proctored quiz attempts by the target student within the course context in which the Proctoring_Override was created.

### Requirement 2: Per-Requirement Waiver Granularity

**User Story:** As a Student_Affairs_Reviewer, I want to waive individual proctoring requirements independently, so that I can relieve only the check a student cannot satisfy while keeping the others in force.

#### Acceptance Criteria

1. THE Proctoring_System SHALL allow a Proctoring_Override to assign exactly one Override_State to each of the five in-scope Proctoring_Requirements (CAPTCHA, Webcam, ID_Verification, Screen_Share, and Multi_Monitor), such that the Override_State assigned to any one Proctoring_Requirement does not change the Override_State assigned to any other Proctoring_Requirement.
2. WHERE a Proctoring_Override assigns the disabled Override_State to a Proctoring_Requirement, THE Proctoring_System SHALL treat that Proctoring_Requirement as waived for the target student within the Override_Scope.
3. WHERE a Proctoring_Override assigns the inherit Override_State to a Proctoring_Requirement, THE Proctoring_System SHALL determine that Proctoring_Requirement's state from the per-quiz setting and site default without override influence.
4. WHERE a Proctoring_Override assigns the enabled Override_State to a Proctoring_Requirement, THE Proctoring_System SHALL treat that Proctoring_Requirement as required for the target student within the Override_Scope.
5. THE Proctoring_System SHALL allow a single Proctoring_Override to assign the disabled Override_State to between two and all five of the in-scope Proctoring_Requirements for the same target student within one create or edit action.
6. WHERE a Proctoring_Override does not explicitly assign an Override_State to an in-scope Proctoring_Requirement, THE Proctoring_System SHALL treat that Proctoring_Requirement as assigned the inherit Override_State.
7. IF a request to assign an Override_State to a Proctoring_Requirement specifies a value other than inherit, enabled, or disabled, THEN THE Proctoring_System SHALL reject the assignment, SHALL leave the stored Override_State of every Proctoring_Requirement in the Proctoring_Override unchanged, and SHALL return an error indicating the Override_State value is invalid.

### Requirement 3: Requirement Resolution Precedence

**User Story:** As a Student_Affairs_Reviewer, I want per-student overrides to take precedence over per-quiz and site settings, so that a granted accommodation reliably takes effect.

#### Acceptance Criteria

1. WHEN a student begins a proctored attempt, THE Proctoring_System SHALL compute a single Effective_Requirement_State of enabled or disabled for each Proctoring_Requirement by applying the Resolution_Order of site default, then per-quiz setting, then Proctoring_Override.
2. WHERE a Proctoring_Override that is applicable to the attempt (within its Override_Scope, not revoked, and not expired) assigns a non-inherit Override_State to a Proctoring_Requirement, THE Proctoring_System SHALL set the Effective_Requirement_State for that Proctoring_Requirement to the Proctoring_Override value regardless of the per-quiz setting and site default.
3. WHERE no Proctoring_Override applicable to the attempt assigns a non-inherit Override_State to a Proctoring_Requirement, THE Proctoring_System SHALL compute that Proctoring_Requirement's Effective_Requirement_State from the per-quiz setting and site default only, producing the same outcome as when no Proctoring_Override layer is present.
4. THE Proctoring_System SHALL preserve the inherit, enabled, and disabled tri-state semantics of the existing per-quiz settings when applying the Proctoring_Override layer, such that an inherit Override_State produces no change to the Effective_Requirement_State that the site default and per-quiz setting would otherwise produce.
5. IF more than one Proctoring_Override applicable to the attempt assigns a non-inherit Override_State to the same Proctoring_Requirement, THEN THE Proctoring_System SHALL determine the Effective_Requirement_State from the Proctoring_Override with the most specific Override_Scope, and SHALL use the most recently created Proctoring_Override when two or more applicable Proctoring_Overrides share the same Override_Scope specificity.

### Requirement 4: Isolation From Other Test-Takers

**User Story:** As a student without an override, I want my proctoring experience to be unchanged, so that another student's accommodation never alters my onboarding.

#### Acceptance Criteria

1. WHEN a Proctoring_Override is created, edited, or revoked for a target student, THE Proctoring_System SHALL compute, for every student and attempt outside that Proctoring_Override's Override_Scope, the same Effective_Requirement_State for each Proctoring_Requirement that the Resolution_Order produces from the per-quiz setting and site default alone.
2. WHEN a student who has no applicable Proctoring_Override begins a proctored attempt, THE Proctoring_System SHALL present the Pre_Check steps for the CAPTCHA, Webcam, ID_Verification, Screen_Share, and Multi_Monitor Proctoring_Requirements determined solely by the per-quiz setting and site default.
3. WHERE a Proctoring_Override is scoped to a specific quiz, WHEN the target student begins a proctored attempt on any quiz outside that Override_Scope, THE Proctoring_System SHALL determine the Effective_Requirement_State for that attempt from the per-quiz setting and site default without applying the Proctoring_Override.
4. WHILE more than one student is beginning proctored attempts, THE Proctoring_System SHALL apply each Proctoring_Override only to attempts within that Proctoring_Override's own Override_Scope and SHALL NOT alter the Effective_Requirement_State of any attempt outside that Override_Scope.

### Requirement 5: Student Pre-Check Experience for Waived Requirements

**User Story:** As a student with an override, I want waived steps to be skipped cleanly, so that I can start my exam without errors or confusion.

#### Acceptance Criteria

1. WHERE a Proctoring_Requirement has an Effective_Requirement_State of disabled for a student's attempt, THE Proctoring_System SHALL omit the corresponding Pre_Check step from that student's onboarding flow without requiring any student action to skip it.
2. WHEN a Pre_Check step is omitted because its Proctoring_Requirement has an Effective_Requirement_State of disabled, THE Proctoring_System SHALL advance the student to the next Pre_Check step whose Proctoring_Requirement has an Effective_Requirement_State of enabled without displaying an error referencing the omitted step.
3. WHERE the CAPTCHA Proctoring_Requirement has an Effective_Requirement_State of disabled for a student's attempt, THE Proctoring_System SHALL omit the CAPTCHA Pre_Check step and allow the student to begin the attempt without completing the CAPTCHA security check.
4. WHILE a student has one or more Proctoring_Requirements with an Effective_Requirement_State of disabled, THE Proctoring_System SHALL still present, within the Pre_Check flow, every Proctoring_Requirement whose Effective_Requirement_State is enabled for that student's attempt.
5. WHERE all five in-scope Proctoring_Requirements have an Effective_Requirement_State of disabled for a student's attempt, THE Proctoring_System SHALL omit all corresponding Pre_Check steps and allow the student to proceed through any remaining non-overridable steps to begin the attempt.

### Requirement 6: Override Recordkeeping

**User Story:** As a Student_Affairs_Reviewer, I want each override to record who granted it and why, so that accommodations are auditable for compliance.

#### Acceptance Criteria

1. WHEN a Proctoring_Override is created, THE Proctoring_System SHALL record the granting Student_Affairs_Reviewer identity, the creation timestamp, the Override_Scope, the affected Proctoring_Requirements with their Override_State, and a Justification of 1 to 2000 characters.
2. IF a Student_Affairs_Reviewer attempts to create a Proctoring_Override with a Justification that is absent, empty, contains only whitespace, or exceeds 2000 characters, THEN THE Proctoring_System SHALL reject the creation, SHALL NOT create the Proctoring_Override, and SHALL return an error indication identifying the Justification as invalid.
3. WHEN a Student_Affairs_Reviewer requests review of a Proctoring_Override, THE Proctoring_System SHALL make available the recorded granting Student_Affairs_Reviewer identity, the creation timestamp, the Override_Scope, the affected Proctoring_Requirements with their Override_State, and the Justification.
4. WHILE a Proctoring_Override exists, THE Proctoring_System SHALL preserve the recorded granting Student_Affairs_Reviewer identity, creation timestamp, and Justification unchanged from their values at creation.

### Requirement 7: Edit and Revoke Overrides with Audit Trail

**User Story:** As a Student_Affairs_Reviewer, I want to edit or revoke an override and keep a history, so that accommodations can change while remaining fully traceable.

#### Acceptance Criteria

1. WHERE the acting user holds the Student_Affairs_Reviewer capability, THE Proctoring_System SHALL allow the acting user to edit an existing Proctoring_Override.
2. WHERE the acting user holds the Student_Affairs_Reviewer capability, THE Proctoring_System SHALL allow the acting user to revoke an existing Proctoring_Override.
3. WHEN a Proctoring_Override is revoked, THE Proctoring_System SHALL stop applying that Proctoring_Override to any attempt the target student begins after the revocation timestamp.
4. WHERE a target student has an attempt in progress at the time a Proctoring_Override is revoked, THE Proctoring_System SHALL continue to apply, for the remainder of that in-progress attempt, the Effective_Requirement_State that was resolved at the start of that attempt.
5. WHEN a Proctoring_Override is created, edited, or revoked, THE Proctoring_System SHALL append an Override_Audit_Record capturing the acting Student_Affairs_Reviewer identity, the timestamp, the action performed, and, for an edit action, each changed field with its previous value and its new value.
6. THE Proctoring_System SHALL retain every Override_Audit_Record associated with a Proctoring_Override, including after the Proctoring_Override is revoked, and SHALL NOT permit modification or deletion of an existing Override_Audit_Record.
7. IF a user without the Student_Affairs_Reviewer capability attempts to edit or revoke a Proctoring_Override, THEN THE Proctoring_System SHALL deny the action, SHALL leave the Proctoring_Override unchanged, and SHALL NOT append an Override_Audit_Record for the denied attempt.

### Requirement 8: Time-Bound Overrides

**User Story:** As a Student_Affairs_Reviewer, I want to set an expiry on an override, so that a temporary accommodation stops applying automatically.

#### Acceptance Criteria

1. WHERE a Student_Affairs_Reviewer sets an Expiry on a Proctoring_Override during creation or editing, THE Proctoring_System SHALL record the Expiry as a specific calendar date and time with the Proctoring_Override.
2. WHEN a target student begins an attempt, IF a Proctoring_Override applicable to that attempt has an Expiry whose date and time is at or before the attempt start time, THEN THE Proctoring_System SHALL NOT apply that Proctoring_Override to the attempt.
3. WHERE a Proctoring_Override has no Expiry, THE Proctoring_System SHALL apply the Proctoring_Override until it is revoked.
4. IF a Student_Affairs_Reviewer submits an Expiry whose date and time is at or before the time the Expiry is submitted, THEN THE Proctoring_System SHALL reject the Expiry, SHALL present an error indicating that the Expiry must be a future date and time, and SHALL leave the Proctoring_Override's existing Expiry unchanged.

### Requirement 9: Coordination with Native Quiz Overrides

**User Story:** As a Student_Affairs_Reviewer, I want proctoring overrides to be visible alongside Moodle's native quiz overrides, so that accommodations for a student are coordinated in one mental model.

#### Acceptance Criteria

1. WHEN a Student_Affairs_Reviewer opens the Proctoring_Override view for a quiz, THE Proctoring_System SHALL display each Proctoring_Override applicable to that quiz together with its target student, the affected Proctoring_Requirements, and each affected Proctoring_Requirement's Override_State.
2. WHERE a Native_Quiz_Override exists for the same target student and quiz as a displayed Proctoring_Override, THE Proctoring_System SHALL display an indication identifying that a Native_Quiz_Override exists for that target student and quiz alongside the displayed Proctoring_Override.
3. WHERE the acting user holds the Student_Affairs_Reviewer capability, THE Proctoring_System SHALL allow the acting user to review all Proctoring_Overrides applicable to a given quiz.
4. IF a user without the Student_Affairs_Reviewer capability attempts to review Proctoring_Overrides for a quiz, THEN THE Proctoring_System SHALL deny the action and SHALL NOT display the Proctoring_Overrides.

---

## Assumptions and Dependencies

The following items are policy, leadership, or integration decisions. They are **not** engineering acceptance criteria. They are recorded here because they constrain or gate the requirements above.

### Overlap with `proctoring-feedback-improvements`

- **Supersedes Requirement 9 (Override / Exemption Path for Webcam and ID):** The existing `proctoring-feedback-improvements` spec introduces a Student-Affairs-granted `Override_Exemption` limited to the webcam and ID requirements, recording scope, granting reviewer, and timestamp. This spec **generalizes** that concept into a per-student Proctoring_Override layer covering CAPTCHA, Webcam, ID_Verification, Screen_Share, and Multi_Monitor (and is extensible to further Pre_Check steps). The two must not be implemented as competing mechanisms. The design phase should treat this feature as the single per-student override layer and migrate or subsume the narrower webcam/ID exemption into it. The `Justification`, granting-reviewer, and timestamp recordkeeping here is intended to satisfy the recordkeeping in that earlier requirement.

### Capability and Authorization

- **Authorized grantors:** Grant/edit/revoke of Proctoring_Overrides is gated on the existing `quizaccess/proctoring:reviewriskholds` capability (or an equivalent capability designated during design), mirroring how Student Affairs review actions are already gated. Whether a dedicated capability (e.g., `quizaccess/proctoring:manageoverrides`) should be introduced instead is a design/leadership decision.
- **Institutional accommodation policy:** Who within Student Affairs is authorized to approve disability/other accommodations, and any approval workflow that must precede granting an override, is an institutional policy decision outside this spec.

### CAPTCHA / Provider Notes (Verified Current Code)

- CAPTCHA is resolved via the `captchamode` field (inherit/enabled/disabled) per quiz, on top of a site default; screen-share and risk-review similarly support per-quiz inherit/override. There is no per-student layer today — this feature adds it.
- `captchaprovider` (Cloudflare Turnstile or Moodle reCAPTCHA) is a site-wide choice, and Turnstile keys are site-wide. This feature waives the CAPTCHA requirement per student rather than switching providers or keys per student; per-student provider selection is explicitly out of scope.
- The originating incident (a student blocked by the Cloudflare Turnstile CAPTCHA with no per-student relief) is addressed by Requirement 5 (waived CAPTCHA is skipped cleanly), not by changing provider behavior.

### Scope Decisions

- **Per-group scope:** Requirement 1 supports per-student and optional per-quiz scope. Whether to additionally support per-group scope (to mirror Native_Quiz_Override group overrides) is deferred as an optional design decision; it is noted here rather than mandated as an acceptance criterion.
- **Additional Pre_Check steps:** The in-scope Proctoring_Requirement set is CAPTCHA, Webcam, ID_Verification, Screen_Share, and Multi_Monitor. Extending overrides to other Pre_Check steps (privacy notice, honor/honesty statement) is a possible future extension, not required here.

### Compliance and Retention

- **Justification retention:** How long `Justification` text and Override_Audit_Records are retained, and any privacy-review requirements for storing accommodation reasons, are compliance/retention policy decisions to be set before finalizing storage behavior.
- **Native override integration depth:** Requirement 9 mandates at least a documented, reviewable coordination with Native_Quiz_Override. Whether proctoring overrides are surfaced directly within core's quiz overrides UI versus a parallel plugin view is a design decision constrained by what Moodle core extension points allow.

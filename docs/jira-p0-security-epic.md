# Jira Epic Draft: P0 Security Hardening for Saylor Proctored Quiz

Project: `MDL`

Issue type: Epic, or Task if the MDL project does not use epics

Assignee: Requesting admin/developer account

Priority: Highest

Labels: `security`, `moodle`, `proctoring`, `p0`, `privacy`

Summary:
P0 security hardening for Saylor Proctored Quiz proctoring images and destructive actions

Description:
The Moodle `quizaccess_proctoring` plugin needs immediate P0 hardening for sensitive proctoring data. The audit found that proctoring images and destructive report actions need stronger Moodle-context authorization, POST/sesskey protection, and cross-course deletion controls.

Scope:
- Lock down `quizaccess_proctoring_pluginfile()` so webcam images, face images, user reference images, and desktop screenshots are only served to authorized users.
- Preserve internal server-side AI/face-match image reads through Moodle File API instead of unauthenticated pluginfile HTTP reads.
- Convert report deletion to POST-only with sesskey validation.
- Validate report ownership before deleting logs, screenshots, AI review rows, face images, and warning rows.
- Convert bulk delete to POST-only with sesskey validation.
- Prevent bulk delete from authorizing one course module and deleting records from another course or quiz.
- Add P0 regression coverage for module-scoped file records, internal pluginfile byte reads, and owner/report-capability access decisions.

Acceptance criteria:
- Anonymous users cannot fetch proctoring pluginfile images.
- A student cannot fetch another student's proctoring images.
- A teacher can fetch proctoring images only when they have `quizaccess/proctoring:viewreport` in a relevant module context.
- Internal AI/face-match jobs can still load stored plugin images without using unauthenticated public HTTP access.
- Report delete fails for GET requests.
- Report delete fails without a valid sesskey.
- Report delete fails when `reportid`, `studentid`, `courseid`, or `cmid` do not match the same record.
- Bulk delete fails for GET requests.
- Bulk delete fails without a valid sesskey.
- Bulk delete cannot delete logs outside the authorized course/module context.
- PHP lint passes for all plugin PHP files.

Estimate:
- Pluginfile access control and internal File API read support: 5 hours
- Report delete POST/sesskey and ownership validation: 3 hours
- Bulk delete POST/sesskey and exact target authorization: 3 hours
- Regression tests and manual verification: 4 hours
- Code review/deployment follow-up: 2 hours

Total estimate: 17 hours

Suggested schedule:
- Day 1: 8 hours
- Day 2: 8 hours
- Day 3: 1 hour review/deployment follow-up

Implementation notes:
- Touched files in the current local fix:
  - `lib.php`
  - `report.php`
  - `bulkdelete.php`
  - `proctoringsummary.php`
  - `templates/proctoring_summary.mustache`
  - `tests/security_test.php`

Testing notes:
- PHP lint was run locally.
- Full PHPUnit execution requires a configured Moodle root and PHPUnit test environment.

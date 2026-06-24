# Changelog
All notable changes to this project will be documented in this file.

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

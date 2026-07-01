<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Example test proving a provider error is recorded as a tool failure, not student risk.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Example test for provider-error handling in the AI review execution path.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_process_ai_review
 */
final class ai_review_provider_error_test extends advanced_testcase {

    /** @var int Synthetic course id used for the generated attempt. */
    private const COURSEID = 515151;

    /** @var int Synthetic quiz course-module id used for the generated attempt. */
    private const CMID = 626262;

    /** @var int Synthetic student id used for the generated attempt. */
    private const USERID = 737373;

    /**
     * A 1x1 PNG encoded as a data URL, used as a webcam capture so image collection succeeds and
     * the review reaches the provider call without any network access.
     *
     * @var string
     */
    private const PNG_DATA_URL = 'data:image/png;base64,'
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * Requirement 4.3 (EXAMPLE): a simulated provider error yields a FAILED AI review row with a
     * non-empty error message, and no student-risk event is written to
     * quizaccess_proctoring_events as a side effect of the failure.
     *
     * The test sets up a QUEUED attempt-scoped AI review backed by a webcam capture (a data URL so
     * image collection succeeds offline), then processes it through the real
     * quizaccess_proctoring_process_ai_review() function with a provider that cannot be dispatched.
     * The provider dispatch throws, the failure is caught, and the review is recorded as a tool
     * failure (status FAILED with an error message) on the AI review row alone. Because tool
     * failures live on the AI review row and never in the student-risk event stream, the events
     * table remains empty.
     *
     * Validates: Requirements 4.3
     */
    public function test_provider_error_records_tool_failure_without_student_risk_event(): void {
        global $DB;

        $this->resetAfterTest(true);

        $scope = [
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
        ];

        // A proctoring log row carrying a webcam capture so the review has an image to send. The
        // report-id lookup path is used (attemptid = 0), so the log id is the review's reportid.
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)($scope + [
            'webcampicture' => self::PNG_DATA_URL,
            'status' => 0,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ]), true);

        // No student events exist before processing: this is the baseline we assert stays empty.
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_events', $scope),
            'the events table should start empty for the synthetic attempt');

        // A QUEUED attempt-scoped AI review awaiting processing.
        $now = time();
        $reviewid = (int)$DB->insert_record('quizaccess_proctoring_ai_reviews', (object)($scope + [
            'attemptid' => 0,
            'reportid' => $reportid,
            'eventid' => 0,
            'reviewtype' => 'attempt',
            'holdid' => 0,
            'riskscore' => 0,
            'triggerthreshold' => 0,
            'provider' => 'failing-provider',
            'model' => 'test-model',
            'reviewscore' => 0,
            'decision' => 'pending',
            'status' => QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
            'summary' => null,
            'evidence' => null,
            'rawresponse' => null,
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => 0,
        ]), true);

        // Build settings with an image source enabled but a provider that cannot be dispatched, so
        // the provider call throws and the failure flows through the real error-handling branch.
        $settings = quizaccess_proctoring_get_ai_review_settings();
        $settings['provider'] = 'failing-provider';

        $review = $DB->get_record('quizaccess_proctoring_ai_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Process the queued review; the simulated provider error must be caught internally.
        quizaccess_proctoring_process_ai_review($review, $settings);

        // The review row ends as a tool failure with a recorded, non-empty error message.
        $processed = $DB->get_record('quizaccess_proctoring_ai_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $this->assertSame(QUIZACCESS_PROCTORING_AI_REVIEW_FAILED, (int)$processed->status,
            'a provider error must leave the AI review row in the FAILED state');
        $this->assertNotSame('', trim((string)$processed->errormessage),
            'a provider error must record a non-empty error message on the AI review row');

        // The failure is presented as a tool failure, never as a student-risk signal.
        $formatted = quizaccess_proctoring_format_ai_review_for_template($processed);
        $this->assertTrue($formatted['isfailed'],
            'a provider error must be flagged as a tool failure');
        $this->assertFalse($formatted['isflagged'],
            'a provider error must not be flagged as student-attributable risk');

        // No student-risk event was written to the events table as a result of the failure.
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_events', $scope),
            'a provider error must not create any student-risk event');
    }
}

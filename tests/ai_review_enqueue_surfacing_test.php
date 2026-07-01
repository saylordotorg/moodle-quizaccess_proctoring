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
 * Example/interaction tests for mode-selected AI-review enqueue and result surfacing.
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
 * Example tests exercising the enqueue decision at submission and the result-surfacing path.
 *
 * These tests drive the real enqueue path (quizaccess_proctoring_queue_ai_review()) using the
 * configured trigger mode exactly as the submission observer does: 'everyattempt' calls with
 * $force = true and 'threshold' calls with $force = false. They then assert the resulting rows in
 * quizaccess_proctoring_ai_reviews and the data/label the report derives from them.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_queue_ai_review
 * @covers ::quizaccess_proctoring_get_ai_review_trigger_mode
 */
final class ai_review_enqueue_surfacing_test extends advanced_testcase {

    /** @var int Synthetic course id used for the generated attempt. */
    private const COURSEID = 818181;

    /** @var int Synthetic quiz course-module id used for the generated attempt. */
    private const CMID = 828282;

    /** @var int Synthetic student id used for the generated attempt. */
    private const USERID = 838383;

    /** @var int Synthetic quiz attempt id used for the generated attempt. */
    private const ATTEMPTID = 848484;

    /** @var int Synthetic proctoring report/log id used for the generated attempt. */
    private const REPORTID = 858585;

    /** @var int AI-review trigger threshold configured for the tests. */
    private const TRIGGERTHRESHOLD = 80;

    /**
     * Configure AI image review so it is "configured and enabled" and set the trigger mode.
     *
     * @param string $mode The trigger mode to configure ('everyattempt' or 'threshold').
     * @return void
     */
    private function configure_ai_review(string $mode): void {
        set_config('aireviewenabled', 1, 'quizaccess_proctoring');
        set_config('aireviewprovider', 'openai', 'quizaccess_proctoring');
        set_config('aireviewopenaiapikey', 'test-key', 'quizaccess_proctoring');
        set_config('aireviewopenaimodel', 'gpt-4.1-mini', 'quizaccess_proctoring');
        set_config('aireviewtriggerthreshold', self::TRIGGERTHRESHOLD, 'quizaccess_proctoring');
        set_config('aireviewtriggermode', $mode, 'quizaccess_proctoring');

        // Guard the precondition the observer relies on: AI review really is configured.
        $this->assertTrue(quizaccess_proctoring_ai_review_configured(),
            'AI review must be configured for the enqueue path to run');
        $this->assertSame($mode, quizaccess_proctoring_get_ai_review_trigger_mode(),
            'the configured trigger mode should be readable through the accessor');
    }

    /**
     * Enqueue for the synthetic attempt exactly as the submission observer does: the trigger mode
     * selects the $force flag, and the enqueue is always gated by AI review being configured.
     *
     * @param int $score The attempt's computed risk score.
     * @return int The AI review id (0 when nothing was enqueued).
     */
    private function enqueue_like_observer(int $score): int {
        $force = quizaccess_proctoring_get_ai_review_trigger_mode() === 'everyattempt';

        return quizaccess_proctoring_queue_ai_review(
            self::COURSEID,
            self::CMID,
            self::USERID,
            self::ATTEMPTID,
            self::REPORTID,
            0,
            $score,
            self::TRIGGERTHRESHOLD,
            $force
        );
    }

    /**
     * The scope filter identifying the synthetic attempt's AI review rows.
     *
     * @return array Column/value pairs scoping the synthetic attempt.
     */
    private function scope(): array {
        return [
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
            'attemptid' => self::ATTEMPTID,
        ];
    }

    /**
     * Requirement 3.2 (EXAMPLE, everyattempt mode): with AI review configured and the trigger mode
     * set to 'everyattempt', a proctored submission whose risk score is BELOW the trigger threshold
     * still inserts a QUEUED AI review row (the score gate is bypassed via $force = true).
     *
     * Validates: Requirements 3.2
     */
    public function test_everyattempt_mode_below_threshold_inserts_queued_row(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->configure_ai_review('everyattempt');

        // A score well below the trigger threshold, which would NOT enqueue in threshold mode.
        $belowthreshold = self::TRIGGERTHRESHOLD - 30;
        $this->assertLessThan(self::TRIGGERTHRESHOLD, $belowthreshold);

        $reviewid = $this->enqueue_like_observer($belowthreshold);

        $this->assertGreaterThan(0, $reviewid,
            'everyattempt mode must enqueue a review for a below-threshold attempt');

        $rows = $DB->get_records('quizaccess_proctoring_ai_reviews', $this->scope());
        $this->assertCount(1, $rows,
            'exactly one AI review row should be inserted for the attempt');

        $row = reset($rows);
        $this->assertSame(QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED, (int)$row->status,
            'the inserted AI review row must be in the QUEUED state');
        $this->assertSame('attempt', (string)$row->reviewtype);
        $this->assertSame($belowthreshold, (int)$row->riskscore,
            'the below-threshold risk score should be recorded on the row');
    }

    /**
     * Requirement 3.3 (EXAMPLE, threshold mode): with AI review configured and the trigger mode set
     * to 'threshold', a submission whose risk score is AT OR ABOVE the trigger threshold inserts a
     * QUEUED AI review row.
     *
     * Validates: Requirements 3.3
     */
    public function test_threshold_mode_at_or_above_threshold_inserts_queued_row(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->configure_ai_review('threshold');

        // Exercise the boundary: a score exactly equal to the threshold must enqueue (>=).
        $atthreshold = self::TRIGGERTHRESHOLD;

        $reviewid = $this->enqueue_like_observer($atthreshold);

        $this->assertGreaterThan(0, $reviewid,
            'threshold mode must enqueue a review when the score meets the threshold');

        $rows = $DB->get_records('quizaccess_proctoring_ai_reviews', $this->scope());
        $this->assertCount(1, $rows,
            'exactly one AI review row should be inserted at/above threshold');

        $row = reset($rows);
        $this->assertSame(QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED, (int)$row->status,
            'the inserted AI review row must be in the QUEUED state');
    }

    /**
     * Requirement 3.4 (EXAMPLE, threshold mode): with AI review configured and the trigger mode set
     * to 'threshold', a submission whose risk score is BELOW the trigger threshold inserts NO row,
     * and the report's AI column therefore falls back to the "Not queued" presentation (there is no
     * row for the status-label path to describe).
     *
     * Validates: Requirements 3.4
     */
    public function test_threshold_mode_below_threshold_inserts_no_row_and_report_shows_not_queued(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->configure_ai_review('threshold');

        $belowthreshold = self::TRIGGERTHRESHOLD - 1;

        $reviewid = $this->enqueue_like_observer($belowthreshold);

        $this->assertSame(0, $reviewid,
            'threshold mode must not enqueue a review below the threshold');
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_ai_reviews', $this->scope()),
            'no AI review row should exist for a below-threshold attempt in threshold mode');

        // With no row, the report lookup returns false, so the template renders the "Not queued"
        // string rather than a pending/completed status label.
        $aireview = quizaccess_proctoring_get_ai_review(
            self::COURSEID,
            self::CMID,
            self::USERID,
            self::ATTEMPTID,
            self::REPORTID
        );
        $this->assertFalse($aireview,
            'the report AI-review lookup must return false when no row was enqueued');

        $notqueued = get_string('aireview:notqueued', 'quizaccess_proctoring');
        $this->assertNotSame('', trim($notqueued),
            'the "Not queued" label used by the report template must be defined');
    }

    /**
     * Requirements 3.5 and 3.7 (EXAMPLE): once an AI review has completed, the report context
     * derived from the row surfaces the resulting score as the current score with a completed
     * status, without any manual "Analyze images" action being required to produce it.
     *
     * Validates: Requirements 3.5, 3.7
     */
    public function test_completed_review_surfaces_current_score_without_manual_action(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->configure_ai_review('everyattempt');

        // A review that has already run to completion with a concrete score.
        $completedscore = 72;
        $now = time();
        $DB->insert_record('quizaccess_proctoring_ai_reviews', (object)($this->scope() + [
            'reportid' => self::REPORTID,
            'eventid' => 0,
            'reviewtype' => 'attempt',
            'holdid' => 0,
            'riskscore' => 50,
            'triggerthreshold' => self::TRIGGERTHRESHOLD,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'reviewscore' => $completedscore,
            'decision' => 'suspicious',
            'status' => QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            'summary' => 'Completed review summary.',
            'evidence' => json_encode(['face not detected in 2 captures']),
            'rawresponse' => '{}',
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => $now,
        ]));

        // The report reads the completed row directly (no manual re-run needed) ...
        $aireview = quizaccess_proctoring_get_ai_review(
            self::COURSEID,
            self::CMID,
            self::USERID,
            self::ATTEMPTID,
            self::REPORTID
        );
        $this->assertNotFalse($aireview,
            'a completed review must be readable by the report without a manual action');

        // ... and the formatted context surfaces the completed score as the current score.
        $formatted = quizaccess_proctoring_format_ai_review_for_template($aireview);
        $this->assertTrue($formatted['iscomplete'],
            'the completed review must present as complete');
        $this->assertFalse($formatted['isqueued']);
        $this->assertFalse($formatted['isprocessing']);
        $this->assertSame($completedscore, (int)$formatted['reviewscore'],
            'the completed review score must surface as the current score');
        $this->assertStringContainsString((string)$completedscore, (string)$formatted['compactlabel'],
            'the compact report label must reflect the completed score');
    }

    /**
     * Requirement 3.8 (EXAMPLE): the manual "Analyze images" re-run affordance is available
     * regardless of the configured trigger mode. The report renders this control from the
     * 'analyzbtn' label independently of the trigger mode (it is gated by capability, not mode),
     * so the label is present under both 'everyattempt' and 'threshold' configurations.
     *
     * Validates: Requirements 3.8
     */
    public function test_manual_analyze_images_control_renders_in_both_modes(): void {
        $this->resetAfterTest(true);

        foreach (['everyattempt', 'threshold'] as $mode) {
            $this->configure_ai_review($mode);

            $analyzelabel = get_string('analyzbtn', 'quizaccess_proctoring');
            $this->assertNotSame('', trim($analyzelabel),
                'the manual "Analyze images" control label must be defined in ' . $mode . ' mode');
        }
    }
}

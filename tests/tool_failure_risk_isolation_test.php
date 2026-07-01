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
 * Property-based tests proving AI tool failures are isolated from the student risk score.
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
 * Property-based tests for risk_calculator's isolation from AI review outcomes.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_calculator::calculate_attempt
 */
final class tool_failure_risk_isolation_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 150;

    /** @var int Synthetic course id used for the generated attempt. */
    private const COURSEID = 424242;

    /** @var int Synthetic quiz course-module id used for the generated attempt. */
    private const CMID = 353535;

    /** @var int Synthetic student id used for the generated attempt. */
    private const USERID = 121212;

    /**
     * Student-attributable event types that feed the scoring factors. These are the only signals
     * that may legitimately move the risk score; the AI review outcome must never be among them.
     *
     * @var string[]
     */
    private const EVENT_TYPES = [
        'focus_lost', 'tab_hidden', 'page_exit',
        'clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu',
        'screen_marker_missing', 'screen_share_stopped',
        'multiple_monitors_detected',
        'possible_ai_tool',
        'multiple_faces_detected',
        'audio_detected',
        'face_missing', 'no_face_detected',
    ];

    /** @var int The proctoring log id (reportid) for the generated attempt. */
    private int $reportid = 0;

    /**
     * Create a clean baseline proctoring log for the synthetic attempt.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        global $DB;

        // A non-empty webcam picture keeps the "webcam missing" factor at zero, so a clean attempt
        // (no adverse student events) scores exactly zero and any non-zero score is attributable to
        // generated student events alone.
        $this->reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
            'webcampicture' => 'data:image/png;base64,AAAA',
            // status doubles as the attempt id in this schema; 0 selects the report-id lookup path
            // and avoids the optional quiz-attempts speed factor.
            'status' => 0,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ], true);

        // A known, in-range hold threshold so "below hold threshold" is well defined.
        set_config('riskreviewthreshold', 80, 'quizaccess_proctoring');
        set_config('riskreviewenabled', 1, 'quizaccess_proctoring');
    }

    /**
     * Feature: proctoring-feedback-improvements, Property 5: Tool failures are isolated from student risk
     *
     * For any set of student-attributable events and any AI review outcome (queued, processing,
     * complete, or a tool failure with any review score / error message), the risk score computed
     * by risk_calculator::calculate_attempt() is identical to the score computed with no AI review
     * row at all: the AI outcome is never a scoring input (Requirements 4.1, 16.2). Furthermore,
     * when a tool failure is the only adverse signal, the score stays at the clean baseline and
     * strictly below the hold threshold, so a failed review can never create or sustain a hold
     * (Requirements 4.2, 4.3).
     *
     * The test drives the real calculator against injected event counts plus a randomly generated
     * AI outcome over many iterations, asserting score invariance with respect to the AI outcome,
     * and separately asserting a lone tool failure is presented as a tool failure (never a
     * student-risk signal) and yields a below-threshold score.
     *
     * Validates: Requirements 4.1, 4.2, 4.3, 16.2
     */
    public function test_tool_failure_is_isolated_from_risk_score(): void {
        global $DB;

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260119);

        $threshold = (int)quizaccess_proctoring_get_effective_risk_review_settings(self::CMID)['threshold'];

        // The clean baseline (no events, no AI review) must be zero and below the hold threshold.
        $baseline = $this->score();
        $this->assertSame(0, $baseline, 'clean attempt baseline score was not zero');
        $this->assertLessThan($threshold, $baseline, 'clean baseline was not below the hold threshold');

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->reset_signals();

            // Inject a random multiset of student-attributable events.
            $counts = $this->generate_event_counts();
            $this->insert_events($counts);

            // Score with no AI review row present.
            $scorewithout = $this->score();

            // Introduce a random AI outcome, including tool failures with arbitrary scores/errors.
            $ai = $this->generate_ai_outcome();
            $aireviewid = $this->insert_ai_review($ai);

            // Score with the AI outcome present.
            $scorewith = $this->score();

            $context = 'iteration=' . $iteration
                . ' counts=' . json_encode($counts)
                . ' ai=' . json_encode($ai);

            // Core property: the AI outcome is not a scoring input, so the score is invariant.
            $this->assertSame($scorewith, $scorewithout,
                'AI review outcome changed the risk score: ' . $context);

            // A tool failure must be presented as a tool failure, never as a student-risk signal.
            if ((int)$ai['status'] === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED) {
                $row = $DB->get_record('quizaccess_proctoring_ai_reviews', ['id' => $aireviewid], '*', MUST_EXIST);
                $formatted = quizaccess_proctoring_format_ai_review_for_template($row);
                $this->assertTrue($formatted['isfailed'],
                    'failed AI review was not flagged as a tool failure: ' . $context);
                $this->assertFalse($formatted['isflagged'],
                    'failed AI review was mislabelled as student-attributable risk: ' . $context);
            }
        }

        // Lone-tool-failure sub-property: with no adverse student events, a failed AI review with an
        // arbitrary (even maximal) review score contributes nothing and stays below the threshold.
        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->reset_signals();

            $bogusscore = mt_rand(0, 100);
            $aireviewid = $this->insert_ai_review([
                'status' => QUIZACCESS_PROCTORING_AI_REVIEW_FAILED,
                'reviewscore' => $bogusscore,
                'decision' => 'highly_suspicious',
                'errormessage' => 'provider error ' . $iteration,
            ]);

            $score = $this->score();
            $context = 'iteration=' . $iteration . ' bogusscore=' . $bogusscore;

            $this->assertSame($baseline, $score,
                'a lone tool failure changed the risk score away from the clean baseline: ' . $context);
            $this->assertLessThan($threshold, $score,
                'a lone tool failure produced a hold-worthy score: ' . $context);

            $row = $DB->get_record('quizaccess_proctoring_ai_reviews', ['id' => $aireviewid], '*', MUST_EXIST);
            $formatted = quizaccess_proctoring_format_ai_review_for_template($row);
            $this->assertTrue($formatted['isfailed'],
                'lone tool failure was not flagged as a tool failure: ' . $context);
            $this->assertFalse($formatted['isflagged'],
                'lone tool failure was mislabelled as student-attributable risk: ' . $context);
        }
    }

    /**
     * Compute the risk score for the synthetic attempt.
     *
     * @return int Risk score from 0 to 100.
     */
    private function score(): int {
        $risk = quizaccess_proctoring_calculate_attempt_risk(
            self::COURSEID, self::CMID, self::USERID, $this->reportid);
        return (int)$risk['score'];
    }

    /**
     * Remove all generated student events and AI reviews for the synthetic attempt.
     */
    private function reset_signals(): void {
        global $DB;
        $scope = ['courseid' => self::COURSEID, 'quizid' => self::CMID, 'userid' => self::USERID];
        $DB->delete_records('quizaccess_proctoring_events', $scope);
        $DB->delete_records('quizaccess_proctoring_ai_reviews', $scope);
    }

    /**
     * Generate a random multiset of student-attributable event counts.
     *
     * @return array<string, int> Map of event type to count, plus 'shortcut_f12' and
     *                            'possible_ai_tool_screenshot' pseudo-keys.
     */
    private function generate_event_counts(): array {
        $counts = [];
        foreach (self::EVENT_TYPES as $type) {
            // Bias towards small counts, occasionally large enough to saturate a factor's cap.
            $counts[$type] = mt_rand(0, 5);
        }
        // A subset of possible_ai_tool events also carry a desktop screenshot (extra factor).
        $counts['possible_ai_tool_screenshot'] = mt_rand(0, min(3, $counts['possible_ai_tool']));
        // F12 shortcut events (recorded as eventtype 'shortcut').
        $counts['shortcut_f12'] = mt_rand(0, 4);
        return $counts;
    }

    /**
     * Insert student events matching the generated counts.
     *
     * @param array<string, int> $counts Generated event counts.
     */
    private function insert_events(array $counts): void {
        global $DB;

        $screenshots = (int)$counts['possible_ai_tool_screenshot'];
        foreach (self::EVENT_TYPES as $type) {
            for ($i = 0; $i < (int)$counts[$type]; $i++) {
                $withscreenshot = ($type === 'possible_ai_tool' && $i < $screenshots);
                $DB->insert_record('quizaccess_proctoring_events', (object)[
                    'courseid' => self::COURSEID,
                    'quizid' => self::CMID,
                    'userid' => self::USERID,
                    'attemptid' => 0,
                    'reportid' => $this->reportid,
                    'eventtype' => $type,
                    'eventdetail' => null,
                    'pagevisibility' => 'visible',
                    'currenturl' => null,
                    'screenshoturl' => $withscreenshot ? 'https://example.test/shot.png' : null,
                    'timemodified' => time(),
                ]);
            }
        }

        for ($i = 0; $i < (int)$counts['shortcut_f12']; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => self::COURSEID,
                'quizid' => self::CMID,
                'userid' => self::USERID,
                'attemptid' => 0,
                'reportid' => $this->reportid,
                'eventtype' => 'shortcut',
                'eventdetail' => json_encode(['shortcut' => 'F12']),
                'pagevisibility' => 'visible',
                'currenturl' => null,
                'screenshoturl' => null,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Generate a random AI review outcome across all statuses, scores and decisions.
     *
     * @return array{status:int,reviewscore:int,decision:string,errormessage:string} AI outcome.
     */
    private function generate_ai_outcome(): array {
        $statuses = [
            QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
            QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING,
            QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            QUIZACCESS_PROCTORING_AI_REVIEW_FAILED,
        ];
        $decisions = ['pending', 'not_suspicious', 'highly_suspicious'];
        $status = $statuses[mt_rand(0, count($statuses) - 1)];

        return [
            'status' => $status,
            'reviewscore' => mt_rand(0, 100),
            'decision' => $decisions[mt_rand(0, count($decisions) - 1)],
            'errormessage' => $status === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED
                ? 'simulated provider failure'
                : '',
        ];
    }

    /**
     * Insert an AI review row for the synthetic attempt.
     *
     * @param array{status:int,reviewscore:int,decision:string,errormessage:string} $ai AI outcome.
     * @return int The inserted AI review id.
     */
    private function insert_ai_review(array $ai): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('quizaccess_proctoring_ai_reviews', (object)[
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
            'attemptid' => 0,
            'reportid' => $this->reportid,
            'eventid' => 0,
            'reviewtype' => 'attempt',
            'holdid' => 0,
            'riskscore' => 0,
            'triggerthreshold' => 0,
            'provider' => 'test',
            'model' => 'test',
            'reviewscore' => (int)$ai['reviewscore'],
            'decision' => (string)$ai['decision'],
            'status' => (int)$ai['status'],
            'summary' => null,
            'evidence' => null,
            'rawresponse' => null,
            'errormessage' => (string)$ai['errormessage'],
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => 0,
        ], true);
    }
}

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
 * Tests for the AI-review-confirmed risk factor (CPIT-483).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\factor_coverage;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * A confirmed event-level AI review raises the score for a reviewer; nothing less does.
 *
 * Student Affairs ran an exam with Gemini beside it: the desktop captures showed it, the AI
 * analysis next to the captures described it, and the risk score only counted tab switching -
 * the strongest evidence was a footnote (CPIT-483). The factor scores exactly the completed
 * event reviews that concluded highly_suspicious, is review-only (holds, never auto-fails),
 * and reads as "not monitored" where event AI review is not running.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_calculator
 * @covers \quizaccess_proctoring\local\factor_coverage
 */
final class ai_review_confirmed_factor_test extends advanced_testcase {

    /**
     * Build a course, quiz, student, one attempt and its report row.
     *
     * @return array [courseid, cmid, userid, attemptid, reportid]
     */
    private function fixture(): array {
        global $DB;

        $this->resetAfterTest();
        set_config('holdcreatednotify', 0, 'quizaccess_proctoring');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 10, 'sumgrades' => 10]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        $now = time();
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => 880001,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => $now - 600,
            'timefinish' => $now - 60,
            'timemodified' => $now,
            'timemodifiedoffline' => 0,
            'timecheckstate' => null,
            'sumgrades' => 9,
        ]);
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $user->id,
            'webcampicture' => 'https://example.com/capture.png',
            'status' => $attemptid,
            'deletionprogress' => 0,
            'timemodified' => $now,
        ]);
        return [(int)$course->id, (int)$cm->id, (int)$user->id, $attemptid, $reportid];
    }

    /**
     * Make event-level AI review configured and switched on.
     */
    private function activate_event_ai_review(): void {
        set_config('aireviewenabled', 1, 'quizaccess_proctoring');
        set_config('aireviewprovider', 'openai', 'quizaccess_proctoring');
        set_config('aireviewopenaiapikey', 'test-key', 'quizaccess_proctoring');
    }

    /**
     * Store one event and one AI review of it.
     *
     * @param array $scope [courseid, cmid, userid, attemptid]
     * @param string $reviewtype 'event' or 'attempt'.
     * @param int $status AI review status constant.
     * @param string $decision Review decision.
     * @return int Event id.
     */
    private function add_review(array $scope, string $reviewtype, int $status, string $decision): int {
        global $DB;

        [$courseid, $cmid, $userid, $attemptid] = $scope;
        $now = time();
        $eventid = (int)$DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => 0,
            'eventtype' => 'focus_lost',
            'eventdetail' => '',
            'pagevisibility' => 'hidden',
            'currenturl' => '',
            'screenshoturl' => 'https://example.com/desktop.png',
            'timemodified' => $now,
        ]);
        $DB->insert_record('quizaccess_proctoring_ai_reviews', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => 0,
            'eventid' => $reviewtype === 'event' ? $eventid : 0,
            'reviewtype' => $reviewtype,
            'holdid' => 0,
            'riskscore' => 0,
            'triggerthreshold' => 80,
            'provider' => 'openai',
            'model' => 'test',
            'reviewscore' => 90,
            'decision' => $decision,
            'status' => $status,
            'summary' => 'An AI chat window is visible beside the exam.',
            'evidence' => '',
            'rawresponse' => '',
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $eventid;
    }

    /**
     * One factor from a risk result, or null when it is absent.
     *
     * @param array $risk Risk result.
     * @return array|null
     */
    private function factor(array $risk): ?array {
        foreach ($risk['factors'] as $factor) {
            if (($factor['key'] ?? '') === 'aireviewconfirmed') {
                return $factor;
            }
        }
        return null;
    }

    /**
     * A completed highly_suspicious event review scores, and only holds - never auto-fails.
     */
    public function test_confirmed_review_scores_review_only(): void {
        $this->activate_event_ai_review();
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');

        $risk = risk_calculator::calculate_attempt($courseid, $cmid, $userid, $reportid);
        $factor = $this->factor($risk);

        $this->assertNotNull($factor);
        $this->assertSame(1, (int)$factor['count']);
        $this->assertSame(20, (int)$factor['points']);
        $this->assertSame(
            $risk['score'] - 20,
            risk_calculator::score_without_review_only_factors($risk),
            'the factor must never contribute to an automatic failure'
        );
    }

    /**
     * Nothing short of a completed, affirmative event review scores.
     */
    public function test_lesser_reviews_score_nothing(): void {
        $this->activate_event_ai_review();
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'inconclusive');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'no_visual_evidence');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_FAILED, 'highly_suspicious');
        $this->add_review($scope, 'attempt', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');

        $risk = risk_calculator::calculate_attempt($courseid, $cmid, $userid, $reportid);
        $factor = $this->factor($risk);

        $this->assertNotNull($factor, 'event AI review is active, so the factor is monitored');
        $this->assertSame(0, (int)$factor['count']);
        $this->assertSame(0, (int)$factor['points']);
    }

    /**
     * With event AI review not running and no verdicts, the factor reads as not monitored.
     */
    public function test_inactive_review_omits_the_factor(): void {
        [$courseid, $cmid, $userid, , $reportid] = $this->fixture();

        $risk = risk_calculator::calculate_attempt($courseid, $cmid, $userid, $reportid);

        $this->assertNull($this->factor($risk), 'an unmonitored factor must not read as passed');
    }

    /**
     * A stored verdict keeps scoring after the feature is switched off.
     */
    public function test_existing_verdicts_outlive_the_setting(): void {
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $this->add_review(
            [$courseid, $cmid, $userid, $attemptid],
            'event',
            QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            'highly_suspicious'
        );

        $risk = risk_calculator::calculate_attempt($courseid, $cmid, $userid, $reportid);
        $factor = $this->factor($risk);

        $this->assertNotNull($factor);
        $this->assertSame(20, (int)$factor['points']);
    }

    /**
     * The bulk path counts the same verdicts the single path does.
     */
    public function test_bulk_path_matches_single_path(): void {
        $this->activate_event_ai_review();
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'inconclusive');

        $single = risk_calculator::calculate_attempt($courseid, $cmid, $userid, $reportid);
        $bulk = risk_calculator::calculate_many([
            'one' => [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'userid' => $userid,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
            ],
        ]);

        $this->assertSame(2, (int)$this->factor($single)['count']);
        $this->assertSame($single['score'], $bulk['one']['score']);
        $this->assertSame(
            (int)$this->factor($single)['count'],
            (int)$this->factor($bulk['one'])['count']
        );
    }

    /**
     * A review completing after submission creates the hold the submission check could not see.
     */
    public function test_late_review_completion_creates_the_missed_hold(): void {
        global $DB;

        $this->activate_event_ai_review();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 40, 'quizaccess_proctoring');
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');

        quizaccess_proctoring_reevaluate_hold_after_event_review((object)[
            'id' => 1,
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => 0,
            'reviewtype' => 'event',
            'decision' => 'highly_suspicious',
        ]);

        $hold = $DB->get_record('quizaccess_proctoring_risk_holds', [
            'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
        ]);
        $this->assertNotEmpty($hold, 'the late verdict must hold the attempt for a reviewer');
        $this->assertEquals(QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE, (int)$hold->status);
        $this->assertSame($reportid, (int)$hold->reportid, 'the report row is resolved when the review carries none');
    }

    /**
     * An attempt a reviewer already decided is left exactly as they left it.
     */
    public function test_late_review_respects_decided_holds(): void {
        global $DB;

        $this->activate_event_ai_review();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 40, 'quizaccess_proctoring');
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $now = time();
        $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'quizinstance' => 0,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'riskscore' => 50,
            'threshold' => 40,
            'originalgrade' => 9,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            'reviewerid' => 7,
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => $now,
        ]);

        quizaccess_proctoring_reevaluate_hold_after_event_review((object)[
            'id' => 2,
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'reviewtype' => 'event',
            'decision' => 'highly_suspicious',
        ]);

        $holds = $DB->get_records('quizaccess_proctoring_risk_holds', [
            'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
        ]);
        $this->assertCount(1, $holds, 'a decided hold must not be joined by a new one');
        $this->assertEquals(QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED, (int)reset($holds)->status);
    }

    /**
     * Below the threshold, a late verdict holds nothing.
     */
    public function test_late_review_below_threshold_holds_nothing(): void {
        global $DB;

        $this->activate_event_ai_review();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 90, 'quizaccess_proctoring');
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $this->add_review(
            [$courseid, $cmid, $userid, $attemptid],
            'event',
            QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            'highly_suspicious'
        );

        quizaccess_proctoring_reevaluate_hold_after_event_review((object)[
            'id' => 3,
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'reviewtype' => 'event',
            'decision' => 'highly_suspicious',
        ]);

        $this->assertFalse($DB->record_exists('quizaccess_proctoring_risk_holds', [
            'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
        ]));
    }

    /**
     * A review completing while the student is still answering holds nothing yet.
     */
    public function test_late_review_ignores_attempts_in_progress(): void {
        global $DB;

        $this->activate_event_ai_review();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 40, 'quizaccess_proctoring');
        [$courseid, $cmid, $userid, $attemptid, $reportid] = $this->fixture();
        $DB->set_field('quiz_attempts', 'state', 'inprogress', ['id' => $attemptid]);
        $scope = [$courseid, $cmid, $userid, $attemptid];
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');
        $this->add_review($scope, 'event', QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE, 'highly_suspicious');

        quizaccess_proctoring_reevaluate_hold_after_event_review((object)[
            'id' => 4,
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'reviewtype' => 'event',
            'decision' => 'highly_suspicious',
        ]);

        $this->assertFalse($DB->record_exists('quizaccess_proctoring_risk_holds', [
            'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
        ]), 'a result-withheld hold must never predate the result');
    }

    /**
     * Under auto-fail, the review queue reads reachable while the review-only factor is armed.
     */
    public function test_review_queue_reachability_counts_the_factor(): void {
        $this->resetAfterTest();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_AUTO_FAIL, 'quizaccess_proctoring');

        $this->assertSame('autofailonly', quizaccess_proctoring_risk_hold_reachability());

        $this->activate_event_ai_review();
        $this->assertSame('reachable', quizaccess_proctoring_risk_hold_reachability(),
            'an armed review-only factor can route attempts to the review queue');
    }

    /**
     * Coverage: armed only while event AI review is configured and on.
     */
    public function test_factor_coverage_reflects_the_setting(): void {
        $this->resetAfterTest();

        $this->assertSame(
            factor_coverage::REASON_SETTING_OFF,
            factor_coverage::reason('aireviewconfirmed', null, false, false, -1, -1, [], false)
        );
        $this->assertNull(
            factor_coverage::reason('aireviewconfirmed', null, false, false, -1, -1, [], true)
        );
    }
}

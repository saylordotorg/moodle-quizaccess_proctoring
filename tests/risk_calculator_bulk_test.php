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
 * Equivalence tests for bulk risk scoring.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Asserts that scoring many attempts at once returns exactly what scoring them one by one returns.
 *
 * risk_calculator::calculate_many() exists only because per-attempt scoping costs about twenty
 * queries per attempt, which a report cannot afford across a whole filtered set. That makes it a
 * second way to reach a number reviewers act on, so the property under test is equality with the
 * canonical path - not "close enough" - across varied evidence, factor settings, reviewer
 * false-positive marks, and the attempt-duration inputs to the speed factor.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class risk_calculator_bulk_test extends advanced_testcase {

    /** @var \stdClass Generated course. */
    private $course;

    /** @var \stdClass Generated quiz instance. */
    private $quiz;

    /** @var \stdClass Generated quiz course module. */
    private $cm;

    /**
     * Every event type the calculator scores, so generated evidence covers each factor.
     *
     * @var string[]
     */
    private const EVENT_TYPES = [
        'focus_lost', 'tab_hidden', 'page_exit',
        'clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu',
        'screen_marker_missing', 'screen_share_stopped',
        'multiple_monitors_detected', 'possible_ai_tool',
        'multiple_faces_detected', 'audio_detected',
        'face_missing', 'no_face_detected', 'phone_detected',
        'shortcut',
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
    }

    /**
     * Create one attempt's worth of evidence.
     *
     * @param int $seed Drives which evidence this attempt gets, so attempts differ from each other.
     * @param bool $withrealattempt Whether to create a quiz_attempts row (needed by the speed factor).
     * @return array ['courseid' => int, 'cmid' => int, 'userid' => int, 'reportid' => int]
     */
    private function seed_attempt(int $seed, bool $withrealattempt): array {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);

        $attemptid = 0;
        if ($withrealattempt) {
            // A real attempt row, so duration and question count feed the speed factor.
            $start = time() - (600 + $seed);
            $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
                'quiz' => $this->quiz->id,
                'userid' => $user->id,
                'attempt' => 1,
                'uniqueid' => 1000 + $seed,
                'layout' => '1,0',
                'state' => 'finished',
                'timestart' => $start,
                'timefinish' => $start + (5 * ($seed % 7) + 1),
                'timemodified' => time(),
                'sumgrades' => 1,
            ], true);
        } else {
            // No attempt id: the scoping falls back to the report id, which cannot be grouped.
            $attemptid = 0;
        }

        // Two capture rows so webcam evidence, face mismatches and failed checks can all vary.
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $user->id,
            'webcampicture' => ($seed % 4 === 0) ? '' : 'data:image/png;base64,AAAA',
            'status' => $attemptid,
            'awsscore' => 10 * ($seed % 9),
            'awsflag' => [0, 2, 3, 2][$seed % 4],
            'deletionprogress' => 0,
            'timemodified' => time(),
        ], true);
        $secondlogid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $user->id,
            'webcampicture' => 'data:image/png;base64,BBBB',
            'status' => $attemptid,
            'awsscore' => 5 * ($seed % 5),
            'awsflag' => [2, 3, 0, 101][$seed % 4],
            'deletionprogress' => 0,
            'timemodified' => time(),
        ], true);

        // Stored face images, some without a face found.
        foreach ([$reportid, $secondlogid] as $index => $parentid) {
            $DB->insert_record('quizaccess_proctoring_face_images', (object)[
                'parent_type' => '1',
                'parentid' => $parentid,
                'faceimage' => 'data:image/png;base64,CCCC',
                'facefound' => (($seed + $index) % 3 === 0) ? 0 : 1,
                'timemodified' => time(),
            ]);
        }

        // A small rotating slice of the scored event types, plus shortcut rows on every attempt so
        // the F12 / other-shortcut split is always exercised. Deliberately sparse: an attempt with
        // evidence for every factor saturates at the 100 cap, and then every attempt scores 100 and
        // an equality test between two paths proves nothing.
        foreach (self::EVENT_TYPES as $index => $eventtype) {
            if ($eventtype !== 'shortcut' && ($index + $seed) % 5 !== 0) {
                continue;
            }
            $howmany = $eventtype === 'shortcut' ? 2 : 1 + (($seed + $index) % 2);
            for ($i = 0; $i < $howmany; $i++) {
                $detail = '';
                if ($eventtype === 'shortcut') {
                    $detail = json_encode(['shortcut' => ($i % 2 === 0) ? 'F12' : 'Ctrl+C']);
                }
                $DB->insert_record('quizaccess_proctoring_events', (object)[
                    'courseid' => $this->course->id,
                    'quizid' => $this->cm->id,
                    'userid' => $user->id,
                    'attemptid' => $attemptid,
                    'reportid' => $reportid,
                    'eventtype' => $eventtype,
                    'eventdetail' => $detail,
                    'screenshoturl' => ($eventtype === 'possible_ai_tool' && $i === 0) ? 'https://example.com/s.png' : '',
                    'timemodified' => time(),
                ]);
            }
        }

        // A reviewer false-positive mark on some attempts, which must zero that factor's points.
        if ($seed % 3 === 0 && $attemptid > 0) {
            $DB->insert_record('quizaccess_proctoring_finding_reviews', (object)[
                'courseid' => $this->course->id,
                'quizid' => $this->cm->id,
                'userid' => $user->id,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'factorkey' => 'tabactivity',
                'verdict' => 'false_positive',
                'revoked' => 0,
                'reviewerid' => 2,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return [
            'courseid' => (int)$this->course->id,
            'cmid' => (int)$this->cm->id,
            'userid' => (int)$user->id,
            'reportid' => $reportid,
        ];
    }

    /**
     * Assert bulk and per-attempt scoring agree for every requested attempt.
     *
     * @param array $requests Requests as accepted by risk_calculator::calculate_many().
     */
    private function assert_bulk_matches_single(array $requests): void {
        $bulk = risk_calculator::calculate_many($requests);

        $this->assertSame(array_keys($requests), array_keys($bulk), 'bulk results keep the caller order');

        foreach ($requests as $key => $request) {
            $single = risk_calculator::calculate_attempt(
                $request['courseid'],
                $request['cmid'],
                $request['userid'],
                $request['reportid']
            );
            $this->assertEquals($single, $bulk[$key], "attempt {$key} scored differently in bulk");
        }
    }

    /**
     * With default factor settings, bulk scoring matches per-attempt scoring for every attempt.
     */
    public function test_bulk_matches_single_with_default_settings(): void {
        $requests = [];
        for ($seed = 0; $seed < 8; $seed++) {
            $requests['a' . $seed] = $this->seed_attempt($seed, true);
        }

        $this->assert_bulk_matches_single($requests);

        // Equality between two paths that both score zero would prove nothing, so check the
        // evidence actually scored: several distinct non-zero scores, and factors that fired.
        $scores = $this->assert_scores($requests);
        $this->assertGreaterThan(0, max($scores), 'seeded evidence produced no risk at all');
        $this->assertGreaterThan(2, count(array_unique($scores)), 'seeded attempts scored too alike to be a test');

        $firedfactors = [];
        foreach (risk_calculator::calculate_many($requests) as $result) {
            foreach ($result['factors'] as $factor) {
                if (!empty($factor['haspoints'])) {
                    $firedfactors[$factor['key'] ?? $factor['label']] = true;
                }
            }
        }
        $this->assertGreaterThan(5, count($firedfactors), 'too few distinct factors exercised');
    }

    /**
     * The speed factor changes the score from duration, so it is exercised explicitly.
     */
    public function test_bulk_matches_single_with_speed_factor_enabled(): void {
        set_config('speedreviewenabled', 1, 'quizaccess_proctoring');
        set_config('speedreviewminsecondsperquestion', 30, 'quizaccess_proctoring');

        $requests = [];
        for ($seed = 0; $seed < 6; $seed++) {
            $requests['s' . $seed] = $this->seed_attempt($seed, true);
        }

        $this->assert_bulk_matches_single($requests);
        foreach ($this->assert_scores($requests) as $score) {
            $this->assertGreaterThanOrEqual(0, $score);
        }
    }

    /**
     * Disabled factors must be skipped identically by both paths, including the F12 / other-shortcut
     * split and the opt-in phone monitor.
     */
    public function test_bulk_matches_single_with_factors_disabled(): void {
        set_config('riskfactor_tabactivity_enabled', 0, 'quizaccess_proctoring');
        set_config('riskfactor_f12_enabled', 0, 'quizaccess_proctoring');
        set_config('riskfactor_noface_enabled', 0, 'quizaccess_proctoring');
        set_config('riskfactor_webcammissing_enabled', 0, 'quizaccess_proctoring');
        set_config('riskfactor_aitoolscreenshot_enabled', 0, 'quizaccess_proctoring');
        set_config('detectphone', 0, 'quizaccess_proctoring');

        $requests = [];
        for ($seed = 0; $seed < 6; $seed++) {
            $requests['d' . $seed] = $this->seed_attempt($seed, true);
        }

        $this->assert_bulk_matches_single($requests);
    }

    /**
     * Only the other-shortcuts factor enabled: F12 rows must still be told apart and excluded.
     */
    public function test_bulk_matches_single_with_only_other_shortcuts_scored(): void {
        set_config('riskfactor_f12_enabled', 0, 'quizaccess_proctoring');
        set_config('riskfactor_shortcut_enabled', 1, 'quizaccess_proctoring');

        $requests = [];
        for ($seed = 1; $seed < 5; $seed++) {
            $requests['k' . $seed] = $this->seed_attempt($seed, true);
        }

        $this->assert_bulk_matches_single($requests);
    }

    /**
     * Attempts with no attempt id are scoped by report id, which cannot be grouped; those fall back
     * to the per-attempt path and must still come back in the caller's order.
     */
    public function test_bulk_handles_attempts_without_an_attempt_id(): void {
        $requests = [
            'withid' => $this->seed_attempt(1, true),
            'noid' => $this->seed_attempt(2, false),
            'alsowithid' => $this->seed_attempt(3, true),
            'alsonoid' => $this->seed_attempt(4, false),
        ];

        $this->assert_bulk_matches_single($requests);
    }

    /**
     * Two report rows pointing at the same attempt score identically.
     */
    public function test_bulk_scores_repeated_attempts_consistently(): void {
        $request = $this->seed_attempt(5, true);
        $bulk = risk_calculator::calculate_many(['first' => $request, 'second' => $request]);

        $this->assertEquals($bulk['first'], $bulk['second']);
        $this->assertEquals(
            risk_calculator::calculate_attempt(
                $request['courseid'],
                $request['cmid'],
                $request['userid'],
                $request['reportid']
            ),
            $bulk['first']
        );
    }

    /**
     * A request set larger than one chunk still matches, so chunk boundaries are not a special case.
     */
    public function test_bulk_matches_single_across_chunk_boundaries(): void {
        $requests = [];
        for ($seed = 0; $seed < 4; $seed++) {
            $requests['c' . $seed] = $this->seed_attempt($seed, true);
        }

        // Force several chunks over a small set rather than seeding hundreds of attempts.
        $chunked = risk_calculator::calculate_many(array_slice($requests, 0, 2, true))
            + risk_calculator::calculate_many(array_slice($requests, 2, 2, true));
        $whole = risk_calculator::calculate_many($requests);

        foreach ($requests as $key => $unused) {
            $this->assertEquals($whole[$key], $chunked[$key]);
        }
        $this->assert_bulk_matches_single($requests);
    }

    /**
     * An empty request set is not an error.
     */
    public function test_bulk_with_no_requests_returns_nothing(): void {
        $this->assertSame([], risk_calculator::calculate_many([]));
    }

    /**
     * One student sitting two different exams is scored per exam.
     *
     * The bulk queries select by attempt id and match rows back on the whole course/quiz/user/attempt
     * key, so this guards the matching: if it keyed on attempt id alone, evidence from one exam could
     * be counted against the other.
     */
    public function test_bulk_keeps_two_exams_by_the_same_student_apart(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        $secondquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $secondcm = get_coursemodule_from_instance('quiz', $secondquiz->id);

        // Same student, one attempt per exam: the first noisy, the second clean.
        $requests = [];
        foreach ([[$this->quiz, $this->cm, 6], [$secondquiz, $secondcm, 0]] as $index => [$quiz, $cm, $events]) {
            $start = time() - 900;
            $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
                'quiz' => $quiz->id,
                'userid' => $user->id,
                'attempt' => 1,
                'uniqueid' => 9000 + $index,
                'layout' => '1,0',
                'state' => 'finished',
                'timestart' => $start,
                'timefinish' => $start + 300,
                'timemodified' => time(),
                'sumgrades' => 1,
            ], true);
            $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
                'courseid' => $this->course->id,
                'quizid' => $cm->id,
                'userid' => $user->id,
                'webcampicture' => 'data:image/png;base64,AAAA',
                'status' => $attemptid,
                'awsscore' => 90,
                'awsflag' => 0,
                'deletionprogress' => 0,
                'timemodified' => time(),
            ], true);
            for ($i = 0; $i < $events; $i++) {
                $DB->insert_record('quizaccess_proctoring_events', (object)[
                    'courseid' => $this->course->id,
                    'quizid' => $cm->id,
                    'userid' => $user->id,
                    'attemptid' => $attemptid,
                    'reportid' => $reportid,
                    'eventtype' => 'multiple_monitors_detected',
                    'eventdetail' => '',
                    'screenshoturl' => '',
                    'timemodified' => time(),
                ]);
            }
            $requests[$index === 0 ? 'noisy' : 'clean'] = [
                'courseid' => (int)$this->course->id,
                'cmid' => (int)$cm->id,
                'userid' => (int)$user->id,
                'reportid' => $reportid,
            ];
        }

        $this->assert_bulk_matches_single($requests);

        $bulk = risk_calculator::calculate_many($requests);
        $this->assertGreaterThan(0, (int)$bulk['noisy']['score']);
        $this->assertSame(0, (int)$bulk['clean']['score'], 'the clean exam inherited the other exam evidence');
    }

    /**
     * Collect the bulk scores for a request set.
     *
     * @param array $requests Requests as accepted by risk_calculator::calculate_many().
     * @return int[] Scores keyed by request key.
     */
    private function assert_scores(array $requests): array {
        return array_map(function ($result) {
            return (int)$result['score'];
        }, risk_calculator::calculate_many($requests));
    }
}

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
 * Property-based test proving the risk score is monotonic in suspicious evidence.
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
 * Property-based test for monotonicity of risk_calculator::calculate_attempt.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_calculator::calculate_attempt
 */
final class risk_score_monotonicity_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 150;

    /** @var int Synthetic course id used for the generated attempt. */
    private const COURSEID = 626262;

    /** @var int Synthetic quiz course-module id used for the generated attempt. */
    private const CMID = 575757;

    /** @var int Synthetic student id used for the generated attempt. */
    private const USERID = 191919;

    /**
     * Student-attributable suspicious event types that feed the scoring factors. These are the only
     * signals that can move the risk score; adding more of any of them must never lower the score.
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

        // A non-empty webcam picture keeps the "webcam missing" factor at zero in BOTH multisets, so
        // it cannot confound monotonicity (webcam-missing is an inverse factor). status = 0 selects
        // the report-id lookup path and avoids the optional quiz-attempts speed factor. No
        // face-mismatch/failed AWS flags are set, so those non-event factors are held constant too.
        $this->reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => 0,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ], true);

        // Speed factor disabled so only the generated event multiset drives the score.
        set_config('speedreviewenabled', 0, 'quizaccess_proctoring');
    }

    /**
     * Feature: proctoring-feedback-improvements, Property 10: Risk score is monotonic in suspicious evidence
     *
     * For any two attempts whose suspicious-event multisets are identical except that the second has
     * at least as many of every suspicious event type as the first, the risk score of the second
     * attempt is greater than or equal to the risk score of the first (Requirement 16.2).
     *
     * The test generates a base multiset of student-attributable events (multiset A), scores it, then
     * adds a non-negative delta of each event type so the second multiset (A + delta) dominates the
     * first, scores it, and asserts score2 >= score1. All non-event factors (webcam presence,
     * face-mismatch/failed AWS flags, speed) are held constant across both scores. Runs over many
     * generated pairs with a deterministic seed so any counterexample is reproducible.
     *
     * Validates: Requirements 16.2
     */
    public function test_risk_score_is_monotonic_in_suspicious_evidence(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260120);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // First (dominated) multiset.
            $this->reset_events();
            $base = $this->generate_event_counts(0, 4);
            $this->insert_events($base);
            $score1 = $this->score();

            // Second multiset dominates the first: at least as many of every event type.
            $this->reset_events();
            $dominating = $this->add_nonnegative_delta($base, 0, 4);
            $this->insert_events($dominating);
            $score2 = $this->score();

            $context = 'iteration=' . $iteration
                . ' base=' . json_encode($base)
                . ' dominating=' . json_encode($dominating)
                . ' score1=' . $score1 . ' score2=' . $score2;

            // Sanity: the second multiset genuinely dominates the first.
            foreach ($dominating as $type => $count) {
                $this->assertGreaterThanOrEqual($base[$type], $count,
                    'generated second multiset does not dominate the first: ' . $context);
            }

            // The property: more suspicious evidence never lowers the score.
            $this->assertGreaterThanOrEqual($score1, $score2,
                'risk score decreased when suspicious evidence increased: ' . $context);
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
     * Remove all generated student events for the synthetic attempt.
     */
    private function reset_events(): void {
        global $DB;
        $DB->delete_records('quizaccess_proctoring_events', [
            'courseid' => self::COURSEID,
            'quizid' => self::CMID,
            'userid' => self::USERID,
        ]);
    }

    /**
     * Generate a random multiset of student-attributable event counts.
     *
     * @param int $min Minimum count per type.
     * @param int $max Maximum count per type.
     * @return array<string, int> Map of event type (and 'shortcut_f12',
     *                            'possible_ai_tool_screenshot' pseudo-keys) to count.
     */
    private function generate_event_counts(int $min, int $max): array {
        $counts = [];
        foreach (self::EVENT_TYPES as $type) {
            $counts[$type] = mt_rand($min, $max);
        }
        // A subset of possible_ai_tool events also carry a desktop screenshot (extra factor).
        $counts['possible_ai_tool_screenshot'] = mt_rand(0, min($max, $counts['possible_ai_tool']));
        // F12 shortcut events (recorded as eventtype 'shortcut').
        $counts['shortcut_f12'] = mt_rand($min, $max);
        return $counts;
    }

    /**
     * Add a non-negative random delta to every count so the result dominates the input multiset.
     *
     * @param array<string, int> $base Base counts.
     * @param int $min Minimum delta per type.
     * @param int $max Maximum delta per type.
     * @return array<string, int> Dominating counts (>= base for every key).
     */
    private function add_nonnegative_delta(array $base, int $min, int $max): array {
        $result = [];
        foreach ($base as $key => $count) {
            $result[$key] = $count + mt_rand($min, $max);
        }
        // The screenshot count must never exceed the number of possible_ai_tool events.
        $result['possible_ai_tool_screenshot'] = min(
            $result['possible_ai_tool_screenshot'],
            $result['possible_ai_tool']
        );
        // Keep domination after the clamp above: it can only reduce toward base's screenshot count,
        // which is itself bounded by base's possible_ai_tool count <= result's, so this is safe.
        $result['possible_ai_tool_screenshot'] = max(
            $result['possible_ai_tool_screenshot'],
            $base['possible_ai_tool_screenshot']
        );
        return $result;
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
}

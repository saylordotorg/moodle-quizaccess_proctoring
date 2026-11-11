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
 * Tests for scoring signs that software is operating the quiz page.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\factor_coverage;
use quizaccess_proctoring\local\overall_report;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * An automation_signal event scores through the "automation" factor, in both scoring paths, and
 * can hold an attempt for review but never fail it automatically.
 *
 * @covers \quizaccess_proctoring\local\risk_calculator
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class automation_signals_test extends advanced_testcase {
    /**
     * Create an attempt with a webcam capture and the given number of automation signals.
     *
     * @param int $signals How many automation_signal events to store.
     * @return array Request as accepted by risk_calculator::calculate_many().
     */
    private function seed_attempt(int $signals): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $user = $generator->create_user();

        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => 7000 + $signals,
            'layout' => '1,0',
            'state' => 'finished',
            'timestart' => time() - 900,
            'timefinish' => time() - 60,
            'timemodified' => time(),
            'sumgrades' => 1,
        ], true);
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $user->id,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => $attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ], true);
        $kinds = ['untrusted_input', 'answer_without_input', 'teleport_click', 'webdriver', 'decoy_control'];
        for ($i = 0; $i < $signals; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $course->id,
                'quizid' => $cm->id,
                'userid' => $user->id,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'eventtype' => 'automation_signal',
                'eventdetail' => json_encode(['signal' => $kinds[$i % count($kinds)]]),
                'screenshoturl' => '',
                'timemodified' => time(),
            ]);
        }

        return [
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'userid' => (int)$user->id,
            'reportid' => $reportid,
        ];
    }

    /**
     * Find one factor in a risk result.
     *
     * @param array $risk Result of risk_calculator::calculate_attempt().
     * @param string $key Factor key.
     * @return array|null The factor, or null when the result does not list it.
     */
    private function factor(array $risk, string $key): ?array {
        foreach ($risk['factors'] as $factor) {
            if (($factor['key'] ?? '') === $key) {
                return $factor;
            }
        }
        return null;
    }

    /**
     * Each signal scores the factor's points, up to its cap, and both scoring paths agree.
     */
    public function test_signals_score_through_the_automation_factor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $points = risk_calculator::FACTOR_DEFAULTS['automation']['points'];
        $cap = risk_calculator::FACTOR_DEFAULTS['automation']['cap'];
        $requests = ['none' => $this->seed_attempt(0), 'two' => $this->seed_attempt(2), 'many' => $this->seed_attempt(9)];
        $expected = ['none' => 0, 'two' => min($cap, 2 * $points), 'many' => $cap];

        $bulk = risk_calculator::calculate_many($requests);
        foreach ($requests as $key => $request) {
            $single = risk_calculator::calculate_attempt(
                $request['courseid'],
                $request['cmid'],
                $request['userid'],
                $request['reportid']
            );
            $factor = $this->factor($single, 'automation');
            $this->assertNotNull($factor, 'the automation factor is listed even with no evidence');
            $this->assertSame($expected[$key], $factor['points'], "attempt {$key}");
            $this->assertEquals($single, $bulk[$key], "attempt {$key} scored differently in bulk");
        }
    }

    /**
     * A switched-off factor scores nothing and is left out of the result.
     */
    public function test_a_disabled_factor_scores_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('riskfactor_automation_enabled', 0, 'quizaccess_proctoring');

        $request = $this->seed_attempt(3);
        $risk = risk_calculator::calculate_attempt(
            $request['courseid'],
            $request['cmid'],
            $request['userid'],
            $request['reportid']
        );
        $this->assertNull($this->factor($risk, 'automation'));
        $this->assertEquals($risk, risk_calculator::calculate_many(['a' => $request])['a']);
    }

    /**
     * Automation signs can hold an attempt for a reviewer but never fail it automatically.
     */
    public function test_automation_is_review_only(): void {
        $this->resetAfterTest();
        $this->assertContains('automation', risk_calculator::REVIEW_ONLY_FACTORS);

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        $this->assertSame(20, risk_calculator::score_without_review_only_factors(['factors' => [
            ['key' => 'automation', 'points' => 45],
            ['key' => 'tabactivity', 'points' => 20],
        ]]));
    }

    /**
     * The event counts as a violation in reports, and is checked whenever browser activity is monitored.
     */
    public function test_the_event_is_a_violation_checked_by_activity_monitoring(): void {
        $this->assertContains('automation_signal', overall_report::SUSPICIOUS_EVENT_TYPES);

        $on = ['activity' => true, 'clipboard' => true, 'screen' => false, 'multimonitor' => false];
        $off = ['activity' => false] + $on;
        $this->assertNull(factor_coverage::reason('automation', $on, true, false, 1, 1));
        $this->assertSame(
            factor_coverage::REASON_SETTING_OFF,
            factor_coverage::reason('automation', $off, true, false, 1, 1)
        );
    }
}

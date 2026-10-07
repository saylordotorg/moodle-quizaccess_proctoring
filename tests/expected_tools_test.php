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
 * Tests for the per-quiz "Tools the exam allows" setting (CPIT-482).
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
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');

/**
 * Expected tools tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring::save_settings
 * @covers \quizaccess_proctoring\local\risk_calculator::open_resource_quizzes
 * @covers \quizaccess_proctoring\local\risk_calculator::is_navigation_shortcut
 */
final class expected_tools_test extends advanced_testcase {

    /**
     * A proctored quiz with three tab switches in one attempt.
     *
     * @param int $expectedtools Setting value.
     * @return array [course, cm, log id]
     */
    private function attempt_with_switches(int $expectedtools): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        \quizaccess_proctoring::save_settings((object)[
            'id' => $quiz->id,
            'proctoringrequired' => 1,
            'expectedtools' => $expectedtools,
        ]);
        $logid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => 7, 'webcampicture' => '',
            'status' => 900, 'awsscore' => 0, 'awsflag' => 0, 'deletionprogress' => 0, 'timemodified' => time(),
        ]);
        foreach (['focus_lost', 'tab_hidden', 'focus_lost'] as $type) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => 7, 'attemptid' => 900,
                'reportid' => $logid, 'eventtype' => $type, 'eventdetail' => '{}', 'timemodified' => time(),
            ]);
        }
        return [$course, (int)$quiz->cmid, (int)$logid];
    }

    /**
     * The tab and focus factor's points in a result.
     *
     * @param array $risk Risk result.
     * @return int
     */
    private function tab_points(array $risk): int {
        foreach ($risk['factors'] as $factor) {
            if (($factor['key'] ?? '') === 'tabactivity') {
                return (int)$factor['points'];
            }
        }
        return 0;
    }

    /**
     * Leaving the quiz scores on a closed-book exam and not on an open-resource one, in both scoring paths.
     */
    public function test_open_resource_exam_does_not_score_leaving_the_quiz(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $logid] = $this->attempt_with_switches(\quizaccess_proctoring::EXPECTED_TOOLS_NONE);
        $this->assertGreaterThan(0, $this->tab_points(risk_calculator::calculate_attempt((int)$course->id, $cmid, 7, $logid)));

        [$course, $cmid, $logid] = $this->attempt_with_switches(\quizaccess_proctoring::EXPECTED_TOOLS_OPEN);
        $this->assertSame([$cmid => true], risk_calculator::open_resource_quizzes([$cmid]));
        $this->assertSame(0, $this->tab_points(risk_calculator::calculate_attempt((int)$course->id, $cmid, 7, $logid)));
        $bulk = risk_calculator::calculate_many([[
            'courseid' => (int)$course->id, 'cmid' => $cmid, 'userid' => 7, 'reportid' => $logid, 'attemptid' => 900,
        ]]);
        $this->assertSame(0, $this->tab_points(reset($bulk)));

        // The events are still recorded for reviewers to see.
        $this->assertSame(3, $DB->count_records('quizaccess_proctoring_events', ['quizid' => $cmid]));
    }

    /**
     * Shortcuts that leave the page are not scored on an open-resource exam; F12 and DevTools still are
     * (PR #52 review).
     */
    public function test_open_resource_exam_does_not_score_navigation_shortcuts(): void {
        global $DB;
        $this->resetAfterTest();

        $points = [];
        foreach ([\quizaccess_proctoring::EXPECTED_TOOLS_NONE, \quizaccess_proctoring::EXPECTED_TOOLS_OPEN] as $tools) {
            [$course, $cmid, $logid] = $this->attempt_with_switches($tools);
            foreach (['Alt+TAB', 'Ctrl+T', 'Meta+L', 'F12', 'Ctrl+Shift+I'] as $shortcut) {
                $DB->insert_record('quizaccess_proctoring_events', (object)[
                    'courseid' => $course->id, 'quizid' => $cmid, 'userid' => 7, 'attemptid' => 900,
                    'reportid' => $logid, 'eventtype' => 'shortcut',
                    'eventdetail' => json_encode(['shortcut' => $shortcut]), 'timemodified' => time(),
                ]);
            }
            $single = risk_calculator::calculate_attempt((int)$course->id, $cmid, 7, $logid);
            $bulk = risk_calculator::calculate_many([[
                'courseid' => (int)$course->id, 'cmid' => $cmid, 'userid' => 7, 'reportid' => $logid, 'attemptid' => 900,
            ]]);
            $points[$tools] = [
                $this->factor_points($single, 'shortcut'), $this->factor_points(reset($bulk), 'shortcut'),
                $this->factor_points($single, 'f12'), $this->factor_points(reset($bulk), 'f12'),
            ];
        }

        [$closedsingle, $closedbulk, $closedf12, $closedf12bulk] = $points[\quizaccess_proctoring::EXPECTED_TOOLS_NONE];
        [$opensingle, $openbulk, $openf12, $openf12bulk] = $points[\quizaccess_proctoring::EXPECTED_TOOLS_OPEN];
        // Closed book: four non-F12 shortcuts, capped. Open resource: only Ctrl+Shift+I.
        $this->assertGreaterThan($opensingle, $closedsingle);
        $this->assertSame($closedsingle, $closedbulk);
        $this->assertSame($opensingle, $openbulk);
        $this->assertGreaterThan(0, $opensingle);
        $this->assertSame($closedf12, $openf12);
        $this->assertSame($openf12, $openf12bulk);
        $this->assertGreaterThan(0, $openf12);
        $this->assertSame($closedf12, $closedf12bulk);
    }

    /**
     * A factor's points in a result.
     *
     * @param array $risk Risk result.
     * @param string $key Factor key.
     * @return int
     */
    private function factor_points(array $risk, string $key): int {
        foreach ($risk['factors'] as $factor) {
            if (($factor['key'] ?? '') === $key) {
                return (int)$factor['points'];
            }
        }
        return 0;
    }

    /**
     * An unknown value is saved as "none".
     */
    public function test_invalid_value_is_saved_as_none(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        \quizaccess_proctoring::save_settings((object)['id' => $quiz->id, 'proctoringrequired' => 1, 'expectedtools' => 9]);
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring', 'expectedtools', ['quizid' => $quiz->id]));
    }
}

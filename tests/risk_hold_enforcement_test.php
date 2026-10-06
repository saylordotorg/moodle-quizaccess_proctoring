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
 * Tests that risk holds actually hold the gradebook grade (CPIT-463).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use completion_info;
use grade_grade;
use grade_item;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Risk hold gradebook enforcement tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_hold_enforcer
 * @covers ::quizaccess_proctoring_apply_risk_hold
 * @covers ::quizaccess_proctoring_release_risk_hold
 * @covers ::quizaccess_proctoring_confirm_risk_hold
 * @covers ::quizaccess_proctoring_certificate_state
 */
final class risk_hold_enforcement_test extends advanced_testcase {

    /** @var stdClass Course. */
    private $course;

    /** @var stdClass Quiz record. */
    private $quiz;

    /** @var \cm_info|stdClass Quiz course module. */
    private $cm;

    /** @var stdClass Student. */
    private $student;

    /** @var int Next fake question usage id for directly inserted attempts. */
    private $nextuniqueid = 900000;

    /**
     * A course with completion, and a quiz out of 10 that completes on a passing grade of 7.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        set_config('holddecisionnotify', 0, 'quizaccess_proctoring');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);
        $quiz = $generator->create_module('quiz', [
            'course' => $this->course->id,
            'grade' => 10,
            'sumgrades' => 10,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
        ]);
        $DB->set_field('quiz', 'sumgrades', 10, ['id' => $quiz->id]);
        $this->quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST);
        $this->quiz->cmidnumber = $this->cm->idnumber;
        $this->quiz->visible = $this->cm->visible;

        $item = $this->grade_item();
        $item->gradepass = 7;
        $item->update();

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * An active hold empties and locks a passing grade, so the quiz is no longer complete.
     */
    public function test_active_hold_empties_and_locks_the_grade(): void {
        $attemptid = $this->add_attempt(9);
        $this->assertEquals(9.0, $this->final_grade());
        $this->assertSame(COMPLETION_COMPLETE_PASS, $this->completion_state());

        $this->hold($attemptid);

        $grade = $this->grade();
        $this->assertNull($grade->finalgrade);
        $this->assertNull($grade->rawgrade);
        $this->assertNotEmpty($grade->locked);
        $this->assertStringContainsString('Proctoring review required', (string)$grade->feedback);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
    }

    /**
     * A quiz regrade or recompute while held cannot put the grade back.
     */
    public function test_regrade_cannot_restore_a_held_grade(): void {
        $attemptid = $this->add_attempt(9);
        $this->hold($attemptid);

        $this->recompute();
        quiz_update_grades($this->quiz, $this->student->id);

        $this->assertNull($this->final_grade());
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
    }

    /**
     * Unlocking the held grade by hand and regrading re-applies the hold.
     */
    public function test_hold_is_reapplied_after_a_manual_unlock(): void {
        $attemptid = $this->add_attempt(9);
        $this->hold($attemptid);

        $this->grade()->set_locked(0, false, false);
        quiz_update_grades($this->quiz, $this->student->id);

        $grade = $this->grade();
        $this->assertNull($grade->finalgrade);
        $this->assertNotEmpty($grade->locked);
    }

    /**
     * Releasing the hold unlocks and restores the grade and removes the hold feedback.
     */
    public function test_release_restores_the_grade(): void {
        $attemptid = $this->add_attempt(9);
        $holdid = $this->hold($attemptid);

        $this->assertTrue(quizaccess_proctoring_release_risk_hold($holdid, 2));

        $grade = $this->grade();
        $this->assertEquals(9.0, $grade->finalgrade);
        $this->assertEmpty($grade->locked);
        $this->assertEmpty($grade->feedback);
        $this->assertSame(COMPLETION_COMPLETE_PASS, $this->completion_state());
    }

    /**
     * A confirmed violation scores its attempt as zero, even after a regrade, but a later
     * honest attempt still counts.
     */
    public function test_confirmed_attempt_scores_zero_but_a_later_attempt_counts(): void {
        $attemptid = $this->add_attempt(9);
        $holdid = $this->hold($attemptid);

        $this->assertTrue(quizaccess_proctoring_confirm_risk_hold($holdid, 2));
        $grade = $this->grade();
        $this->assertEquals(0.0, $grade->finalgrade);
        $this->assertEmpty($grade->locked);
        $this->assertStringContainsString('violation confirmed', (string)$grade->feedback);

        // A regrade rebuilds the attempt's own mark; the confirmed hold keeps it at zero.
        $this->recompute();
        $this->assertEquals(0.0, $this->final_grade());

        // The quiz grades on the highest attempt, so a later passing attempt counts.
        $this->add_attempt(8);
        $this->assertEquals(8.0, $this->final_grade());
        $this->assertSame(COMPLETION_COMPLETE_PASS, $this->completion_state());
    }

    /**
     * A second, active hold on a later attempt keeps the grade held when the first is released.
     */
    public function test_release_keeps_another_active_hold(): void {
        $first = $this->add_attempt(9);
        $firsthold = $this->hold($first);
        $second = $this->add_attempt(10);
        $this->hold($second);

        $this->assertTrue(quizaccess_proctoring_release_risk_hold($firsthold, 2));

        $grade = $this->grade();
        $this->assertNull($grade->finalgrade);
        $this->assertNotEmpty($grade->locked);
    }

    /**
     * Grade changes for students without holds, and for other activities, are left alone.
     */
    public function test_grades_without_holds_are_untouched(): void {
        $this->add_attempt(9);
        $this->assertEquals(9.0, $this->final_grade());
        $this->assertEmpty($this->grade()->locked);
    }

    /**
     * A certificate issued during a hold or after a confirmed violation is flagged.
     */
    public function test_certificate_state_flags_a_certificate_issued_during_a_hold(): void {
        $this->assertSame('conflict', quizaccess_proctoring_certificate_state(true, false, false, true, true));
        $this->assertSame('conflict', quizaccess_proctoring_certificate_state(false, false, true, true, true));
        $this->assertSame('held', quizaccess_proctoring_certificate_state(true, false, false, true, false));
        $this->assertSame('released', quizaccess_proctoring_certificate_state(false, true, false, true, true));
        $this->assertSame('issued', quizaccess_proctoring_certificate_state(false, false, false, true, true));
    }

    /**
     * Insert a finished attempt and publish the quiz grade as core does at submission.
     *
     * @param float $sumgrades Attempt mark out of 10.
     * @return int Attempt id.
     */
    private function add_attempt(float $sumgrades): int {
        global $DB;

        $now = time();
        $number = (int)$DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $this->student->id]) + 1;
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $this->quiz->id,
            'userid' => $this->student->id,
            'attempt' => $number,
            'uniqueid' => $this->nextuniqueid++,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => $now - 600 + $number,
            'timefinish' => $now - 60 + $number,
            'timemodified' => $now,
            'timemodifiedoffline' => 0,
            'timecheckstate' => null,
            'sumgrades' => $sumgrades,
        ]);
        $this->recompute();
        return $attemptid;
    }

    /**
     * Put an active risk hold on an attempt.
     *
     * @param int $attemptid Attempt id.
     * @return int Hold id.
     */
    private function hold(int $attemptid): int {
        return quizaccess_proctoring_apply_risk_hold(
            (int)$this->course->id,
            (int)$this->cm->id,
            (int)$this->student->id,
            $attemptid,
            0,
            90,
            80
        );
    }

    /**
     * Recompute the student's quiz grade the way a quiz regrade does.
     */
    private function recompute(): void {
        \mod_quiz\quiz_settings::create($this->quiz->id, $this->student->id)
            ->get_grade_calculator()
            ->recompute_final_grade($this->student->id);
    }

    /**
     * The quiz's main grade item.
     *
     * @return grade_item
     */
    private function grade_item(): grade_item {
        return grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $this->quiz->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * The student's gradebook grade for the quiz.
     *
     * @return grade_grade
     */
    private function grade(): grade_grade {
        $grade = grade_grade::fetch(['itemid' => $this->grade_item()->id, 'userid' => $this->student->id]);
        $this->assertNotFalse($grade);
        return $grade;
    }

    /**
     * The student's final gradebook grade, as a float or null.
     *
     * @return float|null
     */
    private function final_grade(): ?float {
        $grade = $this->grade();
        return $grade->finalgrade === null ? null : (float)$grade->finalgrade;
    }

    /**
     * The student's completion state for the quiz.
     *
     * @return int
     */
    private function completion_state(): int {
        $completion = new completion_info(get_course($this->course->id));
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->cm->id);
        return (int)$completion->get_data($cm, false, $this->student->id)->completionstate;
    }
}

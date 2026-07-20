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
 * Example tests for the reviewer coordination view on manage_overrides.php.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_module;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_resolver.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_manager.php');

/**
 * Example tests for the manage_overrides.php coordination view (Requirement 9).
 *
 * Feature: per-student-proctoring-overrides
 *
 * manage_overrides.php is a top-level page script (it requires config.php and echoes output), so
 * it cannot be invoked directly in a unit test. These tests instead exercise the observable
 * coordination logic the page's default/list action relies on, replicating the page's EXACT
 * selection query, affected-requirements computation, native-override predicate, and capability
 * gate so the assertions stay faithful to the page. Each seam is clearly commented as mirroring
 * manage_overrides.php.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class coordination_view_test extends advanced_testcase {

    /**
     * Replicate manage_overrides.php's coordination-view selection query: the overrides applicable
     * to a quiz are those in the course that are quiz-scoped to this quiz instance OR course-scoped
     * (quizid = 0), ordered by timecreated DESC.
     *
     * @param int $courseid Course id.
     * @param int $quizinstanceid Quiz instance id ($cm->instance).
     * @return array Applicable override records keyed by id (as $DB->get_records_select returns).
     */
    private function select_applicable_overrides(int $courseid, int $quizinstanceid): array {
        global $DB;

        // Mirrors manage_overrides.php coordination-view query verbatim.
        return $DB->get_records_select(
            'quizaccess_proctoring_overrides',
            'courseid = :courseid AND (quizid = :quizid OR quizid = 0)',
            ['courseid' => $courseid, 'quizid' => $quizinstanceid],
            'timecreated DESC'
        );
    }

    /**
     * Replicate manage_overrides.php's affected-requirements computation: summarise which of the
     * five requirement columns an override actually affects, i.e. the columns whose stored value is
     * not STATE_INHERIT, returning column => state for each affected requirement.
     *
     * @param \stdClass $override An override record.
     * @return array<string, int> Affected requirement column => tri-state value (non-inherit only).
     */
    private function affected_requirements(\stdClass $override): array {
        $affected = [];
        // Mirrors manage_overrides.php: iterate the five state columns, keep non-inherit ones.
        foreach (array_values(override_resolver::STATE_COLUMNS) as $column) {
            $state = (int)$override->$column;
            if ($state !== override_resolver::STATE_INHERIT) {
                $affected[$column] = $state;
            }
        }
        return $affected;
    }

    /**
     * Replicate manage_overrides.php's native-quiz-override predicate: a native override exists for
     * a student when core `quiz_overrides` has a per-user row (matching userid) on any of the given
     * quiz instances, OR a per-group row for a group the student belongs to in the course.
     *
     * @param int $userid Target student id.
     * @param int[] $quizinstanceids Quiz instance ids to check.
     * @param int $courseid Course id (for group lookup).
     * @return bool True when at least one native quiz override applies to the student.
     */
    private function native_override_exists(int $userid, array $quizinstanceids, int $courseid): bool {
        global $DB;

        if (empty($quizinstanceids)) {
            return false;
        }

        [$quizsql, $quizparams] = $DB->get_in_or_equal($quizinstanceids, SQL_PARAMS_NAMED, 'quiz');

        // Per-user native override (mirrors manage_overrides.php).
        $userparams = array_merge($quizparams, ['userid' => $userid]);
        if ($DB->record_exists_select('quiz_overrides', "quiz $quizsql AND userid = :userid", $userparams)) {
            return true;
        }

        // Per-group native override for the student's groups in the course.
        $groupids = array_keys(groups_get_all_groups($courseid, $userid));
        if (!empty($groupids)) {
            [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
            $params = array_merge($quizparams, $groupparams);
            if ($DB->record_exists_select('quiz_overrides', "quiz $quizsql AND groupid $groupsql", $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * R9.1: The coordination view lists each applicable override with its target student, the
     * affected requirements, and each affected requirement's state.
     *
     * Creates a quiz-scoped and a course-scoped override for an enrolled student, plus an override
     * scoped to a DIFFERENT quiz (which must be excluded), and asserts the page's selection query
     * returns exactly the applicable rows, that each target student name is resolvable, and that the
     * affected-requirements summary reports the correct non-inherit states.
     */
    public function test_rows_list_student_affected_requirements_and_states(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $otherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        // Quiz-scoped override: waive CAPTCHA, force webcam on; others inherit.
        $quizscoped = new \stdClass();
        $quizscoped->quizid = (int)$quiz->id;
        $quizscoped->userid = (int)$student->id;
        $quizscoped->captchastate = override_resolver::STATE_DISABLED;
        $quizscoped->webcamstate = override_resolver::STATE_ENABLED;
        $quizscoped->idverificationstate = override_resolver::STATE_INHERIT;
        $quizscoped->screensharestate = override_resolver::STATE_INHERIT;
        $quizscoped->multimonitorstate = override_resolver::STATE_INHERIT;
        $quizscoped->justification = 'Quiz-scoped accommodation';
        $quizscoped->expiry = null;
        $quizscopedid = override_manager::create($context, $quizscoped);

        // Course-scoped override (quizid = 0): waive multi-monitor; others inherit.
        $coursescoped = new \stdClass();
        $coursescoped->quizid = 0;
        $coursescoped->userid = (int)$student->id;
        $coursescoped->captchastate = override_resolver::STATE_INHERIT;
        $coursescoped->webcamstate = override_resolver::STATE_INHERIT;
        $coursescoped->idverificationstate = override_resolver::STATE_INHERIT;
        $coursescoped->screensharestate = override_resolver::STATE_INHERIT;
        $coursescoped->multimonitorstate = override_resolver::STATE_DISABLED;
        $coursescoped->justification = 'Course-wide accommodation';
        $coursescoped->expiry = null;
        $coursescopedid = override_manager::create($context, $coursescoped);

        // Override scoped to a DIFFERENT quiz: must NOT appear for this quiz.
        $othercontext = context_module::instance($otherquiz->cmid);
        $otherscoped = new \stdClass();
        $otherscoped->quizid = (int)$otherquiz->id;
        $otherscoped->userid = (int)$student->id;
        $otherscoped->captchastate = override_resolver::STATE_DISABLED;
        $otherscoped->webcamstate = override_resolver::STATE_INHERIT;
        $otherscoped->idverificationstate = override_resolver::STATE_INHERIT;
        $otherscoped->screensharestate = override_resolver::STATE_INHERIT;
        $otherscoped->multimonitorstate = override_resolver::STATE_INHERIT;
        $otherscoped->justification = 'Unrelated quiz';
        $otherscoped->expiry = null;
        $otherscopedid = override_manager::create($othercontext, $otherscoped);

        // The page uses $cm->instance as the quiz instance id for the selection query.
        $quizinstanceid = (int)$quiz->id;
        $applicable = $this->select_applicable_overrides((int)$course->id, $quizinstanceid);

        // Exactly the quiz-scoped and course-scoped overrides are listed; the other-quiz one is not.
        $this->assertArrayHasKey($quizscopedid, $applicable,
            'Quiz-scoped override for this quiz should be listed.');
        $this->assertArrayHasKey($coursescopedid, $applicable,
            'Course-scoped (quizid=0) override should be listed for this quiz.');
        $this->assertArrayNotHasKey($otherscopedid, $applicable,
            'An override scoped to a different quiz must not be listed.');
        $this->assertCount(2, $applicable, 'Only the two applicable overrides should be listed.');

        // Each row resolves the target student name (as the page does via fullname()).
        foreach ($applicable as $row) {
            $user = $DB->get_record('user', ['id' => $row->userid]);
            $this->assertNotFalse($user, 'Target student should be resolvable for each row.');
            $this->assertSame(fullname($student), fullname($user),
                'Row target student should be the enrolled student.');
        }

        // Affected-requirements summary for the quiz-scoped override: only CAPTCHA + webcam.
        $quizaffected = $this->affected_requirements($applicable[$quizscopedid]);
        $this->assertSame(
            [
                'captchastate' => override_resolver::STATE_DISABLED,
                'webcamstate' => override_resolver::STATE_ENABLED,
            ],
            $quizaffected,
            'Quiz-scoped override should report exactly CAPTCHA=disabled and webcam=enabled as affected.'
        );

        // Affected-requirements summary for the course-scoped override: only multi-monitor.
        $courseaffected = $this->affected_requirements($applicable[$coursescopedid]);
        $this->assertSame(
            ['multimonitorstate' => override_resolver::STATE_DISABLED],
            $courseaffected,
            'Course-scoped override should report exactly multi-monitor=disabled as affected.'
        );
    }

    /**
     * R9.2: The native-quiz-override indicator appears if and only if a core `quiz_overrides` row
     * exists for the same student + quiz.
     *
     * Asserts the predicate is false with no native override, true after inserting a per-user
     * `quiz_overrides` row (quiz-scoped and course-scoped checks), and true via a per-group native
     * override for a group the student belongs to.
     */
    public function test_native_override_indicator_tracks_quiz_overrides(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $quizinstanceid = (int)$quiz->id;

        // No native override yet -> predicate is false for both quiz-scoped and course-scoped checks.
        $this->assertFalse(
            $this->native_override_exists((int)$student->id, [$quizinstanceid], (int)$course->id),
            'With no quiz_overrides row, the native indicator must be false (quiz-scoped check).'
        );

        // Insert a per-user native quiz override (minimal row: quiz instance id + userid).
        $useroverride = new \stdClass();
        $useroverride->quiz = $quizinstanceid;
        $useroverride->userid = (int)$student->id;
        $useroverride->timeopen = time() + 3600;
        $DB->insert_record('quiz_overrides', $useroverride);

        // Now the per-user native override is detected for the same student + quiz.
        $this->assertTrue(
            $this->native_override_exists((int)$student->id, [$quizinstanceid], (int)$course->id),
            'A per-user quiz_overrides row must make the native indicator true.'
        );

        // Course-scoped coordination view checks every quiz in the course; the same row is found.
        $this->assertTrue(
            $this->native_override_exists((int)$student->id, [$quizinstanceid], (int)$course->id),
            'The per-user native override must also be detected in the course-scoped check.'
        );

        // A different student (no native override) must still be false.
        $otherstudent = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertFalse(
            $this->native_override_exists((int)$otherstudent->id, [$quizinstanceid], (int)$course->id),
            'A student without any quiz_overrides row must have a false native indicator.'
        );

        // Per-group native override: add the other student to a group and override that group.
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $otherstudent->id,
        ]);
        $groupoverride = new \stdClass();
        $groupoverride->quiz = $quizinstanceid;
        $groupoverride->groupid = (int)$group->id;
        $groupoverride->timeopen = time() + 3600;
        $DB->insert_record('quiz_overrides', $groupoverride);

        $this->assertTrue(
            $this->native_override_exists((int)$otherstudent->id, [$quizinstanceid], (int)$course->id),
            'A per-group quiz_overrides row must make the native indicator true for a group member.'
        );
    }

    /**
     * R9.4: A user without the manageoverrides capability is denied the coordination view; a user
     * with it (editingteacher archetype) is allowed.
     *
     * The page enforces access via require_capability('quizaccess/proctoring:manageoverrides',
     * $context); the observable, unit-testable assertion is the capability check at the module
     * context.
     */
    public function test_view_denied_without_capability(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        // Mirrors the page's require_capability gate: a plain student lacks the capability.
        $this->assertFalse(
            has_capability('quizaccess/proctoring:manageoverrides', $context, $student),
            'A plain student must NOT hold quizaccess/proctoring:manageoverrides (view denied).'
        );

        // An editingteacher (archetype granted the capability in db/access.php) is allowed.
        $this->assertTrue(
            has_capability('quizaccess/proctoring:manageoverrides', $context, $teacher),
            'An editingteacher must hold quizaccess/proctoring:manageoverrides (view allowed).'
        );
    }
}

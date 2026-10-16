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
 * Tests for the cleanup after a deleted quiz attempt (CPIT-464, second round).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\attempt_cleanup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Deleted attempts take their proctoring records with them.
 *
 * Deleting an attempt removed its grades but not its proctoring rows: the report kept showing
 * the student with an attempt link that goes nowhere, and the captures stayed stored (found by
 * Student Affairs on dev).
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\attempt_cleanup
 */
final class attempt_cleanup_test extends advanced_testcase {

    /**
     * Build a course, quiz, student, one attempt row and one capture row claimed by it.
     *
     * @return array [course, quiz record, cm, user, attemptid, logid]
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
            'uniqueid' => 990001,
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
        $logid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $user->id,
            'webcampicture' => 'https://example.com/capture.png',
            'status' => $attemptid,
            'deletionprogress' => 0,
            'timemodified' => $now,
        ]);
        return [$course, $quiz, $cm, $user, $attemptid, $logid];
    }

    /**
     * Deleting an attempt queues its captures and drops its active hold.
     */
    public function test_attempt_deletion_queues_rows_and_drops_the_hold(): void {
        global $DB;

        [$course, $quiz, $cm, $user, $attemptid, $logid] = $this->fixture();
        $holdid = quizaccess_proctoring_apply_risk_hold(
            (int)$course->id,
            (int)$cm->id,
            (int)$user->id,
            $attemptid,
            0,
            90,
            80
        );

        // Simulate core's deletion, then the event it fires.
        $DB->delete_records('quiz_attempts', ['id' => $attemptid]);
        $event = \mod_quiz\event\attempt_deleted::create([
            'objectid' => $attemptid,
            'relateduserid' => $user->id,
            'courseid' => $course->id,
            'context' => \context_module::instance($cm->id),
            'other' => ['quizid' => $quiz->id],
        ]);
        attempt_cleanup::attempt_deleted($event);

        $this->assertEquals(1, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $logid]),
            'the capture rows must be queued for the image deletion task');
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_risk_holds', ['id' => $holdid]),
            'an active hold on a deleted attempt must not keep holding the gradebook');
    }

    /**
     * The sweep queues rows whose attempt is gone and leaves live attempts alone.
     */
    public function test_orphan_sweep_queues_only_dead_rows(): void {
        global $DB;

        [$course, $quiz, $cm, $user, $attemptid, $livelogid] = $this->fixture();
        $deadlogid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $user->id,
            'webcampicture' => 'https://example.com/dead.png',
            'status' => $attemptid + 100000,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ]);

        $queued = attempt_cleanup::queue_orphaned_attempt_rows();

        $this->assertSame(1, $queued);
        $this->assertEquals(1, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $deadlogid]));
        $this->assertEquals(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $livelogid]),
            'a row whose attempt exists must not be touched');
    }
}

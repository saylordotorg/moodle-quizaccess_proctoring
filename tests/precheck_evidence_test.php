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
 * Tests for what happens to abandoned precheck evidence (CPIT-464).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_module;
use context_system;
use quizaccess_proctoring\local\precheck_evidence;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Precheck evidence lifecycle tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\precheck_evidence
 * @covers ::quizaccess_proctoring_retire_self_registered_reference
 */
final class precheck_evidence_test extends advanced_testcase {

    /** @var stdClass Course. */
    private $course;

    /** @var stdClass Quiz module record (with cmid). */
    private $quiz;

    /** @var stdClass Student. */
    private $student;

    /** @var int Next fake question usage id for inserted attempts. */
    private $nextuniqueid = 950000;

    /**
     * A course with one proctored quiz and an enrolled student.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $DB->insert_record('quizaccess_proctoring', (object)['quizid' => $this->quiz->id, 'proctoringrequired' => 1]);
        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * The capture that passed the precheck becomes part of the attempt that follows.
     */
    public function test_passed_capture_is_claimed_by_the_attempt(): void {
        global $DB;

        $this->setUser($this->student);
        $reportid = $this->add_log(0, time());
        precheck_evidence::remember_passed_capture((int)$this->quiz->cmid, $reportid);
        $attemptid = $this->add_attempt($this->student->id, time());

        precheck_evidence::attempt_started($this->attempt_started_event($attemptid));

        $this->assertSame($attemptid, (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]));
    }

    /**
     * A remembered capture is not claimed once it is stale, or by another student's attempt.
     */
    public function test_stale_or_foreign_captures_are_not_claimed(): void {
        global $DB, $SESSION;

        $this->setUser($this->student);
        $reportid = $this->add_log(0, time());

        // Stale: passed longer ago than the claim window.
        precheck_evidence::remember_passed_capture((int)$this->quiz->cmid, $reportid);
        $SESSION->quizaccess_proctoring_precheckcapture[(int)$this->quiz->cmid]['timecreated'] =
            time() - precheck_evidence::CLAIM_TTL - 1;
        precheck_evidence::attempt_started($this->attempt_started_event($this->add_attempt($this->student->id, time())));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]));

        // Foreign: the event is for another student's attempt.
        $other = $this->getDataGenerator()->create_user();
        precheck_evidence::remember_passed_capture((int)$this->quiz->cmid, $reportid);
        precheck_evidence::attempt_started($this->attempt_started_event($this->add_attempt($other->id, time()), $other->id));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]));
    }

    /**
     * Captures that no attempt claimed within a day are queued for deletion; nothing else is.
     */
    public function test_only_day_old_unclaimed_captures_are_queued(): void {
        global $DB;

        $now = time();
        $old = $this->add_log(0, $now - DAYSECS - 60);
        $fresh = $this->add_log(0, $now - 60);
        $attempt = $this->add_log($this->add_attempt($this->student->id, $now - 2 * DAYSECS), $now - DAYSECS - 60);

        $this->assertSame(1, precheck_evidence::queue_abandoned_captures($now));

        $this->assertSame(1, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $old]));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $fresh]));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'deletionprogress', ['id' => $attempt]));
        $this->assertSame(0, precheck_evidence::queue_abandoned_captures($now));
    }

    /**
     * A self-registered reference photo that no proctored attempt followed is removed after a day.
     */
    public function test_abandoned_self_registered_reference_is_removed(): void {
        global $DB;

        $now = time();
        set_config('abandonedreferencesince', $now - 5 * DAYSECS, 'quizaccess_proctoring');
        $this->add_reference($this->student->id, 0, $now - 2 * DAYSECS);

        $this->assertSame(1, precheck_evidence::retire_abandoned_references($now));

        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $this->student->id]));
        $this->assertFalse(quizaccess_proctoring_get_image_url($this->student->id));
    }

    /**
     * Reference photos are kept when an attempt followed, when staff uploaded them, when they are
     * under a day old, or when they were registered before the rule was introduced.
     */
    public function test_references_that_are_not_abandoned_are_kept(): void {
        global $DB;

        $now = time();
        $generator = $this->getDataGenerator();
        set_config('abandonedreferencesince', $now - 5 * DAYSECS, 'quizaccess_proctoring');

        $followed = $this->student;
        $this->add_reference($followed->id, 0, $now - 2 * DAYSECS);
        $this->add_attempt($followed->id, $now - 2 * DAYSECS + 60);

        $staff = $generator->create_user();
        $this->add_reference($staff->id, 12345, $now - 2 * DAYSECS);

        $fresh = $generator->create_user();
        $this->add_reference($fresh->id, 0, $now - HOURSECS);

        $older = $generator->create_user();
        $this->add_reference($older->id, 0, $now - 10 * DAYSECS);

        $this->assertSame(0, precheck_evidence::retire_abandoned_references($now));
        foreach ([$followed, $staff, $fresh, $older] as $user) {
            $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
            $this->assertNotFalse(quizaccess_proctoring_get_image_url($user->id));
        }
    }

    /**
     * Without a recorded cut-off (a fresh install) the first run records one and removes nothing.
     */
    public function test_first_run_records_the_cut_off(): void {
        $now = time();
        unset_config('abandonedreferencesince', 'quizaccess_proctoring');
        $this->add_reference($this->student->id, 0, $now - 2 * DAYSECS);

        $this->assertSame(0, precheck_evidence::retire_abandoned_references($now));
        $this->assertSame($now, (int)get_config('quizaccess_proctoring', 'abandonedreferencesince'));
        $this->assertNotFalse(quizaccess_proctoring_get_image_url($this->student->id));
    }

    /**
     * Insert a log row for the student on the quiz.
     *
     * @param int $status Attempt id, or 0 for a precheck capture.
     * @param int $time Time modified.
     * @return int Log id.
     */
    private function add_log(int $status, int $time): int {
        global $DB;

        return (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'webcampicture' => '',
            'status' => $status,
            'timemodified' => $time,
        ]);
    }

    /**
     * Insert a started attempt.
     *
     * @param int $userid Student.
     * @param int $timestart Start time.
     * @return int Attempt id.
     */
    private function add_attempt(int $userid, int $timestart): int {
        global $DB;

        $number = (int)$DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $userid]) + 1;
        return (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $this->quiz->id,
            'userid' => $userid,
            'attempt' => $number,
            'uniqueid' => $this->nextuniqueid++,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'inprogress',
            'timestart' => $timestart,
            'timefinish' => 0,
            'timemodified' => $timestart,
            'timemodifiedoffline' => 0,
            'timecheckstate' => null,
            'sumgrades' => null,
        ]);
    }

    /**
     * Build an attempt_started event without triggering other observers.
     *
     * @param int $attemptid Attempt id.
     * @param int $userid Attempt owner (defaults to the student).
     * @return \mod_quiz\event\attempt_started
     */
    private function attempt_started_event(int $attemptid, int $userid = 0): \mod_quiz\event\attempt_started {
        return \mod_quiz\event\attempt_started::create([
            'objectid' => $attemptid,
            'relateduserid' => $userid ?: (int)$this->student->id,
            'courseid' => (int)$this->course->id,
            'context' => context_module::instance((int)$this->quiz->cmid),
            'other' => ['quizid' => (int)$this->quiz->id],
        ]);
    }

    /**
     * Store a reference photo for a user, as a self-registration (draft 0) or a staff upload.
     *
     * @param int $userid User.
     * @param int $draftid 0 for self-registered, otherwise a staff upload draft id.
     * @param int $registered Registration time.
     */
    private function add_reference(int $userid, int $draftid, int $registered): void {
        global $DB;

        get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'quizaccess_proctoring',
            'filearea' => 'user_photo',
            'itemid' => $userid,
            'filepath' => '/',
            'filename' => 'user-' . $userid . '.png',
            'userid' => $userid,
        ], 'reference');
        $imageid = $DB->insert_record('quizaccess_proctoring_user_images', (object)[
            'user_id' => $userid,
            'photo_draft_id' => $draftid,
        ]);
        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'admin_image',
            'parentid' => $imageid,
            'faceimage' => '',
            'facefound' => 1,
            'timemodified' => $registered,
        ]);
    }
}

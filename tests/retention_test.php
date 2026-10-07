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
 * Tests for the retention schedule and account-deletion purge (CPIT-472).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\task\delete_images_task;
use quizaccess_proctoring\task\purge_deleted_user_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Retention tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\task\delete_images_task
 * @covers \quizaccess_proctoring\local\user_data_purge
 * @covers \quizaccess_proctoring\task\purge_deleted_user_task
 */
final class retention_test extends advanced_testcase {

    /**
     * Expired ID images are deleted, except while a hold on that attempt waits for review.
     */
    public function test_id_images_are_kept_while_the_hold_is_open(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('idverificationretentiondays', 30, 'quizaccess_proctoring');
        $old = time() - 40 * DAYSECS;

        $held = $this->idv(7, 101, 501, $old);
        $free = $this->idv(8, 101, 502, $old);
        $recent = $this->idv(9, 101, 503, time());
        $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => 1, 'quizid' => 101, 'quizinstance' => 1, 'userid' => 7, 'attemptid' => 501,
            'reportid' => 0, 'riskscore' => 90, 'threshold' => 80, 'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'reviewerid' => 0, 'timecreated' => $old, 'timemodified' => $old, 'timereviewed' => 0,
            'autoreleaseblockedscore' => 0,
        ]);

        $this->run_cleanup();

        $this->assertTrue($DB->record_exists('quizaccess_proctoring_idv', ['id' => $held]), 'kept: hold open');
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_idv', ['id' => $free]), 'deleted: expired');
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_idv', ['id' => $recent]), 'kept: recent');
    }

    /**
     * A reference photo goes after the retention period without proctoring activity, but not while
     * the student is still active or has a hold waiting for review.
     */
    public function test_reference_photos_expire_after_inactivity(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('referenceretentiondays', 365, 'quizaccess_proctoring');
        $old = time() - 400 * DAYSECS;

        $inactive = $this->getDataGenerator()->create_user();
        $active = $this->getDataGenerator()->create_user();
        $held = $this->getDataGenerator()->create_user();
        foreach ([$inactive, $active, $held] as $user) {
            $DB->insert_record('quizaccess_proctoring_user_images', (object)[
                'user_id' => $user->id, 'photo_draft_id' => 0, 'timeused' => $old,
            ]);
        }
        $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => 1, 'quizid' => 101, 'userid' => $active->id, 'webcampicture' => '',
            'status' => 0, 'timemodified' => time() - DAYSECS,
        ]);
        $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => 1, 'quizid' => 101, 'quizinstance' => 1, 'userid' => $held->id, 'attemptid' => 9,
            'reportid' => 0, 'riskscore' => 90, 'threshold' => 80, 'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'reviewerid' => 0, 'timecreated' => $old, 'timemodified' => $old, 'timereviewed' => 0,
            'autoreleaseblockedscore' => 0,
        ]);

        $this->run_cleanup();

        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $inactive->id]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $active->id]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $held->id]));

        // With the limit off, nothing goes.
        set_config('referenceretentiondays', 0, 'quizaccess_proctoring');
        $DB->set_field('quizaccess_proctoring_risk_holds', 'status', QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED);
        $this->run_cleanup();
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $held->id]));
    }

    /**
     * A run that finishes records when, for the settings page.
     */
    public function test_a_successful_run_is_recorded(): void {
        $this->resetAfterTest();
        $this->assertFalse(get_config('quizaccess_proctoring', 'lastcleanupsuccess'));
        $this->run_cleanup();
        $this->assertGreaterThan(0, (int)get_config('quizaccess_proctoring', 'lastcleanupsuccess'));
    }

    /**
     * Deleting a Moodle account removes the user's proctoring records, and nobody else's.
     */
    public function test_deleting_an_account_removes_its_proctoring_data(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        foreach ([$student, $other] as $user) {
            $logid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
                'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $user->id,
                'webcampicture' => '', 'status' => 0, 'timemodified' => time(),
            ]);
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $user->id, 'attemptid' => 0,
                'reportid' => $logid, 'eventtype' => 'focus_lost', 'eventdetail' => '{}', 'timemodified' => time(),
            ]);
            $DB->insert_record('quizaccess_proctoring_user_images', (object)[
                'user_id' => $user->id, 'photo_draft_id' => 0, 'timeused' => 0,
            ]);
        }

        delete_user($student);
        $this->runAdhocTasks(purge_deleted_user_task::class);

        foreach (['quizaccess_proctoring_logs', 'quizaccess_proctoring_events'] as $table) {
            $this->assertFalse($DB->record_exists($table, ['userid' => $student->id]), $table);
            $this->assertTrue($DB->record_exists($table, ['userid' => $other->id]), $table);
        }
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $student->id]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $other->id]));
    }

    /**
     * Insert an ID verification row.
     *
     * @param int $userid Student ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $attemptid Attempt ID.
     * @param int $time Time of the check.
     * @return int Row ID.
     */
    private function idv(int $userid, int $cmid, int $attemptid, int $time): int {
        global $DB;
        return (int)$DB->insert_record('quizaccess_proctoring_idv', (object)[
            'courseid' => 1, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
            'status' => 'pass', 'timecreated' => $time, 'timemodified' => $time,
        ]);
    }

    /**
     * Run the cleanup task, discarding its progress output.
     */
    private function run_cleanup(): void {
        ob_start();
        try {
            (new delete_images_task())->execute();
        } finally {
            ob_end_clean();
        }
    }
}
